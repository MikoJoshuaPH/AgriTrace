<?php

/**
 * Publishes existing pending community content and stores post-report review details.
 */
class community_reports_and_public_posts
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
        foreach ([
            'post_id' => 'INT NULL',
            'post_content_snapshot' => 'TEXT NULL',
            'post_media_url_snapshot' => 'TEXT NULL',
            'post_media_type_snapshot' => 'VARCHAR(30) NULL',
            'post_product_snapshot' => 'TEXT NULL',
            'review_action' => "ENUM('removed','dismissed') NULL",
        ] as $column => $definition) {
            if (!$this->column_exists('reports', $column)) {
                $db->raw("ALTER TABLE reports ADD COLUMN {$column} {$definition}");
            }
        }

        $db->raw(
            "UPDATE marketplace_listings l
             INNER JOIN community_posts p ON p.listing_id = l.listing_id
             SET l.is_available = 1
             WHERE p.status = 'pending' AND p.post_type = 'product' AND l.is_available = 0"
        );
        $db->raw("UPDATE community_posts SET status = 'approved' WHERE status = 'pending'");
    }

    public function down()
    {
        foreach ([
            'review_action',
            'post_product_snapshot',
            'post_media_type_snapshot',
            'post_media_url_snapshot',
            'post_content_snapshot',
            'post_id',
        ] as $column) {
            if ($this->column_exists('reports', $column)) {
                $this->_lava->db->raw("ALTER TABLE reports DROP COLUMN {$column}");
            }
        }
    }

    private function column_exists($table, $column)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
    }
}
