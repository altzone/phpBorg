<?php

declare(strict_types=1);

namespace PhpBorg\Repository;

use PhpBorg\Database\Connection;
use PhpBorg\Entity\Setting;
use PhpBorg\Exception\DatabaseException;

/**
 * Repository for Setting entities
 */
final class SettingRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find setting by key
     *
     * @throws DatabaseException
     */
    public function findByKey(string $key): ?Setting
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM settings WHERE `key` = ?',
            [$key]
        );

        return $row ? Setting::fromDatabase($row) : null;
    }

    /**
     * Find all settings
     *
     * @return array<int, Setting>
     * @throws DatabaseException
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM settings ORDER BY category, `key`'
        );

        return array_map(fn(array $row) => Setting::fromDatabase($row), $rows);
    }

    /**
     * Find settings by category
     *
     * @return array<int, Setting>
     * @throws DatabaseException
     */
    public function findByCategory(string $category): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM settings WHERE category = ? ORDER BY `key`',
            [$category]
        );

        return array_map(fn(array $row) => Setting::fromDatabase($row), $rows);
    }

    /**
     * Get settings grouped by category
     *
     * @return array<string, array<string, mixed>>
     * @throws DatabaseException
     */
    public function getAllGroupedByCategory(): array
    {
        $settings = $this->findAll();
        $grouped = [];

        foreach ($settings as $setting) {
            $grouped[$setting->category][$setting->key] = $setting->getTypedValue();
        }

        return $grouped;
    }

    /**
     * Update setting value
     *
     * @throws DatabaseException
     */
    public function updateValue(string $key, mixed $value): void
    {
        // Convert value to string based on type
        $stringValue = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value),
            default => (string)$value,
        };

        $this->connection->executeUpdate(
            'UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?',
            [$stringValue, $key]
        );
    }

    /**
     * Update multiple settings at once
     *
     * @param array<string, mixed> $settings Key-value pairs
     * @throws DatabaseException
     */
    public function updateMultiple(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->updateValue($key, $value);
        }
    }

    /**
     * Create new setting
     *
     * @throws DatabaseException
     */
    public function create(
        string $key,
        mixed $value,
        string $category,
        string $type,
        ?string $description = null
    ): int {
        // Convert value to string
        $stringValue = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value),
            default => (string)$value,
        };

        $this->connection->executeUpdate(
            'INSERT INTO settings (`key`, `value`, category, type, description, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$key, $stringValue, $category, $type, $description]
        );

        return $this->connection->getLastInsertId();
    }

    /**
     * Delete setting by key
     *
     * @throws DatabaseException
     */
    public function delete(string $key): void
    {
        $this->connection->executeUpdate(
            'DELETE FROM settings WHERE `key` = ?',
            [$key]
        );
    }
}
