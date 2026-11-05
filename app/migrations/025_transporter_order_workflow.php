<?php

/**
 * Adds transporter profiles and order-linked shipping assignments.
 */
class transporter_order_workflow
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
        $this->add_column('users', 'transport_origin', 'VARCHAR(255) NULL');
        $this->add_column('users', 'vehicle_number', 'VARCHAR(50) NULL');
        $this->add_column('users', 'vehicle_type', 'VARCHAR(80) NULL');
        $this->add_column('shipments', 'order_id', 'INT UNSIGNED NULL');
        if (!$this->index_exists('shipments', 'idx_shipments_order')) {
            $db->raw('ALTER TABLE shipments ADD INDEX idx_shipments_order (order_id)');
        }

        $db->raw("CREATE TABLE IF NOT EXISTS order_shipments (
            assignment_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            farmer_id INT NOT NULL,
            transporter_id INT NOT NULL,
            status ENUM('offered','accepted','rejected','in_transit','out_for_delivery','delivered','cancelled')
                NOT NULL DEFAULT 'offered',
            origin VARCHAR(255) NOT NULL,
            destination VARCHAR(255) NOT NULL,
            vehicle_number VARCHAR(50) NULL,
            remarks VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME NULL,
            completed_at DATETIME NULL,
            INDEX idx_order_shipments_order (order_id, status),
            INDEX idx_order_shipments_transporter (transporter_id, status),
            CONSTRAINT fk_order_shipment_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            CONSTRAINT fk_order_shipment_farmer FOREIGN KEY (farmer_id) REFERENCES users(id),
            CONSTRAINT fk_order_shipment_transporter FOREIGN KEY (transporter_id) REFERENCES users(id)
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        // Shipping records are retained to preserve the traceability history.
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
