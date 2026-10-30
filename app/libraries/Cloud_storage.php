<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/Http_client.php';

/**
 * File storage for uploads and database backups.
 *
 * - "bridge" driver: files go to Puter cloud storage through the cloud-bridge service
 *   (CLOUD_BRIDGE_URL / CLOUD_BRIDGE_KEY in .env).
 * - "local" driver: used when no bridge is configured; files are kept in public/uploads/cloud.
 *
 * Load with $this->call->library('Cloud_storage').
 */
class Cloud_storage
{
    private $http;

    public function __construct()
    {
        $this->http = new Http_client();
    }

    public function driver()
    {
        return config_item('cloud_bridge_url') !== '' ? 'bridge' : 'local';
    }

    /** @return array{path:string,url:string,driver:string} */
    public function put($path, $bytes, $mime)
    {
        $path = $this->clean_path($path);
        if ($this->driver() === 'local') {
            $absolute = PUBLIC_DIR . 'uploads/cloud/' . $path;
            if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true)) {
                throw new RuntimeException('Local storage folder could not be created.');
            }
            if (file_put_contents($absolute, $bytes) === false) {
                throw new RuntimeException('The file could not be saved.');
            }
            return ['path' => $path, 'url' => '/uploads/cloud/' . $path, 'driver' => 'local'];
        }

        $response = $this->bridge('POST', '/v1/files?path=' . rawurlencode($path), $bytes, ['Content-Type' => $mime]);
        return ['path' => $path, 'url' => $response['url'] ?? '', 'driver' => 'bridge'];
    }

    public function delete($path)
    {
        $path = $this->clean_path($path);
        if ($this->driver() === 'local') {
            $absolute = PUBLIC_DIR . 'uploads/cloud/' . $path;
            if (is_file($absolute)) {
                unlink($absolute);
            }
            return true;
        }
        $this->bridge('DELETE', '/v1/files?path=' . rawurlencode($path));
        return true;
    }

    /** @return array list of ['path','size','modified','url'] */
    public function list($prefix = '')
    {
        $prefix = $prefix === '' ? '' : $this->clean_path($prefix);
        if ($this->driver() === 'local') {
            $base = PUBLIC_DIR . 'uploads/cloud/';
            $files = [];
            if (is_dir($base)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
                    if ($prefix !== '' && strpos($relative, $prefix) !== 0) continue;
                    $files[] = ['path' => $relative, 'size' => $file->getSize(), 'modified' => date('c', $file->getMTime()), 'url' => '/uploads/cloud/' . $relative];
                }
            }
            return $files;
        }
        $response = $this->bridge('GET', '/v1/files?prefix=' . rawurlencode($prefix));
        return $response['files'] ?? [];
    }

    private function clean_path($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '' || strpos($path, '..') !== false || !preg_match('#^[A-Za-z0-9/_.\-]+$#', $path)) {
            throw new InvalidArgumentException('Invalid storage path.');
        }
        return $path;
    }

    private function bridge($method, $path, $body = null, array $headers = [])
    {
        $headers['X-Bridge-Key'] = config_item('cloud_bridge_key');
        $response = $this->http->request($method, rtrim(config_item('cloud_bridge_url'), '/') . $path, $headers, $body, 60);
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException('Cloud storage error: ' . (is_array($data) && isset($data['error']) ? $data['error'] : $response['status']));
        }
        return is_array($data) ? $data : [];
    }
}
