<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

/**
 * Role permission entity for granular permission management
 */
final readonly class RolePermission
{
    public function __construct(
        public int $id,
        public string $role,
        public string $permission,
        public bool $enabled,
    ) {
    }

    /**
     * Create RolePermission from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            role: (string)$row['role'],
            permission: (string)$row['permission'],
            enabled: (bool)$row['enabled'],
        );
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'permission' => $this->permission,
            'enabled' => $this->enabled,
        ];
    }
}
