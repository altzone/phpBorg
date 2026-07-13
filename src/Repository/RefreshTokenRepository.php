<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Refresh Tokens
 */
final class RefreshTokenRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Create refresh token
     *
     * @throws DatabaseException
     */
    public function create(int $userId, string $token, DateTimeImmutable $expiresAt): int
    {
        $this->connection->executeUpdate(
            'INSERT INTO refresh_tokens (user_id, token, expires_at, created_at)
             VALUES (?, ?, ?, NOW())',
            [
                $userId,
                $token,
                $expiresAt->format('Y-m-d H:i:s'),
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Find refresh token
     *
     * @throws DatabaseException
     */
    public function findByToken(string $token): ?array
    {
        // Rotation reuse-grace: a token rotated less than 30s ago is still accepted.
        // Refresh tokens are rotated on use; concurrent refreshes (multiple tabs, or a
        // burst of 401s on page reload) race on the same old token — without a grace
        // window the losers failed and the user was logged out on every page refresh.
        return $this->connection->fetchOne(
            'SELECT * FROM refresh_tokens
             WHERE token = ? AND expires_at > NOW()
             AND (revoked = 0 OR (revoked_at IS NOT NULL AND revoked_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)))',
            [$token]
        ) ?: null;
    }

    /**
     * Revoke refresh token
     *
     * @throws DatabaseException
     */
    public function revoke(string $token): void
    {
        $this->connection->executeUpdate(
            'UPDATE refresh_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ?',
            [$token]
        );
    }

    /**
     * Revoke a token IMMEDIATELY (explicit logout): backdate revoked_at past the
     * rotation reuse-grace window so it cannot be reused at all.
     *
     * @throws DatabaseException
     */
    public function revokeImmediately(string $token): void
    {
        $this->connection->executeUpdate(
            'UPDATE refresh_tokens SET revoked = 1, revoked_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE token = ?',
            [$token]
        );
    }

    /**
     * Revoke all tokens for a user
     *
     * @throws DatabaseException
     */
    public function revokeAllForUser(int $userId): void
    {
        // Security action ("logout everywhere"): revoked_at is backdated past the
        // rotation reuse-grace window so these tokens are dead IMMEDIATELY.
        $this->connection->executeUpdate(
            'UPDATE refresh_tokens SET revoked = 1, revoked_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * Delete expired tokens
     *
     * @throws DatabaseException
     */
    public function deleteExpired(): int
    {
        $this->connection->executeUpdate(
            'DELETE FROM refresh_tokens WHERE expires_at < NOW()'
        );

        return $this->connection->getAffectedRows();
    }
}
