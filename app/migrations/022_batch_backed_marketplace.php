<?php

/**
 * Links marketplace listings to the batch that supplies their inventory.
 */
class batch_backed_marketplace
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        if (!$this->column_exists('marketplace_listings', 'batch_id')) {
            $this->_lava->db->raw('ALTER TABLE marketplace_listings ADD COLUMN batch_id INT NULL AFTER farm_id');
        }
        if (!$this->index_exists('marketplace_listings', 'idx_listing_batch')) {
            $this->_lava->db->raw('ALTER TABLE marketplace_listings ADD INDEX idx_listing_batch (batch_id)');
        }
    }

    public function down()
    {
        // Existing listing data is intentionally preserved on rollback.
    }

    private function column_exists($table, $column)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
    }

    private function index_exists($table, $index)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->fetchColumn() > 0;
    }
}
