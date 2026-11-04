<?php

/**
 * Completes the catalog, harvest and inventory workflow.
 * The migration is idempotent so existing installations can be upgraded safely.
 */
class catalog_harvest_inventory_workflow
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

        $this->add_column('products', 'normalized_name', 'VARCHAR(150) NULL');
        $this->add_column('harvest_records', 'harvest_reference', 'VARCHAR(80) NULL');
        $this->add_column('harvest_records', 'product_id', 'INT NULL');
        $this->add_column('harvest_records', 'unit_of_measure', 'VARCHAR(30) NULL');
        $this->add_column('harvest_records', 'description', 'TEXT NULL');
        $this->add_column('harvest_records', 'harvest_status', "ENUM('planned','harvested','available','depleted','cancelled') NOT NULL DEFAULT 'harvested'");
        $this->add_column('product_batches', 'harvest_reference', 'VARCHAR(80) NULL');
        $this->add_column('orders', 'delivery_address', 'TEXT NULL');
        $this->add_column('orders', 'requested_delivery_at', 'DATETIME NULL');
        $this->add_column('orders', 'transaction_reference', 'VARCHAR(120) NULL');

        $db->raw("UPDATE products SET normalized_name = LOWER(TRIM(REGEXP_REPLACE(product_name, '[[:space:]]+', ' ')))
                  WHERE normalized_name IS NULL");
        $db->raw("UPDATE harvest_records hr
                  INNER JOIN product_batches pb ON pb.batch_id = hr.batch_id
                  SET hr.product_id = pb.product_id, hr.unit_of_measure = pb.unit
                  WHERE hr.product_id IS NULL");
        $db->raw("UPDATE product_batches SET harvest_reference = batch_code WHERE harvest_reference IS NULL");

        $this->add_index_if_missing('products', 'uq_products_normalized_name', 'normalized_name', true);
        $this->add_index_if_missing('harvest_records', 'uq_harvest_reference', 'harvest_reference', true);
        $this->add_index_if_missing('harvest_records', 'idx_harvest_product_farmer', 'product_id, farmer_id');
    }

    public function down()
    {
        // Existing data is intentionally preserved on rollback.
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

    private function add_column($table, $column, $definition)
    {
        if (!$this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function add_index_if_missing($table, $index, $columns, $unique = false)
    {
        if (!$this->index_exists($table, $index)) {
            $kind = $unique ? 'UNIQUE ' : '';
            $this->_lava->db->raw("ALTER TABLE {$table} ADD {$kind}INDEX {$index} ({$columns})");
        }
    }
}
