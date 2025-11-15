-- Instant Recovery Sessions Table
-- Tracks active PostgreSQL/MySQL/MongoDB instances mounted from backups

CREATE TABLE IF NOT EXISTS instant_recovery_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archive_id INT NOT NULL,
    server_id INT NOT NULL,
    db_type VARCHAR(20) NOT NULL COMMENT 'postgresql, mysql, mongodb',
    deployment_location VARCHAR(10) NOT NULL DEFAULT 'remote' COMMENT 'remote (on source server) or local (on phpBorg backup server)',
    status VARCHAR(20) NOT NULL DEFAULT 'starting' COMMENT 'starting, active, stopping, stopped, failed',

    -- Mount paths
    borg_mount_point VARCHAR(255) NOT NULL COMMENT 'Borg FUSE mount point',
    overlay_upper_dir VARCHAR(255) NOT NULL COMMENT 'OverlayFS upper (RW) directory',
    overlay_work_dir VARCHAR(255) NOT NULL COMMENT 'OverlayFS work directory',
    overlay_merged_dir VARCHAR(255) NOT NULL COMMENT 'OverlayFS merged mount point',

    -- Database instance info
    db_port INT NOT NULL COMMENT 'Temporary database port (e.g., 15432 for PostgreSQL)',
    db_pid INT NULL COMMENT 'Database process PID',
    db_socket VARCHAR(255) NULL COMMENT 'Unix socket path if applicable',

    -- Connection info
    connection_string TEXT NULL COMMENT 'Connection string for users',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL COMMENT 'When database instance started',
    stopped_at TIMESTAMP NULL COMMENT 'When session was stopped',
    error_message TEXT NULL COMMENT 'Error message if failed',

    -- Foreign keys
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_status (status),
    INDEX idx_archive (archive_id),
    INDEX idx_active (status, created_at),
    INDEX idx_deployment (deployment_location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
