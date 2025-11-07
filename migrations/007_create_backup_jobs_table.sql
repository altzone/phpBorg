-- Migration: Create backup_jobs table for scheduled backups
-- Date: 2025-11-07

CREATE TABLE IF NOT EXISTS `backup_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Job name (e.g., MySQL Daily Backup)',
  `repository_id` int(11) NOT NULL COMMENT 'Repository to backup',
  `schedule_type` enum('manual','daily','weekly','monthly','custom') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'Schedule type',
  `schedule_time` time DEFAULT NULL COMMENT 'Time to run (HH:MM:SS)',
  `schedule_day_of_week` tinyint(4) DEFAULT NULL COMMENT 'Day of week for weekly (1=Monday, 7=Sunday)',
  `schedule_day_of_month` tinyint(4) DEFAULT NULL COMMENT 'Day of month for monthly (1-31)',
  `cron_expression` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Custom cron expression',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is job enabled?',
  `notify_on_success` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Send notification on success?',
  `notify_on_failure` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Send notification on failure?',
  `last_run_at` datetime DEFAULT NULL COMMENT 'Last execution timestamp',
  `next_run_at` datetime DEFAULT NULL COMMENT 'Next scheduled execution (calculated)',
  `last_status` enum('success','failure','running') COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Last run status',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repository_id` (`repository_id`),
  KEY `enabled` (`enabled`),
  KEY `next_run_at` (`next_run_at`),
  KEY `schedule_type` (`schedule_type`),
  CONSTRAINT `backup_jobs_repository_fk` FOREIGN KEY (`repository_id`) REFERENCES `repository` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Scheduled backup jobs configuration';

-- Add index for finding jobs to execute
CREATE INDEX `idx_jobs_to_run` ON `backup_jobs` (`enabled`, `next_run_at`);

-- Add default manual job for existing repositories (optional)
-- This ensures existing backups still work as "manual" jobs
INSERT INTO `backup_jobs` (`name`, `repository_id`, `schedule_type`, `enabled`, `created_at`)
SELECT
    CONCAT(s.hostname, ' - ', r.name, ' (Manual)') as name,
    r.id as repository_id,
    'manual' as schedule_type,
    1 as enabled,
    NOW() as created_at
FROM `repository` r
INNER JOIN `servers` s ON r.server_id = s.id
WHERE NOT EXISTS (
    SELECT 1 FROM `backup_jobs` bj WHERE bj.repository_id = r.id
);
