-- Bug 26: agent_tasks had no freshness signal, so a task whose agent was killed stayed
-- "running" until its (30-day) timeout. Add progress_updated_at, refreshed on every
-- progress report / keepalive from the agent, so a server-side watchdog can detect a
-- dead task (stale progress) and mark it failed / re-dispatch from the last checkpoint.
-- Idempotent (ADD COLUMN IF NOT EXISTS).
ALTER TABLE `agent_tasks`
  ADD COLUMN IF NOT EXISTS `progress_updated_at` DATETIME DEFAULT NULL
  COMMENT 'Last time the agent reported progress (dead-task watchdog)';
