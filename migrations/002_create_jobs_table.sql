-- Migration: Create jobs table for background task queue
-- Date: 2025-11-06

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'default',
  `type` varchar(100) COLLATE utf8_unicode_ci NOT NULL COMMENT 'server_setup, backup_create, backup_restore, etc.',
  `payload` json NOT NULL COMMENT 'Job parameters and data',
  `status` enum('pending','running','completed','failed','cancelled') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending',
  `progress` int(11) NOT NULL DEFAULT 0 COMMENT 'Progress percentage 0-100',
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of execution attempts',
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `output` text COLLATE utf8_unicode_ci COMMENT 'Job output/logs',
  `error` text COLLATE utf8_unicode_ci COMMENT 'Error message if failed',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created the job',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `queue` (`queue`),
  KEY `type` (`type`),
  KEY `created_at` (`created_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_jobs_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Index for worker to efficiently fetch next job
CREATE INDEX `idx_worker_fetch` ON `jobs` (`status`, `queue`, `created_at`);
