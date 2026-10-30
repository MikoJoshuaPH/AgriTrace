-- =====================================================================
-- AgriTrace - Agricultural Product Traceability System
-- MySQL 8.0+ schema (run in Navicat Lite via Query window)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS AgriTrace
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE AgriTrace;

-- Drop existing tables (reverse dependency order) so the script can be re-run
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS weather_records;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS markets;
DROP TABLE IF EXISTS shipments;
DROP TABLE IF EXISTS traceability_logs;
DROP TABLE IF EXISTS qr_codes;
DROP TABLE IF EXISTS harvest_records;
DROP TABLE IF EXISTS product_batches;
DROP TABLE IF EXISTS farms;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS community_posts;
DROP TABLE IF EXISTS marketplace_listings;
DROP TABLE IF EXISTS refresh_tokens;
DROP TABLE IF EXISTS migrations;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- 0. migrations ------------------------------------------------------
CREATE TABLE migrations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    migration   INT NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1. users -----------------------------------------------------------
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(150) NOT NULL,
    first_name  VARCHAR(100) NULL,
    middle_name VARCHAR(100) NULL,
    last_name   VARCHAR(100) NULL,
    address     TEXT NULL,
    email       VARCHAR(150) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','farmer','collector','transporter','seller') NOT NULL,
    profile_pic VARCHAR(2048) NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1a. refresh_tokens -------------------------------------------------
CREATE TABLE refresh_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(255) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    revoked_at  DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. farms -----------------------------------------------------------
CREATE TABLE farms (
    farm_id         INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id       INT NOT NULL,
    farm_name       VARCHAR(150) NOT NULL,
    farm_address    TEXT NULL,
    province        VARCHAR(100) NULL,
    municipality    VARCHAR(100) NULL,
    barangay        VARCHAR(100) NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    farm_size       DECIMAL(10,2) NULL,
    farm_size_unit  VARCHAR(20) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_farms_farmer FOREIGN KEY (farmer_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 3. products --------------------------------------------------------
CREATE TABLE products (
    product_id      INT AUTO_INCREMENT PRIMARY KEY,
    product_name    VARCHAR(150) NOT NULL,
    category        VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    unit_of_measure VARCHAR(30) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3a. marketplace_listings -----------------------------------------
CREATE TABLE marketplace_listings (
    listing_id      INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT NOT NULL,
    product_name    VARCHAR(150) NOT NULL,
    category        VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    price           DECIMAL(12,2) NOT NULL,
    stock           DECIMAL(12,2) NOT NULL,
    unit            VARCHAR(30) NOT NULL,
    location        VARCHAR(255) NULL,
    image_url       VARCHAR(2048) NULL,
    is_available    TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_seller FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 3b. community_posts ----------------------------------------------
CREATE TABLE community_posts (
    post_id         INT AUTO_INCREMENT PRIMARY KEY,
    author_id       INT NOT NULL,
    content         TEXT NOT NULL,
    post_type       VARCHAR(30) NOT NULL DEFAULT 'text',
    listing_id      INT NULL,
    media_url       TEXT NULL,
    media_type      VARCHAR(30) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(listing_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. product_batches -------------------------------------------------
CREATE TABLE product_batches (
    batch_id        INT AUTO_INCREMENT PRIMARY KEY,
    batch_code      VARCHAR(50) NOT NULL UNIQUE,
    product_id      INT NOT NULL,
    farm_id         INT NOT NULL,
    quantity        DECIMAL(12,2) NOT NULL,
    unit            VARCHAR(30) NOT NULL,
    harvest_date    DATE NULL,
    product_image   VARCHAR(255) NULL,
    current_status  ENUM('harvested','collected','in_transit','received','available_at_market','sold','rejected')
                    NOT NULL DEFAULT 'harvested',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products(product_id),
    CONSTRAINT fk_batches_farm    FOREIGN KEY (farm_id)    REFERENCES farms(farm_id)
) ENGINE=InnoDB;

-- 5. harvest_records -------------------------------------------------
CREATE TABLE harvest_records (
    harvest_id          INT AUTO_INCREMENT PRIMARY KEY,
    batch_id            INT NOT NULL,
    farmer_id           INT NOT NULL,
    harvest_date        DATE NOT NULL,
    quantity_harvested  DECIMAL(12,2) NOT NULL,
    quality_grade       VARCHAR(50) NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_harvest_batch  FOREIGN KEY (batch_id)  REFERENCES product_batches(batch_id),
    CONSTRAINT fk_harvest_farmer FOREIGN KEY (farmer_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 6. qr_codes --------------------------------------------------------
CREATE TABLE qr_codes (
    qr_id           INT AUTO_INCREMENT PRIMARY KEY,
    batch_id        INT NOT NULL UNIQUE,
    qr_token        VARCHAR(255) NOT NULL UNIQUE,
    qr_image_path   VARCHAR(255) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_batch FOREIGN KEY (batch_id) REFERENCES product_batches(batch_id)
) ENGINE=InnoDB;

-- 7. traceability_logs -----------------------------------------------
CREATE TABLE traceability_logs (
    log_id          INT AUTO_INCREMENT PRIMARY KEY,
    batch_id        INT NOT NULL,
    updated_by      INT NOT NULL,
    status          ENUM('harvested','collected','in_transit','received','available_at_market','sold','rejected') NOT NULL,
    location_name   VARCHAR(255) NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    remarks         TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trace_batch FOREIGN KEY (batch_id)   REFERENCES product_batches(batch_id),
    CONSTRAINT fk_trace_user  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 8. shipments -------------------------------------------------------
CREATE TABLE shipments (
    shipment_id     INT AUTO_INCREMENT PRIMARY KEY,
    batch_id        INT NOT NULL,
    transporter_id  INT NOT NULL,
    vehicle_number  VARCHAR(50) NULL,
    origin          VARCHAR(255) NOT NULL,
    destination     VARCHAR(255) NOT NULL,
    dispatch_time   DATETIME NULL,
    delivery_time   DATETIME NULL,
    shipment_status ENUM('pending','in_transit','delivered','cancelled') NOT NULL DEFAULT 'pending',
    remarks         TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ship_batch       FOREIGN KEY (batch_id)       REFERENCES product_batches(batch_id),
    CONSTRAINT fk_ship_transporter FOREIGN KEY (transporter_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 9. markets ---------------------------------------------------------
CREATE TABLE markets (
    market_id       INT AUTO_INCREMENT PRIMARY KEY,
    market_name     VARCHAR(150) NOT NULL,
    manager_id      INT NOT NULL,
    address         TEXT NULL,
    province        VARCHAR(100) NULL,
    municipality    VARCHAR(100) NULL,
    contact_number  VARCHAR(20) NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_markets_manager FOREIGN KEY (manager_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 10. notifications --------------------------------------------------
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    message         TEXT NOT NULL,
    channel         ENUM('email','system') NOT NULL,
    delivery_status ENUM('pending','sent','failed','read') NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. weather_records ------------------------------------------------
CREATE TABLE weather_records (
    weather_id          INT AUTO_INCREMENT PRIMARY KEY,
    farm_id             INT NOT NULL,
    harvest_id          INT NULL,
    temperature         DECIMAL(5,2) NULL,
    humidity            DECIMAL(5,2) NULL,
    weather_condition   VARCHAR(100) NULL,
    rainfall_mm         DECIMAL(8,2) NULL,
    recorded_at         DATETIME NOT NULL,
    CONSTRAINT fk_weather_farm    FOREIGN KEY (farm_id)    REFERENCES farms(farm_id),
    CONSTRAINT fk_weather_harvest FOREIGN KEY (harvest_id) REFERENCES harvest_records(harvest_id)
) ENGINE=InnoDB;

-- 12. audit_logs -----------------------------------------------------
CREATE TABLE audit_logs (
    audit_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    action          VARCHAR(150) NOT NULL,
    entity_type     VARCHAR(100) NOT NULL,
    entity_id       INT NULL,
    details         TEXT NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- Seed data from uploaded migrations.json and users.json
-- (dates converted from DD/MM/YYYY to YYYY-MM-DD for MySQL)
-- =====================================================================

INSERT INTO migrations (id, migration, applied_at) VALUES
    (1, 0, '2026-10-17 23:49:26'),
    (2, 1, '2026-10-17 23:49:26'),
    (3, 2, '2026-10-17 23:49:27');

INSERT INTO users (id, username, email, password, role, is_active, created_at, updated_at) VALUES
    (1, 'Miko Joshua', 'mikojoshuaph@gmail.com',
     '$2y$10$lY2Ihb.8LTkG1XH.Wvu0mu.4bziOpniJFvs7EsBeJXTJYfDoVbRBq',
     'admin', 1, '2026-10-17 23:51:26', NULL);

-- Verify
SHOW TABLES;