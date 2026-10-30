<?php

/**
 * Indexes supporting the dashboard's high-volume list and join queries.
 */
class Dashboard_query_indexes
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_index_if_missing('marketplace_listings', 'idx_listing_availability_stock_id', 'is_available, stock, listing_id');
        $this->add_index_if_missing('community_posts', 'idx_posts_status_id', 'status, post_id');
        $this->add_index_if_missing('post_comments', 'idx_comments_post_id', 'post_id, comment_id');
        $this->add_index_if_missing('order_items', 'idx_order_items_order_id', 'order_id, item_id');
    }

    public function down()
    {
        $this->drop_index_if_exists('marketplace_listings', 'idx_listing_availability_stock_id');
        $this->drop_index_if_exists('community_posts', 'idx_posts_status_id');
        $this->drop_index_if_exists('post_comments', 'idx_comments_post_id');
        $this->drop_index_if_exists('order_items', 'idx_order_items_order_id');
    }

    private function index_exists($table, $index)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->fetchColumn() > 0;
    }

    private function add_index_if_missing($table, $index, $columns)
    {
        if (!$this->index_exists($table, $index)) {
            $this->_lava->db->raw("CREATE INDEX {$index} ON {$table} ({$columns})");
        }
    }

    private function drop_index_if_exists($table, $index)
    {
        if ($this->index_exists($table, $index)) {
            $this->_lava->db->raw("DROP INDEX {$index} ON {$table}");
        }
    }
}
