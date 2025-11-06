<?php

declare(strict_types=1);

namespace PhpBorg\Service\Auth;

use PhpBorg\Entity\User;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\RefreshTokenRepository;
use PhpBorg\Repository\UserRepository;

/**
 * Authentication Service
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly JWTService $jwtService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Authenticate user and generate tokens
     *
     * @return array{user: User, access_token: string, refresh_token: string, expires_in: int}
     * @throws PhpBorgException
     */
    public function login(string $username, string $password): array
    {
        // Find user
        $user = $this->userRepository->findByUsername($username);

        if ($user === null) {
            $this->logger->warning("Failed login attempt for username: {$username}", 'AUTH');
            throw new PhpBorgException('Invalid credentials');
        }

        // Check if user is active
        if (!$user->active) {
            $this->logger->warning("Login attempt for inactive user: {$username}", 'AUTH');
            throw new PhpBorgException('Account is inactive');
        }

        // Verify password
        if (!password_verify($password, $user->password)) {
            $this->logger->warning("Failed password verification for user: {$username}", 'AUTH');
            throw new PhpBorgException('Invalid credentials');
        }

        // Generate tokens
        $accessToken = $this->jwtService->generateAccessToken($user);
        $refreshToken = $this->jwtService->generateRefreshToken();

        // Store refresh token
        $this->refreshTokenRepository->create(
            $user->id,
            $refreshToken,
            $this->jwtService->getRefreshTokenExpiration()
        );

        // Update last login
        $this->userRepository->updateLastLogin($user->id);

        $this->logger->info("User logged in: {$username}", 'AUTH');

        return [
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwtService->getAccessTokenLifetime(),
        ];
    }

    /**
     * Refresh access token using refresh token
     *
     * @return array{user: User, access_token: string, refresh_token: string, expires_in: int}
     * @throws PhpBorgException
     */
    public function refresh(string $refreshToken): array
    {
        // Find and validate refresh token
        $tokenData = $this->refreshTokenRepository->findByToken($refreshToken);

        if ($tokenData === null) {
            $this->logger->warning("Invalid or expired refresh token used", 'AUTH');
            throw new PhpBorgException('Invalid or expired refresh token');
        }

        // Get user
        $user = $this->userRepository->findById((int)$tokenData['user_id']);

        if ($user === null || !$user->active) {
            $this->logger->warning("Refresh token used for invalid/inactive user", 'AUTH');
            throw new PhpBorgException('User not found or inactive');
        }

        // Revoke old refresh token (rotation)
        $this->refreshTokenRepository->revoke($refreshToken);

        // Generate new tokens
        $newAccessToken = $this->jwtService->generateAccessToken($user);
        $newRefreshToken = $this->jwtService->generateRefreshToken();

        // Store new refresh token
        $this->refreshTokenRepository->create(
            $user->id,
            $newRefreshToken,
            $this->jwtService->getRefreshTokenExpiration()
        );

        $this->logger->info("Tokens refreshed for user: {$user->username}", 'AUTH');

        return [
            'user' => $user,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => $this->jwtService->getAccessTokenLifetime(),
        ];
    }

    /**
     * Logout user (revoke refresh token)
     *
     * @throws PhpBorgException
     */
    public function logout(string $refreshToken): void
    {
        $this->refreshTokenRepository->revoke($refreshToken);
        $this->logger->info("User logged out", 'AUTH');
    }

    /**
     * Logout user from all devices (revoke all refresh tokens)
     *
     * @throws PhpBorgException
     */
    public function logoutAll(int $userId): void
    {
        $this->refreshTokenRepository->revokeAllForUser($userId);
        $this->logger->info("User logged out from all devices: {$userId}", 'AUTH');
    }

    /**
     * Get current user from access token
     *
     * @throws PhpBorgException
     */
    public function getCurrentUser(string $accessToken): User
    {
        $userId = $this->jwtService->getUserIdFromToken($accessToken);
        $user = $this->userRepository->findById($userId);

        if ($user === null || !$user->active) {
            throw new PhpBorgException('User not found or inactive');
        }

        return $user;
    }

    /**
     * Validate user has required role
     *
     * @throws PhpBorgException
     */
    public function requireRole(User $user, string $role): void
    {
        if (!$user->hasRole($role)) {
            $this->logger->warning(
                "Access denied for user {$user->username}, required role: {$role}",
                'AUTH'
            );
            throw new PhpBorgException('Insufficient permissions');
        }
    }

    /**
     * Validate user has any of the required roles
     *
     * @throws PhpBorgException
     */
    public function requireAnyRole(User $user, array $roles): void
    {
        if (!$user->hasAnyRole($roles)) {
            $this->logger->warning(
                "Access denied for user {$user->username}, required roles: " . implode(', ', $roles),
                'AUTH'
            );
            throw new PhpBorgException('Insufficient permissions');
        }
    }

    /**
     * Clean up expired refresh tokens (should be run periodically)
     */
    public function cleanupExpiredTokens(): int
    {
        $deleted = $this->refreshTokenRepository->deleteExpired();
        $this->logger->info("Cleaned up {$deleted} expired refresh tokens", 'AUTH');
        return $deleted;
    }
}
