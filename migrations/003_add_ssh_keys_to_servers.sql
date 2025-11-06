-- Migration: Add SSH key management fields for secure borg serve architecture
-- Date: 2025-11-06

-- Add column for private key path on remote server
ALTER TABLE `servers`
ADD COLUMN `ssh_private_key_path` varchar(255) COLLATE utf8_unicode_ci DEFAULT '/root/.ssh/phpborg_backup'
COMMENT 'Path to private SSH key on remote server for borg connections'
AFTER `ssh_pub_key`;

-- Add column to track if SSH keys are deployed
ALTER TABLE `servers`
ADD COLUMN `ssh_keys_deployed` tinyint(1) NOT NULL DEFAULT 0
COMMENT 'Whether SSH keys have been deployed to remote server'
AFTER `ssh_private_key_path`;

-- Add column for backup server user (phpborg by default)
ALTER TABLE `servers`
ADD COLUMN `backup_server_user` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'phpborg'
COMMENT 'Username on backup server for borg serve connections'
AFTER `ssh_keys_deployed`;
