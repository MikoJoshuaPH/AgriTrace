<?php

/**
 * Adds buyer-to-farmer applications and review history.
 */
class farmer_applications
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

        $db->raw("CREATE TABLE IF NOT EXISTS farmer_applications (
            application_id INT AUTO_INCREMENT PRIMARY KEY,
            applicant_id INT NOT NULL,
            farm_name VARCHAR(150) NOT NULL,
            farm_address TEXT NULL,
            province VARCHAR(100) NULL,
            municipality VARCHAR(100) NULL,
            barangay VARCHAR(100) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            farm_size DECIMAL(10,2) NULL,
            farm_size_unit VARCHAR(20) NULL,
            farm_description TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            rejection_reason VARCHAR(500) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_farmer_applicant_status (applicant_id, status),
            INDEX idx_farmer_application_status (status),
            CONSTRAINT fk_farmer_application_applicant FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_farmer_application_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $db->raw("CREATE TABLE IF NOT EXISTS farmer_application_history (
            history_id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            previous_status ENUM('pending','approved','rejected') NULL,
            new_status ENUM('pending','approved','rejected') NOT NULL,
            changed_by INT NOT NULL,
            reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_farmer_application_history_application (application_id, history_id),
            CONSTRAINT fk_farmer_application_history_application FOREIGN KEY (application_id) REFERENCES farmer_applications(application_id) ON DELETE CASCADE,
            CONSTRAINT fk_farmer_application_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS farmer_application_history');
        $this->_lava->db->raw('DROP TABLE IF EXISTS farmer_applications');
    }
}
