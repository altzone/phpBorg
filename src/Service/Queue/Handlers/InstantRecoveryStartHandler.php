<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Application;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Job handler for starting instant recovery sessions
 */
final class InstantRecoveryStartHandler implements JobHandlerInterface
{
    private $app;
    private $recoveryManager;
    private $sessionRepo;
    private $archiveRepo;
    private $serverRepo;
    private $logger;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->recoveryManager = $app->getInstantRecoveryManager();
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->logger = $app->getLogger();
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $archiveId = $payload['archive_id'];
        $deploymentLocation = $payload['deployment_location'] ?? 'remote';
        $dbType = $payload['db_type'] ?? 'postgresql';

        $this->logger->info("Starting instant recovery job for archive {$archiveId}");

        // Get archive
        $archive = $this->archiveRepo->findById($archiveId);
        if (!$archive) {
            throw new BackupException("Archive not found: {$archiveId}");
        }

        // Get server
        $server = $this->serverRepo->findById($archive->serverId);
        if (!$server) {
            throw new BackupException("Server not found for archive {$archiveId}");
        }

        // Start recovery
        $session = $this->recoveryManager->startRecovery($archive, $server, $dbType, $deploymentLocation);

        $message = "Instant recovery started successfully. Session ID: {$session->id}, Port: {$session->dbPort}";
        $this->logger->info($message);

        // Return JSON result for frontend parsing
        return json_encode([
            'session_id' => $session->id,
            'port' => $session->dbPort,
            'message' => $message
        ]);
    }

    public function getType(): string
    {
        return 'instant_recovery_start';
    }
}
