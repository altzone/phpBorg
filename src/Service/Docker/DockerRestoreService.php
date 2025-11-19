<?php

declare(strict_types=1);

namespace PhpBorg\Service\Docker;

use PhpBorg\Entity\Archive;
use PhpBorg\Entity\Server;
use PhpBorg\Entity\RestoreOperation;
use PhpBorg\Exception\RestoreException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BackupSourceRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\RestoreOperationRepository;
use PhpBorg\Service\Server\SshExecutor;
use PhpBorg\Service\Backup\BorgExecutor;
use DateTimeImmutable;

/**
 * Docker restore service
 * Handles Docker volumes, compose projects, and configs restore
 */
final class DockerRestoreService
{
    public function __construct(
        private readonly RestoreOperationRepository $restoreOperationRepository,
        private readonly ArchiveRepository $archiveRepository,
        private readonly BackupSourceRepository $backupSourceRepository,
        private readonly BorgRepositoryRepository $repositoryRepository,
        private readonly ServerRepository $serverRepository,
        private readonly SshExecutor $sshExecutor,
        private readonly BorgExecutor $borgExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze archive content to prepare restore
     * Returns structure with volumes, compose projects, configs
     *
     * @return array{volumes: array, compose_projects: array, configs: array, containers: array}
     * @throws RestoreException
     */
    public function analyzeArchive(int $archiveId): array
    {
        $archive = $this->archiveRepository->findById($archiveId);
        if (!$archive) {
            throw new RestoreException("Archive not found: {$archiveId}");
        }

        $repository = $this->repositoryRepository->findByRepoId($archive->repoId);
        if (!$repository) {
            throw new RestoreException("Repository not found for archive: {$archiveId}");
        }

        $server = $this->serverRepository->findById($repository->serverId);
        if (!$server) {
            throw new RestoreException("Server not found for repository: {$repository->id}");
        }

        $this->logger->info("Analyzing Docker archive: {$archive->name}", $server->name);

        // Get backup configuration from archive (snapshot of what was selected during backup)
        $config = $archive->backupConfig ?? [];

        if (empty($config)) {
            $this->logger->warning("No backup config found in archive metadata", $server->name);
            // Fallback: try to get from current backup_sources configuration
            $backupSource = $this->getBackupSourceForArchive($archive, $repository, $server);
            if ($backupSource) {
                $config = $backupSource->config ?? [];
                $this->logger->info("Using backup_sources config as fallback", $server->name);
            }
        }

        if (empty($config)) {
            $this->logger->warning("No configuration found for Docker archive (neither in archive nor backup_sources)", $server->name);
            return [
                'volumes' => [],
                'compose_projects' => [],
                'configs' => [],
                'containers' => [],
            ];
        }

        // Extract volumes from backup configuration
        $volumes = [];

        // PRIORITY 1: Use actual_backed_up_items snapshot (set during backup)
        // This contains the REAL list of volumes that were backed up
        if (!empty($config['actual_backed_up_items']['volumes'])) {
            $actualVolumes = $config['actual_backed_up_items']['volumes'];
            foreach ($actualVolumes as $volumeName) {
                $volumes[] = [
                    'name' => $volumeName,
                    'path' => "/var/lib/docker/volumes/{$volumeName}/_data",
                ];
            }
            $this->logger->info(sprintf("Using actual backed up volumes: %d items", count($volumes)), $server->name);
        }
        // FALLBACK 1: Use selectedVolumes if available (old archives)
        elseif (!empty($config['selectedVolumes'])) {
            $selectedVolumes = $config['selectedVolumes'];
            foreach ($selectedVolumes as $volumeName) {
                $volumes[] = [
                    'name' => $volumeName,
                    'path' => "/var/lib/docker/volumes/{$volumeName}/_data",
                ];
            }
            $this->logger->info("Using selectedVolumes from config (old archive format)", $server->name);
        }
        // FALLBACK 2: backupAllVolumes but no actual_backed_up_items (very old archives)
        else {
            $this->logger->warning("Archive has no volume snapshot - will need manual selection during restore", $server->name);
        }

        // Extract compose projects from backup configuration
        $composeProjects = [];

        // PRIORITY 1: Use actual_backed_up_items snapshot (set during backup)
        if (!empty($config['actual_backed_up_items']['compose_projects'])) {
            $actualProjects = $config['actual_backed_up_items']['compose_projects'];
            foreach ($actualProjects as $project) {
                // New format: array with name and path
                if (is_array($project) && isset($project['name'])) {
                    $composeProjects[] = [
                        'name' => $project['name'],
                        'path' => $project['path'] ?? null,
                    ];
                }
                // Old format: just project name (legacy compatibility)
                else {
                    $composeProjects[] = [
                        'name' => $project,
                        'path' => null, // Path unknown for old backups
                    ];
                }
            }
            $this->logger->info(sprintf("Using actual backed up projects: %d items", count($composeProjects)), $server->name);
        }
        // FALLBACK: Use selectedComposeProjects (old archives)
        elseif (!empty($config['selectedComposeProjects'])) {
            $selectedProjects = $config['selectedComposeProjects'];
            foreach ($selectedProjects as $projectName) {
                $composeProjects[] = [
                    'name' => $projectName,
                    'path' => null, // Path unknown for old backups
                ];
            }
            $this->logger->info("Using selectedComposeProjects from config (old archive format)", $server->name);
        }

        // Check if Docker configs were backed up
        $configs = [];
        if ($config['backupDockerConfig'] ?? false) {
            $configs[] = [
                'path' => '/etc/docker/daemon.json',
                'name' => 'daemon.json',
            ];
        }

        $this->logger->info(
            sprintf(
                "Archive analysis complete: %d volumes, %d compose projects, %d configs",
                count($volumes),
                count($composeProjects),
                count($configs)
            ),
            $server->name
        );

        return [
            'volumes' => $volumes,
            'compose_projects' => $composeProjects,
            'configs' => $configs,
            'containers' => [], // Will be populated from archive metadata if needed
        ];
    }

    /**
     * Detect running containers using volumes/paths to restore
     * Returns conflicts and containers that must be stopped
     *
     * @param array<string, mixed> $selectedItems
     * @return array{conflicts: array, must_stop: array, disk_space_ok: bool, warnings: array}
     * @throws RestoreException
     */
    public function detectConflicts(int $serverId, array $selectedItems): array
    {
        $server = $this->serverRepository->findById($serverId);
        if (!$server) {
            throw new RestoreException("Server not found: {$serverId}");
        }

        $this->logger->info("Detecting restore conflicts", $server->name);

        $conflicts = [];
        $mustStop = [];
        $warnings = [];

        // Check which containers are using the volumes to restore
        if (!empty($selectedItems['volumes'])) {
            foreach ($selectedItems['volumes'] as $volumeName) {
                // Find containers using this volume
                $result = $this->sshExecutor->execute(
                    $server,
                    sprintf(
                        'docker ps --filter "volume=%s" --format "{{.Names}}|{{.State}}"',
                        escapeshellarg($volumeName)
                    ),
                    10
                );

                if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
                    $lines = explode("\n", trim($result['stdout']));
                    foreach ($lines as $line) {
                        if (empty($line)) continue;

                        [$containerName, $state] = explode('|', $line);
                        $conflicts[] = [
                            'volume' => $volumeName,
                            'container' => $containerName,
                            'state' => $state,
                        ];

                        if (!in_array($containerName, $mustStop)) {
                            $mustStop[] = $containerName;
                        }
                    }
                }
            }
        }

        // Check compose projects
        if (!empty($selectedItems['projects'])) {
            foreach ($selectedItems['projects'] as $projectName) {
                // Find containers in this compose project
                $result = $this->sshExecutor->execute(
                    $server,
                    sprintf(
                        'docker ps -a --filter "label=com.docker.compose.project=%s" --format "{{.Names}}|{{.State}}"',
                        escapeshellarg($projectName)
                    ),
                    10
                );

                if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
                    $lines = explode("\n", trim($result['stdout']));
                    foreach ($lines as $line) {
                        if (empty($line)) continue;

                        [$containerName, $state] = explode('|', $line);
                        $conflicts[] = [
                            'project' => $projectName,
                            'container' => $containerName,
                            'state' => $state,
                        ];

                        if ($state === 'running' && !in_array($containerName, $mustStop)) {
                            $mustStop[] = $containerName;
                        }
                    }
                }
            }
        }

        // Check disk space
        $diskSpaceOk = $this->checkDiskSpace($server);

        // Generate warnings
        if (!empty($mustStop)) {
            $warnings[] = sprintf(
                "%d container(s) will be stopped during restore: %s",
                count($mustStop),
                implode(', ', $mustStop)
            );
        }

        if (!$diskSpaceOk) {
            $warnings[] = "⚠️ Low disk space detected. Restore may fail.";
        }

        $this->logger->info(
            sprintf("Conflict detection complete: %d conflicts, %d containers to stop", count($conflicts), count($mustStop)),
            $server->name
        );

        return [
            'conflicts' => $conflicts,
            'must_stop' => $mustStop,
            'disk_space_ok' => $diskSpaceOk,
            'warnings' => $warnings,
        ];
    }

    /**
     * Convert selected items to borg extract paths
     *
     * @param array $selectedItems {volumes: [], projects: [], configs: []}
     * @return array List of paths to extract
     */
    private function convertSelectedItemsToPaths(array $selectedItems): array
    {
        $paths = [];

        // Volumes: Borg stores them as /var/lib/docker/volumes/{volumeName}/_data
        // But we need to specify just the volume directory to get everything
        foreach ($selectedItems['volumes'] ?? [] as $volumeName) {
            $paths[] = "var/lib/docker/volumes/{$volumeName}";
        }

        // Compose projects: Use actual path from backup metadata
        foreach ($selectedItems['projects'] ?? [] as $project) {
            if (is_array($project) && isset($project['path']) && !empty($project['path'])) {
                // Use actual path from backup
                $paths[] = ltrim($project['path'], '/');
            } elseif (is_array($project) && isset($project['name'])) {
                // Fallback: default path pattern
                $paths[] = "var/lib/docker/compose/{$project['name']}";
            } else {
                // Legacy: just project name
                $paths[] = "var/lib/docker/compose/{$project}";
            }
        }

        // Docker configs: specific files from /etc/docker
        if (!empty($selectedItems['configs'])) {
            foreach ($selectedItems['configs'] as $configPath) {
                // Remove leading slash if present
                $paths[] = ltrim($configPath, '/');
            }
        }

        // Always include container metadata
        $paths[] = "tmp/phpborg_docker_containers.json";

        return $paths;
    }

    /**
     * Generate bash script for restore operation
     *
     * @param array<string, mixed> $config - Full restore configuration
     * @param bool $advanced - Advanced mode (full script) or explained mode
     * @return string
     */
    public function generateRestoreScript(array $config, bool $advanced = false): string
    {
        $operation = $this->restoreOperationRepository->findById($config['operation_id']);
        if (!$operation) {
            throw new RestoreException("Restore operation not found: {$config['operation_id']}");
        }

        $archive = $this->archiveRepository->findById($operation->archiveId);
        $server = $this->serverRepository->findById($operation->serverId);
        $repository = $this->repositoryRepository->findByRepoId($archive->repoId);

        $script = "#!/bin/bash\n";
        $script .= "# Docker Restore Script - Generated by phpBorg\n";
        $script .= "# Archive: {$archive->name}\n";
        $script .= "# Mode: {$operation->mode}\n";
        $script .= "# Generated: " . (new DateTimeImmutable())->format('Y-m-d H:i:s') . "\n\n";

        if ($advanced) {
            $script .= "set -euo pipefail\n";
            $script .= "trap 'echo \"❌ Error on line \$LINENO\"' ERR\n\n";
        } else {
            $script .= "set -e  # Exit on error\n\n";
        }

        // Stop containers
        if (!empty($config['containers_to_stop'])) {
            if ($advanced) {
                $script .= "# Step 1: Stop containers\n";
                $script .= "stop_containers() {\n";
                $script .= "  containers=(" . implode(' ', array_map('escapeshellarg', $config['containers_to_stop'])) . ")\n";
                $script .= "  for c in \"\${containers[@]}\"; do\n";
                $script .= "    docker stop \"\$c\" || echo \"Warning: \$c already stopped\"\n";
                $script .= "  done\n";
                $script .= "}\n\n";
                $script .= "stop_containers\n\n";
            } else {
                $script .= "echo \"🛑 Step 1: Stopping conflicting containers...\"\n";
                $script .= "docker stop " . implode(' ', array_map('escapeshellarg', $config['containers_to_stop'])) . "\n";
                $script .= "# → Arrêt de " . count($config['containers_to_stop']) . " container(s) pour éviter corruption pendant restore\n\n";
            }
        }

        // LVM snapshot protection
        if ($config['create_lvm_snapshot'] ?? false) {
            $snapshotName = 'restore_snapshot_' . date('Ymd_His');
            $snapshotSize = $config['snapshot_size'] ?? '20G';

            if ($advanced) {
                $script .= "# Step 2: Create LVM snapshot\n";
                $script .= "SNAPSHOT_NAME=\"{$snapshotName}\"\n";
                $script .= "SNAPSHOT_SIZE=\"{$snapshotSize}\"\n";
                $script .= "lvcreate -L \$SNAPSHOT_SIZE -s -n \$SNAPSHOT_NAME {$config['lvm_path']}\n\n";
            } else {
                $script .= "echo \"📸 Step 2: Creating LVM snapshot...\"\n";
                $script .= "lvcreate -L {$snapshotSize} -s -n {$snapshotName} {$config['lvm_path']}\n";
                $script .= "# → Snapshot de sécurité, permet rollback pendant 8h\n\n";
            }
        }

        // Extract from Borg
        if ($advanced) {
            $script .= "# Step 3: Extract from Borg archive\n";
            $script .= "BORG_REPO=\"{$repository->repoPath}\"\n";
            $script .= "BORG_PASSPHRASE=\"{$repository->passphrase}\"\n";
            $script .= "export BORG_REPO\n";
            $script .= "export BORG_PASSPHRASE\n\n";
        } else {
            $script .= "echo \"📦 Step 3: Extracting from Borg archive...\"\n";
        }

        // Build selective extract command with paths
        $extractPaths = [];
        if (!empty($config['selected_items'])) {
            $extractPaths = $this->convertSelectedItemsToPaths($config['selected_items']);
        }

        // Change to destination directory before extraction
        if ($operation->destination === 'alternative') {
            $script .= "cd {$operation->alternativePath}\n";
        }

        $script .= "borg extract --progress ::{$archive->name}";
        if ($operation->destination === 'alternative') {
            $script .= " --strip-components=1";
        }

        // Add selective paths if specified
        if (!empty($extractPaths)) {
            $script .= " \\\n  ";
            $script .= implode(" \\\n  ", array_map('escapeshellarg', $extractPaths));
        }

        $script .= "\n";

        if (!$advanced) {
            if (!empty($extractPaths)) {
                $script .= "# → Extraction sélective de " . count($extractPaths) . " item(s)\n";
                $script .= "# → Items: " . implode(', ', array_map(fn($p) => basename($p), $extractPaths)) . "\n\n";
            } else {
                $script .= "# → Extraction complète depuis le backup\n\n";
            }
        }

        // Restart containers
        if ($config['auto_restart'] && !empty($config['containers_to_stop'])) {
            if ($advanced) {
                $script .= "\n# Step 4: Restart containers\n";
                $script .= "restart_containers() {\n";
                $script .= "  containers=(" . implode(' ', array_map('escapeshellarg', array_reverse($config['containers_to_stop']))) . ")\n";
                $script .= "  for c in \"\${containers[@]}\"; do\n";
                $script .= "    docker start \"\$c\"\n";
                $script .= "  done\n";
                $script .= "}\n\n";
                $script .= "restart_containers\n\n";
            } else {
                $script .= "echo \"🔄 Step 4: Restarting containers...\"\n";
                $script .= "docker start " . implode(' ', array_map('escapeshellarg', array_reverse($config['containers_to_stop']))) . "\n";
                $script .= "# → Redémarrage des containers avec données restaurées\n\n";
            }
        }

        if (!$advanced) {
            $script .= "echo \"✓ Restore completed successfully!\"\n";
        }

        return $script;
    }

    /**
     * Check disk space availability on server
     */
    private function checkDiskSpace(Server $server): bool
    {
        $result = $this->sshExecutor->execute(
            $server,
            'df /var/lib/docker --output=avail | tail -1',
            5
        );

        if ($result['exitCode'] !== 0) {
            return false;
        }

        $availableKb = (int)trim($result['stdout']);
        $requiredKb = 10 * 1024 * 1024; // 10 GB minimum

        return $availableKb > $requiredKb;
    }

    /**
     * Get backup source for an archive
     *
     * @return object|null
     */
    private function getBackupSourceForArchive($archive, $repository, $server): ?object
    {
        // Find Docker backup source for this server
        $sources = $this->backupSourceRepository->findByServerAndType($server->id, 'docker');
        return !empty($sources) ? $sources[0] : null;
    }

    /**
     * Get Docker volumes from server (via capabilities or live query)
     *
     * @return array<int, array<string, string>>
     */
    private function getDockerVolumesFromServer($server): array
    {
        // Try to get volumes from server via SSH
        $result = $this->sshExecutor->execute(
            $server,
            'docker volume ls --format "{{.Name}}"',
            10
        );

        if ($result['exitCode'] !== 0) {
            return [];
        }

        $volumes = [];
        $lines = explode("\n", trim($result['stdout']));
        foreach ($lines as $line) {
            $volumeName = trim($line);
            if (!empty($volumeName)) {
                $volumes[] = [
                    'name' => $volumeName,
                    'path' => "/var/lib/docker/volumes/{$volumeName}/_data",
                ];
            }
        }

        return $volumes;
    }

    /**
     * Extract container metadata from backup (DEPRECATED - use backup_sources.config instead)
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractContainerMetadata(string $repoPath, string $passphrase): array
    {
        // Extract phpborg_docker_containers.json from backup using borg extract --stdout
        $result = $this->borgExecutor->execute(
            ['extract', '--stdout', $repoPath, 'tmp/phpborg_docker_containers.json'],
            $passphrase,
            30
        );

        if ($result['exitCode'] !== 0) {
            $this->logger->warning(
                "Failed to extract container metadata: " . ($result['stderr'] ?? 'Unknown error'),
                'DockerRestore'
            );
            return [];
        }

        // Parse JSON from stdout
        $containers = json_decode($result['stdout'], true);

        if (!is_array($containers)) {
            $this->logger->warning(
                "Failed to parse container metadata JSON. Output length: " . strlen($result['stdout']),
                'DockerRestore'
            );
            return [];
        }

        $this->logger->info(
            sprintf("Extracted metadata for %d containers", count($containers)),
            'DockerRestore'
        );

        return $containers;
    }
}
