-- Add missing columns to server_stats table
ALTER TABLE server_stats
  ADD COLUMN architecture VARCHAR(50) DEFAULT NULL COMMENT 'CPU architecture (e.g., x86_64, aarch64)' AFTER hostname,
  ADD COLUMN cpu_model VARCHAR(255) DEFAULT NULL COMMENT 'CPU model name' AFTER cpu_cores,
  ADD COLUMN cpu_usage_percent FLOAT DEFAULT NULL COMMENT 'CPU usage percentage' AFTER cpu_load_15,
  ADD COLUMN memory_available_mb INT DEFAULT NULL COMMENT 'Available memory in MB' AFTER memory_free_mb,
  ADD COLUMN swap_total_mb INT DEFAULT NULL COMMENT 'Total swap in MB' AFTER memory_percent,
  ADD COLUMN swap_used_mb INT DEFAULT NULL COMMENT 'Used swap in MB' AFTER swap_total_mb,
  ADD COLUMN swap_percent FLOAT DEFAULT NULL COMMENT 'Swap usage percentage' AFTER swap_used_mb,
  ADD COLUMN disk_mount_point VARCHAR(255) DEFAULT '/' COMMENT 'Mount point monitored' AFTER disk_percent,
  ADD COLUMN boot_time TIMESTAMP NULL DEFAULT NULL COMMENT 'System boot timestamp' AFTER uptime_human;
