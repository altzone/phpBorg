<?php

declare(strict_types=1);

namespace PhpBorg\Service\Backup;

use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\StoragePoolRepository;

/**
 * Import an existing Borg repository into phpBorg (Bug #7 / F2).
 *
 * Shared by the `repository:import` CLI command and the POST /api/repositories/import
 * endpoint. Registers the server / storage pool / repository rows for a pre-existing
 * Borg repository (encrypted or not) and, optionally, syncs its archives so they show
 * up in the UI.
 */
final class RepositoryImportService
{
    public function __construct(
        private readonly BorgRepositoryRepository $repoRepo,
        private readonly ServerRepository $serverRepo,
        private readonly StoragePoolRepository $poolRepo,
        private readonly BackupService $backupService,
        private readonly BorgExecutor $borgExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{
     *   path: string, encryption?: string, passphrase?: string, server?: ?string,
     *   pool?: ?string, type?: string, compression?: string,
     *   keepDaily?: int, keepWeekly?: int, keepMonthly?: int, keepYearly?: int,
     *   fixOwnership?: bool, sync?: bool
     * } $opts
     * @return array{repo_id: string, server_id: int, already: bool, synced: int, errors: int}
     * @throws BackupException on invalid input or DB errors
     */
    public function import(array $opts): array
    {
        $path = rtrim((string)($opts['path'] ?? ''), '/');
        $encryption = strtolower(trim((string)($opts['encryption'] ?? 'none')));
        $passphrase = (string)($opts['passphrase'] ?? '');
        $type = (string)($opts['type'] ?? 'backup');
        $compression = (string)($opts['compression'] ?? 'lz4');
        $keepDaily = (int)($opts['keepDaily'] ?? 7);

        // --- Validate ---------------------------------------------------------
        if ($path === '' || $path[0] !== '/') {
            throw new BackupException('An absolute repository path is required');
        }
        if (!is_dir($path)) {
            throw new BackupException("Path does not exist or is not a directory: {$path}");
        }
        if ($encryption !== 'none' && $passphrase === '') {
            throw new BackupException("Encrypted repository ({$encryption}) requires a passphrase");
        }

        // Local repos are accessed as phpborg-borg (owned by it, 0700). The web user
        // usually cannot read inside them, so we read the id via the borg account.
        $runAsUser = 'phpborg-borg';
        $allowUnencrypted = ($encryption === 'none');

        // --- Optionally fix ownership FIRST ----------------------------------
        // Must happen before reading the repo id: if the repo is owned by someone
        // else, phpborg-borg cannot read it until ownership is fixed.
        if (!empty($opts['fixOwnership'])) {
            exec(sprintf('sudo /usr/bin/chown -R phpborg-borg:phpborg-borg %s 2>&1', escapeshellarg($path)), $o, $rc);
            if ($rc !== 0) {
                $this->logger->warning("Could not chown {$path} to phpborg-borg", 'IMPORT');
            }
        }

        // --- Read Borg repository id ------------------------------------------
        // Fast path: read the (plaintext) config directly if the web user can.
        // Fallback: ask borg as the borg account (works for 0700 phpborg-borg repos
        // and validates that it really is a Borg repository).
        $repoConfig = @parse_ini_file($path . '/config');
        $repoId = is_array($repoConfig) ? (string)($repoConfig['id'] ?? '') : '';
        if ($repoId === '') {
            try {
                $repoId = $this->borgExecutor->getRepoId($path, $passphrase, $runAsUser, $allowUnencrypted);
            } catch (\Exception $e) {
                throw new BackupException("Not a readable Borg repository at {$path}: " . $e->getMessage());
            }
        }
        if ($repoId === '') {
            throw new BackupException("Could not determine the Borg repository id for {$path}");
        }

        if ($this->repoRepo->findByRepoId($repoId) !== null) {
            $this->logger->info("Repository {$repoId} already registered", 'IMPORT');
            $existing = $this->repoRepo->findByRepoId($repoId);
            return [
                'repo_id' => $repoId,
                'server_id' => $existing->serverId,
                'already' => true,
                'synced' => 0,
                'errors' => 0,
            ];
        }

        // --- Server (create if missing) --------------------------------------
        $serverName = (string)($opts['server'] ?? '') ?: basename($path);
        $server = $this->serverRepo->findByName($serverName);
        if ($server === null) {
            $serverId = $this->serverRepo->create(
                name: $serverName,
                host: 'localhost',
                port: 22,
                backupType: 'local',
                sshPublicKey: '',
                active: true
            );
            $this->logger->info("Created server '{$serverName}' (id {$serverId})", 'IMPORT');
        } else {
            $serverId = $server->id;
        }

        // --- Storage pool (create if missing) --------------------------------
        $poolPath = dirname($path);
        $existingPool = null;
        foreach ($this->poolRepo->findAll() as $pool) {
            if (rtrim($pool->path, '/') === $poolPath) {
                $existingPool = $pool;
                break;
            }
        }
        if ($existingPool === null) {
            $poolName = (string)($opts['pool'] ?? '') ?: ('imported-' . basename($poolPath));
            $total = @disk_total_space($poolPath) ?: null;
            $this->poolRepo->create(
                name: $poolName,
                path: $poolPath,
                description: 'Auto-created during repository import',
                capacityTotal: $total ? (int)$total : null
            );
            $this->logger->info("Created storage pool '{$poolName}' at {$poolPath}", 'IMPORT');
        }

        // --- Repository row --------------------------------------------------
        $this->repoRepo->create(
            serverId: $serverId,
            repoId: $repoId,
            type: $type,
            retention: $keepDaily,
            encryption: $encryption,
            passphrase: $passphrase,
            repoPath: $path,
            compression: $compression,
            rateLimit: 0,
            backupPath: '',
            exclude: '',
            keepDaily: $keepDaily,
            keepWeekly: (int)($opts['keepWeekly'] ?? 4),
            keepMonthly: (int)($opts['keepMonthly'] ?? 6),
            keepYearly: (int)($opts['keepYearly'] ?? 0)
        );
        $this->logger->info("Registered repository {$repoId} at {$path} (encryption: {$encryption})", 'IMPORT');

        // --- Sync archives ---------------------------------------------------
        $synced = 0;
        $errors = 0;
        if ($opts['sync'] ?? true) {
            $result = $this->backupService->syncArchivesFromBorg($serverId, $type);
            $synced = (int)$result['synced'];
            $errors = (int)$result['errors'];
        }

        return [
            'repo_id' => $repoId,
            'server_id' => $serverId,
            'already' => false,
            'synced' => $synced,
            'errors' => $errors,
        ];
    }
}
