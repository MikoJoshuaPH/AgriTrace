<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Administrator cloud management: files in cloud storage, and database backups.
 * Only administrators can reach these routes.
 */
class CloudController extends Controller
{
    private $platform;
    private $admin_id = 0;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->platform = $this->call->model('Platform_model');
    }

    public function files()
    {
        $this->api->require_method('GET');
        $this->require_admin();
        $query = $this->api->get_query_params();
        $storage = $this->call->library('Cloud_storage');
        try {
            $files = $storage->list((string) ($query['prefix'] ?? ''));
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        $this->api->respond(['driver' => $storage->driver(), 'files' => $files]);
    }

    /** POST /api/admin/cloud/upload — photos and PDFs go to the cloud store. */
    public function upload()
    {
        $this->api->require_method('POST');
        $this->require_admin();
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->api->respond_error('Please choose a file to upload.', 422);
        }
        $file = $_FILES['file'];
        if ($file['size'] > 8 * 1024 * 1024) {
            $this->api->respond_error('Files must be 8 MB or smaller.', 422);
        }
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            $this->api->respond_error('Only JPG, PNG, GIF, WEBP and PDF files can be uploaded here.', 422);
        }
        $path = 'admin/' . date('Ymd') . '/' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        $storage = $this->call->library('Cloud_storage');
        try {
            $stored = $storage->put($path, file_get_contents($file['tmp_name']), $mime);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        $this->platform->audit($this->admin_id, 'CLOUD_UPLOAD', 'File', $stored['path'], $mime);
        $this->api->respond(['message' => 'File uploaded.', 'path' => $stored['path'], 'url' => $stored['url'], 'driver' => $stored['driver']], 201);
    }

    /** DELETE /api/admin/cloud/files?path= */
    public function delete_file()
    {
        $this->api->require_method('DELETE');
        $this->require_admin();
        $query = $this->api->get_query_params();
        $path = (string) ($query['path'] ?? '');
        $storage = $this->call->library('Cloud_storage');
        try {
            $storage->delete($path);
        } catch (InvalidArgumentException $e) {
            $this->api->respond_error('Invalid file path.', 422);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        $this->platform->audit($this->admin_id, 'CLOUD_DELETE', 'File', $path, null);
        $this->api->respond(['message' => 'File deleted.']);
    }

    /** GET /api/admin/backups */
    public function backups()
    {
        $this->api->require_method('GET');
        $this->require_admin();
        $this->api->respond(['data' => $this->call->model('Backup_model')->list_backups()]);
    }

    /** POST /api/admin/backups — dumps the database, compresses it and stores it in the cloud. */
    public function create_backup()
    {
        $this->api->require_method('POST');
        $this->require_admin();
        $backups = $this->call->model('Backup_model');
        $storage = $this->call->library('Cloud_storage');
        $path = 'backups/agritrace-' . date('Ymd-His') . '.sql.gz';
        try {
            $sql = $backups->dump();
            $bytes = gzencode($sql, 9);
            $stored = $storage->put($path, $bytes, 'application/gzip');
            $backups->record($path, $stored['url'], strlen($bytes), $stored['driver'], 'completed', null, $this->admin_id);
            $this->platform->audit($this->admin_id, 'BACKUP_CREATED', 'Backup', $path, strlen($bytes) . ' bytes');
            $this->api->respond(['message' => 'Backup created.', 'path' => $path, 'url' => $stored['url'], 'size' => strlen($bytes)], 201);
        } catch (Throwable $e) {
            $backups->record($path, null, 0, $storage->driver(), 'failed', substr($e->getMessage(), 0, 500), $this->admin_id);
            $this->platform->audit($this->admin_id, 'BACKUP_FAILED', 'Backup', $path, $e->getMessage());
            $this->api->respond_error('The backup could not be created: ' . $e->getMessage(), 502);
        }
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
