<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Application setting entity
 */
final readonly class Setting
{
    public function __construct(
        public int $id,
        public string $key,
        public ?string $value,
        public string $category,
        public string $type,
        public ?string $description,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create Setting from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            key: (string)$row['key'],
            value: $row['value'] !== null ? (string)$row['value'] : null,
            category: (string)$row['category'],
            type: (string)$row['type'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            updatedAt: isset($row['updated_at'])
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Get typed value based on type
     */
    public function getTypedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean' => $this->value === 'true' || $this->value === '1',
            'integer' => (int)$this->value,
            'json' => json_decode($this->value, true),
            default => $this->value, // string
        };
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->getTypedValue(),
            'category' => $this->category,
            'type' => $this->type,
            'description' => $this->description,
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
