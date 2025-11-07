-- Add granular retention policy fields to repository table
-- This allows per-repository configuration of retention policies

ALTER TABLE `repository`
ADD COLUMN `keep_daily` int(11) NOT NULL DEFAULT 7 COMMENT 'Number of daily backups to keep',
ADD COLUMN `keep_weekly` int(11) NOT NULL DEFAULT 4 COMMENT 'Number of weekly backups to keep',
ADD COLUMN `keep_monthly` int(11) NOT NULL DEFAULT 6 COMMENT 'Number of monthly backups to keep',
ADD COLUMN `keep_yearly` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of yearly backups to keep (0 = disabled)';

-- Migrate existing retention value to keep_daily
UPDATE `repository` SET `keep_daily` = `retention` WHERE `retention` > 0;

-- Keep retention column for backward compatibility (will be deprecated later)
-- ALTER TABLE `repository` DROP COLUMN `retention`;
