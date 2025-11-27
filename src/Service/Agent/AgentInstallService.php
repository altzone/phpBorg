<?php

declare(strict_types=1);

namespace PhpBorg\Service\Agent;

use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\SettingRepository;

/**
 * Service for generating agent installation scripts
 * Used by all server addition methods (password, manual key, curl script)
 */
class AgentInstallService
{
    private ServerRepository $serverRepo;
    private SettingRepository $settingRepo;
    private string $phpBorgRoot;

    public function __construct(
        ServerRepository $serverRepo,
        SettingRepository $settingRepo,
        string $phpBorgRoot = '/opt/newphpborg/phpBorg'
    ) {
        $this->serverRepo = $serverRepo;
        $this->settingRepo = $settingRepo;
        $this->phpBorgRoot = $phpBorgRoot;
    }

    /**
     * Generate an install token for a new server/agent
     */
    public function generateInstallToken(int $userId, ?string $serverName = null): array
    {
        $token = bin2hex(random_bytes(32));
        $uuid = $this->generateUUID();

        $ttlSetting = $this->settingRepo->findByKey('agent_install_token_ttl');
        $ttl = $ttlSetting ? (int)$ttlSetting->value : 3600;
        $expiresAt = time() + $ttl;

        $tokenData = [
            'token' => $token,
            'agent_uuid' => $uuid,
            'agent_name' => $serverName ?? 'agent-' . substr($uuid, 0, 8),
            'user_id' => $userId,
            'created_at' => time(),
            'expires_at' => $expiresAt,
            'status' => 'pending',
        ];

        $this->saveTokenData($token, $tokenData);

        return $tokenData;
    }

    /**
     * Generate the agent installation script
     */
    public function generateInstallScript(string $token): ?string
    {
        $tokenData = $this->getTokenData($token);
        if (!$tokenData || $tokenData['expires_at'] < time()) {
            return null;
        }

        // Get server URL
        $serverUrl = $this->getServerUrl();

        // Get Borg SSH port
        $borgPortSetting = $this->settingRepo->findByKey('borg_ssh_port');
        $borgSshPort = $borgPortSetting ? $borgPortSetting->value : '2222';

        $agentName = $tokenData['agent_name'];
        $agentUuid = $tokenData['agent_uuid'];

        return $this->buildInstallScript($agentName, $agentUuid, $serverUrl, $borgSshPort, $token);
    }

    /**
     * Handle agent registration callback
     */
    public function handleRegistrationCallback(string $token, array $data): array
    {
        $tokenData = $this->getTokenData($token);
        if (!$tokenData) {
            throw new \RuntimeException('Token not found or expired');
        }

        // Update token with registration data
        $tokenData['status'] = 'registered';
        $tokenData['hostname'] = $data['hostname'] ?? null;
        $tokenData['ip_address'] = $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $tokenData['ssh_public_key'] = $data['ssh_public_key'] ?? null;
        $tokenData['os_info'] = $data['os_info'] ?? null;
        $tokenData['borg_version'] = $data['borg_version'] ?? null;
        $tokenData['agent_version'] = $data['agent_version'] ?? '1.0.0';
        $tokenData['registered_at'] = time();

        $this->saveTokenData($token, $tokenData);

        return $tokenData;
    }

    /**
     * Create server record from completed registration
     */
    public function createServerFromRegistration(string $token): ?int
    {
        $tokenData = $this->getTokenData($token);
        if (!$tokenData || $tokenData['status'] !== 'registered') {
            return null;
        }

        // Create server in database using the agent-specific method
        $serverId = $this->serverRepo->createWithAgent([
            'name' => $tokenData['agent_name'],
            'host' => $tokenData['hostname'] ?? $tokenData['ip_address'] ?? 'unknown',
            'port' => 22, // Not used with agent, but required field
            'backuptype' => 'agent',
            'active' => 1,
            'ssh_pub_key' => '', // Not used with agent
            'agent_uuid' => $tokenData['agent_uuid'],
            'agent_status' => 'active',
            'agent_last_heartbeat' => date('Y-m-d H:i:s'),
            'agent_version' => $tokenData['agent_version'] ?? '1.0.0',
            'connection_mode' => 'agent',
            'capabilities_data' => json_encode([
                'os_info' => $tokenData['os_info'] ?? null,
                'borg_version' => $tokenData['borg_version'] ?? null,
            ]),
        ]);

        // Mark token as completed
        $tokenData['status'] = 'completed';
        $tokenData['server_id'] = $serverId;
        $this->saveTokenData($token, $tokenData);

        return $serverId;
    }

    /**
     * Get installation status
     */
    public function getInstallStatus(string $token): ?array
    {
        $tokenData = $this->getTokenData($token);
        if (!$tokenData) {
            return null;
        }

        return [
            'status' => $tokenData['status'],
            'agent_uuid' => $tokenData['agent_uuid'],
            'agent_name' => $tokenData['agent_name'],
            'hostname' => $tokenData['hostname'] ?? null,
            'ip_address' => $tokenData['ip_address'] ?? null,
            'server_id' => $tokenData['server_id'] ?? null,
            'expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
            'is_expired' => $tokenData['expires_at'] < time(),
        ];
    }

    /**
     * Build the bash install script
     */
    private function buildInstallScript(
        string $agentName,
        string $agentUuid,
        string $serverUrl,
        string $borgSshPort,
        string $token
    ): string {
        $callbackUrl = rtrim($serverUrl, '/') . '/api/server-wizard/agent-callback/' . $token;
        $agentBinaryUrl = rtrim($serverUrl, '/') . '/downloads/phpborg-agent';

        return <<<BASH
#!/bin/bash
# phpBorg Agent Installer
# Generated for: {$agentName}
# UUID: {$agentUuid}

set -e

# Configuration
AGENT_NAME="{$agentName}"
AGENT_UUID="{$agentUuid}"
SERVER_URL="{$serverUrl}"
BORG_SSH_PORT="{$borgSshPort}"
CALLBACK_URL="{$callbackUrl}"
AGENT_BINARY_URL="{$agentBinaryUrl}"

# Paths
AGENT_USER="phpborg-agent"
AGENT_HOME="/var/lib/phpborg-agent"
CONFIG_DIR="/etc/phpborg-agent"
LOG_DIR="/var/log/phpborg-agent"
BIN_PATH="/usr/local/bin/phpborg-agent"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "=================================================="
echo "        phpBorg Agent Installer"
echo "=================================================="
echo ""
echo "Agent Name: \$AGENT_NAME"
echo "Agent UUID: \$AGENT_UUID"
echo "Server URL: \$SERVER_URL"
echo ""

# Check if running as root
if [ "\$EUID" -ne 0 ]; then
    echo -e "\${RED}ERROR: Please run as root\${NC}"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=\$ID
    OS_VERSION=\$VERSION_ID
else
    OS="unknown"
    OS_VERSION=""
fi

echo -e "Detected OS: \${GREEN}\$OS \$OS_VERSION\${NC}"
echo ""

# =============================================================================
# Step 1: Create phpborg-agent user
# =============================================================================
echo -e "\${YELLOW}[1/8] Creating phpborg-agent user...\${NC}"

if id "\$AGENT_USER" &>/dev/null; then
    echo "  User \$AGENT_USER already exists"
else
    useradd --system \\
        --home-dir "\$AGENT_HOME" \\
        --create-home \\
        --shell /usr/sbin/nologin \\
        --comment "phpBorg Agent" \\
        "\$AGENT_USER"
    echo -e "  \${GREEN}✓ User created\${NC}"
fi

# =============================================================================
# Step 2: Create directories
# =============================================================================
echo ""
echo -e "\${YELLOW}[2/8] Creating directories...\${NC}"

mkdir -p "\$CONFIG_DIR"
mkdir -p "\$LOG_DIR"
mkdir -p "\$AGENT_HOME/.ssh"
chmod 700 "\$AGENT_HOME/.ssh"
chown -R "\$AGENT_USER:\$AGENT_USER" "\$AGENT_HOME"
chown -R "\$AGENT_USER:\$AGENT_USER" "\$LOG_DIR"
echo -e "  \${GREEN}✓ Directories created\${NC}"

# =============================================================================
# Step 3: Generate SSH key pair (Ed25519)
# =============================================================================
echo ""
echo -e "\${YELLOW}[3/8] Generating SSH key pair...\${NC}"

SSH_KEY_PATH="\$AGENT_HOME/.ssh/id_ed25519"
if [ -f "\$SSH_KEY_PATH" ]; then
    echo "  SSH key already exists"
else
    ssh-keygen -t ed25519 \\
        -f "\$SSH_KEY_PATH" \\
        -N "" \\
        -C "phpborg-agent-\$AGENT_UUID"
    chown "\$AGENT_USER:\$AGENT_USER" "\$SSH_KEY_PATH" "\$SSH_KEY_PATH.pub"
    chmod 600 "\$SSH_KEY_PATH"
    chmod 644 "\$SSH_KEY_PATH.pub"
    echo -e "  \${GREEN}✓ SSH key generated\${NC}"
fi

SSH_PUBLIC_KEY=\$(cat "\$SSH_KEY_PATH.pub")

# =============================================================================
# Step 4: Install BorgBackup
# =============================================================================
echo ""
echo -e "\${YELLOW}[4/8] Installing BorgBackup...\${NC}"

if command -v borg &>/dev/null; then
    BORG_VERSION=\$(borg --version)
    echo -e "  \${GREEN}✓ BorgBackup already installed: \$BORG_VERSION\${NC}"
else
    case \$OS in
        ubuntu|debian)
            apt-get update -qq
            apt-get install -y -qq borgbackup curl
            ;;
        centos|rhel|fedora|rocky|alma)
            if command -v dnf &>/dev/null; then
                dnf install -y borgbackup curl
            else
                yum install -y epel-release
                yum install -y borgbackup curl
            fi
            ;;
        *)
            echo "  WARNING: Unknown OS, downloading borg binary..."
            wget -q -O /usr/local/bin/borg \\
                "https://github.com/borgbackup/borg/releases/download/1.2.7/borg-linux64"
            chmod +x /usr/local/bin/borg
            ;;
    esac
    BORG_VERSION=\$(borg --version)
    echo -e "  \${GREEN}✓ Installed: \$BORG_VERSION\${NC}"
fi

# =============================================================================
# Step 5: Install sudoers rules
# =============================================================================
echo ""
echo -e "\${YELLOW}[5/8] Installing sudoers rules...\${NC}"

SUDOERS_FILE="/etc/sudoers.d/phpborg-agent"
cat > "\$SUDOERS_FILE" << 'SUDOERS_EOF'
# phpBorg Agent sudoers rules
Defaults:phpborg-agent !requiretty
Defaults:phpborg-agent env_reset

# System information (read-only)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /etc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/df *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/du *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/find *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl is-active *

# MySQL/MariaDB
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysqldump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysql -e *

# PostgreSQL
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dump *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dumpall *

# Borg Backup
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg *
phpborg-agent ALL=(root) NOPASSWD: /usr/local/bin/borg *
SUDOERS_EOF

chmod 440 "\$SUDOERS_FILE"
echo -e "  \${GREEN}✓ Sudoers rules installed\${NC}"

# =============================================================================
# Step 6: Register with phpBorg server
# =============================================================================
echo ""
echo -e "\${YELLOW}[6/9] Registering with phpBorg server...\${NC}"

HOSTNAME=\$(hostname -f 2>/dev/null || hostname)
IP_ADDRESS=\$(hostname -I 2>/dev/null | awk '{print \$1}' || echo "unknown")

REGISTER_RESPONSE=\$(curl -s -X POST \\
    -H "Content-Type: application/json" \\
    -d "{
        \\"agent_uuid\\": \\"\$AGENT_UUID\\",
        \\"agent_name\\": \\"\$AGENT_NAME\\",
        \\"hostname\\": \\"\$HOSTNAME\\",
        \\"ip_address\\": \\"\$IP_ADDRESS\\",
        \\"ssh_public_key\\": \\"\$SSH_PUBLIC_KEY\\",
        \\"os_info\\": \\"\$OS \$OS_VERSION\\",
        \\"borg_version\\": \\"\$BORG_VERSION\\",
        \\"agent_version\\": \\"1.0.0\\"
    }" \\
    "\$CALLBACK_URL" 2>&1) || {
    echo -e "  \${RED}ERROR: Failed to connect to phpBorg server\${NC}"
    echo "  Response: \$REGISTER_RESPONSE"
    exit 1
}

if echo "\$REGISTER_RESPONSE" | grep -q '"success":true'; then
    echo -e "  \${GREEN}✓ Registration accepted\${NC}"
else
    echo -e "  \${RED}ERROR: Registration failed\${NC}"
    echo "  Response: \$REGISTER_RESPONSE"
    exit 1
fi

# Extract server host from response for SSH config
SERVER_HOST=\$(echo "\$SERVER_URL" | sed 's|https\\?://||' | sed 's|/.*||' | sed 's|:.*||')

# Extract job_id for polling deployment status
if command -v jq &>/dev/null; then
    JOB_ID=\$(echo "\$REGISTER_RESPONSE" | jq -r '.data.job_id // empty')
else
    JOB_ID=\$(echo "\$REGISTER_RESPONSE" | grep -o '"job_id":[0-9]*' | sed 's/"job_id"://')
fi

# =============================================================================
# Step 6b: Poll for deployment completion and get mTLS certificates
# =============================================================================
CERTS_DIR="\$CONFIG_DIR/certs"
mkdir -p "\$CERTS_DIR"
chmod 700 "\$CERTS_DIR"
USE_MTLS=false

if [ -n "\$JOB_ID" ] && [ "\$JOB_ID" != "null" ]; then
    echo -e "  \${YELLOW}Waiting for deployment (job #\$JOB_ID)...\${NC}"

    POLL_URL="\${SERVER_URL}/api/server-wizard/deployment-status/\${JOB_ID}"
    MAX_ATTEMPTS=30
    ATTEMPT=0

    while [ \$ATTEMPT -lt \$MAX_ATTEMPTS ]; do
        ATTEMPT=\$((ATTEMPT + 1))
        sleep 2

        POLL_RESPONSE=\$(curl -s "\$POLL_URL" 2>&1) || continue

        # Check job status
        if command -v jq &>/dev/null; then
            STATUS=\$(echo "\$POLL_RESPONSE" | jq -r '.data.status // empty')
            SUCCESS=\$(echo "\$POLL_RESPONSE" | jq -r '.data.success // empty')
        else
            STATUS=\$(echo "\$POLL_RESPONSE" | grep -o '"status":"[^"]*"' | head -1 | sed 's/"status":"//;s/"$//')
            SUCCESS=\$(echo "\$POLL_RESPONSE" | grep -o '"success":true' || echo "")
        fi

        if [ "\$STATUS" = "completed" ]; then
            if [ "\$SUCCESS" = "true" ] || [ -n "\$SUCCESS" ]; then
                echo -e "  \${GREEN}✓ Deployment completed\${NC}"

                # Extract certificates
                if command -v jq &>/dev/null; then
                    CERT_B64=\$(echo "\$POLL_RESPONSE" | jq -r '.data.certificates.cert // empty')
                    KEY_B64=\$(echo "\$POLL_RESPONSE" | jq -r '.data.certificates.key // empty')
                    CA_B64=\$(echo "\$POLL_RESPONSE" | jq -r '.data.certificates.ca // empty')
                else
                    CERT_B64=\$(echo "\$POLL_RESPONSE" | grep -o '"cert":"[^"]*"' | sed 's/"cert":"//;s/"$//')
                    KEY_B64=\$(echo "\$POLL_RESPONSE" | grep -o '"key":"[^"]*"' | sed 's/"key":"//;s/"$//')
                    CA_B64=\$(echo "\$POLL_RESPONSE" | grep -o '"ca":"[^"]*"' | sed 's/"ca":"//;s/"$//')
                fi

                if [ -n "\$CERT_B64" ] && [ -n "\$KEY_B64" ] && [ -n "\$CA_B64" ]; then
                    echo "\$CERT_B64" | base64 -d > "\$CERTS_DIR/agent.crt"
                    echo "\$KEY_B64" | base64 -d > "\$CERTS_DIR/agent.key"
                    echo "\$CA_B64" | base64 -d > "\$CERTS_DIR/ca.crt"

                    chmod 644 "\$CERTS_DIR/agent.crt"
                    chmod 600 "\$CERTS_DIR/agent.key"
                    chmod 644 "\$CERTS_DIR/ca.crt"
                    chown -R "\$AGENT_USER:\$AGENT_USER" "\$CERTS_DIR"

                    echo -e "  \${GREEN}✓ mTLS certificates installed\${NC}"
                    USE_MTLS=true
                else
                    echo -e "  \${YELLOW}⚠ Certificates not available, using Bearer token auth\${NC}"
                fi
                break
            fi
        elif [ "\$STATUS" = "failed" ]; then
            echo -e "  \${RED}⚠ Deployment failed, continuing without mTLS\${NC}"
            break
        else
            printf "."
        fi
    done

    if [ \$ATTEMPT -ge \$MAX_ATTEMPTS ]; then
        echo ""
        echo -e "  \${YELLOW}⚠ Deployment timeout, continuing without mTLS\${NC}"
    fi
else
    echo -e "  \${YELLOW}ℹ No deployment job, mTLS not available\${NC}"
fi

# =============================================================================
# Step 7: Download agent binary
# =============================================================================
echo ""
echo -e "\${YELLOW}[7/9] Downloading agent binary...\${NC}"

if curl -fsSL -o "\$BIN_PATH" "\$AGENT_BINARY_URL"; then
    chmod +x "\$BIN_PATH"

    # Verify binary works
    if "\$BIN_PATH" -version &>/dev/null; then
        AGENT_VERSION=\$("\$BIN_PATH" -version 2>&1 | head -1)
        echo -e "  \${GREEN}✓ Agent downloaded: \$AGENT_VERSION\${NC}"
    else
        echo -e "  \${GREEN}✓ Agent binary downloaded\${NC}"
    fi
else
    echo -e "  \${RED}ERROR: Failed to download agent binary\${NC}"
    echo "  URL: \$AGENT_BINARY_URL"
    echo ""
    echo "  You can download it manually later:"
    echo "    curl -o \$BIN_PATH \$AGENT_BINARY_URL && chmod +x \$BIN_PATH"
    # Continue anyway - the binary can be installed later
fi

# =============================================================================
# Step 8: Create configuration
# =============================================================================
echo ""
echo -e "\${YELLOW}[8/9] Creating configuration...\${NC}"

# Build TLS section based on whether mTLS is enabled
if [ "\$USE_MTLS" = true ]; then
    TLS_CONFIG="tls:
  cert_file: \$CERTS_DIR/agent.crt
  key_file: \$CERTS_DIR/agent.key
  ca_file: \$CERTS_DIR/ca.crt"
else
    TLS_CONFIG="# TLS not configured - using Bearer token auth
# tls:
#   cert_file: \$CERTS_DIR/agent.crt
#   key_file: \$CERTS_DIR/agent.key
#   ca_file: \$CERTS_DIR/ca.crt"
fi

cat > "\$CONFIG_DIR/config.yaml" << CONFIG_EOF
# phpBorg Agent Configuration
# Generated: \$(date -Iseconds)

server:
  url: \${SERVER_URL}/api
  insecure_skip_verify: false

agent:
  uuid: \$AGENT_UUID
  name: \$AGENT_NAME
  max_concurrent_tasks: 2

borg_ssh:
  host: \$SERVER_HOST
  port: \$BORG_SSH_PORT
  user: phpborg-borg
  private_key_path: \$SSH_KEY_PATH
  backup_path: /opt/backups/\$AGENT_UUID

\$TLS_CONFIG

polling:
  interval: 5s
  heartbeat_interval: 60s

logging:
  level: info
  file: \$LOG_DIR/agent.log
CONFIG_EOF

chown -R "\$AGENT_USER:\$AGENT_USER" "\$CONFIG_DIR"
chmod 600 "\$CONFIG_DIR/config.yaml"
echo -e "  \${GREEN}✓ Configuration created\${NC}"

# =============================================================================
# Step 9: Create systemd service
# =============================================================================
echo ""
echo -e "\${YELLOW}[9/9] Creating systemd service...\${NC}"

cat > /etc/systemd/system/phpborg-agent.service << SERVICE_EOF
[Unit]
Description=phpBorg Agent
Documentation=https://github.com/altzone/phpBorg
After=network.target

[Service]
Type=simple
User=\$AGENT_USER
Group=\$AGENT_USER
ExecStart=\$BIN_PATH -config \$CONFIG_DIR/config.yaml
Restart=always
RestartSec=10

# Security hardening
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ReadWritePaths=\$LOG_DIR
ReadWritePaths=\$AGENT_HOME

[Install]
WantedBy=multi-user.target
SERVICE_EOF

systemctl daemon-reload
echo -e "  \${GREEN}✓ Systemd service created\${NC}"

# =============================================================================
# Start agent if binary is present
# =============================================================================
if [ -x "\$BIN_PATH" ]; then
    echo ""
    echo -e "\${YELLOW}Starting phpborg-agent service...\${NC}"
    systemctl enable phpborg-agent
    systemctl start phpborg-agent

    sleep 2

    if systemctl is-active --quiet phpborg-agent; then
        echo -e "  \${GREEN}✓ Agent is running\${NC}"
    else
        echo -e "  \${RED}✗ Agent failed to start\${NC}"
        echo "  Check logs: journalctl -u phpborg-agent -f"
    fi
fi

# =============================================================================
# Done!
# =============================================================================
echo ""
echo -e "\${GREEN}=================================================="
echo "        Installation Complete!"
echo "==================================================\${NC}"
echo ""
echo "Agent UUID:  \$AGENT_UUID"
echo "Agent Name:  \$AGENT_NAME"
echo "Config:      \$CONFIG_DIR/config.yaml"
echo ""

if [ -x "\$BIN_PATH" ]; then
    echo -e "\${GREEN}✓ Agent is installed and running\${NC}"
    echo ""
    echo "Useful commands:"
    echo "  Status:   systemctl status phpborg-agent"
    echo "  Logs:     journalctl -u phpborg-agent -f"
    echo "  Restart:  systemctl restart phpborg-agent"
else
    echo -e "\${YELLOW}Note: Agent binary not installed.\${NC}"
    echo ""
    echo "To complete installation, download the agent binary:"
    echo "  curl -o \$BIN_PATH \$AGENT_BINARY_URL && chmod +x \$BIN_PATH"
    echo "  systemctl enable phpborg-agent"
    echo "  systemctl start phpborg-agent"
fi
echo ""

BASH;
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
     * Generate a UUID v4
     */
    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Save token data (Redis or file)
     */
    private function saveTokenData(string $token, array $data): void
    {
        $ttl = max(3600, ($data['expires_at'] ?? time()) - time() + 3600);

        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->setex("agent_install_token:{$token}", $ttl, json_encode($data));
                return;
            } catch (\Exception $e) {
                // Fallback to file
            }
        }

        $dir = '/tmp/phpborg_agent_tokens';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents("{$dir}/{$token}.json", json_encode($data));
    }

    /**
     * Get token data (Redis or file)
     */
    private function getTokenData(string $token): ?array
    {
        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $data = $redis->get("agent_install_token:{$token}");
                if ($data) {
                    return json_decode($data, true);
                }
            } catch (\Exception $e) {
                // Fallback to file
            }
        }

        $filePath = "/tmp/phpborg_agent_tokens/{$token}.json";
        if (file_exists($filePath)) {
            return json_decode(file_get_contents($filePath), true);
        }

        return null;
    }
}
