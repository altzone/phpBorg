-- Migration: Add snapshot capabilities and wizard support
-- Date: 2025-11-10

-- 1. Enhance repository table with snapshot and storage pool info
-- Note: storage_pool_id already added in migration 005, checking if other columns exist
SET @dbname = DATABASE();

-- Add snapshot_method column if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'snapshot_method') = 0,
    "ALTER TABLE `repository` ADD COLUMN `snapshot_method` enum('none','lvm','zfs','btrfs','vmware','hyperv','proxmox','xen') COLLATE utf8_unicode_ci DEFAULT 'none' AFTER `exclude`",
    "SELECT 'Column snapshot_method already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add snapshot_config column
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'snapshot_config') = 0,
    "ALTER TABLE `repository` ADD COLUMN `snapshot_config` JSON DEFAULT NULL COMMENT 'Snapshot-specific configuration' AFTER `snapshot_method`",
    "SELECT 'Column snapshot_config already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add pre_backup_commands column
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'pre_backup_commands') = 0,
    "ALTER TABLE `repository` ADD COLUMN `pre_backup_commands` JSON DEFAULT NULL COMMENT 'Commands to run before backup' AFTER `snapshot_config`",
    "SELECT 'Column pre_backup_commands already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add post_backup_commands column
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'post_backup_commands') = 0,
    "ALTER TABLE `repository` ADD COLUMN `post_backup_commands` JSON DEFAULT NULL COMMENT 'Commands to run after backup' AFTER `pre_backup_commands`",
    "SELECT 'Column post_backup_commands already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_prune_at column
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'last_prune_at') = 0,
    "ALTER TABLE `repository` ADD COLUMN `last_prune_at` datetime DEFAULT NULL AFTER `modified`",
    "SELECT 'Column last_prune_at already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add auto_prune column
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'repository' 
     AND COLUMN_NAME = 'auto_prune') = 0,
    "ALTER TABLE `repository` ADD COLUMN `auto_prune` tinyint(1) DEFAULT 1 COMMENT 'Enable automatic pruning' AFTER `last_prune_at`",
    "SELECT 'Column auto_prune already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Database credentials store for automatic backup configuration
CREATE TABLE IF NOT EXISTS `database_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `db_type` enum('mysql','mariadb','postgresql','mongodb','redis','elasticsearch','influxdb') 
    COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Friendly name for this credential set',
  `host` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'localhost',
  `port` int(11) NOT NULL,
  `username` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `password` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'Encrypted password',
  `auth_database` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Authentication database (MongoDB)',
  `auth_method` enum('password','socket','ssl','kerberos','ldap') COLLATE utf8_unicode_ci DEFAULT 'password',
  `ssl_config` JSON DEFAULT NULL COMMENT 'SSL/TLS configuration',
  `connection_options` JSON DEFAULT NULL COMMENT 'Additional connection options',
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'Default credentials for this DB type on server',
  `auto_detected` tinyint(1) DEFAULT 0 COMMENT 'Were these credentials auto-detected?',
  `test_status` enum('untested','success','failed') COLLATE utf8_unicode_ci DEFAULT 'untested',
  `test_message` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_test_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `db_type` (`db_type`),
  KEY `is_default` (`is_default`),
  UNIQUE KEY `server_db_default` (`server_id`, `db_type`, `is_default`),
  CONSTRAINT `db_credentials_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Database credentials for automated backups';

-- 3. Snapshot capabilities detected on servers
CREATE TABLE IF NOT EXISTS `server_snapshot_capabilities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `snapshot_type` enum('lvm','zfs','btrfs','vmware','hyperv','proxmox','xen') COLLATE utf8_unicode_ci NOT NULL,
  `available` tinyint(1) NOT NULL DEFAULT 0,
  `details` JSON DEFAULT NULL COMMENT 'Type-specific details (volumes, datasets, etc.)',
  `mount_points` JSON DEFAULT NULL COMMENT 'Mount points that can be snapshotted',
  `last_scan_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_type` (`server_id`, `snapshot_type`),
  KEY `server_id` (`server_id`),
  CONSTRAINT `snapshot_cap_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Detected snapshot capabilities per server';

-- 4. Backup templates for quick setup
CREATE TABLE IF NOT EXISTS `backup_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci,
  `icon` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `backup_type` enum('files','mysql','mariadb','postgresql','mongodb','docker','system','custom') 
    COLLATE utf8_unicode_ci NOT NULL,
  `source_config` JSON NOT NULL COMMENT 'Type-specific configuration template',
  `exclude_patterns` JSON DEFAULT NULL COMMENT 'Default exclusion patterns',
  `snapshot_method` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `retention_policy` JSON NOT NULL COMMENT 'Default retention settings',
  `compression` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'lz4',
  `encryption` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'repokey-blake2',
  `pre_backup_script` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `post_backup_script` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0 COMMENT 'System template (cannot be deleted)',
  `tags` JSON DEFAULT NULL,
  `usage_count` int(11) DEFAULT 0 COMMENT 'How many times this template was used',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `backup_type` (`backup_type`),
  KEY `is_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Reusable backup configuration templates';

-- 5. Wizard sessions to track multi-step configuration
CREATE TABLE IF NOT EXISTS `wizard_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `user_id` int(11) NOT NULL,
  `wizard_type` enum('backup','restore','migration') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'backup',
  `current_step` int(11) DEFAULT 1,
  `total_steps` int(11) DEFAULT NULL,
  `data` JSON NOT NULL COMMENT 'Wizard state data',
  `completed` tinyint(1) DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `wizard_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Wizard session state management';

-- 6. Insert default backup templates
INSERT INTO `backup_templates` (`name`, `description`, `icon`, `backup_type`, `source_config`, `exclude_patterns`, `retention_policy`, `compression`, `encryption`, `is_system`, `created_at`) VALUES
-- MySQL template
('MySQL Full Backup', 'Complete MySQL database backup with transactions', 'database', 'mysql', 
 '{"dump_options": "--single-transaction --routines --triggers --events", "compress": true, "lock_tables": false}',
 '["*.log", "*.tmp"]',
 '{"keep_daily": 7, "keep_weekly": 4, "keep_monthly": 6, "keep_yearly": 1}',
 'zstd', 'repokey-blake2', 1, NOW()),

-- PostgreSQL template
('PostgreSQL Cluster', 'PostgreSQL cluster backup with WAL archiving', 'database', 'postgresql',
 '{"dump_options": "--serializable-deferrable --no-unlogged-table-data", "format": "custom", "parallel": 4}',
 NULL,
 '{"keep_daily": 7, "keep_weekly": 4, "keep_monthly": 12, "keep_yearly": 2}',
 'zstd', 'repokey-blake2', 1, NOW()),

-- Web files template
('Web Files', 'Web server files with smart exclusions', 'folder', 'files',
 '{"paths": ["/var/www", "/etc/nginx", "/etc/apache2"], "follow_symlinks": false}',
 '["*/cache/*", "*/tmp/*", "*.log", "*/node_modules/*", "*/.git/*", "*/vendor/*"]',
 '{"keep_daily": 14, "keep_weekly": 8, "keep_monthly": 6, "keep_yearly": 1}',
 'lz4', 'repokey-blake2', 1, NOW()),

-- Docker template
('Docker Containers', 'Docker containers and volumes backup', 'docker', 'docker',
 '{"stop_containers": false, "backup_volumes": true, "backup_config": true}',
 '["*/tmp/*", "*.log"]',
 '{"keep_daily": 7, "keep_weekly": 4, "keep_monthly": 3, "keep_yearly": 0}',
 'lz4', 'repokey-blake2', 1, NOW()),

-- System backup template
('Full System', 'Complete system backup with OS and configs', 'server', 'system',
 '{"paths": ["/"], "one_file_system": true}',
 '["/dev/*", "/proc/*", "/sys/*", "/tmp/*", "/run/*", "/mnt/*", "/media/*", "/lost+found", "/var/cache/apt/*"]',
 '{"keep_daily": 3, "keep_weekly": 2, "keep_monthly": 3, "keep_yearly": 1}',
 'zstd:3', 'repokey-blake2', 1, NOW());

-- 7. Add indexes for better performance
CREATE INDEX IF NOT EXISTS `idx_repo_snapshot` ON `repository` (`snapshot_method`);
CREATE INDEX IF NOT EXISTS `idx_repo_pool` ON `repository` (`storage_pool_id`);
CREATE INDEX IF NOT EXISTS `idx_cred_server_type` ON `database_credentials` (`server_id`, `db_type`);
CREATE INDEX IF NOT EXISTS `idx_template_type` ON `backup_templates` (`backup_type`);

-- 8. Update existing repositories to use default storage pool
UPDATE `repository` r
SET r.storage_pool_id = (SELECT id FROM `storage_pools` WHERE `default_pool` = 1 LIMIT 1)
WHERE r.storage_pool_id IS NULL;