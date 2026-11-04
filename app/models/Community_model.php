<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Community_model extends Model
{
    /** Marketplace listings: available and in stock, or the caller's own listings with $mine. */
    public function listListings($viewerId, $limit = 100, $mine = false, array $filters = [])
    {
        $limit = max(1, min((int) $limit, 100));
        // Sellers can include unavailable listings in their own management view.
        $query = $this->db->table('marketplace_listings')
            ->select('marketplace_listings.listing_id, marketplace_listings.seller_id, marketplace_listings.product_name, marketplace_listings.category, marketplace_listings.description, marketplace_listings.price, marketplace_listings.stock, marketplace_listings.unit, marketplace_listings.selling_unit, marketplace_listings.catalog_id, marketplace_listings.farm_id, marketplace_listings.batch_id, pb.available_quantity AS batch_stock, pb.quantity AS batch_quantity, pb.expiration_date, pb.unit_price AS batch_price, pb.current_status AS batch_status, f.farm_name, f.farm_description, f.municipality, f.province, f.latitude AS farm_latitude, f.longitude AS farm_longitude, marketplace_listings.location, marketplace_listings.image_url, marketplace_listings.is_available, marketplace_listings.created_at, u.username')
            ->inner_join('users u', 'u.id = marketplace_listings.seller_id');
        $query->left_join('farms f', 'f.farm_id = marketplace_listings.farm_id');
        $query->left_join('product_batches pb', 'pb.batch_id = marketplace_listings.batch_id');
        if ($mine) {
            $query->where('marketplace_listings.seller_id', $viewerId);
        } else {
            $query->where('marketplace_listings.is_available', 1);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->grouped(function ($db) use ($search) {
                $db->where('marketplace_listings.product_name', 'LIKE', '%' . $search . '%')
                    ->or_where('marketplace_listings.category', 'LIKE', '%' . $search . '%')
                    ->or_where('u.username', 'LIKE', '%' . $search . '%')
                    ->or_where('u.first_name', 'LIKE', '%' . $search . '%')
                    ->or_where('u.middle_name', 'LIKE', '%' . $search . '%')
                    ->or_where('u.last_name', 'LIKE', '%' . $search . '%')
                    ->or_where('f.farm_name', 'LIKE', '%' . $search . '%')
                    ->or_where('f.municipality', 'LIKE', '%' . $search . '%')
                    ->or_where('f.province', 'LIKE', '%' . $search . '%')
                    ->or_where('marketplace_listings.location', 'LIKE', '%' . $search . '%');
            });
        }
        $sellerIds = is_array($filters['seller_ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $filters['seller_ids']))))
            : [];
        $farmIds = is_array($filters['farm_ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $filters['farm_ids']))))
            : [];
        if ($sellerIds || $farmIds) {
            $query->grouped(function ($db) use ($sellerIds, $farmIds) {
                $hasCondition = false;
                foreach ($sellerIds as $sellerId) {
                    $hasCondition ? $db->or_where('marketplace_listings.seller_id', $sellerId) : $db->where('marketplace_listings.seller_id', $sellerId);
                    $hasCondition = true;
                }
                foreach ($farmIds as $farmId) {
                    $hasCondition ? $db->or_where('marketplace_listings.farm_id', $farmId) : $db->where('marketplace_listings.farm_id', $farmId);
                    $hasCondition = true;
                }
            });
        }
        if (!empty($filters['category'])) $query->where('marketplace_listings.category', $filters['category']);
        if (!empty($filters['unit'])) $query->where('marketplace_listings.unit', $filters['unit']);
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) $query->where('marketplace_listings.price', '>=', (float) $filters['min_price']);
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) $query->where('marketplace_listings.price', '<=', (float) $filters['max_price']);
        $sort = $filters['sort'] ?? 'newest';
        $sortField = $sort === 'price_asc' ? 'marketplace_listings.price' : ($sort === 'price_desc' ? 'marketplace_listings.price' : 'marketplace_listings.listing_id');
        $sortDirection = in_array($sort, ['price_asc', 'price_desc'], true) ? ($sort === 'price_asc' ? 'ASC' : 'DESC') : 'DESC';
        $listings = $query->order_by($sortField, $sortDirection)->limit($limit)->get_all(PDO::FETCH_ASSOC);
        foreach ($listings as &$listing) {
            if ($listing['batch_id'] !== null) {
                $listing['stock'] = $listing['batch_stock'] !== null
                    ? $listing['batch_stock']
                    : ($listing['batch_quantity'] ?? $listing['stock']);
            }
            if ($listing['batch_price'] !== null) {
                $listing['price'] = $listing['batch_price'];
            }
            $listing['batch_expired'] = !empty($listing['expiration_date'])
                && $listing['expiration_date'] < date('Y-m-d');
            $listing['batch_status'] = $listing['batch_status'] ?? null;
            unset($listing['batch_stock'], $listing['batch_quantity'], $listing['batch_price'], $listing['expiration_date']);
            $listing['farm_name'] = $listing['farm_name'] ?: 'Registered farm';
            $generalLocation = array_filter([$listing['municipality'] ?? null, $listing['province'] ?? null]);
            $listing['location'] = $generalLocation ? implode(', ', $generalLocation) : null;
            $listing['batch'] = null;
            $listing['batches'] = [];
            if (!empty($listing['batch_id'])) {
                $batch = $this->db->raw(
                    'SELECT batch_id, batch_code, harvest_date, expiration_date,
                            COALESCE(available_quantity, quantity) AS available_quantity, unit, current_status
                     FROM product_batches WHERE batch_id = ? LIMIT 1',
                    [(int) $listing['batch_id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ($batch) {
                    $listing['batch'] = $batch;
                    $listing['batches'] = [$batch];
                    $listing['stock'] = (float) $batch['available_quantity'];
                    $listing['batch_status'] = $batch['current_status'];
                    if (in_array($batch['current_status'], ['sold', 'rejected'], true)) {
                        $listing['stock'] = 0;
                    }
                }
            }
        }
        unset($listing);
        if (!$mine) {
            $listings = array_values(array_filter($listings, static function ($listing) {
                return (int) $listing['is_available'] === 1
                    && !empty($listing['batch_id'])
                    && empty($listing['batch_expired'])
                    && !in_array($listing['batch_status'], ['sold', 'rejected'], true)
                    && (float) $listing['stock'] > 0;
            }));
        }
        return $this->attach_listing_ratings($listings);
    }

    public function searchNavigation($viewerId, $term, array $matchingFarmers, array $allFarmers)
    {
        $directListings = $this->listListings($viewerId, 100, false, ['search' => $term]);
        $directFarms = $this->searchableFarms($term, [], []);

        $farmerIds = array_map('intval', array_column($matchingFarmers, 'id'));
        $farmIds = array_map('intval', array_column($directFarms, 'farm_id'));
        foreach ($directFarms as $farm) {
            $farmerIds[] = (int) $farm['farmer_id'];
        }
        foreach ($directListings as $listing) {
            if (!empty($listing['seller_id'])) $farmerIds[] = (int) $listing['seller_id'];
            if (!empty($listing['farm_id'])) $farmIds[] = (int) $listing['farm_id'];
        }
        $farmerIds = array_values(array_unique(array_filter($farmerIds)));
        $farmIds = array_values(array_unique(array_filter($farmIds)));

        $relatedListings = [];
        if ($farmerIds || $farmIds) {
            $relatedListings = $this->listListings($viewerId, 100, false, [
                'seller_ids' => $farmerIds,
                'farm_ids' => $farmIds
            ]);
        }

        $listingsById = [];
        foreach (array_merge($directListings, $relatedListings) as $listing) {
            $listingsById[(string) $listing['listing_id']] = $listing;
            if (!empty($listing['seller_id'])) $farmerIds[] = (int) $listing['seller_id'];
            if (!empty($listing['farm_id'])) $farmIds[] = (int) $listing['farm_id'];
        }
        $farmerIds = array_values(array_unique(array_filter($farmerIds)));
        $farmIds = array_values(array_unique(array_filter($farmIds)));

        $farmerIdSet = array_fill_keys($farmerIds, true);
        $farmers = array_values(array_filter($allFarmers, static function ($farmer) use ($farmerIdSet) {
            return isset($farmerIdSet[(int) $farmer['id']]);
        }));

        return [
            'products' => array_values($listingsById),
            'farmers' => $farmers,
            'farms' => $this->searchableFarms($term, $farmerIds, $farmIds)
        ];
    }

    private function searchableFarms($term, array $farmerIds, array $farmIds)
    {
        $conditions = [];
        $params = ['farmer'];
        if ($term !== '') {
            $conditions[] = '(f.farm_name LIKE ? OR f.farm_description LIKE ? OR f.municipality LIKE ? OR f.province LIKE ?
                OR EXISTS (
                    SELECT 1 FROM product_batches pb
                    INNER JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.farm_id = f.farm_id AND (p.product_name LIKE ? OR p.category LIKE ?)
                )
                OR EXISTS (
                    SELECT 1 FROM marketplace_listings l
                    WHERE l.farm_id = f.farm_id AND (l.product_name LIKE ? OR l.category LIKE ?)
                ))';
            $pattern = '%' . $term . '%';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        if ($farmerIds) {
            $conditions[] = 'f.farmer_id IN (' . implode(',', array_fill(0, count($farmerIds), '?')) . ')';
            array_push($params, ...$farmerIds);
        }
        if ($farmIds) {
            $conditions[] = 'f.farm_id IN (' . implode(',', array_fill(0, count($farmIds), '?')) . ')';
            array_push($params, ...$farmIds);
        }
        if (!$conditions) return [];

        return $this->db->raw(
            'SELECT f.farm_id, f.farmer_id, f.farm_name, f.farm_description, f.municipality, f.province,
                    u.first_name, u.middle_name, u.last_name, u.username
             FROM farms f
             INNER JOIN users u ON u.id = f.farmer_id
             WHERE u.role = ? AND u.is_active = 1 AND (' . implode(' OR ', $conditions) . ')
             ORDER BY f.farm_id DESC LIMIT 100',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function productDraftForFarmer($listingId, $farmerId)
    {
        return $this->db->raw(
            'SELECT l.listing_id, l.seller_id, l.product_name, l.category, l.description,
                    l.image_url, l.catalog_id, l.farm_id, f.farm_name
             FROM marketplace_listings l
             INNER JOIN farms f ON f.farm_id = l.farm_id
             WHERE l.listing_id = ? AND l.seller_id = ? AND f.farmer_id = ?
               AND l.batch_id IS NULL AND l.catalog_id IS NOT NULL
             LIMIT 1',
            [(int) $listingId, (int) $farmerId, (int) $farmerId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createListing($sellerId, array $input, $approved)
    {
        return (int) $this->db->table('marketplace_listings')->insert([
            'seller_id' => $sellerId, 'product_name' => $input['product_name'], 'category' => $input['category'],
            'description' => $input['description'] ?? null, 'price' => $input['price'], 'stock' => $input['stock'],
            'unit' => $input['unit'], 'location' => $input['location'] ?? null,
            'image_url' => $input['image_url'] ?? null, 'is_available' => $approved ? 1 : 0,
            'catalog_id' => $input['catalog_id'] ?? null, 'farm_id' => $input['farm_id'] ?? null,
            'batch_id' => $input['batch_id'] ?? null,
            'selling_unit' => $input['selling_unit'] ?? $input['unit']
        ]);
    }

    public function updateListing($listingId, array $input)
    {
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->update([
            'product_name' => $input['product_name'], 'category' => $input['category'],
            'description' => $input['description'] ?? null, 'price' => $input['price'],
            'stock' => $input['stock'], 'unit' => $input['unit'],
            'location' => $input['location'] ?? null,
            'farm_id' => $input['farm_id'] ?? null,
            'batch_id' => $input['batch_id'] ?? null,
            'selling_unit' => $input['selling_unit'] ?? $input['unit']
        ]);
    }

    public function listing_owner($listingId)
    {
        return $this->db->table('marketplace_listings')
            ->select('seller_id, is_available, image_url, farm_id, batch_id, catalog_id')
            ->where('listing_id', $listingId)->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function updateProductDetails($listingId, $sellerId, array $input)
    {
        $listing = $this->listing_owner($listingId);
        if (!$listing || (int) $listing['seller_id'] !== (int) $sellerId) {
            return false;
        }
        $updates = [
            'product_name' => $input['product_name'],
            'category' => $input['category'],
            'description' => $input['description'],
            'image_url' => $input['image_url'] !== '' ? $input['image_url'] : null
        ];
        $query = $this->db->table('marketplace_listings')->where('seller_id', (int) $sellerId);
        if (!empty($listing['catalog_id'])) {
            $query->where('catalog_id', (int) $listing['catalog_id']);
        } else {
            $query->where('listing_id', (int) $listingId);
        }
        if (!empty($listing['farm_id'])) {
            $query->where('farm_id', (int) $listing['farm_id']);
        }
        $query->update($updates);

        $siblings = $this->db->table('marketplace_listings')
            ->select('listing_id')
            ->where('seller_id', (int) $sellerId);
        if (!empty($listing['catalog_id'])) {
            $siblings->where('catalog_id', (int) $listing['catalog_id']);
        } else {
            $siblings->where('listing_id', (int) $listingId);
        }
        if (!empty($listing['farm_id'])) {
            $siblings->where('farm_id', (int) $listing['farm_id']);
        }
        $siblings = $siblings->get_all(PDO::FETCH_ASSOC);
        $mediaUrl = $input['image_url'] === ''
            ? ''
            : json_encode([['url' => $input['image_url'], 'type' => 'image']]);
        foreach ($siblings as $sibling) {
            $this->db->table('community_posts')
                ->where(['listing_id' => (int) $sibling['listing_id'], 'author_id' => (int) $sellerId, 'post_type' => 'product'])
                ->update(['content' => $input['product_name'] . ' is now available in the AgriTrace Marketplace.']);
            $this->update_listing_image((int) $sibling['listing_id'], $mediaUrl);
        }
        return true;
    }

    public function farmBelongsToFarmer($farmId, $farmerId)
    {
        return (bool) $this->db->table('farms')
            ->select('farm_id')
            ->where('farm_id', (int) $farmId)
            ->where('farmer_id', (int) $farmerId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function batchForFarmer($batchId, $farmerId)
    {
        return $this->db->raw(
            'SELECT pb.batch_id, pb.product_id, pb.farm_id, pb.unit,
                    COALESCE(pb.available_quantity, pb.quantity) AS stock
             FROM product_batches pb
             INNER JOIN farms f ON f.farm_id = pb.farm_id
             WHERE pb.batch_id = ? AND f.farmer_id = ? LIMIT 1',
            [(int) $batchId, (int) $farmerId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateBatchPrice($batchId, $price)
    {
        return $this->db->table('product_batches')
            ->where('batch_id', (int) $batchId)
            ->update(['unit_price' => $price]);
    }

    public function set_listing_available($listingId, $available)
    {
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->update(['is_available' => $available ? 1 : 0]);
    }

    public function decrement_stock($listingId, $quantity)
    {
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->decrement('stock', $quantity);
    }

    /** Posts visible to signed-in viewers. */
    public function listPosts($viewerId, $limit = 100, $authorId = null)
    {
        $limit = max(1, min((int) $limit, 100));
        $query = $this->db->table('community_posts')
            ->select('community_posts.post_id, community_posts.author_id, community_posts.content, community_posts.post_type, community_posts.listing_id, community_posts.media_url, community_posts.media_type, community_posts.status, community_posts.created_at, u.username, u.first_name, u.last_name, u.profile_pic, u.role, u.is_verified, l.product_name, l.category, l.price, l.stock, l.unit, l.location, l.image_url, l.is_available, l.batch_id, pb.batch_code, pb.current_status AS batch_status, pb.available_quantity AS batch_stock, pb.quantity AS batch_quantity, pb.expiration_date, pb.unit_price AS batch_price')
            ->inner_join('users u', 'u.id = community_posts.author_id')
            ->left_join('marketplace_listings l', 'l.listing_id = community_posts.listing_id')
            ->left_join('product_batches pb', 'pb.batch_id = l.batch_id')
            ->where('community_posts.status', 'approved');
        if ($authorId !== null) {
            $query->where('community_posts.author_id', (int) $authorId);
        }
        $posts = $query->order_by('community_posts.post_id', 'DESC')->limit($limit)->get_all(PDO::FETCH_ASSOC);
        $posts = array_values(array_filter($posts, static function ($post) use ($viewerId) {
            if ($post['post_type'] !== 'product' || (int) $post['author_id'] === (int) $viewerId) {
                return true;
            }
            if ($post['batch_status'] === 'rejected') {
                return false;
            }
            return empty($post['expiration_date']) || $post['expiration_date'] >= date('Y-m-d');
        }));

        if (!$posts) {
            return $posts;
        }
        foreach ($posts as &$post) {
            if ($post['post_type'] === 'product') {
                if ($post['batch_price'] !== null) {
                    $post['price'] = $post['batch_price'];
                }
                if ($post['batch_id'] !== null) {
                    $post['stock'] = $post['batch_stock'] !== null
                        ? $post['batch_stock']
                        : ($post['batch_quantity'] ?? $post['stock']);
                }
                $post['batch_expired'] = !empty($post['expiration_date'])
                    && $post['expiration_date'] < date('Y-m-d');
                if (in_array($post['batch_status'], ['sold', 'rejected'], true)) {
                    $post['stock'] = 0;
                }
            }
            unset($post['batch_stock'], $post['batch_quantity'], $post['batch_price'], $post['expiration_date'], $post['batch_status']);
        }
        unset($post);

        $postIds = array_map('intval', array_column($posts, 'post_id'));
        $likes = $this->db->table('post_likes')->select('post_id')
            ->select_count('post_id', 'total')
            ->grouped(function ($db) use ($postIds) {
                foreach ($postIds as $index => $postId) {
                    $index === 0 ? $db->where('post_id', $postId) : $db->or_where('post_id', $postId);
                }
            })->group_by('post_id')->get_all(PDO::FETCH_ASSOC);
        $likedByMe = $this->db->table('post_likes')->select('post_id')
            ->where('user_id', $viewerId)
            ->grouped(function ($db) use ($postIds) {
                foreach ($postIds as $index => $postId) {
                    $index === 0 ? $db->where('post_id', $postId) : $db->or_where('post_id', $postId);
                }
            })->get_all(PDO::FETCH_COLUMN);
        $commentCounts = $this->db->table('post_comments')->select('post_id')
            ->select_count('post_id', 'total')
            ->grouped(function ($db) use ($postIds) {
                foreach ($postIds as $index => $postId) {
                    $index === 0 ? $db->where('post_id', $postId) : $db->or_where('post_id', $postId);
                }
            })->group_by('post_id')->get_all(PDO::FETCH_ASSOC);

        $likesByPost = [];
        foreach ($likes as $row) {
            $likesByPost[(int) $row['post_id']] = (int) $row['total'];
        }
        $likedPostIds = array_fill_keys(array_map('intval', $likedByMe), true);
        $commentCountsByPost = [];
        foreach ($commentCounts as $row) {
            $commentCountsByPost[(int) $row['post_id']] = (int) $row['total'];
        }

        $commentsByPost = [];
        foreach ($postIds as $postId) {
            $rows = $this->db->table('post_comments')
                ->select('post_comments.post_id, post_comments.comment_id, post_comments.author_id, post_comments.content, post_comments.created_at, users.username, users.first_name, users.last_name, users.profile_pic')
                ->inner_join('users', 'users.id = post_comments.author_id')
                ->where('post_comments.post_id', $postId)
                ->order_by('post_comments.comment_id', 'ASC')
                ->limit(100)
                ->get_all(PDO::FETCH_ASSOC);
            foreach ($rows as $comment) {
                $commentPostId = (int) $comment['post_id'];
                unset($comment['post_id']);
                $commentsByPost[$commentPostId] = $commentsByPost[$commentPostId] ?? [];
                $commentsByPost[$commentPostId][] = $comment;
            }
        }

        foreach ($posts as &$post) {
            $postId = (int) $post['post_id'];
            $post['likes'] = $likesByPost[$postId] ?? 0;
            $post['liked_by_me'] = isset($likedPostIds[$postId]);
            $post['comment_count'] = $commentCountsByPost[$postId] ?? 0;
            $post['comments'] = $commentsByPost[$postId] ?? [];
        }
        unset($post);
        $posts = $this->attach_listing_ratings($posts);
        return $posts;
    }

    public function listings_by_seller($sellerId, $limit = 100)
    {
        $rows = $this->db->table('marketplace_listings')
            ->select('marketplace_listings.listing_id, marketplace_listings.seller_id, marketplace_listings.product_name, marketplace_listings.category, marketplace_listings.description, marketplace_listings.price, marketplace_listings.stock, marketplace_listings.unit, marketplace_listings.location, marketplace_listings.image_url, marketplace_listings.is_available, marketplace_listings.created_at, u.username, f.farm_name, f.farm_description, f.municipality, f.province, f.latitude AS farm_latitude, f.longitude AS farm_longitude')
            ->inner_join('users u', 'u.id = marketplace_listings.seller_id')
            ->left_join('farms f', 'f.farm_id = marketplace_listings.farm_id')
            ->where('marketplace_listings.seller_id', $sellerId)
            ->order_by('marketplace_listings.listing_id', 'DESC')
            ->limit(max(1, min((int) $limit, 100)))
            ->get_all(PDO::FETCH_ASSOC);
        return $this->attach_listing_ratings($rows);
    }

    public function post_for_report($postId)
    {
        return $this->db->table('community_posts')
            ->select('community_posts.post_id, community_posts.author_id, community_posts.content, community_posts.post_type, community_posts.listing_id, community_posts.media_url, community_posts.media_type, community_posts.status, l.product_name, l.category, l.description AS product_description, l.price, l.unit, l.image_url AS product_image')
            ->left_join('marketplace_listings l', 'l.listing_id = community_posts.listing_id')
            ->where('community_posts.post_id', $postId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    private function attach_listing_ratings(array $rows)
    {
        $listingIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_column($rows, 'listing_id')
        ))));
        $ratings = [];
        if ($listingIds) {
            $ratingRows = $this->db->table('product_reviews')
                ->select('listing_id')
                ->select_avg('rating', 'average_rating')
                ->select_count('review_id', 'review_count')
                ->in('listing_id', $listingIds)
                ->group_by('listing_id')
                ->get_all(PDO::FETCH_ASSOC);
            foreach ($ratingRows as $rating) {
                $ratings[(int) $rating['listing_id']] = [
                    'average_rating' => (float) $rating['average_rating'],
                    'review_count' => (int) $rating['review_count']
                ];
            }
        }
        foreach ($rows as &$row) {
            $rating = $ratings[(int) ($row['listing_id'] ?? 0)] ?? [
                'average_rating' => 0.0,
                'review_count' => 0
            ];
            $row['average_rating'] = $rating['average_rating'];
            $row['review_count'] = $rating['review_count'];
        }
        unset($row);
        return $rows;
    }

    public function comments_for($postId)
    {
        return $this->db->table('post_comments')
            ->select('post_comments.comment_id, post_comments.author_id, post_comments.content, post_comments.created_at, u.username, u.first_name, u.last_name, u.profile_pic')
            ->inner_join('users u', 'u.id = post_comments.author_id')->where('post_comments.post_id', $postId)
            ->order_by('post_comments.comment_id', 'ASC')->limit(100)->get_all(PDO::FETCH_ASSOC);
    }

    public function createPost($authorId, array $input, $status)
    {
        return (int) $this->db->table('community_posts')->insert([
            'author_id' => $authorId, 'content' => $input['content'], 'post_type' => $input['post_type'] ?? 'text',
            'listing_id' => $input['listing_id'] ?? null, 'media_url' => $input['media_url'] ?? null,
            'media_type' => $input['media_type'] ?? null, 'status' => $status
        ]);
    }

    public function post_owner($postId)
    {
        return $this->db->table('community_posts')->select('post_id, author_id, status, listing_id, post_type')->where('post_id', $postId)->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function update_post($postId, array $updates)
    {
        $this->db->table('community_posts')->where('post_id', $postId)->update($updates);
    }

    public function update_listing_image($listingId, $mediaUrl)
    {
        $imageUrl = null;
        if ($mediaUrl !== null && $mediaUrl !== '') {
            $media = json_decode($mediaUrl, true);
            if (is_array($media) && isset($media[0]) && is_array($media[0])) {
                foreach ($media as $item) {
                    if (!empty($item['url']) && ($item['type'] ?? 'image') === 'image') {
                        $imageUrl = $item['url'];
                        break;
                    }
                }
            } else {
                $imageUrl = $mediaUrl;
            }
        }
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->update(['image_url' => $imageUrl]);
        $this->db->table('community_posts')->where(['listing_id' => $listingId, 'post_type' => 'product'])->update([
            'media_url' => $mediaUrl === null || $mediaUrl === '' ? null : $mediaUrl,
            'media_type' => $mediaUrl === null || $mediaUrl === '' ? null : 'image'
        ]);
    }

    public function delete_post($postId)
    {
        $this->db->table('community_posts')->where('post_id', $postId)->delete();
    }

    /** Toggles the viewer's like on a post. Returns [liked, total likes]. */
    public function toggle_like($postId, $userId)
    {
        $liked = (bool) $this->db->table('post_likes')->where(['post_id' => $postId, 'user_id' => $userId])->limit(1)->get();
        if ($liked) {
            $this->db->table('post_likes')->where(['post_id' => $postId, 'user_id' => $userId])->delete();
        } else {
            $this->db->table('post_likes')->insert(['post_id' => $postId, 'user_id' => $userId]);
        }
        $total = (int) $this->db->table('post_likes')->where('post_id', $postId)->count();
        return [!$liked, $total];
    }

    public function add_comment($postId, $authorId, $content)
    {
        $id = (int) $this->db->table('post_comments')->insert(['post_id' => $postId, 'author_id' => $authorId, 'content' => $content]);
        return $this->db->table('post_comments')
            ->select('post_comments.comment_id, post_comments.author_id, post_comments.content, post_comments.created_at, u.username, u.first_name, u.last_name')
            ->inner_join('users u', 'u.id = post_comments.author_id')->where('post_comments.comment_id', $id)->limit(1)->get(PDO::FETCH_ASSOC);
    }

}
