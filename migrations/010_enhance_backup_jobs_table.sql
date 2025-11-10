-- Migration: Enhance backup_jobs table with enterprise features
-- Date: 2025-11-10

-- Add source_id column to link to backup_sources
ALTER TABLE `backup_jobs` 
ADD COLUMN `source_id` int(11) DEFAULT NULL COMMENT 'Link to backup source' AFTER `name`,
ADD COLUMN `description` text COLLATE utf8_unicode_ci DEFAULT NULL AFTER `name`,
ADD KEY `source_id` (`source_id`);

-- Add enterprise features columns
ALTER TABLE `backup_jobs`
ADD COLUMN `priority` enum('low','normal','high','critical') COLLATE utf8_unicode_ci DEFAULT 'normal' AFTER `enabled`,
ADD COLUMN `retention_override` JSON DEFAULT NULL COMMENT 'Override repository retention' AFTER `priority`,
ADD COLUMN `bandwidth_limit` int(11) DEFAULT NULL COMMENT 'KB/s, NULL = unlimited' AFTER `retention_override`,
ADD COLUMN `cpu_nice` int(11) DEFAULT 10 COMMENT 'Process nice level (0-19)' AFTER `bandwidth_limit`,
ADD COLUMN `compression_level` int(11) DEFAULT NULL COMMENT 'Override compression (1-9)' AFTER `cpu_nice`;

-- Add notification enhancements
ALTER TABLE `backup_jobs`
ADD COLUMN `notify_channels` JSON DEFAULT NULL COMMENT 'Notification channels and settings' AFTER `compression_level`,
ADD COLUMN `notify_on_warning` tinyint(1) DEFAULT 1 AFTER `notify_on_failure`;

-- Add hooks and dependencies
ALTER TABLE `backup_jobs`
ADD COLUMN `pre_job_hook` text COLLATE utf8_unicode_ci DEFAULT NULL AFTER `notify_on_warning`,
ADD COLUMN `post_job_hook` text COLLATE utf8_unicode_ci DEFAULT NULL AFTER `pre_job_hook`,
ADD COLUMN `depends_on_job_id` int(11) DEFAULT NULL COMMENT 'Job that must complete first' AFTER `post_job_hook`,
ADD COLUMN `conflict_jobs` JSON DEFAULT NULL COMMENT 'Jobs that cannot run simultaneously' AFTER `depends_on_job_id`;

-- Add enhanced statistics
ALTER TABLE `backup_jobs`
ADD COLUMN `last_duration_seconds` int(11) DEFAULT NULL AFTER `last_status`,
ADD COLUMN `last_size_bytes` bigint(20) DEFAULT NULL AFTER `last_duration_seconds`,
ADD COLUMN `total_runs` int(11) DEFAULT 0 AFTER `next_run_at`,
ADD COLUMN `success_runs` int(11) DEFAULT 0 AFTER `total_runs`,
ADD COLUMN `failed_runs` int(11) DEFAULT 0 AFTER `success_runs`;

-- Add metadata
ALTER TABLE `backup_jobs`
ADD COLUMN `tags` JSON DEFAULT NULL COMMENT 'Tags for categorization' AFTER `failed_runs`;

-- Add indexes for performance
CREATE INDEX `idx_jobs_priority` ON `backup_jobs` (`priority`, `enabled`);
CREATE INDEX `idx_jobs_dependencies` ON `backup_jobs` (`depends_on_job_id`);

-- Update existing jobs to have a source_id based on repository type
UPDATE `backup_jobs` bj
INNER JOIN `repository` r ON bj.repository_id = r.id
SET bj.source_id = (
    SELECT bs.id 
    FROM `backup_sources` bs 
    WHERE bs.server_id = r.server_id 
    AND bs.type = CASE 
        WHEN r.type = 'mysql' THEN 'mysql'
        WHEN r.type = 'database' THEN 'mysql'
        ELSE 'files'
    END
    LIMIT 1
)
WHERE bj.source_id IS NULL;