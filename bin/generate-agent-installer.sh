#!/bin/bash
# phpBorg Agent Installer Generator
# Creates a customized installation script for a specific agent

set -e

usage() {
    echo "Usage: $0 -n <agent-name> -s <phpborg-server-url> [-p <borg-ssh-port>]"
    echo ""
    echo "Options:"
    echo "  -n  Agent name (required)"
    echo "  -s  phpBorg server URL, e.g., https://backup.example.com (required)"
    echo "  -p  Borg SSH port (default: 2222)"
    echo ""
    exit 1
}

AGENT_NAME=""
SERVER_URL=""
BORG_SSH_PORT="2222"

while getopts "n:s:p:h" opt; do
    case $opt in
        n) AGENT_NAME="$OPTARG" ;;
        s) SERVER_URL="$OPTARG" ;;
        p) BORG_SSH_PORT="$OPTARG" ;;
        h) usage ;;
        *) usage ;;
    esac
done

if [ -z "$AGENT_NAME" ] || [ -z "$SERVER_URL" ]; then
    usage
fi

# Generate UUID for agent
AGENT_UUID=$(cat /proc/sys/kernel/random/uuid)

# Output the installer script
cat << 'INSTALLER_EOF'
#!/bin/bash
# phpBorg Agent Installer
# Generated for: AGENT_NAME_PLACEHOLDER
# UUID: AGENT_UUID_PLACEHOLDER

set -e

# Configuration
AGENT_NAME="AGENT_NAME_PLACEHOLDER"
AGENT_UUID="AGENT_UUID_PLACEHOLDER"
SERVER_URL="SERVER_URL_PLACEHOLDER"
BORG_SSH_PORT="BORG_SSH_PORT_PLACEHOLDER"

# Paths
AGENT_USER="phpborg-agent"
AGENT_HOME="/var/lib/phpborg-agent"
CONFIG_DIR="/etc/phpborg-agent"
LOG_DIR="/var/log/phpborg-agent"
BIN_PATH="/usr/local/bin/phpborg-agent"

echo "=================================="
echo "phpBorg Agent Installer"
echo "=================================="
echo ""
echo "Agent Name: $AGENT_NAME"
echo "Agent UUID: $AGENT_UUID"
echo "Server URL: $SERVER_URL"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
    OS_VERSION=$VERSION_ID
else
    OS="unknown"
fi

echo "Detected OS: $OS $OS_VERSION"

# Step 1: Create phpborg-agent user
echo ""
echo "[1/7] Creating phpborg-agent user..."
if id "$AGENT_USER" &>/dev/null; then
    echo "  User $AGENT_USER already exists"
else
    useradd --system \
        --home-dir "$AGENT_HOME" \
        --create-home \
        --shell /usr/sbin/nologin \
        --comment "phpBorg Agent" \
        "$AGENT_USER"
    echo "  User created"
fi

# Step 2: Create directories
echo ""
echo "[2/7] Creating directories..."
mkdir -p "$CONFIG_DIR"
mkdir -p "$LOG_DIR"
mkdir -p "$AGENT_HOME/.ssh"
chmod 700 "$AGENT_HOME/.ssh"
chown -R "$AGENT_USER:$AGENT_USER" "$AGENT_HOME"
chown -R "$AGENT_USER:$AGENT_USER" "$LOG_DIR"
echo "  Directories created"

# Step 3: Generate SSH key pair (Ed25519)
echo ""
echo "[3/7] Generating SSH key pair..."
SSH_KEY_PATH="$AGENT_HOME/.ssh/id_ed25519"
if [ -f "$SSH_KEY_PATH" ]; then
    echo "  SSH key already exists"
else
    ssh-keygen -t ed25519 \
        -f "$SSH_KEY_PATH" \
        -N "" \
        -C "phpborg-agent-$AGENT_UUID"
    chown "$AGENT_USER:$AGENT_USER" "$SSH_KEY_PATH" "$SSH_KEY_PATH.pub"
    chmod 600 "$SSH_KEY_PATH"
    chmod 644 "$SSH_KEY_PATH.pub"
    echo "  SSH key generated"
fi

SSH_PUBLIC_KEY=$(cat "$SSH_KEY_PATH.pub")

# Step 4: Install BorgBackup if not present
echo ""
echo "[4/7] Installing BorgBackup..."
if command -v borg &>/dev/null; then
    BORG_VERSION=$(borg --version)
    echo "  BorgBackup already installed: $BORG_VERSION"
else
    case $OS in
        ubuntu|debian)
            apt-get update -qq
            apt-get install -y -qq borgbackup
            ;;
        centos|rhel|fedora|rocky|alma)
            if command -v dnf &>/dev/null; then
                dnf install -y borgbackup
            else
                yum install -y epel-release
                yum install -y borgbackup
            fi
            ;;
        *)
            echo "  WARNING: Unknown OS, downloading borg binary..."
            wget -q -O /usr/local/bin/borg \
                "https://github.com/borgbackup/borg/releases/download/1.2.7/borg-linux64"
            chmod +x /usr/local/bin/borg
            ;;
    esac
    BORG_VERSION=$(borg --version)
    echo "  Installed: $BORG_VERSION"
fi

# Step 5: Install sudoers rules
echo ""
echo "[5/7] Installing sudoers rules..."
SUDOERS_FILE="/etc/sudoers.d/phpborg-agent"
cat > "$SUDOERS_FILE" << 'SUDOERS_EOF'
# phpBorg Agent sudoers rules
# Minimal privileges for backup operations

Defaults:phpborg-agent !requiretty
Defaults:phpborg-agent env_reset

# System information (read-only)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /etc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /proc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/df *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/du *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/find *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/which *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl is-active *

# MySQL/MariaDB (read-only dumps)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysqldump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mariadb-dump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysql -e *

# PostgreSQL (read-only dumps)
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dump *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dumpall *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/psql -t -c *

# Borg Backup
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg --version
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg create *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg extract *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg list *

# LVM Snapshots (restricted naming pattern)
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvcreate -L * -s -n phpborg_snap_* *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvremove -f /dev/*/phpborg_snap_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mount -o ro /dev/*/phpborg_snap_* /mnt/phpborg_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/umount /mnt/phpborg_*

# Restore operations (restricted paths)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mkdir -p /var/restore/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/rm -rf /var/restore/*
SUDOERS_EOF
chmod 440 "$SUDOERS_FILE"
echo "  Sudoers rules installed"

# Add to docker group if docker exists
if getent group docker > /dev/null 2>&1; then
    usermod -aG docker "$AGENT_USER" 2>/dev/null || true
    echo "  Added to docker group"
fi

# Step 6: Register with phpBorg server
echo ""
echo "[6/7] Registering with phpBorg server..."
HOSTNAME=$(hostname -f)

REGISTER_RESPONSE=$(curl -s -X POST \
    -H "Content-Type: application/json" \
    -d "{
        \"name\": \"$AGENT_NAME\",
        \"hostname\": \"$HOSTNAME\",
        \"ssh_public_key\": \"$SSH_PUBLIC_KEY\",
        \"os_info\": \"$OS $OS_VERSION\",
        \"version\": \"1.0.0\"
    }" \
    "${SERVER_URL}/api/agent/register" 2>&1) || {
    echo "  ERROR: Failed to connect to phpBorg server"
    echo "  Response: $REGISTER_RESPONSE"
    exit 1
}

# Parse response
if ! echo "$REGISTER_RESPONSE" | grep -q '"success":true'; then
    echo "  ERROR: Registration failed"
    echo "  Response: $REGISTER_RESPONSE"
    exit 1
fi

# Extract certificates from response (using simple grep/sed)
CERT=$(echo "$REGISTER_RESPONSE" | grep -o '"certificate":"[^"]*"' | sed 's/"certificate":"//;s/"$//' | sed 's/\\n/\n/g')
KEY=$(echo "$REGISTER_RESPONSE" | grep -o '"private_key":"[^"]*"' | sed 's/"private_key":"//;s/"$//' | sed 's/\\n/\n/g')
CA=$(echo "$REGISTER_RESPONSE" | grep -o '"ca_certificate":"[^"]*"' | sed 's/"ca_certificate":"//;s/"$//' | sed 's/\\n/\n/g')
BACKUP_PATH=$(echo "$REGISTER_RESPONSE" | grep -o '"backup_path":"[^"]*"' | sed 's/"backup_path":"//;s/"$//')
SSH_USER=$(echo "$REGISTER_RESPONSE" | grep -o '"user":"[^"]*"' | sed 's/"user":"//;s/"$//')

# Save certificates
echo "$CERT" > "$CONFIG_DIR/agent.crt"
echo "$KEY" > "$CONFIG_DIR/agent.key"
echo "$CA" > "$CONFIG_DIR/ca.crt"
chmod 600 "$CONFIG_DIR/agent.key"
chmod 644 "$CONFIG_DIR/agent.crt" "$CONFIG_DIR/ca.crt"

echo "  Registered successfully"

# Step 7: Create configuration file
echo ""
echo "[7/7] Creating configuration..."

# Extract server hostname from URL
SERVER_HOST=$(echo "$SERVER_URL" | sed 's|https\?://||' | sed 's|/.*||')

cat > "$CONFIG_DIR/config.yaml" << CONFIG_EOF
# phpBorg Agent Configuration
# Generated: $(date -Iseconds)

server:
  url: ${SERVER_URL}/api
  insecure_skip_verify: false

agent:
  uuid: $AGENT_UUID
  name: $AGENT_NAME
  max_concurrent_tasks: 2

borg_ssh:
  host: $SERVER_HOST
  port: $BORG_SSH_PORT
  user: ${SSH_USER:-phpborg-borg}
  private_key_path: $SSH_KEY_PATH
  backup_path: ${BACKUP_PATH:-/opt/backups/$AGENT_UUID}

polling:
  interval: 5s
  heartbeat_interval: 60s

logging:
  level: info
  file: $LOG_DIR/agent.log

tls:
  cert_file: $CONFIG_DIR/agent.crt
  key_file: $CONFIG_DIR/agent.key
  ca_file: $CONFIG_DIR/ca.crt
CONFIG_EOF

chown -R "$AGENT_USER:$AGENT_USER" "$CONFIG_DIR"
chmod 600 "$CONFIG_DIR/config.yaml"
echo "  Configuration created"

# Download and install agent binary (placeholder - in production would download from server)
echo ""
echo "NOTE: Agent binary installation skipped."
echo "Please copy the phpborg-agent binary to $BIN_PATH"

# Create systemd service
echo ""
echo "Creating systemd service..."
cat > /etc/systemd/system/phpborg-agent.service << SERVICE_EOF
[Unit]
Description=phpBorg Agent
Documentation=https://github.com/your-repo/phpborg
After=network.target

[Service]
Type=simple
User=$AGENT_USER
Group=$AGENT_USER
ExecStart=$BIN_PATH -config $CONFIG_DIR/config.yaml
Restart=always
RestartSec=10

# Security hardening
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ReadWritePaths=$LOG_DIR
ReadWritePaths=$AGENT_HOME

[Install]
WantedBy=multi-user.target
SERVICE_EOF

systemctl daemon-reload
echo "  Systemd service created"

echo ""
echo "=================================="
echo "Installation Complete!"
echo "=================================="
echo ""
echo "Agent UUID: $AGENT_UUID"
echo "Agent Name: $AGENT_NAME"
echo "Config:     $CONFIG_DIR/config.yaml"
echo ""
echo "Next steps:"
echo "1. Copy phpborg-agent binary to $BIN_PATH"
echo "2. Start the agent: systemctl start phpborg-agent"
echo "3. Enable on boot: systemctl enable phpborg-agent"
echo ""
echo "View logs: journalctl -u phpborg-agent -f"
echo ""
INSTALLER_EOF

# Replace placeholders
sed -i "s/AGENT_NAME_PLACEHOLDER/$AGENT_NAME/g" /dev/stdout
sed -i "s/AGENT_UUID_PLACEHOLDER/$AGENT_UUID/g" /dev/stdout
sed -i "s|SERVER_URL_PLACEHOLDER|$SERVER_URL|g" /dev/stdout
sed -i "s/BORG_SSH_PORT_PLACEHOLDER/$BORG_SSH_PORT/g" /dev/stdout
