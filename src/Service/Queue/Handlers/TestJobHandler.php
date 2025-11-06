<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Test job handler for demonstration purposes
 */
final class TestJobHandler implements JobHandlerInterface
{
    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $message = $payload['message'] ?? 'Test job';

        // Simulate work with progress updates
        $queue->updateProgress($job->id, 0, "Starting test job: {$message}");
        sleep(2);

        $queue->updateProgress($job->id, 25, "Processing step 1...");
        sleep(2);

        $queue->updateProgress($job->id, 50, "Processing step 2...");
        sleep(2);

        $queue->updateProgress($job->id, 75, "Processing step 3...");
        sleep(2);

        $queue->updateProgress($job->id, 100, "Test job completed successfully!");

        return "Test job completed: {$message}";
    }
}
