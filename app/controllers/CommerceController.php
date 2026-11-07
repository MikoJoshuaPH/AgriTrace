<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CommerceController extends Controller
{
    private $community;
    private $platform;
    private $payments;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->community = $this->call->model('Community_model');
        $this->platform = $this->call->model('Platform_model');
        $this->payments = $this->call->model('Payment_model');
    }

    /** GET /api/orders — the caller's orders and role-specific management or shipping lists. */
    public function index()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $all = $isAdmin && !empty($query['all']);
        $scope = strtolower((string) ($query['scope'] ?? ''));
        if ($scope !== '' && !in_array($scope, ['buyer', 'managing', 'shipments'], true)) {
            $this->api->respond_error('Invalid order scope.', 422);
        }
        if ($scope === 'managing') {
            if (($auth['role'] ?? '') === 'farmer') {
                $this->api->respond(['data' => $this->platform->orders_for_seller((int) $auth['sub'])]);
            }
            if ($isAdmin) {
                $this->api->respond(['data' => $this->platform->orders(null)]);
            }
            $this->api->respond_error('Only farmers and administrators can manage orders.', 403);
        }
        if ($scope === 'shipments') {
            if (($auth['role'] ?? '') !== 'transporter') {
                $this->api->respond_error('Only transporters can view their shipments.', 403);
            }
            $this->api->respond(['data' => $this->platform->orders_for_transporter((int) $auth['sub'])]);
        }
        if ($scope === 'buyer') {
            $this->api->respond(['data' => $this->platform->orders((int) $auth['sub'])]);
        }
        if (($auth['role'] ?? '') === 'farmer' && !$all) {
            $this->api->respond(['data' => $this->platform->orders_for_seller((int) $auth['sub'])]);
        }
        if (($auth['role'] ?? '') === 'transporter' && !$all) {
            $this->api->respond(['data' => $this->platform->orders_for_transporter((int) $auth['sub'])]);
        }
        $this->api->respond(['data' => $this->platform->orders($all ? null : (int) $auth['sub'])]);
    }

    /** GET /api/marketplace/{id}/sales — sale details are visible only to the listing's seller. */
    public function listing_sales($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $listingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listing = $listingId === false ? null : $this->community->listing_owner($listingId);
        if (!$listing) {
            $this->api->respond_error('Product not found.', 404);
        }
        if ((int) $listing['seller_id'] !== (int) $auth['sub']) {
            $this->api->respond_error('Only the product seller can view its sales.', 403);
        }

        $sales = $this->payments->listing_sales($listingId, (int) $auth['sub']);
        foreach ($sales as &$sale) {
            $sale['buyer_name'] = trim(($sale['buyer_first'] ?? '') . ' ' . ($sale['buyer_last'] ?? ''));
            unset($sale['buyer_first'], $sale['buyer_last']);
        }
        unset($sale);
        $this->api->respond(['sales' => $sales]);
    }

    /** GET /api/orders/{id}/payment-status — reconciles online payments with the gateway. */
    public function payment_status($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->api->respond_error('Invalid order ID.', 422);
        }

        $order = $this->payments->order_with_buyer((int) $orderId);
        if (!$order) {
            $this->api->respond_error('Order not found.', 404);
        }
        if (($auth['role'] ?? '') !== 'admin' && (int) $order['buyer_id'] !== (int) $auth['sub']) {
            $this->api->respond_error('Unauthorized access.', 403);
        }
        if (!in_array($order['payment_status'], ['unpaid', 'awaiting_payment', 'payment_processing'], true)) {
            $this->api->respond(['payment_status' => $order['payment_status']]);
        }

        if ($order['payment_method'] !== 'xendit' || empty($order['xendit_invoice_id'])) {
            $this->api->respond(['payment_status' => $order['payment_status']]);
        }
        try {
            $invoice = $this->call->library('Xendit')->get_invoice($order['xendit_invoice_id']);
        } catch (RuntimeException $e) {
            $this->api->respond_error('The payment status could not be checked right now. Please refresh your orders shortly.', 502);
        }
        if (!$invoice || (string) ($invoice['external_id'] ?? '') !== (string) $order['payment_external_id']) {
            $this->api->respond_error('The payment could not be verified with Xendit.', 502);
        }

        $status = strtoupper((string) ($invoice['status'] ?? ''));
        if ($status === 'PAID' || $status === 'SETTLED') {
            if ($this->payments->mark_paid((int) $orderId)) {
                $this->after_payment((int) $orderId);
            }
            $paymentStatus = 'paid';
        } elseif (in_array($status, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
            if ($this->payments->mark_expired((int) $orderId)) {
                $this->platform->audit((int) $order['buyer_id'], 'PAYMENT_FAILED', 'Order', (int) $orderId, 'Batch reservation released');
            }
            $paymentStatus = 'failed';
        } else {
            $paymentStatus = 'unpaid';
        }

        $this->api->respond(['payment_status' => $paymentStatus]);
    }

    /** POST /api/orders/{id}/payment — starts online payment after farmer acceptance. */
    public function start_payment($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->api->respond_error('Invalid order ID.', 422);
        }
        $order = $this->payments->order_with_buyer((int) $orderId);
        if (!$order || (int) $order['buyer_id'] !== (int) $auth['sub']) {
            $this->api->respond_error('Order not found.', 404);
        }
        if ($order['payment_method'] === 'xendit' && $order['status'] === 'awaiting_payment') {
            if (!empty($order['xendit_invoice_url'])) {
                $this->api->respond(['invoice_url' => $order['xendit_invoice_url']]);
            }
            $xendit = $this->call->library('Xendit');
            if (!$xendit->is_configured()) {
                $this->api->respond_error('Xendit payment is no longer configured. Contact the administrator.', 503);
            }
            $input = $this->api->body();
            try {
                $returnUrl = $this->payment_return_url($input['frontend_origin'] ?? null, (int) $orderId);
                $invoice = $xendit->create_invoice(
                    $this->invoice_payload($order, $this->payments->order_items((int) $orderId), $returnUrl)
                );
            } catch (RuntimeException $e) {
                $this->platform->audit((int) $auth['sub'], 'PAYMENT_START_FAILED', 'Order', (int) $orderId, $e->getMessage());
                $this->api->respond_error('Xendit payment could not be started. Contact the administrator if the problem continues.', 502);
            }
            $this->payments->save_invoice((int) $orderId, $invoice['id'], $invoice['invoice_url'], $invoice['external_id']);
            $this->payments->record_order_event((int) $orderId, 'payment_processing', 'payment_processing', 'Xendit invoice created.', (int) $auth['sub']);
            $this->api->respond(['invoice_url' => $invoice['invoice_url'], 'payment_status' => 'payment_processing']);
        }
        $this->api->respond_error('Online payment is available after the farmer accepts the order.', 409);
    }

    /**
     * POST /api/orders {items: [{listing_id, quantity}]}
     * Reserves batch inventory and creates one reviewable order per farmer.
     * Online invoices are created only after the farmer accepts an order.
     */
    public function store()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $buyer = (int) $auth['sub'];
        $input = $this->api->body();
        $items = $input['items'] ?? null;
        $checkoutKey = trim((string) ($input['idempotency_key'] ?? ''));
        $deliveryAddress = trim((string) ($input['delivery_address'] ?? ''));
        $deliveryLatitude = $input['delivery_latitude'] ?? null;
        $deliveryLongitude = $input['delivery_longitude'] ?? null;
        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? '')));
        if (!in_array($paymentMethod, ['cod', 'xendit'], true)) {
            $this->api->respond_error('Choose Cash on Delivery or Xendit online payment.', 422);
        }
        if ($paymentMethod === 'xendit' && !$this->call->library('Xendit')->is_configured()) {
            $this->api->respond_error('Xendit online payment is not configured. Choose Cash on Delivery or contact the administrator.', 503);
        }
        if (!is_array($items) || count($items) === 0 || count($items) > 50) {
            $this->api->respond_error('Your cart is empty.', 422);
        }
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $checkoutKey)) {
            $this->api->respond_error('A valid checkout request key is required. Please retry checkout.', 422);
        }
        if ($deliveryAddress === '' || strlen($deliveryAddress) > 1000) {
            $this->api->respond_error('Enter a delivery address (maximum 1000 characters).', 422);
        }
        if (($deliveryLatitude === null) !== ($deliveryLongitude === null)) {
            $this->api->respond_error('Choose a complete delivery map location.', 422);
        }
        if ($deliveryLatitude !== null) {
            if (!is_numeric($deliveryLatitude) || !is_numeric($deliveryLongitude)
                || (float) $deliveryLatitude < -90 || (float) $deliveryLatitude > 90
                || (float) $deliveryLongitude < -180 || (float) $deliveryLongitude > 180) {
                $this->api->respond_error('Choose a valid delivery map location.', 422);
            }
            $deliveryLatitude = (float) $deliveryLatitude;
            $deliveryLongitude = (float) $deliveryLongitude;
        }

        $lines = [];
        foreach ($items as $item) {
            $listingId = filter_var($item['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $batchId = array_key_exists('batch_id', $item)
                ? filter_var($item['batch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null;
            if ($listingId === false || $quantity === false || $quantity <= 0
                || (array_key_exists('batch_id', $item) && $batchId === false)) {
                $this->api->respond_error('Each cart item needs a valid listing, batch, and quantity greater than zero.', 422);
            }
            $addedAt = is_string($item['cart_added_at'] ?? null) ? strtotime($item['cart_added_at']) : false;
            $lines[] = [
                'listing_id' => $listingId,
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'cart_added_at' => $addedAt === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $addedAt)
            ];
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM users WHERE id = ? FOR UPDATE', [$buyer])->fetch(PDO::FETCH_ASSOC);
            $existingOrders = $this->db->raw(
                'SELECT order_id, seller_id, total_amount AS total, payment_method
                 FROM orders WHERE buyer_id = ? AND checkout_key = ? ORDER BY order_id',
                [$buyer, $checkoutKey]
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($existingOrders) {
                $this->db->commit();
                $this->api->respond([
                    'message' => 'This checkout was already submitted.',
                    'orders' => $existingOrders,
                    'payment_status' => 'unpaid',
                    'payment_method' => $paymentMethod
                ], 200);
            }
            $preparedBySeller = [];
            $sellerTotals = [];
            foreach ($lines as $line) {
                $listing = $this->db->raw(
                    'SELECT listing_id, seller_id, product_name, category, price, stock, unit, selling_unit,
                            catalog_id, farm_id, batch_id, is_available
                     FROM marketplace_listings WHERE listing_id = ? FOR UPDATE',
                    [$line['listing_id']]
                )->fetch(PDO::FETCH_ASSOC);

                if (!$listing || (int) $listing['is_available'] !== 1) {
                    throw new RuntimeException('One of the products is no longer available.');
                }
                if ((int) $listing['seller_id'] === $buyer) {
                    throw new RuntimeException('You cannot order your own product.');
                }
                if (empty($listing['batch_id']) && empty($line['batch_id'])) {
                    throw new RuntimeException($listing['product_name'] . ' is not linked to an available harvest batch.');
                }
                $selectedBatchId = $line['batch_id'] ?: (int) $listing['batch_id'];
                $batch = $this->db->raw(
                    'SELECT pb.batch_id, pb.batch_code, pb.product_id, pb.farm_id, f.farm_name,
                            pb.quantity, pb.available_quantity,
                            pb.unit, pb.unit_price, pb.selling_unit, pb.current_status, pb.harvest_date, pb.expiration_date
                     FROM product_batches pb INNER JOIN farms f ON f.farm_id = pb.farm_id
                     WHERE pb.batch_id = ? FOR UPDATE',
                    [$selectedBatchId]
                )->fetch(PDO::FETCH_ASSOC);
                $available = $batch && $batch['available_quantity'] !== null
                    ? (float) $batch['available_quantity']
                    : (float) ($batch['quantity'] ?? 0);
                if (!$batch || (int) $batch['farm_id'] !== (int) $listing['farm_id']
                    || ($listing['catalog_id'] !== null && (int) $batch['product_id'] !== (int) $listing['catalog_id'])) {
                    throw new RuntimeException($listing['product_name'] . ' no longer matches its harvest batch.');
                }
                if (!empty($batch['expiration_date']) && $batch['expiration_date'] < date('Y-m-d')) {
                    throw new RuntimeException($listing['product_name'] . ' has expired and is no longer available.');
                }
                if (in_array($batch['current_status'], ['sold', 'rejected'], true)) {
                    throw new RuntimeException($listing['product_name'] . ' is no longer available.');
                }
                $sellingUnit = $listing['selling_unit'] === 'other'
                    ? $listing['unit']
                    : ($listing['selling_unit'] ?: $listing['unit']);
                if (strtolower((string) $sellingUnit) !== strtolower((string) $batch['unit'])) {
                    throw new RuntimeException($listing['product_name'] . ' is sold in a unit that does not match its batch inventory unit.');
                }
                if ($batch['selling_unit'] !== null
                    && strtolower((string) $batch['selling_unit']) !== strtolower((string) $listing['selling_unit'])) {
                    throw new RuntimeException($listing['product_name'] . ' has a marketplace listing that does not match its batch selling unit.');
                }
                if ($batch['unit_price'] !== null) {
                    $listing['price'] = (float) $batch['unit_price'];
                }
                if ($available < $line['quantity']) {
                    throw new RuntimeException('Not enough batch stock for ' . $listing['product_name'] . '.');
                }
                $changed = $this->db->raw(
                    'UPDATE product_batches
                     SET available_quantity = COALESCE(available_quantity, quantity) - ?
                     WHERE batch_id = ? AND COALESCE(available_quantity, quantity) >= ?',
                    [$line['quantity'], (int) $batch['batch_id'], $line['quantity']]
                );
                if ($changed->rowCount() !== 1) {
                    throw new RuntimeException('Batch stock changed while placing your order. Please review your cart.');
                }
                $sellerId = (int) $listing['seller_id'];
                $preparedBySeller[$sellerId][] = [
                    'listing' => $listing, 'batch' => $batch, 'quantity' => $line['quantity'],
                    'cart_added_at' => $line['cart_added_at']
                ];
                $sellerTotals[$sellerId] = ($sellerTotals[$sellerId] ?? 0)
                    + (float) $listing['price'] * $line['quantity'];
            }

            $orders = [];
            foreach ($preparedBySeller as $sellerId => $sellerItems) {
                $orderId = (int) $this->db->table('orders')->insert([
                    'buyer_id' => $buyer,
                    'seller_id' => $sellerId,
                    'checkout_key' => $checkoutKey,
                    'delivery_address' => $deliveryAddress,
                    'delivery_latitude' => $deliveryLatitude,
                    'delivery_longitude' => $deliveryLongitude,
                    'total_amount' => round($sellerTotals[$sellerId], 2),
                    'payment_method' => $paymentMethod,
                    'status' => 'pending',
                    'payment_status' => 'unpaid'
                ]);
                foreach ($sellerItems as $row) {
                    $listing = $row['listing'];
                    $batch = $row['batch'];
                    $itemId = (int) $this->db->table('order_items')->insert([
                        'order_id' => $orderId, 'listing_id' => $listing['listing_id'], 'seller_id' => $listing['seller_id'],
                        'product_name' => $listing['product_name'], 'unit_price' => $listing['price'],
                        'quantity' => $row['quantity'], 'unit' => $batch['unit'],
                        'selling_unit' => $listing['selling_unit'] ?: $batch['unit'],
                        'batch_unit' => $batch['unit'], 'batch_id' => $batch['batch_id'],
                        'batch_code' => $batch['batch_code'], 'farm_id' => $batch['farm_id'],
                        'farm_name' => $batch['farm_name'], 'harvest_date' => $batch['harvest_date'],
                        'expiration_date' => $batch['expiration_date'],
                        'cart_added_at' => $row['cart_added_at'],
                        'catalog_id' => $listing['catalog_id'] ?? null,
                        'subtotal' => round((float) $listing['price'] * $row['quantity'], 2),
                    ]);
                    $this->db->table('batch_allocations')->insert([
                        'order_item_id' => $itemId,
                        'batch_id' => $batch['batch_id'],
                        'quantity' => $row['quantity'],
                        'created_by' => $buyer
                    ]);
                    $this->db->table('inventory_movements')->insert([
                        'listing_id' => $listing['listing_id'],
                        'batch_id' => $batch['batch_id'],
                        'order_id' => $orderId,
                        'movement_type' => 'reserve',
                        'quantity' => $row['quantity'],
                        'unit' => $batch['unit'],
                        'reference' => 'ORDER-' . $orderId,
                        'created_by' => $buyer
                    ]);
                }
                $this->payments->record_order_event($orderId, 'pending', 'unpaid', 'Order placed; batch inventory reserved.', $buyer);
                $orders[] = [
                    'order_id' => $orderId,
                    'seller_id' => $sellerId,
                    'total' => round($sellerTotals[$sellerId], 2),
                    'payment_method' => $paymentMethod
                ];
            }
            $this->db->commit();
            foreach ($orders as $createdOrder) {
                $this->platform->notify(
                    (int) $createdOrder['seller_id'],
                    'New marketplace order',
                    'Order #' . $createdOrder['order_id'] . ' is waiting for your review.'
                );
                $this->platform->audit($buyer, 'PLACE_ORDER', 'Order', (int) $createdOrder['order_id'], 'Reserved batch inventory; ' . $paymentMethod . ' payment');
            }
        } catch (RuntimeException $e) {
            $this->db->roll_back();
            $this->api->respond_error($e->getMessage(), 422);
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->api->respond_error('The order could not be placed. Please try again.', 500);
        }

        foreach ($orders as $createdOrder) {
            $this->platform->notify(
                $buyer,
                'Order #' . $createdOrder['order_id'] . ' placed',
                $paymentMethod === 'cod'
                    ? 'Your COD order is waiting for farmer review.'
                    : 'Your online-payment order is waiting for farmer review. Payment opens after acceptance.'
            );
        }
        $this->api->respond([
            'message' => count($orders) > 1
                ? 'Your cart was split into separate orders for each farmer.'
                : 'Your order was placed and is waiting for farmer review.',
            'orders' => $orders,
            'payment_status' => 'unpaid',
            'payment_method' => $paymentMethod
        ], 201);
    }

    /**
     * POST /api/payments/xendit/webhook — Xendit payment callbacks.
     * The callback token is checked, and the invoice status is read back from Xendit before anything changes.
     */
    public function xendit_webhook()
    {
        $this->api->require_method('POST');
        $xendit = $this->call->library('Xendit');
        $token = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
        if (!$xendit->callback_token_valid($token)) {
            $this->api->respond_error('Invalid callback token.', 401);
        }

        $body = $this->api->body();
        $invoiceId = is_string($body['id'] ?? null) ? $body['id'] : '';
        if ($invoiceId === '') {
            $this->api->respond_error('Invoice id is missing.', 400);
        }

        $invoice = $xendit->get_invoice($invoiceId);
        if ($invoice === null) {
            $this->api->respond_error('Unknown invoice.', 404);
        }
        if (!preg_match('/^AGT-ORDER-(\d+)-/', (string) ($invoice['external_id'] ?? ''), $m)) {
            $this->api->respond_error('Unknown invoice.', 404);
        }
        $orderId = (int) $m[1];
        $order = $this->payments->order_with_buyer($orderId);
        if (!$order || (string) $order['xendit_invoice_id'] !== (string) $invoiceId) {
            $this->api->respond_error('Unknown order.', 404);
        }

        $status = strtoupper((string) ($invoice['status'] ?? ''));
        if ($status === 'PAID' || $status === 'SETTLED') {
            if ($this->payments->mark_paid($orderId)) {
                $this->after_payment($orderId);
            }
        } elseif (in_array($status, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
            if ($this->payments->mark_expired($orderId)) {
                $this->platform->audit((int) $order['buyer_id'], 'PAYMENT_FAILED', 'Order', $orderId, 'Batch reservation released');
            }
        }

        $this->api->respond(['received' => true, 'order_id' => $orderId, 'status' => $status]);
    }

    /** Runs once per order after payment: trace codes, receipt email and notifications. */
    private function after_payment($orderId)
    {
        $order = $this->payments->order_with_buyer($orderId);
        $traces = $this->payments->ensure_trace_codes($orderId);

        $items = $this->payments->order_items($orderId);
        $byItem = [];
        foreach ($traces as $trace) {
            $byItem[$trace['order_item_id']] = $trace['trace_code'];
        }
        foreach ($items as &$item) {
            $item['trace_code'] = $byItem[$item['item_id']] ?? '';
        }
        unset($item);
        $order['items'] = $items;

        $mailer = $this->call->library('Mailer');
        if ($mailer->is_configured() && $order['buyer_email']) {
            try {
                $name = trim($order['buyer_first'] . ' ' . $order['buyer_last']) ?: $order['buyer_username'];
                $mailer->send($order['buyer_email'], 'AgriTrace receipt #' . $orderId,
                    $mailer->receipt_email($name, $order));
                $this->platform->audit((int) $order['buyer_id'], 'RECEIPT_SENT', 'Order', $orderId, $order['buyer_email']);
            } catch (RuntimeException $e) {
                $this->platform->audit((int) $order['buyer_id'], 'RECEIPT_FAILED', 'Order', $orderId, $e->getMessage());
            }
        }

        $this->platform->notify((int) $order['buyer_id'], 'Payment received', 'Order #' . $orderId . ' is paid. The farmer will prepare the order and create its product trace QR code.');
        foreach (array_unique(array_map(fn ($i) => (int) $i['seller_id'], $items)) as $sellerId) {
            $this->platform->notify($sellerId, 'Paid order', 'Order #' . $orderId . ' was paid. Prepare the products for delivery.');
        }
        $this->platform->audit((int) $order['buyer_id'], 'PAYMENT_PAID', 'Order', $orderId, 'Trace codes created');
    }

    private function payment_return_url($requestedOrigin, $orderId)
    {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $requestParts = parse_url($requestOrigin);
        $requestedParts = is_string($requestedOrigin) ? parse_url($requestedOrigin) : false;
        if ($requestParts && $requestedParts
            && isset($requestParts['scheme'], $requestParts['host'], $requestedParts['scheme'], $requestedParts['host'])
            && strtolower($requestParts['scheme']) === strtolower($requestedParts['scheme'])
            && strtolower($requestParts['host']) === strtolower($requestedParts['host'])
            && ($requestParts['port'] ?? null) === ($requestedParts['port'] ?? null)
            && !isset($requestedParts['user'])
            && !isset($requestedParts['pass'])
            && !isset($requestedParts['path'])
            && !isset($requestedParts['query'])
            && !isset($requestedParts['fragment'])) {
            $origin = rtrim($requestedOrigin, '/');
        } else {
            $origin = rtrim(config_item('app_frontend_url'), '/');
        }
        return $origin . '/dashboard?payment=return&order=' . (int) $orderId . '#orders';
    }

    private function invoice_payload(array $order, array $items, $returnUrl)
    {
        $lines = array_map(fn ($i) => $i['product_name'] . ' x' . $i['quantity'] . ' ' . $i['unit'], $items);
        $name = trim(($order['buyer_first'] ?? '') . ' ' . ($order['buyer_last'] ?? '')) ?: $order['buyer_username'];
        return [
            'external_id' => 'AGT-ORDER-' . $order['order_id'] . '-' . bin2hex(random_bytes(3)),
            'amount' => (float) $order['total_amount'],
            'payer_email' => $order['buyer_email'],
            'description' => 'AgriTrace order #' . $order['order_id'] . ': ' . implode(', ', $lines),
            'currency' => 'PHP',
            'invoice_duration' => 86400,
            'customer' => ['given_names' => $name, 'email' => $order['buyer_email']],
            'success_redirect_url' => $returnUrl,
            'failure_redirect_url' => $returnUrl,
        ];
    }

}
