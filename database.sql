-- ============================================================
-- KitchTrack: Restaurant Restock & Petty Cash Management System
-- Database Schema & Realistic Dummy Data
-- Import this file into phpMyAdmin (XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `db_kitchtrack`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_kitchtrack`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `trip_receipts`;
DROP TABLE IF EXISTS `shopping_items`;
DROP TABLE IF EXISTS `shopping_trips`;
DROP TABLE IF EXISTS `essential_items`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 1. Users Table (Owner & Staff)
--    Staff are deactivated via status = 'inactive' instead of
--    hard-deleting so past logs and receipts remain intact.
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'staff') NOT NULL DEFAULT 'staff',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Store Categories Table
-- ------------------------------------------------------------
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(200) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Essential Items (Quick-Restock Templates)
-- ------------------------------------------------------------
CREATE TABLE `essential_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_name` VARCHAR(120) NOT NULL,
  `default_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `default_unit` VARCHAR(30) NOT NULL DEFAULT 'pcs',
  `category_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_essential_category` (`category_id`),
  CONSTRAINT `fk_essential_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Shopping Trips & Petty Cash Table
--    status:
--      'open'      = Petty cash handed to staff before shopping
--      'shopping'  = Active trip lock (staff currently at market)
--      'completed' = Checked out with receipt; awaiting kitchen receive
--      'received'  = Confirmed received at the restaurant kitchen
-- ------------------------------------------------------------
CREATE TABLE `shopping_trips` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shopper_id` INT UNSIGNED NOT NULL,
  `status` ENUM('open', 'shopping', 'completed', 'received') NOT NULL DEFAULT 'open',
  `cash_given` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('Cash', 'Online Transfer / QR') NOT NULL DEFAULT 'Cash',
  `total_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_returned` TINYINT(1) NOT NULL DEFAULT 0,
  `change_confirmed_by` INT UNSIGNED DEFAULT NULL,
  `change_confirmed_at` DATETIME DEFAULT NULL,
  `receipt_image` VARCHAR(255) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trips_shopper` (`shopper_id`),
  KEY `idx_trips_status` (`status`),
  KEY `idx_trips_completed` (`completed_at`),
  CONSTRAINT `fk_trips_shopper`
    FOREIGN KEY (`shopper_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_change_confirmer`
    FOREIGN KEY (`change_confirmed_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_receiver`
    FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Trip Receipts Table (Supports multiple receipt photos per trip)
-- ------------------------------------------------------------
CREATE TABLE `trip_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id` INT UNSIGNED NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `store_label` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receipts_trip` (`trip_id`),
  CONSTRAINT `fk_receipts_trip`
    FOREIGN KEY (`trip_id`) REFERENCES `shopping_trips` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Shopping Items & Accountability Log Table
-- ------------------------------------------------------------
CREATE TABLE `shopping_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_name` VARCHAR(120) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit` VARCHAR(30) NOT NULL DEFAULT 'pcs',
  `category_id` INT UNSIGNED NOT NULL,
  `is_urgent` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'in_cart', 'out_of_stock', 'bought', 'received') NOT NULL DEFAULT 'pending',
  `trip_id` INT UNSIGNED DEFAULT NULL,
  `store_name` VARCHAR(100) DEFAULT NULL,
  `price_paid` DECIMAL(10,2) DEFAULT NULL,
  `unit_price` DECIMAL(10,2) DEFAULT NULL,
  `previous_unit_price` DECIMAL(10,2) DEFAULT NULL,
  `previous_store_name` VARCHAR(100) DEFAULT NULL,
  `is_price_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `requested_by` INT UNSIGNED NOT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `bought_by` INT UNSIGNED DEFAULT NULL,
  `bought_at` DATETIME DEFAULT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_category` (`category_id`),
  KEY `idx_items_status` (`status`),
  KEY `idx_items_trip` (`trip_id`),
  KEY `idx_items_name` (`item_name`),
  KEY `idx_items_alert` (`is_price_alert`),
  CONSTRAINT `fk_items_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_items_trip`
    FOREIGN KEY (`trip_id`) REFERENCES `shopping_trips` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_items_requester`
    FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_items_buyer`
    FOREIGN KEY (`bought_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_items_receiver`
    FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DUMMY DATA
-- Default password for all sample accounts: password123
-- ============================================================

-- Users: 1 Owner, 2 Active Staff, 1 Inactive (Resigned) Staff
INSERT INTO `users` (`id`, `full_name`, `username`, `password_hash`, `role`, `status`) VALUES
(1, 'Hakim Rahman', 'owner', '$2y$10$48K57RXBkknGGsHsJLArYOoQlp9BhKuQqgWhTxfBqDVRZq.dso9Ou', 'owner', 'active'),
(2, 'Siti Aminah',  'siti',  '$2y$10$48K57RXBkknGGsHsJLArYOoQlp9BhKuQqgWhTxfBqDVRZq.dso9Ou', 'staff', 'active'),
(3, 'Ali Hassan',   'ali',   '$2y$10$48K57RXBkknGGsHsJLArYOoQlp9BhKuQqgWhTxfBqDVRZq.dso9Ou', 'staff', 'active'),
(4, 'Farah Nadia',  'farah', '$2y$10$48K57RXBkknGGsHsJLArYOoQlp9BhKuQqgWhTxfBqDVRZq.dso9Ou', 'staff', 'inactive');

-- Store Categories
INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Wet Market',     'Fresh poultry, seafood, meat, and daily vegetables'),
(2, 'Grocery Store',  'Sauces, spices, canned food, and dry kitchen goods'),
(3, 'Wholesaler',     'Bulk rice, cooking oil, sugar, and takeaway packaging'),
(4, 'Bakery & Dairy', 'Eggs, fresh milk, butter, and bread');

-- Essential Items (Quick-Restock Templates)
INSERT INTO `essential_items` (`item_name`, `default_qty`, `default_unit`, `category_id`) VALUES
('Fresh Chicken',   5.00, 'kg',     1),
('Red Onions',      3.00, 'kg',     1),
('Garlic',          2.00, 'kg',     1),
('Green Chilies',   1.00, 'kg',     1),
('Soy Sauce',       2.00, 'bottle', 2),
('Oyster Sauce',    2.00, 'bottle', 2),
('Cooking Oil',     5.00, 'bottle', 3),
('Basmati Rice',   10.00, 'kg',     3),
('Takeaway Boxes',  2.00, 'pack',   3),
('Grade A Eggs',    3.00, 'tray',   4),
('Fresh Milk',      4.00, 'carton', 4);

-- Past Shopping Trips (spanning recent months for Chart.js analytics)
INSERT INTO `shopping_trips`
  (`id`, `shopper_id`, `status`, `cash_given`, `payment_method`, `total_spent`, `change_amount`, `change_returned`, `change_confirmed_by`, `change_confirmed_at`, `receipt_image`, `notes`, `started_at`, `completed_at`, `received_by`, `received_at`, `created_by`)
VALUES
-- 4 months ago
(1, 2, 'received', 350.00, 'Cash', 312.00, 38.00, 1, 1,
  DATE_SUB(NOW(), INTERVAL 120 DAY), NULL, 'Monthly dry goods restock',
  DATE_SUB(NOW(), INTERVAL 120 DAY), DATE_SUB(NOW(), INTERVAL 120 DAY),
  4, DATE_SUB(NOW(), INTERVAL 120 DAY), 1),

-- 3 months ago
(2, 3, 'received', 400.00, 'Online Transfer / QR', 368.50, 31.50, 1, 1,
  DATE_SUB(NOW(), INTERVAL 90 DAY), NULL, 'Weekend market run',
  DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 90 DAY),
  2, DATE_SUB(NOW(), INTERVAL 90 DAY), 1),

-- 2 months ago
(3, 2, 'received', 300.00, 'Cash', 274.00, 26.00, 1, 1,
  DATE_SUB(NOW(), INTERVAL 60 DAY), NULL, 'Wholesaler & wet market',
  DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY),
  4, DATE_SUB(NOW(), INTERVAL 60 DAY), 1),

-- 1 month ago
(4, 3, 'received', 450.00, 'Online Transfer / QR', 418.00, 32.00, 1, 1,
  DATE_SUB(NOW(), INTERVAL 32 DAY), NULL, 'Bulk stock replenishment',
  DATE_SUB(NOW(), INTERVAL 32 DAY), DATE_SUB(NOW(), INTERVAL 32 DAY),
  2, DATE_SUB(NOW(), INTERVAL 32 DAY), 1),

-- Earlier this month (received & change returned)
(5, 2, 'received', 250.00, 'Cash', 226.00, 24.00, 1, 1,
  DATE_SUB(NOW(), INTERVAL 5 DAY), NULL, 'Morning market restock',
  DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY),
  3, DATE_SUB(NOW(), INTERVAL 5 DAY), 1),

-- Yesterday (completed, awaiting delivery check & Pending Change return)
(6, 2, 'completed', 220.00, 'Online Transfer / QR', 184.50, 35.50, 0, NULL,
  NULL, NULL, 'Urgent chicken & oil top-up',
  DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY),
  NULL, NULL, 1);

-- Past Purchased Items (including Store Names & Price Alerts in Trip 5 & Trip 6)
INSERT INTO `shopping_items`
  (`item_name`, `quantity`, `unit`, `category_id`, `is_urgent`, `status`, `trip_id`, `store_name`, `price_paid`, `unit_price`, `previous_unit_price`, `previous_store_name`, `is_price_alert`, `requested_by`, `requested_at`, `bought_by`, `bought_at`, `received_by`, `received_at`)
VALUES
-- Trip 1 (4 months ago)
('Basmati Rice',  10.00, 'kg',     3, 0, 'received', 1, 'NSK Trade City',        120.00, 12.00, NULL,  NULL,                    0, 4, DATE_SUB(NOW(), INTERVAL 120 DAY), 2, DATE_SUB(NOW(), INTERVAL 120 DAY), 4, DATE_SUB(NOW(), INTERVAL 120 DAY)),
('Cooking Oil',    5.00, 'bottle', 3, 0, 'received', 1, 'NSK Trade City',        140.00, 28.00, NULL,  NULL,                    0, 3, DATE_SUB(NOW(), INTERVAL 120 DAY), 2, DATE_SUB(NOW(), INTERVAL 120 DAY), 4, DATE_SUB(NOW(), INTERVAL 120 DAY)),
('Red Onions',     5.00, 'kg',     1, 0, 'received', 1, 'Pasar Borong Selayang',  52.00, 10.40, NULL,  NULL,                    0, 4, DATE_SUB(NOW(), INTERVAL 120 DAY), 2, DATE_SUB(NOW(), INTERVAL 120 DAY), 4, DATE_SUB(NOW(), INTERVAL 120 DAY)),

-- Trip 2 (3 months ago)
('Fresh Chicken', 15.00, 'kg',     1, 1, 'received', 2, 'Pasar Borong Selayang', 142.50,  9.50, NULL,  NULL,                    0, 2, DATE_SUB(NOW(), INTERVAL 90 DAY), 3, DATE_SUB(NOW(), INTERVAL 90 DAY), 2, DATE_SUB(NOW(), INTERVAL 90 DAY)),
('Grade A Eggs',   8.00, 'tray',   4, 0, 'received', 2, '99 Speedmart',          120.00, 15.00, NULL,  NULL,                    0, 3, DATE_SUB(NOW(), INTERVAL 90 DAY), 3, DATE_SUB(NOW(), INTERVAL 90 DAY), 2, DATE_SUB(NOW(), INTERVAL 90 DAY)),
('Takeaway Boxes', 4.00, 'pack',   3, 0, 'received', 2, 'Mydin Wholesale',       106.00, 26.50, NULL,  NULL,                    0, 4, DATE_SUB(NOW(), INTERVAL 90 DAY), 3, DATE_SUB(NOW(), INTERVAL 90 DAY), 2, DATE_SUB(NOW(), INTERVAL 90 DAY)),

-- Trip 3 (2 months ago)
('Fresh Chicken', 12.00, 'kg',     1, 0, 'received', 3, 'Pasar Borong Selayang', 114.00,  9.50,  9.50, 'Pasar Borong Selayang', 0, 3, DATE_SUB(NOW(), INTERVAL 60 DAY), 2, DATE_SUB(NOW(), INTERVAL 60 DAY), 4, DATE_SUB(NOW(), INTERVAL 60 DAY)),
('Fresh Milk',    10.00, 'carton', 4, 0, 'received', 3, '99 Speedmart',           80.00,  8.00, NULL,  NULL,                    0, 2, DATE_SUB(NOW(), INTERVAL 60 DAY), 2, DATE_SUB(NOW(), INTERVAL 60 DAY), 4, DATE_SUB(NOW(), INTERVAL 60 DAY)),
('Soy Sauce',      8.00, 'bottle', 2, 0, 'received', 3, '99 Speedmart',           80.00, 10.00, NULL,  NULL,                    0, 4, DATE_SUB(NOW(), INTERVAL 60 DAY), 2, DATE_SUB(NOW(), INTERVAL 60 DAY), 4, DATE_SUB(NOW(), INTERVAL 60 DAY)),

-- Trip 4 (1 month ago)
('Basmati Rice',  15.00, 'kg',     3, 0, 'received', 4, 'NSK Trade City',        180.00, 12.00, 12.00, 'NSK Trade City',        0, 2, DATE_SUB(NOW(), INTERVAL 32 DAY), 3, DATE_SUB(NOW(), INTERVAL 32 DAY), 2, DATE_SUB(NOW(), INTERVAL 32 DAY)),
('Cooking Oil',    5.00, 'bottle', 3, 0, 'received', 4, 'NSK Trade City',        140.00, 28.00, 28.00, 'NSK Trade City',        0, 3, DATE_SUB(NOW(), INTERVAL 32 DAY), 3, DATE_SUB(NOW(), INTERVAL 32 DAY), 2, DATE_SUB(NOW(), INTERVAL 32 DAY)),
('Garlic',         6.00, 'kg',     1, 0, 'received', 4, 'Pasar Borong Selayang',  98.00, 16.33, NULL,  NULL,                    0, 2, DATE_SUB(NOW(), INTERVAL 32 DAY), 3, DATE_SUB(NOW(), INTERVAL 32 DAY), 2, DATE_SUB(NOW(), INTERVAL 32 DAY)),

-- Trip 5 (Earlier this month - Price Alert on Cooking Oil: RM 28.00 at NSK -> RM 32.00 at Mydin)
('Cooking Oil',    4.00, 'bottle', 3, 1, 'received', 5, 'Mydin Wholesale',       128.00, 32.00, 28.00, 'NSK Trade City',        1, 3, DATE_SUB(NOW(), INTERVAL 5 DAY), 2, DATE_SUB(NOW(), INTERVAL 5 DAY), 3, DATE_SUB(NOW(), INTERVAL 5 DAY)),
('Grade A Eggs',   4.00, 'tray',   4, 0, 'received', 5, '99 Speedmart',           60.00, 15.00, 15.00, '99 Speedmart',          0, 2, DATE_SUB(NOW(), INTERVAL 5 DAY), 2, DATE_SUB(NOW(), INTERVAL 5 DAY), 3, DATE_SUB(NOW(), INTERVAL 5 DAY)),
('Oyster Sauce',   4.00, 'bottle', 2, 0, 'received', 5, '99 Speedmart',           38.00,  9.50, NULL,  NULL,                    0, 3, DATE_SUB(NOW(), INTERVAL 5 DAY), 2, DATE_SUB(NOW(), INTERVAL 5 DAY), 3, DATE_SUB(NOW(), INTERVAL 5 DAY)),

-- Trip 6 (Yesterday - Price Alerts comparing different stores; awaiting kitchen Confirm Received)
('Fresh Chicken', 10.00, 'kg',     1, 1, 'bought',   6, 'Pasar Pagi Melati',     112.00, 11.20,  9.50, 'Pasar Borong Selayang', 1, 3, DATE_SUB(NOW(), INTERVAL 1 DAY), 2, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL),
('Red Onions',     4.00, 'kg',     1, 0, 'bought',   6, 'Pasar Pagi Melati',      42.50, 10.63, 10.40, 'Pasar Borong Selayang', 1, 2, DATE_SUB(NOW(), INTERVAL 1 DAY), 2, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL),
('Fresh Milk',     3.00, 'carton', 4, 0, 'bought',   6, 'Lotus\'s Market',        30.00, 10.00,  8.00, '99 Speedmart',          1, 3, DATE_SUB(NOW(), INTERVAL 1 DAY), 2, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL);

-- Current Live Shopping List Items (Pending for today's trip)
INSERT INTO `shopping_items`
  (`item_name`, `quantity`, `unit`, `category_id`, `is_urgent`, `status`, `requested_by`, `requested_at`)
VALUES
('Fresh Chicken',  6.00, 'kg',     1, 1, 'pending', 3, DATE_SUB(NOW(), INTERVAL 45 MINUTE)),
('Grade A Eggs',   3.00, 'tray',   4, 1, 'pending', 2, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
('Soy Sauce',      2.00, 'bottle', 2, 0, 'pending', 3, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
('Garlic',         2.00, 'kg',     1, 0, 'pending', 2, DATE_SUB(NOW(), INTERVAL 10 MINUTE));
