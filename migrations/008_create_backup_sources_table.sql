-- Migration: Create backup_sources table for defining what to backup
-- Date: 2025-11-10

CREATE TABLE IF NOT EXISTS `backup_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Source name (e.g., Production MySQL)',
  `type` enum('mysql','postgresql','files','docker','vm','custom') COLLATE utf8_unicode_ci NOT NULL,
  `server_id` int(11) NOT NULL COMMENT 'Server where source is located',
  
  -- Type-specific configuration (JSON)
  `config` JSON NOT NULL COMMENT 'Type-specific config (DB name, file paths, container ID, etc.)',
  
  -- Common settings
  `paths` JSON DEFAULT NULL COMMENT 'File paths for file-type backups',
  `exclude_patterns` JSON DEFAULT NULL COMMENT 'Exclusion patterns',
  `pre_backup_script` text COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Script to run before backup',
  `post_backup_script` text COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Script to run after backup',
  
  -- Metadata
  `tags` JSON DEFAULT NULL COMMENT 'Tags for categorization',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `type` (`type`),
  KEY `active` (`active`),
  CONSTRAINT `backup_sources_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Backup source definitions';

-- Insert example backup sources for existing repositories
INSERT INTO `backup_sources` (`name`, `type`, `server_id`, `config`, `active`, `created_at`)
SELECT DISTINCT
    CONCAT(s.name, ' - Files') as name,
    'files' as type,
    r.server_id,
    JSON_OBJECT(
        'paths', JSON_ARRAY(r.backup_path),
        'exclude', CASE WHEN r.exclude IS NOT NULL THEN r.exclude ELSE '' END
    ) as config,
    1 as active,
    NOW() as created_at
FROM `repository` r
INNER JOIN `servers` s ON r.server_id = s.id
WHERE r.type = 'files' OR r.type IS NULL
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Add MySQL sources if type indicates database
INSERT INTO `backup_sources` (`name`, `type`, `server_id`, `config`, `active`, `created_at`)
SELECT DISTINCT
    CONCAT(s.name, ' - MySQL') as name,
    'mysql' as type,
    r.server_id,
    JSON_OBJECT(
        'database', 'all',
        'port', 3306,
        'user', 'backup',
        'dump_options', '--single-transaction --routines --triggers'
    ) as config,
    1 as active,
    NOW() as created_at
FROM `repository` r
INNER JOIN `servers` s ON r.server_id = s.id
WHERE r.type = 'mysql' OR r.type = 'database'
ON DUPLICATE KEY UPDATE updated_at = NOW();