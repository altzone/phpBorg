<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Server\ServerStatsComputer;

/**
 * Handler for maintenance tasks
 *
 * Supported actions:
 * - restart_workers: Restart all phpBorg workers
 * - rebuild_agent: Rebuild Go agent binaries
 * - rebuild_frontend: Rebuild Vue.js frontend
 * - composer_install: Run composer install
 * - run_migrations: Run database migrations
 * - recompute_stats: Recompute backup stats for all servers
 * - clear_cache: Clear OPcache and app cache
 */
final class MaintenanceHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly Configuration $config,
        private readonly LoggerInterface $logger,
        private readonly ServerStatsComputer $statsComputer
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $action = $payload['action'] ?? null;

        if (!$action) {
            throw new \Exception("Missing 'action' in maintenance job payload");
        }

        $this->logger->info("Starting maintenance action: {$action}", 'MAINTENANCE');

        $phpborgRoot = $this->getPhpBorgRoot();

        return match ($action) {
            'restart_workers' => $this->restartWorkers($queue, $job),
            'rebuild_agent' => $this->rebuildAgent($phpborgRoot, $queue, $job),
            'rebuild_frontend' => $this->rebuildFrontend($phpborgRoot, $queue, $job),
            'composer_install' => $this->composerInstall($phpborgRoot, $queue, $job),
            'run_migrations' => $this->runMigrations($phpborgRoot, $queue, $job),
            'recompute_stats' => $this->recomputeStats($queue, $job),
            'clear_cache' => $this->clearCache($phpborgRoot, $queue, $job),
            default => throw new \Exception("Unknown maintenance action: {$action}")
        };
    }

    /**
     * Restart all workers via systemd
     */
    private function restartWorkers(JobQueue $queue, Job $job): string
    {
        $queue->updateProgress($job->id, 10, "Arrêt des workers...");
        $this->logger->info("Stopping workers...", 'MAINTENANCE');

        // Stop workers 1-4
        for ($i = 1; $i <= 4; $i++) {
            exec("sudo systemctl stop phpborg-worker@{$i} 2>&1", $output, $exitCode);
        }

        $queue->updateProgress($job->id, 50, "Démarrage des workers...");
        $this->logger->info("Starting workers...", 'MAINTENANCE');

        // Start workers 1-4
        for ($i = 1; $i <= 4; $i++) {
            exec("sudo systemctl start phpborg-worker@{$i} 2>&1", $output, $exitCode);
        }

        $queue->updateProgress($job->id, 90, "Vérification des workers...");

        // Check status
        $running = 0;
        for ($i = 1; $i <= 4; $i++) {
            exec("systemctl is-active phpborg-worker@{$i} 2>&1", $output, $exitCode);
            if ($exitCode === 0) {
                $running++;
            }
        }

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Workers restarted", 'MAINTENANCE', ['running' => $running]);

        return "Workers redémarrés: {$running}/4 actifs";
    }

    /**
     * Rebuild Go agent binaries
     */
    private function rebuildAgent(string $phpborgRoot, JobQueue $queue, Job $job): string
    {
        $agentDir = "{$phpborgRoot}/agent";
        $releasesDir = "{$phpborgRoot}/releases/agent";

        // Check if agent directory exists
        if (!is_dir($agentDir)) {
            throw new \Exception("Agent directory not found: {$agentDir}");
        }

        // Check if Go is installed
        $output = [];
        exec("which go 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception("Go is not installed");
        }

        $queue->updateProgress($job->id, 10, "Compilation des agents...");
        $this->logger->info("Building agents...", 'MAINTENANCE');

        // Ensure releases directory exists
        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        // Build ALL agents using Makefile
        $output = [];
        $cmd = "cd {$agentDir} && GOCACHE=/tmp/go-cache GOMODCACHE=/tmp/go-mod make all 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error("Agent build failed", 'MAINTENANCE', [
                'output' => implode("\n", $output)
            ]);
            throw new \Exception("Agent build failed: " . implode("\n", array_slice($output, -5)));
        }

        $queue->updateProgress($job->id, 60, "Copie des binaires...");

        // Copy all built binaries to releases directory
        $binaries = [
            'phpborg-agent-linux-amd64',
            'phpborg-agent-linux-arm64',
            'phpborg-agent-windows-amd64.exe',
            'phpborg-installer.exe',
        ];

        $copiedCount = 0;
        foreach ($binaries as $binary) {
            $sourceBinary = "{$agentDir}/build/{$binary}";
            $destBinary = "{$releasesDir}/{$binary}";

            if (file_exists($sourceBinary)) {
                copy($sourceBinary, $destBinary);
                chmod($destBinary, 0755);

                // Generate checksum for agent binaries (not installer)
                if (str_starts_with($binary, 'phpborg-agent-')) {
                    $checksum = hash_file('sha256', $destBinary);
                    file_put_contents("{$destBinary}.sha256", "{$checksum}  {$binary}\n");
                }

                $copiedCount++;
                $this->logger->info("Built and copied: {$binary}", 'MAINTENANCE');
            }
        }

        $queue->updateProgress($job->id, 90, "Extraction de la version...");

        // Extract version from Linux binary
        $version = 'unknown';
        $linuxBinary = "{$releasesDir}/phpborg-agent-linux-amd64";
        if (file_exists($linuxBinary)) {
            $versionOutput = [];
            exec("{$linuxBinary} -version 2>&1", $versionOutput, $versionExitCode);
            if ($versionExitCode === 0 && !empty($versionOutput[0])) {
                if (preg_match('/version\s+(\S+)/', $versionOutput[0], $matches)) {
                    $version = $matches[1];
                    file_put_contents("{$releasesDir}/VERSION", $version);
                }
            }
        }

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Agent rebuilt successfully", 'MAINTENANCE', [
            'version' => $version,
            'binaries_copied' => $copiedCount
        ]);

        return "Agent v{$version} compilé ({$copiedCount} binaires)";
    }

    /**
     * Rebuild frontend
     */
    private function rebuildFrontend(string $phpborgRoot, JobQueue $queue, Job $job): string
    {
        $frontendDir = "{$phpborgRoot}/frontend";

        if (!is_dir($frontendDir)) {
            throw new \Exception("Frontend directory not found: {$frontendDir}");
        }

        // Source NVM and run npm commands with bash
        $nvmSource = 'export NVM_DIR=/var/lib/phpborg/.nvm && source $NVM_DIR/nvm.sh';

        $queue->updateProgress($job->id, 10, "Installation des dépendances npm...");
        $this->logger->info("Installing npm dependencies...", 'MAINTENANCE');

        // npm ci (clean install)
        $output = [];
        $cmd = "/bin/bash -c \"{$nvmSource} && cd {$frontendDir} && npm ci\" 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("npm ci failed, trying npm install", 'MAINTENANCE');
            $output = [];
            $cmd = "/bin/bash -c \"{$nvmSource} && cd {$frontendDir} && npm install\" 2>&1";
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("npm install failed: " . implode("\n", array_slice($output, -5)));
            }
        }

        $queue->updateProgress($job->id, 50, "Construction du frontend...");
        $this->logger->info("Building frontend...", 'MAINTENANCE');

        // npm run build
        $output = [];
        $cmd = "/bin/bash -c \"{$nvmSource} && cd {$frontendDir} && npm run build\" 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("npm build failed: " . implode("\n", array_slice($output, -5)));
        }

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Frontend rebuilt successfully", 'MAINTENANCE');

        return "Frontend reconstruit avec succès";
    }

    /**
     * Run composer install
     */
    private function composerInstall(string $phpborgRoot, JobQueue $queue, Job $job): string
    {
        $queue->updateProgress($job->id, 10, "Installation des dépendances PHP...");
        $this->logger->info("Running composer install...", 'MAINTENANCE');

        $output = [];
        $cmd = "cd {$phpborgRoot} && composer install --no-dev --optimize-autoloader --no-interaction 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Composer install failed: " . implode("\n", array_slice($output, -5)));
        }

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Composer install completed", 'MAINTENANCE');

        return "Dépendances PHP installées";
    }

    /**
     * Run database migrations
     */
    private function runMigrations(string $phpborgRoot, JobQueue $queue, Job $job): string
    {
        $migrationsDir = "{$phpborgRoot}/migrations";

        if (!is_dir($migrationsDir)) {
            return "Aucun dossier de migrations trouvé";
        }

        $migrationFiles = glob("{$migrationsDir}/*.sql");

        if (empty($migrationFiles)) {
            return "Aucune migration à exécuter";
        }

        sort($migrationFiles);
        $total = count($migrationFiles);

        $migrationScript = "{$phpborgRoot}/bin/run-migration.php";
        if (!file_exists($migrationScript)) {
            throw new \Exception("Migration script not found: {$migrationScript}");
        }

        $executed = 0;
        $skipped = 0;

        foreach ($migrationFiles as $index => $migrationFile) {
            $migrationName = basename($migrationFile);
            $progress = (int)(($index + 1) / $total * 90) + 10;
            $queue->updateProgress($job->id, $progress, "Migration: {$migrationName}");

            $output = [];
            exec("php {$migrationScript} {$migrationFile} 2>&1", $output, $exitCode);
            $outputStr = implode("\n", $output);

            if ($exitCode === 0) {
                $this->logger->info("Migration executed: {$migrationName}", 'MAINTENANCE');
                $executed++;
            } else {
                // Check if already applied
                if (strpos($outputStr, 'already applied') !== false
                    || strpos($outputStr, 'already exists') !== false
                    || strpos($outputStr, 'Duplicate column') !== false
                    || strpos($outputStr, 'Duplicate key') !== false
                    || strpos($outputStr, 'Duplicate entry') !== false) {
                    $this->logger->info("Migration already applied: {$migrationName}", 'MAINTENANCE');
                    $skipped++;
                } else {
                    $this->logger->warning("Migration failed: {$migrationName}", 'MAINTENANCE', [
                        'output' => $outputStr
                    ]);
                }
            }
        }

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Migrations completed", 'MAINTENANCE', [
            'executed' => $executed,
            'skipped' => $skipped
        ]);

        return "Migrations: {$executed} exécutées, {$skipped} déjà appliquées";
    }

    /**
     * Recompute backup stats for all servers
     */
    private function recomputeStats(JobQueue $queue, Job $job): string
    {
        $queue->updateProgress($job->id, 10, "Recalcul des statistiques...");
        $this->logger->info("Recomputing all server stats...", 'MAINTENANCE');

        $count = $this->statsComputer->recomputeAllStats();

        $queue->updateProgress($job->id, 100, "Terminé");
        $this->logger->info("Stats recomputed", 'MAINTENANCE', ['servers' => $count]);

        return "Statistiques recalculées pour {$count} serveurs";
    }

    /**
     * Clear OPcache and app cache
     */
    private function clearCache(string $phpborgRoot, JobQueue $queue, Job $job): string
    {
        $queue->updateProgress($job->id, 20, "Nettoyage du cache OPcache...");
        $this->logger->info("Clearing caches...", 'MAINTENANCE');

        $cleared = [];

        // Clear OPcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cleared[] = 'OPcache';
            $this->logger->info("OPcache cleared", 'MAINTENANCE');
        }

        $queue->updateProgress($job->id, 50, "Nettoyage du cache applicatif...");

        // Clear app cache directory if exists
        $cacheDir = "{$phpborgRoot}/var/cache";
        if (is_dir($cacheDir)) {
            $this->deleteDirectory($cacheDir);
            mkdir($cacheDir, 0755, true);
            $cleared[] = 'App cache';
            $this->logger->info("App cache cleared", 'MAINTENANCE');
        }

        // Clear tmp files older than 24h
        $queue->updateProgress($job->id, 80, "Nettoyage des fichiers temporaires...");
        $tmpDir = "{$phpborgRoot}/var/tmp";
        if (is_dir($tmpDir)) {
            $this->cleanOldFiles($tmpDir, 86400); // 24 hours
            $cleared[] = 'Temp files';
        }

        $queue->updateProgress($job->id, 100, "Terminé");

        $result = empty($cleared) ? "Aucun cache à nettoyer" : "Cache nettoyé: " . implode(', ', $cleared);
        $this->logger->info($result, 'MAINTENANCE');

        return $result;
    }

    /**
     * Get phpBorg root directory
     */
    private function getPhpBorgRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Clean files older than specified age
     */
    private function cleanOldFiles(string $dir, int $maxAge): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $now = time();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile() && ($now - $file->getMTime()) > $maxAge) {
                unlink($file->getPathname());
            }
        }
    }
}
