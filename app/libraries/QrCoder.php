<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/Http_client.php';

/**
 * QRCODER v4 client. Returns PNG bytes for a text value (the trace link).
 * The free plan allows 100 requests per day, so callers store the image once and reuse it.
 * Load with $this->call->library('QrCoder').
 */
class QrCoder
{
    private $http;

    public function __construct()
    {
        $this->http = new Http_client();
    }

    public function is_configured()
    {
        return config_item('qrcoder_api_key') !== '';
    }

    /** @return string binary PNG data */
    public function png($text, $size = 6, $padding = 4)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('QR codes are not configured. Set QRCODER_API_KEY in .env.');
        }

        $url = rtrim(config_item('qrcoder_base_url'), '/') . '/?' . http_build_query([
            'key'     => config_item('qrcoder_api_key'),
            'text'    => $text,
            'type'    => 'png',
            'size'    => (int) $size,
            'padding' => (int) $padding,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->http->request('GET', $url, ['Accept' => 'image/png'], null, 20);
        if ($response['status'] !== 200 || strncmp($response['body'], "\x89PNG", 4) !== 0) {
            throw new RuntimeException('QR code service error (' . $response['status'] . ').');
        }
        return $response['body'];
    }
}
