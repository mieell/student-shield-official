-- Import through phpMyAdmin before deploying guardian safety routing.
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
