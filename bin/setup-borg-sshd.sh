#!/bin/bash
set -e

# phpBorg Dedicated SSH Server Setup
# Creates a hardened SSH instance exclusively for Borg backup operations
# This is SEPARATE from the system SSH daemon

echo "🔐 phpBorg Dedicated Borg SSH Server Setup"
echo "==========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Configuration
BORG_SSH_PORT=${1:-2222}
BORG_USER="phpborg-borg"
BORG_HOME="/var/lib/phpborg-borg"
SSH_CONFIG_DIR="/etc/phpborg-sshd"
HOST_KEYS_DIR="$SSH_CONFIG_DIR/host_keys"
BACKUPS_DIR="/opt/backups"
SYSTEMD_DIR="/etc/systemd/system"

echo "📋 Configuration:"
echo "  - SSH Port: $BORG_SSH_PORT"
echo "  - Borg User: $BORG_USER"
echo "  - Config Dir: $SSH_CONFIG_DIR"
echo "  - Backups Dir: $BACKUPS_DIR"
echo ""

# Step 1: Create dedicated borg user
echo "1️⃣  Creating dedicated borg user..."
if id "$BORG_USER" &>/dev/null; then
    echo "   ℹ️  User $BORG_USER already exists"
else
    useradd --system \
        --home-dir "$BORG_HOME" \
        --create-home \
        --shell /usr/sbin/nologin \
        --comment "phpBorg SSH Borg Server" \
        "$BORG_USER"
    echo "   ✅ User $BORG_USER created"
fi

# Step 2: Create directories
echo ""
echo "2️⃣  Creating directories..."

mkdir -p "$SSH_CONFIG_DIR"
mkdir -p "$HOST_KEYS_DIR"
mkdir -p "$BACKUPS_DIR"
mkdir -p "$BORG_HOME/.ssh"

# Set ownership and permissions
chown root:root "$SSH_CONFIG_DIR"
chmod 755 "$SSH_CONFIG_DIR"
chown root:root "$HOST_KEYS_DIR"
chmod 700 "$HOST_KEYS_DIR"
chown "$BORG_USER:$BORG_USER" "$BACKUPS_DIR"
chmod 750 "$BACKUPS_DIR"
chown "$BORG_USER:$BORG_USER" "$BORG_HOME/.ssh"
chmod 700 "$BORG_HOME/.ssh"

echo "   ✅ Directories created"

# Step 3: Generate host key (Ed25519 only)
echo ""
echo "3️⃣  Generating host key..."
if [ -f "$HOST_KEYS_DIR/ssh_host_ed25519_key" ]; then
    echo "   ℹ️  Host key already exists"
else
    ssh-keygen -t ed25519 \
        -f "$HOST_KEYS_DIR/ssh_host_ed25519_key" \
        -N "" \
        -C "phpborg-sshd-host-key"
    chmod 600 "$HOST_KEYS_DIR/ssh_host_ed25519_key"
    chmod 644 "$HOST_KEYS_DIR/ssh_host_ed25519_key.pub"
    echo "   ✅ Ed25519 host key generated"
fi

# Step 4: Create hardened sshd_config
echo ""
echo "4️⃣  Creating hardened SSH configuration..."

cat > "$SSH_CONFIG_DIR/sshd_config" << 'SSHD_EOF'
# phpBorg Dedicated SSH Server Configuration
# This instance is ONLY for borg backup operations
# All unnecessary features are disabled

# Network Configuration
Port BORG_SSH_PORT_PLACEHOLDER
Protocol 2
AddressFamily any
ListenAddress 0.0.0.0
ListenAddress ::

# Host Keys - Ed25519 ONLY (most secure, fastest)
HostKey /etc/phpborg-sshd/host_keys/ssh_host_ed25519_key

# Logging
SyslogFacility AUTH
LogLevel INFO

# Authentication - Public Keys ONLY
LoginGraceTime 30
PermitRootLogin no
StrictModes yes
MaxAuthTries 3
MaxSessions 20

PubkeyAuthentication yes
AuthorizedKeysFile /var/lib/phpborg-borg/.ssh/authorized_keys

# Disable ALL other authentication methods
PasswordAuthentication no
PermitEmptyPasswords no
ChallengeResponseAuthentication no
KerberosAuthentication no
GSSAPIAuthentication no
UsePAM no

# Restrict to single user
AllowUsers phpborg-borg

# Disable ALL forwarding and tunneling
PermitTTY no
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
AllowStreamLocalForwarding no
GatewayPorts no
PermitTunnel no
PermitUserEnvironment no
PermitUserRC no

# Modern Cryptography ONLY
# Key Exchange (only curve25519)
KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org

# Ciphers (only AEAD ciphers)
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com

# MACs (only encrypt-then-mac)
MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com

# Host Key Algorithms (Ed25519 only)
HostKeyAlgorithms ssh-ed25519,ssh-ed25519-cert-v01@openssh.com

# Public Key Accepted Algorithms
PubkeyAcceptedAlgorithms ssh-ed25519,ssh-ed25519-cert-v01@openssh.com

# Subsystems - None (borg uses stdin/stdout)
# No Subsystem definitions

# Connection limits
MaxStartups 10:30:60
ClientAliveInterval 300
ClientAliveCountMax 3

# PID file
PidFile /run/phpborg-sshd.pid
SSHD_EOF

# Replace port placeholder
sed -i "s/BORG_SSH_PORT_PLACEHOLDER/$BORG_SSH_PORT/" "$SSH_CONFIG_DIR/sshd_config"

chmod 644 "$SSH_CONFIG_DIR/sshd_config"
echo "   ✅ Hardened sshd_config created"

# Step 5: Create authorized_keys file with proper permissions
echo ""
echo "5️⃣  Creating authorized_keys file..."
touch "$BORG_HOME/.ssh/authorized_keys"
chown "$BORG_USER:$BORG_USER" "$BORG_HOME/.ssh/authorized_keys"
chmod 600 "$BORG_HOME/.ssh/authorized_keys"
echo "   ✅ authorized_keys ready (empty - agents will be added later)"

# Step 6: Create systemd service
echo ""
echo "6️⃣  Creating systemd service..."

cat > "$SYSTEMD_DIR/phpborg-sshd.service" << 'SERVICE_EOF'
[Unit]
Description=phpBorg Dedicated SSH Server for Borg Backups
Documentation=https://github.com/your-repo/phpborg
After=network.target sshd.service
Wants=network-online.target

[Service]
Type=notify
ExecStartPre=/usr/sbin/sshd -t -f /etc/phpborg-sshd/sshd_config
ExecStart=/usr/sbin/sshd -D -f /etc/phpborg-sshd/sshd_config
ExecReload=/bin/kill -HUP $MAINPID
KillMode=process
Restart=on-failure
RestartSec=5

# Security hardening
NoNewPrivileges=no
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes

# Allow write to backups directory
ReadWritePaths=/opt/backups
ReadWritePaths=/var/lib/phpborg-borg
ReadWritePaths=/run

[Install]
WantedBy=multi-user.target
SERVICE_EOF

echo "   ✅ Systemd service created"

# Step 7: Validate configuration
echo ""
echo "7️⃣  Validating SSH configuration..."
if /usr/sbin/sshd -t -f "$SSH_CONFIG_DIR/sshd_config"; then
    echo "   ✅ Configuration is valid"
else
    echo "   ❌ Configuration validation failed!"
    exit 1
fi

# Step 8: Enable and start service
echo ""
echo "8️⃣  Enabling and starting phpborg-sshd..."
systemctl daemon-reload
systemctl enable phpborg-sshd.service
systemctl start phpborg-sshd.service
echo "   ✅ Service enabled and started"

# Step 9: Show status
echo ""
echo "✅ phpBorg Dedicated SSH Server Setup Complete!"
echo ""
echo "📊 Service Status:"
echo "=================="
systemctl status phpborg-sshd.service --no-pager -l || true

echo ""
echo "🔒 Security Features:"
echo "  - Ed25519 keys only (no RSA, no DSA)"
echo "  - Public key authentication only (no passwords)"
echo "  - Modern ciphers only (ChaCha20, AES-GCM)"
echo "  - All forwarding disabled"
echo "  - No PTY allocation"
echo "  - Single user: $BORG_USER"
echo ""
echo "📁 Key Locations:"
echo "  - Config: $SSH_CONFIG_DIR/sshd_config"
echo "  - Host key: $HOST_KEYS_DIR/ssh_host_ed25519_key"
echo "  - Authorized keys: $BORG_HOME/.ssh/authorized_keys"
echo "  - Backups directory: $BACKUPS_DIR"
echo ""
echo "🔧 Firewall:"
echo "  Remember to allow port $BORG_SSH_PORT in your firewall:"
echo "  - ufw allow $BORG_SSH_PORT/tcp"
echo "  - firewall-cmd --add-port=$BORG_SSH_PORT/tcp --permanent"
echo ""
echo "📋 Next Steps:"
echo "  1. Agents will register via the API"
echo "  2. Their SSH keys will be added to authorized_keys"
echo "  3. Each key will have 'borg serve --restrict-to-path' restriction"
echo ""
