<?php

/**
 * Links paid order trace codes to product batches.
 */
class trace_batch_links
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        if (!$this->column_exists('trace_codes', 'batch_id')) {
            $this->_lava->db->raw(
                'ALTER TABLE trace_codes
                 ADD COLUMN batch_id INT NULL,
                 ADD INDEX idx_trace_codes_batch_id (batch_id),
                 ADD CONSTRAINT fk_trace_codes_batch
                    FOREIGN KEY (batch_id) REFERENCES product_batches(batch_id) ON DELETE SET NULL'
            );
        }
    }

    public function down()
    {
        if ($this->column_exists('trace_codes', 'batch_id')) {
            $this->_lava->db->raw(
                'ALTER TABLE trace_codes
                 DROP FOREIGN KEY fk_trace_codes_batch,
                 DROP INDEX idx_trace_codes_batch_id,
                 DROP COLUMN batch_id'
            );
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
