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
        $backup_driver = $storage->driver('backups');
        try {
            $files = $storage->list((string) ($query['prefix'] ?? ''));
            if ($backup_driver === 'upstash-public') {
                $admin_files = $storage->list('admin/', 'backups');
                foreach ($admin_files as &$file) {
                    $file['bucket'] = 'backups';
                }
                unset($file);
                $files = array_merge($files, $admin_files);
            }
            foreach ($files as &$file) {
                if (!isset($file['bucket'])) {
                    $file['bucket'] = 'media';
                }
            }
            unset($file);
        } catch (InvalidArgumentException $e) {
            $this->api->respond_error('Invalid storage prefix.', 422);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        $driver = $storage->driver('media');
        if ($backup_driver === 'upstash-public') {
            $driver .= ' + admin files/backups are public';
        } elseif ($backup_driver === 'unconfigured') {
            $driver .= ' + backups not configured';
        }
        $this->api->respond(['driver' => $driver, 'files' => $files]);
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
            $stored = $storage->put($path, file_get_contents($file['tmp_name']), $mime, 'backups');
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
            $bucket = strpos($path, 'admin/') === 0 || strpos($path, 'backups/') === 0 ? 'backups' : 'media';
            $storage->delete($path, $bucket);
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

    /** GET /api/admin/backups/download?path= — returns the public URL for a backup. */
    public function backup_download_url()
    {
        $this->api->require_method('GET');
        $this->require_admin();
        $query = $this->api->get_query_params();
        $path = (string) ($query['path'] ?? '');
        if (strpos($path, 'backups/') !== 0) {
            $this->api->respond_error('Backup file not found.', 404);
        }
        try {
            $url = $this->call->library('Cloud_storage')->public_read_url($path, 'backups');
        } catch (InvalidArgumentException $e) {
            $this->api->respond_error('Backup file not found.', 404);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        if ($url === '') {
            $this->api->respond_error('A download link could not be created.', 502);
        }
        $this->api->respond(['url' => $url]);
    }

    /** GET /api/admin/cloud/download?path= — returns the public URL for an admin file. */
    public function admin_file_download_url()
    {
        $this->api->require_method('GET');
        $this->require_admin();
        $query = $this->api->get_query_params();
        $path = (string) ($query['path'] ?? '');
        if (strpos($path, 'admin/') !== 0) {
            $this->api->respond_error('Admin file not found.', 404);
        }
        try {
            $url = $this->call->library('Cloud_storage')->public_read_url($path, 'backups');
        } catch (InvalidArgumentException $e) {
            $this->api->respond_error('Admin file not found.', 404);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        if ($url === '') {
            $this->api->respond_error('A download link could not be created.', 502);
        }
        $this->api->respond(['url' => $url]);
    }

    /** POST /api/admin/backups — dumps the database, compresses it and stores it in the cloud. */
    public function create_backup()
    {
        $this->api->require_method('POST');
        $this->require_admin();
        try {
            $backup = $this->save_cloud_backup($this->admin_id);
            $this->api->respond([
                'message' => 'Backup created.',
                'path' => $backup['path'],
                'url' => $backup['url'],
                'size' => $backup['size'],
            ], 201);
        } catch (Throwable $e) {
            $this->api->respond_error('The backup could not be created: ' . $e->getMessage(), 502);
        }
    }

    /** GET /api/admin/backups/scheduled — CLI-only entry point for the OS scheduler. */
    public function scheduled_backup()
    {
        if (PHP_SAPI !== 'cli') {
            $this->api->respond_error('Not found.', 404);
        }

        try {
            $backup = $this->save_cloud_backup(null);
            fwrite(STDOUT, 'Cloud backup created: ' . $backup['path'] . ' (' . $backup['size'] . " bytes)\n");
        } catch (Throwable $e) {
            fwrite(STDERR, 'Cloud backup failed: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    private function save_cloud_backup($created_by)
    {
        $backups = $this->call->model('Backup_model');
        $storage = $this->call->library('Cloud_storage');
        $path = 'backups/agritrace-' . date('Ymd-His') . '.sql.gz';

        try {
            $bytes = gzencode($backups->dump(), 9);
            $stored = $storage->put($path, $bytes, 'application/gzip', 'backups');
            $backups->record($path, null, strlen($bytes), $stored['driver'], 'completed', null, $created_by);
            $this->platform->audit($created_by, 'BACKUP_CREATED', 'Backup', $path, strlen($bytes) . ' bytes');
            return ['path' => $path, 'url' => $stored['url'], 'size' => strlen($bytes)];
        } catch (Throwable $e) {
            $backups->record($path, null, 0, $storage->driver('backups'), 'failed', substr($e->getMessage(), 0, 500), $created_by);
            $this->platform->audit($created_by, 'BACKUP_FAILED', 'Backup', $path, $e->getMessage());
            throw $e;
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
