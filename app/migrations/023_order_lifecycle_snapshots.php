<?php

/**
 * Stores order status history, immutable batch details, and shipping tracking.
 */
class order_lifecycle_snapshots
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
        $this->add_column('product_batches', 'expiration_date', 'DATE NULL');
        $this->add_column('orders', 'tracking_number', 'VARCHAR(120) NULL');
        $this->add_column('orders', 'seller_id', 'INT NULL');
        $this->add_column('orders', 'checkout_key', 'VARCHAR(64) NULL');
        $this->add_column('order_items', 'batch_id', 'INT NULL');
        $this->add_column('order_items', 'batch_code', 'VARCHAR(50) NULL');
        $this->add_column('order_items', 'farm_id', 'INT NULL');
        $this->add_column('order_items', 'farm_name', 'VARCHAR(150) NULL');
        $this->add_column('order_items', 'harvest_date', 'DATE NULL');
        $this->add_column('order_items', 'expiration_date', 'DATE NULL');
        $this->add_column('order_items', 'selling_unit', 'VARCHAR(30) NULL');
        $this->add_column('order_items', 'batch_unit', 'VARCHAR(30) NULL');
        $this->add_column('order_items', 'cart_added_at', 'DATETIME NULL');
        if (!$this->index_exists('orders', 'uq_order_checkout_seller')) {
            $db->raw('ALTER TABLE orders ADD UNIQUE INDEX uq_order_checkout_seller (buyer_id, checkout_key, seller_id)');
        }
        $db->raw("ALTER TABLE orders MODIFY status ENUM(
            'pending','awaiting_farmer_acceptance','awaiting_payment','confirmed','accepted','preparing',
            'ready_for_shipment','shipped','in_transit','out_for_delivery','delivered','completed','rejected','cancelled'
        ) NOT NULL DEFAULT 'pending'");
        $db->raw("CREATE TABLE IF NOT EXISTS order_status_history (
            history_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL,
            payment_status VARCHAR(30) NOT NULL,
            note VARCHAR(255) NULL,
            changed_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_history (order_id, history_id),
            CONSTRAINT fk_order_history_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            CONSTRAINT fk_order_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        // Order history and snapshots are intentionally preserved on rollback.
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

    private function index_exists($table, $index)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->fetchColumn() > 0;
    }
}
