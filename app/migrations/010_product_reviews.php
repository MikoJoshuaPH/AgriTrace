<?php

/**
 * Product ratings and reviews, linked to a purchased order item.
 */
class product_reviews
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS product_reviews (
            review_id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            order_item_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            review TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_product_review_user (listing_id, user_id),
            INDEX idx_product_reviews_listing (listing_id, created_at),
            CONSTRAINT fk_product_review_listing FOREIGN KEY (listing_id)
                REFERENCES marketplace_listings(listing_id) ON DELETE CASCADE,
            CONSTRAINT fk_product_review_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_review_order_item FOREIGN KEY (order_item_id)
                REFERENCES order_items(item_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS product_reviews');
    }
}
