<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;

/**
 * Interface for database backup strategies
 */
interface DatabaseBackupInterface
{
    /**
     * Prepare database for backup (e.g., create snapshot, dump)
     *
     * @return array{path: string, cleanup: bool} Path to backup and whether cleanup is needed
     */
    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array;

    /**
     * Cleanup after backup
     */
    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void;

    /**
     * Get supported database type
     */
    public function getSupportedType(): string;
}
