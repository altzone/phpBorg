<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

use DateTimeImmutable;

/**
 * Backup source entity - defines what to backup
 */
final readonly class BackupSource
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type, // mysql, postgresql, files, docker, vm, custom
        public int $serverId,
        public array $config, // Type-specific configuration
        public ?array $paths,
        public ?array $excludePatterns,
        public ?string $preBackupScript,
        public ?string $postBackupScript,
        public ?array $tags,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Create BackupSource from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            name: (string)$row['name'],
            type: (string)$row['type'],
            serverId: (int)$row['server_id'],
            config: isset($row['config']) ? json_decode($row['config'], true) : [],
            paths: isset($row['paths']) && $row['paths'] !== null 
                ? json_decode($row['paths'], true) 
                : null,
            excludePatterns: isset($row['exclude_patterns']) && $row['exclude_patterns'] !== null
                ? json_decode($row['exclude_patterns'], true)
                : null,
            preBackupScript: $row['pre_backup_script'] ?? null,
            postBackupScript: $row['post_backup_script'] ?? null,
            tags: isset($row['tags']) && $row['tags'] !== null
                ? json_decode($row['tags'], true)
                : null,
            active: (bool)$row['active'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    /**
     * Get type-specific configuration value
     */
    public function getConfigValue(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    /**
     * Check if source has a specific tag
     */
    public function hasTag(string $tag): bool
    {
        return $this->tags !== null && in_array($tag, $this->tags, true);
    }

    /**
     * Get human-readable type description
     */
    public function getTypeDescription(): string
    {
        return match ($this->type) {
            'mysql' => 'MySQL Database',
            'postgresql' => 'PostgreSQL Database',
            'files' => 'Files & Folders',
            'docker' => 'Docker Container',
            'vm' => 'Virtual Machine',
            'custom' => 'Custom Backup',
            default => 'Unknown',
        };
    }

    /**
     * Get icon class for UI
     */
    public function getIconClass(): string
    {
        return match ($this->type) {
            'mysql', 'postgresql' => 'fas fa-database',
            'files' => 'fas fa-folder-tree',
            'docker' => 'fab fa-docker',
            'vm' => 'fas fa-server',
            'custom' => 'fas fa-cogs',
            default => 'fas fa-question',
        };
    }

    /**
     * Validate configuration based on type
     */
    public function isValidConfig(): bool
    {
        return match ($this->type) {
            'mysql' => isset($this->config['database']) && isset($this->config['user']),
            'postgresql' => isset($this->config['database']) && isset($this->config['user']),
            'files' => $this->paths !== null && count($this->paths) > 0,
            'docker' => isset($this->config['container']),
            'vm' => isset($this->config['vm_id']) || isset($this->config['vm_name']),
            'custom' => isset($this->config['command']),
            default => false,
        };
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_description' => $this->getTypeDescription(),
            'icon_class' => $this->getIconClass(),
            'server_id' => $this->serverId,
            'config' => $this->config,
            'paths' => $this->paths,
            'exclude_patterns' => $this->excludePatterns,
            'pre_backup_script' => $this->preBackupScript,
            'post_backup_script' => $this->postBackupScript,
            'tags' => $this->tags,
            'active' => $this->active,
            'is_valid' => $this->isValidConfig(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}