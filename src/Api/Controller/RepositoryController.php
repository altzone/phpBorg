<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ArchiveRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Repository\ArchiveMountRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Repository management API controller
 */
final class RepositoryController extends BaseController
{
    private readonly BorgRepositoryRepository $repositoryRepo;
    private readonly ArchiveRepository $archiveRepo;
    private readonly BackupJobRepository $jobRepo;
    private readonly ArchiveMountRepository $mountRepo;
    private readonly JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->repositoryRepo = $app->getBorgRepositoryRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->jobRepo = $app->getBackupJobRepository();
        $this->mountRepo = $app->getArchiveMountRepository();
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * List all repositories
     * GET /api/repositories
     */
    public function list(): void
    {
        try {
            $repositories = $this->repositoryRepo->findAll();

            $response = array_map(function ($repo) {
                return [
                    'id' => $repo->id,
                    'server_id' => $repo->serverId,
                    'repo_id' => $repo->repoId,
                    'type' => $repo->type,
                    'encryption' => $repo->encryption,
                    'compression' => $repo->compression,
                    'rate_limit' => $repo->rateLimit,
                    'backup_path' => $repo->backupPath,
                    'exclude' => $repo->exclude,
                    'repo_path' => $repo->repoPath,
                    // Retention policy
                    'retention' => [
                        'keep_daily' => $repo->keepDaily,
                        'keep_weekly' => $repo->keepWeekly,
                        'keep_monthly' => $repo->keepMonthly,
                        'keep_yearly' => $repo->keepYearly,
                    ],
                    // Statistics
                    'stats' => [
                        'size' => $repo->size,
                        'compressed_size' => $repo->compressedSize,
                        'deduplicated_size' => $repo->deduplicatedSize,
                        'total_unique_chunks' => $repo->totalUniqueChunks,
                        'total_chunks' => $repo->totalChunks,
                    ],
                    'modified' => $repo->modified->format('Y-m-d H:i:s'),
                ];
            }, $repositories);

            $this->success($response);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'REPOSITORY_LIST_FAILED');
        }
    }

    /**
     * Get repository by ID
     * GET /api/repositories/{id}
     */
    public function show(): void
    {
        try {
            $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $repository = $this->repositoryRepo->findById($id);

            if ($repository === null) {
                $this->error('Repository not found', 404, 'REPOSITORY_NOT_FOUND');
                return;
            }

            $response = [
                'id' => $repository->id,
                'server_id' => $repository->serverId,
                'repo_id' => $repository->repoId,
                'type' => $repository->type,
                'encryption' => $repository->encryption,
                'compression' => $repository->compression,
                'rate_limit' => $repository->rateLimit,
                'backup_path' => $repository->backupPath,
                'exclude' => $repository->exclude,
                'repo_path' => $repository->repoPath,
                // Retention policy
                'retention' => [
                    'keep_daily' => $repository->keepDaily,
                    'keep_weekly' => $repository->keepWeekly,
                    'keep_monthly' => $repository->keepMonthly,
                    'keep_yearly' => $repository->keepYearly,
                ],
                // Statistics
                'stats' => [
                    'size' => $repository->size,
                    'compressed_size' => $repository->compressedSize,
                    'deduplicated_size' => $repository->deduplicatedSize,
                    'total_unique_chunks' => $repository->totalUniqueChunks,
                    'total_chunks' => $repository->totalChunks,
                ],
                'modified' => $repository->modified->format('Y-m-d H:i:s'),
            ];

            $this->success($response);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'REPOSITORY_SHOW_FAILED');
        }
    }

    /**
     * Update repository retention policy
     * PUT /api/repositories/{id}/retention
     */
    public function updateRetention(): void
    {
        try {
            $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            // Validate repository exists
            $repository = $this->repositoryRepo->findById($id);
            if ($repository === null) {
                $this->error('Repository not found', 404, 'REPOSITORY_NOT_FOUND');
                return;
            }

            // Validate required fields
            if (!isset($data['keep_daily']) || !isset($data['keep_weekly']) || !isset($data['keep_monthly'])) {
                $this->error('Missing required fields: keep_daily, keep_weekly, keep_monthly', 400, 'VALIDATION_ERROR');
                return;
            }

            $keepDaily = (int)$data['keep_daily'];
            $keepWeekly = (int)$data['keep_weekly'];
            $keepMonthly = (int)$data['keep_monthly'];
            $keepYearly = (int)($data['keep_yearly'] ?? 0);

            // Validate values
            if ($keepDaily < 0 || $keepDaily > 365) {
                $this->error('keep_daily must be between 0 and 365', 400, 'INVALID_RETENTION_VALUE');
                return;
            }
            if ($keepWeekly < 0 || $keepWeekly > 52) {
                $this->error('keep_weekly must be between 0 and 52', 400, 'INVALID_RETENTION_VALUE');
                return;
            }
            if ($keepMonthly < 0 || $keepMonthly > 60) {
                $this->error('keep_monthly must be between 0 and 60', 400, 'INVALID_RETENTION_VALUE');
                return;
            }
            if ($keepYearly < 0 || $keepYearly > 10) {
                $this->error('keep_yearly must be between 0 and 10', 400, 'INVALID_RETENTION_VALUE');
                return;
            }

            // At least one retention value must be > 0
            if ($keepDaily === 0 && $keepWeekly === 0 && $keepMonthly === 0 && $keepYearly === 0) {
                $this->error('At least one retention value must be greater than 0', 400, 'INVALID_RETENTION_POLICY');
                return;
            }

            // Update retention
            $this->repositoryRepo->updateRetention($id, $keepDaily, $keepWeekly, $keepMonthly, $keepYearly);

            // Return updated repository
            $updated = $this->repositoryRepo->findById($id);

            $this->success([
                'message' => 'Retention policy updated successfully',
                'retention' => [
                    'keep_daily' => $updated->keepDaily,
                    'keep_weekly' => $updated->keepWeekly,
                    'keep_monthly' => $updated->keepMonthly,
                    'keep_yearly' => $updated->keepYearly,
                ],
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'UPDATE_RETENTION_FAILED');
        }
    }

    /**
     * Delete repository with safety checks
     * DELETE /api/repositories/{id}
     *
     * ⚠️ CRITICAL: This deletes the entire Borg repository and ALL backups permanently
     * Creates a background job to:
     * - Check for active scheduled jobs (blocks if exist)
     * - Check for mounted archives (blocks if exist)
     * - Physically remove repository directory (rm -rf)
     * - Remove all database records
     */
    public function delete(): void
    {
        try {
            $id = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            // Check for force parameter (query string or JSON body)
            $force = isset($_GET['force']) && $_GET['force'] === '1';
            if (!$force) {
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $force = isset($data['force']) && $data['force'] === true;
            }

            // 1. Validate repository exists
            $repository = $this->repositoryRepo->findById($id);
            if ($repository === null) {
                $this->error('Repository not found', 404, 'REPOSITORY_NOT_FOUND');
                return;
            }

            // If force mode: delete directly from database (orphan cleanup)
            if ($force) {
                // Delete all related records
                $archives = $this->archiveRepo->findByRepositoryId($repository->repoId);
                foreach ($archives as $archive) {
                    // Delete mounts
                    $this->mountRepo->deleteByArchiveId($archive->id);
                    // Delete archive
                    $this->archiveRepo->deleteById($archive->id);
                }

                // Delete backup jobs
                $jobs = $this->jobRepo->findByRepositoryId($id);
                foreach ($jobs as $job) {
                    $this->jobRepo->deleteById($job->id);
                }

                // Delete repository
                $this->repositoryRepo->deleteById($id);

                $this->success([
                    'message' => 'Repository force deleted (database only)',
                    'repository_id' => $id,
                    'mode' => 'force',
                    'archives_deleted' => count($archives),
                    'jobs_deleted' => count($jobs),
                ]);
                return;
            }

            // 2. Quick check for active scheduled jobs
            $activeJobs = $this->jobRepo->findByRepositoryId($id);
            $enabledJobs = array_filter($activeJobs, fn($job) => $job->enabled);

            if (count($enabledJobs) > 0) {
                $this->error(
                    sprintf(
                        'Cannot delete repository: %d active scheduled job(s) exist. Disable or delete them first.',
                        count($enabledJobs)
                    ),
                    400,
                    'REPOSITORY_HAS_ACTIVE_JOBS',
                    [
                        'active_jobs_count' => count($enabledJobs),
                        'active_job_ids' => array_map(fn($job) => $job->id, $enabledJobs)
                    ]
                );
                return;
            }

            // 3. Quick check for mounted archives
            $archives = $this->archiveRepo->findByRepositoryId($repository->repoId);
            $mountedArchives = [];
            foreach ($archives as $archive) {
                $mount = $this->mountRepo->findByArchiveId($archive->id);
                if ($mount !== null && $mount->status === 'mounted') {
                    $mountedArchives[] = $archive;
                }
            }

            if (count($mountedArchives) > 0) {
                $this->error(
                    sprintf(
                        'Cannot delete repository: %d archive(s) currently mounted. Unmount them first.',
                        count($mountedArchives)
                    ),
                    400,
                    'REPOSITORY_HAS_MOUNTED_ARCHIVES'
                );
                return;
            }

            // 4. Create background job for repository deletion
            $jobId = $this->jobQueue->push(
                type: 'repository_delete',
                payload: ['repository_id' => $id],
                queue: 'default',
                maxAttempts: 1
            );

            // 5. Success response with job ID
            $this->success([
                'message' => 'Repository deletion job created',
                'job_id' => $jobId,
                'repository_id' => $id,
                'repository_path' => $repository->repoPath,
                'archives_count' => count($archives),
            ]);

        } catch (\Exception $e) {
            $this->error(
                'Failed to create repository deletion job: ' . $e->getMessage(),
                500,
                'DELETE_REPOSITORY_FAILED'
            );
        }
    }
}
