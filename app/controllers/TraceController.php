<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Product traceability: the public trace record behind each QR code, and admin views.
 */
class TraceController extends Controller
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

    /** GET /api/trace/{code} — public. Buyers are shown by first name and last initial only. */
    public function show($code)
    {
        $this->api->require_method('GET');
        if (!preg_match('/^AGT-[A-F0-9]{10}$/', (string) $code)) {
            $this->api->respond_error('Trace code not found.', 404);
        }
        $t = $this->payments->public_trace($code);
        if (!$t) {
            $this->api->respond_error('Trace code not found.', 404);
        }

        $farmer = trim(($t['farmer_first'] ?? '') . ' ' . ($t['farmer_last'] ?? '')) ?: $t['farmer_username'];
        $initial = mb_substr((string) ($t['buyer_last'] ?? ''), 0, 1);
        $buyer = $t['buyer_first']
            ? trim($t['buyer_first'] . ' ' . ($initial !== '' ? $initial . '.' : ''))
            : $t['buyer_username'];
        $history = !empty($t['batch_id'])
            ? $this->payments->batch_history_by_id((int) $t['batch_id'])
            : null;

        $this->api->respond([
            'trace_code' => $t['trace_code'],
            'verified' => $t['payment_status'] === 'paid',
            'provenance_verified' => $history ? $history['provenance_verified'] : false,
            'product' => [
                'name' => $t['product_name'],
                'category' => $t['category'],
                'quantity' => (float) $t['quantity'],
                'unit' => $t['unit'],
                'unit_price' => (float) $t['unit_price'],
            ],
            'farmer' => [
                'name' => $history ? $history['farmer']['name'] : $farmer,
                'registered_farm' => $history ? $history['farmer']['registered_farm'] : null,
                'location' => $history ? $history['farmer']['location'] : ($t['farmer_location'] ?: $t['listing_location']),
            ],
            'buyer' => ['name' => $buyer],
            'batch' => $history ? $history['batch'] : null,
            'harvest' => $history ? $history['harvest'] : [],
            'supply_chain' => $history ? $history['supply_chain'] : [],
            'order' => [
                'order_id' => (int) $t['order_id'],
                'status' => $t['order_status'],
                'ordered_at' => $t['created_at'],
                'paid_at' => $t['paid_at'],
            ],
            'qr_url' => $history && $history['qr_url'] ? $history['qr_url'] : $t['qr_url'],
        ]);
    }

    /** GET /api/trace/batch/{batch_code} — public batch trace and timeline. */
    public function show_batch($batchCode)
    {
        $this->api->require_method('GET');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', (string) $batchCode)) {
            $this->api->respond_error('Batch not found.', 404);
        }
        $history = $this->payments->batch_history((string) $batchCode);
        if (!$history) {
            $this->api->respond_error('Batch not found.', 404);
        }
        $this->api->respond($this->batch_response($history, false));
    }

    /** GET /api/trace/batch/{batch_code}/authorized — supply-chain partner detail. */
    public function show_batch_authorized($batchCode)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
            $this->api->respond_error('Supply-chain partner access is required.', 403);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', (string) $batchCode)) {
            $this->api->respond_error('Batch not found.', 404);
        }
        $history = $this->payments->batch_history((string) $batchCode, true);
        if (!$history) {
            $this->api->respond_error('Batch not found.', 404);
        }
        $this->api->respond($this->batch_response($history, true));
    }

    /** GET /api/admin/traces — every trace code with the buyer and farmer accounts. */
    public function admin_index()
    {
        $this->api->require_method('GET');
        $this->require_admin();
        $this->api->respond(['data' => $this->payments->admin_traces(200)]);
    }

    /** POST /api/admin/traces/{code}/qr — generates a missing QR image (for example after a service outage). */
    public function regenerate_qr($code)
    {
        $this->api->require_method('POST');
        $this->require_admin();
        if (!preg_match('/^AGT-[A-F0-9]{10}$/', (string) $code)) {
            $this->api->respond_error('Trace code not found.', 404);
        }
        $trace = $this->payments->public_trace($code);
        if (!$trace) {
            $this->api->respond_error('Trace code not found.', 404);
        }
        $row = $this->payments->trace_row($code);
        $qr = $this->call->library('QrCoder');
        $cloud = $this->call->library('Cloud_storage');
        try {
            $traceUrl = rtrim((string) config_item('app_frontend_url'), '/')
                . '/trace?code=' . rawurlencode($code);
            $png = $qr->png($traceUrl);
            $stored = $cloud->put('qr/' . $code . '.png', $png, 'image/png');
        } catch (RuntimeException $e) {
            $this->api->respond_error('The QR code could not be generated: ' . $e->getMessage(), 502);
        }
        $this->payments->set_trace_qr((int) $row['trace_id'], $stored['url']);
        $this->platform->audit($this->admin_id, 'REGENERATE_QR', 'Trace', $code, null);
        $this->api->respond(['message' => 'QR code generated.', 'qr_url' => $stored['url']]);
    }

    private $admin_id = 0;

    private function batch_response(array $history, $authorized)
    {
        $response = [
            'trace_code' => $history['trace_code'],
            'verified' => $history['payment_status'] === 'paid',
            'provenance_verified' => $history['provenance_verified'],
            'product' => $history['product'],
            'farmer' => $history['farmer'],
            'batch' => $history['batch'],
            'harvest' => $history['harvest'],
            'supply_chain' => $history['supply_chain'],
            'qr_url' => $history['qr_url'],
        ];
        if ($authorized) {
            $response['authorized'] = true;
        }
        return $response;
    }

    private function require_admin()
    {
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
        $this->admin_id = (int) $auth['sub'];
    }
}
