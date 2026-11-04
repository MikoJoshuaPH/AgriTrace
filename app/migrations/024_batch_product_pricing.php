<?php

/**
 * Stores the marketplace price and selling unit on each harvest batch.
 */
class batch_product_pricing
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column('product_batches', 'unit_price', 'DECIMAL(12,2) NULL');
        $this->add_column('product_batches', 'selling_unit', 'VARCHAR(30) NULL');
    }

    public function down()
    {
        // Existing batch and listing data is intentionally preserved on rollback.
    }

    private function add_column($table, $column, $definition)
    {
        $exists = (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
        if (!$exists) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
