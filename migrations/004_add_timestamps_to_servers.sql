-- phpBorg Server Timestamps
-- Migration: 004_add_timestamps_to_servers.sql
-- Date: 2025-11-27
-- Description: Adds created_at and updated_at timestamps to servers table

-- =============================================================================
-- Add timestamp columns to servers table
-- =============================================================================

-- Created timestamp
ALTER TABLE `servers`
ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
COMMENT 'When the server was added' AFTER `connection_mode`;

-- Updated timestamp
ALTER TABLE `servers`
ADD COLUMN `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
COMMENT 'When the server was last modified' AFTER `created_at`;

-- Update existing records to have created_at set to NOW()
UPDATE `servers` SET `created_at` = NOW() WHERE `created_at` = '0000-00-00 00:00:00' OR `created_at` IS NULL;
