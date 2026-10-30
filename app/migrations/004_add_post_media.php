<?php

/**
 * Adds media columns to community_posts. Idempotent: fresh installs from
 * DATABASE.sql already contain these columns, so they are only added when
 * missing.
 */
class Add_post_media
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column_if_missing('community_posts', 'media_url', 'VARCHAR(255) NULL');
        $this->add_column_if_missing('community_posts', 'media_type', 'VARCHAR(30) NULL');
    }

    public function down()
    {
        $this->drop_column_if_exists('community_posts', 'media_url');
        $this->drop_column_if_exists('community_posts', 'media_type');
    }

    private function column_exists($table, $column)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
    }

    private function add_column_if_missing($table, $column, $definition)
    {
        if (!$this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function drop_column_if_exists($table, $column)
    {
        if ($this->column_exists($table, $column)) {
            $this->_lava->db->raw("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
}
