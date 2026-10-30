<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Community_model extends Model
{
    /** Marketplace listings: approved and in stock, or the caller's own listings with $mine. */
    public function listListings($viewerId, $limit = 100, $mine = false)
    {
        $limit = max(1, min((int) $limit, 100));
        // The public marketplace shows approved, in-stock listings only; sellers see their own
        // listings (including pending ones) through ?mine=1.
        $query = $this->db->table('marketplace_listings')
            ->select('marketplace_listings.listing_id, marketplace_listings.seller_id, marketplace_listings.product_name, marketplace_listings.category, marketplace_listings.description, marketplace_listings.price, marketplace_listings.stock, marketplace_listings.unit, marketplace_listings.location, marketplace_listings.image_url, marketplace_listings.is_available, marketplace_listings.created_at, u.username')
            ->inner_join('users u', 'u.id = marketplace_listings.seller_id');
        if ($mine) {
            $query->where('marketplace_listings.seller_id', $viewerId);
        } else {
            $query->where('marketplace_listings.is_available', 1)
                ->where('marketplace_listings.stock', '>', 0);
        }
        $listings = $query->order_by('marketplace_listings.listing_id', 'DESC')->limit($limit)->get_all(PDO::FETCH_ASSOC);
        return $this->attach_listing_ratings($listings);
    }

    public function createListing($sellerId, array $input, $approved)
    {
        return (int) $this->db->table('marketplace_listings')->insert([
            'seller_id' => $sellerId, 'product_name' => $input['product_name'], 'category' => $input['category'],
            'description' => $input['description'] ?? null, 'price' => $input['price'], 'stock' => $input['stock'],
            'unit' => $input['unit'], 'location' => $input['location'] ?? null,
            'image_url' => $input['image_url'] ?? null, 'is_available' => $approved ? 1 : 0
        ]);
    }

    public function listing_owner($listingId)
    {
        return $this->db->table('marketplace_listings')->select('seller_id, is_available')->where('listing_id', $listingId)->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function set_listing_available($listingId, $available)
    {
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->update(['is_available' => $available ? 1 : 0]);
    }

    public function decrement_stock($listingId, $quantity)
    {
        $this->db->table('marketplace_listings')->where('listing_id', $listingId)->decrement('stock', $quantity);
    }

    /** Posts visible to a viewer: approved posts plus the viewer's own pending posts. */
    public function listPosts($viewerId, $limit = 100)
    {
        $limit = max(1, min((int) $limit, 100));
        $posts = $this->db->table('community_posts')
            ->select('community_posts.post_id, community_posts.author_id, community_posts.content, community_posts.post_type, community_posts.listing_id, community_posts.media_url, community_posts.media_type, community_posts.status, community_posts.created_at, u.username, u.first_name, u.last_name, u.profile_pic, u.role, u.is_verified, l.product_name, l.category, l.price, l.stock, l.unit, l.location, l.image_url, l.is_available')
            ->inner_join('users u', 'u.id = community_posts.author_id')
            ->left_join('marketplace_listings l', 'l.listing_id = community_posts.listing_id')
            ->grouped(function ($db) use ($viewerId) {
                $db->where('community_posts.status', 'approved')->or_where('community_posts.author_id', $viewerId);
            })->order_by('community_posts.post_id', 'DESC')->limit($limit)->get_all(PDO::FETCH_ASSOC);

        if (!$posts) {
            return $posts;
        }

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
        foreach (array_chunk($postIds, 25) as $chunk) {
            $branches = [];
            $params = [];
            foreach ($chunk as $postId) {
                $branches[] = '(SELECT c.post_id, c.comment_id, c.author_id, c.content, c.created_at, u.username, u.first_name, u.last_name, u.profile_pic
                    FROM post_comments c
                    INNER JOIN users u ON u.id = c.author_id
                    WHERE c.post_id = ?
                    ORDER BY c.comment_id ASC
                    LIMIT 100)';
                $params[] = $postId;
            }
            $rows = $this->db->raw(
                'SELECT post_id, comment_id, author_id, content, created_at, username, first_name, last_name, profile_pic
                 FROM (' . implode(' UNION ALL ', $branches) . ')
                 AS post_comments ORDER BY post_id ASC, comment_id ASC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $comment) {
                $postId = (int) $comment['post_id'];
                unset($comment['post_id']);
                $commentsByPost[$postId] = $commentsByPost[$postId] ?? [];
                $commentsByPost[$postId][] = $comment;
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

    private function attach_listing_ratings(array $rows)
    {
        $listingIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_column($rows, 'listing_id')
        ))));
        $ratings = [];
        if ($listingIds) {
            $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
            $ratingRows = $this->db->raw(
                'SELECT listing_id, AVG(rating) AS average_rating, COUNT(*) AS review_count
                 FROM product_reviews WHERE listing_id IN (' . $placeholders . ')
                 GROUP BY listing_id',
                $listingIds
            )->fetchAll(PDO::FETCH_ASSOC);
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

    public function set_post_status($postId, $status)
    {
        $this->db->table('community_posts')->where('post_id', $postId)->update(['status' => $status]);
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

    public function pending_posts()
    {
        return $this->db->table('community_posts')
            ->select('community_posts.post_id, community_posts.content, community_posts.post_type, community_posts.created_at, community_posts.listing_id, u.username AS author, l.product_name, l.category, l.price, l.unit')
            ->inner_join('users u', 'u.id = community_posts.author_id')->left_join('marketplace_listings l', 'l.listing_id = community_posts.listing_id')
            ->where('community_posts.status', 'pending')->order_by('community_posts.post_id', 'DESC')->limit(100)->get_all(PDO::FETCH_ASSOC);
    }
}
