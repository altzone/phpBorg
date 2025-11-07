<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Storage pool entity for managing backup storage locations
 */
final readonly class StoragePool
{
    public function __construct(
        public int $id,
        public string $name,
        public string $path,
        public ?string $description,
        public ?int $capacityTotal,
        public int $capacityUsed,
        public bool $active,
        public bool $defaultPool,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create StoragePool from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            name: (string)$row['name'],
            path: (string)$row['path'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            capacityTotal: $row['capacity_total'] !== null ? (int)$row['capacity_total'] : null,
            capacityUsed: (int)($row['capacity_used'] ?? 0),
            active: (bool)$row['active'],
            defaultPool: (bool)$row['default_pool'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at'])
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Get usage percentage
     */
    public function getUsagePercentage(): ?float
    {
        if ($this->capacityTotal === null || $this->capacityTotal === 0) {
            return null;
        }

        return round(($this->capacityUsed / $this->capacityTotal) * 100, 2);
    }

    /**
     * Get free space
     */
    public function getFreeSpace(): ?int
    {
        if ($this->capacityTotal === null) {
            return null;
        }

        return $this->capacityTotal - $this->capacityUsed;
    }

    /**
     * Check if pool has enough space
     */
    public function hasSpace(int $requiredBytes): bool
    {
        $freeSpace = $this->getFreeSpace();

        // If capacity is unknown, assume there's space
        if ($freeSpace === null) {
            return true;
        }

        return $freeSpace >= $requiredBytes;
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'description' => $this->description,
            'capacity_total' => $this->capacityTotal,
            'capacity_used' => $this->capacityUsed,
            'capacity_free' => $this->getFreeSpace(),
            'usage_percentage' => $this->getUsagePercentage(),
            'active' => $this->active,
            'default_pool' => $this->defaultPool,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
