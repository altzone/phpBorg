-- Session fix: refresh tokens are rotated (revoked on first use). Concurrent refreshes
-- from multiple tabs/requests with the same token made the losers fail and logged the
-- user out. revoked_at enables a short reuse-grace window (30s) that absorbs those
-- races (standard "reuse interval" pattern).
-- Idempotent (ADD COLUMN IF NOT EXISTS).
ALTER TABLE `refresh_tokens`
  ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME DEFAULT NULL
  COMMENT 'When the token was rotated/revoked (grace-window checks)';
