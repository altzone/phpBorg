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

# Update all sudoers files from templates
log "Updating sudoers rules from templates..."

update_sudoers_from_template() {
    local name="$1"
    local sudoers_file="/etc/sudoers.d/phpborg-${name}"
    local sudoers_template="${PHPBORG_ROOT}/install/templates/sudoers-${name}.conf"

    if [ ! -f "$sudoers_template" ]; then
        log "WARNING: Sudoers template not found: $sudoers_template"
        return 1
    fi

    # Replace PHPBORG_ROOT placeholder in template (only workers template has it)
    local sudoers_content
    sudoers_content=$(sed "s|/opt/newphpborg/phpBorg|${PHPBORG_ROOT}|g" "$sudoers_template")

    # Check if current file differs from template
    local current_content=""
    if [ -f "$sudoers_file" ]; then
        current_content=$(cat "$sudoers_file")
    fi

    if [ "$sudoers_content" != "$current_content" ]; then
        log "Updating ${name} sudoers rules..."

        # Write to temp file first for validation
        local temp_sudoers
        temp_sudoers=$(mktemp)
        echo "$sudoers_content" > "$temp_sudoers"

        # Validate sudoers syntax
        if visudo -c -f "$temp_sudoers" &>/dev/null; then
            cp "$temp_sudoers" "$sudoers_file"
            chmod 440 "$sudoers_file"
            log "${name} sudoers rules updated successfully"
        else
            log "ERROR: ${name} sudoers template syntax validation failed"
        fi
        rm -f "$temp_sudoers"
    else
        log "${name} sudoers rules already up to date"
    fi
}

# Update all sudoers files
update_sudoers_from_template "workers"
update_sudoers_from_template "backup-server"
update_sudoers_from_template "agent-worker"
update_sudoers_from_template "www-data"

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
