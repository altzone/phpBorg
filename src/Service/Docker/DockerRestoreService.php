<?php

declare(strict_types=1);

namespace PhpBorg\Service\Docker;

use PhpBorg\Entity\Archive;
use PhpBorg\Entity\Server;
use PhpBorg\Entity\RestoreOperation;
use PhpBorg\Exception\RestoreException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\ArchiveRepository;
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

        // List archive contents
        $repoPath = $repository->repoPath . '::' . $archive->name;
        $result = $this->borgExecutor->listArchive($repoPath, $repository->passphrase);

        if ($result['exitCode'] !== 0) {
            throw new RestoreException("Failed to list archive contents: {$result['stderr']}");
        }

        $contents = explode("\n", trim($result['stdout']));

        // Parse contents to extract volumes, compose projects, configs
        $volumes = [];
        $composeProjects = [];
        $configs = [];
        $containers = [];

        foreach ($contents as $line) {
            if (empty($line)) continue;

            // Docker volumes: var/lib/docker/volumes/VOLUME_NAME/_data
            if (preg_match('#var/lib/docker/volumes/([^/]+)/_data#', $line, $matches)) {
                $volumeName = $matches[1];
                if (!isset($volumes[$volumeName])) {
                    $volumes[$volumeName] = [
                        'name' => $volumeName,
                        'path' => "/var/lib/docker/volumes/{$volumeName}/_data",
                        'files' => 0,
                        'size' => 0,
                    ];
                }
                $volumes[$volumeName]['files']++;
            }

            // Compose projects: paths containing docker-compose.yml
            if (str_contains($line, 'docker-compose.yml') || str_contains($line, 'docker-compose.yaml')) {
                $projectPath = dirname($line);
                $projectName = basename($projectPath);

                if (!isset($composeProjects[$projectName])) {
                    $composeProjects[$projectName] = [
                        'name' => $projectName,
                        'path' => '/' . $projectPath,
                        'files' => [],
                    ];
                }
                $composeProjects[$projectName]['files'][] = basename($line);
            }

            // Docker configs: /etc/docker/
            if (str_starts_with($line, 'etc/docker/')) {
                $configs[] = [
                    'path' => '/' . $line,
                    'name' => basename($line),
                ];
            }

            // Container metadata files
            if (str_contains($line, 'phpborg_docker_containers.json')) {
                // Extract container list from backup metadata
                $containers = $this->extractContainerMetadata($server, $repoPath, $repository->passphrase);
            }
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
            'volumes' => array_values($volumes),
            'compose_projects' => array_values($composeProjects),
            'configs' => $configs,
            'containers' => $containers,
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
        $repoPath = $repository->repoPath . '::' . $archive->name;
        if ($advanced) {
            $script .= "# Step 3: Extract from Borg archive\n";
            $script .= "BORG_REPO=\"{$repoPath}\"\n";
            $script .= "BORG_PASSPHRASE=\"{$repository->passphrase}\"\n";
            $script .= "export BORG_PASSPHRASE\n\n";
        } else {
            $script .= "echo \"📦 Step 3: Extracting from Borg archive...\"\n";
        }

        $script .= "borg extract --progress {$repoPath}";
        if ($operation->destination === 'alternative') {
            $script .= " --strip-components=1 --target={$operation->alternativePath}";
        }
        $script .= "\n";

        if (!$advanced) {
            $script .= "# → Extraction des fichiers depuis le backup\n\n";
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
     * Extract container metadata from backup
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractContainerMetadata(Server $server, string $repoPath, string $passphrase): array
    {
        // Extract phpborg_docker_containers.json from backup
        $tmpFile = '/tmp/phpborg_restore_containers_' . uniqid() . '.json';

        $result = $this->borgExecutor->execute(
            $server,
            sprintf(
                'BORG_PASSPHRASE=%s borg extract --stdout %s tmp/phpborg_docker_containers.json > %s',
                escapeshellarg($passphrase),
                escapeshellarg($repoPath),
                escapeshellarg($tmpFile)
            ),
            30
        );

        if ($result['exitCode'] !== 0) {
            return [];
        }

        // Read and parse JSON
        $readResult = $this->sshExecutor->execute($server, "cat {$tmpFile} && rm {$tmpFile}", 10);
        if ($readResult['exitCode'] !== 0) {
            return [];
        }

        $containers = json_decode($readResult['stdout'], true);
        return is_array($containers) ? $containers : [];
    }
}
