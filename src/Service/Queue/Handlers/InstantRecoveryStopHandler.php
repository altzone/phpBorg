<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Application;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
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

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->recoveryManager = $app->getInstantRecoveryManager();
        $this->sessionRepo = $app->getInstantRecoverySessionRepository();
        $this->serverRepo = $app->getServerRepository();
        $this->logger = $app->getLogger();
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

        // Stop recovery
        $this->recoveryManager->stopRecovery($session, $server);

        $message = "Instant recovery session {$sessionId} stopped successfully";
        $this->logger->info($message);

        return $message;
    }

    public function getType(): string
    {
        return 'instant_recovery_stop';
    }
}
