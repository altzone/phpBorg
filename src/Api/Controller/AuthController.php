<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Auth\AuthService;

/**
 * Authentication Controller
 */
final class AuthController extends BaseController
{
    private readonly AuthService $authService;

    public function __construct(Application $app)
    {
        $this->authService = $app->getAuthService();
    }

    /**
     * POST /api/auth/login
     * Login with username and password
     */
    public function login(): void
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['username', 'password']);

        try {
            $result = $this->authService->login(
                $data['username'],
                $data['password']
            );

            $this->success([
                'user' => $result['user']->toArray(),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in' => $result['expires_in'],
            ], 'Login successful');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 401, 'INVALID_CREDENTIALS');
        }
    }

    /**
     * POST /api/auth/refresh
     * Refresh access token using refresh token
     */
    public function refresh(): void
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['refresh_token']);

        try {
            $result = $this->authService->refresh($data['refresh_token']);

            $this->success([
                'user' => $result['user']->toArray(),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in' => $result['expires_in'],
            ], 'Token refreshed successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 401, 'INVALID_REFRESH_TOKEN');
        }
    }

    /**
     * POST /api/auth/logout
     * Logout (revoke refresh token)
     */
    public function logout(): void
    {
        $data = $this->getJsonBody();

        if (isset($data['refresh_token'])) {
            try {
                $this->authService->logout($data['refresh_token']);
            } catch (PhpBorgException $e) {
                // Ignore errors, token might already be revoked
            }
        }

        $this->success(null, 'Logged out successfully');
    }

    /**
     * GET /api/auth/me
     * Get current user info (requires valid access token)
     *
     * This endpoint should be called AFTER JWTMiddleware validates the token
     * and sets $_SERVER['USER'] with the User object
     */
    public function me(): void
    {
        // User is set by JWTMiddleware
        if (!isset($_SERVER['USER'])) {
            $this->error('Unauthorized', 401, 'UNAUTHORIZED');
            return;
        }

        $user = $_SERVER['USER'];
        $this->success($user->toArray(), 'User info retrieved');
    }

    /**
     * POST /api/auth/logout-all
     * Logout from all devices (revoke all refresh tokens)
     * Requires valid access token
     */
    public function logoutAll(): void
    {
        // User is set by JWTMiddleware
        if (!isset($_SERVER['USER'])) {
            $this->error('Unauthorized', 401, 'UNAUTHORIZED');
            return;
        }

        $user = $_SERVER['USER'];

        try {
            $this->authService->logoutAll($user->id);
            $this->success(null, 'Logged out from all devices');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
