<?php

/**
 * Align users created by the original migrations with the public registration
 * fields and roles used by the current application.
 */
class registration_compatibility
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->add_column_if_missing('first_name', 'VARCHAR(100) NULL');
        $this->add_column_if_missing('middle_name', 'VARCHAR(100) NULL');
        $this->add_column_if_missing('last_name', 'VARCHAR(100) NULL');
        $this->add_column_if_missing('address', 'TEXT NULL');

        $this->_lava->db->raw(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin','moderator','user','farmer','collector','transporter','seller')
             NOT NULL DEFAULT 'user'"
        );
    }

    public function down()
    {
        // Keep registration data and roles when rolling back application code.
    }

    private function add_column_if_missing($column, $definition)
    {
        $exists = (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', $column]
        )->fetchColumn() > 0;

        if (!$exists) {
            $this->_lava->db->raw("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }
}
