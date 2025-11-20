/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `archive_mounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `archive_mounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `archive_id` int(11) NOT NULL,
  `mount_path` varchar(512) NOT NULL,
  `status` enum('mounting','mounted','unmounting','error') NOT NULL DEFAULT 'mounting',
  `mounted_at` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_archive_mount` (`archive_id`),
  KEY `idx_archive_id` (`archive_id`),
  KEY `idx_last_access` (`last_access`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_archive_mounts_archive` FOREIGN KEY (`archive_id`) REFERENCES `archives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `archives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `archives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `repo_id` varchar(64) NOT NULL,
  `server_id` int(11) NOT NULL,
  `nom` varchar(250) DEFAULT NULL,
  `archive_id` varchar(250) DEFAULT NULL,
  `backup_config` longtext DEFAULT NULL,
  `dur` float DEFAULT NULL,
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `csize` bigint(10) DEFAULT NULL,
  `dsize` bigint(10) DEFAULT NULL,
  `osize` bigint(10) DEFAULT NULL,
  `nfiles` int(11) DEFAULT NULL,
  `avg_transfer_rate` bigint(20) unsigned DEFAULT NULL COMMENT 'Average transfer rate in bytes/second',
  PRIMARY KEY (`id`),
  UNIQUE KEY `archive_id` (`archive_id`),
  KEY `repo_id_key` (`repo_id`),
  CONSTRAINT `repo_id_key` FOREIGN KEY (`repo_id`) REFERENCES `repository` (`repo_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Job name (e.g., MySQL Daily Backup)',
  `description` text DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL COMMENT 'Link to backup source',
  `repository_id` int(11) NOT NULL COMMENT 'Repository to backup',
  `schedule_type` enum('manual','daily','weekly','monthly','custom') NOT NULL DEFAULT 'manual' COMMENT 'Schedule type',
  `schedule_time` time DEFAULT NULL COMMENT 'Time to run (HH:MM:SS)',
  `schedule_day_of_week` tinyint(4) DEFAULT NULL COMMENT 'Day of week for weekly (1=Monday, 7=Sunday)',
  `schedule_day_of_month` tinyint(4) DEFAULT NULL COMMENT 'Day of month for monthly (1-31)',
  `cron_expression` varchar(100) DEFAULT NULL COMMENT 'Custom cron expression',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is job enabled?',
  `priority` enum('low','normal','high','critical') DEFAULT 'normal',
  `retention_override` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Override repository retention' CHECK (json_valid(`retention_override`)),
  `bandwidth_limit` int(11) DEFAULT NULL COMMENT 'KB/s, NULL = unlimited',
  `cpu_nice` int(11) DEFAULT 10 COMMENT 'Process nice level (0-19)',
  `compression_level` int(11) DEFAULT NULL COMMENT 'Override compression (1-9)',
  `notify_channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Notification channels and settings' CHECK (json_valid(`notify_channels`)),
  `notify_on_success` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Send notification on success?',
  `notify_on_failure` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Send notification on failure?',
  `notify_on_warning` tinyint(1) DEFAULT 1,
  `pre_job_hook` text DEFAULT NULL,
  `post_job_hook` text DEFAULT NULL,
  `depends_on_job_id` int(11) DEFAULT NULL COMMENT 'Job that must complete first',
  `conflict_jobs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Jobs that cannot run simultaneously' CHECK (json_valid(`conflict_jobs`)),
  `last_run_at` datetime DEFAULT NULL COMMENT 'Last execution timestamp',
  `next_run_at` datetime DEFAULT NULL COMMENT 'Next scheduled execution (calculated)',
  `total_runs` int(11) DEFAULT 0,
  `success_runs` int(11) DEFAULT 0,
  `failed_runs` int(11) DEFAULT 0,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tags for categorization' CHECK (json_valid(`tags`)),
  `last_status` enum('success','failure','running') DEFAULT NULL COMMENT 'Last run status',
  `last_duration_seconds` int(11) DEFAULT NULL,
  `last_size_bytes` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repository_id` (`repository_id`),
  KEY `enabled` (`enabled`),
  KEY `next_run_at` (`next_run_at`),
  KEY `schedule_type` (`schedule_type`),
  KEY `idx_jobs_to_run` (`enabled`,`next_run_at`),
  KEY `source_id` (`source_id`),
  KEY `idx_jobs_priority` (`priority`,`enabled`),
  KEY `idx_jobs_dependencies` (`depends_on_job_id`),
  CONSTRAINT `backup_jobs_repository_fk` FOREIGN KEY (`repository_id`) REFERENCES `repository` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Scheduled backup jobs configuration';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `type` enum('interval','daily','weekly','monthly','cron','advanced') NOT NULL,
  `time` time NOT NULL COMMENT 'Backup time (HH:MM:SS)',
  `timezone` varchar(50) DEFAULT 'UTC',
  `weekdays` int(11) DEFAULT NULL COMMENT 'Bitmap of selected weekdays',
  `monthdays` int(11) DEFAULT NULL COMMENT 'Bitmap of selected month days',
  `interval_hours` int(11) DEFAULT NULL COMMENT 'For interval type: run every N hours',
  `cron_expression` varchar(100) DEFAULT NULL COMMENT 'For cron type',
  `window_start` time DEFAULT NULL COMMENT 'Earliest time to start',
  `window_end` time DEFAULT NULL COMMENT 'Latest time to start',
  `max_runtime` int(11) DEFAULT 14400 COMMENT 'Max runtime in seconds (default 4h)',
  `blackout_periods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Periods when backup should not run' CHECK (json_valid(`blackout_periods`)),
  `retry_on_failure` tinyint(1) DEFAULT 1,
  `max_retries` int(11) DEFAULT 3,
  `retry_delay_minutes` int(11) DEFAULT 30,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_id` (`job_id`),
  KEY `type` (`type`),
  KEY `idx_schedules_next_run` (`type`,`time`),
  CONSTRAINT `backup_schedules_job_fk` FOREIGN KEY (`job_id`) REFERENCES `backup_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Advanced backup scheduling configuration';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Source name (e.g., Production MySQL)',
  `type` enum('mysql','postgresql','files','docker','vm','custom','system','mongodb') NOT NULL,
  `server_id` int(11) NOT NULL COMMENT 'Server where source is located',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Type-specific config (DB name, file paths, container ID, etc.)' CHECK (json_valid(`config`)),
  `paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'File paths for file-type backups' CHECK (json_valid(`paths`)),
  `exclude_patterns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Exclusion patterns' CHECK (json_valid(`exclude_patterns`)),
  `pre_backup_script` text DEFAULT NULL COMMENT 'Script to run before backup',
  `post_backup_script` text DEFAULT NULL COMMENT 'Script to run after backup',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tags for categorization' CHECK (json_valid(`tags`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `type` (`type`),
  KEY `active` (`active`),
  CONSTRAINT `backup_sources_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Backup source definitions';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `backup_type` enum('files','mysql','mariadb','postgresql','mongodb','docker','system','custom') NOT NULL,
  `source_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Type-specific configuration template' CHECK (json_valid(`source_config`)),
  `exclude_patterns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Default exclusion patterns' CHECK (json_valid(`exclude_patterns`)),
  `snapshot_method` varchar(50) DEFAULT NULL,
  `retention_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Default retention settings' CHECK (json_valid(`retention_policy`)),
  `compression` varchar(50) DEFAULT 'lz4',
  `encryption` varchar(50) DEFAULT 'repokey-blake2',
  `pre_backup_script` text DEFAULT NULL,
  `post_backup_script` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0 COMMENT 'System template (cannot be deleted)',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `usage_count` int(11) DEFAULT 0 COMMENT 'How many times this template was used',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `backup_type` (`backup_type`),
  KEY `is_system` (`is_system`),
  KEY `idx_template_type` (`backup_type`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Reusable backup configuration templates';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `database_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `database_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `db_type` enum('mysql','mariadb','postgresql','mongodb','redis','elasticsearch','influxdb') NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Friendly name for this credential set',
  `host` varchar(255) DEFAULT 'localhost',
  `port` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` text NOT NULL COMMENT 'Encrypted password',
  `auth_database` varchar(100) DEFAULT NULL COMMENT 'Authentication database (MongoDB)',
  `auth_method` enum('password','socket','ssl','kerberos','ldap') DEFAULT 'password',
  `ssl_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'SSL/TLS configuration' CHECK (json_valid(`ssl_config`)),
  `connection_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional connection options' CHECK (json_valid(`connection_options`)),
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'Default credentials for this DB type on server',
  `auto_detected` tinyint(1) DEFAULT 0 COMMENT 'Were these credentials auto-detected?',
  `test_status` enum('untested','success','failed') DEFAULT 'untested',
  `test_message` text DEFAULT NULL,
  `last_test_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_db_default` (`server_id`,`db_type`,`is_default`),
  KEY `server_id` (`server_id`),
  KEY `db_type` (`db_type`),
  KEY `is_default` (`is_default`),
  KEY `idx_cred_server_type` (`server_id`,`db_type`),
  CONSTRAINT `db_credentials_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Database credentials for automated backups';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `db_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `db_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `server_id` int(11) NOT NULL,
  `repo_id` varchar(64) NOT NULL,
  `db_host` varchar(50) NOT NULL,
  `db_user` varchar(50) NOT NULL,
  `db_pass` varchar(50) NOT NULL,
  `vg_name` varchar(20) NOT NULL,
  `lvm_part` varchar(20) NOT NULL,
  `lvsize` varchar(5) NOT NULL,
  `pg_svg_path` text NOT NULL,
  `mysql_path` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  CONSTRAINT `db_info_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `instant_recovery_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `instant_recovery_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `archive_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `db_type` varchar(20) NOT NULL COMMENT 'postgresql, mysql, mongodb',
  `deployment_location` varchar(10) NOT NULL DEFAULT 'remote' COMMENT 'remote (on source server) or local (on phpBorg backup server)',
  `status` varchar(20) NOT NULL DEFAULT 'starting' COMMENT 'starting, active, stopping, stopped, failed',
  `borg_mount_point` varchar(255) NOT NULL COMMENT 'Borg FUSE mount point',
  `temp_data_dir` varchar(255) DEFAULT NULL,
  `db_port` int(11) NOT NULL COMMENT 'Temporary database port (e.g., 15432 for PostgreSQL)',
  `admin_port` int(11) DEFAULT NULL,
  `admin_token` varchar(64) DEFAULT NULL,
  `admin_container_id` varchar(64) DEFAULT NULL,
  `db_pid` int(11) DEFAULT NULL COMMENT 'Database process PID',
  `db_socket` varchar(255) DEFAULT NULL COMMENT 'Unix socket path if applicable',
  `connection_string` text DEFAULT NULL COMMENT 'Connection string for users',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL COMMENT 'When database instance started',
  `stopped_at` timestamp NULL DEFAULT NULL COMMENT 'When session was stopped',
  `error_message` text DEFAULT NULL COMMENT 'Error message if failed',
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `idx_status` (`status`),
  KEY `idx_archive` (`archive_id`),
  KEY `idx_active` (`status`,`created_at`),
  KEY `idx_deployment` (`deployment_location`),
  CONSTRAINT `instant_recovery_sessions_ibfk_1` FOREIGN KEY (`archive_id`) REFERENCES `archives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `instant_recovery_sessions_ibfk_2` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) NOT NULL DEFAULT 'default',
  `type` varchar(100) NOT NULL COMMENT 'server_setup, backup_create, backup_restore, etc.',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Job parameters and data' CHECK (json_valid(`payload`)),
  `status` enum('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `worker_id` varchar(20) DEFAULT NULL,
  `progress` int(11) NOT NULL DEFAULT 0 COMMENT 'Progress percentage 0-100',
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of execution attempts',
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `output` text DEFAULT NULL COMMENT 'Job output/logs',
  `error` text DEFAULT NULL COMMENT 'Error message if failed',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created the job',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `queue` (`queue`),
  KEY `type` (`type`),
  KEY `created_at` (`created_at`),
  KEY `created_by` (`created_by`),
  KEY `idx_worker_fetch` (`status`,`queue`,`created_at`),
  CONSTRAINT `fk_jobs_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3891 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  KEY `revoked` (`revoked`),
  CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=527 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `type` varchar(10) NOT NULL,
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `dur` int(11) DEFAULT NULL,
  `nfiles` bigint(20) DEFAULT NULL,
  `osize` bigint(20) DEFAULT NULL,
  `csize` bigint(20) DEFAULT NULL,
  `dsize` bigint(20) DEFAULT NULL,
  `nb_archive` int(11) DEFAULT NULL,
  `curpos` varchar(50) DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `error` int(11) DEFAULT NULL,
  `log` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repository`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `repository` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `storage_pool_id` int(11) DEFAULT 1 COMMENT 'Storage pool where repository is located',
  `repo_id` varchar(64) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `retention` int(11) NOT NULL,
  `compression` varchar(10) NOT NULL,
  `ratelimit` varchar(10) NOT NULL,
  `backup_path` text NOT NULL,
  `exclude` text NOT NULL,
  `snapshot_method` enum('none','lvm','zfs','btrfs','vmware','hyperv','proxmox','xen') DEFAULT 'none',
  `snapshot_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot-specific configuration' CHECK (json_valid(`snapshot_config`)),
  `pre_backup_commands` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Commands to run before backup' CHECK (json_valid(`pre_backup_commands`)),
  `post_backup_commands` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Commands to run after backup' CHECK (json_valid(`post_backup_commands`)),
  `encryption` varchar(50) DEFAULT NULL,
  `passphrase` text NOT NULL,
  `size` bigint(20) DEFAULT NULL,
  `dsize` bigint(20) DEFAULT NULL,
  `csize` bigint(20) DEFAULT NULL,
  `ttuchunks` bigint(20) DEFAULT NULL,
  `ttchunks` bigint(20) DEFAULT NULL,
  `repo_path` varchar(200) DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `last_prune_at` datetime DEFAULT NULL,
  `auto_prune` tinyint(1) DEFAULT 1 COMMENT 'Enable automatic pruning',
  `keep_daily` int(11) NOT NULL DEFAULT 7 COMMENT 'Number of daily backups to keep',
  `keep_weekly` int(11) NOT NULL DEFAULT 4 COMMENT 'Number of weekly backups to keep',
  `keep_monthly` int(11) NOT NULL DEFAULT 6 COMMENT 'Number of monthly backups to keep',
  `keep_yearly` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of yearly backups to keep (0 = disabled)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `repo_id` (`repo_id`),
  UNIQUE KEY `location` (`repo_path`),
  KEY `server_id` (`server_id`),
  KEY `storage_pool_id` (`storage_pool_id`),
  KEY `idx_repo_snapshot` (`snapshot_method`),
  KEY `idx_repo_pool` (`storage_pool_id`),
  CONSTRAINT `repository_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `restore_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `restore_operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `archive_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_type` enum('docker','mysql','postgresql','mongodb','filesystem','system') NOT NULL,
  `mode` enum('express','pro_safe') NOT NULL DEFAULT 'express',
  `restore_type` enum('full','volumes_only','compose_only','database_only','custom','files_only') NOT NULL,
  `destination` enum('in_place','alternative') NOT NULL,
  `alternative_path` varchar(500) DEFAULT NULL,
  `compose_path_adaptation` enum('none','auto_modify','generate_new') DEFAULT 'none',
  `selected_items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected_items`)),
  `lvm_snapshot_created` tinyint(1) DEFAULT 0,
  `lvm_snapshot_name` varchar(100) DEFAULT NULL,
  `pre_restore_backup_created` tinyint(1) DEFAULT 0,
  `pre_restore_backup_archive` varchar(100) DEFAULT NULL,
  `auto_restart` tinyint(1) DEFAULT 1,
  `stopped_containers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`stopped_containers`)),
  `status` enum('draft','pending','running','completed','failed','rolled_back') NOT NULL DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `generated_script` longtext DEFAULT NULL,
  `script_executed` tinyint(1) DEFAULT 0,
  `can_rollback_until` datetime DEFAULT NULL,
  `rolled_back_at` datetime DEFAULT NULL,
  `items_restored` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items_restored`)),
  `bytes_restored` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_archive` (`archive_id`),
  KEY `idx_server` (`server_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_rollback` (`can_rollback_until`),
  CONSTRAINT `restore_operations_ibfk_1` FOREIGN KEY (`archive_id`) REFERENCES `archives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restore_operations_ibfk_2` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restore_operations_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL COMMENT 'Role name (e.g., ROLE_ADMIN, ROLE_OPERATOR)',
  `permission` varchar(100) NOT NULL COMMENT 'Permission key (e.g., servers.create, backups.delete)',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is permission enabled for this role?',
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission` (`role`,`permission`),
  KEY `role` (`role`),
  KEY `permission` (`permission`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `server_snapshot_capabilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_snapshot_capabilities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `snapshot_type` enum('lvm','zfs','btrfs','vmware','hyperv','proxmox','xen') NOT NULL,
  `available` tinyint(1) NOT NULL DEFAULT 0,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Type-specific details (volumes, datasets, etc.)' CHECK (json_valid(`details`)),
  `mount_points` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Mount points that can be snapshotted' CHECK (json_valid(`mount_points`)),
  `last_scan_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_type` (`server_id`,`snapshot_type`),
  KEY `server_id` (`server_id`),
  CONSTRAINT `snapshot_cap_server_fk` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Detected snapshot capabilities per server';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `server_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `os_distribution` varchar(255) DEFAULT NULL,
  `os_version` varchar(100) DEFAULT NULL,
  `kernel_version` varchar(100) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `architecture` varchar(50) DEFAULT NULL COMMENT 'CPU architecture (e.g., x86_64, aarch64)',
  `cpu_cores` int(11) DEFAULT NULL,
  `cpu_model` varchar(255) DEFAULT NULL COMMENT 'CPU model name',
  `cpu_load_1` float DEFAULT NULL,
  `cpu_load_5` float DEFAULT NULL,
  `cpu_load_15` float DEFAULT NULL,
  `cpu_usage_percent` float DEFAULT NULL COMMENT 'CPU usage percentage',
  `memory_total_mb` int(11) DEFAULT NULL,
  `memory_used_mb` int(11) DEFAULT NULL,
  `memory_free_mb` int(11) DEFAULT NULL,
  `memory_available_mb` int(11) DEFAULT NULL COMMENT 'Available memory in MB',
  `memory_percent` float DEFAULT NULL,
  `swap_total_mb` int(11) DEFAULT NULL COMMENT 'Total swap in MB',
  `swap_used_mb` int(11) DEFAULT NULL COMMENT 'Used swap in MB',
  `swap_percent` float DEFAULT NULL COMMENT 'Swap usage percentage',
  `disk_total_gb` float DEFAULT NULL,
  `disk_used_gb` float DEFAULT NULL,
  `disk_free_gb` float DEFAULT NULL,
  `disk_percent` float DEFAULT NULL,
  `disk_mount_point` varchar(255) DEFAULT '/' COMMENT 'Mount point monitored',
  `uptime_seconds` bigint(20) DEFAULT NULL,
  `uptime_human` varchar(100) DEFAULT NULL,
  `boot_time` timestamp NULL DEFAULT NULL COMMENT 'System boot timestamp',
  `ip_address` varchar(45) DEFAULT NULL,
  `collected_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_server_collected` (`server_id`,`collected_at`),
  CONSTRAINT `server_stats_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2573 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `host` varchar(150) NOT NULL,
  `backuptype` varchar(30) NOT NULL DEFAULT 'internal',
  `port` int(11) NOT NULL,
  `ssh_pub_key` text NOT NULL,
  `ssh_private_key_path` varchar(255) DEFAULT '/root/.ssh/phpborg_backup' COMMENT 'Path to private SSH key on remote server for borg connections',
  `ssh_keys_deployed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether SSH keys have been deployed to remote server',
  `backup_server_user` varchar(50) DEFAULT 'phpborg' COMMENT 'Username on backup server for borg serve connections',
  `active` int(11) NOT NULL,
  `capabilities_detected` tinyint(1) DEFAULT 0 COMMENT 'Whether capabilities have been detected',
  `capabilities_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON data of detected capabilities' CHECK (json_valid(`capabilities_data`)),
  `capabilities_detected_at` timestamp NULL DEFAULT NULL COMMENT 'When capabilities were last detected',
  PRIMARY KEY (`id`),
  KEY `idx_capabilities_detected` (`capabilities_detected`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL COMMENT 'Setting key (e.g., app.name, smtp.host)',
  `value` text DEFAULT NULL COMMENT 'Setting value (JSON for complex values)',
  `category` varchar(50) NOT NULL COMMENT 'Category: general, email, backup, borg, security, network',
  `type` varchar(20) NOT NULL COMMENT 'Type: string, integer, boolean, json',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storage_pools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `storage_pools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Storage pool name',
  `path` varchar(500) NOT NULL COMMENT 'Absolute filesystem path',
  `type` enum('local','nfs','s3','sftp','smb','azure','gcs') DEFAULT 'local',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Type-specific configuration' CHECK (json_valid(`config`)),
  `performance_tier` enum('archive','standard','performance') DEFAULT 'standard',
  `health_status` enum('healthy','degraded','critical','unknown') DEFAULT 'unknown',
  `last_health_check` datetime DEFAULT NULL,
  `quota_bytes` bigint(20) DEFAULT NULL COMMENT 'Storage quota in bytes',
  `alert_threshold_percent` int(11) DEFAULT 80 COMMENT 'Alert when usage exceeds %',
  `description` text DEFAULT NULL,
  `capacity_total` bigint(20) DEFAULT NULL COMMENT 'Total capacity in bytes (NULL = unknown)',
  `capacity_used` bigint(20) DEFAULT 0 COMMENT 'Used space in bytes',
  `filesystem_type` varchar(50) DEFAULT NULL COMMENT 'Filesystem type: ext4, xfs, nfs, btrfs, etc.',
  `storage_type` varchar(50) DEFAULT NULL COMMENT 'Storage type: local_disk, nfs, smb, unknown',
  `mount_point` varchar(500) DEFAULT NULL COMMENT 'Mount point path',
  `available_bytes` bigint(20) DEFAULT NULL COMMENT 'Available space in bytes',
  `usage_percent` int(11) DEFAULT NULL COMMENT 'Usage percentage (0-100)',
  `last_analyzed_at` datetime DEFAULT NULL COMMENT 'Last filesystem analysis timestamp',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Is pool active?',
  `default_pool` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this the default pool for new repositories?',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `path` (`path`),
  KEY `active` (`active`),
  KEY `default_pool` (`default_pool`),
  KEY `storage_type` (`storage_type`),
  KEY `filesystem_type` (`filesystem_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Argon2id hash',
  `email` varchar(255) NOT NULL,
  `roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of roles' CHECK (json_valid(`roles`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wizard_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wizard_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `wizard_type` enum('backup','restore','migration') NOT NULL DEFAULT 'backup',
  `current_step` int(11) DEFAULT 1,
  `total_steps` int(11) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Wizard state data' CHECK (json_valid(`data`)),
  `completed` tinyint(1) DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `wizard_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Wizard session state management';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

