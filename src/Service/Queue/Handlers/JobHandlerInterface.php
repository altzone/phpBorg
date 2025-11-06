<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Job handler interface
 */
interface JobHandlerInterface
{
    /**
     * Handle the job
     *
     * @param Job $job The job to process
     * @param JobQueue $queue Queue service for updating progress
     * @return string Job result/output
     * @throws \Exception If job fails
     */
    public function handle(Job $job, JobQueue $queue): string;
}
