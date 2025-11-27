-- phpBorg Agent Integration into Servers
-- Migration: 003_add_agent_to_servers.sql
-- Date: 2025-11-27
-- Description: Adds agent-related columns to servers table for unified server/agent management

-- =============================================================================
-- Add agent columns to servers table
-- =============================================================================

-- Agent UUID (links to agents table, NULL if no agent installed)
ALTER TABLE `servers`
ADD COLUMN `agent_uuid` varchar(36) DEFAULT NULL COMMENT 'Agent UUID if agent is installed' AFTER `capabilities_detected_at`;

-- Agent status
ALTER TABLE `servers`
ADD COLUMN `agent_status` enum('none','pending','installing','active','inactive','error') NOT NULL DEFAULT 'none'
COMMENT 'Agent installation/connection status' AFTER `agent_uuid`;

-- Last heartbeat from agent
ALTER TABLE `servers`
ADD COLUMN `agent_last_heartbeat` datetime DEFAULT NULL
COMMENT 'Last heartbeat received from agent' AFTER `agent_status`;

-- Agent version
ALTER TABLE `servers`
ADD COLUMN `agent_version` varchar(20) DEFAULT NULL
COMMENT 'Installed agent version' AFTER `agent_last_heartbeat`;

-- Agent install token (temporary, for installation process)
ALTER TABLE `servers`
ADD COLUMN `agent_install_token` varchar(64) DEFAULT NULL
COMMENT 'Temporary token for agent installation' AFTER `agent_version`;

-- Agent install token expiry
ALTER TABLE `servers`
ADD COLUMN `agent_install_token_expires` datetime DEFAULT NULL
COMMENT 'Token expiration time' AFTER `agent_install_token`;

-- Connection mode: 'ssh' (legacy) or 'agent' (new)
ALTER TABLE `servers`
ADD COLUMN `connection_mode` enum('ssh','agent') NOT NULL DEFAULT 'ssh'
COMMENT 'How phpBorg communicates with this server' AFTER `agent_install_token_expires`;

-- Add index for agent lookups
ALTER TABLE `servers`
ADD INDEX `idx_agent_uuid` (`agent_uuid`);

ALTER TABLE `servers`
ADD INDEX `idx_agent_status` (`agent_status`);

ALTER TABLE `servers`
ADD INDEX `idx_connection_mode` (`connection_mode`);

-- =============================================================================
-- Update agents table to reference servers
-- =============================================================================

-- Ensure agents.server_id foreign key exists (if agents table was created)
-- This allows bi-directional linking: server -> agent_uuid, agent -> server_id

-- =============================================================================
-- Add settings for agent installation
-- =============================================================================

INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('agent_install_token_ttl', '3600', 'agent', 'integer', 'Agent install token TTL in seconds (default 1 hour)', NOW())
ON DUPLICATE KEY UPDATE `description` = 'Agent install token TTL in seconds (default 1 hour)';

INSERT INTO `settings` (`key`, `value`, `category`, `type`, `description`, `updated_at`)
VALUES ('agent_auto_approve', 'false', 'agent', 'boolean', 'Automatically approve new agent registrations', NOW())
ON DUPLICATE KEY UPDATE `description` = 'Automatically approve new agent registrations';
