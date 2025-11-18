-- Migration: 016_create_restore_operations_table
-- Description: Create table for ALL restore operations tracking (Docker, MySQL, PostgreSQL, Filesystem, System)
-- Date: 2025-11-18

CREATE TABLE IF NOT EXISTS restore_operations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archive_id INT NOT NULL,
  server_id INT NOT NULL,
  user_id INT NOT NULL,

  -- Source type (what we're restoring)
  source_type ENUM('docker', 'mysql', 'postgresql', 'mongodb', 'filesystem', 'system') NOT NULL,

  -- Configuration
  mode ENUM('express', 'pro_safe') NOT NULL DEFAULT 'express',
  restore_type ENUM('full', 'volumes_only', 'compose_only', 'database_only', 'custom', 'files_only') NOT NULL,
  destination ENUM('in_place', 'alternative') NOT NULL,
  alternative_path VARCHAR(500),

  -- Options
  compose_path_adaptation ENUM('none', 'auto_modify', 'generate_new') DEFAULT 'none',
  selected_items JSON,  -- {volumes: [...], projects: [...], configs: [...]}

  -- Protections
  lvm_snapshot_created BOOLEAN DEFAULT FALSE,
  lvm_snapshot_name VARCHAR(100),
  pre_restore_backup_created BOOLEAN DEFAULT FALSE,
  pre_restore_backup_archive VARCHAR(100),
  auto_restart BOOLEAN DEFAULT TRUE,

  -- Containers
  stopped_containers JSON,  -- [{name, id, restart_order}]

  -- Execution
  status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
  started_at DATETIME,
  completed_at DATETIME,
  error_message TEXT,

  -- Script
  generated_script LONGTEXT,
  script_executed BOOLEAN DEFAULT FALSE,

  -- Rollback capability (8 hours)
  can_rollback_until DATETIME,
  rolled_back_at DATETIME,

  -- Tracking
  items_restored JSON,  -- Progress tracking
  bytes_restored BIGINT,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_archive (archive_id),
  INDEX idx_server (server_id),
  INDEX idx_user (user_id),
  INDEX idx_status (status),
  INDEX idx_rollback (can_rollback_until),

  FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
  FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
