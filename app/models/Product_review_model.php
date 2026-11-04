<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Product_review_model extends Model
{
    public function for_listing($listingId, $userId)
    {
        $reviews = $this->db->raw(
            'SELECT r.review_id, r.user_id, r.rating, r.review, r.created_at,
                    u.username, u.first_name, u.last_name
             FROM product_reviews r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.listing_id = ?
             ORDER BY r.created_at DESC, r.review_id DESC
             LIMIT 100',
            [$listingId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $summary = $this->db->table('product_reviews')
            ->select_count('review_id', 'review_count')
            ->select_avg('rating', 'average_rating')
            ->where('listing_id', $listingId)
            ->get(PDO::FETCH_ASSOC);
        $ownReview = $this->db->table('product_reviews')
            ->select('review_id, rating, review')
            ->where('listing_id', $listingId)
            ->where('user_id', $userId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        $purchase = $this->eligible_order_item($listingId, $userId);
        $comments = $this->comments_for_listing($listingId);
        foreach ($reviews as &$review) {
            $review['comments'] = $comments[(int) $review['review_id']] ?? [];
        }
        unset($review);

        return [
            'reviews' => $reviews,
            'review_count' => (int) $summary['review_count'],
            'average_rating' => round((float) $summary['average_rating'], 1),
            'can_review' => (bool) $purchase,
            'own_review' => $ownReview ?: null,
        ];
    }

    public function eligible_order_item($listingId, $userId)
    {
        return $this->db->raw(
            'SELECT oi.item_id
             FROM order_items oi
             INNER JOIN orders o ON o.order_id = oi.order_id
             INNER JOIN marketplace_listings l ON l.listing_id = oi.listing_id
             WHERE oi.listing_id = ? AND o.buyer_id = ? AND l.seller_id <> o.buyer_id
               AND o.payment_status = ? AND o.status <> ?
             ORDER BY o.paid_at DESC, oi.item_id DESC
             LIMIT 1',
            [$listingId, $userId, 'paid', 'cancelled']
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function save_review($listingId, $userId, $orderItemId, $rating, $review)
    {
        $this->db->raw(
            'INSERT INTO product_reviews (listing_id, user_id, order_item_id, rating, review)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE order_item_id = VALUES(order_item_id),
                 rating = VALUES(rating), review = VALUES(review), updated_at = CURRENT_TIMESTAMP',
            [$listingId, $userId, $orderItemId, $rating, $review]
        );
        $saved = $this->db->table('product_reviews')
            ->select('review_id')
            ->where('listing_id', $listingId)
            ->where('user_id', $userId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        return (int) $saved['review_id'];
    }

    public function review_exists($listingId, $reviewId)
    {
        return (bool) $this->db->table('product_reviews')
            ->select('review_id')
            ->where('listing_id', $listingId)
            ->where('review_id', $reviewId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function add_comment($reviewId, $userId, $comment)
    {
        return (int) $this->db->table('product_review_comments')->insert([
            'review_id' => $reviewId,
            'user_id' => $userId,
            'body' => $comment,
        ]);
    }

    private function comments_for_listing($listingId)
    {
        $rows = $this->db->table('product_review_comments')
            ->select('product_review_comments.review_id, product_review_comments.comment_id, product_review_comments.body AS comment, product_review_comments.created_at, users.username, users.first_name, users.last_name')
            ->inner_join('product_reviews', 'product_reviews.review_id = product_review_comments.review_id')
            ->inner_join('users', 'users.id = product_review_comments.user_id')
            ->where('product_reviews.listing_id', $listingId)
            ->order_by('product_review_comments.comment_id', 'ASC')
            ->limit(500)
            ->get_all(PDO::FETCH_ASSOC);
        $comments = [];
        foreach ($rows as $row) {
            $reviewId = (int) $row['review_id'];
            unset($row['review_id']);
            $comments[$reviewId][] = $row;
        }
        return $comments;
    }
}
