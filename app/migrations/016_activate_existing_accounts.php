<?php

/**
 * Removes the legacy administrator approval gate for registered accounts.
 */
class activate_existing_accounts
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw('UPDATE users SET is_active = 1 WHERE is_active = 0');
    }

    public function down()
    {
        // Account activation is intentionally not reversible.
    }
}
