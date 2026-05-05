-- =============================================
-- Lidset Vaccine Management System
-- Production Database Schema
-- =============================================

DROP DATABASE IF EXISTS lidset_vms;
CREATE DATABASE lidset_vms;
USE lidset_vms;

-- -------------------------------------------------
-- Roles
-- -------------------------------------------------
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL
);
INSERT INTO roles (name) VALUES ('parent'), ('nurse'), ('admin');

-- -------------------------------------------------
-- Users (parents, nurses, admin)
-- -------------------------------------------------
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_email (email),
    INDEX idx_phone (phone)
);

-- Predefined nurses (passwords will be updated via admin)
INSERT INTO users (name, email, phone, password_hash, role_id, is_verified) VALUES
('Hayat', 'hayat@lidset.com', '0911000001', '$2y$10$DUMMY', 2, TRUE),
('Fenet', 'fenet@lidset.com', '0911000002', '$2y$10$DUMMY', 2, TRUE),
('Adey', 'adey@lidset.com', '0911000003', '$2y$10$DUMMY', 2, TRUE),
('Selome', 'selome@lidset.com', '0911000004', '$2y$10$DUMMY', 2, TRUE),
('Nahom', 'nahom@lidset.com', '0911000005', '$2y$10$DUMMY', 2, TRUE);

-- Admin (password: Admin@123 – hash generated)
INSERT INTO users (name, email, phone, password_hash, role_id, is_verified) VALUES
('Lelena', 'lelena@lidset.com', '0911000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, TRUE);

-- -------------------------------------------------
-- Children
-- -------------------------------------------------
CREATE TABLE children (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_id INT NOT NULL,
    unique_child_id VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    gender ENUM('Male','Female','Other') NOT NULL,
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NULL,
    allergies TEXT,
    birth_weight DECIMAL(5,2) NULL,
    delivery_type ENUM('Normal','C-Section') NULL,
    birth_place VARCHAR(255),
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_parent (parent_id),
    INDEX idx_unique (unique_child_id)
);

-- -------------------------------------------------
-- Vaccines (EPI Schedule)
-- -------------------------------------------------
CREATE TABLE vaccines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    days_from_birth INT NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO vaccines (name, days_from_birth) VALUES
('BCG', 0), ('OPV-0', 0), ('OPV-1', 42), ('Pentavalent-1', 42),
('PCV-1', 42), ('Rota-1', 42), ('OPV-2', 70), ('Pentavalent-2', 70),
('PCV-2', 70), ('Rota-2', 70), ('OPV-3', 98), ('Pentavalent-3', 98),
('PCV-3', 98), ('IPV', 98), ('MCV1', 274), ('MCV2', 456);

-- -------------------------------------------------
-- Inventory (Vaccine Batches)
-- -------------------------------------------------
CREATE TABLE inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vaccine_id INT NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    expiry_date DATE NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id),
    UNIQUE KEY uk_vaccine_batch (vaccine_id, batch_number),
    INDEX idx_expiry (expiry_date)
);

-- -------------------------------------------------
-- Appointments (Scheduled Vaccinations)
-- -------------------------------------------------
CREATE TABLE appointments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    child_id INT NOT NULL,
    vaccine_id INT NOT NULL,
    nurse_id INT NULL,
    scheduled_date DATE NOT NULL,
    status ENUM('pending','completed','missed','rescheduled','cancelled') DEFAULT 'pending',
    given_date DATE NULL,
    batch_number VARCHAR(100) NULL,
    notes TEXT,
    reschedule_request_date DATE NULL,
    reschedule_approved BOOLEAN DEFAULT FALSE,
    reminder_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id),
    FOREIGN KEY (nurse_id) REFERENCES users(id),
    INDEX idx_child (child_id),
    INDEX idx_scheduled (scheduled_date)
);

-- -------------------------------------------------
-- Nurse Assignments (Round‑Robin)
-- -------------------------------------------------
CREATE TABLE nurse_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    child_id INT NOT NULL UNIQUE,
    nurse_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES users(id),
    INDEX idx_nurse (nurse_id)
);

-- -------------------------------------------------
-- Notifications
-- -------------------------------------------------
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    child_id INT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('appointment_reminder','approval','stock_alert','expiry_alert','certificate_ready','report') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (child_id) REFERENCES children(id),
    INDEX idx_user_read (user_id, is_read)
);

-- -------------------------------------------------
-- Certificates
-- -------------------------------------------------
CREATE TABLE certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    child_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_approved_by_nurse BOOLEAN DEFAULT FALSE,
    is_approved_by_admin BOOLEAN DEFAULT FALSE,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
);

-- -------------------------------------------------
-- Audit Logs
-- -------------------------------------------------
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_action (action)
);

-- -------------------------------------------------
-- Reports
-- -------------------------------------------------
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('weekly','monthly') NOT NULL,
    generated_by INT NOT NULL,
    file_path VARCHAR(255) NULL,
    data JSON NULL,
    period_start DATE,
    period_end DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- -------------------------------------------------
-- Password Resets
-- -------------------------------------------------
CREATE TABLE password_resets (
    email VARCHAR(100) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Additional performance indexes
CREATE INDEX idx_appointments_status ON appointments(status);
CREATE INDEX idx_notifications_created ON notifications(created_at);
CREATE INDEX idx_inventory_expiry ON inventory(expiry_date);
CREATE INDEX idx_children_dob ON children(dob);