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
        return $this->connection->fetchOne(
            'SELECT * FROM refresh_tokens WHERE token = ? AND revoked = 0 AND expires_at > NOW()',
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
            'UPDATE refresh_tokens SET revoked = 1 WHERE token = ?',
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
        $this->connection->executeUpdate(
            'UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?',
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
