<?php

/**
 * Adds the payment method recorded on each order. Both COD and Xendit
 * checkout use this column.
 */
class cod_checkout
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        if (!$this->column_exists('orders', 'payment_method')) {
            $this->_lava->db->raw("ALTER TABLE orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cod' AFTER payment_status");
        }
    }

    public function down()
    {
        if ($this->column_exists('orders', 'payment_method')) {
            $this->_lava->db->raw('ALTER TABLE orders DROP COLUMN payment_method');
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
