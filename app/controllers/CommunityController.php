<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CommunityController extends Controller
{
    private $model;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->model = $this->call->model('Community_model');
        $this->platform = $this->call->model('Platform_model');
    }

    /** GET /api/marketplace  (?mine=1 returns the caller's own listings) */
    public function listings()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $mine = !empty($query['mine']);
        $this->api->respond(['data' => $this->model->listListings((int) $auth['sub'], $query['limit'] ?? 100, $mine)]);
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

    /** POST /api/marketplace — listings from non-admins wait for admin approval. */
    public function storeListing()
    {
        $auth = $this->authenticateWrite();
        $input = $this->api->body();
        $input = $this->validateListing($input);
        $isAdmin = ($auth['role'] ?? '') === 'admin';

        $id = $this->model->createListing((int) $auth['sub'], $input, $isAdmin);
        $this->platform->audit((int) $auth['sub'], 'CREATE_LISTING', 'Listing', $id, $input['product_name']);

        $this->api->respond([
            'message' => $isAdmin ? 'Product published.' : 'Product submitted. It will appear in the marketplace after admin approval.',
            'id' => $id,
            'status' => $isAdmin ? 'approved' : 'pending'
        ], 201);
    }

    /** GET /api/posts — approved posts, plus the caller's own pending posts. */
    public function posts()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $this->api->respond(['data' => $this->model->listPosts((int) $auth['sub'], $query['limit'] ?? 100)]);
    }

    /** POST /api/posts — non-admin posts start as "pending" until an admin approves them. */
    public function storePost()
    {
        $auth = $this->authenticateWrite();
        $input = $this->api->body();
        $userId = (int) $auth['sub'];
        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            $this->api->respond_error('Post content is required.', 422);
        }

        $listingId = !empty($input['listing_id']) ? (int) $input['listing_id'] : null;
        if ($listingId !== null) {
            $listing = $this->model->listing_owner($listingId);
            if (!$listing || (int) $listing['seller_id'] !== $userId) {
                $this->api->respond_error('You can only link your own product listing.', 422);
            }
        }

        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $id = $this->model->createPost($userId, [
            'content' => $content,
            'post_type' => trim((string) ($input['post_type'] ?? 'text')) ?: 'text',
            'listing_id' => $listingId,
            'media_url' => trim((string) ($input['media_url'] ?? '')),
            'media_type' => trim((string) ($input['media_type'] ?? ''))
        ], $isAdmin ? 'approved' : 'pending');

        $this->platform->audit($userId, 'CREATE_POST', 'Post', $id, $content);
        $this->api->respond([
            'message' => $isAdmin ? 'Post published successfully.' : 'Post submitted. It will appear after admin approval.',
            'id' => $id,
            'status' => $isAdmin ? 'approved' : 'pending'
        ], 201);
    }

    /** POST /api/media — stores an image or video under public/uploads/community. */
    public function uploadMedia()
    {
        $this->api->require_method('POST');
        $this->api->require_jwt();

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
            $stored = $cloud->put('community/' . date('Ym') . '/' . bin2hex(random_bytes(16)) . '.' . $extension,
                file_get_contents($file['tmp_name']), $mime);
        } catch (RuntimeException $e) {
            $this->api->respond_error('The media upload could not be saved.', 500);
        }

        $this->api->respond([
            'message' => 'Media uploaded successfully.',
            'url' => $stored['url'],
            'type' => $type,
            'driver' => $stored['driver']
        ], 201);
    }

    private function validateListing(array $input)
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
