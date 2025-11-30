<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\AgentRepository;
use PhpBorg\Repository\AgentTaskRepository;
use PhpBorg\Service\Agent\AgentManager;
use PhpBorg\Service\Agent\CertificateManager;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Agent Gateway API Controller
 *
 * This controller handles all communication between phpborg-agent instances
 * and the phpBorg server. Authentication is via mTLS client certificates.
 *
 * Endpoints:
 * - POST /api/agent/register - Register a new agent
 * - POST /api/agent/heartbeat - Agent heartbeat/keepalive
 * - GET  /api/agent/tasks - Poll for pending tasks
 * - POST /api/agent/tasks/{id}/start - Mark task as started
 * - POST /api/agent/tasks/{id}/progress - Update task progress
 * - POST /api/agent/tasks/{id}/complete - Mark task as completed
 * - POST /api/agent/tasks/{id}/fail - Mark task as failed
 */
final class AgentGatewayController extends BaseController
{
    private readonly AgentRepository $agentRepo;
    private readonly AgentTaskRepository $taskRepo;
    private readonly AgentManager $agentManager;
    private readonly CertificateManager $certManager;
    private readonly LoggerInterface $logger;
    private readonly \PhpBorg\Repository\ServerRepository $serverRepo;
    private readonly JobQueue $jobQueue;
    private readonly \PhpBorg\Database\Connection $connection;

    public function __construct(Application $app)
    {
        $this->agentRepo = $app->getAgentRepository();
        $this->taskRepo = $app->getAgentTaskRepository();
        $this->agentManager = $app->getAgentManager();
        $this->certManager = $app->getCertificateManager();
        $this->logger = $app->getLogger();
        $this->serverRepo = $app->getServerRepository();
        $this->jobQueue = $app->getJobQueue();
        $this->connection = $app->getConnection();
    }

    /**
     * Register a new agent
     * POST /api/agent/register
     *
     * Request body:
     * {
     *   "name": "server-name",
     *   "hostname": "server.example.com",
     *   "ssh_public_key": "ssh-ed25519 AAAA...",
     *   "os_info": "Ubuntu 22.04",
     *   "version": "1.0.0"
     * }
     */
    public function register(): void
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['name', 'hostname', 'ssh_public_key']);

        $name = $data['name'];
        $hostname = $data['hostname'];
        $sshPublicKey = $data['ssh_public_key'];
        $osInfo = $data['os_info'] ?? null;
        $version = $data['version'] ?? null;

        // Check if agent already exists
        if ($this->agentRepo->findByName($name) !== null) {
            $this->error('Agent with this name already exists', 409, 'AGENT_EXISTS');
            return;
        }

        try {
            // Generate UUID
            $uuid = $this->generateUuid();

            // Register agent with SSH key
            $sshConfig = $this->agentManager->registerAgent(
                $uuid,
                $name,
                $sshPublicKey,
                true // append-only by default
            );

            // Generate mTLS certificate
            $certificates = $this->certManager->generateAgentCertificate($uuid, $name);

            // Create database record
            $agentId = $this->agentRepo->create(
                uuid: $uuid,
                name: $name,
                hostname: $hostname,
                sshPublicKey: $sshPublicKey,
                backupPath: $sshConfig['backup_path'],
            );

            // Update with certificate info
            $certExpiry = $this->certManager->getAgentCertificateExpiry($uuid);
            if ($certExpiry) {
                $this->agentRepo->updateCertificate(
                    $agentId,
                    "agent-{$uuid}",
                    $certExpiry,
                    hash('sha256', $certificates['cert'])
                );
            }

            // Update OS info and version
            if ($osInfo) {
                $this->agentRepo->updateOsInfo($agentId, $osInfo);
            }
            if ($version) {
                $this->agentRepo->updateHeartbeat($agentId, $_SERVER['REMOTE_ADDR'] ?? null, $version);
            }

            // Activate the agent
            $this->agentRepo->updateStatus($agentId, 'active');

            $this->logger->info("Agent registered: {$name} ({$uuid})", 'AGENT_API');

            $this->success([
                'agent_id' => $agentId,
                'uuid' => $uuid,
                'certificate' => $certificates['cert'],
                'private_key' => $certificates['key'],
                'ca_certificate' => $certificates['ca'],
                'borg_ssh' => [
                    'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
                    'port' => $sshConfig['ssh_port'],
                    'user' => $sshConfig['ssh_user'],
                    'backup_path' => $sshConfig['backup_path'],
                ],
            ], 'Agent registered successfully', 201);

        } catch (\Exception $e) {
            $this->logger->error("Agent registration failed: {$e->getMessage()}", 'AGENT_API');
            $this->error('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Agent heartbeat
     * POST /api/agent/heartbeat
     *
     * Must be authenticated via mTLS
     */
    public function heartbeat(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $data = $this->getJsonBody();
        $version = $data['version'] ?? null;

        // Update heartbeat in agents table
        $this->agentRepo->updateHeartbeat(
            $agent['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $version
        );

        // Also sync heartbeat/version to servers table (for UI display)
        if ($agent['uuid']) {
            $this->serverRepo->updateAgentHeartbeat($agent['uuid'], $version);
        }

        // Update capabilities if provided
        if (isset($data['capabilities'])) {
            $this->agentRepo->updateCapabilities($agent['id'], $data['capabilities']);
        }

        // Update OS info if provided
        if (isset($data['os_info'])) {
            $this->agentRepo->updateOsInfo($agent['id'], $data['os_info']);
        }

        $this->success([
            'server_time' => date('c'),
            'next_heartbeat_in' => 60, // seconds
        ], 'Heartbeat received');
    }

    /**
     * Poll for pending tasks
     * GET /api/agent/tasks
     *
     * Must be authenticated via mTLS
     */
    public function getTasks(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        // Get pending tasks
        $tasks = $this->taskRepo->findPendingForAgent($agent['id'], 5);

        // Format tasks for response
        $formattedTasks = array_map(function ($task) {
            return [
                'id' => $task['id'],
                'type' => $task['type'],
                'priority' => $task['priority'],
                'payload' => json_decode($task['payload'], true),
                'timeout_seconds' => $task['timeout_seconds'],
                'created_at' => $task['created_at'],
            ];
        }, $tasks);

        $this->success([
            'tasks' => $formattedTasks,
            'count' => count($formattedTasks),
        ]);
    }

    /**
     * Mark task as started
     * POST /api/agent/tasks/{id}/start
     */
    public function startTask(int $taskId): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $task = $this->taskRepo->findById($taskId);
        if (!$task) {
            $this->error('Task not found', 404);
            return;
        }

        if ($task['agent_id'] !== $agent['id']) {
            $this->error('Task does not belong to this agent', 403);
            return;
        }

        // Try to assign the task
        if (!$this->taskRepo->assignTask($taskId)) {
            $this->error('Task is no longer available', 409);
            return;
        }

        // Mark as running
        $this->taskRepo->markRunning($taskId);

        $this->logger->info("Task {$taskId} started by agent {$agent['name']}", 'AGENT_API');

        $this->success(null, 'Task started');
    }

    /**
     * Update task progress
     * POST /api/agent/tasks/{id}/progress
     *
     * Request body:
     * {
     *   "progress": 50,
     *   "message": "Processing files...",
     *   "files_count": 1234,          // optional: borg file count
     *   "original_size": 12345678,    // optional: borg original size
     *   "compressed_size": 6789012,   // optional: borg compressed size
     *   "deduplicated_size": 1234567  // optional: borg dedup size
     * }
     */
    public function updateProgress(int $taskId): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $task = $this->taskRepo->findById($taskId);
        if (!$task || $task['agent_id'] !== $agent['id']) {
            $this->error('Task not found or not owned by agent', 404);
            return;
        }

        $data = $this->getJsonBody();
        $progress = (int) ($data['progress'] ?? 0);
        $message = $data['message'] ?? null;

        // Update in database
        $this->taskRepo->updateProgress($taskId, min(99, max(0, $progress)), $message);

        // If detailed borg stats are provided, store in Redis for SSE real-time updates
        if (isset($data['files_count']) || isset($data['original_size'])) {
            $progressInfo = [
                'files_count' => (int) ($data['files_count'] ?? 0),
                'original_size' => (int) ($data['original_size'] ?? 0),
                'compressed_size' => (int) ($data['compressed_size'] ?? 0),
                'deduplicated_size' => (int) ($data['deduplicated_size'] ?? 0),
                'message' => $message,
                'current_path' => $data['current_path'] ?? null,
                'timestamp' => time(),
                'agent_task_id' => $taskId,
            ];

            // If this task is linked to a job, store progress under that job ID for SSE
            if ($task['job_id']) {
                $this->jobQueue->setProgressInfo((int) $task['job_id'], $progressInfo);
            }

            // Also store under agent_task key for direct access
            $this->storeAgentTaskProgress($taskId, $progressInfo);
        }

        $this->success(null, 'Progress updated');
    }

    /**
     * Store agent task progress in Redis for SSE real-time updates
     */
    private function storeAgentTaskProgress(int $taskId, array $progressInfo): void
    {
        try {
            // Use job queue's Redis connection
            $key = "phpborg:agent-task:{$taskId}:progress";
            $redis = $this->jobQueue->getRedis();
            $redis->setex($key, 3600, json_encode($progressInfo));
        } catch (\Exception $e) {
            $this->logger->warning("Failed to store agent task progress in Redis: {$e->getMessage()}", 'AGENT_API');
        }
    }

    /**
     * Mark task as completed
     * POST /api/agent/tasks/{id}/complete
     *
     * Request body:
     * {
     *   "result": { ... },
     *   "exit_code": 0
     * }
     */
    public function completeTask(int $taskId): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $task = $this->taskRepo->findById($taskId);
        if (!$task || $task['agent_id'] !== $agent['id']) {
            $this->error('Task not found or not owned by agent', 404);
            return;
        }

        $data = $this->getJsonBody();
        $result = $data['result'] ?? null;
        $exitCode = (int) ($data['exit_code'] ?? 0);

        $this->taskRepo->markCompleted($taskId, $result, $exitCode);

        $this->logger->info("Task {$taskId} completed by agent {$agent['name']}", 'AGENT_API');

        $this->success(null, 'Task completed');
    }

    /**
     * Mark task as failed
     * POST /api/agent/tasks/{id}/fail
     *
     * Request body:
     * {
     *   "error": "Error message",
     *   "exit_code": 1
     * }
     */
    public function failTask(int $taskId): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $task = $this->taskRepo->findById($taskId);
        if (!$task || $task['agent_id'] !== $agent['id']) {
            $this->error('Task not found or not owned by agent', 404);
            return;
        }

        $data = $this->getJsonBody();
        $error = $data['error'] ?? 'Unknown error';
        $exitCode = isset($data['exit_code']) ? (int) $data['exit_code'] : null;

        $this->taskRepo->markFailed($taskId, $error, $exitCode);

        $this->logger->warning("Task {$taskId} failed: {$error}", 'AGENT_API');

        $this->success(null, 'Task marked as failed');
    }

    /**
     * Get agent info (for debugging)
     * GET /api/agent/info
     */
    public function getInfo(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $taskStats = $this->taskRepo->getStatsForAgent($agent['id']);

        $this->success([
            'agent' => [
                'id' => $agent['id'],
                'uuid' => $agent['uuid'],
                'name' => $agent['name'],
                'status' => $agent['status'],
                'last_heartbeat' => $agent['last_heartbeat'],
                'certificate_expires_at' => $agent['certificate_expires_at'],
            ],
            'task_stats' => $taskStats,
        ]);
    }

    /**
     * Require agent authentication via mTLS certificate
     */
    private function requireAgentAuth(): ?array
    {
        // Check for mTLS client certificate
        $clientCertCN = $_SERVER['SSL_CLIENT_S_DN_CN'] ?? null;

        // For development/testing, also accept API key
        if (!$clientCertCN) {
            $apiKey = $this->getBearerToken();
            if ($apiKey) {
                // Look up agent by API key (UUID)
                $agent = $this->agentRepo->findByUuid($apiKey);
                if ($agent && $agent['status'] === 'active') {
                    return $agent;
                }
            }

            $this->error('Agent authentication required', 401, 'AUTH_REQUIRED');
            return null;
        }

        // Look up agent by certificate CN
        $agent = $this->agentRepo->findByCertificateCN($clientCertCN);

        if (!$agent) {
            $this->error('Unknown agent certificate', 403, 'UNKNOWN_AGENT');
            return null;
        }

        if ($agent['status'] !== 'active') {
            $this->error('Agent is not active', 403, 'AGENT_INACTIVE');
            return null;
        }

        return $agent;
    }

    /**
     * Check for agent update
     * POST /api/agent/update/check
     *
     * Request body:
     * {
     *   "current_version": "1.0.0"
     * }
     */
    public function checkUpdate(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $data = $this->getJsonBody();
        $currentVersion = $data['current_version'] ?? '0.0.0';

        // Get latest agent version from releases directory
        $releasesDir = dirname(__DIR__, 3) . '/releases/agent';
        $latestVersion = $this->getLatestAgentVersion($releasesDir);
        $binaryPath = $this->getAgentBinaryPath($releasesDir, $latestVersion);

        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

        $response = [
            'available' => $updateAvailable,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
        ];

        if ($updateAvailable && $binaryPath && file_exists($binaryPath)) {
            $response['download_url'] = '/api/agent/update/download';
            $response['checksum'] = hash_file('sha256', $binaryPath);

            // Read release notes if available
            $releaseNotesPath = dirname($binaryPath) . '/CHANGELOG.md';
            if (file_exists($releaseNotesPath)) {
                $response['release_notes'] = file_get_contents($releaseNotesPath);
            }
        }

        $this->success($response);
    }

    /**
     * Download agent binary
     * GET /api/agent/update/download
     *
     * Returns the latest agent binary as a downloadable file
     */
    public function downloadUpdate(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $releasesDir = dirname(__DIR__, 3) . '/releases/agent';
        $latestVersion = $this->getLatestAgentVersion($releasesDir);
        $binaryPath = $this->getAgentBinaryPath($releasesDir, $latestVersion);

        if (!$binaryPath || !file_exists($binaryPath)) {
            $this->error('Agent binary not found', 404, 'BINARY_NOT_FOUND');
            return;
        }

        $this->logger->info(
            "Agent {$agent['name']} downloading update v{$latestVersion}",
            'AGENT_API'
        );

        // Send binary file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="phpborg-agent"');
        header('Content-Length: ' . filesize($binaryPath));
        header('X-Agent-Version: ' . $latestVersion);
        header('X-Checksum-SHA256: ' . hash_file('sha256', $binaryPath));

        readfile($binaryPath);
        exit;
    }

    /**
     * Get the latest agent version from releases directory
     */
    private function getLatestAgentVersion(string $releasesDir): string
    {
        if (!is_dir($releasesDir)) {
            return '0.0.0';
        }

        $versions = [];
        $dirs = scandir($releasesDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            // Match version directory like "1.0.0" or "v1.0.0"
            if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $dir, $matches)) {
                $versions[] = $matches[1];
            }
        }

        if (empty($versions)) {
            // Check for latest binary directly in releases dir
            if (file_exists($releasesDir . '/phpborg-agent')) {
                return '1.0.0'; // Default version for non-versioned releases
            }
            return '0.0.0';
        }

        usort($versions, 'version_compare');
        return end($versions);
    }

    /**
     * Renew agent certificate
     * POST /api/agent/certificate/renew
     *
     * Request body:
     * {
     *   "agent_uuid": "...",
     *   "agent_name": "..."
     * }
     */
    public function renewCertificate(): void
    {
        $agent = $this->requireAgentAuth();
        if (!$agent) {
            return;
        }

        $data = $this->getJsonBody();
        $agentUuid = $data['agent_uuid'] ?? $agent['uuid'];
        $agentName = $data['agent_name'] ?? $agent['name'];

        // Verify agent UUID matches authenticated agent
        if ($agentUuid !== $agent['uuid']) {
            $this->error('Agent UUID mismatch', 403, 'UUID_MISMATCH');
            return;
        }

        try {
            // Renew certificate using CertificateManager
            $certificates = $this->certManager->renewAgentCertificate($agentUuid, $agentName);

            // Get expiry date
            $expiry = $this->certManager->getAgentCertificateExpiry($agentUuid);

            $this->logger->info("Certificate renewed for agent {$agentName} ({$agentUuid})", 'AGENT_API');

            $this->success([
                'cert' => base64_encode($certificates['cert']),
                'key' => base64_encode($certificates['key']),
                'ca' => base64_encode($certificates['ca']),
                'expires_at' => $expiry?->format('Y-m-d H:i:s') ?? 'unknown',
            ], 'Certificate renewed successfully');

        } catch (\Exception $e) {
            $this->logger->error("Certificate renewal failed: {$e->getMessage()}", 'AGENT_API');
            $this->error('Certificate renewal failed: ' . $e->getMessage(), 500, 'RENEWAL_FAILED');
        }
    }

    /**
     * Download Windows agent binary (public endpoint)
     * GET /api/agent/download/windows
     *
     * Requires X-Registration-Token header
     */
    public function downloadWindows(): void
    {
        // Validate registration token
        $token = $_SERVER['HTTP_X_REGISTRATION_TOKEN'] ?? null;
        if (!$token || !$this->validateRegistrationToken($token)) {
            $this->error('Invalid registration token', 401, 'INVALID_TOKEN');
            return;
        }

        $releasesDir = dirname(__DIR__, 3) . '/releases/agent';
        $binaryPath = $this->getAgentBinaryPath($releasesDir, $this->getLatestAgentVersion($releasesDir), 'windows');

        if (!$binaryPath || !file_exists($binaryPath)) {
            $this->error('Windows agent binary not found', 404, 'BINARY_NOT_FOUND');
            return;
        }

        $this->logger->info("Windows agent download requested", 'AGENT_API');

        // Send binary file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="phpborg-agent.exe"');
        header('Content-Length: ' . filesize($binaryPath));
        header('X-Agent-Version: ' . $this->getLatestAgentVersion($releasesDir));
        header('X-Checksum-SHA256: ' . hash_file('sha256', $binaryPath));

        readfile($binaryPath);
        exit;
    }

    /**
     * Download Linux agent binary (public endpoint)
     * GET /api/agent/download/linux
     *
     * Requires X-Registration-Token header
     */
    public function downloadLinux(): void
    {
        // Validate registration token
        $token = $_SERVER['HTTP_X_REGISTRATION_TOKEN'] ?? null;
        if (!$token || !$this->validateRegistrationToken($token)) {
            $this->error('Invalid registration token', 401, 'INVALID_TOKEN');
            return;
        }

        $releasesDir = dirname(__DIR__, 3) . '/releases/agent';
        $binaryPath = $this->getAgentBinaryPath($releasesDir, $this->getLatestAgentVersion($releasesDir), 'linux');

        if (!$binaryPath || !file_exists($binaryPath)) {
            $this->error('Linux agent binary not found', 404, 'BINARY_NOT_FOUND');
            return;
        }

        $this->logger->info("Linux agent download requested", 'AGENT_API');

        // Send binary file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="phpborg-agent"');
        header('Content-Length: ' . filesize($binaryPath));
        header('X-Agent-Version: ' . $this->getLatestAgentVersion($releasesDir));
        header('X-Checksum-SHA256: ' . hash_file('sha256', $binaryPath));

        readfile($binaryPath);
        exit;
    }

    /**
     * Register agent with registration token (for installer scripts)
     * POST /api/agent/register-token
     *
     * Request body:
     * {
     *   "name": "server-name",
     *   "registration_token": "token-from-ui",
     *   "os": "Windows",
     *   "os_version": "Windows Server 2022",
     *   "hostname": "server.example.com",
     *   "architecture": "AMD64",
     *   "cpu_cores": 4,
     *   "cpu_model": "Intel...",
     *   "total_memory_mb": 16384,
     *   "agent_version": "2.3.0"
     * }
     */
    public function registerWithToken(): void
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['name', 'registration_token']);

        $token = $data['registration_token'];
        if (!$this->validateRegistrationToken($token)) {
            $this->error('Invalid registration token', 401, 'INVALID_TOKEN');
            return;
        }

        $name = $data['name'];
        $hostname = $data['hostname'] ?? $name;
        $os = $data['os'] ?? 'Unknown';
        $osVersion = $data['os_version'] ?? '';
        $architecture = $data['architecture'] ?? '';
        $version = $data['agent_version'] ?? null;

        // Check if agent already exists
        if ($this->agentRepo->findByName($name) !== null) {
            $this->error('Agent with this name already exists', 409, 'AGENT_EXISTS');
            return;
        }

        try {
            // Generate UUID
            $uuid = $this->generateUuid();

            // Generate SSH key pair for the agent
            $sshKeyPair = $this->generateSSHKeyPair();

            // Register agent with generated SSH key
            $sshConfig = $this->agentManager->registerAgent(
                $uuid,
                $name,
                $sshKeyPair['public'],
                true // append-only by default
            );

            // Generate mTLS certificate
            $certificates = $this->certManager->generateAgentCertificate($uuid, $name);

            // Create database record
            $agentId = $this->agentRepo->create(
                uuid: $uuid,
                name: $name,
                hostname: $hostname,
                sshPublicKey: $sshKeyPair['public'],
                backupPath: $sshConfig['backup_path'],
            );

            // Update with certificate info
            $certExpiry = $this->certManager->getAgentCertificateExpiry($uuid);
            if ($certExpiry) {
                $this->agentRepo->updateCertificate(
                    $agentId,
                    "agent-{$uuid}",
                    $certExpiry,
                    hash('sha256', $certificates['cert'])
                );
            }

            // Update OS info
            $osInfo = "{$os} {$osVersion} ({$architecture})";
            $this->agentRepo->updateOsInfo($agentId, $osInfo);

            if ($version) {
                $this->agentRepo->updateHeartbeat($agentId, $_SERVER['REMOTE_ADDR'] ?? null, $version);
            }

            // Activate the agent
            $this->agentRepo->updateStatus($agentId, 'active');

            $this->logger->info("Agent registered via token: {$name} ({$uuid}) - OS: {$os}", 'AGENT_API');

            $this->success([
                'uuid' => $uuid,
                'borg_host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
                'borg_port' => $sshConfig['ssh_port'],
                'borg_user' => $sshConfig['ssh_user'],
                'backup_path' => $sshConfig['backup_path'],
                'ssh_private_key' => $sshKeyPair['private'],
                'tls_cert' => $certificates['cert'],
                'tls_key' => $certificates['key'],
                'ca_cert' => $certificates['ca'],
            ], 'Agent registered successfully', 201);

        } catch (\Exception $e) {
            $this->logger->error("Agent registration failed: {$e->getMessage()}", 'AGENT_API');
            $this->error('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get installer script for Windows
     * GET /api/agent/installer/windows
     */
    public function getWindowsInstaller(): void
    {
        $installerPath = dirname(__DIR__, 3) . '/agent/install/install-windows.ps1';

        if (!file_exists($installerPath)) {
            $this->error('Windows installer not found', 404);
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="install-phpborg.ps1"');
        header('Content-Length: ' . filesize($installerPath));

        readfile($installerPath);
        exit;
    }

    /**
     * Generate pre-configured Windows installer package
     * GET /api/agent/installer/package
     *
     * Query params:
     * - agent_name: Optional pre-filled agent name
     *
     * Returns a ZIP containing:
     * - phpborg-installer.exe (GUI installer)
     * - install-config.json (pre-filled configuration)
     */
    public function getInstallerPackage(): void
    {
        // User is already authenticated by middleware (requireAuth: true)
        // Check if user has admin or operator role
        $user = $_SERVER['USER'] ?? null;
        if (!$user || !$user->hasAnyRole(['ROLE_ADMIN', 'ROLE_OPERATOR'])) {
            $this->error('Insufficient permissions', 403);
            return;
        }

        // Get installer exe
        $releasesDir = dirname(__DIR__, 3) . '/releases/agent';
        $installerPath = $releasesDir . '/phpborg-installer.exe';

        // Also check build directory for development
        if (!file_exists($installerPath)) {
            $installerPath = dirname(__DIR__, 3) . '/agent/build/phpborg-installer.exe';
        }

        if (!file_exists($installerPath)) {
            $this->error('Windows installer not found. Build it with: cd agent && make installer', 404);
            return;
        }

        // Get server URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $serverUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Get registration token
        $settingsRepo = new \PhpBorg\Repository\SettingRepository($this->connection);
        $setting = $settingsRepo->findByKey('agent.registration_token');
        $registrationToken = $setting?->value;
        if (!$registrationToken) {
            $registrationToken = bin2hex(random_bytes(32));
            $settingsRepo->create('agent.registration_token', $registrationToken, 'agent', 'string', 'Registration token for agents');
        }

        // Get optional agent name from query params
        $agentName = $_GET['agent_name'] ?? '';

        // Create config JSON
        $config = [
            'server_url' => $serverUrl,
            'token' => $registrationToken,
            'agent_name' => $agentName,
            'auto_start' => true,
        ];

        // Create ZIP archive
        $zipPath = sys_get_temp_dir() . '/phpborg-installer-' . uniqid() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->error('Failed to create installer package', 500);
            return;
        }

        // Add installer exe
        $zip->addFile($installerPath, 'phpborg-installer.exe');

        // Add config file
        $zip->addFromString('install-config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Add readme
        $readme = "phpBorg Agent Installer\n";
        $readme .= "========================\n\n";
        $readme .= "This installer package is pre-configured for your phpBorg server.\n\n";
        $readme .= "Instructions:\n";
        $readme .= "1. Extract both files to the same folder\n";
        $readme .= "2. Right-click phpborg-installer.exe and 'Run as administrator'\n";
        $readme .= "3. Follow the on-screen instructions\n\n";
        $readme .= "Server: {$serverUrl}\n";
        $readme .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $zip->addFromString('README.txt', $readme);

        $zip->close();

        $this->logger->info("Installer package generated by user {$user->username}", 'AGENT_API');

        // Send ZIP file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="phpborg-installer-package.zip"');
        header('Content-Length: ' . filesize($zipPath));

        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

    /**
     * Validate registration token
     */
    private function validateRegistrationToken(string $token): bool
    {
        // Get registration token from settings
        $settingsRepo = new \PhpBorg\Repository\SettingRepository($this->connection);
        $setting = $settingsRepo->findByKey('agent.registration_token');
        $validToken = $setting?->value;

        if (!$validToken) {
            // Generate a default token if not set
            $validToken = bin2hex(random_bytes(32));
            $settingsRepo->create('agent.registration_token', $validToken, 'agent', 'string', 'Registration token for agents');
        }

        return hash_equals($validToken, $token);
    }

    /**
     * Generate SSH key pair
     */
    private function generateSSHKeyPair(): array
    {
        // Generate ED25519 key pair using OpenSSL
        $tempDir = sys_get_temp_dir() . '/phpborg-keygen-' . uniqid();
        mkdir($tempDir, 0700, true);

        $privateKeyPath = $tempDir . '/id_ed25519';
        $publicKeyPath = $tempDir . '/id_ed25519.pub';

        // Generate key using ssh-keygen
        $cmd = sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -C "phpborg-agent" 2>/dev/null',
            escapeshellarg($privateKeyPath)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($privateKeyPath)) {
            // Fallback: generate RSA key
            $cmd = sprintf(
                'ssh-keygen -t rsa -b 4096 -f %s -N "" -C "phpborg-agent" 2>/dev/null',
                escapeshellarg($privateKeyPath)
            );
            exec($cmd, $output, $returnCode);
        }

        $privateKey = file_exists($privateKeyPath) ? file_get_contents($privateKeyPath) : '';
        $publicKey = file_exists($publicKeyPath) ? trim(file_get_contents($publicKeyPath)) : '';

        // Cleanup
        @unlink($privateKeyPath);
        @unlink($publicKeyPath);
        @rmdir($tempDir);

        return [
            'private' => $privateKey,
            'public' => $publicKey,
        ];
    }

    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get the path to the agent binary for a specific version and platform
     */
    private function getAgentBinaryPath(string $releasesDir, string $version, string $platform = 'linux'): ?string
    {
        $binaryName = $platform === 'windows' ? 'phpborg-agent-windows-amd64.exe' : 'phpborg-agent-linux-amd64';
        $fallbackName = $platform === 'windows' ? 'phpborg-agent.exe' : 'phpborg-agent';

        // Check versioned directory first
        $paths = [
            $releasesDir . '/' . $version . '/' . $binaryName,
            $releasesDir . '/v' . $version . '/' . $binaryName,
            $releasesDir . '/' . $binaryName,
            $releasesDir . '/' . $version . '/' . $fallbackName,
            $releasesDir . '/v' . $version . '/' . $fallbackName,
            $releasesDir . '/' . $fallbackName,
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
