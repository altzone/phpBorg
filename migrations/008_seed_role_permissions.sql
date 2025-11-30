-- Migration: Seed default role permissions
-- Date: 2024-11-30

-- Clear existing data (if any)
TRUNCATE TABLE role_permissions;

-- Define all permissions
-- Format: module.action

-- ROLE_ADMIN - Full access to everything
INSERT INTO role_permissions (role, permission, enabled) VALUES
-- Servers
('ROLE_ADMIN', 'servers.view', 1),
('ROLE_ADMIN', 'servers.create', 1),
('ROLE_ADMIN', 'servers.edit', 1),
('ROLE_ADMIN', 'servers.delete', 1),
-- Backups
('ROLE_ADMIN', 'backups.view', 1),
('ROLE_ADMIN', 'backups.create', 1),
('ROLE_ADMIN', 'backups.restore', 1),
('ROLE_ADMIN', 'backups.delete', 1),
-- Repositories
('ROLE_ADMIN', 'repositories.view', 1),
('ROLE_ADMIN', 'repositories.create', 1),
('ROLE_ADMIN', 'repositories.edit', 1),
('ROLE_ADMIN', 'repositories.delete', 1),
-- Jobs
('ROLE_ADMIN', 'jobs.view', 1),
('ROLE_ADMIN', 'jobs.create', 1),
('ROLE_ADMIN', 'jobs.edit', 1),
('ROLE_ADMIN', 'jobs.delete', 1),
('ROLE_ADMIN', 'jobs.execute', 1),
-- Users
('ROLE_ADMIN', 'users.view', 1),
('ROLE_ADMIN', 'users.create', 1),
('ROLE_ADMIN', 'users.edit', 1),
('ROLE_ADMIN', 'users.delete', 1),
-- Settings
('ROLE_ADMIN', 'settings.view', 1),
('ROLE_ADMIN', 'settings.edit', 1),
-- System
('ROLE_ADMIN', 'system.maintenance', 1),
('ROLE_ADMIN', 'system.update', 1),
('ROLE_ADMIN', 'system.logs', 1);

-- ROLE_OPERATOR - Can manage servers/backups but not users/settings
INSERT INTO role_permissions (role, permission, enabled) VALUES
-- Servers
('ROLE_OPERATOR', 'servers.view', 1),
('ROLE_OPERATOR', 'servers.create', 1),
('ROLE_OPERATOR', 'servers.edit', 1),
('ROLE_OPERATOR', 'servers.delete', 0),
-- Backups
('ROLE_OPERATOR', 'backups.view', 1),
('ROLE_OPERATOR', 'backups.create', 1),
('ROLE_OPERATOR', 'backups.restore', 1),
('ROLE_OPERATOR', 'backups.delete', 0),
-- Repositories
('ROLE_OPERATOR', 'repositories.view', 1),
('ROLE_OPERATOR', 'repositories.create', 1),
('ROLE_OPERATOR', 'repositories.edit', 1),
('ROLE_OPERATOR', 'repositories.delete', 0),
-- Jobs
('ROLE_OPERATOR', 'jobs.view', 1),
('ROLE_OPERATOR', 'jobs.create', 1),
('ROLE_OPERATOR', 'jobs.edit', 1),
('ROLE_OPERATOR', 'jobs.delete', 0),
('ROLE_OPERATOR', 'jobs.execute', 1),
-- Users
('ROLE_OPERATOR', 'users.view', 0),
('ROLE_OPERATOR', 'users.create', 0),
('ROLE_OPERATOR', 'users.edit', 0),
('ROLE_OPERATOR', 'users.delete', 0),
-- Settings
('ROLE_OPERATOR', 'settings.view', 0),
('ROLE_OPERATOR', 'settings.edit', 0),
-- System
('ROLE_OPERATOR', 'system.maintenance', 0),
('ROLE_OPERATOR', 'system.update', 0),
('ROLE_OPERATOR', 'system.logs', 1);

-- ROLE_USER - Read-only access
INSERT INTO role_permissions (role, permission, enabled) VALUES
-- Servers
('ROLE_USER', 'servers.view', 1),
('ROLE_USER', 'servers.create', 0),
('ROLE_USER', 'servers.edit', 0),
('ROLE_USER', 'servers.delete', 0),
-- Backups
('ROLE_USER', 'backups.view', 1),
('ROLE_USER', 'backups.create', 0),
('ROLE_USER', 'backups.restore', 0),
('ROLE_USER', 'backups.delete', 0),
-- Repositories
('ROLE_USER', 'repositories.view', 1),
('ROLE_USER', 'repositories.create', 0),
('ROLE_USER', 'repositories.edit', 0),
('ROLE_USER', 'repositories.delete', 0),
-- Jobs
('ROLE_USER', 'jobs.view', 1),
('ROLE_USER', 'jobs.create', 0),
('ROLE_USER', 'jobs.edit', 0),
('ROLE_USER', 'jobs.delete', 0),
('ROLE_USER', 'jobs.execute', 0),
-- Users
('ROLE_USER', 'users.view', 0),
('ROLE_USER', 'users.create', 0),
('ROLE_USER', 'users.edit', 0),
('ROLE_USER', 'users.delete', 0),
-- Settings
('ROLE_USER', 'settings.view', 0),
('ROLE_USER', 'settings.edit', 0),
-- System
('ROLE_USER', 'system.maintenance', 0),
('ROLE_USER', 'system.update', 0),
('ROLE_USER', 'system.logs', 0);
