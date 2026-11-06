<?php

/**
 * Stores PayMongo payment links without overwriting legacy Xendit references.
 */
class paymongo_payment_links
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column('orders', 'paymongo_payment_link_id', 'VARCHAR(100) NULL');
        $this->add_column('orders', 'paymongo_payment_url', 'VARCHAR(500) NULL');
    }

    public function down()
    {
        // Keep payment references intact during rollbacks.
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
