<?php

/**
 * Stores signed-in user complaints and comments on product reviews.
 */
class support_complaints_review_comments
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $db = $this->_lava->db;
        $db->raw("CREATE TABLE IF NOT EXISTS support_complaints (
            complaint_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            reporter_name VARCHAR(302) NOT NULL,
            reporter_email VARCHAR(254) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('open','resolved') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            INDEX idx_support_complaints_status (status, created_at),
            CONSTRAINT fk_support_complaint_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS product_review_comments (
            comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            review_id INT NOT NULL,
            user_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_review_comments_review (review_id, comment_id),
            CONSTRAINT fk_review_comment_review FOREIGN KEY (review_id)
                REFERENCES product_reviews(review_id) ON DELETE CASCADE,
            CONSTRAINT fk_review_comment_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS product_review_comments');
        $this->_lava->db->raw('DROP TABLE IF EXISTS support_complaints');
    }
}
