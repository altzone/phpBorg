#!/bin/bash
# phpBorg Services Restart Script
# Called by systemd phpborg-restart.service after updates to restart all services with new code
# This script runs as root via systemd, so no sudo needed

set -e

PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"
LOG_FILE="/var/log/phpborg/phpborg.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [RESTART_SCRIPT] $1" | tee -a "$LOG_FILE"
}

log "Starting phpBorg services restart..."

# Update systemd service files from repository (may have changed)
log "Syncing systemd service files from repository..."
SYSTEMD_DIR="$PHPBORG_ROOT/systemd"
TEMPLATE_PATH="/opt/newphpborg/phpBorg"

if [ -d "$SYSTEMD_DIR" ]; then
    for svc_file in "$SYSTEMD_DIR"/*.service "$SYSTEMD_DIR"/*.target "$SYSTEMD_DIR"/*.path; do
        if [ -f "$svc_file" ]; then
            filename=$(basename "$svc_file")
            # Copy and replace template path with actual PHPBORG_ROOT
            sed "s|${TEMPLATE_PATH}|${PHPBORG_ROOT}|g" "$svc_file" > "/etc/systemd/system/$filename"
            chmod 644 "/etc/systemd/system/$filename"
            log "Updated: $filename"
        fi
    done
    systemctl daemon-reload
    log "Systemd daemon reloaded"
else
    log "WARNING: Systemd directory not found at $SYSTEMD_DIR"
fi

# Restart all workers first
for i in 1 2 3 4; do
    log "Restarting worker #$i..."
    if systemctl restart "phpborg-worker@$i" 2>&1 | tee -a "$LOG_FILE"; then
        log "Worker #$i restarted successfully"
    else
        log "ERROR: Failed to restart worker #$i"
    fi
done

# Restart agent worker
log "Restarting agent worker..."
if systemctl restart phpborg-agent-worker 2>&1 | tee -a "$LOG_FILE"; then
    log "Agent worker restarted successfully"
else
    log "ERROR: Failed to restart agent worker"
fi

# Restart scheduler last (will reload new code)
log "Restarting scheduler..."
if systemctl restart phpborg-scheduler 2>&1 | tee -a "$LOG_FILE"; then
    log "Scheduler restarted successfully"
else
    log "ERROR: Failed to restart scheduler"
fi

log "phpBorg services restart completed"
