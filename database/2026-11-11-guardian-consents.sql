-- Run in phpMyAdmin before deploying the guardian consent workflow.
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
