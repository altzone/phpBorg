-- Migration: Remove OverlayFS columns (switched to Docker-based recovery)
-- Date: 2025-11-16
-- Description: Remove unused overlay_* columns since we use Docker containers instead

ALTER TABLE instant_recovery_sessions
DROP COLUMN IF EXISTS overlay_upper_dir,
DROP COLUMN IF EXISTS overlay_work_dir,
DROP COLUMN IF EXISTS overlay_merged_dir;
