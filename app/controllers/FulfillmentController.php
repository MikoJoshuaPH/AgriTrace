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

    public function nearby_transporters($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (!in_array($auth['role'] ?? '', ['farmer', 'admin'], true)) {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->api->respond_error('Invalid order ID.', 422);
        }
        $order = $this->order_for_shipping((int) $orderId, (int) $auth['sub'], ($auth['role'] ?? '') === 'admin');
        if (!$order || $order['status'] !== 'ready_for_shipment') {
            $this->api->respond_error('Prepare the order before choosing a transporter.', 409);
        }
        if ($order['payment_method'] === 'xendit' && $order['payment_status'] !== 'paid') {
            $this->api->respond_error('Verified payment is required before shipping.', 409);
        }
        $farm = $this->db->raw(
            'SELECT oi.farm_id, f.farm_name, f.farm_address, f.barangay, f.municipality, f.province,
                    f.latitude, f.longitude
             FROM order_items oi LEFT JOIN farms f ON f.farm_id = oi.farm_id
             WHERE oi.order_id = ? ORDER BY oi.item_id LIMIT 1',
            [(int) $orderId]
        )->fetch(PDO::FETCH_ASSOC);
        $farmLocation = $farm ? implode(', ', array_filter([
            $farm['farm_address'], $farm['barangay'], $farm['municipality'], $farm['province']
        ])) : '';
        $transporters = $this->db->raw(
            "SELECT id, username, first_name, last_name, transport_origin, vehicle_number,
                    vehicle_type, location_label, latitude, longitude
             FROM users
             WHERE role = 'transporter' AND is_active = 1
               AND transport_origin IS NOT NULL AND transport_origin <> ''
               AND vehicle_number IS NOT NULL AND vehicle_number <> ''
               AND vehicle_type IS NOT NULL AND vehicle_type <> ''
               AND latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY first_name, last_name LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($transporters as &$transporter) {
            $transporter['id'] = (int) $transporter['id'];
            $transporter['latitude'] = (float) $transporter['latitude'];
            $transporter['longitude'] = (float) $transporter['longitude'];
            $transporter['distance_km'] = $farm && $farm['latitude'] !== null && $farm['longitude'] !== null
                ? round($this->distance_km(
                    (float) $farm['latitude'],
                    (float) $farm['longitude'],
                    $transporter['latitude'],
                    $transporter['longitude']
                ), 1)
                : null;
        }
        unset($transporter);
        usort($transporters, static function ($left, $right) {
            if ($left['distance_km'] === null) return $right['distance_km'] === null ? 0 : 1;
            if ($right['distance_km'] === null) return -1;
            return $left['distance_km'] <=> $right['distance_km'];
        });
        $assignment = $this->db->raw(
            "SELECT os.assignment_id, os.status, os.transporter_id, u.first_name, u.last_name, u.username
             FROM order_shipments os INNER JOIN users u ON u.id = os.transporter_id
             WHERE os.order_id = ? AND os.status NOT IN ('rejected','cancelled')
             ORDER BY os.assignment_id DESC LIMIT 1",
            [(int) $orderId]
        )->fetch(PDO::FETCH_ASSOC);
        $this->api->respond([
            'farm' => $farm ? [
                'farm_id' => (int) $farm['farm_id'],
                'name' => $farm['farm_name'],
                'location' => $farmLocation ?: 'Registered farm location',
                'latitude' => $farm['latitude'] === null ? null : (float) $farm['latitude'],
                'longitude' => $farm['longitude'] === null ? null : (float) $farm['longitude'],
            ] : null,
            'assignment' => $assignment ?: null,
            'data' => $transporters,
        ]);
    }

    public function assign_transporter($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (!in_array($auth['role'] ?? '', ['farmer', 'admin'], true)) {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $input = $this->api->body();
        $transporterId = filter_var($input['transporter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false || $transporterId === false) {
            $this->api->respond_error('Select a valid transporter.', 422);
        }
        $order = $this->order_for_shipping((int) $orderId, (int) $auth['sub'], ($auth['role'] ?? '') === 'admin');
        if (!$order || $order['status'] !== 'ready_for_shipment') {
            $this->api->respond_error('Only a prepared order can be assigned for shipping.', 409);
        }
        if ($order['payment_method'] === 'xendit' && $order['payment_status'] !== 'paid') {
            $this->api->respond_error('Verified payment is required before shipping.', 409);
        }
        $transporter = $this->db->raw(
            "SELECT id, vehicle_number, transport_origin, latitude, longitude
             FROM users WHERE id = ? AND role = 'transporter' AND is_active = 1
               AND vehicle_number IS NOT NULL AND vehicle_number <> ''
               AND transport_origin IS NOT NULL AND transport_origin <> ''
               AND vehicle_type IS NOT NULL AND vehicle_type <> ''
               AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1",
            [(int) $transporterId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$transporter) {
            $this->api->respond_error('This transporter has not completed their shipping profile.', 422);
        }
        $farm = $this->db->raw(
            'SELECT f.farm_name, f.farm_address, f.barangay, f.municipality, f.province
             FROM order_items oi LEFT JOIN farms f ON f.farm_id = oi.farm_id
             WHERE oi.order_id = ? ORDER BY oi.item_id LIMIT 1',
            [(int) $orderId]
        )->fetch(PDO::FETCH_ASSOC);
        $origin = $farm ? implode(', ', array_filter([
            $farm['farm_name'], $farm['farm_address'], $farm['barangay'], $farm['municipality'], $farm['province']
        ])) : 'Registered farm';
        $this->db->transaction();
        try {
            $lockedOrder = $this->db->raw(
                'SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE',
                [(int) $orderId]
            )->fetch(PDO::FETCH_ASSOC);
            $currentOrder = $lockedOrder
                ? $this->order_for_shipping((int) $orderId, (int) $auth['sub'], ($auth['role'] ?? '') === 'admin')
                : null;
            if (!$currentOrder || $currentOrder['status'] !== 'ready_for_shipment'
                || ($currentOrder['payment_method'] === 'xendit' && $currentOrder['payment_status'] !== 'paid')) {
                throw new RuntimeException('The order is no longer ready for shipping.');
            }
            $activeAssignment = $this->db->raw(
                "SELECT assignment_id FROM order_shipments
                 WHERE order_id = ? AND status NOT IN ('rejected','cancelled') LIMIT 1",
                [(int) $orderId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($activeAssignment) {
                throw new RuntimeException('A transporter is already assigned to this order.');
            }
            $assignmentId = (int) $this->db->table('order_shipments')->insert([
                'order_id' => (int) $orderId,
                'farmer_id' => (int) $currentOrder['farmer_id'],
                'transporter_id' => (int) $transporterId,
                'status' => 'offered',
                'origin' => substr($origin, 0, 255),
                'destination' => substr((string) $currentOrder['delivery_address'], 0, 255),
                'vehicle_number' => $transporter['vehicle_number'],
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->api->respond_error($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
        }
        $this->platform->notify(
            (int) $transporterId,
            'New delivery request',
            'Review Order #' . $orderId . ' and its product traceability details before accepting.'
        );
        $this->platform->audit((int) $auth['sub'], 'ASSIGN_TRANSPORTER', 'Order', (int) $orderId, 'Assignment #' . $assignmentId);
        $this->api->respond(['message' => 'Shipping request sent to the transporter.', 'assignment_id' => $assignmentId], 201);
    }

    public function save_order_trace_qr($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $input = $this->api->body();
        $traceCode = trim((string) ($input['trace_code'] ?? ''));
        $imageData = (string) ($input['image_data'] ?? '');
        if ($orderId === false || !preg_match('/^AGT-[A-F0-9]{10}$/', $traceCode)) {
            $this->api->respond_error('Invalid order or trace code.', 422);
        }
        $order = $this->order_for_shipping((int) $orderId, (int) $auth['sub']);
        if (!$order || $order['status'] !== 'ready_for_shipment') {
            $this->api->respond_error('Prepare the order before saving its QR code.', 409);
        }
        $trace = $this->db->raw(
            'SELECT tc.trace_id FROM trace_codes tc
             INNER JOIN order_items oi ON oi.item_id = tc.order_item_id
             WHERE tc.trace_code = ? AND oi.order_id = ? AND oi.seller_id = ? LIMIT 1',
            [$traceCode, (int) $orderId, (int) $auth['sub']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$trace) {
            $this->api->respond_error('Trace code not found for this order.', 404);
        }
        if (strpos($imageData, 'data:image/png;base64,') === 0) {
            $imageData = substr($imageData, strlen('data:image/png;base64,'));
        }
        $png = base64_decode($imageData, true);
        if ($png === false || strlen($png) > 524288 || strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            $this->api->respond_error('A valid PNG QR image smaller than 512 KB is required.', 422);
        }
        $imageInfo = getimagesizefromstring($png);
        if (!$imageInfo || ($imageInfo['mime'] ?? '') !== 'image/png' || $imageInfo[0] > 1024 || $imageInfo[1] > 1024) {
            $this->api->respond_error('The QR image dimensions or format are not valid.', 422);
        }
        try {
            $stored = $this->call->library('Cloud_storage')->put('qr/' . $traceCode . '.png', $png, 'image/png');
        } catch (RuntimeException $e) {
            $this->api->respond_error('The generated QR image could not be saved: ' . $e->getMessage(), 502);
        }
        $this->payments->set_trace_qr((int) $trace['trace_id'], $stored['url']);
        $this->api->respond(['message' => 'Trace QR code saved.', 'qr_url' => $stored['url']]);
    }

    public function transporter_status($assignmentId)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'transporter') {
            $this->api->respond_error('Transporter access is required.', 403);
        }
        $assignmentId = filter_var($assignmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $input = $this->api->body();
        $status = trim((string) ($input['status'] ?? ''));
        if ($assignmentId === false || !in_array($status, ['accepted', 'rejected', 'in_transit', 'out_for_delivery', 'delivered'], true)) {
            $this->api->respond_error('Invalid shipping update.', 422);
        }
        $this->db->transaction();
        try {
            $assignment = $this->db->raw(
                'SELECT os.assignment_id, os.order_id, os.farmer_id, os.status AS shipping_status,
                        o.buyer_id, o.status AS order_status, o.payment_status, o.payment_method,
                        o.delivery_address
                 FROM order_shipments os INNER JOIN orders o ON o.order_id = os.order_id
                 WHERE os.assignment_id = ? AND os.transporter_id = ? FOR UPDATE',
                [(int) $assignmentId, (int) $auth['sub']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new RuntimeException('Shipping request not found.');
            }
            $transitions = [
                'offered' => ['accepted', 'rejected'],
                'accepted' => ['in_transit'],
                'in_transit' => ['out_for_delivery'],
                'out_for_delivery' => ['delivered'],
            ];
            if (!in_array($status, $transitions[$assignment['shipping_status']] ?? [], true)) {
                throw new RuntimeException('That shipping update is not allowed.');
            }
            $expectedOrderStatus = [
                'offered' => 'ready_for_shipment',
                'accepted' => 'shipped',
                'in_transit' => 'in_transit',
                'out_for_delivery' => 'out_for_delivery',
            ];
            if (($expectedOrderStatus[$assignment['shipping_status']] ?? null) !== $assignment['order_status']) {
                throw new RuntimeException('This order is no longer at the expected shipping stage.');
            }
            $assignmentUpdates = [
                'status' => $status,
                'remarks' => isset($input['remarks']) ? substr(trim((string) $input['remarks']), 0, 500) : null,
            ];
            if ($status === 'accepted') {
                $assignmentUpdates['accepted_at'] = date('Y-m-d H:i:s');
            }
            if ($status === 'delivered') {
                $assignmentUpdates['completed_at'] = date('Y-m-d H:i:s');
            }
            $this->db->table('order_shipments')->where('assignment_id', (int) $assignmentId)->update($assignmentUpdates);
            if ($status === 'accepted') {
                $this->db->raw(
                    "INSERT INTO shipments (order_id, batch_id, transporter_id, vehicle_number, origin, destination, shipment_status)
                     SELECT ?, oi.batch_id, ?, u.vehicle_number, os.origin, os.destination, 'pending'
                     FROM order_items oi
                     INNER JOIN order_shipments os ON os.order_id = oi.order_id
                     INNER JOIN users u ON u.id = os.transporter_id
                     WHERE oi.order_id = ? AND oi.batch_id IS NOT NULL AND os.assignment_id = ?
                     GROUP BY oi.batch_id, u.vehicle_number, os.origin, os.destination",
                     [(int) $assignment['order_id'], (int) $auth['sub'], (int) $assignment['order_id'], (int) $assignmentId]
                );
                $this->db->table('orders')->where('order_id', (int) $assignment['order_id'])->update([
                    'status' => 'shipped',
                    'shipping_provider' => 'AgriTrace transporter',
                ]);
                $this->db->raw(
                    'INSERT INTO inventory_movements (listing_id, batch_id, order_id, movement_type, quantity, unit, reference, created_by)
                     SELECT oi.listing_id, ba.batch_id, oi.order_id, ?, ba.quantity, oi.unit, ?, ?
                     FROM order_items oi INNER JOIN batch_allocations ba ON ba.order_item_id = oi.item_id
                     WHERE oi.order_id = ?',
                    ['sale', 'ORDER-' . $assignment['order_id'], (int) $auth['sub'], (int) $assignment['order_id']]
                );
            } elseif (in_array($status, ['in_transit', 'out_for_delivery'], true)) {
                $orderStatus = $status;
                $this->db->table('orders')->where('order_id', (int) $assignment['order_id'])->update(['status' => $orderStatus]);
                $this->db->raw(
                    "UPDATE shipments SET shipment_status = 'in_transit', dispatch_time = COALESCE(dispatch_time, ?)
                     WHERE order_id = ?",
                    [date('Y-m-d H:i:s'), (int) $assignment['order_id']]
                );
            } elseif ($status === 'delivered') {
                $orderStatus = $assignment['payment_method'] === 'cod' && $assignment['payment_status'] !== 'paid'
                    ? 'out_for_delivery'
                    : 'delivered';
                $this->db->table('orders')->where('order_id', (int) $assignment['order_id'])->update(['status' => $orderStatus]);
                $this->db->raw(
                    "UPDATE shipments SET shipment_status = 'delivered', delivery_time = ? WHERE order_id = ?",
                    [date('Y-m-d H:i:s'), (int) $assignment['order_id']]
                );
            }
            $eventStatus = $status === 'accepted' ? 'shipped' : ($status === 'delivered' && $assignment['payment_method'] === 'cod' && $assignment['payment_status'] !== 'paid' ? 'out_for_delivery' : $status);
            $this->payments->record_order_event(
                (int) $assignment['order_id'],
                $eventStatus,
                $assignment['payment_status'],
                'Transporter updated shipping: ' . str_replace('_', ' ', $status) . '.',
                (int) $auth['sub']
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            $httpStatus = $e instanceof RuntimeException ? 409 : 500;
            $this->api->respond_error($e->getMessage(), $httpStatus);
        }
        if ($status === 'rejected') {
            $this->platform->notify((int) $assignment['farmer_id'], 'Transporter declined Order #' . $assignment['order_id'], 'Choose another nearby transporter for this prepared order.');
        } else {
            $this->platform->notify((int) $assignment['buyer_id'], 'Shipping update', 'Order #' . $assignment['order_id'] . ' is now ' . str_replace('_', ' ', $status) . '.');
            $this->platform->notify((int) $assignment['farmer_id'], 'Shipping update', 'Order #' . $assignment['order_id'] . ' is now ' . str_replace('_', ' ', $status) . '.');
        }
        $this->api->respond(['message' => 'Shipping status updated.']);
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
                    'preparing' => ['ready_for_shipment', 'cancelled'],
                    'ready_for_shipment' => ['cancelled'],
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
            if ($status === 'ready_for_shipment') {
                $this->create_order_traceability($orderId, (int) $auth['sub']);
            }
            if ($role === 'farmer' && in_array($status, ['shipped', 'in_transit', 'out_for_delivery'], true)) {
                $hasTransporter = $this->db->raw(
                    "SELECT assignment_id FROM order_shipments
                     WHERE order_id = ? AND status NOT IN ('rejected','cancelled') LIMIT 1",
                    [(int) $orderId]
                )->fetch(PDO::FETCH_ASSOC);
                if ($hasTransporter) {
                    throw new RuntimeException('The assigned transporter must update the shipping progress.');
                }
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
            $httpStatus = $e instanceof RuntimeException ? 409 : 500;
            if (strpos($e->getMessage(), 'Trace QR generation failed:') === 0) {
                $httpStatus = 502;
            }
            $this->api->respond_error($e->getMessage(), $httpStatus);
        }
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            $this->platform->notify(
                (int) $order['buyer_id'],
                'Order #' . $orderId . ' ' . $status,
                'The order was ' . $status . '. Its batch reservation was released.'
            );
        } elseif (in_array($status, ['preparing', 'ready_for_shipment', 'shipped', 'in_transit', 'out_for_delivery', 'delivered', 'completed'], true)) {
            $message = 'Order #' . $orderId . ' is now ' . str_replace('_', ' ', $status) . '.';
            if ($status === 'ready_for_shipment') {
                $message .= ' Product traceability and QR codes are available.';
            }
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

    private function order_for_shipping($orderId, $userId, $isAdmin = false)
    {
        return $this->db->raw(
            'SELECT o.order_id, o.status, o.payment_method, o.payment_status, o.delivery_address,
                    (SELECT oi.seller_id FROM order_items oi WHERE oi.order_id = o.order_id
                     ORDER BY oi.item_id LIMIT 1) AS farmer_id
             FROM orders o
             WHERE o.order_id = ? AND (? = 1 OR EXISTS (
                 SELECT 1 FROM order_items oi WHERE oi.order_id = o.order_id AND oi.seller_id = ?
             ))
             LIMIT 1',
            [(int) $orderId, $isAdmin ? 1 : 0, (int) $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function create_order_traceability($orderId, $actorId)
    {
        $traces = $this->payments->ensure_trace_codes((int) $orderId);
        if (!$traces) {
            throw new RuntimeException('No product lines are available to prepare for traceability.');
        }
        $batches = $this->db->raw(
            'SELECT DISTINCT pb.batch_id, f.farm_name, f.farm_address, f.barangay, f.municipality, f.province,
                    f.latitude, f.longitude
             FROM order_items oi
             INNER JOIN product_batches pb ON pb.batch_id = oi.batch_id
             INNER JOIN farms f ON f.farm_id = pb.farm_id
             WHERE oi.order_id = ?',
            [(int) $orderId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($batches as $batch) {
            $note = 'Order #' . (int) $orderId . ' prepared; harvest and expiration dates are available in the batch record.';
            $alreadyLogged = $this->db->raw(
                'SELECT log_id FROM traceability_logs WHERE batch_id = ? AND updated_by = ? AND remarks = ? LIMIT 1',
                [(int) $batch['batch_id'], (int) $actorId, $note]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$alreadyLogged) {
                $location = implode(', ', array_filter([
                    $batch['farm_name'], $batch['farm_address'], $batch['barangay'],
                    $batch['municipality'], $batch['province']
                ]));
                $this->db->table('traceability_logs')->insert([
                    'batch_id' => (int) $batch['batch_id'],
                    'updated_by' => (int) $actorId,
                    'status' => 'available_at_market',
                    'location_name' => substr($location, 0, 255),
                    'latitude' => $batch['latitude'],
                    'longitude' => $batch['longitude'],
                    'remarks' => $note,
                ]);
            }
        }
    }

    private function distance_km($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
        return 2 * $earthRadius * asin(min(1, sqrt($a)));
    }
}
