<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Repository\RestoreOperationRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Service\Docker\DockerRestoreService;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Docker Restore API Controller
 */
class DockerRestoreController extends BaseController
{
    private readonly RestoreOperationRepository $restoreOperationRepo;
    private readonly ArchiveRepository $archiveRepo;
    private readonly DockerRestoreService $restoreService;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->restoreOperationRepo = $app->getRestoreOperationRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->restoreService = $app->getDockerRestoreService();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * POST /api/docker-restore/analyze
     * Analyze archive content for restore
     *
     * Request body:
     * {
     *   "archive_id": 123
     * }
     */
    public function analyze(): void
    {
        try {
            $data = $this->getJsonBody();
            $archiveId = $data['archive_id'] ?? null;

            if (!$archiveId) {
                $this->error('Missing archive_id', 400, 'MISSING_ARCHIVE_ID');
                return;
            }

            $analysis = $this->restoreService->analyzeArchive((int)$archiveId);

            $this->success([
                'analysis' => $analysis,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ANALYSIS_ERROR');
        }
    }

    /**
     * POST /api/docker-restore/detect-conflicts
     * Detect conflicts with running containers (via job queue for security)
     *
     * Request body:
     * {
     *   "server_id": 1,
     *   "selected_items": {
     *     "volumes": ["volume1", "volume2"],
     *     "projects": ["project1"]
     *   }
     * }
     *
     * Returns: job_id for polling results
     */
    public function detectConflicts(): void
    {
        try {

            $data = $this->getJsonBody();
            $serverId = $data['server_id'] ?? null;
            $selectedItems = $data['selected_items'] ?? [];

            if (!$serverId) {
                $this->error('Missing server_id', 400, 'MISSING_SERVER_ID');
                return;
            }

            // Create job for conflict detection (requires SSH access via phpborg user)
            $jobId = $this->jobQueue->push('docker_conflicts_detection', [
                'server_id' => (int)$serverId,
                'selected_items' => $selectedItems,
            ]);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Conflict detection started',
            ], 'Conflict detection queued', 202);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'CONFLICT_DETECTION_ERROR');
        }
    }

    /**
     * POST /api/docker-restore/preview-script
     * Generate restore script preview
     *
     * Request body:
     * {
     *   "operation_id": 123,
     *   "advanced": false
     * }
     */
    public function previewScript(): void
    {
        try {

            $data = $this->getJsonBody();
            $operationId = $data['operation_id'] ?? null;
            $advanced = $data['advanced'] ?? false;

            if (!$operationId) {
                $this->error('Missing operation_id', 400, 'MISSING_OPERATION_ID');
                return;
            }

            // Build config for script generation
            $config = [
                'operation_id' => (int)$operationId,
                ...$data
            ];

            $script = $this->restoreService->generateRestoreScript($config, (bool)$advanced);

            $this->success([
                'script' => $script,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SCRIPT_GENERATION_ERROR');
        }
    }

    /**
     * POST /api/docker-restore/create-operation
     * Create a restore operation record (for preview, without starting job)
     *
     * Request body:
     * {
     *   "archive_id": 123,
     *   "server_id": 1,
     *   "mode": "express",
     *   "restore_type": "full",
     *   "destination": "in_place",
     *   "selected_items": {...},
     *   "auto_restart": true
     * }
     */
    public function createOperation(): void
    {
        try {

            $data = $this->getJsonBody();
            $archiveId = $data['archive_id'] ?? null;
            $serverId = $data['server_id'] ?? null;

            if (!$archiveId || !$serverId) {
                $this->error('Missing archive_id or server_id', 400, 'MISSING_PARAMETERS');
                return;
            }

            // Get current user ID (hardcoded to admin for now)
            $userId = 1;

            // Create restore operation record
            $operationData = [
                'archive_id' => (int)$archiveId,
                'server_id' => (int)$serverId,
                'user_id' => $userId,
                'source_type' => 'docker',
                'mode' => $data['mode'] ?? 'express',
                'restore_type' => $data['restore_type'] ?? 'full',
                'destination' => $data['destination'] ?? 'in_place',
                'alternative_path' => $data['alternative_path'] ?? null,
                'compose_path_adaptation' => $data['compose_path_adaptation'] ?? 'none',
                'selected_items' => $data['selected_items'] ?? null,
                'auto_restart' => $data['auto_restart'] ?? true,
                'lvm_snapshot_created' => false,
                'pre_restore_backup_created' => false,
                'script_executed' => false,
                'status' => 'draft', // Draft status for preview only
            ];

            $operationId = $this->restoreOperationRepo->create($operationData);

            // Get the created operation
            $operation = $this->restoreOperationRepo->findById($operationId);

            $this->success([
                'operation' => $this->serializeOperation($operation),
            ]);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'OPERATION_CREATE_ERROR');
        }
    }

    /**
     * POST /api/docker-restore/start
     * Start a Docker restore operation (creates or updates job)
     *
     * Request body:
     * {
     *   "operation_id": 123 (optional, if already created for preview),
     *   "archive_id": 123,
     *   "server_id": 1,
     *   "mode": "express",
     *   "restore_type": "full",
     *   "destination": "in_place",
     *   "selected_items": {...},
     *   "create_lvm_snapshot": true,
     *   "auto_restart": true
     * }
     */
    public function start(): void
    {
        try {

            $data = $this->getJsonBody();
            $existingOperationId = $data['operation_id'] ?? null;
            $archiveId = $data['archive_id'] ?? null;
            $serverId = $data['server_id'] ?? null;

            if (!$archiveId || !$serverId) {
                $this->error('Missing archive_id or server_id', 400, 'MISSING_PARAMETERS');
                return;
            }

            // If operation already exists (from preview), update it, otherwise create new
            if ($existingOperationId) {
                $operationId = (int)$existingOperationId;
                // Update status from 'draft' to 'pending'
                $this->restoreOperationRepo->update($operationId, ['status' => 'pending']);
            } else {
                // Get current user ID (hardcoded to admin for now)
                $userId = 1;

                // Create restore operation record
                $operationData = [
                    'archive_id' => (int)$archiveId,
                    'server_id' => (int)$serverId,
                    'user_id' => $userId,
                    'source_type' => 'docker',
                    'mode' => $data['mode'] ?? 'express',
                    'restore_type' => $data['restore_type'] ?? 'full',
                    'destination' => $data['destination'] ?? 'in_place',
                    'alternative_path' => $data['alternative_path'] ?? null,
                    'compose_path_adaptation' => $data['compose_path_adaptation'] ?? 'none',
                    'selected_items' => $data['selected_items'] ?? null,
                    'auto_restart' => $data['auto_restart'] ?? true,
                    'status' => 'pending',
                ];

                $operationId = $this->restoreOperationRepo->create($operationData);
            }

            // Create job for async execution
            $jobPayload = [
                'operation_id' => $operationId,
                'create_lvm_snapshot' => $data['create_lvm_snapshot'] ?? false,
                'lvm_path' => $data['lvm_path'] ?? '/dev/vg_data/lv_docker',
                'snapshot_size' => $data['snapshot_size'] ?? '20G',
                'create_pre_restore_backup' => $data['create_pre_restore_backup'] ?? false,
                'auto_rollback_on_failure' => $data['auto_rollback_on_failure'] ?? true,
                'containers_to_stop' => $data['containers_to_stop'] ?? [],
            ];

            $jobId = $this->jobQueue->push('docker_restore', $jobPayload);

            $this->success([
                'operation_id' => $operationId,
                'job_id' => $jobId,
                'message' => 'Docker restore started successfully',
            ], 'Restore operation queued', 202);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'RESTORE_START_ERROR');
        }
    }

    /**
     * GET /api/docker-restore/:id
     * Get restore operation status
     */
    public function show(): void
    {
        try {

            $operationId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($operationId <= 0) {
                $this->error('Invalid operation ID', 400, 'INVALID_OPERATION_ID');
                return;
            }

            $operation = $this->restoreOperationRepo->findById($operationId);

            if (!$operation) {
                $this->error('Restore operation not found', 404, 'OPERATION_NOT_FOUND');
                return;
            }

            $this->success([
                'operation' => $this->serializeOperation($operation),
            ]);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'OPERATION_FETCH_ERROR');
        }
    }

    /**
     * GET /api/docker-restore
     * List all restore operations
     */
    public function list(): void
    {
        try {

            $serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : null;
            $sourceType = $_GET['source_type'] ?? null;

            $operations = $serverId
                ? $this->restoreOperationRepo->findByServerId($serverId)
                : ($sourceType
                    ? $this->restoreOperationRepo->findBySourceType($sourceType)
                    : $this->restoreOperationRepo->findAll());

            $this->success([
                'operations' => array_map(fn($op) => $this->serializeOperation($op), $operations),
                'total' => count($operations),
            ]);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'OPERATIONS_LIST_ERROR');
        }
    }

    /**
     * POST /api/docker-restore/:id/rollback
     * Rollback a restore operation (within 8h window)
     */
    public function rollback(): void
    {
        try {

            $operationId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($operationId <= 0) {
                $this->error('Invalid operation ID', 400, 'INVALID_OPERATION_ID');
                return;
            }

            $operation = $this->restoreOperationRepo->findById($operationId);

            if (!$operation) {
                $this->error('Restore operation not found', 404, 'OPERATION_NOT_FOUND');
                return;
            }

            // Check if rollback is still possible
            if (!$operation->canRollback()) {
                $this->error('Rollback window expired (8 hours maximum)', 400, 'ROLLBACK_EXPIRED');
                return;
            }

            // Create job for rollback
            $jobId = $this->jobQueue->push('docker_rollback', [
                'operation_id' => $operationId,
            ]);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Rollback started successfully',
            ], 'Rollback operation queued', 202);

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'ROLLBACK_ERROR');
        }
    }

    /**
     * Serialize restore operation for API response
     */
    private function serializeOperation(object $operation): array
    {
        return [
            'id' => $operation->id,
            'archive_id' => $operation->archiveId,
            'server_id' => $operation->serverId,
            'user_id' => $operation->userId,
            'source_type' => $operation->sourceType,
            'mode' => $operation->mode,
            'restore_type' => $operation->restoreType,
            'destination' => $operation->destination,
            'alternative_path' => $operation->alternativePath,
            'compose_path_adaptation' => $operation->composePathAdaptation,
            'selected_items' => $operation->selectedItems,
            'lvm_snapshot_created' => $operation->lvmSnapshotCreated,
            'lvm_snapshot_name' => $operation->lvmSnapshotName,
            'pre_restore_backup_created' => $operation->preRestoreBackupCreated,
            'pre_restore_backup_archive' => $operation->preRestoreBackupArchive,
            'auto_restart' => $operation->autoRestart,
            'stopped_containers' => $operation->stoppedContainers,
            'status' => $operation->status,
            'started_at' => $operation->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $operation->completedAt?->format('Y-m-d H:i:s'),
            'error_message' => $operation->errorMessage,
            'generated_script' => $operation->generatedScript,
            'script_executed' => $operation->scriptExecuted,
            'can_rollback_until' => $operation->canRollbackUntil?->format('Y-m-d H:i:s'),
            'can_rollback' => $operation->canRollback(),
            'rolled_back_at' => $operation->rolledBackAt?->format('Y-m-d H:i:s'),
            'items_restored' => $operation->itemsRestored,
            'bytes_restored' => $operation->bytesRestored,
            'created_at' => $operation->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $operation->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
