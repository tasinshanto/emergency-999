CREATE DATABASE IF NOT EXISTS emergency_999_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE emergency_999_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS Audit_Log;
DROP TABLE IF EXISTS Dispatch_Assignment;
DROP TABLE IF EXISTS Responder;
DROP TABLE IF EXISTS Emergency_Report;
DROP TABLE IF EXISTS Hospital;
DROP TABLE IF EXISTS Dispatch_Unit;
DROP TABLE IF EXISTS Emergency_Type;
DROP TABLE IF EXISTS Users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE Users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user', 'responder') NOT NULL DEFAULT 'user',
    address TEXT NULL,
    status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE Emergency_Type (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    priority_level ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    icon VARCHAR(40) NOT NULL DEFAULT 'alert',
    color VARCHAR(20) NOT NULL DEFAULT '#dc2626',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_emergency_type_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE Dispatch_Unit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_name VARCHAR(140) NOT NULL,
    unit_type ENUM('fire', 'ambulance', 'police', 'rescue', 'other') NOT NULL DEFAULT 'other',
    phone VARCHAR(30) NOT NULL,
    base_address TEXT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    status ENUM('available', 'busy', 'offline') NOT NULL DEFAULT 'available',
    capacity INT UNSIGNED NOT NULL DEFAULT 1,
    -- Tracks how many non-terminal assignments are currently active for this unit.
    -- Maintained automatically by DB triggers; do NOT update manually.
    active_assignments_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dispatch_unit_status (status),
    INDEX idx_dispatch_unit_type (unit_type)
) ENGINE=InnoDB;

CREATE TABLE Hospital (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    available_beds INT UNSIGNED NOT NULL DEFAULT 0,
    emergency_capacity INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('available', 'busy', 'offline') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hospital_status (status)
) ENGINE=InnoDB;

CREATE TABLE Emergency_Report (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    emergency_type_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    address TEXT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    requested_unit_types VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending', 'verified', 'assigned', 'dispatched', 'in_progress', 'resolved', 'rejected') NOT NULL DEFAULT 'pending',
    reported_by_name VARCHAR(120) NOT NULL,
    reported_by_phone VARCHAR(30) NOT NULL,
    verified_by INT UNSIGNED NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_report_user FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE SET NULL,
    CONSTRAINT fk_report_type FOREIGN KEY (emergency_type_id) REFERENCES Emergency_Type(id),
    CONSTRAINT fk_report_verified_by FOREIGN KEY (verified_by) REFERENCES Users(id) ON DELETE SET NULL,
    INDEX idx_report_status (status),
    INDEX idx_report_severity (severity),
    INDEX idx_report_type (emergency_type_id),
    INDEX idx_report_location (latitude, longitude)
) ENGINE=InnoDB;

CREATE TABLE Responder (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    dispatch_unit_id INT UNSIGNED NULL,
    designation VARCHAR(120) NOT NULL,
    badge_no VARCHAR(80) NOT NULL UNIQUE,
    specialization VARCHAR(160) NULL,
    availability_status ENUM('available', 'assigned', 'on_scene', 'offline') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_responder_user FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    CONSTRAINT fk_responder_unit FOREIGN KEY (dispatch_unit_id) REFERENCES Dispatch_Unit(id) ON DELETE SET NULL,
    INDEX idx_responder_status (availability_status),
    INDEX idx_responder_unit (dispatch_unit_id)
) ENGINE=InnoDB;

CREATE TABLE Dispatch_Assignment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emergency_report_id INT UNSIGNED NOT NULL,
    dispatch_unit_id INT UNSIGNED NULL,
    responder_id INT UNSIGNED NULL,
    assigned_by INT UNSIGNED NULL,
    assignment_status ENUM('assigned', 'accepted', 'arrived', 'completed', 'cancelled') NOT NULL DEFAULT 'assigned',
    instructions TEXT NULL,
    responder_notes TEXT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    arrived_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignment_report FOREIGN KEY (emergency_report_id) REFERENCES Emergency_Report(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_unit FOREIGN KEY (dispatch_unit_id) REFERENCES Dispatch_Unit(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_responder FOREIGN KEY (responder_id) REFERENCES Responder(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_admin FOREIGN KEY (assigned_by) REFERENCES Users(id) ON DELETE SET NULL,
    INDEX idx_assignment_status (assignment_status),
    INDEX idx_assignment_report (emergency_report_id)
) ENGINE=InnoDB;

-- ============================================================
-- AUDIT LOG TABLE
-- Populated by application code (includes/audit.php) on every
-- platform action. Records who changed what, when, and from where.
-- ============================================================
CREATE TABLE Audit_Log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type    VARCHAR(40)  NOT NULL,
    entity_type   VARCHAR(60)  NOT NULL,
    entity_id     INT UNSIGNED NULL,
    entity_label  VARCHAR(255) NULL,
    field_name    VARCHAR(80)  NULL,
    old_value     TEXT         NULL,
    new_value     TEXT         NULL,
    summary       VARCHAR(500) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_name    VARCHAR(120) NOT NULL,
    actor_role    VARCHAR(20)  NOT NULL DEFAULT 'system',
    ip_address    VARCHAR(45)  NULL,
    request_path  VARCHAR(255) NULL,
    changed_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity      (entity_type, entity_id),
    INDEX idx_audit_actor       (actor_user_id),
    INDEX idx_audit_changed_at  (changed_at),
    INDEX idx_audit_event       (event_type)
) ENGINE=InnoDB;

-- ============================================================
-- TRIGGERS
-- ============================================================

DELIMITER $$

-- ----------------------------------------------------------------
-- TRIGGER 1: after_dispatch_assignment_insert
-- When a new assignment is inserted:
--   • Increment the unit's active_assignments_count.
--   • If the count reaches the unit's capacity, mark it 'busy'.
--   • Mark the assigned responder as 'assigned'.
--   • Write an INSERT audit log entry.
-- ----------------------------------------------------------------
CREATE TRIGGER after_dispatch_assignment_insert
AFTER INSERT ON Dispatch_Assignment
FOR EACH ROW
BEGIN
    -- Update unit load counter and status
    IF NEW.dispatch_unit_id IS NOT NULL THEN
        UPDATE Dispatch_Unit
        SET active_assignments_count = active_assignments_count + 1,
            status = IF(active_assignments_count >= capacity, 'busy', 'available')
        WHERE id = NEW.dispatch_unit_id;
    END IF;

    -- Mark responder busy
    IF NEW.responder_id IS NOT NULL THEN
        UPDATE Responder
        SET availability_status = 'assigned'
        WHERE id = NEW.responder_id;
    END IF;

END$$

-- ----------------------------------------------------------------
-- TRIGGER 2: after_dispatch_assignment_update
-- When an existing assignment changes status:
--   • On 'completed' or 'cancelled':
--       – Decrement the unit's active_assignments_count (floor 0).
--       – If count falls below capacity, set unit back to 'available'.
--       – Mark the responder 'available'.
--   • On 'arrived': mark the responder 'on_scene'.
--   • Always write an UPDATE audit log entry when status changes.
-- ----------------------------------------------------------------
CREATE TRIGGER after_dispatch_assignment_update
AFTER UPDATE ON Dispatch_Assignment
FOR EACH ROW
BEGIN
    -- Only react when the status field actually changes
    IF OLD.assignment_status <> NEW.assignment_status THEN

        -- Terminal statuses: free up resources
        IF NEW.assignment_status IN ('completed', 'cancelled') THEN
            IF NEW.dispatch_unit_id IS NOT NULL THEN
                UPDATE Dispatch_Unit
                SET active_assignments_count = GREATEST(0, active_assignments_count - 1),
                    status = IF(active_assignments_count < capacity, 'available', 'busy')
                WHERE id = NEW.dispatch_unit_id;
            END IF;

            IF NEW.responder_id IS NOT NULL THEN
                UPDATE Responder SET availability_status = 'available' WHERE id = NEW.responder_id;
            END IF;

        -- Arrived at scene
        ELSEIF NEW.assignment_status = 'arrived' THEN
            IF NEW.responder_id IS NOT NULL THEN
                UPDATE Responder SET availability_status = 'on_scene' WHERE id = NEW.responder_id;
            END IF;
        END IF;

    END IF;
END$$

-- ----------------------------------------------------------------
-- TRIGGER 3: after_dispatch_assignment_delete
-- Handles cascade deletes: restore unit counts and responder status.
-- ----------------------------------------------------------------
CREATE TRIGGER after_dispatch_assignment_delete
AFTER DELETE ON Dispatch_Assignment
FOR EACH ROW
BEGIN
    -- Only free resources if the assignment was still active
    IF OLD.assignment_status IN ('assigned', 'accepted', 'arrived') THEN
        IF OLD.dispatch_unit_id IS NOT NULL THEN
            UPDATE Dispatch_Unit
            SET active_assignments_count = GREATEST(0, active_assignments_count - 1),
                status = IF(active_assignments_count < capacity, 'available', 'busy')
            WHERE id = OLD.dispatch_unit_id;
        END IF;

        IF OLD.responder_id IS NOT NULL THEN
            UPDATE Responder SET availability_status = 'available' WHERE id = OLD.responder_id;
        END IF;
    END IF;

END$$

-- ----------------------------------------------------------------
-- TRIGGER 4: before_dispatch_unit_update
-- Automatically manages the unit status based on capacity vs active count.
-- ----------------------------------------------------------------
CREATE TRIGGER before_dispatch_unit_update
BEFORE UPDATE ON Dispatch_Unit
FOR EACH ROW
BEGIN
    IF NEW.status <> 'offline' THEN
        IF NEW.active_assignments_count >= NEW.capacity THEN
            SET NEW.status = 'busy';
        ELSE
            SET NEW.status = 'available';
        END IF;
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Core users (password for all: "password")
INSERT INTO Users (id, name, email, phone, password_hash, role, address, status) VALUES
(1, 'System Admin', 'admin@999.local', '01700000001', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'admin', 'Emergency Control Room, Dhaka', 'active'),
(2, 'Sample Citizen', 'user@999.local', '01700000002', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'user', 'Gulshan, Dhaka', 'active'),
(3, 'Lt. Farhan Rahman', 'responder@999.local', '01700000003', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka', 'active');

INSERT INTO Emergency_Type (id, name, description, priority_level, icon, color, is_active) VALUES
(1, 'Fire', 'Structural fire, smoke, electrical fire, or explosion risk.', 'critical', 'flame', '#dc2626', 1),
(2, 'Medical', 'Medical emergency requiring ambulance or hospital coordination.', 'high', 'heart-pulse', '#16a34a', 1),
(3, 'Police', 'Public safety, violence, crime, or law enforcement incident.', 'high', 'shield', '#2563eb', 1),
(4, 'Rescue', 'Road accident, trapped person, flood, collapse, or rescue call.', 'critical', 'life-buoy', '#ea580c', 1);

-- Dispatch Units  (capacity = max simultaneous active assignments)
INSERT INTO Dispatch_Unit (id, unit_name, unit_type, phone, base_address, latitude, longitude, status, capacity, active_assignments_count) VALUES
(1,  'Tejgaon Fire Response Unit',    'fire',      '01710000001', 'Tejgaon Fire Station, Dhaka',       23.7644000, 90.3907000, 'available', 4,  0),
(2,  'Dhaka Metro Ambulance Unit',    'ambulance', '01710000002', 'Dhaka Medical College Hospital',    23.7259000, 90.3975000, 'available', 2,  0),
(3,  'Gulshan Police Patrol Unit',    'police',    '01710000003', 'Gulshan Police Station, Dhaka',     23.7925000, 90.4078000, 'available', 2,  0),
(4,  'Mirpur Fire Station Unit',      'fire',      '01710000004', 'Mirpur-1, Dhaka',                   23.8042000, 90.3663000, 'available', 1,  0),
(5,  'Uttara Fire Station Unit',      'fire',      '01710000005', 'Uttara Sector-3, Dhaka',            23.8740000, 90.3990000, 'busy',      0,  0),
(6,  'Chattogram Central Fire Unit',  'fire',      '01710000006', 'Agrabad, Chattogram',               22.3236000, 91.8100000, 'available', 1,  0),
(7,  'DMCH Ambulance-2',              'ambulance', '01710000007', 'Dhaka Medical College Hospital',    23.7259000, 90.3975000, 'busy',      0,  0),
(8,  'Shaheed Suhrawardy Ambulance',  'ambulance', '01710000008', 'Sher-e-Bangla Nagar, Dhaka',        23.7636000, 90.3750000, 'available', 1,  0),
(9,  'Uttara Crescent Ambulance',     'ambulance', '01710000009', 'Uttara Model Town, Dhaka',          23.8700000, 90.3950000, 'busy',      0,  0),
(10, 'Chattogram Medical Ambulance',  'ambulance', '01710000010', 'Chattogram Medical College',        22.3590000, 91.8318000, 'busy',      0,  0),
(11, 'Motijheel Police Patrol',       'police',    '01710000011', 'Motijheel, Dhaka',                  23.7333000, 90.4176000, 'available', 1,  0),
(12, 'Dhanmondi Police Patrol',       'police',    '01710000012', 'Dhanmondi-27, Dhaka',               23.7461000, 90.3742000, 'available', 1,  0),
(13, 'Kotwali Police Unit (CTG)',     'police',    '01710000013', 'Kotwali, Chattogram',               22.3340000, 91.8362000, 'busy',      0,  0),
(14, 'Dhaka Rescue-1 (FSCD)',         'rescue',    '01710000014', 'Sadarghat, Dhaka',                  23.7083000, 90.4070000, 'busy',      0,  0),
(15, 'Sylhet Rescue Unit',            'rescue',    '01710000015', 'Zindabazar, Sylhet',                24.8949000, 91.8687000, 'busy',      0,  0);

INSERT INTO Hospital (id, name, phone, address, latitude, longitude, available_beds, emergency_capacity, status) VALUES
(1, 'Dhaka Medical College Hospital',                     '02-55165088', 'Secretariat Road, Dhaka',      23.7259000, 90.3975000, 18, 40, 'available'),
(2, 'Kurmitola General Hospital',                         '02-55062388', 'Airport Road, Dhaka',           23.8223000, 90.4078000, 11, 25, 'available'),
(3, 'Shaheed Suhrawardy Medical College Hospital',        '02-58151079', 'Sher-e-Bangla Nagar, Dhaka',    23.7636000, 90.3750000, 22, 35, 'available'),
(4, 'National Institute of Traumatology (NITOR)',         '02-58610985', 'Sher-e-Bangla Nagar, Dhaka',    23.7617000, 90.3755000, 14, 30, 'available'),
(5, 'Sir Salimullah Medical College Hospital',            '02-7319002',  'Mitford Road, Dhaka',           23.7131000, 90.4000000,  9, 20, 'available'),
(6, 'Mugda General Hospital',                             '02-7692830',  'Mugda, Dhaka',                  23.7440000, 90.4350000, 16, 25, 'available'),
(7, 'Uttara Adhunik Medical College Hospital',            '02-58955390', 'Uttara Sector-4, Dhaka',        23.8690000, 90.3965000, 20, 30, 'available'),
(8, 'Bangabandhu Sheikh Mujib Medical University',        '02-8614001',  'Shahbagh, Dhaka',               23.7395000, 90.3960000, 30, 50, 'available'),
(9, 'Chattogram Medical College Hospital',                '031-630335',  'K.B. Fazlul Kader Rd, CTG',     22.3590000, 91.8318000, 25, 40, 'available'),
(10,'MAG Osmani Medical College Hospital',                '0821-714001', 'Medical College Road, Sylhet',   24.8980000, 91.8710000, 18, 30, 'available');

-- ============================================================
-- Additional Users: responders across multiple units
-- ============================================================
INSERT INTO Users (id, name, email, phone, password_hash, role, address, status) VALUES
-- Tejgaon Fire Response Unit (unit 1) — 3 extra responders
(4,  'Sgt. Razia Sultana',     'razia@999.local',   '01711000004', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka',        'active'),
(5,  'Cpl. Nusrat Jahan',      'nusrat@999.local',  '01711000005', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka',        'active'),
(6,  'FF. Jabir Hossain',      'jabir@999.local',   '01711000006', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka',        'active'),
-- Dhaka Metro Ambulance Unit (unit 2) — 2 extra responders
(7,  'Dr. Kamal Hossain',      'kamal@999.local',   '01711000007', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College, Dhaka',       'active'),
(8,  'Paramedic Shamim Akter', 'shamim@999.local',  '01711000008', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College, Dhaka',       'active'),
-- Gulshan Police Patrol Unit (unit 3) — 2 extra responders
(9,  'SI Rafiq Islam',         'rafiq@999.local',   '01711000009', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka',      'active'),
(10, 'ASI Tarek Mahmud',       'tarek@999.local',   '01711000010', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka',      'active'),
-- Mirpur Fire (unit 4)
(11, 'Lt. Jamal Uddin',        'jamal@999.local',   '01711000011', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka',         'active'),
-- Motijheel Police (unit 11)
(12, 'Cpl. Anwar Haque',       'anwar@999.local',   '01711000012', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station, Dhaka',   'active'),
-- Dhanmondi Police (unit 12)
(13, 'SI Fatema Khatun',       'fatema@999.local',  '01711000013', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station, Dhaka',   'active'),
-- Chattogram Fire (unit 6)
(14, 'Lt. Hasanul Bari',       'hasan@999.local',   '01711000014', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station',           'active'),
-- Suhrawardy Ambulance (unit 8)
(15, 'Paramedic Nasrin Akter', 'nasrin@999.local',  '01711000015', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sher-e-Bangla Nagar, Dhaka',        'active'),
-- Public users for demo reports
(16, 'Ayesha Begum',           'ayesha@999.local',  '01700000016', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'user', 'Banani, Dhaka',       'active'),
(17, 'Rahim Mia',              'rahim@999.local',   '01700000017', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'user', 'Mirpur-10, Dhaka',    'active'),
(18, 'Mizanur Rahman',         'mizan@999.local',   '01700000018', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'user', 'Sylhet City',         'active'),
-- Unit 1 new responders
(19, 'FF. Sajjad Hossein', 'sajjad@999.local', '01711000019', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka', 'active'),
(20, 'FF. Arifur Rahman', 'arif@999.local', '01711000020', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Tejgaon Fire Station, Dhaka', 'active'),
-- Unit 2 new responders
(21, 'Paramedic Tanveer Alam', 'tanveer@999.local', '01711000021', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College', 'active'),
(22, 'Paramedic Sadia Islam', 'sadia@999.local', '01711000022', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College', 'active'),
(23, 'Dr. Fahmida Yesmin', 'fahmida@999.local', '01711000023', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College', 'active'),
(24, 'Dr. Mahbubur Rahman', 'mahbub@999.local', '01711000024', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College', 'active'),
-- Unit 3 new responders
(25, 'Sgt. Imran Khan', 'imran@999.local', '01711000025', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka', 'active'),
(26, 'ASI Shamima Yasmin', 'shamimay@999.local', '01711000026', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka', 'active'),
(27, 'SI Tariqul Islam', 'tariqul@999.local', '01711000027', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka', 'active'),
(28, 'ASI Rashedul Bari', 'rashed@999.local', '01711000028', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Gulshan Police Station, Dhaka', 'active'),
-- Unit 4 new responders
(29, 'FF. Selim Rezwan', 'selim@999.local', '01711000029', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka', 'active'),
(30, 'FF. Mainul Islam', 'mainul@999.local', '01711000030', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka', 'active'),
(31, 'FF. Riaz Uddin', 'riaz@999.local', '01711000031', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka', 'active'),
(32, 'FF. Tariq Anam', 'tariqa@999.local', '01711000032', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka', 'active'),
(33, 'FF. Shakil Ahmed', 'shakil@999.local', '01711000033', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Mirpur Fire Station, Dhaka', 'active'),
-- Unit 5 new responders
(34, 'Lt. Nazmul Huda', 'nazmul@999.local', '01711000034', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
(35, 'Sgt. Mizanur Rahman', 'mizanr@999.local', '01711000035', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
(36, 'FF. Kabir Hossain', 'kabir@999.local', '01711000036', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
(37, 'FF. Shafiul Alam', 'shafiul@999.local', '01711000037', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
(38, 'FF. Rubel Mia', 'rubel@999.local', '01711000038', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
(39, 'FF. Masud Rana', 'masud@999.local', '01711000039', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Fire Station, Dhaka', 'active'),
-- Unit 6 new responders
(40, 'Sgt. Biplob Kumar', 'biplob@999.local', '01711000040', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station', 'active'),
(41, 'FF. Anisur Rahman', 'anis@999.local', '01711000041', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station', 'active'),
(42, 'FF. Zahirul Islam', 'zahir@999.local', '01711000042', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station', 'active'),
(43, 'FF. Didarul Alam', 'didar@999.local', '01711000043', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station', 'active'),
(44, 'FF. Saiful Islam', 'saiful@999.local', '01711000044', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Fire Station', 'active'),
-- Unit 7 new responders
(45, 'Dr. Nazma Begum', 'nazma@999.local', '01711000045', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
(46, 'Paramedic Liton Mia', 'liton@999.local', '01711000046', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
(47, 'Dr. Rubaiya Tasnim', 'rubaiya@999.local', '01711000047', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
(48, 'Paramedic Belal Hossain', 'belal@999.local', '01711000048', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
(49, 'Dr. Asif Iqbal', 'asifi@999.local', '01711000049', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
(50, 'Paramedic Salma Khatun', 'salmak@999.local', '01711000050', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhaka Medical College Hospital', 'active'),
-- Unit 8 new responders
(51, 'Dr. Shahriar Kabir', 'shahriar@999.local', '01711000051', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Suhrawardy Hospital', 'active'),
(52, 'Paramedic Nigar Sultana', 'nigar@999.local', '01711000052', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Suhrawardy Hospital', 'active'),
(53, 'Dr. Rashed Ahmed', 'rasheda@999.local', '01711000053', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Suhrawardy Hospital', 'active'),
(54, 'Paramedic Jewel Rana', 'jewel@999.local', '01711000054', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Suhrawardy Hospital', 'active'),
(55, 'Dr. Farzana Yasmin', 'farzana@999.local', '01711000055', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Suhrawardy Hospital', 'active'),
-- Unit 9 new responders
(56, 'Dr. Munir Chowdhury', 'munir@999.local', '01711000056', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
(57, 'Paramedic Halima Sadia', 'halima@999.local', '01711000057', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
(58, 'Dr. Tareq Hasan', 'tareqh@999.local', '01711000058', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
(59, 'Paramedic Rafiqul Islam', 'rafiquli@999.local', '01711000059', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
(60, 'Dr. Sabiha Jahan', 'sabiha@999.local', '01711000060', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
(61, 'Paramedic Aminul Haque', 'aminul@999.local', '01711000061', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Uttara Model Town', 'active'),
-- Unit 10 new responders
(62, 'Dr. Tanvir Hasan', 'tanvirh@999.local', '01711000062', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
(63, 'Paramedic Shirin Akter', 'shirina@999.local', '01711000063', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
(64, 'Dr. Mahmudul Hasan', 'mahmudul@999.local', '01711000064', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
(65, 'Paramedic Alamgir Kabir', 'alamgir@999.local', '01711000065', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
(66, 'Dr. Rehana Sultana', 'rehana@999.local', '01711000066', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
(67, 'Paramedic Jahir Uddin', 'jahiru@999.local', '01711000067', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Chattogram Medical College', 'active'),
-- Unit 11 new responders
(68, 'Sgt. Abu Bakar', 'abubakar@999.local', '01711000068', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station', 'active'),
(69, 'ASI Mukta Begum', 'muktab@999.local', '01711000069', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station', 'active'),
(70, 'SI Delwar Hossain', 'delwar@999.local', '01711000070', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station', 'active'),
(71, 'ASI Ripon Mia', 'riponm@999.local', '01711000071', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station', 'active'),
(72, 'SI Farhana Akter', 'farhanaa@999.local', '01711000072', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Motijheel Police Station', 'active'),
-- Unit 12 new responders
(73, 'Sgt. Monirul Islam', 'monirul@999.local', '01711000073', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station', 'active'),
(74, 'ASI Kaniz Fatima', 'kanizf@999.local', '01711000074', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station', 'active'),
(75, 'SI Mahfuzur Rahman', 'mahfuzr@999.local', '01711000075', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station', 'active'),
(76, 'ASI Sohel Rana', 'sohelr@999.local', '01711000076', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station', 'active'),
(77, 'SI Taslima Begum', 'taslimab@999.local', '01711000077', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Dhanmondi Police Station', 'active'),
-- Unit 13 new responders
(78, 'SI Mominul Haque', 'mominul@999.local', '01711000078', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
(79, 'ASI Jesmin Ara', 'jesmin@999.local', '01711000079', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
(80, 'Sgt. Ziaur Rahman', 'ziaur@999.local', '01711000080', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
(81, 'ASI Babul Mia', 'babul@999.local', '01711000081', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
(82, 'SI Rokeya Begum', 'rokeya@999.local', '01711000082', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
(83, 'ASI Masum Billah', 'masumb@999.local', '01711000083', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Kotwali, Chattogram', 'active'),
-- Unit 14 new responders
(84, 'Lt. Shafiqul Islam', 'shafiqul@999.local', '01711000084', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
(85, 'Sgt. Rubel Mia', 'rubelm@999.local', '01711000085', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
(86, 'FF. Aminul Islam', 'aminuli@999.local', '01711000086', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
(87, 'FF. Habibur Rahman', 'habibur@999.local', '01711000087', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
(88, 'FF. Nazim Uddin', 'nazim@999.local', '01711000088', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
(89, 'FF. Murad Hossain', 'murad@999.local', '01711000089', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Sadarghat, Dhaka', 'active'),
-- Unit 15 new responders
(90, 'Lt. Tanvir Ahmed', 'tanvira@999.local', '01711000090', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active'),
(91, 'Sgt. Lutfur Rahman', 'lutfur@999.local', '01711000091', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active'),
(92, 'FF. Shakil Khan', 'shakilk@999.local', '01711000092', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active'),
(93, 'FF. Mizanur Rahman', 'mizanm@999.local', '01711000093', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active'),
(94, 'FF. Jamil Hasan', 'jamil@999.local', '01711000094', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active'),
(95, 'FF. Sohel Rana', 'sohelra@999.local', '01711000095', '$2y$10$z3L.Hhm4q9bNmaMMBaXEC.Cai.NOXMkE2bOhhbcFFwFO6QK4kpz/q', 'responder', 'Zindabazar, Sylhet', 'active');

-- ============================================================
-- Responders (linked to Units)
-- ============================================================
INSERT INTO Responder (id, user_id, dispatch_unit_id, designation, badge_no, specialization, availability_status) VALUES
-- Tejgaon Fire Unit (id 1) — 6 responders
(1,  3,  1,  'Fire Response Lead',         'FS-DHK-001', 'Urban fire suppression',          'available'),
(2,  4,  1,  'Fire Response Officer',      'FS-DHK-002', 'Structural rescue & suppression', 'available'),
(3,  5,  1,  'Fire Response Officer',      'FS-DHK-003', 'High-rise fire rescue',           'available'),
(4,  6,  1,  'Firefighter',                'FS-DHK-004', 'Hazmat & chemical incidents',     'available'),
(14, 19, 1,  'Firefighter',                'FS-DHK-006', 'Hazmat & rescue',                 'available'),
(15, 20, 1,  'Firefighter',                'FS-DHK-007', 'Urban search & rescue',            'available'),
-- Dhaka Metro Ambulance Unit (id 2) — 6 responders
(5,  7,  2,  'Emergency Medical Officer',  'EMO-DHK-001','Trauma and cardiac emergencies',  'available'),
(6,  8,  2,  'Senior Paramedic',           'EMO-DHK-002','Pre-hospital trauma care',         'available'),
(16, 21, 2,  'Paramedic',                  'EMO-DHK-004','Cardiac care',                    'available'),
(17, 22, 2,  'Paramedic',                  'EMO-DHK-005','Pediatric emergencies',            'available'),
(18, 23, 2,  'Emergency Medical Officer',  'EMO-DHK-006','Emergency medicine',              'available'),
(19, 24, 2,  'Emergency Medical Officer',  'EMO-DHK-007','Trauma surgery',                  'available'),
-- Gulshan Police Patrol Unit (id 3) — 6 responders
(7,  9,  3,  'Sub-Inspector',              'PS-DHK-001', 'Crime scene & crowd control',     'available'),
(8,  10, 3,  'Assistant Sub-Inspector',    'PS-DHK-002', 'Traffic & accident response',     'available'),
(20, 25, 3,  'Police Sergeant',            'PS-DHK-005', 'Criminal investigation',          'available'),
(21, 26, 3,  'Assistant Sub-Inspector',    'PS-DHK-006', 'Public order & safety',           'available'),
(22, 27, 3,  'Sub-Inspector',              'PS-DHK-007', 'Counter terrorism',               'available'),
(23, 28, 3,  'Assistant Sub-Inspector',    'PS-DHK-008', 'Traffic management',              'available'),
-- Mirpur Fire Station Unit (id 4) — 6 responders
(9,  11, 4,  'Fire Response Officer',      'FS-DHK-005', 'Industrial fire suppression',     'available'),
(24, 29, 4,  'Firefighter',                'FS-DHK-008', 'Structural fires',                'available'),
(25, 30, 4,  'Firefighter',                'FS-DHK-009', 'Chemical hazmat',                 'available'),
(26, 31, 4,  'Firefighter',                'FS-DHK-010', 'High rise rescue',                'available'),
(27, 32, 4,  'Firefighter',                'FS-DHK-011', 'Electrical fire rescue',          'available'),
(28, 33, 4,  'Firefighter',                'FS-DHK-012', 'Water rescue operations',         'available'),
-- Uttara Fire Station Unit (id 5) — 6 responders
(29, 34, 5,  'Fire Response Lead',         'FS-DHK-013', 'Lead officer & logistics',        'available'),
(30, 35, 5,  'Fire Response Officer',      'FS-DHK-014', 'Structural fire safety',          'available'),
(31, 36, 5,  'Firefighter',                'FS-DHK-015', 'Search and rescue',               'available'),
(32, 37, 5,  'Firefighter',                'FS-DHK-016', 'First response medical',          'available'),
(33, 38, 5,  'Firefighter',                'FS-DHK-017', 'Hazmat cleanup',                  'available'),
(34, 39, 5,  'Firefighter',                'FS-DHK-018', 'Forest & grass fires',            'available'),
-- Chattogram Central Fire Unit (id 6) — 6 responders
(12, 14, 6,  'Fire Response Lead',         'FS-CTG-001', 'Port area fire operations',       'available'),
(35, 40, 6,  'Fire Response Officer',      'FS-CTG-002', 'Port fires',                      'available'),
(36, 41, 6,  'Firefighter',                'FS-CTG-003', 'Marine rescue',                   'available'),
(37, 42, 6,  'Firefighter',                'FS-CTG-004', 'Chemical container fire',         'available'),
(38, 43, 6,  'Firefighter',                'FS-CTG-005', 'Industrial fire safety',          'available'),
(39, 44, 6,  'Firefighter',                'FS-CTG-006', 'Heavy vehicle rescue',            'available'),
-- DMCH Ambulance-2 (id 7) — 6 responders
(40, 45, 7,  'Emergency Medical Officer',  'EMO-DHK-008','Triage & trauma',                 'available'),
(41, 46, 7,  'Senior Paramedic',           'EMO-DHK-009','Pre-hospital life support',       'available'),
(42, 47, 7,  'Emergency Medical Officer',  'EMO-DHK-010','Emergency medicine',              'available'),
(43, 48, 7,  'Paramedic',                  'EMO-DHK-011','Critical care transport',          'available'),
(44, 49, 7,  'Emergency Medical Officer',  'EMO-DHK-012','Neonatal emergency',              'available'),
(45, 50, 7,  'Paramedic',                  'EMO-DHK-013','Advanced first aid',              'available'),
-- Shaheed Suhrawardy Ambulance (id 8) — 6 responders
(13, 15, 8,  'Senior Paramedic',           'EMO-DHK-003','Pre-hospital critical care',       'available'),
(46, 51, 8,  'Emergency Medical Officer',  'EMO-DHK-014','Critical cardiac care',           'available'),
(47, 52, 8,  'Paramedic',                  'EMO-DHK-015','Trauma stabilization',            'available'),
(48, 53, 8,  'Emergency Medical Officer',  'EMO-DHK-016','Respiratory emergencies',          'available'),
(49, 54, 8,  'Paramedic',                  'EMO-DHK-017','Splinting & immobilization',      'available'),
(50, 55, 8,  'Emergency Medical Officer',  'EMO-DHK-018','Emergency pediatrics',            'available'),
-- Uttara Crescent Ambulance (id 9) — 6 responders
(51, 56, 9,  'Emergency Medical Officer',  'EMO-DHK-019','Emergency medicine',              'available'),
(52, 57, 9,  'Senior Paramedic',           'EMO-DHK-020','Advanced cardiac life support',   'available'),
(53, 58, 9,  'Emergency Medical Officer',  'EMO-DHK-021','Trauma & resuscitation',          'available'),
(54, 59, 9,  'Paramedic',                  'EMO-DHK-022','Emergency patient handling',      'available'),
(55, 60, 9,  'Emergency Medical Officer',  'EMO-DHK-023','Toxicology & poisoning',          'available'),
(56, 61, 9,  'Paramedic',                  'EMO-DHK-024','Airway management',                'available'),
-- Chattogram Medical Ambulance (id 10) — 6 responders
(57, 62, 10, 'Emergency Medical Officer',  'EMO-CTG-001','Emergency medicine',              'available'),
(58, 63, 10, 'Senior Paramedic',           'EMO-CTG-002','Trauma assessment',               'available'),
(59, 64, 10, 'Emergency Medical Officer',  'EMO-CTG-003','Cardiac life support',             'available'),
(60, 65, 10, 'Paramedic',                  'EMO-CTG-004','Skeletal trauma stabilization',   'available'),
(61, 66, 10, 'Emergency Medical Officer',  'EMO-CTG-005','Toxicological emergencies',       'available'),
(62, 67, 10, 'Paramedic',                  'EMO-CTG-006','Advanced airway support',          'available'),
-- Motijheel Police Patrol (id 11) — 6 responders
(10, 12, 11, 'Police Sub-Inspector',       'PS-DHK-003', 'Crime scene investigation',       'available'),
(63, 68, 11, 'Police Sergeant',            'PS-DHK-009', 'Riot control',                    'available'),
(64, 69, 11, 'Assistant Sub-Inspector',    'PS-DHK-010', 'Crime prevention',                'available'),
(65, 70, 11, 'Sub-Inspector',              'PS-DHK-011', 'Hostage negotiation',             'available'),
(66, 71, 11, 'Assistant Sub-Inspector',    'PS-DHK-012', 'Vip protection',                  'available'),
(67, 72, 11, 'Sub-Inspector',              'PS-DHK-013', 'Cyber investigation',             'available'),
-- Dhanmondi Police Patrol (id 12) — 6 responders
(11, 13, 12, 'Police Sub-Inspector',       'PS-DHK-004', 'Community policing',              'available'),
(68, 73, 12, 'Police Sergeant',            'PS-DHK-014', 'Patrol operations',               'available'),
(69, 74, 12, 'Assistant Sub-Inspector',    'PS-DHK-015', 'Juvenile justice',                'available'),
(70, 75, 12, 'Sub-Inspector',              'PS-DHK-016', 'Narcotics control',               'available'),
(71, 76, 12, 'Assistant Sub-Inspector',    'PS-DHK-017', 'Traffic accident investigation',  'available'),
(72, 77, 12, 'Sub-Inspector',              'PS-DHK-018', 'Public relations & safety',       'available'),
-- Kotwali Police Unit (CTG) (id 13) — 6 responders
(73, 78, 13, 'Sub-Inspector',              'PS-CTG-001', 'Port security & patrol',          'available'),
(74, 79, 13, 'Assistant Sub-Inspector',    'PS-CTG-002', 'Domestic violence response',      'available'),
(75, 80, 13, 'Police Sergeant',            'PS-CTG-003', 'Crowd management',                'available'),
(76, 81, 13, 'Assistant Sub-Inspector',    'PS-CTG-004', 'Patrol officer',                  'available'),
(77, 82, 13, 'Sub-Inspector',              'PS-CTG-005', 'Counter-narcotics',               'available'),
(78, 83, 13, 'Assistant Sub-Inspector',    'PS-CTG-006', 'First aid & rescue',              'available'),
-- Dhaka Rescue-1 (FSCD) (id 14) — 6 responders
(79, 84, 14, 'Rescue Lead Officer',        'RC-DHK-001', 'Water rescue lead',               'available'),
(80, 85, 14, 'Rescue Specialist',          'RC-DHK-002', 'Deep diving rescue',              'available'),
(81, 86, 14, 'Rescuer',                    'RC-DHK-003', 'Flood disaster rescue',           'available'),
(82, 87, 14, 'Rescuer',                    'RC-DHK-004', 'Boat capsize operations',         'available'),
(83, 88, 14, 'Rescuer',                    'RC-DHK-005', 'Collapsed building rescue',       'available'),
(84, 89, 14, 'Rescuer',                    'RC-DHK-006', 'Hazmat rescue operations',         'available'),
-- Sylhet Rescue Unit (id 15) — 6 responders
(85, 90, 15, 'Rescue Lead Officer',        'RC-SYL-001', 'Flash flood operations',          'available'),
(86, 91, 15, 'Rescue Specialist',          'RC-SYL-002', 'Mountain search & rescue',        'available'),
(87, 92, 15, 'Rescuer',                    'RC-SYL-003', 'Landslide rescue operations',     'available'),
(88, 93, 15, 'Rescuer',                    'RC-SYL-004', 'Emergency medical aid',            'available'),
(89, 94, 15, 'Rescuer',                    'RC-SYL-005', 'High-altitude rescue',            'available'),
(90, 95, 15, 'Rescuer',                    'RC-SYL-006', 'Flood relief & rescue',            'available');

-- ============================================================
-- Emergency Reports
-- ============================================================
INSERT INTO Emergency_Report
    (id, user_id, emergency_type_id, title, description, address, latitude, longitude, severity, status, reported_by_name, reported_by_phone, verified_by)
VALUES
(1,  2,  1, 'Demo Fire Incident – Gulshan',
    'Live demo fire incident for map monitoring and dispatch workflow.',
    'Gulshan Avenue, Dhaka', 23.7925000, 90.4078000, 'critical', 'in_progress',
    'Sample Citizen', '01700000002', 1),
(2,  16, 1, 'Garment Factory Fire – Mirpur',
    'Smoke seen from upper floors of a garment building near Mirpur-11. Workers evacuating.',
    'Mirpur-11, Dhaka', 23.8190000, 90.3660000, 'critical', 'dispatched',
    'Ayesha Begum', '01700000016', 1),
(3,  17, 1, 'Kitchen Fire – Banani Restaurant',
    'Gas cylinder leak caused fire in a restaurant kitchen. Customers evacuated safely.',
    'Banani-11, Dhaka', 23.7940000, 90.4028000, 'high', 'in_progress',
    'Rahim Mia', '01700000017', 1),
(4,  16, 2, 'Road Accident Victim – Farmgate',
    'Motorcycle collision at Farmgate intersection. One person unconscious with head injury.',
    'Farmgate, Dhaka', 23.7570000, 90.3890000, 'critical', 'dispatched',
    'Ayesha Begum', '01700000016', 1),
(5,  17, 2, 'Elderly Collapse – Dhanmondi',
    'An elderly man collapsed near Dhanmondi Lake. Possible cardiac arrest. Bystanders providing CPR.',
    'Dhanmondi-8, Dhaka', 23.7450000, 90.3730000, 'critical', 'in_progress',
    'Rahim Mia', '01700000017', 1),
(6,  18, 2, 'Pregnant Woman Emergency – Sylhet',
    'Pregnant woman in labor with complications. Needs immediate hospital transfer.',
    'Zindabazar, Sylhet', 24.8960000, 91.8700000, 'high', 'verified',
    'Mizanur Rahman', '01700000018', 1),
(7,  16, 3, 'Armed Robbery – Motijheel',
    'Two armed robbers entered a jewelry shop at Motijheel. Staff held at knifepoint.',
    'Motijheel C/A, Dhaka', 23.7340000, 90.4180000, 'critical', 'dispatched',
    'Ayesha Begum', '01700000016', 1),
(8,  17, 3, 'Street Mugging – Uttara',
    'A woman was mugged near Uttara Sector-7. Suspect fled towards Jasimuddin Road.',
    'Uttara Sector-7, Dhaka', 23.8680000, 90.3980000, 'high', 'verified',
    'Rahim Mia', '01700000017', 1),
(9,  18, 4, 'Building Collapse – Chattogram',
    'Partial collapse of an under-construction building in Agrabad. Workers may be trapped.',
    'Agrabad, Chattogram', 22.3250000, 91.8110000, 'critical', 'in_progress',
    'Mizanur Rahman', '01700000018', 1),
(10, 16, 4, 'Flash Flood Rescue – Sylhet',
    'Several families stranded on rooftops in Sunamganj Road area. Water level rising fast.',
    'Sunamganj Road, Sylhet', 24.9010000, 91.8600000, 'critical', 'verified',
    'Ayesha Begum', '01700000016', 1),
(11, 17, 4, 'Boat Capsize – Sadarghat',
    'A passenger launch capsized near Sadarghat terminal. Approximately 15 passengers in the water.',
    'Sadarghat, Dhaka', 23.7083000, 90.4070000, 'critical', 'dispatched',
    'Rahim Mia', '01700000017', 1),
(12, 16, 1, 'Electrical Fire – Mohakhali',
    'Transformer explosion caused fire in roadside shops at Mohakhali bus stand area.',
    'Mohakhali, Dhaka', 23.7780000, 90.4050000, 'high', 'verified',
    'Ayesha Begum', '01700000016', 1),
(13, 18, 2, 'Factory Worker Injury – CTG EPZ',
    'Worker caught in machinery at EPZ factory. Severe leg injury. On-site first aid applied.',
    'EPZ, Chattogram', 22.3450000, 91.7900000, 'high', 'dispatched',
    'Mizanur Rahman', '01700000018', 1),
-- Extra pending reports for live demo / judges
(14, 2,  1, 'Warehouse Fire – Keraniganj',
    'Large fire broke out in a chemical warehouse on the Buriganga riverbank.',
    'Keraniganj, Dhaka', 23.7010000, 90.3810000, 'critical', 'pending',
    'Sample Citizen', '01700000002', NULL),
(15, 2,  2, 'Drowning Incident – Hatirjheel',
    'A teenager fell into Hatirjheel lake and is struggling. Bystanders unable to help.',
    'Hatirjheel, Dhaka', 23.7611000, 90.4120000, 'high', 'pending',
    'Sample Citizen', '01700000002', NULL);

-- ============================================================
-- Dispatch Assignments
-- NOTE: We INSERT here with status already set correctly so
-- the AFTER INSERT trigger will handle unit/responder updates
-- automatically. We do NOT manually UPDATE Dispatch_Unit or
-- Responder statuses after this block.
-- ============================================================
INSERT INTO Dispatch_Assignment
    (id, emergency_report_id, dispatch_unit_id, responder_id, assigned_by, assignment_status, instructions, responder_notes, accepted_at, arrived_at)
VALUES
(1,  1,  1,  1, 1, 'arrived',  'Contain the fire, secure nearby buildings, update control room.', 'Crew arrived and checking entry points.',        NOW(), NOW()),
(2,  2,  4,  9, 1, 'accepted', 'Rush to Mirpur-11 garment area. Coordinate with factory security.','En route with full crew.',                      NOW(), NULL),
(3,  3,  1,  2, 1, 'arrived',  'Kitchen fire at Banani restaurant. Ensure gas supply is shut off.', 'On scene. Gas line closed.',                  NOW(), NOW()),
(4,  4,  8, 13, 1, 'accepted', 'Farmgate accident. Stabilize victim and transport to nearest hospital.','Ambulance en route.',                      NOW(), NULL),
(5,  5,  2,  5, 1, 'arrived',  'Elderly cardiac case at Dhanmondi. Bring defibrillator.',           'Patient stabilized, heading to BSMMU.',       NOW(), NOW()),
(6,  7,  11,10, 1, 'accepted', 'Armed robbery in progress at Motijheel. Approach with caution.',    'Patrol unit dispatched.',                      NOW(), NULL),
(7,  9,  6,  12,1, 'arrived',  'Building collapse at Agrabad CTG. Heavy rescue equipment needed.',  'Rescue ops underway. 2 workers extracted.',    NOW(), NOW()),
(8,  11, 14,NULL,1,'assigned', 'Boat capsize at Sadarghat. Deploy rescue divers immediately.',      NULL,                                           NULL, NULL),
(9,  13, 10,NULL,1,'accepted', 'Factory injury at CTG EPZ. Transport victim to CTG Medical College.','Ambulance heading to EPZ.',                   NOW(), NULL);
