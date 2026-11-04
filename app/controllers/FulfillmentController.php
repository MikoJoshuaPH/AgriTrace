<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class FulfillmentController extends Controller
{
    private $payments;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->payments = $this->call->model('Payment_model');
        $this->platform = $this->call->model('Platform_model');
    }

    public function batch_options($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->api->respond_error('Invalid order ID.', 422);
        }
        $items = $this->db->raw(
            'SELECT oi.item_id, oi.product_name, oi.catalog_id, oi.quantity, oi.unit,
                    COALESCE(SUM(ba.quantity), 0) AS allocated_quantity
             FROM order_items oi
             LEFT JOIN batch_allocations ba ON ba.order_item_id = oi.item_id
             WHERE oi.order_id = ? AND oi.seller_id = ?
             GROUP BY oi.item_id',
            [$orderId, $auth['sub']]
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            $this->api->respond_error('Order not found.', 404);
        }
        foreach ($items as &$item) {
            $item['batches'] = $this->db->raw(
                'SELECT pb.batch_id, pb.batch_code, pb.product_id, pb.harvest_reference,
                        pb.available_quantity, pb.unit, hr.harvest_id, hr.harvest_reference AS harvest_record_reference
                 FROM product_batches pb
                 INNER JOIN farms f ON f.farm_id = pb.farm_id AND f.farmer_id = ?
                 LEFT JOIN harvest_records hr ON hr.batch_id = pb.batch_id
                 WHERE pb.product_id = ? AND pb.available_quantity > 0
                 ORDER BY pb.harvest_date ASC, pb.batch_id ASC',
                [$auth['sub'], (int) $item['catalog_id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($item);
        $this->api->respond(['data' => $items]);
    }

    public function allocate($id)
    {
        $this->api->respond_error('Batch inventory is reserved automatically at checkout. Accept the order from the order list.', 409);
    }

    public function status($id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $input = $this->api->body();
        $status = trim((string) ($input['status'] ?? ''));
        $allowed = ['accepted', 'rejected', 'preparing', 'ready_for_shipment', 'shipped', 'in_transit', 'out_for_delivery', 'delivered', 'completed', 'cancelled'];
        if ($orderId === false || !in_array($status, $allowed, true)) {
            $this->api->respond_error('Invalid order status.', 422);
        }
        $role = $auth['role'] ?? '';
        if (!in_array($role, ['admin', 'farmer', 'buyer', 'seller', 'user'], true)) {
            $this->api->respond_error('You are not allowed to update this order.', 403);
        }

        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT order_id, buyer_id, status, payment_method, payment_status, xendit_invoice_id
                 FROM orders WHERE order_id = ? FOR UPDATE',
                [$orderId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }
            if (in_array($role, ['buyer', 'seller', 'user'], true) && $status !== 'cancelled') {
                throw new RuntimeException('Buyers may only cancel their own eligible orders.');
            }
            if ($role === 'farmer') {
                $ownsOrder = $this->db->raw(
                    'SELECT item_id FROM order_items WHERE order_id = ? AND seller_id = ? LIMIT 1',
                    [$orderId, $auth['sub']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$ownsOrder) {
                    throw new RuntimeException('Order not found.');
                }
            } elseif ($role !== 'admin' && (int) $order['buyer_id'] !== (int) $auth['sub']) {
                throw new RuntimeException('Order not found.');
            }

            if ($status === 'accepted') {
                if ($role !== 'farmer' || !in_array($order['status'], ['pending', 'awaiting_farmer_acceptance'], true)) {
                    throw new RuntimeException('Only the farmer can accept an order awaiting review.');
                }
                $unallocated = $this->db->raw(
                    'SELECT oi.item_id FROM order_items oi
                     LEFT JOIN batch_allocations ba ON ba.order_item_id = oi.item_id
                     WHERE oi.order_id = ?
                     GROUP BY oi.item_id, oi.quantity
                     HAVING COALESCE(SUM(ba.quantity), 0) < oi.quantity LIMIT 1',
                    [$orderId]
                )->fetch(PDO::FETCH_ASSOC);
                if ($unallocated) {
                    throw new RuntimeException('Reserved batch inventory is missing for an order item.');
                }
                $nextStatus = $order['payment_method'] === 'xendit' ? 'awaiting_payment' : 'accepted';
                $nextPayment = $order['payment_method'] === 'xendit' ? 'awaiting_payment' : 'unpaid';
                $this->db->table('orders')->where('order_id', $orderId)->update([
                    'status' => $nextStatus, 'payment_status' => $nextPayment
                ]);
                $this->payments->record_order_event($orderId, $nextStatus, $nextPayment, 'Farmer accepted the order.', (int) $auth['sub']);
                $this->db->commit();
                $this->platform->notify(
                    (int) $order['buyer_id'],
                    $order['payment_method'] === 'xendit' ? 'Payment required for Order #' . $orderId : 'Order #' . $orderId . ' accepted',
                    $order['payment_method'] === 'xendit'
                        ? 'The farmer accepted your order. Complete online payment from My Orders.'
                        : 'The farmer accepted your COD order and will prepare it for delivery.'
                );
                $this->api->respond(['message' => $order['payment_method'] === 'xendit'
                    ? 'Order accepted. The buyer can now pay online.'
                    : 'COD order accepted and ready for preparation.']);
            }

            $isBuyerCancel = in_array($role, ['buyer', 'seller', 'user'], true) && $status === 'cancelled';
            $transitions = [
                'pending' => ['rejected', 'cancelled'],
                'awaiting_farmer_acceptance' => ['rejected', 'cancelled'],
                'awaiting_payment' => ['cancelled'],
                'confirmed' => ['preparing', 'rejected', 'cancelled'],
                'accepted' => ['preparing', 'rejected', 'cancelled'],
                'preparing' => ['ready_for_shipment', 'shipped', 'cancelled'],
                'ready_for_shipment' => ['shipped', 'cancelled'],
                'shipped' => ['in_transit', 'out_for_delivery', 'delivered'],
                'in_transit' => ['out_for_delivery', 'delivered'],
                'out_for_delivery' => ['delivered'],
                'delivered' => ['completed'],
            ];
            if (!in_array($status, $transitions[$order['status']] ?? [], true)) {
                throw new RuntimeException('That order status transition is not allowed.');
            }
            if ($isBuyerCancel && !in_array($order['status'], ['pending', 'awaiting_farmer_acceptance', 'awaiting_payment', 'accepted'], true)) {
                throw new RuntimeException('This order can no longer be cancelled.');
            }
            if ($status === 'cancelled' && $order['payment_method'] === 'xendit' && !empty($order['xendit_invoice_id'])) {
                throw new RuntimeException('An online payment is already in progress. Wait for the payment result before cancelling.');
            }
            if (in_array($status, ['rejected', 'cancelled'], true)
                && in_array($order['payment_status'], ['paid', 'refunded'], true)) {
                throw new RuntimeException('A paid order cannot be cancelled without a verified refund.');
            }
            if ($order['payment_method'] === 'xendit'
                && in_array($status, ['preparing', 'ready_for_shipment', 'shipped', 'in_transit', 'out_for_delivery', 'delivered', 'completed'], true)
                && $order['payment_status'] !== 'paid') {
                throw new RuntimeException('Online payment must be verified before shipping can proceed.');
            }
            if ($order['payment_method'] === 'cod' && in_array($status, ['delivered', 'completed'], true)
                && $order['payment_status'] !== 'paid') {
                throw new RuntimeException('Record the COD payment before confirming delivery.');
            }
            $shippingProvider = trim((string) ($input['shipping_provider'] ?? ''));
            if ($status === 'shipped' && $shippingProvider === '' && $role !== 'admin') {
                throw new RuntimeException('Select a shipping provider before marking the order shipped.');
            }
            $updates = ['status' => $status];
            if ($status === 'cancelled' && $order['payment_method'] === 'xendit'
                && !in_array($order['payment_status'], ['paid', 'refunded'], true)) {
                $updates['payment_status'] = 'failed';
            }
            if ($shippingProvider !== '') {
                $updates['shipping_provider'] = substr($shippingProvider, 0, 80);
            }
            if (array_key_exists('tracking_number', $input)) {
                $updates['tracking_number'] = substr(trim((string) $input['tracking_number']), 0, 120);
            }
            $this->db->table('orders')->where('order_id', $orderId)->update($updates);
            if ($status === 'shipped') {
                $this->db->raw(
                    'INSERT INTO inventory_movements (listing_id, batch_id, order_id, movement_type, quantity, unit, reference, created_by)
                     SELECT oi.listing_id, ba.batch_id, oi.order_id, ?, ba.quantity, oi.unit, ?, ?
                     FROM order_items oi INNER JOIN batch_allocations ba ON ba.order_item_id = oi.item_id
                     WHERE oi.order_id = ?',
                    ['sale', 'ORDER-' . $orderId, (int) $auth['sub'], $orderId]
                );
            }
            if (in_array($status, ['rejected', 'cancelled'], true)) {
                $this->payments->release_reserved_inventory($orderId, (int) $auth['sub']);
            }
            $this->payments->record_order_event(
                $orderId, $status, $updates['payment_status'] ?? $order['payment_status'],
                isset($input['note']) ? trim((string) $input['note']) : null,
                (int) $auth['sub']
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->api->respond_error($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
        }
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            $this->platform->notify(
                (int) $order['buyer_id'],
                'Order #' . $orderId . ' ' . $status,
                'The order was ' . $status . '. Its batch reservation was released.'
            );
        } elseif (in_array($status, ['preparing', 'shipped', 'in_transit', 'out_for_delivery', 'delivered', 'completed'], true)) {
            $message = 'Order #' . $orderId . ' is now ' . str_replace('_', ' ', $status) . '.';
            if (!empty($input['shipping_provider'])) {
                $message .= ' Delivery: ' . trim((string) $input['shipping_provider']) . '.';
            }
            if (!empty($input['tracking_number'])) {
                $message .= ' Tracking: ' . trim((string) $input['tracking_number']) . '.';
            }
            $this->platform->notify((int) $order['buyer_id'], 'Order update', $message);
        }
        $this->api->respond(['message' => 'Order status updated.']);
    }

    public function collect_payment($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Only the farmer can record COD collection.', 403);
        }
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->api->respond_error('Invalid order ID.', 422);
        }
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT o.buyer_id, o.status, o.payment_method, o.payment_status
                 FROM orders o INNER JOIN order_items oi ON oi.order_id = o.order_id
                 WHERE o.order_id = ? AND oi.seller_id = ? LIMIT 1 FOR UPDATE',
                [$orderId, $auth['sub']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }
            if ($order['payment_method'] !== 'cod' || !in_array($order['status'], ['shipped', 'in_transit', 'out_for_delivery'], true)) {
                throw new RuntimeException('COD payment can only be recorded while the order is being delivered.');
            }
            if ($order['payment_status'] === 'paid') {
                throw new RuntimeException('COD payment has already been recorded.');
            }
            $this->db->table('orders')->where('order_id', $orderId)->update([
                'payment_status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')
            ]);
            $this->payments->record_order_event($orderId, 'payment_paid', 'paid', 'COD payment collected by the farmer.', (int) $auth['sub']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->api->respond_error($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
        }
        $this->platform->notify((int) $order['buyer_id'], 'COD payment recorded', 'Payment for Order #' . $orderId . ' was marked paid.');
        $this->api->respond(['message' => 'COD payment recorded. You can now confirm delivery.']);
    }
}
