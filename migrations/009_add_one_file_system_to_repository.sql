-- Bug 17: per-repository --one-file-system option for `borg create`.
-- When enabled, borg does not cross mount points, so backing up `/` on a
-- multi-filesystem host (e.g. `/` ext4 + `/data0` ZFS with .zfs snapshots)
-- does not spill into nested filesystems. Each listed source path (`/`, `/data0`,
-- `/boot/efi`) is still backed up independently. Disabled by default (backward
-- compatible: existing repos keep the previous behaviour).
ALTER TABLE `repository`
  ADD COLUMN IF NOT EXISTS `one_file_system` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Pass --one-file-system to borg create (do not cross mount points)';
