<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Application;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\InstantRecoveryLogger;
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
    private $recoveryLogger;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->recoveryManager = $app->getInstantRecoveryManager();
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->archiveRepo = $app->getArchiveRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->logger = $app->getLogger();
        $this->recoveryLogger = $app->getInstantRecoveryLogger();
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

        // INSTANT RECOVERY LOG: Start
        $this->recoveryLogger->info('start', "Starting instant recovery for archive '{$archive->name}'", [
            'archive_id' => $archiveId,
            'archive_name' => $archive->name,
            'server_id' => $archive->serverId,
            'server_name' => $server->name,
            'db_type' => $dbType,
            'deployment_location' => $deploymentLocation,
            'job_id' => $job->id
        ]);

        try {
            // Start recovery
            $session = $this->recoveryManager->startRecovery($archive, $server, $dbType, $deploymentLocation);

            $message = "Instant recovery started successfully. Session ID: {$session->id}, Port: {$session->dbPort}";
            $this->logger->info($message);

            // INSTANT RECOVERY LOG: Success
            $this->recoveryLogger->info('start', "Instant recovery started successfully: session #{$session->id}", [
                'session_id' => $session->id,
                'archive_id' => $archiveId,
                'archive_name' => $archive->name,
                'server_name' => $server->name,
                'db_port' => $session->dbPort,
                'db_type' => $dbType,
                'deployment_location' => $deploymentLocation,
                'job_id' => $job->id
            ]);

            // Return JSON result for frontend parsing
            return json_encode([
                'session_id' => $session->id,
                'port' => $session->dbPort,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            // INSTANT RECOVERY LOG: Error
            $this->recoveryLogger->error('start', "Instant recovery start failed: {$e->getMessage()}", [
                'archive_id' => $archiveId,
                'archive_name' => $archive->name,
                'server_name' => $server->name,
                'db_type' => $dbType,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);
            throw $e;
        }
    }

    public function getType(): string
    {
        return 'instant_recovery_start';
    }
}
