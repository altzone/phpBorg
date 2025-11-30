-- Add computed_stats column to servers table
-- These stats are pre-computed and updated on events (backup complete, archive delete, etc.)

ALTER TABLE servers ADD COLUMN computed_stats JSON DEFAULT NULL AFTER capabilities_data;

-- Index for quick lookup of servers that need stats refresh
ALTER TABLE servers ADD COLUMN stats_computed_at DATETIME DEFAULT NULL AFTER computed_stats;
