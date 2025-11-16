-- Migration: Add temp_data_dir column for Docker-based recovery
-- Date: 2025-11-16
-- Description: Store temporary datadir path for cleanup

ALTER TABLE instant_recovery_sessions
ADD COLUMN temp_data_dir VARCHAR(255) NULL AFTER borg_mount_point;
