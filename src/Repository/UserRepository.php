<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use DateTimeImmutable;
use PhpBorg\Database\Connection;
use PhpBorg\Entity\User;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for User entities
 */
final class UserRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find user by ID
     *
     * @throws DatabaseException
     */
    public function findById(int $id): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM users WHERE id = ?',
            [$id]
        );

        return $row ? User::fromDatabase($row) : null;
    }

    /**
     * Find user by username
     *
     * @throws DatabaseException
     */
    public function findByUsername(string $username): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM users WHERE username = ?',
            [$username]
        );

        return $row ? User::fromDatabase($row) : null;
    }

    /**
     * Find user by email
     *
     * @throws DatabaseException
     */
    public function findByEmail(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM users WHERE email = ?',
            [$email]
        );

        return $row ? User::fromDatabase($row) : null;
    }

    /**
     * Find all users
     *
     * @return array<int, User>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM users ORDER BY username'
        );

        return array_map(fn(array $row) => User::fromDatabase($row), $rows);
    }

    /**
     * Find active users
     *
     * @return array<int, User>
     * @throws DatabaseException
     */
    public function findActive(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM users WHERE active = 1 ORDER BY username'
        );

        return array_map(fn(array $row) => User::fromDatabase($row), $rows);
    }

    /**
     * Create new user
     *
     * @throws DatabaseException
     */
    public function create(
        string $username,
        string $hashedPassword,
        string $email,
        array $roles,
        bool $active = true
    ): int {
        $this->connection->executeUpdate(
            'INSERT INTO users (username, password, email, roles, active, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $username,
                $hashedPassword,
                $email,
                json_encode($roles),
                $active ? 1 : 0,
            ]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Update user
     *
     * @throws DatabaseException
     */
    public function update(
        int $id,
        string $username,
        string $email,
        array $roles,
        bool $active
    ): void {
        $this->connection->executeUpdate(
            'UPDATE users
             SET username = ?, email = ?, roles = ?, active = ?
             WHERE id = ?',
            [
                $username,
                $email,
                json_encode($roles),
                $active ? 1 : 0,
                $id,
            ]
        );
    }

    /**
     * Update user password
     *
     * @throws DatabaseException
     */
    public function updatePassword(int $id, string $hashedPassword): void
    {
        $this->connection->executeUpdate(
            'UPDATE users SET password = ? WHERE id = ?',
            [$hashedPassword, $id]
        );
    }

    /**
     * Update last login time
     *
     * @throws DatabaseException
     */
    public function updateLastLogin(int $id): void
    {
        $this->connection->executeUpdate(
            'UPDATE users SET last_login_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete user
     *
     * @throws DatabaseException
     */
    public function delete(int $id): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM users WHERE id = ?',
            [$id]
        );
    }

    /**
     * Check if username exists
     *
     * @throws DatabaseException
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->connection->fetchOne(
                'SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?',
                [$username, $excludeId]
            );
        } else {
            $row = $this->connection->fetchOne(
                'SELECT COUNT(*) as count FROM users WHERE username = ?',
                [$username]
            );
        }

        return (int)($row['count'] ?? 0) > 0;
    }

    /**
     * Check if email exists
     *
     * @throws DatabaseException
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->connection->fetchOne(
                'SELECT COUNT(*) as count FROM users WHERE email = ? AND id != ?',
                [$email, $excludeId]
            );
        } else {
            $row = $this->connection->fetchOne(
                'SELECT COUNT(*) as count FROM users WHERE email = ?',
                [$email]
            );
        }

        return (int)($row['count'] ?? 0) > 0;
    }
}
