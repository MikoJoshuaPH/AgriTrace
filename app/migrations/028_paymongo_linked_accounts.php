<?php

/**
 * Stores farmer PayMongo account onboarding and linked Checkout Session references.
 */
class paymongo_linked_accounts
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column('users', 'paymongo_account_id', 'VARCHAR(100) NULL');
        $this->add_column('users', 'paymongo_account_status', "VARCHAR(30) NOT NULL DEFAULT 'not_started'");
        $this->add_column('users', 'paymongo_identity_status', "VARCHAR(30) NOT NULL DEFAULT 'not_started'");
        $this->add_column('orders', 'paymongo_checkout_session_id', 'VARCHAR(100) NULL');
        $this->add_column('orders', 'paymongo_checkout_url', 'VARCHAR(500) NULL');
        $this->add_unique_index('users', 'uq_users_paymongo_account_id', 'paymongo_account_id');
    }

    public function down()
    {
        // Keep account and payment references intact during rollbacks.
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

    private function add_unique_index($table, $index, $column)
    {
        $exists = (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->fetchColumn() > 0;
        if (!$exists) {
            $this->_lava->db->raw("CREATE UNIQUE INDEX {$index} ON {$table} ({$column})");
        }
    }
}
