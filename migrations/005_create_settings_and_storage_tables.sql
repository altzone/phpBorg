-- Migration: Create settings and storage management tables
-- Date: 2025-11-07

-- Settings table for application configuration
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Setting key (e.g., app.name, smtp.host)',
  `value` text COLLATE utf8_unicode_ci COMMENT 'Setting value (JSON for complex values)',
  `category` varchar(50) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Category: general, email, backup, borg, security, network',
  `type` varchar(20) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Type: string, integer, boolean, json',
  `description` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Storage pools table for managing multiple backup storage locations
CREATE TABLE IF NOT EXISTS `storage_pools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Storage pool name',
  `path` varchar(500) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Absolute filesystem path',
  `description` text COLLATE utf8_unicode_ci,
  `capacity_total` bigint(20) DEFAULT NULL COMMENT 'Total capacity in bytes (NULL = unknown)',
  `capacity_used` bigint(20) DEFAULT 0 COMMENT 'Used space in bytes',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is pool active?',
  `default_pool` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this the default pool for new repositories?',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `path` (`path`),
  KEY `active` (`active`),
  KEY `default_pool` (`default_pool`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Role permissions table for granular permission management
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Role name (e.g., ROLE_ADMIN, ROLE_OPERATOR)',
  `permission` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Permission key (e.g., servers.create, backups.delete)',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is permission enabled for this role?',
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission` (`role`, `permission`),
  KEY `role` (`role`),
  KEY `permission` (`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Insert default settings
INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`) VALUES
-- General settings
('app.name', 'phpBorg', 'general', 'string', 'Application name'),
('app.timezone', 'UTC', 'general', 'string', 'Application timezone'),
('app.language', 'en', 'general', 'string', 'Default language'),

-- Email/SMTP settings
('smtp.enabled', 'false', 'email', 'boolean', 'Enable SMTP notifications'),
('smtp.host', '', 'email', 'string', 'SMTP server hostname'),
('smtp.port', '587', 'email', 'integer', 'SMTP server port'),
('smtp.encryption', 'tls', 'email', 'string', 'Encryption type: tls, ssl, or none'),
('smtp.username', '', 'email', 'string', 'SMTP username'),
('smtp.password', '', 'email', 'string', 'SMTP password (encrypted)'),
('smtp.from_email', 'noreply@phpborg.local', 'email', 'string', 'From email address'),
('smtp.from_name', 'phpBorg', 'email', 'string', 'From name'),

-- Backup default settings
('backup.retention.daily', '7', 'backup', 'integer', 'Default daily retention'),
('backup.retention.weekly', '4', 'backup', 'integer', 'Default weekly retention'),
('backup.retention.monthly', '6', 'backup', 'integer', 'Default monthly retention'),
('backup.retention.yearly', '0', 'backup', 'integer', 'Default yearly retention'),

-- Borg settings
('borg.compression', 'lz4', 'borg', 'string', 'Default compression algorithm'),
('borg.encryption', 'repokey-blake2', 'borg', 'string', 'Default encryption mode'),
('borg.default_path', '/backup/borg', 'borg', 'string', 'Default borg repository base path'),
('borg.ratelimit', '0', 'borg', 'integer', 'Default rate limit (KB/s, 0 = unlimited)'),

-- Security settings
('security.jwt.access_ttl', '3600', 'security', 'integer', 'JWT access token TTL (seconds)'),
('security.jwt.refresh_ttl', '2592000', 'security', 'integer', 'JWT refresh token TTL (seconds)'),
('security.session_timeout', '1800', 'security', 'integer', 'Session timeout (seconds)'),
('security.force_https', 'false', 'security', 'boolean', 'Force HTTPS connections'),
('security.2fa_enabled', 'false', 'security', 'boolean', 'Enable 2FA (future feature)'),

-- Network settings
('network.external_ip', '', 'network', 'string', 'External IP address'),
('network.internal_ip', '', 'network', 'string', 'Internal IP address'),
('network.api_port', '8080', 'network', 'integer', 'API server port'),

-- Notification settings
('notifications.backup_failed', 'true', 'notifications', 'boolean', 'Notify on backup failure'),
('notifications.backup_success', 'false', 'notifications', 'boolean', 'Notify on backup success'),
('notifications.webhook_url', '', 'notifications', 'string', 'Webhook URL for notifications');

-- Insert default storage pool
INSERT INTO `storage_pools` (`name`, `path`, `description`, `active`, `default_pool`, `created_at`) VALUES
('Default Storage', '/backup/borg', 'Default backup storage location', 1, 1, NOW());

-- Insert default permissions for ROLE_ADMIN
INSERT INTO `role_permissions` (`role`, `permission`, `enabled`) VALUES
('ROLE_ADMIN', 'users.view', 1),
('ROLE_ADMIN', 'users.create', 1),
('ROLE_ADMIN', 'users.edit', 1),
('ROLE_ADMIN', 'users.delete', 1),
('ROLE_ADMIN', 'servers.view', 1),
('ROLE_ADMIN', 'servers.create', 1),
('ROLE_ADMIN', 'servers.edit', 1),
('ROLE_ADMIN', 'servers.delete', 1),
('ROLE_ADMIN', 'backups.view', 1),
('ROLE_ADMIN', 'backups.create', 1),
('ROLE_ADMIN', 'backups.delete', 1),
('ROLE_ADMIN', 'jobs.view', 1),
('ROLE_ADMIN', 'jobs.cancel', 1),
('ROLE_ADMIN', 'settings.view', 1),
('ROLE_ADMIN', 'settings.edit', 1),
('ROLE_ADMIN', 'storage.view', 1),
('ROLE_ADMIN', 'storage.manage', 1);

-- Insert default permissions for ROLE_OPERATOR
INSERT INTO `role_permissions` (`role`, `permission`, `enabled`) VALUES
('ROLE_OPERATOR', 'users.view', 0),
('ROLE_OPERATOR', 'users.create', 0),
('ROLE_OPERATOR', 'users.edit', 0),
('ROLE_OPERATOR', 'users.delete', 0),
('ROLE_OPERATOR', 'servers.view', 1),
('ROLE_OPERATOR', 'servers.create', 1),
('ROLE_OPERATOR', 'servers.edit', 1),
('ROLE_OPERATOR', 'servers.delete', 0),
('ROLE_OPERATOR', 'backups.view', 1),
('ROLE_OPERATOR', 'backups.create', 1),
('ROLE_OPERATOR', 'backups.delete', 0),
('ROLE_OPERATOR', 'jobs.view', 1),
('ROLE_OPERATOR', 'jobs.cancel', 1),
('ROLE_OPERATOR', 'settings.view', 1),
('ROLE_OPERATOR', 'settings.edit', 0),
('ROLE_OPERATOR', 'storage.view', 1),
('ROLE_OPERATOR', 'storage.manage', 0);

-- Insert default permissions for ROLE_USER
INSERT INTO `role_permissions` (`role`, `permission`, `enabled`) VALUES
('ROLE_USER', 'users.view', 0),
('ROLE_USER', 'users.create', 0),
('ROLE_USER', 'users.edit', 0),
('ROLE_USER', 'users.delete', 0),
('ROLE_USER', 'servers.view', 1),
('ROLE_USER', 'servers.create', 0),
('ROLE_USER', 'servers.edit', 0),
('ROLE_USER', 'servers.delete', 0),
('ROLE_USER', 'backups.view', 1),
('ROLE_USER', 'backups.create', 0),
('ROLE_USER', 'backups.delete', 0),
('ROLE_USER', 'jobs.view', 1),
('ROLE_USER', 'jobs.cancel', 0),
('ROLE_USER', 'settings.view', 1),
('ROLE_USER', 'settings.edit', 0),
('ROLE_USER', 'storage.view', 1),
('ROLE_USER', 'storage.manage', 0);

-- Add storage_pool_id to repository table (if not exists)
ALTER TABLE `repository`
ADD COLUMN `storage_pool_id` int(11) DEFAULT 1 COMMENT 'Storage pool where repository is located' AFTER `server_id`,
ADD KEY `storage_pool_id` (`storage_pool_id`);
