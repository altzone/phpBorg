<?php

declare(strict_types=1);

namespace PhpBorg\Service\Database;

use PhpBorg\Entity\DatabaseInfo;
use PhpBorg\Entity\Server;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Server\SshExecutor;

/**
 * Docker backup strategy
 * Backs up Docker volumes, compose projects, and system configuration
 */
final class DockerBackupStrategy implements DatabaseBackupInterface
{
    public function __construct(
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepareBackup(Server $server, DatabaseInfo $dbInfo): array
    {
        $this->logger->info("Preparing Docker environment backup", $server->name);

        try {
            $paths = [];

            // Parse source config from database_info (stored as JSON in mysqlPath or similar field)
            $sourceConfig = $this->parseSourceConfig($dbInfo);

            // 1. Backup Docker volumes
            if ($sourceConfig['backupAllVolumes'] ?? true) {
                // Get all volume paths
                $volumePaths = $this->getAllVolumePaths($server);
                foreach ($volumePaths as $volumePath) {
                    $paths[] = $volumePath;
                    $this->logger->info("Including Docker volume: {$volumePath}", $server->name);
                }
            } else {
                // Backup only selected volumes
                $selectedVolumes = $sourceConfig['selectedVolumes'] ?? [];
                foreach ($selectedVolumes as $volumeName) {
                    $volumePath = $this->getVolumePath($server, $volumeName);
                    if ($volumePath) {
                        $paths[] = $volumePath;
                        $this->logger->info("Including selected volume: {$volumeName} ({$volumePath})", $server->name);
                    }
                }
            }

            // 2. Backup Compose projects (CRITICAL for restore!)
            // If user selected specific projects, use those. Otherwise backup ALL detected projects.
            $selectedProjects = $sourceConfig['selectedComposeProjects'] ?? [];
            $backupAllProjects = empty($selectedProjects); // If nothing selected, backup everything

            if ($backupAllProjects) {
                $this->logger->info("No projects selected - backing up ALL detected Compose projects", $server->name);
                $allProjects = $this->getAllComposeProjects($server);
                foreach ($allProjects as $projectName => $projectInfo) {
                    $projectPath = $projectInfo['working_dir'] ?? null;
                    if ($projectPath && $this->fileExists($server, $projectPath)) {
                        $paths[] = $projectPath;
                        $this->logger->info("Including Compose project: {$projectName} ({$projectPath})", $server->name);
                    }
                }
            } else {
                foreach ($selectedProjects as $projectName) {
                    $projectPath = $this->getComposeProjectPath($server, $projectName);
                    if ($projectPath) {
                        $paths[] = $projectPath;
                        $this->logger->info("Including Compose project: {$projectName} ({$projectPath})", $server->name);
                    }
                }
            }

            // 3. Backup Docker system configuration
            if ($sourceConfig['backupDockerConfig'] ?? true) {
                // Docker daemon configuration
                if ($this->fileExists($server, '/etc/docker/daemon.json')) {
                    $paths[] = '/etc/docker/daemon.json';
                    $this->logger->info("Including Docker daemon config", $server->name);
                }

                // Docker service configuration
                if ($this->fileExists($server, '/etc/docker')) {
                    $paths[] = '/etc/docker';
                    $this->logger->info("Including Docker config directory", $server->name);
                }
            }

            // 4. Export custom networks configuration (if selected)
            if ($sourceConfig['backupCustomNetworks'] ?? false) {
                $this->exportNetworkConfig($server);
                $paths[] = '/tmp/phpborg_docker_networks.json';
                $this->logger->info("Exported Docker networks configuration", $server->name);
            }

            // 5. Export containers list for documentation
            $this->exportContainersList($server);
            $paths[] = '/tmp/phpborg_docker_containers.json';

            if (empty($paths)) {
                throw new BackupException("No Docker paths selected for backup");
            }

            $this->logger->info("Docker backup prepared with " . count($paths) . " paths", $server->name);

            return [
                'paths' => $paths,
                'cleanup' => true, // Will cleanup exported JSON files
            ];

        } catch (BackupException $e) {
            $this->logger->error("Failed to prepare Docker backup: {$e->getMessage()}", $server->name);
            throw $e;
        }
    }

    public function cleanupBackup(Server $server, DatabaseInfo $dbInfo): void
    {
        $this->logger->info("Cleaning up Docker backup", $server->name);

        try {
            // Remove temporary exported files
            $this->sshExecutor->execute($server, 'rm -f /tmp/phpborg_docker_networks.json /tmp/phpborg_docker_containers.json', 5);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to cleanup Docker backup: {$e->getMessage()}", $server->name);
        }
    }

    public function getSupportedType(): string
    {
        return 'docker';
    }

    /**
     * Parse source config from DatabaseInfo
     */
    private function parseSourceConfig(DatabaseInfo $dbInfo): array
    {
        // Source config is stored in dataPath as JSON
        // Format: {"backupAllVolumes": true, "selectedVolumes": [], "selectedComposeProjects": [], ...}

        if (!empty($dbInfo->dataPath)) {
            $config = json_decode($dbInfo->dataPath, true);
            if (is_array($config)) {
                return $config;
            }
        }

        // Default configuration
        return [
            'backupAllVolumes' => true,
            'selectedVolumes' => [],
            'selectedComposeProjects' => [],
            'backupDockerConfig' => true,
            'backupCustomNetworks' => false,
        ];
    }

    /**
     * Get all Docker volume mount paths
     */
    private function getAllVolumePaths(Server $server): array
    {
        $paths = [];

        // Get all volumes with their mount points
        $result = $this->sshExecutor->execute(
            $server,
            'docker volume ls -q | xargs -I {} docker volume inspect {} --format "{{.Mountpoint}}" 2>/dev/null',
            30
        );

        $this->logger->info("Docker volume detection - exit code: {$result['exitCode']}, stdout length: " . strlen($result['stdout']), $server->name);

        if ($result['exitCode'] !== 0) {
            $this->logger->error("Failed to get Docker volumes: {$result['stderr']}", $server->name);
        }

        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            $paths = array_filter(explode("\n", trim($result['stdout'])));
            $this->logger->info("Found " . count($paths) . " Docker volume paths", $server->name);
        } else {
            $this->logger->warning("No Docker volumes found or command failed", $server->name);
        }

        return $paths;
    }

    /**
     * Get specific Docker volume path
     */
    private function getVolumePath(Server $server, string $volumeName): ?string
    {
        $result = $this->sshExecutor->execute(
            $server,
            sprintf('docker volume inspect %s --format "{{.Mountpoint}}" 2>/dev/null', escapeshellarg($volumeName)),
            10
        );

        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            return trim($result['stdout']);
        }

        return null;
    }

    /**
     * Get Compose project working directory
     */
    private function getComposeProjectPath(Server $server, string $projectName): ?string
    {
        // Get the working directory from compose project containers
        $result = $this->sshExecutor->execute(
            $server,
            sprintf(
                'docker ps -a --filter "label=com.docker.compose.project=%s" --format "{{.Label \"com.docker.compose.project.working_dir\"}}" | head -1',
                escapeshellarg($projectName)
            ),
            10
        );

        if ($result['exitCode'] === 0 && !empty($result['stdout'])) {
            return trim($result['stdout']);
        }

        return null;
    }

    /**
     * Get all Compose projects on server
     * Returns array of [projectName => ['name' => ..., 'working_dir' => ..., 'containers' => []]]
     */
    private function getAllComposeProjects(Server $server): array
    {
        $projects = [];

        // Get all containers with compose labels
        $result = $this->sshExecutor->execute(
            $server,
            'docker ps -a --filter "label=com.docker.compose.project" --format "{{.ID}}|{{.Names}}|{{.Label \"com.docker.compose.project\"}}|{{.Label \"com.docker.compose.project.working_dir\"}}"',
            30
        );

        if ($result['exitCode'] !== 0 || empty($result['stdout'])) {
            $this->logger->warning("No Compose projects found or command failed", $server->name);
            return [];
        }

        $lines = explode("\n", trim($result['stdout']));
        foreach ($lines as $line) {
            if (empty($line)) continue;

            $parts = explode('|', $line);
            if (count($parts) < 4) continue;

            [$containerId, $containerName, $projectName, $workingDir] = $parts;

            if (empty($projectName)) continue;

            // Initialize project if not seen before
            if (!isset($projects[$projectName])) {
                $projects[$projectName] = [
                    'name' => $projectName,
                    'working_dir' => $workingDir ?: null,
                    'containers' => []
                ];
            }

            $projects[$projectName]['containers'][] = $containerName;
        }

        $this->logger->info("Detected " . count($projects) . " Compose projects", $server->name);
        return $projects;
    }

    /**
     * Check if file exists on server
     */
    private function fileExists(Server $server, string $path): bool
    {
        $result = $this->sshExecutor->execute(
            $server,
            sprintf('test -e %s && echo "exists"', escapeshellarg($path)),
            5
        );

        return $result['exitCode'] === 0 && trim($result['stdout']) === 'exists';
    }

    /**
     * Export Docker networks configuration to JSON
     */
    private function exportNetworkConfig(Server $server): void
    {
        // Export all custom networks (exclude default bridge/host/none)
        $this->sshExecutor->execute(
            $server,
            'docker network ls --filter "type=custom" --format "{{.ID}}" | xargs docker network inspect > /tmp/phpborg_docker_networks.json 2>/dev/null',
            15
        );
    }

    /**
     * Export containers list with metadata
     */
    private function exportContainersList(Server $server): void
    {
        // Export all containers configuration for documentation
        $this->sshExecutor->execute(
            $server,
            'docker ps -a --format "{{.ID}}" | xargs docker inspect > /tmp/phpborg_docker_containers.json 2>/dev/null',
            30
        );
    }
}
