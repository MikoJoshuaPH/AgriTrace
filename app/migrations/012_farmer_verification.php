<?php

/**
 * Adds administrator-controlled verification badges for farmers.
 */
class farmer_verification
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $column = $this->_lava->db->raw("SHOW COLUMNS FROM users LIKE 'is_verified'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $this->_lava->db->raw('ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_pic');
        }
    }

    public function down()
    {
        $column = $this->_lava->db->raw("SHOW COLUMNS FROM users LIKE 'is_verified'")->fetch(PDO::FETCH_ASSOC);
        if ($column) {
            $this->_lava->db->raw('ALTER TABLE users DROP COLUMN is_verified');
        }
    }
}
