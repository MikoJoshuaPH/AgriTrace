<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CommunityController extends Controller
{
    private $model;
    private $platform;
    private $catalog;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->model = $this->call->model('Community_model');
        $this->platform = $this->call->model('Platform_model');
        $this->catalog = $this->call->model('Catalog_model');
    }

    /** GET /api/marketplace  (?mine=1 returns the caller's own listings) */
    public function listings()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $mine = !empty($query['mine']);
        $this->api->respond(['data' => $this->model->listListings((int) $auth['sub'], $query['limit'] ?? 100, $mine, $query)]);
    }

    /** GET /api/search — related Marketplace, FarmerHub, and registered farm results. */
    public function search()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $term = $query['q'] ?? '';
        if (!is_string($term)) {
            $this->api->respond_error('Search query must be text.', 422);
        }
        $term = trim($term);
        if (mb_strlen($term) < 2 || mb_strlen($term) > 100) {
            $this->api->respond_error('Search must be between 2 and 100 characters.', 422);
        }

        $farmers = $this->platform->farmers((int) $auth['sub']);
        $matchingFarmers = array_values(array_filter($farmers, static function ($farmer) use ($term) {
            $profile = implode(' ', array_filter([
                $farmer['first_name'] ?? '',
                $farmer['middle_name'] ?? '',
                $farmer['last_name'] ?? '',
                $farmer['username'] ?? '',
                $farmer['farm_name'] ?? '',
                $farmer['municipality'] ?? '',
                $farmer['province'] ?? ''
            ]));
            return stripos($profile, $term) !== false;
        }));

        $this->api->respond([
            'data' => $this->model->searchNavigation((int) $auth['sub'], $term, $matchingFarmers, $farmers)
        ]);
    }

    /** GET /api/marketplace/{id}/reviews — reviews and purchase eligibility for the current user. */
    public function reviews($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $listingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listing = $listingId === false ? null : $this->model->listing_owner($listingId);
        if (!$listing) {
            $this->api->respond_error('Product not found.', 404);
        }

        $reviewModel = $this->call->model('Product_review_model');
        $this->api->respond($reviewModel->for_listing($listingId, (int) $auth['sub']));
    }

    /** POST /api/marketplace/{id}/reviews — only buyers with paid orders may rate a product. */
    public function create_review($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $listingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listing = $listingId === false ? null : $this->model->listing_owner($listingId);
        if (!$listing) {
            $this->api->respond_error('Product not found.', 404);
        }

        $input = $this->api->body();
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
        if ($rating === false || $rating < 1 || $rating > 5) {
            $this->api->respond_error('Choose a rating from 1 to 5 stars.', 422);
        }
        $review = $input['review'] ?? '';
        if (!is_string($review) || mb_strlen(trim($review)) > 1000) {
            $this->api->respond_error('Reviews must be 1000 characters or fewer.', 422);
        }
        $review = trim($review);
        $userId = (int) $auth['sub'];
        if ((int) $listing['seller_id'] === $userId) {
            $this->api->respond_error('You cannot review your own product.', 403);
        }
        $reviewModel = $this->call->model('Product_review_model');
        $purchase = $reviewModel->eligible_order_item($listingId, $userId);
        if (!$purchase) {
            $this->api->respond_error('You can review this product after completing payment for an order.', 403);
        }

        $reviewId = $reviewModel->save_review(
            $listingId,
            $userId,
            (int) $purchase['item_id'],
            $rating,
            $review === '' ? null : $review
        );
        $this->platform->audit($userId, 'REVIEW_PRODUCT', 'ProductReview', $reviewId, 'Rated ' . $rating . ' stars');
        $this->api->respond(['message' => 'Your product review has been saved.']);
    }

    /** POST /api/marketplace/{id}/reviews/{reviewId}/comments — any signed-in user may comment. */
    public function create_review_comment($id, $reviewId)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $listingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewId = filter_var($reviewId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewModel = $this->call->model('Product_review_model');
        if ($listingId === false || $reviewId === false || !$reviewModel->review_exists($listingId, $reviewId)) {
            $this->api->respond_error('Review not found.', 404);
        }

        $input = $this->api->body();
        $comment = $input['comment'] ?? '';
        if (!is_string($comment) || trim($comment) === '' || mb_strlen(trim($comment)) > 1000) {
            $this->api->respond_error('Comments must contain 1 to 1000 characters.', 422);
        }
        $commentId = $reviewModel->add_comment($reviewId, (int) $auth['sub'], trim($comment));
        $this->platform->audit((int) $auth['sub'], 'COMMENT_PRODUCT_REVIEW', 'ProductReviewComment', $commentId);
        $this->api->respond(['message' => 'Your comment has been posted.'], 201);
    }

    /** POST /api/marketplace — publish a product listing immediately. */
    public function storeListing()
    {
        $auth = $this->authenticateWrite();
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer'], true)) {
            $this->api->respond_error('Only farmers and administrators can list products.', 403);
        }
        $input = $this->api->body();
        if (!empty($input['product_draft'])) {
            if (($auth['role'] ?? '') !== 'farmer') {
                $this->api->respond_error('Only farmers can save products before creating a harvest batch.', 403);
            }
            $productName = trim((string) ($input['product_name'] ?? ''));
            $category = trim((string) ($input['category'] ?? ''));
            $farmId = filter_var($input['farm_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $imageUrl = $input['image_url'] ?? '';
            if ($productName === '' || mb_strlen($productName) > 150 || $category === '' || mb_strlen($category) > 100) {
                $this->api->respond_error('Enter a product name and category.', 422);
            }
            if ($farmId === false || !$this->model->farmBelongsToFarmer((int) $farmId, (int) $auth['sub'])) {
                $this->api->respond_error('Select a registered farm that belongs to your account.', 403);
            }
            if (!is_string($imageUrl) || strlen($imageUrl) > 2048) {
                $this->api->respond_error('Product image URL is invalid.', 422);
            }
            $catalogId = $this->catalog->createOrGet($productName, $category, 'unit');
            $id = $this->model->createListing((int) $auth['sub'], [
                'product_name' => $productName,
                'category' => $category,
                'description' => trim((string) ($input['description'] ?? '')),
                'price' => 0,
                'stock' => 0,
                'unit' => 'unit',
                'selling_unit' => 'other',
                'catalog_id' => $catalogId,
                'farm_id' => (int) $farmId,
                'batch_id' => null,
                'image_url' => trim($imageUrl)
            ], false);
            $this->platform->audit((int) $auth['sub'], 'SAVE_PRODUCT_DRAFT', 'Listing', $id, $productName);
            $this->api->respond([
                'message' => 'Product saved. Create a new batch to publish it to the marketplace and feed.',
                'id' => $id,
                'status' => 'draft'
            ], 201);
        }
        $input = $this->validateListing($input);
        if (($auth['role'] ?? '') === 'farmer') {
            if (empty($input['farm_id']) || !$this->model->farmBelongsToFarmer((int) $input['farm_id'], (int) $auth['sub'])) {
                $this->api->respond_error('Select a registered farm that belongs to your account.', 403);
            }
            $batch = empty($input['batch_id']) ? null : $this->model->batchForFarmer((int) $input['batch_id'], (int) $auth['sub']);
            if (!$batch) {
                $this->api->respond_error('Select a batch from your registered farm.', 422);
            }
            if ((int) $batch['farm_id'] !== (int) $input['farm_id']) {
                $this->api->respond_error('The selected batch must belong to the selected farm.', 422);
            }
            $sellingUnit = $input['selling_unit'] === 'other' ? $input['unit'] : $input['selling_unit'];
            if (strtolower((string) $sellingUnit) !== strtolower((string) $batch['unit'])) {
                $this->api->respond_error('Choose a selling unit that matches the selected batch inventory unit.', 422);
            }
            if (!empty($input['catalog_id']) && (int) $input['catalog_id'] !== (int) $batch['product_id']) {
                $this->api->respond_error('The selected product does not match the selected harvest batch.', 422);
            }
            $input['stock'] = $batch['stock'];
            $input['unit'] = $batch['unit'];
        }
        if (empty($input['catalog_id'])) {
            $input['catalog_id'] = $this->catalog->createOrGet($input['product_name'], $input['category'], $input['unit']);
        }
        $id = $this->model->createListing((int) $auth['sub'], $input, true);
        $this->platform->audit((int) $auth['sub'], 'CREATE_LISTING', 'Listing', $id, $input['product_name']);

        $this->api->respond([
            'message' => 'Product published.',
            'id' => $id,
            'status' => 'approved'
        ], 201);
    }

    /** PUT /api/marketplace/{id} — update a seller's own product listing. */
    public function updateListing($id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $listingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listing = $listingId === false ? null : $this->model->listing_owner($listingId);
        if (!$listing) {
            $this->api->respond_error('Product not found.', 404);
        }
        if ((int) $listing['seller_id'] !== (int) $auth['sub']) {
            $this->api->respond_error('You can only edit your own product listings.', 403);
        }

        $body = $this->api->body();
        if (!empty($body['product_details_only'])) {
            if (($auth['role'] ?? '') !== 'farmer') {
                $this->api->respond_error('Product details can only be edited by the farmer who posted them.', 403);
            }
            $productName = trim((string) ($body['product_name'] ?? ''));
            $category = trim((string) ($body['category'] ?? ''));
            $description = trim((string) ($body['description'] ?? ''));
            $imageUrl = $body['image_url'] ?? '';
            if ($productName === '' || mb_strlen($productName) > 150 || $category === '' || mb_strlen($category) > 100) {
                $this->api->respond_error('Enter a product name (up to 150 characters) and category (up to 100 characters).', 422);
            }
            if (mb_strlen($description) > 10000 || !is_string($imageUrl) || strlen($imageUrl) > 2048) {
                $this->api->respond_error('Product description or image URL is invalid.', 422);
            }
            $input = [
                'product_name' => $productName,
                'category' => $category,
                'description' => $description,
                'image_url' => trim($imageUrl)
            ];
            $this->model->updateProductDetails($listingId, (int) $auth['sub'], $input);
            $this->platform->audit((int) $auth['sub'], 'UPDATE_PRODUCT_DETAILS', 'Listing', $listingId, $productName);
            $this->api->respond(['message' => 'Product details updated successfully.']);
        }
        if (!array_key_exists('image_url', $body)) {
            $body['image_url'] = $listing['image_url'] ?? '';
        }
        if (!array_key_exists('batch_id', $body)) {
            $body['batch_id'] = $listing['batch_id'] ?? null;
        }
        $input = $this->validateListing($body, true);
        if (($auth['role'] ?? '') === 'farmer' &&
            (empty($input['farm_id']) || !$this->model->farmBelongsToFarmer((int) $input['farm_id'], (int) $auth['sub']))) {
            $this->api->respond_error('Select a registered farm that belongs to your account.', 403);
        }
        if (($auth['role'] ?? '') === 'farmer') {
            $batch = $this->model->batchForFarmer((int) ($input['batch_id'] ?? 0), (int) $auth['sub']);
            if (!$batch || (int) $batch['farm_id'] !== (int) $input['farm_id']) {
                $this->api->respond_error('Select a batch from the selected registered farm.', 422);
            }
            $sellingUnit = $input['selling_unit'] === 'other' ? $input['unit'] : $input['selling_unit'];
            if (strtolower((string) $sellingUnit) !== strtolower((string) $batch['unit'])) {
                $this->api->respond_error('Choose a selling unit that matches the selected batch inventory unit.', 422);
            }
            if (!empty($input['catalog_id']) && (int) $input['catalog_id'] !== (int) $batch['product_id']) {
                $this->api->respond_error('The selected product does not match the selected harvest batch.', 422);
            }
            $input['stock'] = $batch['stock'];
            $input['unit'] = $batch['unit'];
        }
        $this->model->updateListing($listingId, $input);
        if (($auth['role'] ?? '') === 'farmer' && !empty($input['batch_id'])) {
            $this->model->updateBatchPrice((int) $input['batch_id'], $input['price']);
        }
        $this->model->update_listing_image($listingId, $input['image_url']);
        $this->platform->audit((int) $auth['sub'], 'UPDATE_LISTING', 'Listing', $listingId, $input['product_name']);
        $this->api->respond(['message' => 'Product updated successfully.']);
    }

    /** GET /api/posts — published community posts. */
    public function posts()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $this->api->respond(['data' => $this->model->listPosts((int) $auth['sub'], $query['limit'] ?? 100)]);
    }

    /** POST /api/posts — publish immediately without an approval queue. */
    public function storePost()
    {
        $auth = $this->authenticateWrite();
        $input = $this->api->body();
        $userId = (int) $auth['sub'];
        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            $this->api->respond_error('Post content is required.', 422);
        }

        $postType = trim((string) ($input['post_type'] ?? 'text')) ?: 'text';
        if (!in_array($postType, ['text', 'media', 'product'], true)) {
            $this->api->respond_error('Post type must be text, media, or product.', 422);
        }
        $role = $auth['role'] ?? '';
        if (!in_array($role, ['admin', 'farmer', 'collector'], true)) {
            $this->api->respond_error('Only farmers, buyers, and administrators can publish posts.', 403);
        }
        if ($postType === 'product' && !in_array($role, ['admin', 'farmer'], true)) {
            $this->api->respond_error('Only farmers and administrators can publish product posts.', 403);
        }

        $listingId = !empty($input['listing_id']) ? (int) $input['listing_id'] : null;
        if (($postType === 'product') !== ($listingId !== null)) {
            $this->api->respond_error('Product posts must link to a product listing, and other posts cannot link a listing.', 422);
        }
        if ($listingId !== null) {
            $listing = $this->model->listing_owner($listingId);
            if (!$listing || (int) $listing['seller_id'] !== $userId) {
                $this->api->respond_error('You can only link your own product listing.', 422);
            }
        }

        $id = $this->model->createPost($userId, [
            'content' => $content,
            'post_type' => $postType,
            'listing_id' => $listingId,
            'media_url' => trim((string) ($input['media_url'] ?? '')),
            'media_type' => trim((string) ($input['media_type'] ?? ''))
        ], 'approved');

        $this->platform->audit($userId, 'CREATE_POST', 'Post', $id, $content);
        $this->api->respond([
            'message' => 'Post published successfully.',
            'id' => $id,
            'status' => 'approved'
        ], 201);
    }

    /** POST /api/posts/{id}/reports — reports a post for administrator review. */
    public function report_post($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $postId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $post = $postId === false ? null : $this->model->post_for_report($postId);
        if (!$post || $post['status'] !== 'approved') {
            $this->api->respond_error('Post not found.', 404);
        }
        if ((int) $post['author_id'] === (int) $auth['sub']) {
            $this->api->respond_error('You cannot report your own post.', 403);
        }

        $input = $this->api->body();
        if (!is_string($input['reason'] ?? null)) {
            $this->api->respond_error('Please describe the issue in 1 to 255 characters.', 422);
        }
        $reason = trim($input['reason']);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->api->respond_error('Please describe the issue in 1 to 255 characters.', 422);
        }

        $reportId = $this->platform->create_post_report(
            (int) $auth['sub'],
            (int) $post['author_id'],
            (int) $postId,
            $reason,
            $post
        );
        $this->platform->audit((int) $auth['sub'], 'REPORT_POST', 'Post', (int) $postId, $reason);
        $this->api->respond(['message' => 'Your report was sent to the administrator for review.', 'report_id' => $reportId], 201);
    }

    /** POST /api/media — stores an image or video in the public media bucket. */
    public function uploadMedia()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $folder = (string) ($_POST['folder'] ?? 'community');
        if (!in_array($folder, ['community', 'profiles'], true)) {
            $this->api->respond_error('Invalid media folder.', 422);
        }
        if ($folder === 'community' && !in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector'], true)) {
            $this->api->respond_error('Only farmers, buyers, and administrators can upload post media.', 403);
        }

        if (empty($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            $this->api->respond_error('Please choose a photo or video to upload.', 422);
        }

        $file = $_FILES['media'];
        if ($file['size'] > 8 * 1024 * 1024) {
            $this->api->respond_error('Media files must be 8 MB or smaller.', 422);
        }

        $allowed = [
            'image/jpeg' => ['image', 'jpg'],
            'image/png'  => ['image', 'png'],
            'image/gif'  => ['image', 'gif'],
            'image/webp' => ['image', 'webp'],
            'video/mp4'  => ['video', 'mp4'],
            'video/webm' => ['video', 'webm'],
            'video/quicktime' => ['video', 'mov'],
        ];
        // The type is decided by the file contents, and the stored extension comes from that type,
        // never from the client-supplied filename.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            $this->api->respond_error('Only JPG, PNG, GIF, WEBP, MP4, WEBM, and MOV files are supported.', 422);
        }
        [$type, $extension] = $allowed[$mime];

        $cloud = $this->call->library('Cloud_storage');
        try {
            $stored = $cloud->put('users/' . (int) $auth['sub'] . '/' . date('Y-m-d') . '/' . bin2hex(random_bytes(16)) . '.' . $extension,
                file_get_contents($file['tmp_name']), $mime);
        } catch (RuntimeException $e) {
            $this->api->respond_error('The media upload could not be saved: ' . $e->getMessage(), 502);
        }

        $this->api->respond([
            'message' => 'Media uploaded successfully.',
            'url' => $stored['url'],
            'type' => $type,
            'driver' => $stored['driver']
        ], 201);
    }

    private function validateListing(array $input, $allowZeroStock = false)
    {
        $productName = trim((string) ($input['product_name'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $unit = trim((string) ($input['unit'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_FLOAT);
        $batchId = filter_var($input['batch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $imageUrl = $input['image_url'] ?? '';
        $sellingUnit = trim((string) ($input['selling_unit'] ?? ''));
        $farmId = filter_var($input['farm_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $allowedSellingUnits = ['piece', 'kg', 'bundle', 'pack', 'sack', 'box', 'dozen', 'set', 'liter', 'other'];

        if ($productName === '' || $category === '' || $unit === '' || $price === false || $price < 0 || $batchId === false) {
            $this->api->respond_error('Product name, category, price, and batch are required.', 422);
        }
        if (!in_array($sellingUnit, $allowedSellingUnits, true)) {
            $this->api->respond_error('Choose a valid selling unit.', 422);
        }
        if ($sellingUnit !== 'other') {
            $unit = $sellingUnit;
        }
        if ($sellingUnit === 'other' && $unit === '') {
            $this->api->respond_error('Enter a label for the selling unit.', 422);
        }
        if (!is_string($imageUrl) || strlen($imageUrl) > 2048) {
            $this->api->respond_error('Product image URL is invalid.', 422);
        }
        return [
            'product_name' => $productName,
            'category' => $category,
            'description' => trim((string) ($input['description'] ?? '')),
            'price' => $price,
            'stock' => $stock === false ? 0 : $stock,
            'unit' => $unit,
            'selling_unit' => $sellingUnit,
            'catalog_id' => !empty($input['catalog_id']) ? (int) $input['catalog_id'] : null,
            'farm_id' => $farmId === false ? null : (int) $farmId,
            'batch_id' => (int) $batchId,
            'location' => trim((string) ($input['location'] ?? '')),
            'image_url' => trim($imageUrl)
        ];
    }

    private function authenticateWrite()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (!in_array($auth['role'] ?? '', ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
            $this->api->respond_error('You do not have permission to publish content.', 403);
        }
        return $auth;
    }
}
