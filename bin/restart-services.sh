#!/bin/bash
# phpBorg Services Restart Script
# Called by scheduler after updates to restart all services with new code

set -e

PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"
LOG_FILE="/var/log/phpborg/phpborg.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [RESTART_SCRIPT] $1" | tee -a "$LOG_FILE"
}

log "Starting phpBorg services restart..."

# Restart all workers first
for i in 1 2 3 4; do
    log "Restarting worker #$i..."
    if sudo systemctl restart "phpborg-worker@$i" 2>&1 | tee -a "$LOG_FILE"; then
        log "Worker #$i restarted successfully"
    else
        log "ERROR: Failed to restart worker #$i"
    fi
done

# Restart scheduler last (will reload new code)
log "Restarting scheduler..."
if sudo systemctl restart phpborg-scheduler 2>&1 | tee -a "$LOG_FILE"; then
    log "Scheduler restarted successfully"
else
    log "ERROR: Failed to restart scheduler"
fi

log "phpBorg services restart completed"
