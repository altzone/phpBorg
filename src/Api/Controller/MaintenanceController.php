<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Service\Server\ServerStatsComputer;

/**
 * Maintenance Controller
 * Provides endpoints for manual maintenance operations
 */
class MaintenanceController extends BaseController
{
    private Application $app;
    private string $phpborgRoot;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->phpborgRoot = dirname(__DIR__, 3);
    }

    /**
     * POST /api/maintenance/restart-workers
     * Restart all phpBorg workers
     */
    public function restartWorkers(): void
    {
        $results = [];
        $services = [
            'phpborg-scheduler',
            'phpborg-worker@1',
            'phpborg-worker@2',
            'phpborg-worker@3',
            'phpborg-worker@4',
            'phpborg-agent-worker'
        ];

        foreach ($services as $service) {
            $output = [];
            $exitCode = 0;
            exec("sudo systemctl restart {$service} 2>&1", $output, $exitCode);
            $results[$service] = [
                'success' => $exitCode === 0,
                'output' => implode("\n", $output)
            ];
        }

        $allSuccess = !in_array(false, array_column($results, 'success'));

        $this->success([
            'message' => $allSuccess ? 'All workers restarted successfully' : 'Some workers failed to restart',
            'results' => $results
        ]);
    }

    /**
     * POST /api/maintenance/rebuild-agent
     * Rebuild the Go agent binary
     */
    public function rebuildAgent(): void
    {
        $agentDir = $this->phpborgRoot . '/agent';

        if (!is_dir($agentDir)) {
            $this->error('Agent directory not found', 404);
            return;
        }

        // Check Go installation
        exec("which go 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            $this->error('Go is not installed', 500);
            return;
        }

        // Build agent using Makefile
        $output = [];
        $cmd = "cd {$agentDir} && GOCACHE=/tmp/go-cache GOMODCACHE=/tmp/go-mod make all 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('Agent build failed: ' . implode("\n", $output), 500);
            return;
        }

        // Get new version
        $version = null;
        $mainGoPath = $agentDir . '/cmd/phpborg-agent/main.go';
        if (file_exists($mainGoPath)) {
            $content = file_get_contents($mainGoPath);
            if (preg_match('/const\s+Version\s*=\s*"([^"]+)"/', $content, $matches)) {
                $version = $matches[1];
            }
        }

        $this->success([
            'message' => 'Agent rebuilt successfully',
            'version' => $version,
            'output' => implode("\n", array_slice($output, -10))
        ]);
    }

    /**
     * POST /api/maintenance/rebuild-frontend
     * Rebuild the Vue.js frontend
     */
    public function rebuildFrontend(): void
    {
        $frontendDir = $this->phpborgRoot . '/frontend';

        if (!is_dir($frontendDir)) {
            $this->error('Frontend directory not found', 404);
            return;
        }

        // Run npm build
        $output = [];
        $cmd = "cd {$frontendDir} && npm run build 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('Frontend build failed: ' . implode("\n", $output), 500);
            return;
        }

        $this->success([
            'message' => 'Frontend rebuilt successfully',
            'output' => implode("\n", array_slice($output, -15))
        ]);
    }

    /**
     * POST /api/maintenance/composer-install
     * Run composer install
     */
    public function composerInstall(): void
    {
        $output = [];
        $cmd = "cd {$this->phpborgRoot} && composer install --no-dev --optimize-autoloader 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('Composer install failed: ' . implode("\n", $output), 500);
            return;
        }

        $this->success([
            'message' => 'Composer dependencies installed successfully',
            'output' => implode("\n", array_slice($output, -10))
        ]);
    }

    /**
     * POST /api/maintenance/run-migrations
     * Run database migrations
     */
    public function runMigrations(): void
    {
        $migrationsDir = $this->phpborgRoot . '/migrations';

        if (!is_dir($migrationsDir)) {
            $this->error('Migrations directory not found', 404);
            return;
        }

        $connection = $this->app->getConnection();
        $results = [];
        $migrationFiles = glob($migrationsDir . '/*.sql');
        sort($migrationFiles);

        foreach ($migrationFiles as $file) {
            $filename = basename($file);

            // Skip .gitkeep
            if ($filename === '.gitkeep') continue;

            try {
                // Check if migration was already applied (simple check based on table existence or tracking)
                $sql = file_get_contents($file);

                // Execute migration
                $connection->executeMultiple($sql);
                $results[$filename] = ['success' => true, 'message' => 'Applied'];
            } catch (\Exception $e) {
                // Check if it's "already exists" error
                if (strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false) {
                    $results[$filename] = ['success' => true, 'message' => 'Already applied'];
                } else {
                    $results[$filename] = ['success' => false, 'message' => $e->getMessage()];
                }
            }
        }

        $this->success([
            'message' => 'Migrations processed',
            'results' => $results
        ]);
    }

    /**
     * POST /api/maintenance/recompute-stats
     * Recompute backup stats for all servers
     */
    public function recomputeStats(): void
    {
        $statsComputer = $this->app->getServerStatsComputer();
        $count = $statsComputer->recomputeAll();

        $this->success([
            'message' => "Stats recomputed for {$count} servers",
            'servers_updated' => $count
        ]);
    }

    /**
     * POST /api/maintenance/clear-cache
     * Clear application caches
     */
    public function clearCache(): void
    {
        $cleared = [];

        // Clear OPcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cleared[] = 'opcache';
        }

        // Clear var/cache directory if exists
        $cacheDir = $this->phpborgRoot . '/var/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $cleared[] = 'var/cache';
        }

        $this->success([
            'message' => 'Cache cleared successfully',
            'cleared' => $cleared
        ]);
    }

    /**
     * GET /api/maintenance/status
     * Get maintenance status (versions, last rebuild times, etc.)
     */
    public function status(): void
    {
        $status = [];

        // Agent version
        $mainGoPath = $this->phpborgRoot . '/agent/cmd/phpborg-agent/main.go';
        if (file_exists($mainGoPath)) {
            $content = file_get_contents($mainGoPath);
            if (preg_match('/const\s+Version\s*=\s*"([^"]+)"/', $content, $matches)) {
                $status['agent_source_version'] = $matches[1];
            }
        }

        // Agent binary info
        $agentBinary = $this->phpborgRoot . '/agent/phpborg-agent';
        if (file_exists($agentBinary)) {
            $status['agent_binary_modified'] = date('Y-m-d H:i:s', filemtime($agentBinary));
            $status['agent_binary_size'] = filesize($agentBinary);
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

        // Node version
        exec('node --version 2>&1', $nodeOutput, $nodeExitCode);
        if ($nodeExitCode === 0) {
            $status['node_version'] = trim($nodeOutput[0] ?? 'unknown');
        }

        // Disk space
        $status['disk_free_gb'] = round(disk_free_space($this->phpborgRoot) / (1024 * 1024 * 1024), 2);

        $this->success($status);
    }
}
