<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Application;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\InstantRecoveryLogger;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Job handler for stopping instant recovery sessions
 */
final class InstantRecoveryStopHandler implements JobHandlerInterface
{
    private $app;
    private $recoveryManager;
    private $sessionRepo;
    private $serverRepo;
    private $logger;
    private $recoveryLogger;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->recoveryManager = $app->getInstantRecoveryManager();
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->logger = $app->getLogger();
        $this->recoveryLogger = $app->getInstantRecoveryLogger();
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $sessionId = $payload['session_id'];

        $this->logger->info("Stopping instant recovery session {$sessionId}");

        // Get session
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new BackupException("Instant recovery session not found: {$sessionId}");
        }

        // Get server
        $server = $this->serverRepo->findById($session->serverId);
        if (!$server) {
            throw new BackupException("Server not found for session {$sessionId}");
        }

        // INSTANT RECOVERY LOG: Stop
        $this->recoveryLogger->info('stop', "Stopping instant recovery session #{$sessionId}", [
            'session_id' => $sessionId,
            'server_id' => $session->serverId,
            'server_name' => $server->name,
            'archive_id' => $session->archiveId,
            'job_id' => $job->id
        ]);

        try {
            // Stop recovery
            $this->recoveryManager->stopRecovery($session, $server);

            $message = "Instant recovery session {$sessionId} stopped successfully";
            $this->logger->info($message);

            // INSTANT RECOVERY LOG: Success
            $this->recoveryLogger->info('stop', "Instant recovery session stopped successfully: #{$sessionId}", [
                'session_id' => $sessionId,
                'server_name' => $server->name,
                'job_id' => $job->id
            ]);

            return $message;
        } catch (\Exception $e) {
            // INSTANT RECOVERY LOG: Error
            $this->recoveryLogger->error('stop', "Instant recovery stop failed: {$e->getMessage()}", [
                'session_id' => $sessionId,
                'server_name' => $server->name,
                'error' => $e->getMessage(),
                'job_id' => $job->id
            ]);
            throw $e;
        }
    }

    public function getType(): string
    {
        return 'instant_recovery_stop';
    }
}
