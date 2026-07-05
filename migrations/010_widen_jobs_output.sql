-- Bug 24: jobs.output was TEXT (64 KB) and overflowed with borg's --progress/--log-json
-- stream over hundreds of thousands of files ("Data too long for column 'output'").
-- Widen it to LONGTEXT. The agent now also only persists the final --json stats plus a
-- tail of the stream, but keep the column wide as a safety net.
-- Idempotent: re-running MODIFY to the same type is a no-op.
ALTER TABLE `jobs` MODIFY COLUMN `output` LONGTEXT DEFAULT NULL COMMENT 'Job output/logs';
