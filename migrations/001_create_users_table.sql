-- Migration: Create users table for JWT authentication
-- Date: 2025-11-06

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Argon2id hash',
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `roles` json NOT NULL COMMENT 'Array of roles: ["ROLE_ADMIN", "ROLE_OPERATOR"]',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Create default admin user
-- Username: admin
-- Password: admin123 (CHANGE THIS IN PRODUCTION!)
-- Roles: ROLE_ADMIN
INSERT INTO `users` (`username`, `password`, `email`, `roles`, `active`, `created_at`)
VALUES (
  'admin',
  '$argon2id$v=19$m=65536,t=4,p=1$cGhwQm9yZ1NhbHQxMjM0$zYvR7fH9kZPp0HxE5qL9xKmWuJ3RnGpB1TcYhNsV8aE',
  'admin@phpborg.local',
  '["ROLE_ADMIN"]',
  1,
  NOW()
);

-- Create refresh_tokens table for JWT refresh tokens
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  KEY `revoked` (`revoked`),
  CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
