<?php

/**
 * Stores the buyer's pinned delivery location for order fulfillment.
 */
class order_delivery_coordinates
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column('orders', 'delivery_latitude', 'DECIMAL(10,7) NULL');
        $this->add_column('orders', 'delivery_longitude', 'DECIMAL(10,7) NULL');
    }

    public function down()
    {
        // Order delivery coordinates are retained to preserve fulfillment history.
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
