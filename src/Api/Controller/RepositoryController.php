<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\BorgRepositoryRepository;

/**
 * Repository management API controller
 */
final class RepositoryController extends BaseController
{
    private readonly BorgRepositoryRepository $repositoryRepo;

    public function __construct(Application $app)
    {
        $this->repositoryRepo = $app->getBorgRepositoryRepository();
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
            $this->error($e->getMessage(), 'REPOSITORY_LIST_FAILED');
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
                $this->error('Repository not found', 'REPOSITORY_NOT_FOUND', 404);
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
            $this->error($e->getMessage(), 'REPOSITORY_SHOW_FAILED');
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
                $this->error('Repository not found', 'REPOSITORY_NOT_FOUND', 404);
                return;
            }

            // Validate required fields
            if (!isset($data['keep_daily']) || !isset($data['keep_weekly']) || !isset($data['keep_monthly'])) {
                $this->error('Missing required fields: keep_daily, keep_weekly, keep_monthly', 'VALIDATION_ERROR', 400);
                return;
            }

            $keepDaily = (int)$data['keep_daily'];
            $keepWeekly = (int)$data['keep_weekly'];
            $keepMonthly = (int)$data['keep_monthly'];
            $keepYearly = (int)($data['keep_yearly'] ?? 0);

            // Validate values
            if ($keepDaily < 0 || $keepDaily > 365) {
                $this->error('keep_daily must be between 0 and 365', 'INVALID_RETENTION_VALUE', 400);
                return;
            }
            if ($keepWeekly < 0 || $keepWeekly > 52) {
                $this->error('keep_weekly must be between 0 and 52', 'INVALID_RETENTION_VALUE', 400);
                return;
            }
            if ($keepMonthly < 0 || $keepMonthly > 60) {
                $this->error('keep_monthly must be between 0 and 60', 'INVALID_RETENTION_VALUE', 400);
                return;
            }
            if ($keepYearly < 0 || $keepYearly > 10) {
                $this->error('keep_yearly must be between 0 and 10', 'INVALID_RETENTION_VALUE', 400);
                return;
            }

            // At least one retention value must be > 0
            if ($keepDaily === 0 && $keepWeekly === 0 && $keepMonthly === 0 && $keepYearly === 0) {
                $this->error('At least one retention value must be greater than 0', 'INVALID_RETENTION_POLICY', 400);
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
            $this->error($e->getMessage(), 'UPDATE_RETENTION_FAILED');
        }
    }
}
