<?php
/**
 * KitchTrack - Database Configuration (PDO)
 * XAMPP Default Settings: localhost, root, empty password, db_kitchtrack
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

$db_host = 'localhost';
$db_name = 'db_kitchtrack';
$db_user = 'root';
$db_pass = '';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

$pdo_options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $pdo_options);

    // Ensure newer columns & tables exist even if database.sql was imported earlier
    $colCheck = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    $colCheck->execute([$db_name, 'shopping_trips', 'payment_method']);
    if (!$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE `shopping_trips` ADD COLUMN `payment_method` ENUM('Cash', 'Online Transfer / QR') NOT NULL DEFAULT 'Cash' AFTER `cash_given`");
    }

    $colCheck->execute([$db_name, 'shopping_items', 'store_name']);
    if (!$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE `shopping_items` ADD COLUMN `store_name` VARCHAR(100) DEFAULT NULL AFTER `trip_id`");
    }

    $colCheck->execute([$db_name, 'shopping_items', 'previous_store_name']);
    if (!$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE `shopping_items` ADD COLUMN `previous_store_name` VARCHAR(100) DEFAULT NULL AFTER `previous_unit_price`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `trip_receipts` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Error - KitchTrack</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#FAF7F2;color:#4A3525;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem;}';
    echo '.box{background:#fff;border:1px solid #E5DDD3;border-radius:12px;padding:2rem;max-width:480px;box-shadow:0 4px 16px rgba(74,53,37,.08);}</style></head><body>';
    echo '<div class="box"><h2 style="margin-top:0;">Database Connection Failed</h2>';
    echo '<p>Please make sure XAMPP MySQL is running and you have imported <code>database.sql</code> into <strong>db_kitchtrack</strong>.</p>';
    echo '<p style="font-size:0.85rem;color:#8a7768;">Details: ' . htmlspecialchars($e->getMessage()) . '</p></div></body></html>';
    exit;
}
