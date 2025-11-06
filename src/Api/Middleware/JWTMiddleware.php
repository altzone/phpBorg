<?php

declare(strict_types=1);

namespace PhpBorg\Api\Middleware;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Auth\AuthService;

/**
 * JWT Middleware for API authentication
 * Validates JWT token and sets current user
 */
final class JWTMiddleware
{
    private readonly AuthService $authService;

    public function __construct(Application $app)
    {
        $this->authService = $app->getAuthService();
    }

    /**
     * Validate JWT token and set current user
     *
     * @return bool True if authenticated, false otherwise
     */
    public function handle(): bool
    {
        $token = $this->getBearerToken();

        if ($token === null) {
            $this->unauthorized('No token provided');
            return false;
        }

        try {
            $user = $this->authService->getCurrentUser($token);

            // Set user in $_SERVER for controllers to access
            $_SERVER['USER'] = $user;

            return true;
        } catch (PhpBorgException $e) {
            $this->unauthorized($e->getMessage());
            return false;
        }
    }

    /**
     * Check if user has required role
     */
    public function requireRole(string $role): bool
    {
        if (!isset($_SERVER['USER'])) {
            $this->forbidden('Authentication required');
            return false;
        }

        $user = $_SERVER['USER'];

        try {
            $this->authService->requireRole($user, $role);
            return true;
        } catch (PhpBorgException $e) {
            $this->forbidden($e->getMessage());
            return false;
        }
    }

    /**
     * Check if user has any of the required roles
     */
    public function requireAnyRole(array $roles): bool
    {
        if (!isset($_SERVER['USER'])) {
            $this->forbidden('Authentication required');
            return false;
        }

        $user = $_SERVER['USER'];

        try {
            $this->authService->requireAnyRole($user, $roles);
            return true;
        } catch (PhpBorgException $e) {
            $this->forbidden($e->getMessage());
            return false;
        }
    }

    /**
     * Get bearer token from request
     */
    private function getBearerToken(): ?string
    {
        $headers = $this->getAuthorizationHeader();

        if ($headers !== null && preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get authorization header
     */
    private function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }

        return null;
    }

    /**
     * Send 401 Unauthorized response
     */
    private function unauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => 'UNAUTHORIZED',
            ],
        ]);
        exit;
    }

    /**
     * Send 403 Forbidden response
     */
    private function forbidden(string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => 'FORBIDDEN',
            ],
        ]);
        exit;
    }
}
