<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/Http_client.php';

/**
 * File storage for uploads and database backups.
 *
 * - Public files, admin files and backups use one public Upstash Blob bucket.
 * - "local" is a fallback for public files when Blob is not configured.
 *
 * Load with $this->call->library('Cloud_storage').
 */
class Cloud_storage
{
    private $http;
    private $credentials_cache = [];

    public function __construct()
    {
        $this->http = new Http_client();
    }

    public function driver($bucket = 'media')
    {
        $token = $this->token($bucket);
        if ($token !== '') return 'upstash-public';
        return $bucket === 'backups' ? 'unconfigured' : 'local';
    }

    /** @return array{path:string,url:string,driver:string} */
    public function put($path, $bytes, $mime, $bucket = 'media')
    {
        $path = $this->clean_path($path);
        $this->validate_bucket($bucket);
        $token = $this->token($bucket);
        if ($token !== '') {
            $credentials = $this->credentials($bucket);
            $this->assert_visibility($credentials, $bucket);
            $this->s3_client($credentials, $bucket)->putObject([
                'Bucket' => $credentials['bucket'],
                'Key' => $path,
                'Body' => $bytes,
                'ContentType' => $mime,
            ]);
            return [
                'path' => $path,
                'url' => $this->public_url($token, $path),
                'driver' => $this->driver($bucket),
            ];
        }
        if ($bucket === 'backups') throw new RuntimeException('Upstash Blob storage is not configured.');

        $absolute = PUBLIC_DIR . 'uploads/cloud/' . $path;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true)) {
            throw new RuntimeException('Local storage folder could not be created.');
        }
        if (file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException('The file could not be saved.');
        }
        return ['path' => $path, 'url' => '/uploads/cloud/' . $path, 'driver' => 'local'];
    }

    public function delete($path, $bucket = 'media')
    {
        $path = $this->clean_path($path);
        $this->validate_bucket($bucket);
        $token = $this->token($bucket);
        if ($token !== '') {
            $credentials = $this->credentials($bucket);
            $this->assert_visibility($credentials, $bucket);
            $this->s3_client($credentials, $bucket)->deleteObject([
                'Bucket' => $credentials['bucket'],
                'Key' => $path,
            ]);
            return true;
        }
        if ($bucket === 'backups') throw new RuntimeException('Upstash Blob storage is not configured.');
        $absolute = PUBLIC_DIR . 'uploads/cloud/' . $path;
        if (is_file($absolute)) {
            unlink($absolute);
        }
        return true;
    }

    /** @return array list of ['path','size','modified','url','bucket'] */
    public function list($prefix = '', $bucket = 'media')
    {
        $this->validate_bucket($bucket);
        if ($bucket === 'backups') {
            if ($prefix !== 'admin/') {
                throw new InvalidArgumentException('Only admin files can be listed here.');
            }
        } elseif ($prefix !== '') {
            $prefix = $this->clean_path($prefix);
            if (substr($prefix, -1) !== '/') $prefix .= '/';
        }
        $token = $this->token($bucket);
        if ($token === '') {
            if ($bucket === 'backups') {
                throw new RuntimeException('Upstash Blob storage is not configured.');
            }
            return $this->list_local($prefix);
        }

        $files = [];
        $continuation = null;
        do {
            $credentials = $this->credentials($bucket);
            $this->assert_visibility($credentials, $bucket);
            $arguments = [
                'Bucket' => $credentials['bucket'],
                'MaxKeys' => 1000,
            ];
            if ($prefix !== '') $arguments['Prefix'] = $prefix;
            if ($continuation !== null) $arguments['ContinuationToken'] = $continuation;
            $page = $this->s3_client($credentials, $bucket)->listObjectsV2($arguments);
            foreach ($page['Contents'] ?? [] as $object) {
                $path = (string) $object['Key'];
                if ($bucket === 'media'
                    && (strpos($path, 'admin/') === 0 || strpos($path, 'backups/') === 0)) {
                    continue;
                }
                $files[] = [
                    'path' => $path,
                    'size' => (int) $object['Size'],
                    'modified' => isset($object['LastModified']) ? $object['LastModified']->format(DATE_ATOM) : null,
                    'url' => $this->public_url($token, $path),
                    'bucket' => $bucket,
                ];
                if (count($files) > 10000) {
                    throw new RuntimeException('Too many files to display in one listing.');
                }
            }
            $continuation = !empty($page['IsTruncated']) ? (string) $page['NextContinuationToken'] : null;
        } while ($continuation !== null && $continuation !== '');
        return $files;
    }

    public function public_read_url($path, $bucket = 'backups')
    {
        $path = $this->clean_path($path);
        $this->validate_bucket($bucket);
        if ($bucket !== 'backups' || $this->token($bucket) === '') {
            throw new RuntimeException('Upstash Blob storage is not configured.');
        }
        return $this->public_url($this->token($bucket), $path);
    }

    private function list_local($prefix)
    {
        $base = PUBLIC_DIR . 'uploads/cloud/';
        $files = [];
        if (is_dir($base)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
                if ($prefix !== '' && strpos($relative, $prefix) !== 0) continue;
                $files[] = [
                    'path' => $relative,
                    'size' => $file->getSize(),
                    'modified' => date('c', $file->getMTime()),
                    'url' => '/uploads/cloud/' . $relative,
                    'bucket' => 'media',
                ];
            }
        }
        return $files;
    }

    private function token($bucket)
    {
        $this->validate_bucket($bucket);
        return config_item('upstash_blob_media_token');
    }

    private function validate_bucket($bucket)
    {
        if (!in_array($bucket, ['media', 'backups'], true)) {
            throw new InvalidArgumentException('Invalid storage bucket.');
        }
    }

    private function credentials($bucket, $force_refresh = false)
    {
        $token = $this->token($bucket);
        if ($token === '') {
            throw new RuntimeException('Upstash Blob token is not configured.');
        }
        if (!$force_refresh && isset($this->credentials_cache[$bucket])
            && $this->credentials_cache[$bucket]['expiresAt'] > time() + 30) {
            return $this->credentials_cache[$bucket];
        }

        $response = $this->http->request(
            'POST',
            'https://blob.upstash.io/v1/credentials',
            ['Authorization' => 'Bearer ' . $token],
            null,
            15,
            false
        );
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] > 299 || !is_array($data)) {
            $message = is_array($data) && isset($data['error']) ? $data['error'] : $response['status'];
            throw new RuntimeException('Upstash Blob credential request failed: ' . $message);
        }
        foreach (['endpoint', 'bucket', 'accessKeyId', 'secretAccessKey', 'sessionToken', 'expiresAt'] as $required) {
            if (!isset($data[$required])) {
                throw new RuntimeException('Upstash Blob returned incomplete temporary credentials.');
            }
        }
        $endpoint = parse_url($data['endpoint']);
        if (($endpoint['scheme'] ?? '') !== 'https'
            || !isset($endpoint['host'])
            || substr($endpoint['host'], -strlen('.r2.cloudflarestorage.com')) !== '.r2.cloudflarestorage.com') {
            throw new RuntimeException('Upstash Blob returned an invalid S3 endpoint.');
        }
        $data['expiresAt'] = (int) $data['expiresAt'];
        if ($data['expiresAt'] <= time()) {
            throw new RuntimeException('Upstash Blob returned expired temporary credentials.');
        }
        $data['visibility'] = $data['visibility'] ?? 'public';
        $this->credentials_cache[$bucket] = $data;
        return $data;
    }

    private function assert_visibility(array $credentials, $bucket)
    {
        if ($credentials['visibility'] !== 'public') {
            throw new RuntimeException('The Upstash Blob bucket must be public for this storage configuration.');
        }
    }

    private function s3_client(array $credentials, $bucket)
    {
        $provider = \Aws\Credentials\CredentialProvider::memoize(function () use ($credentials, $bucket) {
            $current = $credentials;
            if ($current['expiresAt'] <= time() + 60) {
                $current = $this->credentials($bucket, true);
                $this->assert_visibility($current, $bucket);
            }
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Credentials\Credentials(
                $current['accessKeyId'],
                $current['secretAccessKey'],
                $current['sessionToken'],
                $current['expiresAt']
            ));
        });
        return new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => $credentials['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => $provider,
        ]);
    }

    private function public_url($token, $path)
    {
        $domain = $this->domain_hash($token);
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        return 'https://' . $domain . '.blob.upstash.io/' . $encoded_path;
    }

    private function domain_hash($token)
    {
        $raw = base64_decode(strtr(trim($token), '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 6 || ord($raw[0]) !== 2) {
            throw new RuntimeException('Upstash Blob token has an unsupported format.');
        }
        $id_length = ord($raw[2]);
        $password_length = unpack('n', substr($raw, 3, 2))[1];
        $domain_length = ord($raw[5]);
        if ($id_length === 0 || $password_length === 0 || $domain_length === 0
            || strlen($raw) !== 6 + $id_length + $password_length + $domain_length) {
            throw new RuntimeException('Upstash Blob token is malformed.');
        }
        $domain = substr($raw, 6 + $id_length + $password_length, $domain_length);
        if (!preg_match('/^[A-Za-z0-9-]+$/', $domain)) {
            throw new RuntimeException('Upstash Blob token contains an invalid public domain.');
        }
        return $domain;
    }

    private function clean_path($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '' || strpos($path, '..') !== false || !preg_match('#^[A-Za-z0-9/_.\-]+$#', $path)) {
            throw new InvalidArgumentException('Invalid storage path.');
        }
        return $path;
    }
}
