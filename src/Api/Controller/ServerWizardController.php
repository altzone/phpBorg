<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\ServerManager;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Service\Agent\AgentInstallService;
use PhpBorg\Service\Agent\AgentManager;
use PhpBorg\Service\Agent\CertificateManager;
use PhpBorg\Repository\ServerRepository;

/**
 * Server Add Wizard API controller
 * Provides 3 methods for adding servers:
 * 1. Manual SSH key copy (display key + test button)
 * 2. SSH password auth + auto-install
 * 3. One-liner curl|bash with webhook callback
 *
 * All methods now deploy the phpborg-agent for pull-based communication.
 */
class ServerWizardController extends BaseController
{
    private readonly ServerManager $serverManager;
    private readonly JobQueue $jobQueue;
    private readonly ServerRepository $serverRepo;
    private readonly \PhpBorg\Repository\SettingRepository $settingRepo;
    private readonly AgentInstallService $agentInstallService;
    private readonly AgentManager $agentManager;
    private readonly CertificateManager $certManager;

    public function __construct(Application $app)
    {
        $this->serverManager = $app->getServerManager();
        $this->jobQueue = $app->getJobQueue();
        $this->serverRepo = $app->getServerRepository();
        $this->settingRepo = $app->getSettingRepository();
        $this->agentInstallService = new AgentInstallService(
            $app->getServerRepository(),
            $app->getAgentRepository(),
            $app->getSettingRepository()
        );
        $this->agentManager = $app->getAgentManager();
        $this->certManager = $app->getCertificateManager();
    }

    /**
     * GET /api/server-wizard/public-key
     * Get phpborg user's SSH public key for manual method
     */
    public function getPublicKey(): void
    {
        try {
            // Read public key from database settings
            $setting = $this->settingRepo->findByKey('phpborg_ssh_public_key');

            if (!$setting || !$setting->value) {
                $this->error('SSH public key not found in settings. Please run installer.', 500, 'PUBLIC_KEY_NOT_FOUND');
                return;
            }

            $publicKey = trim($setting->value);

            $this->success([
                'public_key' => $publicKey,
                'key_type' => $this->getKeyType($publicKey),
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'PUBLIC_KEY_ERROR');
        }
    }

    /**
     * POST /api/server-wizard/test-connection
     * Test SSH connection with phpborg key (for manual method)
     * Body: { "hostname": "...", "port": 22, "username": "root" }
     */
    public function testConnection(): void
    {
        try {
            $data = $this->getJsonBody();

            $this->validateRequired($data, ['hostname']);

            $hostname = $data['hostname'];
            $port = $data['port'] ?? 22;
            $username = $data['username'] ?? 'root';

            // Create job for testing SSH connection
            $jobId = $this->jobQueue->push('server_test_connection', [
                'hostname' => $hostname,
                'port' => $port,
                'username' => $username,
            ]);

            $this->success([
                'job_id' => $jobId,
                'message' => 'Connection test started. Use SSE to monitor progress.',
            ], 'Connection test job created', 202);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'TEST_CONNECTION_ERROR');
        }
    }

    /**
     * POST /api/server-wizard/setup-with-password
     * Auto-setup server using SSH password (method 2)
     * Body: { "hostname": "...", "port": 22, "username": "root", "password": "...", "use_sudo": false }
     */
    public function setupWithPassword(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            $this->validateRequired($data, ['hostname', 'password']);

            $hostname = $data['hostname'];
            $port = $data['port'] ?? 22;
            $username = $data['username'] ?? 'root';
            $password = $data['password'];
            $useSudo = $data['use_sudo'] ?? false;

            // Check if sshpass is available
            exec('which sshpass', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error(
                    'sshpass is not installed on the backup server. Please install it: apt-get install sshpass',
                    500,
                    'SSHPASS_NOT_INSTALLED'
                );
                return;
            }

            // Create a temporary job for password-based setup
            $jobId = $this->jobQueue->push(
                'server_setup_password',
                [
                    'hostname' => $hostname,
                    'port' => $port,
                    'username' => $username,
                    'password' => $password,
                    'use_sudo' => $useSudo,
                ],
                'default',
                3,
                $user->id
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Server setup job queued. The worker will install borg and configure SSH keys.'
            ], 'Setup job created', 202);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'PASSWORD_SETUP_ERROR');
        }
    }

    /**
     * POST /api/server-wizard/generate-install-token
     * Generate one-liner install script token (method 3)
     * Now generates an agent installation script
     * Body: { "server_name": "optional-name" }
     */
    public function generateInstallToken(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();
            $serverName = $data['server_name'] ?? null;

            // Generate token using AgentInstallService
            $tokenData = $this->agentInstallService->generateInstallToken($user->id, $serverName);

            // Get server URL from config or headers
            $serverUrl = $_SERVER['HTTP_HOST'] ?? 'backup.local';
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = "{$protocol}://{$serverUrl}";

            $installUrl = "{$baseUrl}/api/server-wizard/install-script/{$tokenData['token']}";
            $oneLiner = "curl -sSL '{$installUrl}' | sudo bash";

            $this->success([
                'token' => $tokenData['token'],
                'agent_uuid' => $tokenData['agent_uuid'],
                'agent_name' => $tokenData['agent_name'],
                'install_url' => $installUrl,
                'one_liner' => $oneLiner,
                'expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
                'expires_in_seconds' => $tokenData['expires_at'] - time(),
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'GENERATE_TOKEN_ERROR');
        }
    }

    /**
     * GET /api/server-wizard/install-script/:token
     * Serve the agent bash install script
     */
    public function serveInstallScript(): void
    {
        try {
            $token = $_SERVER['ROUTE_PARAMS']['token'] ?? '';

            if (empty($token)) {
                header('Content-Type: text/plain');
                echo "#!/bin/bash\necho 'Error: Invalid token'\nexit 1\n";
                return;
            }

            // Generate script using AgentInstallService
            $script = $this->agentInstallService->generateInstallScript($token);

            if (!$script) {
                header('Content-Type: text/plain');
                echo "#!/bin/bash\necho 'Error: Token expired or invalid'\nexit 1\n";
                return;
            }

            header('Content-Type: text/plain');
            echo $script;

        } catch (\Exception $e) {
            header('Content-Type: text/plain');
            echo "#!/bin/bash\necho 'Error: " . addslashes($e->getMessage()) . "'\nexit 1\n";
        }
    }

    /**
     * POST /api/server-wizard/install-callback/:token
     * Legacy webhook - redirects to agent callback
     * @deprecated Use agentCallback instead
     */
    public function installCallback(): void
    {
        $this->agentCallback();
    }

    /**
     * POST /api/server-wizard/agent-callback/:token
     * Webhook called by the agent install script when complete
     * Creates a job for async deployment of SSH key and mTLS certificates
     */
    public function agentCallback(): void
    {
        try {
            $token = $_SERVER['ROUTE_PARAMS']['token'] ?? '';
            $data = $this->getJsonBody();

            if (empty($token)) {
                $this->error('Invalid token', 400, 'INVALID_TOKEN');
                return;
            }

            // Handle registration via AgentInstallService
            try {
                $tokenData = $this->agentInstallService->handleRegistrationCallback($token, $data);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage(), 404, 'TOKEN_NOT_FOUND');
                return;
            }

            $agentUuid = $tokenData['agent_uuid'];
            $agentName = $tokenData['agent_name'];

            // Create server record first
            $serverId = $this->agentInstallService->createServerFromRegistration($token);

            // Create async job for deploying SSH key and generating certificates
            // This job runs on the "agent" queue with the dedicated agent-worker
            $jobId = null;
            if (!empty($data['ssh_public_key'])) {
                $jobId = $this->jobQueue->push(
                    'deploy_agent_key',
                    [
                        'agent_uuid' => $agentUuid,
                        'agent_name' => $agentName,
                        'public_key' => $data['ssh_public_key'],
                        'append_only' => true,
                        'server_id' => $serverId,
                    ],
                    'agent',  // Queue dédiée traitée par phpborg-agent-worker
                    1         // Max 1 attempt
                );
            }

            // Build response - agent will poll for deployment result
            $response = [
                'success' => true,
                'status' => 'pending',
                'server_id' => $serverId,
                'agent_uuid' => $agentUuid,
                'job_id' => $jobId,
                'message' => 'Registration accepted. Deployment in progress.',
                'poll_url' => $this->getServerUrl() . "/api/server-wizard/deployment-status/{$jobId}",
                'api_url' => $this->getServerUrl() . '/api',
            ];

            $this->success($response, 'Registration accepted', 202);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'CALLBACK_ERROR');
        }
    }

    /**
     * GET /api/server-wizard/deployment-status/:job_id
     * Poll endpoint for agent to check deployment status and get certificates
     */
    public function getDeploymentStatus(): void
    {
        try {
            $jobId = (int) ($_SERVER['ROUTE_PARAMS']['job_id'] ?? 0);

            if ($jobId <= 0) {
                $this->error('Invalid job ID', 400, 'INVALID_JOB_ID');
                return;
            }

            // Get job from queue
            $job = $this->jobQueue->getJob($jobId);

            if (!$job) {
                $this->error('Job not found', 404, 'JOB_NOT_FOUND');
                return;
            }

            // Check job status
            $response = [
                'job_id' => $jobId,
                'status' => $job->status,
                'progress' => $job->progress,
            ];

            if ($job->status === 'completed') {
                // Get the deployment result from Redis (set by DeployAgentKeyHandler)
                $result = $this->jobQueue->getProgressInfo($jobId);

                if ($result && isset($result['success']) && $result['success']) {
                    $response['success'] = true;
                    $response['backup_path'] = $result['backup_path'] ?? null;
                    $response['ssh_port'] = $result['ssh_port'] ?? 2222;
                    $response['ssh_user'] = $result['ssh_user'] ?? 'phpborg-borg';
                    $response['mtls_enabled'] = $result['mtls_enabled'] ?? false;

                    if (isset($result['certificates'])) {
                        $response['certificates'] = $result['certificates'];
                    }
                } else {
                    $response['success'] = true;
                    $response['message'] = 'Deployment completed';
                }
            } elseif ($job->status === 'failed') {
                $response['success'] = false;
                $response['error'] = $job->error ?? 'Unknown error';
            } else {
                // Still pending or running
                $response['success'] = null;
                $response['message'] = $job->output ?? 'Deployment in progress...';
            }

            $this->success($response);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'DEPLOYMENT_STATUS_ERROR');
        }
    }

    /**
     * Get the phpBorg server URL
     */
    private function getServerUrl(): string
    {
        // Try from settings first
        $urlSetting = $this->settingRepo->findByKey('server_url');
        if ($urlSetting && $urlSetting->value) {
            return $urlSetting->value;
        }

        // Build from current request
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return "{$protocol}://{$host}";
    }

    /**
     * GET /api/server-wizard/install-status/:token
     * Check installation status (polling endpoint for frontend)
     */
    public function getInstallStatus(): void
    {
        try {
            $token = $_SERVER['ROUTE_PARAMS']['token'] ?? '';

            if (empty($token)) {
                $this->error('Invalid token', 400, 'INVALID_TOKEN');
                return;
            }

            $status = $this->agentInstallService->getInstallStatus($token);

            if (!$status) {
                $this->error('Token not found or expired', 404, 'TOKEN_NOT_FOUND');
                return;
            }

            $this->success($status);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'STATUS_CHECK_ERROR');
        }
    }

    // ========== Private Helper Methods ==========

    private function getKeyType(string $publicKey): string
    {
        if (str_starts_with($publicKey, 'ssh-rsa')) {
            return 'RSA';
        } elseif (str_starts_with($publicKey, 'ssh-ed25519')) {
            return 'Ed25519';
        } elseif (str_starts_with($publicKey, 'ecdsa-sha2-')) {
            return 'ECDSA';
        }
        return 'Unknown';
    }
}

