<?php

class Create_community_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('marketplace_listings')) {
            $this->_lava->dbforge
                ->add_field([
                    'listing_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'seller_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'product_name' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => FALSE,
                    ],
                    'category' => [
                        'type' => 'VARCHAR',
                        'constraint' => 100,
                        'null' => FALSE,
                    ],
                    'description' => [
                        'type' => 'TEXT',
                        'null' => TRUE,
                    ],
                    'price' => [
                        'type' => 'DECIMAL',
                        'constraint' => '12,2',
                        'null' => FALSE,
                    ],
                    'stock' => [
                        'type' => 'DECIMAL',
                        'constraint' => '12,2',
                        'null' => FALSE,
                    ],
                    'unit' => [
                        'type' => 'VARCHAR',
                        'constraint' => 30,
                        'null' => FALSE,
                    ],
                    'location' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => TRUE,
                    ],
                    'image_url' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => TRUE,
                    ],
                    'is_available' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'default' => 1,
                        'null' => FALSE,
                    ],
                    'created_at' => [
                        'type' => 'DATETIME',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                ])
                ->add_key('listing_id', primary: TRUE)
                ->add_key('seller_id', name: 'marketplace_seller_idx')
                ->create_table('marketplace_listings');
        }

        if (!$this->_lava->dbforge->table_exists('community_posts')) {
            $this->_lava->dbforge
                ->add_field([
                    'post_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'author_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'content' => [
                        'type' => 'TEXT',
                        'null' => FALSE,
                    ],
                    'post_type' => [
                        'type' => 'VARCHAR',
                        'constraint' => 30,
                        'default' => 'text',
                        'null' => FALSE,
                    ],
                    'listing_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => TRUE,
                    ],
                    'created_at' => [
                        'type' => 'DATETIME',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                ])
                ->add_key('post_id', primary: TRUE)
                ->add_key('author_id', name: 'community_posts_author_idx')
                ->add_key('listing_id', name: 'community_posts_listing_idx')
                ->create_table('community_posts');
        }
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('community_posts');
        $this->_lava->dbforge->drop_table('marketplace_listings');
    }
}
