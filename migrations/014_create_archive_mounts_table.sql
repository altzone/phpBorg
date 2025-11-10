-- Migration: Create archive_mounts table for tracking mounted archives
-- Purpose: Track mounted Borg archives for browse/restore operations

CREATE TABLE IF NOT EXISTS archive_mounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archive_id INT NOT NULL,
    mount_path VARCHAR(512) NOT NULL,
    status ENUM('mounting', 'mounted', 'unmounting', 'error') NOT NULL DEFAULT 'mounting',
    mounted_at DATETIME NOT NULL,
    last_access DATETIME NOT NULL,
    error_message TEXT NULL,

    CONSTRAINT fk_archive_mounts_archive
        FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,

    INDEX idx_archive_id (archive_id),
    INDEX idx_last_access (last_access),
    INDEX idx_status (status),
    UNIQUE KEY uk_archive_mount (archive_id) -- Only one mount per archive at a time
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
