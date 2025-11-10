-- Add columns to servers table for storing capabilities
ALTER TABLE servers 
ADD COLUMN capabilities_detected TINYINT(1) DEFAULT 0 COMMENT 'Whether capabilities have been detected',
ADD COLUMN capabilities_data JSON DEFAULT NULL COMMENT 'JSON data of detected capabilities',
ADD COLUMN capabilities_detected_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When capabilities were last detected';

-- Index for faster queries
ALTER TABLE servers ADD INDEX idx_capabilities_detected (capabilities_detected);