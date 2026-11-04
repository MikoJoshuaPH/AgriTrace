<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AgriTraceController extends Controller
{
    private $model;
    private $communityModel;
    private $payments;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->model = $this->call->model('AgriTrace_model');
        $this->communityModel = $this->call->model('Community_model');
        $this->payments = $this->call->model('Payment_model');
        $this->platform = $this->call->model('Platform_model');
    }

    public function index($resource)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if ($resource === 'marketplace') {
            $query = $this->api->get_query_params();
            $this->api->respond(['data' => $this->communityModel->listListings($query['limit'] ?? 100)]);
        }
        if ($resource === 'posts') {
            $query = $this->api->get_query_params();
            $this->api->respond(['data' => $this->communityModel->listPosts($query['limit'] ?? 100)]);
        }
        $this->assertResource($resource);
        $this->assertTraceabilityResourceAccess($resource, $auth);

        $query = $this->api->get_query_params();
        $limit = $query['limit'] ?? 100;
        $data = ($auth['role'] ?? '') === 'farmer' && $resource !== 'products'
            ? $this->model->listResourceForFarmer($resource, (int) $auth['sub'], $limit)
            : $this->model->listResource($resource, $limit);
        $this->api->respond(['data' => $data]);
    }

    public function show($resource, $id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $id = $this->validateId($id);
        $this->assertResource($resource);
        $this->assertTraceabilityResourceAccess($resource, $auth);

        $record = ($auth['role'] ?? '') === 'farmer' && $resource !== 'products'
            ? $this->model->findResourceForFarmer($resource, $id, (int) $auth['sub'])
            : $this->model->findResource($resource, $id);
        if (!$record) {
            $this->api->respond_error('Record not found.', 404);
        }

        $this->api->respond(['data' => $record]);
    }

    public function store($resource)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $productDraftListingId = null;
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
        $this->assertTraceabilityResourceAccess($resource, $auth, true);

        $input = $this->api->body();
        if ($resource === 'harvests') {
            $this->assertHarvestApprovalAccess($auth, $input);
            if (($auth['role'] ?? '') === 'farmer') {
                $input['farmer_id'] = (int) $auth['sub'];
                if (!$this->model->batchBelongsToFarmer((int) ($input['batch_id'] ?? 0), (int) $auth['sub'])) {
                    $this->api->respond_error('You can only add harvest records to your own farm batches.', 403);
                }
            }
        } elseif ($resource === 'farms' && ($auth['role'] ?? '') === 'farmer') {
            $input['farmer_id'] = (int) $auth['sub'];
        } elseif ($resource === 'batches' && ($auth['role'] ?? '') === 'farmer') {
            $productListingId = filter_var($input['product_listing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productListingId === false) {
                $this->api->respond_error('Choose a product you have posted before creating a batch.', 422);
            }
            $productDraftListingId = (int) $productListingId;
            $productDraft = $this->communityModel->productDraftForFarmer((int) $productListingId, (int) $auth['sub']);
            if (!$productDraft) {
                $this->api->respond_error('Choose one of your saved products from a registered farm.', 403);
            }
            $unitPrice = filter_var($input['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
            $sellingUnit = trim((string) ($input['selling_unit'] ?? ''));
            $unit = trim((string) ($input['unit'] ?? ''));
            $allowedSellingUnits = ['piece', 'kg', 'bundle', 'pack', 'sack', 'box', 'dozen', 'set', 'liter', 'other'];
            if ($unitPrice === false || $unitPrice <= 0) {
                $this->api->respond_error('Enter a unit price greater than zero.', 422);
            }
            if (!in_array($sellingUnit, $allowedSellingUnits, true)) {
                $this->api->respond_error('Choose a valid selling unit.', 422);
            }
            if ($sellingUnit !== 'other') {
                $unit = $sellingUnit;
            } elseif ($unit === '' || mb_strlen($unit) > 30) {
                $this->api->respond_error('Enter a label for the selected selling unit.', 422);
            }
            $input['product_id'] = (int) $productDraft['catalog_id'];
            $input['farm_id'] = (int) $productDraft['farm_id'];
            $input['unit_price'] = $unitPrice;
            $input['selling_unit'] = $sellingUnit;
            $input['unit'] = $unit;
            $input['current_status'] = 'available_at_market';
            unset($input['product_listing_id']);
            if (!$this->model->farmBelongsToFarmer((int) $input['farm_id'], (int) $auth['sub'])) {
                $this->api->respond_error('You can only create batches for your own farms.', 403);
            }
        } elseif ($resource === 'shipments' && ($auth['role'] ?? '') === 'farmer') {
            if (!$this->model->batchBelongsToFarmer((int) ($input['batch_id'] ?? 0), (int) $auth['sub'])) {
                $this->api->respond_error('You can only record shipments for your own farm batches.', 403);
            }
        }
        $traceRow = null;
        if ($resource === 'batches') {
            $harvestDate = trim((string) ($input['harvest_date'] ?? ''));
            $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$this->isValidDate($harvestDate)) {
                $this->api->respond_error('A valid harvest date is required when creating a batch.', 422);
            }
            $expirationDate = trim((string) ($input['expiration_date'] ?? ''));
            if ($expirationDate !== '' && (!$this->isValidDate($expirationDate) || $expirationDate < $harvestDate)) {
                $this->api->respond_error('Expiration date must be a valid date on or after the harvest date.', 422);
            }
            if ($quantity === false || $quantity <= 0) {
                $this->api->respond_error('Batch quantity must be greater than zero.', 422);
            }
            if (empty($input['product_id']) || empty($input['farm_id']) || trim((string) ($input['unit'] ?? '')) === '') {
                $this->api->respond_error('Product, registered farm, quantity, and unit are required for a batch.', 422);
            }
            $traceCode = trim((string) ($input['trace_code'] ?? ''));
            unset($input['trace_code']);
            if ($traceCode !== '') {
                if (!preg_match('/^AGT-[A-F0-9]{10}$/', $traceCode)) {
                    $this->api->respond_error('Invalid order trace code.', 422);
                }
                $traceRow = $this->payments->trace_row_by_code(strtoupper($traceCode));
                if (!$traceRow) {
                    $this->api->respond_error('Order trace code not found.', 404);
                }
                if (!empty($traceRow['batch_id'])) {
                    $this->api->respond_error('Order trace code is already linked to a batch.', 409);
                }
            }
            $batchCode = trim((string) ($input['batch_code'] ?? ''));
            if (($auth['role'] ?? '') === 'farmer' || $batchCode === '') {
                $batchCode = $this->model->nextBatchCode((int) ($input['product_id'] ?? 0));
                $input['batch_code'] = $batchCode;
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', $batchCode) || strpos($batchCode, '..') !== false) {
                $this->api->respond_error('Batch code must contain only letters, numbers, periods, underscores, or hyphens.', 422);
            }
            if ($resource === 'harvests') {
                $input['harvest_reference'] = trim((string) ($input['harvest_reference'] ?? ''));
                $input['harvest_status'] = trim((string) ($input['harvest_status'] ?? 'harvested'));
                if ($input['harvest_reference'] === '') {
                    $input['harvest_reference'] = 'HAR-' . date('YmdHis') . '-' . random_int(100, 999);
                }
                if (!in_array($input['harvest_status'], ['planned', 'harvested', 'available', 'depleted', 'cancelled'], true)) {
                    $this->api->respond_error('Invalid harvest status.', 422);
                }
                $batch = $this->model->findResourceForFarmer('batches', (int) ($input['batch_id'] ?? 0), (int) $auth['sub']);
                if (!$batch) {
                    $this->api->respond_error('The harvest batch could not be found.', 404);
                }
                $input['product_id'] = (int) $batch['product_id'];
                $input['unit_of_measure'] = trim((string) ($input['unit_of_measure'] ?? $batch['unit']));
                $input['description'] = trim((string) ($input['description'] ?? $input['notes'] ?? ''));
            }
            if ($this->model->batchCodeExists($batchCode)) {
                $this->api->respond_error('That batch code is already in use.', 409);
            }
        }

        $isBatch = $resource === 'batches';
        if ($isBatch) {
            $this->db->transaction();
        }
        try {
            $id = $this->model->createResource($resource, $input);
            if ($id === null) {
                if ($isBatch) {
                    $this->db->roll_back();
                }
                $this->api->respond_error('At least one supported field is required.', 422);
            }

            $record = $this->model->findResource($resource, $id);
            if ($isBatch) {
                $farm = $this->model->findResource('farms', (int) $input['farm_id']);
                $farmerId = $farm && !empty($farm['farmer_id']) ? (int) $farm['farmer_id'] : (int) $auth['sub'];
                $this->model->createHarvestForBatch((int) $id, $farmerId, $input);
                if ($productDraftListingId !== null) {
                    $listingId = $this->communityModel->createListing((int) $auth['sub'], [
                    'product_name' => $productDraft['product_name'],
                    'category' => $productDraft['category'],
                    'description' => $productDraft['description'],
                    'price' => $input['unit_price'],
                    'stock' => $input['quantity'],
                    'unit' => $input['unit'],
                    'selling_unit' => $input['selling_unit'],
                    'catalog_id' => $input['product_id'],
                    'farm_id' => $input['farm_id'],
                    'batch_id' => $id,
                    'image_url' => $productDraft['image_url']
                    ], true);
                    $mediaUrl = $productDraft['image_url'] !== ''
                        ? json_encode([['url' => $productDraft['image_url'], 'type' => 'image']])
                        : '';
                    $this->communityModel->createPost((int) $auth['sub'], [
                    'content' => $productDraft['product_name'] . ' is now available in the AgriTrace Marketplace.',
                    'post_type' => 'product',
                    'listing_id' => $listingId,
                    'media_url' => $mediaUrl,
                    'media_type' => $mediaUrl === '' ? '' : 'image-gallery'
                    ], 'approved');
                    $this->platform->audit((int) $auth['sub'], 'PUBLISH_BATCH_PRODUCT', 'Listing', $listingId, $productDraft['product_name']);
                }
            }
            if ($isBatch) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($isBatch) {
                $this->db->roll_back();
            }
            $this->api->respond_error($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
        }
        $logger = $this->call->library('Traceability_logger');
        $logger->record_change($resource, [], $record, (int) $auth['sub']);

        if ($resource === 'batches') {
            if ($traceRow) {
                $this->payments->link_trace_to_batch((int) $traceRow['trace_id'], (int) $id);
            }

            $qrUrl = null;
            $qrError = null;
            try {
                $qr = $this->call->library('QrCoder');
                $cloud = $this->call->library('Cloud_storage');
                $traceUrl = rtrim((string) config_item('app_frontend_url'), '/')
                    . '/trace?batch=' . rawurlencode($record['batch_code']);
                $png = $qr->png($traceUrl);
                $stored = $cloud->put('qr/batches/' . $record['batch_code'] . '.png', $png, 'image/png');
                if (empty($stored['url'])) {
                    throw new RuntimeException('Cloud storage did not return a URL for the batch QR code.');
                }
                $this->model->saveBatchQr((int) $id, $record['batch_code'], $stored['url']);
                $qrUrl = $stored['url'];
            } catch (RuntimeException $e) {
                $qrError = $e->getMessage();
            } catch (InvalidArgumentException $e) {
                $qrError = $e->getMessage();
            }

            $response = [
                'message' => $qrError === null ? 'Batch created successfully.' : 'Batch created, but its QR code could not be generated.',
                'id' => $id,
                'data' => $record,
                'qr_url' => $qrUrl,
                'listing_id' => $listingId ?? null
            ];
            if ($qrError !== null) {
                $response['qr_error'] = $qrError;
            }
            $this->api->respond($response, 201);
        }

        $this->api->respond([
            'message' => 'Record created successfully.',
            'id' => $id,
            'data' => $record
        ], 201);
    }

    public function update($resource, $id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $this->assertWriteAccess($auth);
        $id = $this->validateId($id);
        $this->assertResource($resource);
        $this->assertTraceabilityResourceAccess($resource, $auth, true);

        $before = ($auth['role'] ?? '') === 'farmer' && $resource !== 'products'
            ? $this->model->findResourceForFarmer($resource, $id, (int) $auth['sub'])
            : $this->model->findResource($resource, $id);
        if (!$before) {
            $this->api->respond_error('Record not found.', 404);
        }
        $input = $this->api->body();
        if ($resource === 'harvests') {
            $this->assertHarvestApprovalAccess($auth, $input);
            if (($auth['role'] ?? '') === 'farmer') {
                $input['farmer_id'] = (int) $auth['sub'];
                if (isset($input['batch_id']) && !$this->model->batchBelongsToFarmer((int) $input['batch_id'], (int) $auth['sub'])) {
                    $this->api->respond_error('You can only update harvests for your own farm batches.', 403);
                }
            }
        } elseif ($resource === 'farms' && ($auth['role'] ?? '') === 'farmer') {
            if (isset($input['farmer_id']) && (int) $input['farmer_id'] !== (int) $auth['sub']) {
                $this->api->respond_error('You cannot transfer a farm to another account.', 403);
            }
            unset($input['farmer_id']);
        } elseif ($resource === 'batches' && ($auth['role'] ?? '') === 'farmer') {
            if (isset($input['farm_id']) && !$this->model->farmBelongsToFarmer((int) $input['farm_id'], (int) $auth['sub'])) {
                $this->api->respond_error('You can only move batches between your own farms.', 403);
            }
        } elseif ($resource === 'shipments' && ($auth['role'] ?? '') === 'farmer') {
            if (isset($input['batch_id']) && !$this->model->batchBelongsToFarmer((int) $input['batch_id'], (int) $auth['sub'])) {
                $this->api->respond_error('You can only update shipments for your own farm batches.', 403);
            }
        }
        if (!$this->model->updateResource($resource, $id, $input)) {
            $this->api->respond_error('At least one supported field is required.', 422);
        }

        $record = $this->model->findResource($resource, $id);
        $logger = $this->call->library('Traceability_logger');
        $logger->record_change($resource, $before, $record, (int) $auth['sub']);
        $this->api->respond([
            'message' => 'Record updated successfully.',
            'data' => $record
        ]);
    }

    public function delete($resource, $id)
    {
        $this->api->require_method('DELETE');
        $auth = $this->api->require_jwt();
        $this->assertWriteAccess($auth);
        $id = $this->validateId($id);
        $this->assertResource($resource);
        $this->assertTraceabilityResourceAccess($resource, $auth, true);

        $record = ($auth['role'] ?? '') === 'farmer' && $resource !== 'products'
            ? $this->model->findResourceForFarmer($resource, $id, (int) $auth['sub'])
            : $this->model->findResource($resource, $id);
        if (!$record) {
            $this->api->respond_error('Record not found.', 404);
        }
        if ($resource === 'batches') {
            $this->db->begin();
            try {
                $farmerId = ($auth['role'] ?? '') === 'farmer' ? (int) $auth['sub'] : null;
                $deleted = $this->model->deleteBatchSafely($id, $farmerId);
            } catch (Throwable $error) {
                $this->db->roll_back();
                throw $error;
            }
            if (!$deleted) {
                $this->db->roll_back();
                $this->api->respond_error('This batch is linked to an order or other dependent records and cannot be deleted.', 409);
            }
            $this->db->commit();
        } elseif ($resource === 'harvests') {
            if (!$this->model->deleteHarvestSafely($id)) {
                $this->api->respond_error('This harvest record is linked to an order allocation or weather record and cannot be deleted.', 409);
            }
        } else {
            $this->model->deleteResource($resource, $id);
        }
        $logger = $this->call->library('Traceability_logger');
        $logger->record_change($resource, $record, [], (int) $auth['sub']);
        $this->platform->audit((int) $auth['sub'], 'DELETE_TRACEABILITY_RECORD', ucfirst($resource), $id);
        $this->api->respond(['message' => 'Record deleted successfully.']);
    }

    public function farmer_traceability()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        $this->api->respond(['data' => $this->model->farmerTraceability((int) $auth['sub'])]);
    }

    private function isValidDate($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
        return $date !== false && $date->format('Y-m-d') === $value;
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

    private function assertTraceabilityResourceAccess($resource, array $auth, $write = false)
    {
        if (!in_array($resource, ['farms', 'products', 'batches', 'harvests', 'shipments'], true)) {
            return;
        }
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer'], true)) {
            $this->api->respond_error('Only farmers and administrators can access farm traceability records.', 403);
        }
        if ($write && $resource === 'products' && ($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Only administrators can manage the shared product catalog.', 403);
        }
    }

    private function assertCommunityWriteAccess(array $auth)
    {
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
            $this->api->respond_error('You do not have permission to publish content.', 403);
        }
    }

    private function assertHarvestApprovalAccess(array $auth, array $input)
    {
        if (!array_key_exists('approval_status', $input)) {
            return;
        }
        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Only administrators can change harvest approval status.', 403);
        }
        if (!in_array($input['approval_status'], ['pending', 'approved', 'rejected'], true)) {
            $this->api->respond_error('Invalid harvest approval status.', 422);
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
            $this->api->respond_error('Product name, category, unit, price, and batch are required.', 422);
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
