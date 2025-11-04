<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

/**
 * Database backup configuration entity
 */
final readonly class DatabaseInfo
{
    public function __construct(
        public int $id,
        public string $type,
        public int $serverId,
        public string $repoId,
        public string $dbHost,
        public string $dbUser,
        public string $dbPassword,
        public string $vgName,
        public string $lvmPartition,
        public string $lvSize,
        public string $mysqlPath,
    ) {
    }

    /**
     * Create from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int)$row['id'],
            type: (string)$row['type'],
            serverId: (int)$row['server_id'],
            repoId: (string)$row['repo_id'],
            dbHost: (string)$row['db_host'],
            dbUser: (string)$row['db_user'],
            dbPassword: (string)$row['db_pass'],
            vgName: (string)$row['vg_name'],
            lvmPartition: (string)$row['lvm_part'],
            lvSize: (string)$row['lvsize'],
            mysqlPath: (string)$row['mysql_path'],
        );
    }

    /**
     * Check if this is a MySQL database
     */
    public function isMysql(): bool
    {
        return $this->type === 'mysql';
    }

    /**
     * Check if this is a PostgreSQL database
     */
    public function isPostgres(): bool
    {
        return $this->type === 'postgres';
    }
}
