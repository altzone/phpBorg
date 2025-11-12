#!/bin/bash
set -e

# phpBorg Services Installation Script
# Installs systemd services for scheduler and worker pool

echo "📦 phpBorg Services Installation"
echo "================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Directories
PHPBORG_DIR="/opt/newphpborg/phpBorg"
SYSTEMD_DIR="/etc/systemd/system"
SUDOERS_FILE="/etc/sudoers.d/phpborg-workers"

# Number of worker instances (default: 4)
WORKER_INSTANCES=${1:-4}

echo "📋 Configuration:"
echo "  - phpBorg directory: $PHPBORG_DIR"
echo "  - Worker instances: $WORKER_INSTANCES"
echo ""

# Install sudoers file
echo "1️⃣  Installing sudoers permissions..."
if [ -f "$PHPBORG_DIR/docs/sudoers-phpborg-workers" ]; then
    cp "$PHPBORG_DIR/docs/sudoers-phpborg-workers" "$SUDOERS_FILE"
    chmod 440 "$SUDOERS_FILE"
    echo "   ✅ Sudoers file installed"
else
    echo "   ⚠️  Sudoers file not found, skipping"
fi

# Install systemd service files
echo ""
echo "2️⃣  Installing systemd service files..."

# Scheduler service
cp "$PHPBORG_DIR/systemd/phpborg-scheduler.service" "$SYSTEMD_DIR/"
echo "   ✅ phpborg-scheduler.service"

# Worker template
cp "$PHPBORG_DIR/systemd/phpborg-worker@.service" "$SYSTEMD_DIR/"
echo "   ✅ phpborg-worker@.service"

# Workers target
cp "$PHPBORG_DIR/systemd/phpborg-workers.target" "$SYSTEMD_DIR/"
echo "   ✅ phpborg-workers.target"

# Reload systemd
echo ""
echo "3️⃣  Reloading systemd daemon..."
systemctl daemon-reload
echo "   ✅ Systemd reloaded"

# Enable services
echo ""
echo "4️⃣  Enabling services..."
systemctl enable phpborg-scheduler.service
echo "   ✅ Scheduler enabled"

for i in $(seq 1 $WORKER_INSTANCES); do
    systemctl enable phpborg-worker@$i.service
    echo "   ✅ Worker #$i enabled"
done

systemctl enable phpborg-workers.target
echo "   ✅ Workers target enabled"

# Start services
echo ""
echo "5️⃣  Starting services..."
systemctl start phpborg-scheduler.service
echo "   ✅ Scheduler started"

for i in $(seq 1 $WORKER_INSTANCES); do
    systemctl start phpborg-worker@$i.service
    echo "   ✅ Worker #$i started"
done

# Show status
echo ""
echo "✅ Installation complete!"
echo ""
echo "📊 Service Status:"
echo "=================="
systemctl status phpborg-scheduler.service --no-pager -l || true
echo ""
for i in $(seq 1 $WORKER_INSTANCES); do
    systemctl status phpborg-worker@$i.service --no-pager -l || true
    echo ""
done

echo ""
echo "🔧 Useful commands:"
echo "==================="
echo "  View scheduler logs:  journalctl -u phpborg-scheduler -f"
echo "  View worker #1 logs:  journalctl -u phpborg-worker@1 -f"
echo "  View all logs:        journalctl -u phpborg-* -f"
echo ""
echo "  Restart scheduler:    systemctl restart phpborg-scheduler"
echo "  Restart worker #1:    systemctl restart phpborg-worker@1"
echo "  Restart all workers:  systemctl restart phpborg-worker@{1..$WORKER_INSTANCES}"
echo ""
echo "  Stop all services:    systemctl stop phpborg-scheduler phpborg-worker@{1..$WORKER_INSTANCES}"
echo ""
