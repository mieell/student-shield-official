-- StudentShield account schema migration for the existing MariaDB database.
-- Import in phpMyAdmin during maintenance, then deploy the matching PHP code
-- before reopening the site. Verify a full export on a test database first.
-- Keep the legacy profile tables and users.role for post-deploy reconciliation.
-- DDL commits separately. After a failure, fix the reported constraint and retry.

CREATE TABLE IF NOT EXISTS roles (
    id TINYINT NOT NULL PRIMARY KEY,
    name VARCHAR(32) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO roles (id, name) VALUES
    (0, 'Student'), (1, 'Counselor'), (2, 'Super Admin'), (3, 'Principal')
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role_id TINYINT NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS lrn VARCHAR(12) NULL,
    ADD COLUMN IF NOT EXISTS grade_level VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS section VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS adviser_name VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS parent_email VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE audit_logs
    MODIFY COLUMN user_id INT NULL,
    ADD COLUMN IF NOT EXISTS legacy_user_id INT NULL;

ALTER TABLE virtual_meetings
    ENGINE=InnoDB,
    MODIFY COLUMN student_id INT NULL,
    MODIFY COLUMN counselor_id INT NULL,
    ADD COLUMN IF NOT EXISTS legacy_student_id INT NULL,
    ADD COLUMN IF NOT EXISTS legacy_counselor_id INT NULL;

START TRANSACTION;

UPDATE users u
JOIN roles r ON r.id = u.role
LEFT JOIN students s ON s.user_id = u.id
LEFT JOIN counselors c ON c.user_id = u.id
LEFT JOIN admins a ON a.user_id = u.id
SET u.role_id = r.id,
    u.email = CASE WHEN u.role = 1 THEN COALESCE(c.counselor_email, a.email)
                   ELSE COALESCE(a.email, c.counselor_email) END,
    u.phone = CASE WHEN u.role = 1 THEN COALESCE(c.counselor_phone, a.phone)
                   ELSE COALESCE(a.phone, c.counselor_phone) END,
    u.student_id = s.student_id,
    u.lrn = s.lrn,
    u.grade_level = s.grade_level,
    u.section = s.section,
    u.adviser_name = s.adviser_name,
    u.parent_phone = s.parent_phone,
    u.parent_email = s.parent_email,
    u.updated_at = COALESCE(s.updated_at, c.updated_at, a.updated_at)
WHERE u.role_id IS NULL;

-- Preserve original IDs for historical references whose accounts are gone.
UPDATE audit_logs l
LEFT JOIN users actor ON actor.id = l.user_id
SET l.legacy_user_id = l.user_id, l.user_id = NULL
WHERE l.user_id IS NOT NULL AND actor.id IS NULL;

UPDATE virtual_meetings m
LEFT JOIN users student ON student.id = m.student_id
SET m.legacy_student_id = m.student_id, m.student_id = NULL
WHERE m.student_id IS NOT NULL AND student.id IS NULL;

UPDATE virtual_meetings m
LEFT JOIN users counselor ON counselor.id = m.counselor_id
SET m.legacy_counselor_id = m.counselor_id, m.counselor_id = NULL
WHERE m.counselor_id IS NOT NULL AND counselor.id IS NULL;

COMMIT;

ALTER TABLE users
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_student_id (student_id),
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_lrn (lrn),
    ADD CONSTRAINT fk_users_roles FOREIGN KEY IF NOT EXISTS fk_users_roles (role_id) REFERENCES roles(id);

ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_logs_actor FOREIGN KEY IF NOT EXISTS fk_audit_logs_actor (user_id)
        REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE virtual_meetings
    ADD CONSTRAINT fk_virtual_meetings_student FOREIGN KEY IF NOT EXISTS fk_virtual_meetings_student (student_id)
        REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_virtual_meetings_counselor FOREIGN KEY IF NOT EXISTS fk_virtual_meetings_counselor (counselor_id)
        REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE bully_reports
    MODIFY COLUMN reporter_student_id INT NULL,
    MODIFY COLUMN victim_student_id INT NULL,
    MODIFY COLUMN bully_student_id INT NULL,
    ADD CONSTRAINT fk_bully_reports_reporter FOREIGN KEY IF NOT EXISTS fk_bully_reports_reporter (reporter_student_id)
        REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_bully_reports_victim FOREIGN KEY IF NOT EXISTS fk_bully_reports_victim (victim_student_id)
        REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_bully_reports_respondent FOREIGN KEY IF NOT EXISTS fk_bully_reports_respondent (bully_student_id)
        REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE case_status_history ENGINE=InnoDB;
ALTER TABLE case_status_history
    ADD CONSTRAINT fk_case_status_history_student FOREIGN KEY IF NOT EXISTS fk_case_status_history_student (student_id)
        REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_case_status_history_actor FOREIGN KEY IF NOT EXISTS fk_case_status_history_actor (changed_by)
        REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE counselor_notes ENGINE=InnoDB;
ALTER TABLE counselor_notes
    ADD CONSTRAINT fk_counselor_notes_student FOREIGN KEY IF NOT EXISTS fk_counselor_notes_student (student_id)
        REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_counselor_notes_author FOREIGN KEY IF NOT EXISTS fk_counselor_notes_author (counselor_id)
        REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE email_logs ENGINE=InnoDB;
ALTER TABLE email_logs
    ADD CONSTRAINT fk_email_logs_student FOREIGN KEY IF NOT EXISTS fk_email_logs_student (student_id)
        REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE sms_logs ENGINE=InnoDB;
ALTER TABLE sms_logs
    ADD CONSTRAINT fk_sms_logs_student FOREIGN KEY IF NOT EXISTS fk_sms_logs_student (student_id)
        REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE guardian_consents
    ADD CONSTRAINT fk_guardian_consents_verifier FOREIGN KEY IF NOT EXISTS fk_guardian_consents_verifier (verified_by)
        REFERENCES users(id);

-- Make the new column mandatory only once all accounts have a valid role.
ALTER TABLE users MODIFY COLUMN role_id TINYINT NOT NULL;
