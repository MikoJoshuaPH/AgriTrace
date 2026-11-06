<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/Http_client.php';

class PayMongo
{
    private $http;

    public function __construct()
    {
        $this->http = new Http_client();
    }

    public function is_configured()
    {
        return config_item('paymongo_secret_key') !== '';
    }

    public function is_live_mode()
    {
        return (bool) config_item('paymongo_livemode');
    }

    public function create_payment_link(array $data)
    {
        $response = $this->call('POST', '/v1/payment_links', $data);
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('PayMongo returned an invalid payment link.');
        }
        return $response['data'];
    }

    public function get_link_payments($link_id)
    {
        $response = $this->call('GET', '/v1/payment_links/' . rawurlencode($link_id) . '/payments');
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('PayMongo returned an invalid payment list.');
        }
        return $response['data'];
    }

    public function create_account(array $data)
    {
        return $this->account_response($this->call('POST', '/v2/accounts', $data));
    }

    public function get_account($account_id)
    {
        return $this->account_response($this->call('GET', '/v2/accounts/' . rawurlencode($account_id)));
    }

    public function create_identity_verification($account_id)
    {
        $response = $this->call(
            'POST',
            '/v2/accounts/' . rawurlencode($account_id) . '/identity_verification'
        );
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('PayMongo returned an invalid identity verification session.');
        }
        return $response['data'];
    }

    public function update_account($account_id, array $data)
    {
        return $this->account_response(
            $this->call('PATCH', '/v2/accounts/' . rawurlencode($account_id), $data)
        );
    }

    public function activate_account($account_id)
    {
        return $this->account_response(
            $this->call('POST', '/v2/accounts/' . rawurlencode($account_id) . '/activate')
        );
    }

    public function create_checkout_session(array $data, $account_id)
    {
        $response = $this->call('POST', '/v2/checkout_sessions', $data, $account_id);
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('PayMongo returned an invalid Checkout Session.');
        }
        return $response['data'];
    }

    public function get_checkout_session($session_id, $account_id)
    {
        $response = $this->call(
            'GET',
            '/v2/checkout_sessions/' . rawurlencode($session_id),
            null,
            $account_id
        );
        if (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('PayMongo returned an invalid Checkout Session.');
        }
        return $response['data'];
    }

    public function webhook_signature_valid($raw_body, $signature, $live_mode)
    {
        $secret = config_item('paymongo_webhook_secret');
        if ($secret === '' || !is_string($signature) || $signature === '') {
            return false;
        }

        $signature = trim($signature);
        if (preg_match('/^sha256=([a-f0-9]{64})$/i', $signature, $matches)
            || preg_match('/^([a-f0-9]{64})$/i', $signature, $matches)) {
            return hash_equals(hash_hmac('sha256', $raw_body, $secret), strtolower($matches[1]));
        }

        if (preg_match('/^t=(\d+),te=([a-f0-9]{64}),li=([a-f0-9]{64})$/i', $signature, $matches)) {
            $expected = hash_hmac('sha256', $matches[1] . '.' . $raw_body, $secret);
            $provided = $live_mode ? $matches[3] : $matches[2];
            return hash_equals($expected, strtolower($provided));
        }

        return false;
    }

    private function account_response(array $response)
    {
        if (!is_array($response['data'] ?? null) || empty($response['data']['id'])) {
            throw new RuntimeException('PayMongo returned an invalid account response.');
        }
        return $response['data'];
    }

    private function call($method, $path, $payload = null, $account_id = null)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('Payments are not configured. Set PAYMONGO_SECRET_KEY in .env.');
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode(config_item('paymongo_secret_key') . ':'),
            'Accept'        => 'application/json',
        ];
        if ($account_id !== null) {
            $headers['Account-ID'] = $account_id;
        }
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload);
            if ($body === false) {
                throw new RuntimeException('Could not encode the PayMongo request.');
            }
        }

        $response = $this->http->request(
            $method,
            $this->api_root() . $path,
            $headers,
            $body,
            20
        );
        $data = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] > 299 || !is_array($data)) {
            $message = 'Payment service error (' . $response['status'] . ').';
            if (isset($data['errors'][0]['detail']) && is_string($data['errors'][0]['detail'])) {
                $message = $data['errors'][0]['detail'];
            } elseif (isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            }
            throw new RuntimeException('PayMongo: ' . $message);
        }
        return $data;
    }

    private function api_root()
    {
        return preg_replace('~/v1$~', '', rtrim(config_item('paymongo_base_url'), '/'));
    }
}
