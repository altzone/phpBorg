-- phpBorg Agent Architecture Tables
-- Migration: 002_create_agents_tables.sql
-- Date: 2025-11-27
-- Description: Creates tables for the new pull-based agent architecture

-- =============================================================================
-- Table: agents
-- Stores information about registered phpborg-agent instances
-- =============================================================================
CREATE TABLE IF NOT EXISTS `agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL COMMENT 'Unique agent identifier (UUID v4)',
  `name` varchar(100) NOT NULL COMMENT 'Human-readable agent name',
  `hostname` varchar(255) NOT NULL COMMENT 'Remote server hostname',
  `server_id` int(11) DEFAULT NULL COMMENT 'Link to legacy servers table (for migration)',

  -- Agent Status
  `status` enum('pending','active','inactive','revoked') NOT NULL DEFAULT 'pending',
  `last_heartbeat` datetime DEFAULT NULL COMMENT 'Last time agent checked in',
  `version` varchar(20) DEFAULT NULL COMMENT 'Agent software version',

  -- SSH Configuration
  `ssh_public_key` text NOT NULL COMMENT 'Ed25519 public key for borg SSH access',
  `backup_path` varchar(255) NOT NULL COMMENT 'Path to agent backups on phpBorg server',
  `append_only` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Enable append-only mode (ransomware protection)',

  -- mTLS Certificate
  `certificate_cn` varchar(100) DEFAULT NULL COMMENT 'Certificate Common Name',
  `certificate_expires_at` datetime DEFAULT NULL COMMENT 'Certificate expiration date',
  `certificate_fingerprint` varchar(64) DEFAULT NULL COMMENT 'SHA256 fingerprint for verification',

  -- Agent Metadata
  `os_info` varchar(255) DEFAULT NULL COMMENT 'Operating system info',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Last known IP address',
  `capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    COMMENT 'Detected capabilities JSON' CHECK (json_valid(`capabilities`)),

  -- Registration
  `registered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `registered_by` int(11) DEFAULT NULL COMMENT 'User who registered the agent',
  `notes` text DEFAULT NULL,

  -- Timestamps
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status` (`status`),
  KEY `idx_server_id` (`server_id`),
  KEY `idx_last_heartbeat` (`last_heartbeat`),
  KEY `idx_certificate_expires` (`certificate_expires_at`),
  CONSTRAINT `fk_agents_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agents_user` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered phpborg-agent instances';

-- =============================================================================
-- Table: agent_tasks
-- Queue of tasks for agents to execute
-- =============================================================================
CREATE TABLE IF NOT EXISTS `agent_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL COMMENT 'Target agent',
  `job_id` int(11) DEFAULT NULL COMMENT 'Link to main jobs table',

  -- Task Definition
  `type` varchar(50) NOT NULL COMMENT 'backup_create, backup_restore, capabilities_detect, etc.',
  `priority` enum('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
    COMMENT 'Task parameters JSON' CHECK (json_valid(`payload`)),

  -- Task Status
  `status` enum('pending','assigned','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `progress` int(11) NOT NULL DEFAULT 0 COMMENT 'Progress 0-100',
  `progress_message` varchar(255) DEFAULT NULL,

  -- Execution Details
  `assigned_at` datetime DEFAULT NULL COMMENT 'When agent picked up task',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `timeout_seconds` int(11) NOT NULL DEFAULT 3600 COMMENT 'Task timeout',

  -- Results
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    COMMENT 'Task result JSON' CHECK (json_valid(`result`)),
  `error` text DEFAULT NULL,
  `exit_code` int(11) DEFAULT NULL,

  -- Retry Logic
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `retry_after` datetime DEFAULT NULL,

  -- Timestamps
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_agent_status` (`agent_id`, `status`),
  KEY `idx_status_priority` (`status`, `priority`, `created_at`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_pending_tasks` (`status`, `agent_id`, `priority` DESC, `created_at`),
  CONSTRAINT `fk_agent_tasks_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_tasks_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agent_tasks_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Task queue for phpborg agents';

-- =============================================================================
-- Table: agent_events
-- Audit log of agent activities
-- =============================================================================
CREATE TABLE IF NOT EXISTS `agent_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'heartbeat, task_start, task_complete, error, etc.',
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    COMMENT 'Event details JSON' CHECK (json_valid(`event_data`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_agent_events` (`agent_id`, `created_at`),
  KEY `idx_event_type` (`event_type`, `created_at`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_agent_events_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Agent activity audit log';

-- =============================================================================
-- Add borg_ssh_port setting
-- =============================================================================
INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('borg_ssh_port', '2222', 'borg', 'integer', 'Port for dedicated Borg SSH server', NOW())
ON DUPLICATE KEY UPDATE `value` = '2222';

INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('agent_heartbeat_interval', '60', 'agent', 'integer', 'Agent heartbeat interval in seconds', NOW())
ON DUPLICATE KEY UPDATE `value` = '60';

INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('agent_task_poll_interval', '5', 'agent', 'integer', 'Agent task polling interval in seconds', NOW())
ON DUPLICATE KEY UPDATE `value` = '5';

INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('agent_default_append_only', 'true', 'agent', 'boolean', 'Enable append-only mode by default for new agents', NOW())
ON DUPLICATE KEY UPDATE `value` = 'true';
