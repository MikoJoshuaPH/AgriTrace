<?php

/**
 * Stores the public description for registered farms.
 */
class public_farm_details
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $exists = (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['farms', 'farm_description']
        )->fetchColumn();
        if (!$exists) {
            $this->_lava->db->raw('ALTER TABLE farms ADD COLUMN farm_description TEXT NULL AFTER farm_size_unit');
        }
    }

    public function down()
    {
        // Existing farm descriptions are intentionally preserved on rollback.
    }
}
