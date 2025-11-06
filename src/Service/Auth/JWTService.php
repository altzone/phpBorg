<?php

declare(strict_types=1);

namespace PhpBorg\Service\Auth;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PhpBorg\Config\Configuration;
use PhpBorg\Entity\User;
use PhpBorg\Exception\PhpBorgException;

/**
 * JWT Service for token generation and validation
 */
final class JWTService
{
    private const ACCESS_TOKEN_LIFETIME = 900; // 15 minutes
    private const REFRESH_TOKEN_LIFETIME = 604800; // 7 days

    public function __construct(
        private readonly Configuration $config
    ) {
    }

    /**
     * Generate access token for user
     */
    public function generateAccessToken(User $user): string
    {
        $now = new DateTimeImmutable();
        $exp = $now->getTimestamp() + self::ACCESS_TOKEN_LIFETIME;

        $payload = [
            'iss' => 'phpborg',
            'aud' => 'phpborg-api',
            'iat' => $now->getTimestamp(),
            'exp' => $exp,
            ...$user->toJWTPayload(),
        ];

        return JWT::encode($payload, $this->config->appSecret, 'HS256');
    }

    /**
     * Generate refresh token (simple random token, not JWT)
     */
    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Validate and decode access token
     *
     * @throws PhpBorgException
     */
    public function validateAccessToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config->appSecret, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            throw new PhpBorgException('Invalid or expired token: ' . $e->getMessage());
        }
    }

    /**
     * Extract user ID from token
     *
     * @throws PhpBorgException
     */
    public function getUserIdFromToken(string $token): int
    {
        $payload = $this->validateAccessToken($token);
        return (int)($payload['sub'] ?? throw new PhpBorgException('Invalid token payload'));
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired(string $token): bool
    {
        try {
            $this->validateAccessToken($token);
            return false;
        } catch (PhpBorgException $e) {
            return str_contains($e->getMessage(), 'Expired token');
        }
    }

    /**
     * Get access token expiration time
     */
    public function getAccessTokenLifetime(): int
    {
        return self::ACCESS_TOKEN_LIFETIME;
    }

    /**
     * Get refresh token expiration time
     */
    public function getRefreshTokenLifetime(): int
    {
        return self::REFRESH_TOKEN_LIFETIME;
    }

    /**
     * Get refresh token expiration date
     */
    public function getRefreshTokenExpiration(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->modify('+' . self::REFRESH_TOKEN_LIFETIME . ' seconds');
    }
}
