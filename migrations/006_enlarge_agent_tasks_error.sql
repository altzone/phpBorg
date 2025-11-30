-- Migration 006: Enlarge error column in agent_tasks table
-- The error column needs to store full borg stderr output which can be very long

ALTER TABLE agent_tasks MODIFY COLUMN error MEDIUMTEXT NULL;

-- Also enlarge result column if needed
ALTER TABLE agent_tasks MODIFY COLUMN result MEDIUMTEXT NULL;
