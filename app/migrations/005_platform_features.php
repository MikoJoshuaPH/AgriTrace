<?php

/**
 * Platform features used by the dashboard, admin and profile pages:
 * profile fields, moderation status, likes, comments, orders, messages,
 * groups, follows, saved items and reports. Every statement is idempotent.
 */
class Platform_features
{
    private $_lava;

    /** Community groups offered in the Discover view. */
    private $groups = [
        ['Sustainable Farming PH', 'Community'], ['Organic Rice Growers', 'Crops'],
        ['Modern AgriTech Enthusiasts', 'AgriTech'], ['Backyard Gardening Tips', 'Community'],
        ['Livestock & Poultry PH', 'Livestock'], ['Urban Farming Community', 'Community'],
        ['Coconut Farmers Network', 'Crops'], ['Corn Producers Association', 'Crops'],
        ['Fresh Vegetables Traders', 'Crops'], ['Fruit Orchard Owners', 'Crops'],
        ['Agri-Business Startups', 'AgriTech'], ['Permaculture Philippines', 'Community'],
        ['Hydroponics & Aquaponics', 'AgriTech'], ['Farm Machinery Buy & Sell', 'AgriTech'],
        ['Weather & Crop Updates', 'Community'], ['AgriTourism PH', 'Community'],
        ['Young Farmers Club', 'Community'], ['Seed Savers Exchange', 'Crops'],
        ['Composting & Fertilizers', 'Crops'], ['Farm-to-Table Restaurants', 'Community'],
    ];

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $db = $this->_lava->db;

        $this->add_column('users', 'age', 'TINYINT UNSIGNED NULL');
        $this->add_column('users', 'gender', 'VARCHAR(20) NULL');
        $this->add_column('users', 'contact_number', 'VARCHAR(30) NULL');
        $this->add_column('users', 'profile_pic', 'VARCHAR(255) NULL');
        $this->add_column('community_posts', 'status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");

        $db->raw("CREATE TABLE IF NOT EXISTS post_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_post_like (post_id, user_id),
            CONSTRAINT fk_like_post FOREIGN KEY (post_id) REFERENCES community_posts(post_id) ON DELETE CASCADE,
            CONSTRAINT fk_like_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS post_comments (
            comment_id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            author_id INT NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_comment_post FOREIGN KEY (post_id) REFERENCES community_posts(post_id) ON DELETE CASCADE,
            CONSTRAINT fk_comment_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS orders (
            order_id INT AUTO_INCREMENT PRIMARY KEY,
            buyer_id INT NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_order_buyer FOREIGN KEY (buyer_id) REFERENCES users(id)
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS order_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            listing_id INT NULL,
            seller_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
            CONSTRAINT fk_item_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(listing_id) ON DELETE SET NULL,
            CONSTRAINT fk_item_seller FOREIGN KEY (seller_id) REFERENCES users(id)
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            body TEXT NOT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_msg_sender (sender_id),
            INDEX idx_msg_receiver (receiver_id),
            CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS community_groups (
            group_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            category VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS group_members (
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id),
            CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES community_groups(group_id) ON DELETE CASCADE,
            CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS user_follows (
            follower_id INT NOT NULL,
            followee_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (follower_id, followee_id),
            CONSTRAINT fk_follow_from FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_follow_to FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS saved_items (
            user_id INT NOT NULL,
            listing_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, listing_id),
            CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(listing_id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS reports (
            report_id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NULL,
            reported_user_id INT NULL,
            reason VARCHAR(255) NOT NULL,
            status ENUM('open','resolved') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_report_user FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        foreach ($this->groups as [$name, $category]) {
            $db->raw('INSERT IGNORE INTO community_groups (name, category) VALUES (?, ?)', [$name, $category]);
        }
    }

    public function down()
    {
        $db = $this->_lava->db;
        foreach (['reports', 'saved_items', 'user_follows', 'group_members', 'community_groups',
                  'messages', 'order_items', 'orders', 'post_comments', 'post_likes'] as $table) {
            $db->raw("DROP TABLE IF EXISTS {$table}");
        }
        foreach (['age', 'gender', 'contact_number', 'profile_pic'] as $column) {
            $this->drop_column_if_exists('users', $column);
        }
        $this->drop_column_if_exists('community_posts', 'status');
    }

    private function column_exists($table, $column)
    {
        return (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn() > 0;
    }

    private function add_column($table, $column, $definition)
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
