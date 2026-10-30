<?php

/**
 * Payments (Xendit), product trace codes with QR images, email two-factor sign-in,
 * password reset, map coordinates for users, and cloud database backup records.
 * Every statement is idempotent.
 */
class Payments_trace_security
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

        // Map location for each account (set on the profile page, shown on the dashboard map)
        $this->add_column('users', 'latitude', 'DECIMAL(9,6) NULL');
        $this->add_column('users', 'longitude', 'DECIMAL(9,6) NULL');
        $this->add_column('users', 'location_label', 'VARCHAR(255) NULL');

        // Email two-factor sign-in and password reset
        $this->add_column('users', 'two_factor_code_hash', 'VARCHAR(64) NULL');
        $this->add_column('users', 'two_factor_expires_at', 'DATETIME NULL');
        $this->add_column('users', 'two_factor_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        $this->add_column('users', 'password_reset_hash', 'VARCHAR(64) NULL');
        $this->add_column('users', 'password_reset_expires_at', 'DATETIME NULL');

        // Payment state on each order (order.status keeps the fulfilment state)
        $this->add_column('orders', 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
        $this->add_column('orders', 'xendit_invoice_id', 'VARCHAR(100) NULL');
        $this->add_column('orders', 'xendit_invoice_url', 'VARCHAR(500) NULL');
        $this->add_column('orders', 'payment_external_id', 'VARCHAR(100) NULL');
        $this->add_column('orders', 'paid_at', 'DATETIME NULL');

        $db->raw("CREATE TABLE IF NOT EXISTS trace_codes (
            trace_id INT AUTO_INCREMENT PRIMARY KEY,
            trace_code VARCHAR(40) NOT NULL UNIQUE,
            order_id INT UNSIGNED NOT NULL,
            order_item_id INT UNSIGNED NOT NULL UNIQUE,
            listing_id INT UNSIGNED NULL,
            buyer_id INT NOT NULL,
            seller_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            category VARCHAR(50) NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            qr_url VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_trace_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            CONSTRAINT fk_trace_item FOREIGN KEY (order_item_id) REFERENCES order_items(item_id) ON DELETE CASCADE,
            CONSTRAINT fk_trace_buyer FOREIGN KEY (buyer_id) REFERENCES users(id),
            CONSTRAINT fk_trace_seller FOREIGN KEY (seller_id) REFERENCES users(id)
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS database_backups (
            backup_id INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(255) NOT NULL,
            file_url VARCHAR(500) NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            storage_driver VARCHAR(20) NOT NULL DEFAULT 'local',
            status ENUM('completed','failed') NOT NULL,
            error_message VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        $db = $this->_lava->db;
        $db->raw('DROP TABLE IF EXISTS database_backups');
        $db->raw('DROP TABLE IF EXISTS trace_codes');
        foreach (['payment_status', 'xendit_invoice_id', 'xendit_invoice_url', 'payment_external_id', 'paid_at'] as $c) {
            $this->drop_column('orders', $c);
        }
        foreach (['latitude', 'longitude', 'location_label', 'two_factor_code_hash', 'two_factor_expires_at',
                  'two_factor_attempts', 'password_reset_hash', 'password_reset_expires_at'] as $c) {
            $this->drop_column('users', $c);
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

    private function add_column($table, $column, $definition)
    {
        if (!$this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function drop_column($table, $column)
    {
        if ($this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
}
