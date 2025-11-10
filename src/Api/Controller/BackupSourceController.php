<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\BackupSourceRepository;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Backup source management API controller
 */
class BackupSourceController extends BaseController
{
    private readonly BackupSourceRepository $backupSourceRepository;
    private readonly ServerRepository $serverRepository;

    public function __construct(Application $app)
    {
        $this->backupSourceRepository = new BackupSourceRepository($app->getConnection());
        $this->serverRepository = new ServerRepository($app->getConnection());
    }

    /**
     * Get all backup sources
     * GET /api/backup-sources
     */
    public function index(): array
    {
        $sources = $this->backupSourceRepository->findAll();
        
        return [
            'success' => true,
            'data' => array_map(fn($source) => $source->toArray(), $sources),
            'total' => count($sources)
        ];
    }

    /**
     * Get active backup sources
     * GET /api/backup-sources/active
     */
    public function active(): array
    {
        $sources = $this->backupSourceRepository->findActive();
        
        return [
            'success' => true,
            'data' => array_map(fn($source) => $source->toArray(), $sources),
            'total' => count($sources)
        ];
    }

    /**
     * Get backup source by ID
     * GET /api/backup-sources/{id}
     */
    public function show(int $id): array
    {
        $source = $this->backupSourceRepository->findById($id);
        
        if (!$source) {
            throw new PhpBorgException('Backup source not found', 404);
        }

        // Get server details
        $server = $this->serverRepository->findById($source->serverId);

        return [
            'success' => true,
            'data' => array_merge(
                $source->toArray(),
                ['server' => $server ? $server->toArray() : null]
            )
        ];
    }

    /**
     * Create a new backup source
     * POST /api/backup-sources
     */
    public function create(array $data): array
    {
        // Validate required fields
        if (!isset($data['name']) || empty($data['name'])) {
            throw new PhpBorgException('Name is required', 400);
        }

        if (!isset($data['type']) || empty($data['type'])) {
            throw new PhpBorgException('Type is required', 400);
        }

        if (!isset($data['server_id']) || empty($data['server_id'])) {
            throw new PhpBorgException('Server ID is required', 400);
        }

        // Validate server exists
        if (!$this->serverRepository->exists((int)$data['server_id'])) {
            throw new PhpBorgException('Invalid server ID', 400);
        }

        // Validate type-specific configuration
        $this->validateTypeConfig($data['type'], $data['config'] ?? []);

        // Create backup source
        $source = $this->backupSourceRepository->create($data);

        return [
            'success' => true,
            'message' => 'Backup source created successfully',
            'data' => $source->toArray()
        ];
    }

    /**
     * Update a backup source
     * PUT /api/backup-sources/{id}
     */
    public function update(int $id, array $data): array
    {
        // Check if source exists
        if (!$this->backupSourceRepository->exists($id)) {
            throw new PhpBorgException('Backup source not found', 404);
        }

        // Validate server if provided
        if (isset($data['server_id']) && !$this->serverRepository->exists((int)$data['server_id'])) {
            throw new PhpBorgException('Invalid server ID', 400);
        }

        // Validate type-specific configuration if provided
        if (isset($data['type']) && isset($data['config'])) {
            $this->validateTypeConfig($data['type'], $data['config']);
        }

        // Update backup source
        $source = $this->backupSourceRepository->update($id, $data);

        return [
            'success' => true,
            'message' => 'Backup source updated successfully',
            'data' => $source->toArray()
        ];
    }

    /**
     * Delete a backup source
     * DELETE /api/backup-sources/{id}
     */
    public function delete(int $id): array
    {
        if (!$this->backupSourceRepository->exists($id)) {
            throw new PhpBorgException('Backup source not found', 404);
        }

        // Check if source is used by any jobs
        $jobsUsing = $this->checkSourceUsage($id);
        if ($jobsUsing > 0) {
            throw new PhpBorgException(
                "Cannot delete backup source: $jobsUsing job(s) are using it", 
                409
            );
        }

        $deleted = $this->backupSourceRepository->delete($id);

        return [
            'success' => $deleted,
            'message' => $deleted ? 'Backup source deleted successfully' : 'Failed to delete backup source'
        ];
    }

    /**
     * Get backup sources by server
     * GET /api/backup-sources/by-server/{serverId}
     */
    public function byServer(int $serverId): array
    {
        $sources = $this->backupSourceRepository->findByServerId($serverId);
        
        return [
            'success' => true,
            'data' => array_map(fn($source) => $source->toArray(), $sources),
            'total' => count($sources)
        ];
    }

    /**
     * Get backup sources by type
     * GET /api/backup-sources/by-type/{type}
     */
    public function byType(string $type): array
    {
        $sources = $this->backupSourceRepository->findByType($type);
        
        return [
            'success' => true,
            'data' => array_map(fn($source) => $source->toArray(), $sources),
            'total' => count($sources)
        ];
    }

    /**
     * Get type statistics
     * GET /api/backup-sources/statistics
     */
    public function statistics(): array
    {
        $stats = $this->backupSourceRepository->getTypeStatistics();
        
        return [
            'success' => true,
            'data' => $stats
        ];
    }

    /**
     * Search backup sources
     * GET /api/backup-sources/search?q={query}
     */
    public function search(string $q): array
    {
        if (strlen($q) < 2) {
            throw new PhpBorgException('Search query must be at least 2 characters', 400);
        }

        $sources = $this->backupSourceRepository->search($q);
        
        return [
            'success' => true,
            'data' => array_map(fn($source) => $source->toArray(), $sources),
            'total' => count($sources)
        ];
    }

    /**
     * Validate backup source configuration
     * POST /api/backup-sources/validate
     */
    public function validate(array $data): array
    {
        if (!isset($data['type']) || empty($data['type'])) {
            throw new PhpBorgException('Type is required for validation', 400);
        }

        $config = $data['config'] ?? [];
        $isValid = false;
        $errors = [];

        try {
            $this->validateTypeConfig($data['type'], $config);
            $isValid = true;
        } catch (PhpBorgException $e) {
            $errors[] = $e->getMessage();
        }

        // Type-specific validation
        switch ($data['type']) {
            case 'mysql':
                if (!isset($config['database'])) {
                    $errors[] = 'Database name is required';
                }
                if (!isset($config['user'])) {
                    $errors[] = 'Database user is required';
                }
                break;

            case 'files':
                if (!isset($data['paths']) || empty($data['paths'])) {
                    $errors[] = 'At least one path is required for file backup';
                }
                break;

            case 'docker':
                if (!isset($config['container'])) {
                    $errors[] = 'Container ID or name is required';
                }
                break;
        }

        return [
            'success' => true,
            'valid' => $isValid && empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get available backup types
     * GET /api/backup-sources/types
     */
    public function types(): array
    {
        return [
            'success' => true,
            'data' => [
                [
                    'id' => 'mysql',
                    'name' => 'MySQL Database',
                    'description' => 'Backup MySQL/MariaDB databases',
                    'icon' => 'fas fa-database',
                    'required_config' => ['database', 'user'],
                    'optional_config' => ['password', 'host', 'port', 'dump_options']
                ],
                [
                    'id' => 'postgresql',
                    'name' => 'PostgreSQL Database',
                    'description' => 'Backup PostgreSQL databases',
                    'icon' => 'fas fa-database',
                    'required_config' => ['database', 'user'],
                    'optional_config' => ['password', 'host', 'port', 'dump_options']
                ],
                [
                    'id' => 'files',
                    'name' => 'Files & Folders',
                    'description' => 'Backup files and directories',
                    'icon' => 'fas fa-folder-tree',
                    'required_config' => [],
                    'required_fields' => ['paths'],
                    'optional_fields' => ['exclude_patterns']
                ],
                [
                    'id' => 'docker',
                    'name' => 'Docker Container',
                    'description' => 'Backup Docker containers and volumes',
                    'icon' => 'fab fa-docker',
                    'required_config' => ['container'],
                    'optional_config' => ['volumes', 'stop_during_backup']
                ],
                [
                    'id' => 'vm',
                    'name' => 'Virtual Machine',
                    'description' => 'Backup virtual machines',
                    'icon' => 'fas fa-server',
                    'required_config' => ['vm_id'],
                    'optional_config' => ['vm_name', 'snapshot']
                ],
                [
                    'id' => 'custom',
                    'name' => 'Custom Backup',
                    'description' => 'Custom backup with script',
                    'icon' => 'fas fa-cogs',
                    'required_config' => ['command'],
                    'optional_config' => ['arguments', 'environment']
                ]
            ]
        ];
    }

    /**
     * Validate type-specific configuration
     */
    private function validateTypeConfig(string $type, array $config): void
    {
        $validTypes = ['mysql', 'postgresql', 'files', 'docker', 'vm', 'custom'];
        
        if (!in_array($type, $validTypes, true)) {
            throw new PhpBorgException("Invalid backup source type: $type", 400);
        }

        // Type-specific validation will be extended based on needs
    }

    /**
     * Check if source is used by any backup jobs
     */
    private function checkSourceUsage(int $sourceId): int
    {
        $app = new Application();
        $result = $app->getConnection()->query(
            'SELECT COUNT(*) as count FROM backup_jobs WHERE source_id = :source_id',
            ['source_id' => $sourceId]
        );

        $row = $result->fetch();
        return $row ? (int)$row['count'] : 0;
    }
}