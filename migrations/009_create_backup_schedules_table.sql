-- Migration: Create backup_schedules table for advanced scheduling
-- Date: 2025-11-10

CREATE TABLE IF NOT EXISTS `backup_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  
  -- Schedule type
  `type` enum('interval','daily','weekly','monthly','cron','advanced') COLLATE utf8_unicode_ci NOT NULL,
  
  -- Time settings
  `time` time NOT NULL COMMENT 'Backup time (HH:MM:SS)',
  `timezone` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'UTC',
  
  -- Multi-day selection for weekly (bitmap: Mon=1, Tue=2, Wed=4, Thu=8, Fri=16, Sat=32, Sun=64)
  `weekdays` int(11) DEFAULT NULL COMMENT 'Bitmap of selected weekdays',
  
  -- Multi-day selection for monthly (bitmap for days 1-31)
  `monthdays` int(11) DEFAULT NULL COMMENT 'Bitmap of selected month days',
  
  -- Advanced options
  `interval_hours` int(11) DEFAULT NULL COMMENT 'For interval type: run every N hours',
  `cron_expression` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'For cron type',
  
  -- Backup window
  `window_start` time DEFAULT NULL COMMENT 'Earliest time to start',
  `window_end` time DEFAULT NULL COMMENT 'Latest time to start',
  `max_runtime` int(11) DEFAULT 14400 COMMENT 'Max runtime in seconds (default 4h)',
  
  -- Blackout periods (JSON array of date ranges)
  `blackout_periods` JSON DEFAULT NULL COMMENT 'Periods when backup should not run',
  
  -- Retry policy
  `retry_on_failure` tinyint(1) DEFAULT 1,
  `max_retries` int(11) DEFAULT 3,
  `retry_delay_minutes` int(11) DEFAULT 30,
  
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `job_id` (`job_id`),
  KEY `type` (`type`),
  CONSTRAINT `backup_schedules_job_fk` FOREIGN KEY (`job_id`) REFERENCES `backup_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Advanced backup scheduling configuration';

-- Migrate existing schedules from backup_jobs table
INSERT INTO `backup_schedules` (`job_id`, `type`, `time`, `weekdays`, `monthdays`, `cron_expression`, `created_at`)
SELECT 
    bj.id as job_id,
    CASE 
        WHEN bj.schedule_type = 'custom' THEN 'cron'
        ELSE bj.schedule_type
    END as type,
    COALESCE(bj.schedule_time, '00:00:00') as time,
    CASE 
        WHEN bj.schedule_type = 'weekly' AND bj.schedule_day_of_week IS NOT NULL 
        THEN POWER(2, bj.schedule_day_of_week - 1)
        ELSE NULL
    END as weekdays,
    CASE 
        WHEN bj.schedule_type = 'monthly' AND bj.schedule_day_of_month IS NOT NULL 
        THEN POWER(2, bj.schedule_day_of_month - 1)
        ELSE NULL
    END as monthdays,
    bj.cron_expression,
    NOW() as created_at
FROM `backup_jobs` bj
WHERE bj.schedule_type != 'manual'
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Create index for efficient schedule queries
CREATE INDEX `idx_schedules_next_run` ON `backup_schedules` (`type`, `time`);