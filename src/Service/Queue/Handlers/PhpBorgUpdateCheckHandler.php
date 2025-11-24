<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\PhpBorgUpdateService;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for checking phpBorg updates
 *
 * This runs as a job under the phpborg user to avoid git permission issues
 */
final class PhpBorgUpdateCheckHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PhpBorgUpdateService $updateService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $this->logger->info("Checking for phpBorg updates", 'PHPBORG_UPDATE_CHECK');

        try {
            // Check for updates
            $updateInfo = $this->updateService->checkForUpdates();

            if ($updateInfo['error']) {
                throw new \Exception($updateInfo['error']);
            }

            // Get current version details
            $versionInfo = $this->updateService->getCurrentVersion();

            if ($updateInfo['available']) {
                $message = sprintf(
                    "Update available: %d commit(s) behind (current: %s, latest: %s)",
                    $updateInfo['commits_behind'],
                    $updateInfo['current_commit_short'],
                    $updateInfo['latest_commit_short']
                );
                $this->logger->info($message, 'PHPBORG_UPDATE_CHECK');

                // Also fetch changelog
                $changelog = $this->updateService->getChangelog();

                return json_encode([
                    'update_info' => $updateInfo,
                    'version_info' => $versionInfo,
                    'changelog' => $changelog
                ]);
            } else {
                $this->logger->info("phpBorg is up to date", 'PHPBORG_UPDATE_CHECK', [
                    'current_commit' => $updateInfo['current_commit_short']
                ]);

                return json_encode([
                    'update_info' => $updateInfo,
                    'version_info' => $versionInfo,
                    'changelog' => ['commits' => []]
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to check for updates", 'PHPBORG_UPDATE_CHECK', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
