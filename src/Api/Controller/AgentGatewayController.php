<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\AgentRepository;
use PhpBorg\Repository\AgentTaskRepository;
use PhpBorg\Service\Agent\AgentManager;
use PhpBorg\Service\Agent\CertificateManager;

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

    public function __construct(Application $app)
    {
        $this->agentRepo = $app->getAgentRepository();
        $this->taskRepo = $app->getAgentTaskRepository();
        $this->agentManager = $app->getAgentManager();
        $this->certManager = $app->getCertificateManager();
        $this->logger = $app->getLogger();
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

        // Update heartbeat
        $this->agentRepo->updateHeartbeat(
            $agent['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $data['version'] ?? null
        );

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
     *   "message": "Processing files..."
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

        $this->taskRepo->updateProgress($taskId, min(99, max(0, $progress)), $message);

        $this->success(null, 'Progress updated');
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
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
