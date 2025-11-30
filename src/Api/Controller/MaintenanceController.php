<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Maintenance Controller
 * Provides endpoints for manual maintenance operations via job queue
 */
class MaintenanceController extends BaseController
{
    private readonly JobQueue $jobQueue;
    private readonly string $phpborgRoot;

    public function __construct(Application $app)
    {
        $this->jobQueue = $app->getJobQueue();
        $this->phpborgRoot = dirname(__DIR__, 3);
    }

    /**
     * POST /api/maintenance/restart-workers
     * Create job to restart all phpBorg workers
     */
    public function restartWorkers(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'restart_workers'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Restart workers job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/rebuild-agent
     * Create job to rebuild the Go agent binary
     */
    public function rebuildAgent(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'rebuild_agent'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Rebuild agent job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/rebuild-frontend
     * Create job to rebuild the Vue.js frontend
     */
    public function rebuildFrontend(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'rebuild_frontend'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Rebuild frontend job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/composer-install
     * Create job to run composer install
     */
    public function composerInstall(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'composer_install'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Composer install job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/run-migrations
     * Create job to run database migrations
     */
    public function runMigrations(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'run_migrations'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Run migrations job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/recompute-stats
     * Create job to recompute backup stats for all servers
     */
    public function recomputeStats(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'recompute_stats'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Recompute stats job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/maintenance/clear-cache
     * Create job to clear application caches
     */
    public function clearCache(): void
    {
        try {
            $jobId = $this->jobQueue->push('maintenance', [
                'action' => 'clear_cache'
            ], 'default', 1);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Clear cache job created'
            ], 'Job created', 202);
        } catch (\Exception $e) {
            $this->error('Failed to create job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/maintenance/status
     * Get maintenance status (versions, last rebuild times, etc.)
     * This is synchronous as it just reads info
     */
    public function status(): void
    {
        $status = [];

        // Agent version from source
        $mainGoPath = $this->phpborgRoot . '/agent/cmd/phpborg-agent/main.go';
        if (file_exists($mainGoPath)) {
            $content = file_get_contents($mainGoPath);
            if (preg_match('/const\s+Version\s*=\s*"([^"]+)"/', $content, $matches)) {
                $status['agent_source_version'] = $matches[1];
            }
        }

        // Agent binary info from releases
        $releasesDir = $this->phpborgRoot . '/releases/agent';
        $agentBinary = $releasesDir . '/phpborg-agent-linux-amd64';
        if (file_exists($agentBinary)) {
            $status['agent_binary_modified'] = date('Y-m-d H:i:s', filemtime($agentBinary));
            $status['agent_binary_size'] = filesize($agentBinary);

            // Get actual compiled version
            $versionFile = $releasesDir . '/VERSION';
            if (file_exists($versionFile)) {
                $status['agent_compiled_version'] = trim(file_get_contents($versionFile));
            }
        }

        // Frontend build info
        $frontendDist = $this->phpborgRoot . '/frontend/dist/index.html';
        if (file_exists($frontendDist)) {
            $status['frontend_built'] = date('Y-m-d H:i:s', filemtime($frontendDist));
        }

        // PHP version
        $status['php_version'] = PHP_VERSION;

        // Go version
        exec('go version 2>&1', $goOutput, $goExitCode);
        if ($goExitCode === 0) {
            $status['go_version'] = trim($goOutput[0] ?? 'unknown');
        }

        // Node version (via NVM with proper PATH)
        $nvmCmd = '/bin/bash -c "export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && export NVM_DIR=/var/lib/phpborg/.nvm && source \$NVM_DIR/nvm.sh 2>/dev/null && node --version" 2>&1';
        exec($nvmCmd, $nodeOutput, $nodeExitCode);
        if ($nodeExitCode === 0 && !empty($nodeOutput)) {
            // Filter out any warning lines, get just the version
            foreach ($nodeOutput as $line) {
                if (preg_match('/^v[\d.]+/', $line)) {
                    $status['node_version'] = trim($line);
                    break;
                }
            }
        }

        // Disk space
        $status['disk_free_gb'] = round(disk_free_space($this->phpborgRoot) / (1024 * 1024 * 1024), 2);

        // Worker status
        $workersRunning = 0;
        for ($i = 1; $i <= 4; $i++) {
            exec("systemctl is-active phpborg-worker@{$i} 2>&1", $output, $exitCode);
            if ($exitCode === 0) {
                $workersRunning++;
            }
        }
        $status['workers_running'] = $workersRunning;
        $status['workers_total'] = 4;

        $this->success($status);
    }
}
