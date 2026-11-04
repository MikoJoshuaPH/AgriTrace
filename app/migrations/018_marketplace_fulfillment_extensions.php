<?php

/**
 * Product catalog, inventory movement, fulfillment allocation and payout metadata.
 * All statements are safe to run more than once.
 */
class marketplace_fulfillment_extensions
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
        $this->add_column('marketplace_listings', 'catalog_id', 'INT NULL');
        $this->add_column('marketplace_listings', 'farm_id', 'INT NULL');
        $this->add_column('marketplace_listings', 'selling_unit', 'VARCHAR(30) NULL');
        $this->add_column('product_batches', 'available_quantity', 'DECIMAL(12,2) NULL');
        $this->add_column('orders', 'delivery_address', 'TEXT NULL');
        $this->add_column('orders', 'requested_delivery_at', 'DATETIME NULL');
        $this->add_column('orders', 'shipping_provider', 'VARCHAR(80) NULL');
        $this->add_column('orders', 'transaction_reference', 'VARCHAR(120) NULL');
        $this->add_column('order_items', 'catalog_id', 'INT NULL');
        $this->add_column('order_items', 'subtotal', 'DECIMAL(12,2) NULL');
        $db->raw("ALTER TABLE orders MODIFY status ENUM(
            'pending','awaiting_farmer_acceptance','confirmed','accepted','preparing',
            'ready_for_shipment','shipped','in_transit','delivered','completed','rejected','cancelled'
        ) NOT NULL DEFAULT 'pending'");

        $db->raw("CREATE TABLE IF NOT EXISTS product_catalog_aliases (
            alias_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            alias_name VARCHAR(150) NOT NULL,
            normalized_name VARCHAR(150) NOT NULL,
            language_code VARCHAR(12) NULL,
            UNIQUE KEY uq_catalog_alias (product_id, normalized_name),
            INDEX idx_catalog_alias_normalized (normalized_name),
            CONSTRAINT fk_catalog_alias_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS inventory_movements (
            movement_id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT NULL,
            batch_id INT NULL,
            order_id INT UNSIGNED NULL,
            movement_type ENUM('reserve','sale','release','adjustment') NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            reference VARCHAR(120) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_listing (listing_id, created_at),
            INDEX idx_inventory_batch (batch_id, created_at)
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS batch_allocations (
            allocation_id INT AUTO_INCREMENT PRIMARY KEY,
            order_item_id INT UNSIGNED NOT NULL,
            batch_id INT NOT NULL,
            harvest_id INT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            shipping_provider VARCHAR(80) NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_order_batch (order_item_id, batch_id),
            CONSTRAINT fk_allocation_item FOREIGN KEY (order_item_id) REFERENCES order_items(item_id) ON DELETE CASCADE,
            CONSTRAINT fk_allocation_batch FOREIGN KEY (batch_id) REFERENCES product_batches(batch_id),
            CONSTRAINT fk_allocation_user FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS farmer_payment_settings (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            farmer_id INT NOT NULL,
            payment_method VARCHAR(30) NOT NULL,
            account_name VARCHAR(150) NOT NULL,
            account_reference VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_farmer_payment_method (farmer_id, payment_method),
            CONSTRAINT fk_payment_setting_farmer FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS farmer_payouts (
            payout_id INT AUTO_INCREMENT PRIMARY KEY,
            farmer_id INT NOT NULL,
            order_id INT UNSIGNED NULL,
            amount DECIMAL(12,2) NOT NULL,
            platform_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','processing','paid','failed') NOT NULL DEFAULT 'pending',
            transaction_reference VARCHAR(120) NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payout_farmer_status (farmer_id, status),
            CONSTRAINT fk_payout_farmer FOREIGN KEY (farmer_id) REFERENCES users(id),
            CONSTRAINT fk_payout_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS generated_reports (
            report_id INT AUTO_INCREMENT PRIMARY KEY,
            report_type VARCHAR(40) NOT NULL,
            date_from DATE NULL,
            date_to DATE NULL,
            file_name VARCHAR(180) NULL,
            status ENUM('generated','failed') NOT NULL,
            error_message VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_report_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $db->raw("UPDATE product_batches SET available_quantity = quantity WHERE available_quantity IS NULL");
        $db->raw("UPDATE marketplace_listings SET selling_unit = unit WHERE selling_unit IS NULL");
    }

    public function down()
    {
        foreach (['generated_reports', 'farmer_payouts', 'farmer_payment_settings', 'batch_allocations', 'inventory_movements', 'product_catalog_aliases'] as $table) {
            $this->_lava->db->raw("DROP TABLE IF EXISTS {$table}");
        }
    }

    private function column_exists($table, $column)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
    }

    private function add_column($table, $column, $definition)
    {
        if (!$this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
