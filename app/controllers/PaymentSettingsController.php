<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PaymentSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $auth = $this->farmer();
        $rows = $this->db->table('farmer_payment_settings')
            ->select('setting_id, payment_method, account_name, account_reference, is_active, updated_at')
            ->where('farmer_id', $auth)
            ->order_by('payment_method', 'ASC')->get_all(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['account_reference'] = $this->mask($row['account_reference']);
        }
        $this->api->respond(['data' => $rows]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $farmer = $this->farmer();
        $input = $this->api->body();
        $method = strtolower(trim((string) ($input['payment_method'] ?? '')));
        $name = trim((string) ($input['account_name'] ?? ''));
        $reference = trim((string) ($input['account_reference'] ?? ''));
        if (!in_array($method, ['gcash', 'bank_transfer', 'paypal'], true) || $name === '' || $reference === '') {
            $this->api->respond_error('Choose a supported payment method and provide account details.', 422);
        }
        $this->db->table('farmer_payment_settings')->insert([
            'farmer_id' => $farmer, 'payment_method' => $method,
            'account_name' => substr($name, 0, 150), 'account_reference' => substr($reference, 0, 255),
            'is_active' => 1, 'updated_at' => date('Y-m-d H:i:s')
        ]);
        $this->api->respond(['message' => 'Payment setting saved.'], 201);
    }

    public function payouts()
    {
        $this->api->require_method('GET');
        $farmer = $this->farmer();
        $this->api->respond(['data' => $this->db->table('farmer_payouts')
            ->select('payout_id, order_id, amount, platform_fee, status, transaction_reference, paid_at, created_at')
            ->where('farmer_id', $farmer)->order_by('payout_id', 'DESC')->limit(100)
            ->get_all(PDO::FETCH_ASSOC)]);
    }

    private function farmer()
    {
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        return (int) $auth['sub'];
    }

    private function mask($value)
    {
        $value = (string) $value;
        return strlen($value) <= 4 ? '••••' : str_repeat('•', max(4, strlen($value) - 4)) . substr($value, -4);
    }
}
