-- Migration: Add filesystem analysis fields to storage_pools
-- Date: 2025-11-07

ALTER TABLE `storage_pools`
ADD COLUMN `filesystem_type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Filesystem type: ext4, xfs, nfs, btrfs, etc.' AFTER `capacity_used`,
ADD COLUMN `storage_type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Storage type: local_disk, nfs, smb, unknown' AFTER `filesystem_type`,
ADD COLUMN `mount_point` varchar(500) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Mount point path' AFTER `storage_type`,
ADD COLUMN `available_bytes` bigint(20) DEFAULT NULL COMMENT 'Available space in bytes' AFTER `mount_point`,
ADD COLUMN `usage_percent` int(11) DEFAULT NULL COMMENT 'Usage percentage (0-100)' AFTER `available_bytes`,
ADD COLUMN `last_analyzed_at` datetime DEFAULT NULL COMMENT 'Last filesystem analysis timestamp' AFTER `usage_percent`;

-- Add indexes for filtering
ALTER TABLE `storage_pools`
ADD KEY `storage_type` (`storage_type`),
ADD KEY `filesystem_type` (`filesystem_type`);
