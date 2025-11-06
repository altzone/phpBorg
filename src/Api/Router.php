<?php

declare(strict_types=1);

namespace PhpBorg\Api;

use PhpBorg\Application;
use PhpBorg\Api\Middleware\CorsMiddleware;
use PhpBorg\Api\Middleware\JWTMiddleware;

/**
 * Simple API Router
 */
final class Router
{
    private array $routes = [];
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register a route
     */
    public function add(
        string $method,
        string $path,
        string $controller,
        string $action,
        bool $requireAuth = false,
        ?array $requiredRoles = null
    ): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
            'requireAuth' => $requireAuth,
            'requiredRoles' => $requiredRoles,
        ];
    }

    /**
     * Handle the incoming request
     */
    public function dispatch(): void
    {
        // Apply CORS middleware
        CorsMiddleware::handle();

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove /api prefix if present
        $path = preg_replace('#^/api#', '', $path);

        foreach ($this->routes as $route) {
            if ($this->matchRoute($route, $method, $path)) {
                $this->handleRoute($route);
                return;
            }
        }

        // No route found
        $this->notFound();
    }

    /**
     * Check if route matches
     */
    private function matchRoute(array $route, string $method, string $path): bool
    {
        if ($route['method'] !== $method) {
            return false;
        }

        // Convert route pattern to regex
        $pattern = '#^' . preg_replace('#:(\w+)#', '(?P<$1>[^/]+)', $route['path']) . '$#';

        if (preg_match($pattern, $path, $matches)) {
            // Store path parameters in $_SERVER for controller access
            $_SERVER['ROUTE_PARAMS'] = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return true;
        }

        return false;
    }

    /**
     * Handle matched route
     */
    private function handleRoute(array $route): void
    {
        // Check authentication if required
        if ($route['requireAuth']) {
            $jwtMiddleware = new JWTMiddleware($this->app);

            if (!$jwtMiddleware->handle()) {
                return; // Middleware already sent response
            }

            // Check roles if required
            if ($route['requiredRoles'] !== null) {
                if (!$jwtMiddleware->requireAnyRole($route['requiredRoles'])) {
                    return; // Middleware already sent response
                }
            }
        }

        // Instantiate controller and call action
        $controllerClass = $route['controller'];
        $action = $route['action'];

        if (!class_exists($controllerClass)) {
            $this->error("Controller not found: {$controllerClass}", 500);
            return;
        }

        $controller = new $controllerClass($this->app);

        if (!method_exists($controller, $action)) {
            $this->error("Action not found: {$action}", 500);
            return;
        }

        $controller->$action();
    }

    /**
     * Send 404 Not Found response
     */
    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => 'Route not found',
                'code' => 'NOT_FOUND',
            ],
        ]);
    }

    /**
     * Send error response
     */
    private function error(string $message, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => 'ERROR',
            ],
        ]);
    }

    /**
     * GET route helper
     */
    public function get(
        string $path,
        string $controller,
        string $action,
        bool $requireAuth = false,
        ?array $requiredRoles = null
    ): void {
        $this->add('GET', $path, $controller, $action, $requireAuth, $requiredRoles);
    }

    /**
     * POST route helper
     */
    public function post(
        string $path,
        string $controller,
        string $action,
        bool $requireAuth = false,
        ?array $requiredRoles = null
    ): void {
        $this->add('POST', $path, $controller, $action, $requireAuth, $requiredRoles);
    }

    /**
     * PUT route helper
     */
    public function put(
        string $path,
        string $controller,
        string $action,
        bool $requireAuth = false,
        ?array $requiredRoles = null
    ): void {
        $this->add('PUT', $path, $controller, $action, $requireAuth, $requiredRoles);
    }

    /**
     * DELETE route helper
     */
    public function delete(
        string $path,
        string $controller,
        string $action,
        bool $requireAuth = false,
        ?array $requiredRoles = null
    ): void {
        $this->add('DELETE', $path, $controller, $action, $requireAuth, $requiredRoles);
    }
}
