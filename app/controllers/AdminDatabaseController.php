<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminDatabaseController extends Controller
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
        $auth = $this->api->require_jwt();

        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Unauthorized access.', 403);
        }

        $model = $this->call->model('AdminDatabase_model');
        $this->api->respond($model->getSnapshot());
    }
}
