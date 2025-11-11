<?php

declare(strict_types=1);

/**
 * phpBorg 2.0 - API REST Entry Point
 */

// Disable error display in production (errors are logged)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Load Composer autoloader
require __DIR__ . '/../../vendor/autoload.php';

use PhpBorg\Application;
use PhpBorg\Api\Router;
use PhpBorg\Api\Controller\AuthController;
use PhpBorg\Api\Controller\BackupController;
use PhpBorg\Api\Controller\BackupJobController;
use PhpBorg\Api\Controller\BackupSourceController;
use PhpBorg\Api\Controller\BackupScheduleController;
use PhpBorg\Api\Controller\BackupWizardController;
use PhpBorg\Api\Controller\DashboardController;
use PhpBorg\Api\Controller\EmailController;
use PhpBorg\Api\Controller\JobController;
use PhpBorg\Api\Controller\RepositoryController;
use PhpBorg\Api\Controller\RoleController;
use PhpBorg\Api\Controller\ServerController;
use PhpBorg\Api\Controller\SettingsController;
use PhpBorg\Api\Controller\StoragePoolController;
use PhpBorg\Api\Controller\UserController;

try {
    // Initialize application
    $app = new Application();

    // Create router
    $router = new Router($app);

    // ===========================================
    // Authentication Routes (Public)
    // ===========================================
    $router->post('/auth/login', AuthController::class, 'login');
    $router->post('/auth/refresh', AuthController::class, 'refresh');
    $router->post('/auth/logout', AuthController::class, 'logout');

    // ===========================================
    // Authentication Routes (Protected)
    // ===========================================
    $router->get('/auth/me', AuthController::class, 'me', requireAuth: true);
    $router->post('/auth/logout-all', AuthController::class, 'logoutAll', requireAuth: true);

    // ===========================================
    // Server Management Routes (Protected)
    // ===========================================
    $router->get('/servers', ServerController::class, 'list', requireAuth: true);
    $router->get('/servers/:id', ServerController::class, 'show', requireAuth: true);
    $router->post('/servers', ServerController::class, 'create', requireAuth: true);
    $router->put('/servers/:id', ServerController::class, 'update', requireAuth: true);
    $router->delete('/servers/:id', ServerController::class, 'delete', requireAuth: true);
    $router->get('/servers/:id/repositories', ServerController::class, 'repositories', requireAuth: true);

    // ===========================================
    // Job Queue Routes (Protected)
    // ===========================================
    $router->get('/jobs', JobController::class, 'list', requireAuth: true);
    $router->get('/jobs/stats', JobController::class, 'stats', requireAuth: true);
    $router->post('/jobs/test', JobController::class, 'createTest', requireAuth: true);
    $router->get('/jobs/:id', JobController::class, 'show', requireAuth: true);
    $router->post('/jobs/:id/cancel', JobController::class, 'cancel', requireAuth: true);
    $router->post('/jobs/:id/retry', JobController::class, 'retry', requireAuth: true);

    // ===========================================
    // Backup Routes (Protected)
    // ===========================================
    $router->get('/backups', BackupController::class, 'list', requireAuth: true);
    $router->get('/backups/stats', BackupController::class, 'stats', requireAuth: true);
    $router->post('/backups', BackupController::class, 'create', requireAuth: true);
    $router->get('/backups/:id', BackupController::class, 'show', requireAuth: true);
    $router->delete('/backups/:id', BackupController::class, 'delete', requireAuth: true);
    $router->post('/backups/:id/mount', BackupController::class, 'mount', requireAuth: true);
    $router->post('/backups/:id/unmount', BackupController::class, 'unmount', requireAuth: true);
    $router->get('/backups/:id/browse', BackupController::class, 'browse', requireAuth: true);
    $router->get('/backups/:id/download', BackupController::class, 'download', requireAuth: true);
    $router->get('/backups/:id/preview', BackupController::class, 'preview', requireAuth: true);

    // ===========================================
    // Backup Job Routes (Protected)
    // ===========================================
    $router->get('/backup-jobs', BackupJobController::class, 'list', requireAuth: true);
    $router->get('/backup-jobs/:id', BackupJobController::class, 'show', requireAuth: true);
    $router->post('/backup-jobs', BackupJobController::class, 'create', requireAuth: true);
    $router->put('/backup-jobs/:id', BackupJobController::class, 'update', requireAuth: true);
    $router->post('/backup-jobs/:id/toggle', BackupJobController::class, 'toggle', requireAuth: true);
    $router->post('/backup-jobs/:id/run', BackupJobController::class, 'run', requireAuth: true);
    $router->delete('/backup-jobs/:id', BackupJobController::class, 'delete', requireAuth: true);

    // ===========================================
    // Backup Source Routes (Protected)
    // ===========================================
    $router->get('/backup-sources', BackupSourceController::class, 'index', requireAuth: true);
    $router->get('/backup-sources/active', BackupSourceController::class, 'active', requireAuth: true);
    $router->get('/backup-sources/types', BackupSourceController::class, 'types', requireAuth: true);
    $router->get('/backup-sources/statistics', BackupSourceController::class, 'statistics', requireAuth: true);
    $router->get('/backup-sources/search', BackupSourceController::class, 'search', requireAuth: true);
    $router->post('/backup-sources/validate', BackupSourceController::class, 'validate', requireAuth: true);
    $router->get('/backup-sources/:id', BackupSourceController::class, 'show', requireAuth: true);
    $router->post('/backup-sources', BackupSourceController::class, 'create', requireAuth: true);
    $router->put('/backup-sources/:id', BackupSourceController::class, 'update', requireAuth: true);
    $router->delete('/backup-sources/:id', BackupSourceController::class, 'delete', requireAuth: true);
    $router->get('/backup-sources/by-server/:serverId', BackupSourceController::class, 'byServer', requireAuth: true);
    $router->get('/backup-sources/by-type/:type', BackupSourceController::class, 'byType', requireAuth: true);

    // ===========================================
    // Backup Schedule Routes (Protected)
    // ===========================================
    $router->get('/backup-schedules', BackupScheduleController::class, 'index', requireAuth: true);
    $router->get('/backup-schedules/due', BackupScheduleController::class, 'due', requireAuth: true);
    $router->get('/backup-schedules/types', BackupScheduleController::class, 'types', requireAuth: true);
    $router->post('/backup-schedules/preview', BackupScheduleController::class, 'preview', requireAuth: true);
    $router->get('/backup-schedules/:id', BackupScheduleController::class, 'show', requireAuth: true);
    $router->get('/backup-schedules/by-job/:jobId', BackupScheduleController::class, 'byJob', requireAuth: true);
    $router->post('/backup-schedules', BackupScheduleController::class, 'create', requireAuth: true);
    $router->put('/backup-schedules/:id', BackupScheduleController::class, 'update', requireAuth: true);
    $router->delete('/backup-schedules/:id', BackupScheduleController::class, 'delete', requireAuth: true);

    // ===========================================
    // Repository Routes (Protected)
    // ===========================================
    $router->get('/repositories', RepositoryController::class, 'list', requireAuth: true);
    $router->get('/repositories/:id', RepositoryController::class, 'show', requireAuth: true);
    $router->get('/repositories/:id/backup-jobs', BackupJobController::class, 'listByRepository', requireAuth: true);
    $router->put('/repositories/:id/retention', RepositoryController::class, 'updateRetention', requireAuth: true);

    // ===========================================
    // Dashboard Routes (Protected)
    // ===========================================
    $router->get('/dashboard/stats', DashboardController::class, 'stats', requireAuth: true);

    // ===========================================
    // User Management Routes (Protected - Admin only)
    // ===========================================
    $router->get('/users', UserController::class, 'list', requireAuth: true);
    $router->get('/users/:id', UserController::class, 'show', requireAuth: true);
    $router->post('/users', UserController::class, 'create', requireAuth: true);
    $router->put('/users/:id', UserController::class, 'update', requireAuth: true);
    $router->put('/users/:id/password', UserController::class, 'resetPassword', requireAuth: true);
    $router->delete('/users/:id', UserController::class, 'delete', requireAuth: true);

    // ===========================================
    // Role & Permission Routes (Protected - Admin only)
    // ===========================================
    $router->get('/roles', RoleController::class, 'list', requireAuth: true);
    $router->get('/roles/:role', RoleController::class, 'show', requireAuth: true);
    $router->put('/roles/:role/permissions', RoleController::class, 'updatePermissions', requireAuth: true);
    $router->get('/permissions', RoleController::class, 'listPermissions', requireAuth: true);

    // ===========================================
    // Settings Routes (Protected)
    // ===========================================
    $router->get('/settings', SettingsController::class, 'list', requireAuth: true);
    $router->get('/settings/:category', SettingsController::class, 'getByCategory', requireAuth: true);
    $router->put('/settings', SettingsController::class, 'update', requireAuth: true);
    $router->put('/settings/:category', SettingsController::class, 'updateCategory', requireAuth: true);

    // ===========================================
    // Storage Pool Routes (Protected)
    // ===========================================
    $router->get('/storage-pools', StoragePoolController::class, 'list', requireAuth: true);
    $router->post('/storage-pools/analyze', StoragePoolController::class, 'analyzePath', requireAuth: true);
    $router->get('/storage-pools/:id', StoragePoolController::class, 'show', requireAuth: true);
    $router->post('/storage-pools', StoragePoolController::class, 'create', requireAuth: true);
    $router->put('/storage-pools/:id', StoragePoolController::class, 'update', requireAuth: true);
    $router->delete('/storage-pools/:id', StoragePoolController::class, 'delete', requireAuth: true);

    // ===========================================
    // Email Routes (Protected)
    // ===========================================
    $router->post('/email/test', EmailController::class, 'sendTest', requireAuth: true);

    // ===========================================
    // Backup Wizard Routes (Protected)
    // ===========================================
    $router->get('/backup-wizard/capabilities/:serverId', BackupWizardController::class, 'capabilities', requireAuth: true);
    $router->get('/backup-wizard/job-status/:jobId', BackupWizardController::class, 'jobStatus', requireAuth: true);
    $router->post('/backup-wizard/detect-mysql', BackupWizardController::class, 'detectMySQL', requireAuth: true);
    $router->post('/backup-wizard/test-db-connection', BackupWizardController::class, 'testDatabaseConnection', requireAuth: true);
    $router->post('/backup-wizard/create-backup-chain', BackupWizardController::class, 'createBackupChain', requireAuth: true);
    $router->get('/backup-wizard/templates', BackupWizardController::class, 'templates', requireAuth: true);

    // ===========================================
    // Future Routes (To be implemented)
    // ===========================================

    // Logs (Protected)
    // $router->get('/logs', LogController::class, 'list', true);
    // $router->get('/logs/tail', LogController::class, 'tail', true); // SSE

    // Dispatch request
    $router->dispatch();

} catch (\Throwable $e) {
    // Global error handler
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Internal server error: ' . $e->getMessage(),
            'code' => 'INTERNAL_ERROR',
        ],
    ]);
}
