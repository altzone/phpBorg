<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\ServerManager;
use PhpBorg\Service\Queue\JobQueue;
use PhpBorg\Repository\ServerRepository;

/**
 * Server Add Wizard API controller
 * Provides 3 methods for adding servers:
 * 1. Manual SSH key copy (display key + test button)
 * 2. SSH password auth + auto-install
 * 3. One-liner curl|bash with webhook callback
 */
class ServerWizardController extends BaseController
{
    private readonly ServerManager $serverManager;
    private readonly JobQueue $jobQueue;
    private readonly ServerRepository $serverRepo;
    private readonly \PhpBorg\Repository\SettingRepository $settingRepo;

    public function __construct(Application $app)
    {
        $this->serverManager = $app->getServerManager();
        $this->jobQueue = $app->getJobQueue();
        $this->serverRepo = $app->getServerRepository();
        $this->settingRepo = $app->getSettingRepository();
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
     * GET /api/server-wizard/install-script/:token
     * Generate one-liner install script (method 3)
     * Returns bash script that will:
     * - Install borg if needed
     * - Add phpborg's public key to authorized_keys
     * - Call back to webhook when complete
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

            // Generate unique token
            $token = bin2hex(random_bytes(16));
            $expiresAt = time() + 600; // 10 minutes

            // Store token in Redis or temp file
            $tokenData = [
                'token' => $token,
                'user_id' => $user->id,
                'created_at' => time(),
                'expires_at' => $expiresAt,
            ];

            // Use Redis if available, otherwise use temp file
            $redisAvailable = extension_loaded('redis');
            if ($redisAvailable) {
                try {
                    $redis = new \Redis();
                    $redis->connect('127.0.0.1', 6379);
                    $redis->setex("server_install_token:{$token}", 600, json_encode($tokenData));
                } catch (\Exception $e) {
                    // Fallback to file
                    file_put_contents("/tmp/phpborg_install_token_{$token}.json", json_encode($tokenData));
                }
            } else {
                file_put_contents("/tmp/phpborg_install_token_{$token}.json", json_encode($tokenData));
            }

            // Get server URL from config or headers
            $serverUrl = $_SERVER['HTTP_HOST'] ?? 'backup.local';
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = "{$protocol}://{$serverUrl}";

            $installUrl = "{$baseUrl}/api/server-wizard/install-script/{$token}";
            $oneLiner = "curl -sSL {$installUrl} | sudo bash";

            $this->success([
                'token' => $token,
                'install_url' => $installUrl,
                'one_liner' => $oneLiner,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'expires_in_seconds' => 600,
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'GENERATE_TOKEN_ERROR');
        }
    }

    /**
     * GET /api/server-wizard/install-script/:token
     * Serve the actual bash install script
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

            // Validate token
            $tokenData = $this->getTokenData($token);
            if (!$tokenData || $tokenData['expires_at'] < time()) {
                header('Content-Type: text/plain');
                echo "#!/bin/bash\necho 'Error: Token expired or invalid'\nexit 1\n";
                return;
            }

            // Get phpborg public key from database
            $setting = $this->settingRepo->findByKey('phpborg_ssh_public_key');
            $publicKey = trim($setting ? $setting->value : '');

            // Get callback URL
            $serverUrl = $_SERVER['HTTP_HOST'] ?? 'backup.local';
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $callbackUrl = "{$protocol}://{$serverUrl}/api/server-wizard/install-callback/{$token}";

            // Generate bash script
            $script = $this->generateBashInstallScript($publicKey, $callbackUrl, $token);

            header('Content-Type: text/plain');
            echo $script;

        } catch (\Exception $e) {
            header('Content-Type: text/plain');
            echo "#!/bin/bash\necho 'Error: " . addslashes($e->getMessage()) . "'\nexit 1\n";
        }
    }

    /**
     * POST /api/server-wizard/install-callback/:token
     * Webhook called by the install script when complete
     */
    public function installCallback(): void
    {
        try {
            $token = $_SERVER['ROUTE_PARAMS']['token'] ?? '';
            $data = $this->getJsonBody();

            if (empty($token)) {
                $this->error('Invalid token', 400, 'INVALID_TOKEN');
                return;
            }

            // Validate token
            $tokenData = $this->getTokenData($token);
            if (!$tokenData) {
                $this->error('Token not found or expired', 404, 'TOKEN_NOT_FOUND');
                return;
            }

            // Extract server info from callback
            $hostname = $data['hostname'] ?? null;
            $ipAddress = $data['ip_address'] ?? null;
            $borgVersion = $data['borg_version'] ?? null;
            $osInfo = $data['os_info'] ?? null;

            if (!$hostname) {
                $this->error('Missing hostname in callback', 400, 'MISSING_HOSTNAME');
                return;
            }

            // Mark token as used and store server info
            $this->markTokenAsUsed($token, [
                'hostname' => $hostname,
                'ip_address' => $ipAddress,
                'borg_version' => $borgVersion,
                'os_info' => $osInfo,
                'completed_at' => time(),
            ]);

            $this->success([
                'status' => 'received',
                'message' => 'Installation callback received successfully'
            ]);

        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500, 'CALLBACK_ERROR');
        }
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

            $tokenData = $this->getTokenData($token);
            if (!$tokenData) {
                $this->error('Token not found or expired', 404, 'TOKEN_NOT_FOUND');
                return;
            }

            $status = 'pending';
            $serverInfo = null;

            if (isset($tokenData['completed_at'])) {
                $status = 'completed';
                $serverInfo = [
                    'hostname' => $tokenData['hostname'] ?? null,
                    'ip_address' => $tokenData['ip_address'] ?? null,
                    'borg_version' => $tokenData['borg_version'] ?? null,
                    'os_info' => $tokenData['os_info'] ?? null,
                ];
            } elseif ($tokenData['expires_at'] < time()) {
                $status = 'expired';
            }

            $this->success([
                'status' => $status,
                'server_info' => $serverInfo,
                'expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
            ]);

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

    private function getTokenData(string $token): ?array
    {
        // Try Redis first
        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $data = $redis->get("server_install_token:{$token}");
                if ($data) {
                    return json_decode($data, true);
                }
            } catch (\Exception $e) {
                // Fallback to file
            }
        }

        // Try file
        $filePath = "/tmp/phpborg_install_token_{$token}.json";
        if (file_exists($filePath)) {
            return json_decode(file_get_contents($filePath), true);
        }

        return null;
    }

    private function markTokenAsUsed(string $token, array $serverInfo): void
    {
        $tokenData = $this->getTokenData($token);
        if (!$tokenData) {
            return;
        }

        $tokenData = array_merge($tokenData, $serverInfo);

        // Update Redis or file
        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->setex("server_install_token:{$token}", 3600, json_encode($tokenData)); // Keep for 1h
                return;
            } catch (\Exception $e) {
                // Fallback to file
            }
        }

        // Update file
        $filePath = "/tmp/phpborg_install_token_{$token}.json";
        file_put_contents($filePath, json_encode($tokenData));
    }

    private function generateBashInstallScript(string $publicKey, string $callbackUrl, string $token): string
    {
        return <<<BASH
#!/bin/bash
set -e

echo "=================================================="
echo "phpBorg Server Setup Script"
echo "=================================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=\$ID
    VER=\$VERSION_ID
else
    echo -e "\${RED}Cannot detect OS\${NC}"
    exit 1
fi

echo -e "\${GREEN}Detected OS: \$OS \$VER\${NC}"
echo ""

# Check if running as root
if [ "\$EUID" -ne 0 ]; then
    echo -e "\${RED}Please run as root (use sudo)\${NC}"
    exit 1
fi

# Step 1: Install BorgBackup
echo -e "\${YELLOW}[1/3] Installing BorgBackup...\${NC}"

if command -v borg &> /dev/null; then
    echo -e "\${GREEN}✓ BorgBackup already installed\${NC}"
else
    case "\$OS" in
        ubuntu|debian)
            apt-get update -qq
            apt-get install -y borgbackup
            ;;
        centos|rhel|fedora)
            yum install -y epel-release
            yum install -y borgbackup
            ;;
        *)
            echo -e "\${RED}Unsupported OS: \$OS\${NC}"
            exit 1
            ;;
    esac
fi

BORG_VERSION=\$(borg --version 2>&1 | head -1)
echo -e "\${GREEN}✓ \$BORG_VERSION installed\${NC}"
echo ""

# Step 2: Add SSH public key to root
echo -e "\${YELLOW}[2/3] Configuring SSH access...\${NC}"

mkdir -p /root/.ssh
chmod 700 /root/.ssh

# Add public key if not already present
if ! grep -q "{$publicKey}" /root/.ssh/authorized_keys 2>/dev/null; then
    echo "{$publicKey}" >> /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
    echo -e "\${GREEN}✓ SSH key added to authorized_keys\${NC}"
else
    echo -e "\${GREEN}✓ SSH key already present\${NC}"
fi

echo ""

# Step 3: Send callback to phpBorg server
echo -e "\${YELLOW}[3/3] Registering with backup server...\${NC}"

HOSTNAME=\$(hostname -f 2>/dev/null || hostname)
IP_ADDRESS=\$(hostname -I | awk '{print \$1}')

curl -X POST "{$callbackUrl}" \\
    -H "Content-Type: application/json" \\
    -d "{
        \"hostname\": \"\$HOSTNAME\",
        \"ip_address\": \"\$IP_ADDRESS\",
        \"borg_version\": \"\$BORG_VERSION\",
        \"os_info\": \"\$OS \$VER\"
    }" 2>&1 | grep -q "success" && echo -e "\${GREEN}✓ Registration successful\${NC}" || echo -e "\${YELLOW}⚠ Callback failed (non-critical)\${NC}"

echo ""
echo -e "\${GREEN}=================================================="
echo "✓ Server setup completed successfully!"
echo "=================================================="
echo ""
echo "You can now add this server to phpBorg."
echo ""

BASH;
    }
}

