<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Orders, payments and product trace codes.
 * An order reserves stock at checkout, is paid through Xendit, and gets one trace code
 * per product line once the payment is confirmed.
 */
class Payment_model extends Model
{
    public function order_with_buyer($orderId)
    {
        return $this->db->table('orders')
            ->select('orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.xendit_invoice_id, orders.xendit_invoice_url, orders.payment_external_id, orders.paid_at, orders.created_at, u.email AS buyer_email, u.first_name AS buyer_first, u.last_name AS buyer_last, u.username AS buyer_username')
            ->inner_join('users u', 'u.id = orders.buyer_id')
            ->where('orders.order_id', $orderId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function order_items($orderId)
    {
        return $this->db->table('order_items')
            ->select('item_id, listing_id, seller_id, product_name, unit_price, quantity, unit')
            ->where('order_id', $orderId)
            ->order_by('item_id', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function save_invoice($orderId, $invoiceId, $invoiceUrl, $externalId)
    {
        $this->db->table('orders')->where('order_id', $orderId)->update([
            'xendit_invoice_id' => $invoiceId, 'xendit_invoice_url' => $invoiceUrl, 'payment_external_id' => $externalId,
        ]);
    }

    /** Marks an unpaid order as paid. Returns false when it was already paid (webhook retries are harmless). */
    public function mark_paid($orderId)
    {
        $changed = $this->db->table('orders')
            ->where(['order_id' => $orderId, 'payment_status' => 'unpaid'])
            ->update(['payment_status' => 'paid', 'status' => 'confirmed', 'paid_at' => date('Y-m-d H:i:s')]);
        return (bool) $changed;
    }

    /** Cancels an unpaid order whose invoice expired and gives its stock back. Returns true when it changed. */
    public function mark_expired($orderId)
    {
        $changed = $this->db->table('orders')
            ->where(['order_id' => $orderId, 'payment_status' => 'unpaid'])
            ->update(['payment_status' => 'expired', 'status' => 'cancelled']);
        if (!$changed) {
            return false;
        }
        foreach ($this->order_items($orderId) as $item) {
            if ($item['listing_id'] !== null) {
                $this->db->table('marketplace_listings')->where('listing_id', $item['listing_id'])->increment('stock', $item['quantity']);
            }
        }
        return true;
    }

    /** Creates one trace code per order line (idempotent). Returns the trace rows for the order. */
    public function ensure_trace_codes($orderId)
    {
        $order = $this->order_with_buyer($orderId);
        foreach ($this->order_items($orderId) as $item) {
            $exists = $this->db->table('trace_codes')->select('trace_id')->where('order_item_id', $item['item_id'])->limit(1)->get(PDO::FETCH_ASSOC);
            if ($exists) {
                continue;
            }
            $category = null;
            if ($item['listing_id'] !== null) {
                $listing = $this->db->table('marketplace_listings')->select('category')->where('listing_id', $item['listing_id'])->limit(1)->get(PDO::FETCH_ASSOC);
                $category = $listing ? $listing['category'] : null;
            }
            // Generate the code before starting the insert: the query builder keeps one state per table() chain.
            $traceCode = $this->new_trace_code();
            $this->db->table('trace_codes')->insert([
                'trace_code' => $traceCode,
                'order_id' => $orderId,
                'order_item_id' => $item['item_id'],
                'listing_id' => $item['listing_id'],
                'buyer_id' => $order['buyer_id'],
                'seller_id' => $item['seller_id'],
                'product_name' => $item['product_name'],
                'category' => $category,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
            ]);
        }
        return $this->traces_for_order($orderId);
    }

    public function traces_for_order($orderId)
    {
        return $this->db->table('trace_codes')
            ->select('trace_codes.trace_id, trace_codes.trace_code, trace_codes.order_id, trace_codes.order_item_id, trace_codes.listing_id, trace_codes.buyer_id, trace_codes.seller_id, trace_codes.product_name, trace_codes.category, trace_codes.quantity, trace_codes.unit, trace_codes.unit_price, trace_codes.qr_url, trace_codes.created_at, oi.product_name AS item_name')
            ->inner_join('order_items oi', 'oi.item_id = trace_codes.order_item_id')
            ->where('trace_codes.order_id', $orderId)
            ->order_by('trace_codes.trace_id', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function listing_sales($listingId, $sellerId)
    {
        return $this->db->raw(
            'SELECT oi.item_id, o.order_id, o.status AS order_status, o.payment_status,
                    o.created_at, o.paid_at, oi.product_name, oi.unit_price, oi.quantity, oi.unit,
                    b.first_name AS buyer_first, b.last_name AS buyer_last, b.username AS buyer_username,
                    tc.trace_code
             FROM order_items oi
             INNER JOIN orders o ON o.order_id = oi.order_id
             INNER JOIN users b ON b.id = o.buyer_id
             LEFT JOIN trace_codes tc ON tc.order_item_id = oi.item_id
             WHERE oi.listing_id = ? AND oi.seller_id = ?
             ORDER BY o.order_id DESC, oi.item_id DESC
             LIMIT 200',
            [$listingId, $sellerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function trace_row($code)
    {
        return $this->db->table('trace_codes')->select('trace_id, trace_code')->where('trace_code', $code)->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function set_trace_qr($traceId, $url)
    {
        $this->db->table('trace_codes')->where('trace_id', $traceId)->update(['qr_url' => $url]);
    }

    /** Public trace record: product, farmer, buyer (first name and last initial only) and dates. */
    public function public_trace($code)
    {
        $row = $this->db->table('trace_codes')
            ->select('trace_codes.trace_code, trace_codes.product_name, trace_codes.category, trace_codes.quantity, trace_codes.unit, trace_codes.unit_price, trace_codes.qr_url, trace_codes.created_at, o.order_id, o.paid_at, o.payment_status, b.first_name AS buyer_first, b.last_name AS buyer_last, b.username AS buyer_username, s.id AS farmer_id, s.first_name AS farmer_first, s.last_name AS farmer_last, s.username AS farmer_username, s.address AS farmer_address, s.location_label AS farmer_location, s.latitude AS farmer_lat, s.longitude AS farmer_lng, l.location AS listing_location')
            ->inner_join('orders o', 'o.order_id = trace_codes.order_id')
            ->inner_join('users b', 'b.id = trace_codes.buyer_id')
            ->inner_join('users s', 's.id = trace_codes.seller_id')
            ->left_join('marketplace_listings l', 'l.listing_id = trace_codes.listing_id')
            ->where('trace_codes.trace_code', $code)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Full trace list for administrators (includes the buyer's email). */
    public function admin_traces($limit = 200)
    {
        return $this->db->table('trace_codes')
            ->select('trace_codes.trace_id, trace_codes.trace_code, trace_codes.product_name, trace_codes.quantity, trace_codes.unit, trace_codes.unit_price, trace_codes.created_at, trace_codes.order_id, o.paid_at, o.payment_status, b.username AS buyer, b.email AS buyer_email, s.username AS farmer, s.address AS farmer_address')
            ->inner_join('orders o', 'o.order_id = trace_codes.order_id')
            ->inner_join('users b', 'b.id = trace_codes.buyer_id')
            ->inner_join('users s', 's.id = trace_codes.seller_id')
            ->order_by('trace_codes.trace_id', 'DESC')
            ->limit(max(1, min((int) $limit, 500)))
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function order_by_invoice($invoiceId)
    {
        $row = $this->db->table('orders')->select('order_id')->where('xendit_invoice_id', $invoiceId)->limit(1)->get(PDO::FETCH_ASSOC);
        return $row ? (int) $row['order_id'] : null;
    }

    private function new_trace_code()
    {
        do {
            $code = 'AGT-' . strtoupper(bin2hex(random_bytes(5)));
            $taken = $this->db->table('trace_codes')->select('trace_id')->where('trace_code', $code)->limit(1)->get(PDO::FETCH_ASSOC);
        } while ($taken);
        return $code;
    }
}
