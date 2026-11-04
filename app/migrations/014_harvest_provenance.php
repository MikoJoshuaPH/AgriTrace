<?php

/**
 * Supports approval-gated provenance badges and cloud storage image URLs.
 */
class harvest_provenance
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        if (!$this->column_exists('harvest_records', 'approval_status')) {
            $this->_lava->db->raw(
                "ALTER TABLE harvest_records
                 ADD COLUMN approval_status ENUM('pending','approved','rejected')
                 NOT NULL DEFAULT 'pending' AFTER quality_grade"
            );
        }
        $this->_lava->db->raw('ALTER TABLE qr_codes MODIFY qr_image_path VARCHAR(500) NULL');
    }

    public function down()
    {
        if ($this->column_exists('harvest_records', 'approval_status')) {
            $this->_lava->db->raw('ALTER TABLE harvest_records DROP COLUMN approval_status');
        }
        $this->_lava->db->raw('ALTER TABLE qr_codes MODIFY qr_image_path VARCHAR(255) NULL');
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
