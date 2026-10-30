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

    /** GET /api/orders — the caller's orders (admins may pass ?all=1). */
    public function index()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $all = $isAdmin && !empty($query['all']);
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

    /** GET /api/orders/{id}/payment-status — reconcile the invoice after returning from Xendit. */
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
        if ($order['payment_status'] !== 'unpaid' || empty($order['xendit_invoice_id'])) {
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
        } elseif ($status === 'EXPIRED') {
            if ($this->payments->mark_expired((int) $orderId)) {
                $this->platform->audit((int) $order['buyer_id'], 'PAYMENT_EXPIRED', 'Order', (int) $orderId, 'Stock released');
            }
            $paymentStatus = 'expired';
        } else {
            $paymentStatus = 'unpaid';
        }

        $this->api->respond(['payment_status' => $paymentStatus]);
    }

    /**
     * POST /api/orders {items: [{listing_id, quantity}]}
     * Reserves the stock in one transaction, then opens a Xendit invoice for the total.
     * If the invoice cannot be created the reservation is released.
     */
    public function store()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $buyer = (int) $auth['sub'];
        $input = $this->api->body();
        $items = $input['items'] ?? null;
        $returnOrigin = $input['frontend_origin'] ?? null;

        $xendit = $this->call->library('Xendit');
        if (!$xendit->is_configured()) {
            $this->api->respond_error('Online payment is not available right now. Please try again later.', 503);
        }
        if (!is_array($items) || count($items) === 0 || count($items) > 50) {
            $this->api->respond_error('Your cart is empty.', 422);
        }

        $lines = [];
        foreach ($items as $item) {
            $listingId = filter_var($item['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($listingId === false || $quantity === false || $quantity <= 0) {
                $this->api->respond_error('Each cart item needs a listing and a quantity greater than zero.', 422);
            }
            $lines[] = ['listing_id' => $listingId, 'quantity' => $quantity];
        }

        $this->db->transaction();
        try {
            $total = 0.0;
            $prepared = [];
            foreach ($lines as $line) {
                $listing = $this->db->raw(
                    'SELECT listing_id, seller_id, product_name, price, stock, unit, is_available
                     FROM marketplace_listings WHERE listing_id = ? FOR UPDATE',
                    [$line['listing_id']]
                )->fetch(PDO::FETCH_ASSOC);

                if (!$listing || (int) $listing['is_available'] !== 1) {
                    throw new RuntimeException('One of the products is no longer available.');
                }
                if ((int) $listing['seller_id'] === $buyer) {
                    throw new RuntimeException('You cannot order your own product.');
                }
                if ((float) $listing['stock'] < $line['quantity']) {
                    throw new RuntimeException('Not enough stock for ' . $listing['product_name'] . '.');
                }
                $total += (float) $listing['price'] * $line['quantity'];
                $prepared[] = ['listing' => $listing, 'quantity' => $line['quantity']];
            }

            $orderId = (int) $this->db->table('orders')->insert([
                'buyer_id' => $buyer, 'total_amount' => round($total, 2),
            ]);

            foreach ($prepared as $row) {
                $listing = $row['listing'];
                $this->db->table('order_items')->insert([
                    'order_id' => $orderId, 'listing_id' => $listing['listing_id'], 'seller_id' => $listing['seller_id'],
                    'product_name' => $listing['product_name'], 'unit_price' => $listing['price'],
                    'quantity' => $row['quantity'], 'unit' => $listing['unit'],
                ]);
                $this->community->decrement_stock($listing['listing_id'], $row['quantity']);
            }
            $this->db->commit();
        } catch (RuntimeException $e) {
            $this->db->roll_back();
            $this->api->respond_error($e->getMessage(), 422);
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->api->respond_error('The order could not be placed. Please try again.', 500);
        }

        // Open the payment invoice. If Xendit refuses, release the reservation.
        $order = $this->payments->order_with_buyer($orderId);
        try {
            $returnUrl = $this->payment_return_url($returnOrigin, $orderId);
            $invoice = $xendit->create_invoice($this->invoice_payload($order, $this->payments->order_items($orderId), $returnUrl));
        } catch (RuntimeException $e) {
            $this->payments->mark_expired($orderId);
            $this->platform->audit($buyer, 'PAYMENT_START_FAILED', 'Order', $orderId, $e->getMessage());
            $this->api->respond_error('Payment could not be started: ' . $e->getMessage(), 502);
        }

        $this->payments->save_invoice($orderId, $invoice['id'], $invoice['invoice_url'], $invoice['external_id']);
        $this->platform->notify($buyer, 'Order #' . $orderId . ' created', 'Complete the payment to confirm your order.');
        $this->platform->audit($buyer, 'PLACE_ORDER', 'Order', $orderId, count($prepared) . ' item(s)');

        $this->api->respond([
            'message' => 'Order created. Continue to payment to confirm it.',
            'order_id' => $orderId,
            'total' => round($total, 2),
            'payment_status' => 'unpaid',
            'invoice_url' => $invoice['invoice_url']
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
        } elseif ($status === 'EXPIRED') {
            if ($this->payments->mark_expired($orderId)) {
                $this->platform->audit((int) $order['buyer_id'], 'PAYMENT_EXPIRED', 'Order', $orderId, 'Stock released');
            }
        }

        $this->api->respond(['received' => true, 'order_id' => $orderId, 'status' => $status]);
    }

    /** Runs once per order after payment: trace codes, QR images, receipt email and notifications. */
    private function after_payment($orderId)
    {
        $order = $this->payments->order_with_buyer($orderId);
        $traces = $this->payments->ensure_trace_codes($orderId);
        $cloud = $this->call->library('Cloud_storage');
        $qr = $this->call->library('QrCoder');

        foreach ($traces as $trace) {
            try {
                $png = $qr->png(config_item('app_public_url') . '/trace.html?code=' . $trace['trace_code']);
                $stored = $cloud->put('qr/' . $trace['trace_code'] . '.png', $png, 'image/png');
                $this->payments->set_trace_qr($trace['trace_id'], $stored['url']);
            } catch (Throwable $e) {
                $this->platform->audit((int) $order['buyer_id'], 'QR_FAILED', 'Trace', $trace['trace_id'], $e->getMessage());
            }
        }

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

        $this->platform->notify((int) $order['buyer_id'], 'Payment received', 'Order #' . $orderId . ' is paid. Your trace codes and QR codes are ready.');
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
