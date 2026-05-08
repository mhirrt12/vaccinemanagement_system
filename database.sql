-- ============================================================
-- Vaccine Management System – Full Database Creation Script
-- ============================================================
-- Run this as a single query to create the database, tables,
-- constraints, indexes and seed data.
-- ============================================================

-- 1. CREATE DATABASE
CREATE DATABASE IF NOT EXISTS `vaccine_ms`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `vaccine_ms`;

-- ==============================
-- 2. ROLES TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 3. USERS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` INT NOT NULL DEFAULT 1,
  `username` VARCHAR(50) DEFAULT NULL,
  `education_level` VARCHAR(100) DEFAULT NULL,
  `certificate` VARCHAR(255) DEFAULT NULL,
  `work_experience` TEXT DEFAULT NULL,
  `is_verified` TINYINT NOT NULL DEFAULT 0,   -- 0=pending, 1=approved, -1=rejected
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `phone` (`phone`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 4. CHILDREN TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `children` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `parent_id` INT NOT NULL,
  `unique_child_id` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `dob` DATE NOT NULL,
  `gender` VARCHAR(10) DEFAULT 'Male',
  `blood_type` VARCHAR(5) DEFAULT NULL,
  `allergies` TEXT DEFAULT NULL,
  `birth_weight` DECIMAL(5,2) DEFAULT NULL,
  `delivery_type` VARCHAR(20) DEFAULT 'Normal',
  `birth_place` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'pending',      -- pending, approved, rejected
  `approved_by_nurse_id` INT DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `pending_historical_vaccines` VARCHAR(500) DEFAULT NULL,  -- comma separated vaccine IDs
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_child_id` (`unique_child_id`),
  KEY `parent_id` (`parent_id`),
  KEY `approved_by_nurse_id` (`approved_by_nurse_id`),
  CONSTRAINT `children_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `children_ibfk_2` FOREIGN KEY (`approved_by_nurse_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 5. VACCINES TABLE (EPI Schedule)
-- ==============================
CREATE TABLE IF NOT EXISTS `vaccines` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `days_from_birth` INT NOT NULL DEFAULT 0,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 6. APPOINTMENTS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `appointments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `child_id` INT NOT NULL,
  `vaccine_id` INT NOT NULL,
  `scheduled_date` DATE NOT NULL,
  `status` ENUM('pending','completed','missed','rescheduled','cancelled') DEFAULT 'pending',
  `given_date` DATE DEFAULT NULL,
  `batch_number` VARCHAR(50) DEFAULT NULL,
  `nurse_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `reschedule_request_date` DATE DEFAULT NULL,
  `reschedule_approved` TINYINT(1) DEFAULT 0,
  `reminder_sent` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `vaccine_id` (`vaccine_id`),
  KEY `nurse_id` (`nurse_id`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 7. NURSE ASSIGNMENTS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `nurse_assignments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nurse_id` INT NOT NULL,
  `child_id` INT NOT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `nurse_id` (`nurse_id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `nurse_assignments_ibfk_1` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nurse_assignments_ibfk_2` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 8. CERTIFICATES TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `certificates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `child_id` INT NOT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `is_approved_by_nurse` TINYINT(1) NOT NULL DEFAULT 0,
  `is_approved_by_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `child_id` (`child_id`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 9. INVENTORY TABLE (vaccine stock)
-- ==============================
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `vaccine_id` INT NOT NULL,
  `batch_number` VARCHAR(50) NOT NULL,
  `expiry_date` DATE NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `registration_date` DATE NOT NULL DEFAULT (CURRENT_DATE),
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vaccine_batch` (`vaccine_id`,`batch_number`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 10. NOTIFICATIONS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `child_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'general',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 11. REPORTS TABLE (generated by nurses)
-- ==============================
CREATE TABLE IF NOT EXISTS `reports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `type` ENUM('weekly','monthly') NOT NULL,
  `generated_by` INT NOT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `data` TEXT DEFAULT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `generated_by` (`generated_by`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 12. AUDIT LOGS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================
-- 13. INSERT SEED DATA
-- ==============================

-- ROLES
INSERT INTO `roles` (`id`, `name`) VALUES
(1, 'parent'),
(2, 'nurse'),
(3, 'admin');

-- USERS (password for all: "password123" – bcrypt hash)
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role_id`, `username`, `is_verified`) VALUES
(1, 'Admin Leleina', 'lelena@admin.com', '0911223344', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 'lelena', 1);

-- 5 PREDEFINED NURSES
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role_id`, `username`, `is_verified`) VALUES
(2, 'Hayat', 'hayat@nurse.com', '0911000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'hayat', 1),
(3, 'Fenet', 'fenet@nurse.com', '0911000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'fenet', 1),
(4, 'Adey', 'adey@nurse.com', '0911000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'adey', 1),
(5, 'Selome', 'selome@nurse.com', '0911000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'selome', 1),
(6, 'Nahom', 'nahom@nurse.com', '0911000005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'nahom', 1);

-- SAMPLE PARENT (optional)
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role_id`, `is_verified`) VALUES
(7, 'Kalkidan Tamene', 'kalkidan@parent.com', '0936279621', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1);

-- VACCINES (Ethiopian EPI schedule)
INSERT INTO `vaccines` (`name`, `days_from_birth`, `description`, `is_active`) VALUES
('BCG', 0, 'Bacillus Calmette-Guérin', 1),
('OPV-0', 0, 'Oral Polio Vaccine (birth dose)', 1),
('OPV-1', 42, 'Oral Polio Vaccine (6 weeks)', 1),
('Pentavalent-1', 42, 'DTP-HepB-Hib (6 weeks)', 1),
('PCV-1', 42, 'Pneumococcal Conjugate Vaccine (6 weeks)', 1),
('Rota-1', 42, 'Rotavirus Vaccine (6 weeks)', 1),
('OPV-2', 70, 'Oral Polio Vaccine (10 weeks)', 1),
('Pentavalent-2', 70, 'DTP-HepB-Hib (10 weeks)', 1),
('PCV-2', 70, 'Pneumococcal Conjugate Vaccine (10 weeks)', 1),
('Rota-2', 70, 'Rotavirus Vaccine (10 weeks)', 1),
('OPV-3', 98, 'Oral Polio Vaccine (14 weeks)', 1),
('Pentavalent-3', 98, 'DTP-HepB-Hib (14 weeks)', 1),
('PCV-3', 98, 'Pneumococcal Conjugate Vaccine (14 weeks)', 1),
('IPV', 98, 'Inactivated Polio Vaccine (14 weeks)', 1),
('MCV1', 274, 'Measles Containing Vaccine (9 months)', 1),
('MCV2', 456, 'Measles Containing Vaccine (15 months)', 1);



ALTER TABLE certificates 
ADD COLUMN verification_hash VARCHAR(64) UNIQUE NULL AFTER file_path,
ADD COLUMN stamp_image VARCHAR(255) NULL AFTER verification_hash,
ADD COLUMN signature_image VARCHAR(255) NULL AFTER stamp_image;


CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(50) PRIMARY KEY,
    `value` TEXT NULL
);

INSERT INTO settings (`key`, `value`) VALUES
('stamp_image', ''),
('signature_image', '')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);


CREATE TABLE IF NOT EXISTS sent_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    notification_type ENUM('reminder_3day','reminder_dayof') NOT NULL,
    channel ENUM('email','sms') NOT NULL,
    sent_to VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id)
);
-- ==============================
-- DONE
-- ==============================