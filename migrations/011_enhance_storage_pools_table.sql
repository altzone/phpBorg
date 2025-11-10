-- Migration: Enhance storage_pools table with enterprise features
-- Date: 2025-11-10
-- IMPORTANT: This migration only ADDS columns, does not modify existing ones

-- Check if columns exist before adding them (safe migration)
SET @dbname = DATABASE();

-- Add type column if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'type') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `type` enum('local','nfs','s3','sftp','smb','azure','gcs') COLLATE utf8_unicode_ci DEFAULT 'local' AFTER `path`",
    "SELECT 'Column type already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add config column if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'config') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `config` JSON DEFAULT NULL COMMENT 'Type-specific configuration' AFTER `type`",
    "SELECT 'Column config already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add performance_tier column if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'performance_tier') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `performance_tier` enum('archive','standard','performance') COLLATE utf8_unicode_ci DEFAULT 'standard' AFTER `config`",
    "SELECT 'Column performance_tier already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add health monitoring columns if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'health_status') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `health_status` enum('healthy','degraded','critical','unknown') COLLATE utf8_unicode_ci DEFAULT 'unknown' AFTER `performance_tier`",
    "SELECT 'Column health_status already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'last_health_check') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `last_health_check` datetime DEFAULT NULL AFTER `health_status`",
    "SELECT 'Column last_health_check already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add quota management columns if not exists
SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'quota_bytes') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `quota_bytes` bigint(20) DEFAULT NULL COMMENT 'Storage quota in bytes' AFTER `last_health_check`",
    "SELECT 'Column quota_bytes already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @query = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname 
     AND TABLE_NAME = 'storage_pools' 
     AND COLUMN_NAME = 'alert_threshold_percent') = 0,
    "ALTER TABLE `storage_pools` ADD COLUMN `alert_threshold_percent` int(11) DEFAULT 80 COMMENT 'Alert when usage exceeds %' AFTER `quota_bytes`",
    "SELECT 'Column alert_threshold_percent already exists'"
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;