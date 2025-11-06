<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * User entity for authentication
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $username,
        public string $password, // Hashed password
        public string $email,
        public array $roles, // ['ROLE_ADMIN', 'ROLE_OPERATOR']
        public bool $active,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastLoginAt = null,
    ) {
    }

    /**
     * Create User from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            username: (string)$row['username'],
            password: (string)$row['password'],
            email: (string)$row['email'],
            roles: json_decode($row['roles'], true) ?? [],
            active: (bool)$row['active'],
            createdAt: new DateTimeImmutable($row['created_at']),
            lastLoginAt: isset($row['last_login_at'])
                ? new DateTimeImmutable($row['last_login_at'])
                : null,
        );
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return !empty(array_intersect($this->roles, $roles));
    }

    /**
     * Convert to array for JWT payload (without password)
     */
    public function toJWTPayload(): array
    {
        return [
            'sub' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $this->roles,
        ];
    }

    /**
     * Convert to array for API response (without password)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $this->roles,
            'active' => $this->active,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'last_login_at' => $this->lastLoginAt?->format('Y-m-d H:i:s'),
        ];
    }
}
