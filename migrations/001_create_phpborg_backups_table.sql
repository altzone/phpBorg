-- Migration: Create phpborg_backups table
-- Description: Stores information about phpBorg self-backups for restore and rollback
-- Date: 2025-01-24

CREATE TABLE IF NOT EXISTS phpborg_backups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(512) NOT NULL,
    size_bytes BIGINT NOT NULL,
    encrypted BOOLEAN DEFAULT 0,
    hash_sha256 VARCHAR(64) NOT NULL,
    phpborg_version VARCHAR(50) NOT NULL,
    php_version VARCHAR(50) NOT NULL,
    mysql_version VARCHAR(50) NULL,
    node_version VARCHAR(50) NULL,
    borg_version VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    created_by INT NULL,
    backup_type ENUM('manual', 'pre_update', 'scheduled') DEFAULT 'manual',
    notes TEXT NULL,

    INDEX idx_created_at (created_at),
    INDEX idx_backup_type (backup_type),
    INDEX idx_encrypted (encrypted),

    CONSTRAINT fk_phpborg_backup_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings for backup configuration
INSERT INTO settings (`key`, `value`, category, type, description, updated_at)
VALUES
    ('backup_storage_path', '/opt/backups', 'backup', 'text', 'Path where phpBorg self-backups are stored', NOW()),
    ('backup_encryption_enabled', '0', 'backup', 'boolean', 'Enable encryption for phpBorg backups', NOW()),
    ('backup_encryption_passphrase', '', 'backup', 'password', 'Passphrase for backup encryption (AES-256-CBC)', NOW()),
    ('backup_retention_count', '3', 'backup', 'number', 'Number of backups to keep (auto-cleanup)', NOW()),
    ('backup_scheduled_enabled', '1', 'backup', 'boolean', 'Enable weekly scheduled backups', NOW()),
    ('backup_scheduled_day', 'sunday', 'backup', 'text', 'Day of week for scheduled backup', NOW()),
    ('backup_scheduled_time', '02:00', 'backup', 'text', 'Time for scheduled backup (HH:MM)', NOW()),
    ('backup_notify_email', '1', 'backup', 'boolean', 'Send email notification after backup/restore', NOW())
ON DUPLICATE KEY UPDATE
    updated_at = NOW();
