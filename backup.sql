SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE `archives` (
	  `id` int(11) NOT NULL,
	  `repo` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `nom` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `archive_id` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `dur` float DEFAULT NULL,
	  `start` datetime DEFAULT NULL,
	  `end` datetime DEFAULT NULL,
	  `csize` bigint(10) DEFAULT NULL,
	  `dsize` bigint(10) DEFAULT NULL,
	  `osize` bigint(10) DEFAULT NULL,
	  `nfiles` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `db_info` (
	  `id` int(11) NOT NULL,
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
	  `mysql_path` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `report` (
	  `id` int(11) NOT NULL,
	  `start` datetime DEFAULT NULL,
	  `end` datetime DEFAULT NULL,
	  `dur` int(11) DEFAULT NULL,
	  `nfiles` bigint(20) DEFAULT NULL,
	  `osize` bigint(20) DEFAULT NULL,
	  `csize` bigint(20) DEFAULT NULL,
	  `dsize` bigint(20) DEFAULT NULL,
	  `nb_archive` int(11) DEFAULT NULL,
	  `curpos` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `status` int(11) DEFAULT NULL,
	  `error` int(11) DEFAULT NULL,
	  `log` text COLLATE utf8_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `repository` (
	  `id` int(11) NOT NULL,
	  `server_id` int(11) NOT NULL,
	  `repo_id` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
	  `type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `retention` int(11) NOT NULL,
	  `compression` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
	  `ratelimit` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
	  `backup_path` text COLLATE utf8_unicode_ci NOT NULL,
	  `exclude` text COLLATE utf8_unicode_ci NOT NULL,
	  `encryption` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `passphrase` text COLLATE utf8_unicode_ci NOT NULL,
	  `size` bigint(20) DEFAULT NULL,
	  `dsize` bigint(20) DEFAULT NULL,
	  `csize` bigint(20) DEFAULT NULL,
	  `ttuchunks` bigint(20) DEFAULT NULL,
	  `ttchunks` bigint(20) DEFAULT NULL,
	  `repo_path` varchar(200) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `modified` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `servers` (
	  `id` int(11) NOT NULL,
	  `name` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
	  `host` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
	  `port` int(11) NOT NULL,
	  `backuptype` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
	  `repo_id` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
	  `compression` varchar(5) COLLATE utf8_unicode_ci NOT NULL,
	  `ratelimit` int(11) NOT NULL,
	  `backup_path` varchar(150) COLLATE utf8_unicode_ci NOT NULL,
	  `exclude` text COLLATE utf8_unicode_ci NOT NULL,
	  `retention` int(11) NOT NULL,
	  `ssh_pub_key` text COLLATE utf8_unicode_ci NOT NULL,
	  `passphrase` text COLLATE utf8_unicode_ci,
	  `db_mysql` int(11) DEFAULT NULL,
	  `db_postgres` int(11) DEFAULT NULL,
	  `active` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


ALTER TABLE `archives`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `archive_id` (`archive_id`);

ALTER TABLE `db_info`
  ADD PRIMARY KEY (`id`),
  ADD KEY `server_id` (`server_id`);

ALTER TABLE `report`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `repository`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `repo_id` (`repo_id`),
  ADD UNIQUE KEY `location` (`repo_path`),
  ADD KEY `server_id` (`server_id`);

ALTER TABLE `servers`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `archives`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `db_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `report`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `repository`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `servers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `db_info`
  ADD CONSTRAINT `db_info_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

ALTER TABLE `repository`
  ADD CONSTRAINT `repository_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

