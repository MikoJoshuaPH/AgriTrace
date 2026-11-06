<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Orders, payments and product trace codes.
 * An order reserves stock at checkout and gets one trace code per product line
 * once payment is confirmed. Online payment references are stored per gateway.
 */
class Payment_model extends Model
{
    public function order_with_buyer($orderId)
    {
        return $this->db->table('orders')
            ->select('orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.payment_method, orders.xendit_invoice_id, orders.xendit_invoice_url, orders.paymongo_payment_link_id, orders.paymongo_payment_url, orders.paymongo_checkout_session_id, orders.paymongo_checkout_url, orders.payment_external_id, orders.paid_at, orders.created_at, u.email AS buyer_email, u.first_name AS buyer_first, u.last_name AS buyer_last, u.username AS buyer_username, s.id AS farmer_id, s.paymongo_account_id, s.paymongo_account_status')
            ->inner_join('users u', 'u.id = orders.buyer_id')
            ->left_join('users s', 's.id = (SELECT oi.seller_id FROM order_items oi WHERE oi.order_id = orders.order_id ORDER BY oi.item_id LIMIT 1)')
            ->where('orders.order_id', $orderId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function order_items($orderId)
    {
        return $this->db->table('order_items')
            ->select('item_id, listing_id, seller_id, product_name, unit_price, quantity, unit, batch_id')
            ->where('order_id', $orderId)
            ->order_by('item_id', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function save_invoice($orderId, $invoiceId, $invoiceUrl, $externalId)
    {
        $this->db->table('orders')->where('order_id', $orderId)->update([
            'xendit_invoice_id' => $invoiceId, 'xendit_invoice_url' => $invoiceUrl,
            'payment_external_id' => $externalId, 'payment_status' => 'payment_processing',
        ]);
    }

    public function save_payment_link($orderId, $linkId, $paymentUrl, $referenceNumber)
    {
        $changed = $this->db->raw(
            "UPDATE orders
             SET paymongo_payment_link_id = ?, paymongo_payment_url = ?,
                 payment_external_id = ?, payment_status = 'payment_processing'
             WHERE order_id = ? AND payment_method = 'paymongo' AND status = 'awaiting_payment'
               AND paymongo_payment_link_id IS NULL",
            [$linkId, $paymentUrl, $referenceNumber, (int) $orderId]
        )->rowCount();
        return $changed > 0;
    }

    public function save_checkout_session($orderId, $sessionId, $checkoutUrl, $referenceNumber)
    {
        $changed = $this->db->raw(
            "UPDATE orders
             SET paymongo_checkout_session_id = ?, paymongo_checkout_url = ?,
                 payment_external_id = ?, payment_status = 'payment_processing'
             WHERE order_id = ? AND payment_method = 'paymongo' AND status = 'awaiting_payment'
               AND paymongo_checkout_session_id IS NULL",
            [$sessionId, $checkoutUrl, $referenceNumber, (int) $orderId]
        )->rowCount();
        return $changed > 0;
    }

    /** Marks an accepted online order as paid only after the gateway verifies it. */
    public function mark_paid($orderId)
    {
        $changed = $this->db->raw(
            "UPDATE orders SET payment_status = 'paid', status = 'accepted', paid_at = ?
             WHERE order_id = ? AND payment_method IN ('xendit', 'paymongo')
               AND status = 'awaiting_payment'
               AND payment_status IN ('unpaid','awaiting_payment','payment_processing')",
            [date('Y-m-d H:i:s'), (int) $orderId]
        )->rowCount();
        if ($changed) {
            $this->record_order_event($orderId, 'payment_paid', 'paid', 'Online payment verified.');
        }
        return $changed > 0;
    }

    /** Cancels an unpaid online order and releases its batch reservation once. */
    public function mark_expired($orderId)
    {
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT order_id, status, payment_status FROM orders WHERE order_id = ? FOR UPDATE',
                [(int) $orderId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['status'] !== 'awaiting_payment'
                || !in_array($order['payment_status'], ['unpaid', 'awaiting_payment', 'payment_processing'], true)) {
                $this->db->roll_back();
                return false;
            }
            $this->db->table('orders')->where('order_id', (int) $orderId)->update([
                'payment_status' => 'failed', 'status' => 'cancelled'
            ]);
            $this->release_reserved_inventory((int) $orderId, null);
            $this->record_order_event($orderId, 'cancelled', 'failed', 'Online payment expired; reserved inventory released.');
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function release_reserved_inventory($orderId, $actorId)
    {
        $items = $this->db->raw(
            'SELECT oi.item_id, oi.listing_id, oi.quantity, oi.unit, oi.batch_id,
                    ba.batch_id AS allocated_batch_id, ba.quantity AS allocated_quantity
             FROM order_items oi
             LEFT JOIN batch_allocations ba ON ba.order_item_id = oi.item_id
             WHERE oi.order_id = ?',
            [(int) $orderId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            if ($item['allocated_batch_id'] !== null) {
                $this->db->raw(
                    'UPDATE product_batches SET available_quantity = available_quantity + ? WHERE batch_id = ?',
                    [$item['allocated_quantity'], $item['allocated_batch_id']]
                );
                $this->db->table('inventory_movements')->insert([
                    'listing_id' => $item['listing_id'],
                    'batch_id' => $item['allocated_batch_id'],
                    'order_id' => (int) $orderId,
                    'movement_type' => 'release',
                    'quantity' => $item['allocated_quantity'],
                    'unit' => $item['unit'],
                    'reference' => 'ORDER-' . $orderId,
                    'created_by' => $actorId
                ]);
            } elseif (empty($item['batch_id']) && $item['listing_id'] !== null) {
                $this->db->table('marketplace_listings')
                    ->where('listing_id', $item['listing_id'])
                    ->increment('stock', $item['quantity']);
            }
        }
    }

    public function record_order_event($orderId, $status, $paymentStatus, $note = null, $actorId = null)
    {
        return $this->db->table('order_status_history')->insert([
            'order_id' => (int) $orderId,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'note' => $note,
            'changed_by' => $actorId === null ? null : (int) $actorId,
        ]);
    }

    /** Creates one trace code per order line (idempotent). Returns the trace rows for the order. */
    public function ensure_trace_codes($orderId)
    {
        $order = $this->order_with_buyer($orderId);
        foreach ($this->order_items($orderId) as $item) {
            $exists = $this->db->table('trace_codes')->select('trace_id')->where('order_item_id', $item['item_id'])->limit(1)->get(PDO::FETCH_ASSOC);
            if ($exists) {
                $this->db->table('trace_codes')->where('trace_id', $exists['trace_id'])->update([
                    'batch_id' => $item['batch_id'] ?: null
                ]);
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
                'batch_id' => $item['batch_id'] ?: null,
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

    public function trace_row_by_code($code)
    {
        return $this->db->table('trace_codes')
            ->select('trace_id, trace_code, batch_id')
            ->where('trace_code', $code)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function link_trace_to_batch($traceId, $batchId)
    {
        return $this->db->table('trace_codes')
            ->where('trace_id', $traceId)
            ->update(['batch_id' => $batchId]);
    }

    public function set_trace_qr($traceId, $url)
    {
        $this->db->table('trace_codes')->where('trace_id', $traceId)->update(['qr_url' => $url]);
    }

    /** Public trace record: product, farmer, buyer (first name and last initial only) and dates. */
    public function public_trace($code)
    {
        $row = $this->db->table('trace_codes')
            ->select('trace_codes.batch_id, trace_codes.trace_code, trace_codes.product_name, trace_codes.category, trace_codes.quantity, trace_codes.unit, trace_codes.unit_price, trace_codes.qr_url, trace_codes.created_at, o.order_id, o.status AS order_status, o.paid_at, o.payment_status, b.first_name AS buyer_first, b.last_name AS buyer_last, b.username AS buyer_username, s.first_name AS farmer_first, s.last_name AS farmer_last, s.username AS farmer_username, s.location_label AS farmer_location, l.location AS listing_location')
            ->inner_join('orders o', 'o.order_id = trace_codes.order_id')
            ->inner_join('users b', 'b.id = trace_codes.buyer_id')
            ->inner_join('users s', 's.id = trace_codes.seller_id')
            ->left_join('marketplace_listings l', 'l.listing_id = trace_codes.listing_id')
            ->where('trace_codes.trace_code', $code)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function batch_history($batchCode, $includeInternal = false)
    {
        $batch = $this->db->raw(
            'SELECT pb.batch_id, pb.product_id, pb.farm_id, pb.batch_code, pb.current_status, pb.quantity, pb.unit,
                    pb.harvest_date, pb.expiration_date, pb.created_at, p.product_name, p.category,
                    f.farm_name, f.farm_address, f.province, f.municipality, f.barangay,
                    f.latitude, f.longitude, f.farmer_id, u.first_name AS farmer_first,
                    u.last_name AS farmer_last, u.username AS farmer_username, q.qr_image_path
             FROM product_batches pb
             INNER JOIN products p ON p.product_id = pb.product_id
             INNER JOIN farms f ON f.farm_id = pb.farm_id
             INNER JOIN users u ON u.id = f.farmer_id
             LEFT JOIN qr_codes q ON q.batch_id = pb.batch_id AND q.is_active = 1
             WHERE pb.batch_code = ?
             LIMIT 1',
            [$batchCode]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            return null;
        }
        return $this->batch_history_from_row($batch, $includeInternal);
    }

    public function batch_history_by_id($batchId, $includeInternal = false)
    {
        $batch = $this->db->raw(
            'SELECT pb.batch_id, pb.product_id, pb.farm_id, pb.batch_code, pb.current_status, pb.quantity, pb.unit,
                    pb.harvest_date, pb.expiration_date, pb.created_at, p.product_name, p.category,
                    f.farm_name, f.farm_address, f.province, f.municipality, f.barangay,
                    f.latitude, f.longitude, f.farmer_id, u.first_name AS farmer_first,
                    u.last_name AS farmer_last, u.username AS farmer_username, q.qr_image_path
             FROM product_batches pb
             INNER JOIN products p ON p.product_id = pb.product_id
             INNER JOIN farms f ON f.farm_id = pb.farm_id
             INNER JOIN users u ON u.id = f.farmer_id
             LEFT JOIN qr_codes q ON q.batch_id = pb.batch_id AND q.is_active = 1
             WHERE pb.batch_id = ?
             LIMIT 1',
            [$batchId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            return null;
        }
        return $this->batch_history_from_row($batch, $includeInternal);
    }

    private function batch_history_from_row(array $batch, $includeInternal)
    {
        $harvestQuery = 'SELECT harvest_id, harvest_date, quantity_harvested, quality_grade, notes, approval_status, created_at
                         FROM harvest_records WHERE batch_id = ?';
        if (!$includeInternal) {
            $harvestQuery .= " AND approval_status = 'approved'";
        }
        $harvestQuery .= ' ORDER BY harvest_date ASC, harvest_id ASC';
        $harvests = $this->db->raw($harvestQuery, [(int) $batch['batch_id']])->fetchAll(PDO::FETCH_ASSOC);
        if (!$harvests && !empty($batch['harvest_date'])) {
            $harvests[] = [
                'harvest_id' => null,
                'harvest_date' => $batch['harvest_date'],
                'quantity_harvested' => (float) $batch['quantity'],
                'quality_grade' => null,
                'notes' => 'Harvest date and expiration are recorded on the registered product batch.',
                'approval_status' => 'batch_record',
                'expiration_date' => $batch['expiration_date'],
                'created_at' => $batch['created_at'],
            ];
        }

        $shipments = $this->db->raw(
            'SELECT shipment_id, origin, destination, dispatch_time, delivery_time, shipment_status, remarks, created_at,
                    transporter_id, vehicle_number
             FROM shipments WHERE batch_id = ? ORDER BY created_at ASC, shipment_id ASC',
            [(int) $batch['batch_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $logs = $this->db->raw(
            'SELECT log_id, status, location_name, latitude, longitude, remarks, created_at, updated_by
             FROM traceability_logs WHERE batch_id = ? ORDER BY created_at ASC, log_id ASC',
            [(int) $batch['batch_id']]
        )->fetchAll(PDO::FETCH_ASSOC);

        $supplyChain = [];
        foreach ($shipments as $shipment) {
            $details = [
                'shipment_id' => (int) $shipment['shipment_id'],
                'origin' => $shipment['origin'],
                'destination' => $shipment['destination'],
                'remarks' => $shipment['remarks'],
            ];
            if ($includeInternal) {
                $details['transporter_id'] = (int) $shipment['transporter_id'];
                $details['vehicle_number'] = $shipment['vehicle_number'];
                $details['dispatch_time'] = $shipment['dispatch_time'];
                $details['delivery_time'] = $shipment['delivery_time'];
                $details['shipment_status'] = $shipment['shipment_status'];
                $details['created_at'] = $shipment['created_at'];
            }
            $hasDispatch = $shipment['dispatch_time'] || in_array($shipment['shipment_status'], ['in_transit', 'delivered'], true);
            $hasDelivery = $shipment['delivery_time'] || $shipment['shipment_status'] === 'delivered';
            if ($hasDispatch) {
                $supplyChain[] = array_merge($details, [
                    'type' => 'dispatch',
                    'status' => 'in_transit',
                    'location' => $shipment['origin'],
                    'date' => $shipment['dispatch_time'] ?: $shipment['created_at'],
                    'event' => 'Shipment dispatched',
                ]);
            }
            if ($hasDelivery) {
                $supplyChain[] = array_merge($details, [
                    'type' => 'delivery',
                    'status' => 'delivered',
                    'location' => $shipment['destination'],
                    'date' => $shipment['delivery_time'] ?: $shipment['created_at'],
                    'event' => 'Shipment delivered',
                ]);
            }
            if (!$hasDispatch && !$hasDelivery) {
                $supplyChain[] = array_merge($details, [
                    'type' => 'shipment',
                    'status' => $shipment['shipment_status'],
                    'location' => $shipment['origin'],
                    'date' => $shipment['created_at'],
                    'event' => 'Shipment ' . str_replace('_', ' ', $shipment['shipment_status']),
                ]);
            }
        }
        foreach ($logs as $log) {
            $event = [
                'type' => 'log',
                'status' => $log['status'],
                'location' => $log['location_name'],
                'remarks' => $log['remarks'],
                'date' => $log['created_at'],
                'event' => 'Batch status: ' . str_replace('_', ' ', $log['status']),
            ];
            if ($includeInternal) {
                $event['latitude'] = $log['latitude'] !== null ? (float) $log['latitude'] : null;
                $event['longitude'] = $log['longitude'] !== null ? (float) $log['longitude'] : null;
                $event['updated_by'] = (int) $log['updated_by'];
            }
            $supplyChain[] = $event;
        }
        usort($supplyChain, static function ($left, $right) {
            return strtotime((string) $left['date']) <=> strtotime((string) $right['date']);
        });

        $linkedTrace = $this->db->raw(
            'SELECT tc.trace_code, o.payment_status
             FROM trace_codes tc
             LEFT JOIN orders o ON o.order_id = tc.order_id
             WHERE tc.batch_id = ?
             ORDER BY tc.trace_id ASC
             LIMIT 1',
            [(int) $batch['batch_id']]
        )->fetch(PDO::FETCH_ASSOC);

        $locationParts = array_filter([
            trim((string) ($batch['barangay'] ?? '')),
            trim((string) ($batch['municipality'] ?? '')),
            trim((string) ($batch['province'] ?? '')),
        ], static function ($value) {
            return $value !== '';
        });
        $farmerName = trim(($batch['farmer_first'] ?? '') . ' ' . ($batch['farmer_last'] ?? ''));
        $farmer = [
            'name' => $farmerName ?: ($batch['farmer_username'] ?? 'Registered farmer'),
            'registered_farm' => $batch['farm_name'],
            'location' => $locationParts ? implode(', ', $locationParts) : 'Location not set',
        ];
        if ($includeInternal) {
            $farmer['farm_id'] = (int) $batch['farm_id'];
            $farmer['address'] = $batch['farm_address'];
            $farmer['latitude'] = $batch['latitude'] !== null ? (float) $batch['latitude'] : null;
            $farmer['longitude'] = $batch['longitude'] !== null ? (float) $batch['longitude'] : null;
        }

        $result = [
            'trace_code' => $linkedTrace ? $linkedTrace['trace_code'] : null,
            'payment_status' => $linkedTrace['payment_status'] ?? null,
            'provenance_verified' => count(array_filter($harvests, static function ($harvest) {
                return $harvest['approval_status'] === 'approved';
            })) > 0,
            'product' => [
                'name' => $batch['product_name'],
                'category' => $batch['category'],
                'quantity' => (float) $batch['quantity'],
                'unit' => $batch['unit'],
            ],
            'farmer' => $farmer,
            'batch' => [
                'batch_id' => (int) $batch['batch_id'],
                'batch_code' => $batch['batch_code'],
                'status' => $batch['current_status'],
                'quantity' => (float) $batch['quantity'],
                'unit' => $batch['unit'],
                'harvest_date' => $batch['harvest_date'],
                'expiration_date' => $batch['expiration_date'],
                'created_at' => $batch['created_at'],
            ],
            'harvest' => $harvests,
            'supply_chain' => $supplyChain,
            'qr_url' => $batch['qr_image_path'],
        ];
        if ($includeInternal) {
            $result['product']['product_id'] = (int) $batch['product_id'];
            $result['batch']['product_id'] = (int) $batch['product_id'];
            $result['batch']['farm_id'] = (int) $batch['farm_id'];
        }
        return $result;
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

    public function order_by_paymongo_link($linkId)
    {
        $row = $this->db->table('orders')->select('order_id')
            ->where('paymongo_payment_link_id', $linkId)->limit(1)->get(PDO::FETCH_ASSOC);
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
