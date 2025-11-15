-- Add deployment_location column to instant_recovery_sessions if not exists
-- This allows choosing between remote (on source server) or local (on phpBorg server) deployment

ALTER TABLE instant_recovery_sessions
ADD COLUMN IF NOT EXISTS deployment_location VARCHAR(10) NOT NULL DEFAULT 'remote'
COMMENT 'remote (on source server) or local (on phpBorg backup server)'
AFTER db_type;

-- Add index on deployment_location
CREATE INDEX IF NOT EXISTS idx_deployment ON instant_recovery_sessions(deployment_location);
