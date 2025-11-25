<?php

declare(strict_types=1);

namespace PhpBorg\Service;

use PhpBorg\Config\Configuration;
use PhpBorg\Logger\LoggerInterface;

/**
 * Service for checking and managing phpBorg updates
 *
 * Features:
 * - Check for available updates from git remote
 * - Get changelog between versions
 * - Get current version info
 * - Compare local and remote commits
 */
final class PhpBorgUpdateService
{
    private const GIT_REMOTE = 'origin';
    private const GIT_BRANCH = 'master';

    // Default minimum commits for update notification
    private const MIN_COMMITS_FOR_NOTIFICATION = 1;

    // Update check cache TTL in seconds (30 minutes)
    private const UPDATE_CHECK_CACHE_TTL = 1800;

    // Test constant to verify update system works correctly
    private const UPDATE_SYSTEM_TEST_VERSION = 15;

    // Maximum number of changelog commits to display
    private const MAX_CHANGELOG_COMMITS = 50;

    // Enable verbose logging for update operations
    private const ENABLE_DEBUG_LOGGING = true;

    // Retry attempts for failed git operations
    private const GIT_RETRY_ATTEMPTS = 3;

    // Timeout for git operations in seconds
    private const GIT_OPERATION_TIMEOUT = 300;

    // Enable automatic rollback on update failure
    private const ENABLE_AUTO_ROLLBACK = true;

    public function __construct(
        private readonly Configuration $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if updates are available
     *
     * @return array{available: bool, current_commit: string, latest_commit: string, commits_behind: int, error: ?string}
     */
    public function checkForUpdates(): array
    {
        try {
            $gitDir = $this->getGitDirectory();
            $remoteRef = self::GIT_REMOTE . '/' . self::GIT_BRANCH;

            // Fetch latest from remote
            $this->logger->info("Fetching latest updates from git remote", 'PHPBORG_UPDATE');
            exec("cd {$gitDir} && git fetch " . self::GIT_REMOTE . " " . self::GIT_BRANCH . " 2>&1", $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception("Failed to fetch from remote: " . implode("\n", $output));
            }

            // Get current commit
            $currentCommit = trim(shell_exec("cd {$gitDir} && git rev-parse HEAD") ?? '');

            // Get latest remote commit (use origin/master which is more reliable than FETCH_HEAD)
            $latestCommit = trim(shell_exec("cd {$gitDir} && git rev-parse {$remoteRef} 2>/dev/null") ?? '');

            // Validate we got a proper commit hash (40 hex characters)
            if (empty($latestCommit) || !preg_match('/^[a-f0-9]{40}$/i', $latestCommit)) {
                throw new \Exception("Failed to get remote commit hash: got '{$latestCommit}'");
            }

            // Count commits behind
            $commitsBehind = 0;
            if ($currentCommit !== $latestCommit) {
                $commitsBehind = (int)trim(shell_exec("cd {$gitDir} && git rev-list --count HEAD..{$remoteRef}") ?? '0');
            }

            // Get latest commit message
            $latestMessage = '';
            if ($currentCommit !== $latestCommit) {
                $latestMessage = trim(shell_exec("cd {$gitDir} && git log -1 --format=%s {$remoteRef} 2>/dev/null") ?? '');
            }

            $this->logger->info("Update check completed", 'PHPBORG_UPDATE', [
                'current' => substr($currentCommit, 0, 7),
                'latest' => substr($latestCommit, 0, 7),
                'commits_behind' => $commitsBehind
            ]);

            return [
                'available' => $currentCommit !== $latestCommit,
                'current_commit' => $currentCommit,
                'current_commit_short' => substr($currentCommit, 0, 7),
                'latest_commit' => $latestCommit,
                'latest_commit_short' => substr($latestCommit, 0, 7),
                'commits_behind' => $commitsBehind,
                'latest_message' => $latestMessage,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to check for updates", 'PHPBORG_UPDATE', [
                'error' => $e->getMessage()
            ]);

            return [
                'available' => false,
                'current_commit' => '',
                'current_commit_short' => '',
                'latest_commit' => '',
                'latest_commit_short' => '',
                'commits_behind' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get changelog between current and latest version
     *
     * @return array{commits: array<array{hash: string, author: string, date: string, message: string}>, error: ?string}
     */
    public function getChangelog(): array
    {
        try {
            $gitDir = $this->getGitDirectory();
            $remoteRef = self::GIT_REMOTE . '/' . self::GIT_BRANCH;

            // Get commits between HEAD and origin/master
            $format = '--pretty=format:{"hash":"%H","hash_short":"%h","author":"%an","date":"%ai","message":"%s"}';
            $cmd = "cd {$gitDir} && git log HEAD..{$remoteRef} {$format} 2>&1";

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception("Failed to get changelog: " . implode("\n", $output));
            }

            $commits = [];
            foreach ($output as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                $commit = json_decode($line, true);
                if ($commit) {
                    $commits[] = $commit;
                }
            }

            return [
                'commits' => $commits,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to get changelog", 'PHPBORG_UPDATE', [
                'error' => $e->getMessage()
            ]);

            return [
                'commits' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get current version info
     *
     * @return array{commit: string, commit_short: string, branch: string, date: string, author: string, message: string}
     */
    public function getCurrentVersion(): array
    {
        try {
            $gitDir = $this->getGitDirectory();

            $commit = trim(shell_exec("cd {$gitDir} && git rev-parse HEAD") ?? '');
            $commitShort = substr($commit, 0, 7);
            $branch = trim(shell_exec("cd {$gitDir} && git rev-parse --abbrev-ref HEAD") ?? '');
            $date = trim(shell_exec("cd {$gitDir} && git log -1 --format=%ai") ?? '');
            $author = trim(shell_exec("cd {$gitDir} && git log -1 --format=%an") ?? '');
            $message = trim(shell_exec("cd {$gitDir} && git log -1 --format=%s") ?? '');

            return [
                'commit' => $commit,
                'commit_short' => $commitShort,
                'branch' => $branch,
                'date' => $date,
                'author' => $author,
                'message' => $message
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to get current version", 'PHPBORG_UPDATE', [
                'error' => $e->getMessage()
            ]);

            return [
                'commit' => '',
                'commit_short' => '',
                'branch' => '',
                'date' => '',
                'author' => '',
                'message' => ''
            ];
        }
    }

    /**
     * Get quick status for badge display (synchronous, cached)
     *
     * This method uses local git refs only (no network call) for fast response
     *
     * @return array{available: bool, commits_behind: int, current_commit_short: string, latest_commit_short: string, latest_message: string}
     */
    public function getQuickStatus(): array
    {
        try {
            $gitDir = $this->getGitDirectory();

            // Get current commit
            $currentCommit = trim(shell_exec("cd {$gitDir} && git rev-parse HEAD") ?? '');
            $currentCommitShort = substr($currentCommit, 0, 7);

            // Get latest fetched remote commit (from last git fetch)
            $latestCommit = trim(shell_exec("cd {$gitDir} && git rev-parse " . self::GIT_REMOTE . "/" . self::GIT_BRANCH . " 2>/dev/null") ?? '');
            $latestCommitShort = substr($latestCommit, 0, 7);

            // If we couldn't get remote ref, no updates available
            if (empty($latestCommit)) {
                return [
                    'available' => false,
                    'commits_behind' => 0,
                    'current_commit_short' => $currentCommitShort,
                    'latest_commit_short' => $currentCommitShort,
                    'latest_message' => ''
                ];
            }

            // Count commits behind
            $commitsBehind = 0;
            if ($currentCommit !== $latestCommit) {
                $behindOutput = trim(shell_exec("cd {$gitDir} && git rev-list HEAD.." . self::GIT_REMOTE . "/" . self::GIT_BRANCH . " --count 2>/dev/null") ?? '0');
                $commitsBehind = (int) $behindOutput;
            }

            // Get latest commit message
            $latestMessage = '';
            if ($commitsBehind > 0) {
                $latestMessage = trim(shell_exec("cd {$gitDir} && git log -1 --format=%s " . self::GIT_REMOTE . "/" . self::GIT_BRANCH . " 2>/dev/null") ?? '');
            }

            return [
                'available' => $commitsBehind > 0,
                'commits_behind' => $commitsBehind,
                'current_commit_short' => $currentCommitShort,
                'latest_commit_short' => $latestCommitShort,
                'latest_message' => $latestMessage
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to get quick status", 'PHPBORG_UPDATE', [
                'error' => $e->getMessage()
            ]);

            return [
                'available' => false,
                'commits_behind' => 0,
                'current_commit_short' => '',
                'latest_commit_short' => '',
                'latest_message' => ''
            ];
        }
    }

    /**
     * Get git repository directory
     */
    private function getGitDirectory(): string
    {
        // phpBorg root is 4 levels up from this file
        return dirname(__DIR__, 2);
    }
}
