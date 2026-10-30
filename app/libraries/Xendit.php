<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/Http_client.php';

/**
 * Xendit invoice client (payments). Load with $this->call->library('Xendit').
 * Invoices are created through the REST API; webhook callbacks are confirmed by
 * fetching the invoice again with the secret key, so a forged callback cannot mark an order paid.
 */
class XenditNotFound extends RuntimeException {}

class Xendit
{
    private $http;

    public function __construct()
    {
        $this->http = new Http_client();
    }

    public function is_configured()
    {
        return config_item('xendit_secret_key') !== '';
    }

    public function callback_token_valid($token)
    {
        $expected = config_item('xendit_callback_token');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /** @return array invoice (id, external_id, status, invoice_url, amount, expiry_date ...) */
    public function create_invoice(array $data)
    {
        return $this->call('POST', '/v2/invoices', $data);
    }

    /** @return array|null invoice fetched from Xendit (authoritative status); null when Xendit does not know the id */
    public function get_invoice($invoice_id)
    {
        try {
            return $this->call('GET', '/v2/invoices/' . rawurlencode($invoice_id));
        } catch (XenditNotFound $e) {
            return null;
        }
    }

    private function call($method, $path, $payload = null)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('Payments are not configured. Set XENDIT_SECRET_KEY in .env.');
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode(config_item('xendit_secret_key') . ':'),
            'Accept'        => 'application/json',
        ];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload);
        }

        $response = $this->http->request($method, rtrim(config_item('xendit_base_url'), '/') . $path, $headers, $body, 20);
        $data = json_decode($response['body'], true);

        if ($response['status'] === 404) {
            throw new XenditNotFound('Xendit: not found.');
        }
        if ($response['status'] < 200 || $response['status'] > 299 || !is_array($data)) {
            $message = is_array($data) && isset($data['message']) ? $data['message'] : 'Payment service error (' . $response['status'] . ').';
            throw new RuntimeException('Xendit: ' . $message);
        }
        return $data;
    }
}
