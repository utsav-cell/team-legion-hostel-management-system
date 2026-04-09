<?php
require_once 'db.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS `enquiries` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(100) NOT NULL,
      `email` VARCHAR(100) DEFAULT NULL,
      `message` TEXT NOT NULL,
      `source` VARCHAR(50) DEFAULT 'Website Form',
      `status` ENUM('unread', 'read', 'replied') DEFAULT 'unread',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "ALTER TABLE `users` ADD COLUMN `otp_code` VARCHAR(6) DEFAULT NULL AFTER `password`;",
    "ALTER TABLE `users` ADD COLUMN `is_verified` TINYINT(1) DEFAULT 0 AFTER `otp_code`;"
];

foreach ($queries as $q) {
    if (mysqli_query($conn, $q)) {
        echo "Successfully executed: " . substr($q, 0, 50) . "...\n";
    } else {
        echo "Error executing query: " . mysqli_error($conn) . "\n";
    }
}

// Mark existing users as verified for convenience
mysqli_query($conn, "UPDATE users SET is_verified = 1");

unlink(__FILE__); // Self-destruct for security
?>
