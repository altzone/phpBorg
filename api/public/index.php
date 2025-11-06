<?php

declare(strict_types=1);

/**
 * phpBorg 2.0 - API REST Entry Point
 */

// Load Composer autoloader
require __DIR__ . '/../../vendor/autoload.php';

use PhpBorg\Application;
use PhpBorg\Api\Router;
use PhpBorg\Api\Controller\AuthController;

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
    // Future Routes (To be implemented)
    // ===========================================

    // Servers (Protected - ROLE_ADMIN or ROLE_OPERATOR)
    // $router->get('/servers', ServerController::class, 'list', true, ['ROLE_ADMIN', 'ROLE_OPERATOR']);
    // $router->get('/servers/:id', ServerController::class, 'show', true, ['ROLE_ADMIN', 'ROLE_OPERATOR']);
    // $router->post('/servers', ServerController::class, 'create', true, ['ROLE_ADMIN']);
    // $router->put('/servers/:id', ServerController::class, 'update', true, ['ROLE_ADMIN']);
    // $router->delete('/servers/:id', ServerController::class, 'delete', true, ['ROLE_ADMIN']);
    // $router->post('/servers/:id/test', ServerController::class, 'test', true, ['ROLE_ADMIN', 'ROLE_OPERATOR']);

    // Backups (Protected)
    // $router->get('/backups', BackupController::class, 'list', true);
    // $router->get('/backups/:id', BackupController::class, 'show', true);
    // $router->post('/backups', BackupController::class, 'create', true, ['ROLE_ADMIN', 'ROLE_OPERATOR']);

    // Jobs (Protected)
    // $router->get('/jobs', JobController::class, 'list', true);
    // $router->get('/jobs/:id', JobController::class, 'show', true);
    // $router->delete('/jobs/:id', JobController::class, 'cancel', true, ['ROLE_ADMIN', 'ROLE_OPERATOR']);

    // Logs (Protected)
    // $router->get('/logs', LogController::class, 'list', true);
    // $router->get('/logs/tail', LogController::class, 'tail', true); // SSE

    // Statistics (Protected)
    // $router->get('/stats/dashboard', StatsController::class, 'dashboard', true);

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
