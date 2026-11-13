-- Database Schema for StudentShield (Normalized Version)

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role TINYINT(1) DEFAULT 0 COMMENT '0=Student, 1=Counselor, 2=Super Admin, 3=Principal',
    remember_token VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    registration_status VARCHAR(12) NOT NULL DEFAULT 'approved',
    access_disabled TINYINT(1) NOT NULL DEFAULT 0,
    auth_version INT NOT NULL DEFAULT 1,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    user_id INT PRIMARY KEY,
    student_id VARCHAR(50) UNIQUE,
    lrn VARCHAR(12) NULL UNIQUE,
    parent_phone VARCHAR(50),
    parent_email VARCHAR(255),
    section VARCHAR(100),
    adviser_name VARCHAR(255),
    grade_level VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS guardian_consents (
    user_id INT NOT NULL PRIMARY KEY,
    verified_by INT NOT NULL,
    phone VARCHAR(13) NOT NULL,
    verified_name VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NULL UNIQUE,
    notice TEXT NOT NULL,
    requested_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    sms_state VARCHAR(24) NOT NULL,
    response VARCHAR(10) NULL,
    guardian_name VARCHAR(120) NULL,
    responded_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS guardian_alert_routes (
    user_id INT NOT NULL PRIMARY KEY,
    unsafe_guardian TINYINT(1) NOT NULL DEFAULT 0,
    alternate_name VARCHAR(120) NULL,
    alternate_phone VARCHAR(13) NULL,
    alternate_email VARCHAR(254) NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS counselors (
    user_id INT PRIMARY KEY,
    counselor_email VARCHAR(255),
    counselor_phone VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admins (
    user_id INT PRIMARY KEY,
    email VARCHAR(255),
    phone VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vent_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    raw_text TEXT NOT NULL,
    emotions VARCHAR(255),
    analysis_details TEXT NULL,
    risk_percent DECIMAL(5,2),
    risk_level VARCHAR(50),
    trajectory VARCHAR(50) DEFAULT 'INSUFFICIENT_DATA',
    escalation_probability DECIMAL(5,4) DEFAULT 0.0000,
    status ENUM('Pending', 'Reviewed', 'Flagged') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bully_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_student_id INT,
    reporter_type ENUM('self', 'bystander') DEFAULT 'self',
    victim_student_id INT NULL,
    bully_student_id INT NULL,
    incident_details TEXT,
    evidence_file VARCHAR(255),
    status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (reporter_student_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (victim_student_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (bully_student_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sms_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    parent_number VARCHAR(50) NOT NULL,
    alert_type VARCHAR(50) NOT NULL,
    message_content TEXT NOT NULL,
    sent_status VARCHAR(50) DEFAULT 'pending',
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    alert_type VARCHAR(50) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message_content TEXT NOT NULL,
    sent_status VARCHAR(50) DEFAULT 'pending',
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS counselor_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    counselor_id INT NOT NULL,
    note_type ENUM('observation', 'recommendation', 'intervention', 'follow_up', 'general') DEFAULT 'general',
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS case_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    status ENUM('active_monitoring', 'intervention_ongoing', 'parent_consultation_required', 'escalated', 'under_investigation', 'resolved', 'closed') NOT NULL,
    remarks TEXT NULL,
    changed_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS virtual_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    counselor_id INT NULL,
    meet_link VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'One Google Meet URL reserved for this counseling session',
    status VARCHAR(50) NOT NULL DEFAULT 'requested',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
);
