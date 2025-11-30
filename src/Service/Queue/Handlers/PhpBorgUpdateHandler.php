<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Config\Configuration;
use PhpBorg\Entity\Job;
use PhpBorg\Exception\BackupException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\PhpBorgBackupRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for phpBorg self-update
 *
 * Process:
 * 1. Pre-checks (git clean, disk space, dependencies)
 * 2. Create pre-update backup (MANDATORY)
 * 3. Git pull (update code)
 * 4. Composer install
 * 5. NPM build frontend
 * 5b. Rebuild Go agent (if Go available)
 * 6. Run database migrations
 * 7. Regenerate systemd services
 * 8. Health check (auto-rollback on failure)
 * 9. Request services restart (async - job completes before restart)
 */
final class PhpBorgUpdateHandler implements JobHandlerInterface
{
    private const MIN_DISK_SPACE_GB = 2;

    public function __construct(
        private readonly Configuration $config,
        private readonly PhpBorgBackupRepository $backupRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $targetCommit = $payload['target_commit'] ?? null;

        $this->logger->info("Starting phpBorg update", 'PHPBORG_UPDATE', [
            'target_commit' => $targetCommit
        ]);

        $phpborgRoot = $this->getPhpBorgRoot();
        $preUpdateBackupId = null;
        $currentCommit = $this->getCurrentCommit($phpborgRoot);
        $codeUpdated = false; // Track if git pull succeeded (only rollback if true)

        try {
            // Step 1: Pre-checks (5%)
            $queue->updateProgress($job->id, 5, "update.steps.pre_checks");
            $this->logger->info("Running pre-update checks...", 'PHPBORG_UPDATE');
            $this->preChecks($phpborgRoot);

            // Step 2: Create pre-update backup (15%)
            $queue->updateProgress($job->id, 10, "update.steps.backup_creating");
            $this->logger->info("Creating pre-update backup...", 'PHPBORG_UPDATE');
            $preUpdateBackupId = $this->createPreUpdateBackup();
            $queue->updateProgress($job->id, 15, "update.steps.backup_created|{$preUpdateBackupId}");
            $this->logger->info("Pre-update backup created", 'PHPBORG_UPDATE', [
                'backup_id' => $preUpdateBackupId
            ]);

            // Step 3: Git update (25%)
            $queue->updateProgress($job->id, 20, "update.steps.git_updating");
            $this->logger->info("Updating code from git...", 'PHPBORG_UPDATE');
            $this->gitUpdate($phpborgRoot, $targetCommit);
            $newCommit = $this->getCurrentCommit($phpborgRoot);
            $codeUpdated = ($newCommit !== $currentCommit); // Only true if commit actually changed
            $fromShort = substr($currentCommit, 0, 7);
            $toShort = substr($newCommit, 0, 7);
            $queue->updateProgress($job->id, 25, "update.steps.code_updated|{$fromShort}|{$toShort}");
            $this->logger->info("Code updated", 'PHPBORG_UPDATE', [
                'from' => $fromShort,
                'to' => $toShort
            ]);

            // If no changes, exit early with success
            if (!$codeUpdated) {
                $queue->updateProgress($job->id, 100, "update.steps.already_uptodate");
                $this->logger->info("Already up to date, nothing to do", 'PHPBORG_UPDATE');
                return "phpBorg already at latest version ({$newCommit})";
            }

            // Analyze what changed for smart update
            $changedFiles = $this->getChangedFiles($phpborgRoot, $currentCommit, $newCommit);
            $needsComposer = $this->needsComposerInstall($changedFiles);
            $needsFrontend = $this->needsFrontendBuild($changedFiles);
            $needsAgent = $this->needsAgentBuild($changedFiles);
            $needsMigrations = $this->needsMigrations($changedFiles);
            $needsSystemd = $this->needsSystemdRegeneration($changedFiles);
            $needsDocker = $this->needsDockerBuild($changedFiles);

            $this->logger->info("Smart update analysis", 'PHPBORG_UPDATE', [
                'files_changed' => count($changedFiles),
                'needs_composer' => $needsComposer,
                'needs_frontend' => $needsFrontend,
                'needs_agent' => $needsAgent,
                'needs_migrations' => $needsMigrations,
                'needs_systemd' => $needsSystemd,
                'needs_docker' => $needsDocker,
            ]);

            // Step 4: Composer install (40%) - only if needed
            if ($needsComposer) {
                $queue->updateProgress($job->id, 30, "update.steps.composer_installing");
                $this->logger->info("Installing PHP dependencies...", 'PHPBORG_UPDATE');
                $this->composerInstall($phpborgRoot);
                $queue->updateProgress($job->id, 40, "update.steps.composer_installed");
            } else {
                $queue->updateProgress($job->id, 40, "update.steps.composer_skipped");
                $this->logger->info("Skipping Composer install (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 5: NPM build (60%) - only if frontend changed
            if ($needsFrontend) {
                $queue->updateProgress($job->id, 45, "update.steps.frontend_building");
                $this->logger->info("Building frontend...", 'PHPBORG_UPDATE');
                $this->npmBuild($phpborgRoot);
                $queue->updateProgress($job->id, 60, "update.steps.frontend_built");
            } else {
                $queue->updateProgress($job->id, 60, "update.steps.frontend_skipped");
                $this->logger->info("Skipping frontend build (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 5b: Rebuild Go agent (70%) - only if agent changed
            if ($needsAgent) {
                $queue->updateProgress($job->id, 65, "update.steps.agent_building");
                $this->logger->info("Rebuilding Go agent...", 'PHPBORG_UPDATE');
                $this->rebuildAgent($phpborgRoot);
                $queue->updateProgress($job->id, 70, "update.steps.agent_built");
            } else {
                $queue->updateProgress($job->id, 70, "update.steps.agent_skipped");
                $this->logger->info("Skipping agent rebuild (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 6: Database migrations (80%) - only if migrations changed
            if ($needsMigrations) {
                $queue->updateProgress($job->id, 75, "update.steps.migrations_running");
                $this->logger->info("Running database migrations...", 'PHPBORG_UPDATE');
                $this->runMigrations($phpborgRoot);
                $queue->updateProgress($job->id, 80, "update.steps.migrations_done");
            } else {
                $queue->updateProgress($job->id, 80, "update.steps.migrations_skipped");
                $this->logger->info("Skipping migrations (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 7: Regenerate systemd services (85%) - only if systemd files changed
            if ($needsSystemd) {
                $queue->updateProgress($job->id, 82, "update.steps.systemd_regenerating");
                $this->logger->info("Regenerating systemd services...", 'PHPBORG_UPDATE');
                $this->regenerateSystemdServices($phpborgRoot);
                $queue->updateProgress($job->id, 84, "update.steps.systemd_regenerated");
            } else {
                $queue->updateProgress($job->id, 84, "update.steps.systemd_skipped");
                $this->logger->info("Skipping systemd regeneration (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 7b: Rebuild Docker images (87%) - only if docker files changed
            if ($needsDocker) {
                $queue->updateProgress($job->id, 85, "update.steps.docker_building");
                $this->logger->info("Rebuilding Docker images...", 'PHPBORG_UPDATE');
                $this->rebuildDockerImages($phpborgRoot);
                $queue->updateProgress($job->id, 87, "update.steps.docker_built");
            } else {
                $queue->updateProgress($job->id, 87, "update.steps.docker_skipped");
                $this->logger->info("Skipping Docker rebuild (no changes)", 'PHPBORG_UPDATE');
            }

            // Step 8: Pre-restart health check (90%)
            $queue->updateProgress($job->id, 88, "update.steps.health_checking");
            $this->logger->info("Running pre-restart health check...", 'PHPBORG_UPDATE');
            $healthCheck = $this->healthCheck();

            if (!$healthCheck['healthy']) {
                throw new \Exception("Health check failed: " . $healthCheck['error']);
            }
            $queue->updateProgress($job->id, 90, "update.steps.health_passed");

            // Build summary of what was done
            $actions = [];
            if ($needsComposer) $actions[] = 'composer';
            if ($needsFrontend) $actions[] = 'frontend';
            if ($needsAgent) $actions[] = 'agent';
            if ($needsMigrations) $actions[] = 'migrations';
            if ($needsSystemd) $actions[] = 'systemd';
            if ($needsDocker) $actions[] = 'docker';

            $message = sprintf(
                "phpBorg updated %s → %s (%s)",
                substr($currentCommit, 0, 7),
                substr($newCommit, 0, 7),
                empty($actions) ? 'code only' : implode(', ', $actions)
            );

            $this->logger->info($message, 'PHPBORG_UPDATE');

            // Step 9: Request services restart (95%)
            $queue->updateProgress($job->id, 95, "update.steps.restarting");
            $this->logger->info("Requesting services restart...", 'PHPBORG_UPDATE');
            $this->restartServices($phpborgRoot);
            $queue->updateProgress($job->id, 100, "update.steps.update_done");

            return $message;

        } catch (\Exception $e) {
            $this->logger->error("Update failed", 'PHPBORG_UPDATE', [
                'error' => $e->getMessage(),
                'code_updated' => $codeUpdated
            ]);

            // Auto-rollback ONLY if code was updated (git pull succeeded)
            // If git pull failed, nothing changed - no rollback needed
            if ($preUpdateBackupId && $codeUpdated) {
                $this->logger->info("Code was modified, attempting rollback...", 'PHPBORG_UPDATE');
                try {
                    $this->logger->info("Rolling back to pre-update backup", 'PHPBORG_UPDATE', [
                        'backup_id' => $preUpdateBackupId
                    ]);

                    $this->rollback($preUpdateBackupId);

                    throw new \Exception(
                        "Update failed and rolled back to previous version. Error: " . $e->getMessage()
                    );
                } catch (\Exception $rollbackError) {
                    throw new \Exception(
                        "Update failed AND rollback failed! Manual intervention required. " .
                        "Update error: " . $e->getMessage() . " | " .
                        "Rollback error: " . $rollbackError->getMessage()
                    );
                }
            }

            throw $e;
        }
    }

    /**
     * Pre-update checks
     */
    private function preChecks(string $phpborgRoot): void
    {
        // Check git repo is clean
        exec("cd {$phpborgRoot} && git status --porcelain 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Git status check failed");
        }

        if (!empty($output) && count($output) > 0) {
            $this->logger->warning("Git working directory has uncommitted changes", 'PHPBORG_UPDATE', [
                'changes' => implode("\n", $output)
            ]);
            // Don't block update, just warn
        }

        // Check disk space
        $diskFree = disk_free_space($phpborgRoot);
        $diskFreeGB = $diskFree / 1024 / 1024 / 1024;

        if ($diskFreeGB < self::MIN_DISK_SPACE_GB) {
            throw new \Exception(
                sprintf("Insufficient disk space: %.2f GB free (minimum: %d GB)", $diskFreeGB, self::MIN_DISK_SPACE_GB)
            );
        }

        // Check required binaries
        $required = ['git', 'composer', 'mysql'];
        foreach ($required as $bin) {
            exec("which {$bin} 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("Required binary not found: {$bin}");
            }
        }

        // Check npm (sourcing NVM first with bash)
        $nvmCheck = '/bin/bash -c "export NVM_DIR=/var/lib/phpborg/.nvm && source \$NVM_DIR/nvm.sh && which npm" 2>&1';
        $this->logger->debug("Running npm check: {$nvmCheck}", 'PHPBORG_UPDATE');
        exec($nvmCheck, $output, $exitCode);
        $this->logger->debug("npm check result", 'PHPBORG_UPDATE', [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output)
        ]);
        if ($exitCode !== 0) {
            throw new \Exception("Required binary not found: npm (NVM might not be loaded). Command: {$nvmCheck}");
        }

        $this->logger->info("Pre-checks passed", 'PHPBORG_UPDATE', [
            'disk_free_gb' => round($diskFreeGB, 2)
        ]);
    }

    /**
     * Create pre-update backup
     */
    private function createPreUpdateBackup(): int
    {
        // Trigger phpborg_backup_create job with type "pre_update"
        $handler = new PhpBorgBackupCreateHandler(
            $this->config,
            new \PhpBorg\Database\Connection(
                $this->config->dbHost,
                $this->config->dbPort,
                $this->config->dbName,
                $this->config->dbUser,
                $this->config->dbPassword
            ),
            $this->backupRepository,
            new \PhpBorg\Repository\SettingRepository(
                new \PhpBorg\Database\Connection(
                    $this->config->dbHost,
                    $this->config->dbPort,
                    $this->config->dbName,
                    $this->config->dbUser,
                    $this->config->dbPassword
                )
            ),
            $this->logger
        );

        $job = new Job(
            id: 0,
            queue: 'default',
            type: 'phpborg_backup_create',
            payload: [
                'backup_type' => 'pre_update',
                'notes' => 'Automatic backup before update'
            ],
            status: 'processing',
            workerId: null,
            progress: 0,
            attempts: 1,
            maxAttempts: 1,
            output: null,
            error: null,
            startedAt: new \DateTime(),
            completedAt: null,
            createdAt: new \DateTime(),
            createdBy: null
        );

        $handler->handle($job, new JobQueue(
            $this->config,
            new \PhpBorg\Repository\JobRepository(
                new \PhpBorg\Database\Connection(
                    $this->config->dbHost,
                    $this->config->dbPort,
                    $this->config->dbName,
                    $this->config->dbUser,
                    $this->config->dbPassword
                )
            ),
            $this->logger
        ));

        // Get last created pre_update backup
        $latestBackup = $this->backupRepository->findLatestPreUpdate();
        if (!$latestBackup) {
            throw new BackupException("Failed to create pre-update backup");
        }

        return $latestBackup->id;
    }

    /**
     * Update code via git pull
     */
    private function gitUpdate(string $phpborgRoot, ?string $targetCommit): void
    {
        // Fetch latest
        exec("cd {$phpborgRoot} && git fetch origin 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception("Git fetch failed: " . implode("\n", $output));
        }

        // Checkout target or pull master
        if ($targetCommit) {
            exec("cd {$phpborgRoot} && git checkout {$targetCommit} 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("Git checkout failed: " . implode("\n", $output));
            }
        } else {
            exec("cd {$phpborgRoot} && git pull origin master 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("Git pull failed: " . implode("\n", $output));
            }
        }

        $this->logger->info("Git update completed", 'PHPBORG_UPDATE');
    }

    /**
     * Install PHP dependencies
     */
    private function composerInstall(string $phpborgRoot): void
    {
        $cmd = "cd {$phpborgRoot} && composer install --no-dev --optimize-autoloader --no-interaction 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Composer install failed: " . implode("\n", $output));
        }

        $this->logger->info("Composer dependencies installed", 'PHPBORG_UPDATE');
    }

    /**
     * Build frontend
     */
    private function npmBuild(string $phpborgRoot): void
    {
        // Source NVM and run npm commands with bash
        $nvmSource = 'export NVM_DIR=/var/lib/phpborg/.nvm && source \$NVM_DIR/nvm.sh';

        // npm ci (clean install)
        $cmd = "/bin/bash -c \"{$nvmSource} && cd {$phpborgRoot}/frontend && npm ci\" 2>&1";
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $this->logger->warning("npm ci failed, trying npm install", 'PHPBORG_UPDATE');
            $cmd = "/bin/bash -c \"{$nvmSource} && cd {$phpborgRoot}/frontend && npm install\" 2>&1";
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("npm install failed: " . implode("\n", $output));
            }
        }

        // npm run build
        $cmd = "/bin/bash -c \"{$nvmSource} && cd {$phpborgRoot}/frontend && npm run build\" 2>&1";
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception("npm build failed: " . implode("\n", $output));
        }

        $this->logger->info("Frontend built successfully", 'PHPBORG_UPDATE');
    }

    /**
     * Rebuild Go agent binaries (all platforms)
     */
    private function rebuildAgent(string $phpborgRoot): void
    {
        $agentDir = "{$phpborgRoot}/agent";
        $releasesDir = "{$phpborgRoot}/releases/agent";

        // Check if agent directory exists
        if (!is_dir($agentDir)) {
            $this->logger->info("No agent directory found, skipping agent rebuild", 'PHPBORG_UPDATE');
            return;
        }

        // Check if Go is installed
        $output = [];
        exec("which go 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            $this->logger->warning("Go not installed, skipping agent rebuild", 'PHPBORG_UPDATE');
            return;
        }

        // Ensure releases directory exists
        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        // Build ALL agents using Makefile (Linux amd64/arm64, Windows, Installer)
        $output = [];
        $cmd = "cd {$agentDir} && GOCACHE=/tmp/go-cache GOMODCACHE=/tmp/go-mod make all 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("Agent rebuild with 'make all' failed", 'PHPBORG_UPDATE', [
                'output' => implode("\n", $output)
            ]);
            // Don't fail update - agent rebuild is optional
            return;
        }

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
                if (!copy($sourceBinary, $destBinary)) {
                    $this->logger->error("Failed to copy {$binary} to releases directory", 'PHPBORG_UPDATE', [
                        'source' => $sourceBinary,
                        'dest' => $destBinary,
                        'releases_dir_writable' => is_writable($releasesDir),
                        'releases_dir_owner' => posix_getpwuid(fileowner($releasesDir))['name'] ?? 'unknown',
                    ]);
                    continue;
                }
                chmod($destBinary, 0755);

                // Generate checksum for agent binaries (not installer)
                if (str_starts_with($binary, 'phpborg-agent-')) {
                    $checksum = hash_file('sha256', $destBinary);
                    file_put_contents("{$destBinary}.sha256", "{$checksum}  {$binary}\n");
                }

                $copiedCount++;
                $this->logger->info("Built and copied: {$binary}", 'PHPBORG_UPDATE');
            }
        }

        if ($copiedCount > 0) {
            // Extract version from Linux binary
            $linuxBinary = "{$releasesDir}/phpborg-agent-linux-amd64";
            if (file_exists($linuxBinary)) {
                $versionOutput = [];
                exec("{$linuxBinary} -version 2>&1", $versionOutput, $versionExitCode);
                if ($versionExitCode === 0 && !empty($versionOutput[0])) {
                    if (preg_match('/version\s+(\S+)/', $versionOutput[0], $matches)) {
                        file_put_contents("{$releasesDir}/VERSION", $matches[1]);
                        $this->logger->info("Agent version: {$matches[1]}", 'PHPBORG_UPDATE');
                    }
                }
            }

            $this->logger->info("All agents rebuilt successfully", 'PHPBORG_UPDATE', [
                'binaries_copied' => $copiedCount
            ]);
        } else {
            $this->logger->warning("No agent binaries found after build", 'PHPBORG_UPDATE');
        }
    }

    /**
     * Run database migrations
     */
    private function runMigrations(string $phpborgRoot): void
    {
        // Check if migrations directory exists
        $migrationsDir = "{$phpborgRoot}/migrations";
        if (!is_dir($migrationsDir)) {
            $this->logger->info("No migrations directory found, skipping migrations", 'PHPBORG_UPDATE');
            return;
        }

        // Get all SQL migration files
        $migrationFiles = glob("{$migrationsDir}/*.sql");

        if (empty($migrationFiles)) {
            $this->logger->info("No migration files found, skipping migrations", 'PHPBORG_UPDATE');
            return;
        }

        sort($migrationFiles); // Execute in order

        $migrationScript = "{$phpborgRoot}/bin/run-migration.php";
        if (!file_exists($migrationScript)) {
            $this->logger->info("No migration script found, skipping migrations", 'PHPBORG_UPDATE');
            return;
        }

        $executed = 0;
        $skipped = 0;

        foreach ($migrationFiles as $migrationFile) {
            $migrationName = basename($migrationFile);

            exec("php {$migrationScript} {$migrationFile} 2>&1", $output, $exitCode);

            if ($exitCode === 0) {
                $this->logger->info("Migration executed successfully: {$migrationName}", 'PHPBORG_UPDATE');
                $executed++;
            } else {
                $outputStr = implode("\n", $output);
                // Already applied is not an error (check various MySQL/MariaDB messages)
                if (strpos($outputStr, 'already applied') !== false
                    || strpos($outputStr, 'already exists') !== false
                    || strpos($outputStr, 'Duplicate column') !== false
                    || strpos($outputStr, 'Duplicate key') !== false
                    || strpos($outputStr, 'Duplicate entry') !== false) {
                    $this->logger->info("Migration already applied: {$migrationName}", 'PHPBORG_UPDATE');
                    $skipped++;
                } else {
                    $this->logger->warning("Migration failed: {$migrationName}", 'PHPBORG_UPDATE', [
                        'output' => $outputStr
                    ]);
                }
            }
        }

        $this->logger->info("Database migrations completed", 'PHPBORG_UPDATE', [
            'executed' => $executed,
            'skipped' => $skipped
        ]);
    }

    /**
     * Regenerate systemd services
     */
    private function regenerateSystemdServices(string $phpborgRoot): void
    {
        $script = "{$phpborgRoot}/bin/regenerate-systemd-services.sh";

        if (!file_exists($script)) {
            $this->logger->info("Systemd regeneration script not found, skipping", 'PHPBORG_UPDATE');
            return;
        }

        exec("sudo {$script} 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            // NoNewPrivileges is expected in systemd context, not a real error
            if (strpos($outputStr, 'no new privileges') !== false) {
                $this->logger->info("Systemd regeneration skipped (NoNewPrivileges context)", 'PHPBORG_UPDATE');
            } else {
                $this->logger->warning("Systemd services regeneration failed", 'PHPBORG_UPDATE', [
                    'output' => $outputStr
                ]);
            }
        } else {
            $this->logger->info("Systemd services regenerated successfully", 'PHPBORG_UPDATE');
        }
    }

    /**
     * Request services restart via systemd path unit
     * Creates a flag file that triggers phpborg-restart.service
     *
     * NOTE: This is async - we don't wait because the restart will kill this process.
     * The job should complete before calling this method.
     */
    private function restartServices(string $phpborgRoot): void
    {
        // Use phpborg var directory instead of /tmp because systemd PrivateTmp=true
        // isolates /tmp per service, so scheduler wouldn't see the flag
        $flagFile = $phpborgRoot . '/var/restart-needed';

        // Create restart flag with metadata
        $metadata = [
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_by' => 'phpborg_update',
        ];

        if (file_put_contents($flagFile, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
            $this->logger->error("Failed to create restart flag file", 'PHPBORG_UPDATE');
            throw new \Exception("Failed to request services restart");
        }

        $this->logger->info("Services restart requested via systemd path unit", 'PHPBORG_UPDATE');

        // Don't wait - the restart will kill this process
        // The phpborg-restart.service will handle the restart and cleanup
    }

    /**
     * Health check
     *
     * @return array{healthy: bool, error: ?string}
     */
    private function healthCheck(): array
    {
        $errors = [];

        // Check workers
        foreach ([1, 2, 3, 4] as $workerNum) {
            exec("systemctl is-active phpborg-worker@{$workerNum} 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                $errors[] = "Worker #{$workerNum} not running";
            }
        }

        // Check scheduler
        exec("systemctl is-active phpborg-scheduler 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            $errors[] = "Scheduler not running";
        }

        // Check database connection
        try {
            $conn = new \PhpBorg\Database\Connection(
                $this->config->dbHost,
                $this->config->dbPort,
                $this->config->dbName,
                $this->config->dbUser,
                $this->config->dbPassword
            );
            $conn->execute("SELECT 1");
        } catch (\Exception $e) {
            $errors[] = "Database connection failed: " . $e->getMessage();
        }

        if (!empty($errors)) {
            return [
                'healthy' => false,
                'error' => implode(", ", $errors)
            ];
        }

        return [
            'healthy' => true,
            'error' => null
        ];
    }

    /**
     * Rollback to backup
     */
    private function rollback(int $backupId): void
    {
        $this->logger->info("Starting rollback", 'PHPBORG_UPDATE', [
            'backup_id' => $backupId
        ]);

        // Use PhpBorgBackupRestoreHandler
        $handler = new PhpBorgBackupRestoreHandler(
            $this->config,
            new \PhpBorg\Database\Connection(
                $this->config->dbHost,
                $this->config->dbPort,
                $this->config->dbName,
                $this->config->dbUser,
                $this->config->dbPassword
            ),
            $this->backupRepository,
            $this->logger
        );

        $job = new Job(
            id: 0,
            queue: 'default',
            type: 'phpborg_backup_restore',
            payload: [
                'backup_id' => $backupId,
                'create_pre_restore_backup' => false // Don't create backup when rolling back
            ],
            status: 'processing',
            workerId: null,
            progress: 0,
            attempts: 1,
            maxAttempts: 1,
            output: null,
            error: null,
            startedAt: new \DateTime(),
            completedAt: null,
            createdAt: new \DateTime(),
            createdBy: null
        );

        $handler->handle($job, new JobQueue(
            $this->config,
            new \PhpBorg\Repository\JobRepository(
                new \PhpBorg\Database\Connection(
                    $this->config->dbHost,
                    $this->config->dbPort,
                    $this->config->dbName,
                    $this->config->dbUser,
                    $this->config->dbPassword
                )
            ),
            $this->logger
        ));

        $this->logger->info("Rollback completed", 'PHPBORG_UPDATE');
    }

    /**
     * Get current git commit
     */
    private function getCurrentCommit(string $phpborgRoot): string
    {
        return trim(shell_exec("cd {$phpborgRoot} && git rev-parse HEAD") ?? '');
    }

    /**
     * Get phpBorg root directory
     */
    private function getPhpBorgRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    // ==========================================
    // Smart Update Detection Methods
    // ==========================================

    /**
     * Get list of files changed between two commits
     *
     * @return array<string> List of changed file paths
     */
    private function getChangedFiles(string $phpborgRoot, string $fromCommit, string $toCommit): array
    {
        $cmd = "cd {$phpborgRoot} && git diff --name-only {$fromCommit}..{$toCommit} 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("Failed to get changed files, assuming full rebuild needed", 'PHPBORG_UPDATE');
            return ['*']; // Wildcard = rebuild everything
        }

        return array_filter($output, fn($line) => !empty(trim($line)));
    }

    /**
     * Check if Composer install is needed
     * Triggers on: composer.json, composer.lock changes
     */
    private function needsComposerInstall(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if ($file === 'composer.json' || $file === 'composer.lock') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if frontend build is needed
     * Triggers on: any file in frontend/ directory
     */
    private function needsFrontendBuild(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'frontend/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if agent rebuild is needed
     * Triggers on: any file in agent/ directory
     */
    private function needsAgentBuild(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'agent/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if database migrations are needed
     * Triggers on: any file in migrations/ directory
     */
    private function needsMigrations(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'migrations/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if systemd service regeneration is needed
     * Triggers on: systemd template files or bin/regenerate-systemd-services.sh
     */
    private function needsSystemdRegeneration(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'systemd/') ||
                str_contains($file, '.service') ||
                $file === 'bin/regenerate-systemd-services.sh') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if Docker image rebuild is needed
     * Triggers on: any file in docker/ directory
     */
    private function needsDockerBuild(array $changedFiles): bool
    {
        if (in_array('*', $changedFiles)) return true;

        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'docker/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild Docker images (Adminer, etc.)
     */
    private function rebuildDockerImages(string $phpborgRoot): void
    {
        // Rebuild Adminer image
        $adminerDockerDir = $phpborgRoot . '/docker/adminer';
        if (is_dir($adminerDockerDir)) {
            $this->logger->info("Rebuilding Adminer Docker image...", 'PHPBORG_UPDATE');

            $cmd = "docker build -t phpborg/adminer:latest {$adminerDockerDir} 2>&1";
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->logger->warning("Adminer Docker build failed: " . implode("\n", $output), 'PHPBORG_UPDATE');
                // Don't throw exception, just warn - Docker is optional
            } else {
                $this->logger->info("Adminer Docker image rebuilt successfully", 'PHPBORG_UPDATE');
            }
        }

        // Add more Docker images here as needed
    }
}
