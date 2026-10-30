<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AgriTraceController extends Controller
{
    private $model;
    private $communityModel;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->model = $this->call->model('AgriTrace_model');
        $this->communityModel = $this->call->model('Community_model');
    }

    public function index($resource)
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        if ($resource === 'marketplace') {
            $query = $this->api->get_query_params();
            $this->api->respond(['data' => $this->communityModel->listListings($query['limit'] ?? 100)]);
        }
        if ($resource === 'posts') {
            $query = $this->api->get_query_params();
            $this->api->respond(['data' => $this->communityModel->listPosts($query['limit'] ?? 100)]);
        }
        $this->assertResource($resource);

        $query = $this->api->get_query_params();
        $limit = $query['limit'] ?? 100;
        $this->api->respond(['data' => $this->model->listResource($resource, $limit)]);
    }

    public function show($resource, $id)
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $id = $this->validateId($id);
        $this->assertResource($resource);

        $record = $this->model->findResource($resource, $id);
        if (!$record) {
            $this->api->respond_error('Record not found.', 404);
        }

        $this->api->respond(['data' => $record]);
    }

    public function store($resource)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if ($resource === 'marketplace') {
            $this->assertCommunityWriteAccess($auth);
            $input = $this->api->body();
            $id = $this->communityModel->createListing((int) $auth['sub'], $this->validateListingInput($input));
            $this->api->respond(['message' => 'Product added to the marketplace.', 'id' => $id], 201);
        }
        if ($resource === 'posts') {
            $this->assertCommunityWriteAccess($auth);
            $input = $this->api->body();
            $content = trim((string) ($input['content'] ?? ''));
            if ($content === '') {
                $this->api->respond_error('Post content is required.', 422);
            }
            $id = $this->communityModel->createPost((int) $auth['sub'], [
                'content' => $content,
                'post_type' => trim((string) ($input['post_type'] ?? 'text')) ?: 'text',
                'listing_id' => !empty($input['listing_id']) ? (int) $input['listing_id'] : null,
                'media_url' => trim((string) ($input['media_url'] ?? '')),
                'media_type' => trim((string) ($input['media_type'] ?? ''))
            ]);
            $this->api->respond(['message' => 'Post published successfully.', 'id' => $id], 201);
        }
        $this->assertWriteAccess($auth);
        $this->assertResource($resource);

        $id = $this->model->createResource($resource, $this->api->body());
        if ($id === null) {
            $this->api->respond_error('At least one supported field is required.', 422);
        }

        $this->api->respond([
            'message' => 'Record created successfully.',
            'id' => $id,
            'data' => $this->model->findResource($resource, $id)
        ], 201);
    }

    public function update($resource, $id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $this->assertWriteAccess($auth);
        $id = $this->validateId($id);
        $this->assertResource($resource);

        if (!$this->model->findResource($resource, $id)) {
            $this->api->respond_error('Record not found.', 404);
        }
        if (!$this->model->updateResource($resource, $id, $this->api->body())) {
            $this->api->respond_error('At least one supported field is required.', 422);
        }

        $this->api->respond([
            'message' => 'Record updated successfully.',
            'data' => $this->model->findResource($resource, $id)
        ]);
    }

    public function delete($resource, $id)
    {
        $this->api->require_method('DELETE');
        $auth = $this->api->require_jwt();
        $this->assertWriteAccess($auth);
        $id = $this->validateId($id);
        $this->assertResource($resource);

        if (!$this->model->findResource($resource, $id)) {
            $this->api->respond_error('Record not found.', 404);
        }
        $this->model->deleteResource($resource, $id);
        $this->api->respond(['message' => 'Record deleted successfully.']);
    }

    private function assertResource($resource)
    {
        if (!$this->model->hasResource($resource)) {
            $this->api->respond_error('Unsupported resource.', 404);
        }
    }

    private function assertWriteAccess(array $auth)
    {
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
            $this->api->respond_error('You do not have permission to modify records.', 403);
        }
    }

    private function assertCommunityWriteAccess(array $auth)
    {
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
            $this->api->respond_error('You do not have permission to publish content.', 403);
        }
    }

    private function validateListingInput(array $input)
    {
        $productName = trim((string) ($input['product_name'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $unit = trim((string) ($input['unit'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($productName === '' || $category === '' || $unit === '' || $price === false || $price < 0 || $stock === false || $stock <= 0) {
            $this->api->respond_error('Product name, category, unit, price, and stock are required.', 422);
        }

        return [
            'product_name' => $productName,
            'category' => $category,
            'description' => trim((string) ($input['description'] ?? '')),
            'price' => $price,
            'stock' => $stock,
            'unit' => $unit,
            'location' => trim((string) ($input['location'] ?? '')),
            'image_url' => trim((string) ($input['image_url'] ?? ''))
        ];
    }

    private function validateId($id)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Invalid record ID.', 422);
        }
        return $id;
    }
}
