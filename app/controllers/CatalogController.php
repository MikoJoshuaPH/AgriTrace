<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CatalogController extends Controller
{
    private $catalog;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->catalog = $this->call->model('Catalog_model');
    }

    public function search()
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $name = trim((string) ($query['name'] ?? ''));
        if ($name === '') {
            $this->api->respond_error('Product name is required.', 422);
        }
        $this->api->respond(['data' => $this->catalog->findSimilar($name)]);
    }

    public function alias($id)
    {
        $auth = $this->api->require_jwt();
        $this->api->require_method('POST');
        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
        $productId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $input = $this->api->body();
        $alias = trim((string) ($input['alias_name'] ?? ''));
        if ($productId === false || $alias === '') {
            $this->api->respond_error('Catalog product and alias are required.', 422);
        }
        if (!$this->catalog->addAlias($productId, $alias, $input['language_code'] ?? null)) {
            $this->api->respond_error('That alias already exists.', 409);
        }
        $this->api->respond(['message' => 'Catalog alias saved.'], 201);
    }
}
