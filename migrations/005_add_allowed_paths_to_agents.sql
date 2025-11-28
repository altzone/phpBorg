-- Migration: Add allowed_paths column to agents table
-- This stores the list of paths that the agent is allowed to access via borg serve
-- Each path is added when a repository is created for this agent

ALTER TABLE `agents`
ADD COLUMN `allowed_paths` JSON DEFAULT NULL COMMENT 'List of paths allowed for borg serve --restrict-to-path'
AFTER `backup_path`;

-- Update existing agents to have their current backup_path in allowed_paths
UPDATE `agents`
SET `allowed_paths` = JSON_ARRAY(`backup_path`)
WHERE `backup_path` IS NOT NULL AND `backup_path` != '';
