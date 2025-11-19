-- Migration: Add 'draft' status to restore_operations
-- Date: 2025-11-19
-- Purpose: Support draft operations for script preview before execution

ALTER TABLE restore_operations
MODIFY COLUMN status ENUM('draft','pending','running','completed','failed','rolled_back')
NOT NULL DEFAULT 'pending';
