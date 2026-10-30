<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Minimal HTTP client built on PHP streams (no cURL extension required).
 * Used by the payment, QR code and cloud storage libraries.
 */
class Http_client
{
    /**
     * @return array{status:int, body:string, headers:array<string,string>}
     * @throws RuntimeException when the request cannot be made
     */
    public function request($method, $url, array $headers = [], $body = null, $timeout = 20)
    {
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $header_lines),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
        ]);

        try {
            $response_body = file_get_contents($url, false, $context);
        } catch (Throwable $e) {
            // LavaLust turns PHP warnings into exceptions; treat any transport failure the same way.
            $response_body = false;
        }
        if ($response_body === false) {
            throw new RuntimeException('Could not reach ' . parse_url($url, PHP_URL_HOST) . '.');
        }

        $status = 0;
        $response_headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $response_headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => $response_body, 'headers' => $response_headers];
    }
}
