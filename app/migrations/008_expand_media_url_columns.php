<?php

/**
 * Puter read URLs and product media galleries exceed the original 255-char
 * columns. Keep their full URLs so the database can render uploaded media.
 */
class expand_media_url_columns
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
        $db->raw('ALTER TABLE users MODIFY COLUMN profile_pic VARCHAR(2048) NULL');
        $db->raw('ALTER TABLE marketplace_listings MODIFY COLUMN image_url VARCHAR(2048) NULL');
        $db->raw('ALTER TABLE community_posts MODIFY COLUMN media_url TEXT NULL');
    }

    public function down()
    {
        // Shrinking these columns could truncate existing Puter URLs or galleries.
    }
}
