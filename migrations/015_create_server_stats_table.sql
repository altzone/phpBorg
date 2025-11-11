-- Create server_stats table for storing real-time server metrics
CREATE TABLE IF NOT EXISTS server_stats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  server_id INT NOT NULL,

  -- System Information
  os_distribution VARCHAR(255) DEFAULT NULL COMMENT 'OS distribution (e.g., Ubuntu, Debian, CentOS)',
  os_version VARCHAR(100) DEFAULT NULL COMMENT 'OS version (e.g., 22.04, 11, 8)',
  kernel_version VARCHAR(100) DEFAULT NULL COMMENT 'Kernel version',
  hostname VARCHAR(255) DEFAULT NULL COMMENT 'Server hostname',
  architecture VARCHAR(50) DEFAULT NULL COMMENT 'CPU architecture (e.g., x86_64, aarch64)',

  -- CPU Metrics
  cpu_cores INT DEFAULT NULL COMMENT 'Number of CPU cores',
  cpu_model VARCHAR(255) DEFAULT NULL COMMENT 'CPU model name',
  cpu_load_1 FLOAT DEFAULT NULL COMMENT 'Load average - 1 minute',
  cpu_load_5 FLOAT DEFAULT NULL COMMENT 'Load average - 5 minutes',
  cpu_load_15 FLOAT DEFAULT NULL COMMENT 'Load average - 15 minutes',
  cpu_usage_percent FLOAT DEFAULT NULL COMMENT 'CPU usage percentage',

  -- Memory Metrics (in MB for precision)
  memory_total_mb INT DEFAULT NULL COMMENT 'Total memory in MB',
  memory_used_mb INT DEFAULT NULL COMMENT 'Used memory in MB',
  memory_free_mb INT DEFAULT NULL COMMENT 'Free memory in MB',
  memory_available_mb INT DEFAULT NULL COMMENT 'Available memory in MB',
  memory_percent FLOAT DEFAULT NULL COMMENT 'Memory usage percentage',
  swap_total_mb INT DEFAULT NULL COMMENT 'Total swap in MB',
  swap_used_mb INT DEFAULT NULL COMMENT 'Used swap in MB',
  swap_percent FLOAT DEFAULT NULL COMMENT 'Swap usage percentage',

  -- Disk Metrics (in GB for large volumes)
  disk_total_gb FLOAT DEFAULT NULL COMMENT 'Total disk space in GB',
  disk_used_gb FLOAT DEFAULT NULL COMMENT 'Used disk space in GB',
  disk_free_gb FLOAT DEFAULT NULL COMMENT 'Free disk space in GB',
  disk_percent FLOAT DEFAULT NULL COMMENT 'Disk usage percentage',
  disk_mount_point VARCHAR(255) DEFAULT '/' COMMENT 'Mount point monitored',

  -- Uptime
  uptime_seconds BIGINT DEFAULT NULL COMMENT 'System uptime in seconds',
  uptime_human VARCHAR(100) DEFAULT NULL COMMENT 'Human-readable uptime',
  boot_time TIMESTAMP NULL DEFAULT NULL COMMENT 'System boot timestamp',

  -- Network Information
  ip_address VARCHAR(45) DEFAULT NULL COMMENT 'Primary IP address (IPv4 or IPv6)',

  -- Timestamps
  collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When metrics were collected',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation time',

  -- Foreign key and indexes
  CONSTRAINT fk_server_stats_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
  INDEX idx_server_id (server_id),
  INDEX idx_collected_at (collected_at),
  INDEX idx_server_collected (server_id, collected_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Real-time server system metrics';
