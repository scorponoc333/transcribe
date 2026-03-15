<?php
/**
 * Database Setup - Run once to create the database and tables
 * Visit: http://localhost/transcribe/api/db_setup.php
 */
header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `transcribe_ai` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `transcribe_ai`");

    // Transcriptions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `transcriptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) DEFAULT NULL,
        `mode` ENUM('recording','meeting') NOT NULL DEFAULT 'recording',
        `language` VARCHAR(50) DEFAULT 'en',
        `transcript_text` LONGTEXT NOT NULL,
        `transcript_english` LONGTEXT DEFAULT NULL,
        `analysis_json` JSON DEFAULT NULL,
        `pdf_blob` LONGBLOB DEFAULT NULL,
        `pdf_filename` VARCHAR(255) DEFAULT NULL,
        `whisper_model` VARCHAR(50) DEFAULT 'turbo',
        `word_count` INT DEFAULT 0,
        `char_count` INT DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Email log table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `email_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `transcription_id` INT NOT NULL,
        `sent_to` VARCHAR(500) NOT NULL,
        `cc` VARCHAR(500) DEFAULT NULL,
        `bcc` VARCHAR(500) DEFAULT NULL,
        `subject` VARCHAR(500) NOT NULL,
        `sender` VARCHAR(255) DEFAULT NULL,
        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `status` ENUM('sent','failed') DEFAULT 'sent',
        `error_message` TEXT DEFAULT NULL,
        FOREIGN KEY (`transcription_id`) REFERENCES `transcriptions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Attendees table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendees` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `transcription_id` INT NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `source` ENUM('ai','manual') DEFAULT 'ai',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`transcription_id`) REFERENCES `transcriptions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Contacts table (for email autocomplete)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `contacts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) DEFAULT NULL,
        `email` VARCHAR(255) NOT NULL,
        `use_count` INT DEFAULT 1,
        `last_used_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add timing columns to transcriptions (idempotent)
    try {
        $pdo->exec("ALTER TABLE `transcriptions` ADD COLUMN `timer_seconds` INT DEFAULT NULL");
    } catch (PDOException $e) {
        // Column may already exist — ignore
    }
    try {
        $pdo->exec("ALTER TABLE `transcriptions` ADD COLUMN `audio_duration_seconds` INT DEFAULT NULL");
    } catch (PDOException $e) {
        // Column may already exist — ignore
    }

    // Add company column to contacts (idempotent)
    try {
        $pdo->exec("ALTER TABLE `contacts` ADD COLUMN `company` VARCHAR(255) DEFAULT NULL AFTER `email`");
    } catch (PDOException $e) {
        // Column may already exist — ignore
    }

    // Expand mode ENUM to include 'learning' (idempotent)
    try {
        $pdo->exec("ALTER TABLE `transcriptions` MODIFY COLUMN `mode` ENUM('recording','meeting','learning') NOT NULL DEFAULT 'recording'");
    } catch (PDOException $e) {
        // May already be expanded — ignore
    }

    // Add transcript_source column to transcriptions (idempotent)
    try {
        $pdo->exec("ALTER TABLE `transcriptions` ADD COLUMN `transcript_source` ENUM('audio','text','youtube') DEFAULT 'audio'");
    } catch (PDOException $e) {
        // Column may already exist — ignore
    }

    // AI cost tracking table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_costs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `transcription_id` INT DEFAULT NULL,
        `operation` VARCHAR(50) NOT NULL COMMENT 'analyze, translate',
        `generation_id` VARCHAR(255) DEFAULT NULL,
        `model` VARCHAR(255) DEFAULT NULL,
        `prompt_tokens` INT DEFAULT 0,
        `completion_tokens` INT DEFAULT 0,
        `total_tokens` INT DEFAULT 0,
        `cost_usd` DECIMAL(10,6) DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`transcription_id`) REFERENCES `transcriptions`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ──────────────────────────────────────────────
    // Users table (authentication & RBAC)
    // ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `last_login_at` DATETIME DEFAULT NULL,
        `password_reset_token` VARCHAR(255) DEFAULT NULL,
        `password_reset_expires` DATETIME DEFAULT NULL,
        UNIQUE KEY `unique_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ──────────────────────────────────────────────
    // Application settings table (replaces localStorage)
    // ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ──────────────────────────────────────────────
    // Cover pages table (PDF report covers)
    // ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cover_pages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) NOT NULL,
        `original_name` VARCHAR(255) DEFAULT NULL,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add user_id column to transcriptions (idempotent)
    try {
        $pdo->exec("ALTER TABLE `transcriptions` ADD COLUMN `user_id` INT DEFAULT NULL AFTER `id`");
    } catch (PDOException $e) {
        // Column may already exist — ignore
    }
    try {
        $pdo->exec("ALTER TABLE `transcriptions` ADD INDEX `idx_user_id` (`user_id`)");
    } catch (PDOException $e) {
        // Index may already exist — ignore
    }

    // ──────────────────────────────────────────────
    // Seed default admin user
    // ──────────────────────────────────────────────
    $adminEmail = 'jasonhogan333@gmail.com';
    $check = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $check->execute([':email' => $adminEmail]);
    $adminRow = $check->fetch(PDO::FETCH_ASSOC);
    if (!$adminRow) {
        $hash = password_hash('Ngcxlebp#000', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, 'admin')");
        $stmt->execute([':name' => 'Jason Hogan', ':email' => $adminEmail, ':hash' => $hash]);
        $adminId = $pdo->lastInsertId();
    } else {
        $adminId = $adminRow['id'];
    }

    // Assign existing transcriptions to admin user
    try {
        $pdo->prepare("UPDATE transcriptions SET user_id = :uid WHERE user_id IS NULL")
            ->execute([':uid' => $adminId]);
    } catch (PDOException $e) {
        // Ignore if user_id column didn't exist yet
    }

    // ──────────────────────────────────────────────
    // Seed default cover page
    // ──────────────────────────────────────────────
    $coverCheck = $pdo->query("SELECT COUNT(*) FROM cover_pages")->fetchColumn();
    if ($coverCheck == 0) {
        $pdo->exec("INSERT INTO cover_pages (filename, original_name, is_default, sort_order)
                     VALUES ('default-cover.png', 'Default Cover', 1, 0)");
    }

    echo "<h2 style='color:green'>Database setup complete!</h2>";
    echo "<p>Database: <code>transcribe_ai</code></p>";
    echo "<p>Tables created: <code>transcriptions</code>, <code>email_log</code>, <code>attendees</code>, <code>contacts</code>, <code>ai_costs</code>, <code>users</code>, <code>settings</code>, <code>cover_pages</code></p>";
    echo "<p>Default admin user created: <code>" . htmlspecialchars($adminEmail) . "</code></p>";
    echo "<p><a href='/transcribe/'>Go to Transcribe AI</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>Setup failed</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Make sure MySQL is running in XAMPP.</p>";
}
