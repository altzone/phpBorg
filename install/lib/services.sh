#!/bin/bash
#
# phpBorg Installation - Systemd Services Setup
# Configures phpborg-scheduler and phpborg-worker pool services
#

# Number of worker instances in the pool
WORKER_POOL_SIZE="${WORKER_POOL_SIZE:-4}"

# Template path used in repo systemd files (will be replaced with actual PHPBORG_ROOT)
SYSTEMD_TEMPLATE_PATH="/opt/newphpborg/phpBorg"

#
# Install systemd file from repo with path substitution
#
install_systemd_file() {
    local source_file="$1"
    local dest_file="$2"

    if [ ! -f "${source_file}" ]; then
        log_error "Source file not found: ${source_file}"
        return 1
    fi

    # Copy with path substitution
    sed "s|${SYSTEMD_TEMPLATE_PATH}|${PHPBORG_ROOT}|g" "${source_file}" > "${dest_file}"
    chmod 644 "${dest_file}"

    return 0
}

#
# Create phpborg system user
#

create_phpborg_user() {
    print_section "Creating phpBorg System User"

    # Check if user already exists
    if id "phpborg" &>/dev/null; then
        log_info "User 'phpborg' already exists"
        save_state "create_phpborg_user" "completed"
        return 0
    fi

    # Create system user with home directory
    log_info "Creating system user: phpborg"

    if useradd --system --create-home --home-dir /var/lib/phpborg \
               --shell /bin/bash --comment "phpBorg Worker User" phpborg; then
        log_success "User 'phpborg' created"

        # Set correct ownership of phpBorg installation
        log_info "Setting ownership of ${PHPBORG_ROOT}"
        chown -R phpborg:phpborg "${PHPBORG_ROOT}"

        save_state "create_phpborg_user" "completed"
        return 0
    else
        log_error "Failed to create user 'phpborg'"
        return 1
    fi
}

#
# Configure sudoers for workers
#

setup_sudoers_workers() {
    print_section "Configuring Sudoers for Workers"

    local sudoers_file="/etc/sudoers.d/phpborg-workers"

    backup_file "${sudoers_file}"

    log_info "Creating sudoers file: ${sudoers_file}"

    cat > "${sudoers_file}" <<-'EOF'
# phpBorg Workers - Systemd Service Management
# Allows phpborg user to manage worker services via web interface

# Status checks (read-only)
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl status phpborg-*
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl is-active phpborg-*
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl is-enabled phpborg-*

# Service management (start/stop/restart)
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl start phpborg-scheduler
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl stop phpborg-scheduler
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl restart phpborg-scheduler

phpborg ALL=(ALL) NOPASSWD: /bin/systemctl start phpborg-worker@*
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl stop phpborg-worker@*
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl restart phpborg-worker@*

phpborg ALL=(ALL) NOPASSWD: /bin/systemctl start phpborg-workers.target
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl stop phpborg-workers.target
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl restart phpborg-workers.target

# Log viewing
phpborg ALL=(ALL) NOPASSWD: /bin/journalctl -u phpborg-* -n * --since * --output *
phpborg ALL=(ALL) NOPASSWD: /bin/journalctl -u phpborg-* -n *
phpborg ALL=(ALL) NOPASSWD: /bin/journalctl -u phpborg-*

# Reload systemd daemon
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl daemon-reload

# Regenerate systemd services (for updates)
phpborg ALL=(ALL) NOPASSWD: /opt/newphpborg/phpBorg/bin/regenerate-systemd-services.sh

# Restart services script (for updates)
phpborg ALL=(ALL) NOPASSWD: /bin/bash ${PHPBORG_ROOT}/bin/restart-services.sh

# Agent SSH key management (for AgentManager)
phpborg ALL=(ALL) NOPASSWD: /bin/tee -a /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(ALL) NOPASSWD: /bin/cp * /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(ALL) NOPASSWD: /bin/chown phpborg-borg\:phpborg-borg /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(ALL) NOPASSWD: /bin/chmod 600 /var/lib/phpborg-borg/.ssh/authorized_keys

# Agent backup directory preparation (validates against storage pools)
phpborg ALL=(ALL) NOPASSWD: /opt/newphpborg/phpBorg/bin/prepare-backup-directory.sh *

# Borg SSH server management
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl status phpborg-sshd
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl start phpborg-sshd
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl stop phpborg-sshd
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl restart phpborg-sshd
EOF

    chmod 440 "${sudoers_file}"

    # Validate sudoers syntax
    if visudo -c -f "${sudoers_file}" &>/dev/null; then
        log_success "Sudoers file created and validated"
        save_state "setup_sudoers_workers" "completed"
        return 0
    else
        log_error "Sudoers syntax validation failed"
        rm -f "${sudoers_file}"
        return 1
    fi
}

#
# Configure sudoers for backup server operations
#

setup_sudoers_backup_server() {
    print_section "Configuring Sudoers for Backup Operations"

    local sudoers_file="/etc/sudoers.d/phpborg-backup-server"

    backup_file "${sudoers_file}"

    log_info "Creating sudoers file: ${sudoers_file}"

    cat > "${sudoers_file}" <<-'EOF'
# phpBorg Backup Server - BorgBackup & Instant Recovery
# Allows phpborg user to perform backup and recovery operations

# Borg mount operations (MUST be sudo for allow_other)
phpborg ALL=(ALL) NOPASSWD: /bin/sh -c * borg mount * /tmp/phpborg_instant_recovery/*
phpborg ALL=(ALL) NOPASSWD: /bin/sh -c * borg umount /tmp/phpborg_instant_recovery/*

# Fuse-overlayfs (MUST be sudo to access root-owned Borg mount)
phpborg ALL=(ALL) NOPASSWD: /usr/bin/fuse-overlayfs * /tmp/phpborg_overlay_*
phpborg ALL=(ALL) NOPASSWD: /bin/fusermount -u /tmp/phpborg_overlay_*

# File access on root-owned Borg mount
phpborg ALL=(ALL) NOPASSWD: /bin/ls * /tmp/phpborg_instant_recovery/*
phpborg ALL=(ALL) NOPASSWD: /usr/bin/find /tmp/phpborg_instant_recovery/* *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/test * /tmp/phpborg_instant_recovery/*

# Recursive ownership change (CRITICAL for Docker containers)
phpborg ALL=(ALL) NOPASSWD: /bin/chown * /tmp/phpborg_overlay_*
phpborg ALL=(ALL) NOPASSWD: /bin/chmod * /tmp/phpborg_overlay_*

# Directory operations
phpborg ALL=(ALL) NOPASSWD: /bin/mkdir -p /tmp/phpborg_instant_recovery/*
phpborg ALL=(ALL) NOPASSWD: /bin/mkdir -p /tmp/phpborg_overlay_*
phpborg ALL=(ALL) NOPASSWD: /bin/rm -rf /tmp/phpborg_instant_recovery/*
phpborg ALL=(ALL) NOPASSWD: /bin/rm -rf /tmp/phpborg_overlay_*

# Docker operations for instant recovery
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker run *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker stop *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker rm *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker logs *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker ps *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker images *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker build *
phpborg ALL=(ALL) NOPASSWD: /usr/bin/docker buildx *
EOF

    chmod 440 "${sudoers_file}"

    # Validate sudoers syntax
    if visudo -c -f "${sudoers_file}" &>/dev/null; then
        log_success "Backup server sudoers file created and validated"
        save_state "setup_sudoers_backup_server" "completed"
        return 0
    else
        log_error "Sudoers syntax validation failed"
        rm -f "${sudoers_file}"
        return 1
    fi
}

#
# Configure sudoers for agent worker operations
#

setup_sudoers_agent_worker() {
    print_section "Configuring Sudoers for Agent Worker"

    local sudoers_file="/etc/sudoers.d/phpborg-agent-worker"

    backup_file "${sudoers_file}"

    log_info "Creating sudoers file: ${sudoers_file}"

    cat > "${sudoers_file}" <<-'EOF'
# phpBorg Agent Worker - Agent deployment operations
# Allows phpborg user to manage agent SSH keys and backup directories

# Gestion des répertoires de backup pour les agents
phpborg ALL=(root) NOPASSWD: /bin/mkdir -p /opt/backups/*
phpborg ALL=(root) NOPASSWD: /bin/chown phpborg-borg\:phpborg-borg /opt/backups/*
phpborg ALL=(root) NOPASSWD: /bin/chmod 750 /opt/backups/*

# Gestion du répertoire SSH de phpborg-borg
phpborg ALL=(root) NOPASSWD: /bin/mkdir -p /var/lib/phpborg-borg/.ssh
phpborg ALL=(root) NOPASSWD: /bin/chown phpborg-borg\:phpborg-borg /var/lib/phpborg-borg/.ssh
phpborg ALL=(root) NOPASSWD: /bin/chmod 700 /var/lib/phpborg-borg/.ssh

# Gestion du fichier authorized_keys
phpborg ALL=(root) NOPASSWD: /bin/touch /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(root) NOPASSWD: /bin/cat /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(root) NOPASSWD: /bin/cp /tmp/phpborg_authorized_keys_* /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(root) NOPASSWD: /bin/chown phpborg-borg\:phpborg-borg /var/lib/phpborg-borg/.ssh/authorized_keys
phpborg ALL=(root) NOPASSWD: /bin/chmod 600 /var/lib/phpborg-borg/.ssh/authorized_keys

# Lecture des répertoires (pour vérification)
phpborg ALL=(root) NOPASSWD: /bin/ls /var/lib/phpborg-borg
phpborg ALL=(root) NOPASSWD: /bin/ls /var/lib/phpborg-borg/.ssh
phpborg ALL=(root) NOPASSWD: /bin/ls /opt/backups
phpborg ALL=(root) NOPASSWD: /bin/ls /opt/backups/*
EOF

    chmod 440 "${sudoers_file}"

    # Validate sudoers syntax
    if visudo -c -f "${sudoers_file}" &>/dev/null; then
        log_success "Agent worker sudoers file created and validated"
        save_state "setup_sudoers_agent_worker" "completed"
        return 0
    else
        log_error "Agent worker sudoers syntax validation failed"
        rm -f "${sudoers_file}"
        return 1
    fi
}

#
# Install systemd service files
#

install_scheduler_service() {
    print_section "Installing Scheduler Service"

    local source_file="${PHPBORG_ROOT}/systemd/phpborg-scheduler.service"
    local service_file="/etc/systemd/system/phpborg-scheduler.service"

    backup_file "${service_file}"

    log_info "Installing service from repo: ${source_file}"

    if install_systemd_file "${source_file}" "${service_file}"; then
        log_success "Scheduler service file created"
        save_state "install_scheduler_service" "completed"
        return 0
    else
        log_error "Failed to create scheduler service file"
        return 1
    fi
}

install_worker_service() {
    print_section "Installing Worker Service Template"

    local source_file="${PHPBORG_ROOT}/systemd/phpborg-worker@.service"
    local service_file="/etc/systemd/system/phpborg-worker@.service"

    backup_file "${service_file}"

    log_info "Installing service template from repo: ${source_file}"

    if install_systemd_file "${source_file}" "${service_file}"; then
        log_success "Worker service template created"
        save_state "install_worker_service" "completed"
        return 0
    else
        log_error "Failed to create worker service template"
        return 1
    fi
}

install_workers_target() {
    print_section "Installing Workers Target"

    local target_file="/etc/systemd/system/phpborg-workers.target"

    backup_file "${target_file}"

    log_info "Creating target file: ${target_file}"

    cat > "${target_file}" <<EOF
[Unit]
Description=phpBorg Worker Pool
Documentation=https://github.com/altzone/phpBorg
Wants=$(for i in $(seq 1 ${WORKER_POOL_SIZE}); do echo -n "phpborg-worker@${i}.service "; done)

[Install]
WantedBy=multi-user.target
EOF

    if [ $? -eq 0 ]; then
        log_success "Workers target created (pool size: ${WORKER_POOL_SIZE})"
        save_state "install_workers_target" "completed"
        return 0
    else
        log_error "Failed to create workers target"
        return 1
    fi
}

install_agent_worker_service() {
    print_section "Installing Agent Worker Service"

    local source_file="${PHPBORG_ROOT}/systemd/phpborg-agent-worker.service"
    local service_file="/etc/systemd/system/phpborg-agent-worker.service"

    backup_file "${service_file}"

    log_info "Installing service from repo: ${source_file}"

    if install_systemd_file "${source_file}" "${service_file}"; then
        log_success "Agent worker service file created"
        save_state "install_agent_worker_service" "completed"
        return 0
    else
        log_error "Failed to create agent worker service"
        return 1
    fi
}

install_restart_path_unit() {
    print_section "Installing Restart Path Unit"

    # This path unit monitors the restart flag file and triggers service restart
    # Used by the update system to restart services after code updates

    local path_file="/etc/systemd/system/phpborg-restart.path"
    local service_file="/etc/systemd/system/phpborg-restart.service"

    backup_file "${path_file}"
    backup_file "${service_file}"

    log_info "Creating path unit: ${path_file}"

    cat > "${path_file}" <<EOF
[Unit]
Description=phpBorg Restart Trigger Path
Documentation=https://github.com/altzone/phpBorg

[Path]
PathExists=${PHPBORG_ROOT}/var/restart-needed
Unit=phpborg-restart.service

[Install]
WantedBy=multi-user.target
EOF

    if [ $? -ne 0 ]; then
        log_error "Failed to create restart path unit"
        return 1
    fi

    log_info "Creating restart service: ${service_file}"

    cat > "${service_file}" <<EOF
[Unit]
Description=phpBorg Services Restart
Documentation=https://github.com/altzone/phpBorg
After=network.target

[Service]
Type=oneshot
ExecStart=${PHPBORG_ROOT}/bin/restart-services.sh
# Remove the flag file after restart
ExecStartPost=/bin/rm -f ${PHPBORG_ROOT}/var/restart-needed

[Install]
WantedBy=multi-user.target
EOF

    if [ $? -eq 0 ]; then
        log_success "Restart path unit and service created"
        save_state "install_restart_path_unit" "completed"
        return 0
    else
        log_error "Failed to create restart service"
        return 1
    fi
}

#
# Reload systemd and enable services
#

reload_systemd() {
    print_section "Reloading Systemd Daemon"

    if run_cmd "systemctl daemon-reload"; then
        log_success "Systemd daemon reloaded"
        return 0
    else
        log_error "Failed to reload systemd daemon"
        return 1
    fi
}

enable_services() {
    print_section "Enabling phpBorg Services"

    local errors=0

    # Enable scheduler
    log_info "Enabling phpborg-scheduler.service"
    if run_cmd "systemctl enable phpborg-scheduler.service"; then
        log_success "Scheduler enabled"
    else
        log_error "Failed to enable scheduler"
        errors=$((errors + 1))
    fi

    # Enable worker pool target
    log_info "Enabling phpborg-workers.target"
    if run_cmd "systemctl enable phpborg-workers.target"; then
        log_success "Workers target enabled"
    else
        log_error "Failed to enable workers target"
        errors=$((errors + 1))
    fi

    # Enable individual workers
    for i in $(seq 1 ${WORKER_POOL_SIZE}); do
        log_info "Enabling phpborg-worker@${i}.service"
        if run_cmd "systemctl enable phpborg-worker@${i}.service"; then
            log_success "Worker #${i} enabled"
        else
            log_error "Failed to enable worker #${i}"
            errors=$((errors + 1))
        fi
    done

    # Enable agent worker
    log_info "Enabling phpborg-agent-worker.service"
    if run_cmd "systemctl enable phpborg-agent-worker.service"; then
        log_success "Agent worker enabled"
    else
        log_error "Failed to enable agent worker"
        errors=$((errors + 1))
    fi

    # Enable restart path unit (monitors flag file for auto-restart after updates)
    log_info "Enabling phpborg-restart.path"
    if run_cmd "systemctl enable phpborg-restart.path"; then
        log_success "Restart path unit enabled"
    else
        log_error "Failed to enable restart path unit"
        errors=$((errors + 1))
    fi

    # Start the path unit immediately (it's a monitoring unit)
    log_info "Starting phpborg-restart.path"
    if run_cmd "systemctl start phpborg-restart.path"; then
        log_success "Restart path unit started"
    else
        log_error "Failed to start restart path unit"
        errors=$((errors + 1))
    fi

    if [ ${errors} -eq 0 ]; then
        save_state "enable_services" "completed"
        return 0
    else
        return 1
    fi
}

start_services() {
    print_section "Starting phpBorg Services"

    if [ "${INSTALL_MODE}" = "interactive" ]; then
        if ! confirm "Start phpBorg services now?" "y"; then
            log_info "Services not started (can be started later with: systemctl start phpborg-workers.target phpborg-scheduler)"
            save_state "start_services" "skipped"
            return 0
        fi
    fi

    local errors=0

    # Start scheduler
    log_info "Starting phpborg-scheduler.service"
    if run_cmd "systemctl start phpborg-scheduler.service"; then
        log_success "Scheduler started"
    else
        log_error "Failed to start scheduler"
        errors=$((errors + 1))
    fi

    # Start worker pool (this starts all workers via target)
    log_info "Starting phpborg-workers.target (${WORKER_POOL_SIZE} workers)"
    if run_cmd "systemctl start phpborg-workers.target"; then
        log_success "Worker pool started"
    else
        log_error "Failed to start worker pool"
        errors=$((errors + 1))
    fi

    # Start agent worker
    log_info "Starting phpborg-agent-worker.service"
    if run_cmd "systemctl start phpborg-agent-worker.service"; then
        log_success "Agent worker started"
    else
        log_error "Failed to start agent worker"
        errors=$((errors + 1))
    fi

    # Wait for services to start
    sleep 2

    # Check status
    log_info "Checking service status..."

    if systemctl is-active --quiet phpborg-scheduler; then
        log_success "Scheduler is running"
    else
        log_error "Scheduler failed to start"
        errors=$((errors + 1))
    fi

    local running_workers=0
    for i in $(seq 1 ${WORKER_POOL_SIZE}); do
        if systemctl is-active --quiet phpborg-worker@${i}; then
            running_workers=$((running_workers + 1))
        fi
    done

    if [ ${running_workers} -eq ${WORKER_POOL_SIZE} ]; then
        log_success "All ${WORKER_POOL_SIZE} workers are running"
    else
        log_warn "Only ${running_workers}/${WORKER_POOL_SIZE} workers are running"
        errors=$((errors + 1))
    fi

    if systemctl is-active --quiet phpborg-agent-worker; then
        log_success "Agent worker is running"
    else
        log_error "Agent worker failed to start"
        errors=$((errors + 1))
    fi

    if [ ${errors} -eq 0 ]; then
        save_state "start_services" "completed"
        return 0
    else
        return 1
    fi
}

#
# Redis service setup
#

setup_redis() {
    print_section "Configuring Redis"

    # Start Redis
    log_info "Starting Redis service"
    if run_cmd "systemctl start redis-server || systemctl start redis"; then
        log_success "Redis started"
    else
        log_error "Failed to start Redis"
        return 1
    fi

    # Enable Redis at boot
    log_info "Enabling Redis at boot"
    if run_cmd "systemctl enable redis-server || systemctl enable redis"; then
        log_success "Redis enabled at boot"
    else
        log_warn "Failed to enable Redis at boot"
    fi

    # Test Redis connection
    if redis-cli ping &>/dev/null; then
        log_success "Redis is responding"
        save_state "setup_redis" "completed"
        return 0
    else
        log_error "Redis is not responding"
        return 1
    fi
}

#
# Certificate Authority setup (for mTLS agent authentication)
#

setup_certificate_authority() {
    print_section "Setting up Certificate Authority for Agent mTLS"

    local ca_dir="/etc/phpborg/certs"
    local ca_key="${ca_dir}/ca.key"
    local ca_cert="${ca_dir}/ca.crt"
    local agents_dir="${ca_dir}/agents"

    # Check if CA already exists
    if [ -f "${ca_key}" ] && [ -f "${ca_cert}" ]; then
        log_info "Certificate Authority already initialized"
        save_state "setup_certificate_authority" "completed"
        return 0
    fi

    # Create directories
    log_info "Creating certificate directories..."
    mkdir -p "${ca_dir}"
    mkdir -p "${agents_dir}"
    chmod 700 "${ca_dir}"
    chmod 700 "${agents_dir}"

    # Generate CA private key (ECDSA P-256)
    log_info "Generating CA private key..."
    if ! openssl ecparam -genkey -name prime256v1 -out "${ca_key}" 2>/dev/null; then
        log_error "Failed to generate CA private key"
        return 1
    fi
    chmod 600 "${ca_key}"

    # Generate self-signed CA certificate (valid 10 years)
    log_info "Generating CA certificate..."
    if ! openssl req -new -x509 \
        -key "${ca_key}" \
        -out "${ca_cert}" \
        -days 3650 \
        -subj "/CN=phpBorg Internal CA/O=phpBorg/C=FR" \
        -sha256 2>/dev/null; then
        log_error "Failed to generate CA certificate"
        return 1
    fi
    chmod 644 "${ca_cert}"

    # Set ownership
    chown -R phpborg:phpborg "${ca_dir}"

    # Verify CA was created correctly
    if openssl x509 -in "${ca_cert}" -noout -text &>/dev/null; then
        local expiry=$(openssl x509 -in "${ca_cert}" -noout -enddate | cut -d= -f2)
        log_success "Certificate Authority initialized"
        log_info "CA valid until: ${expiry}"
        save_state "setup_certificate_authority" "completed"
        return 0
    else
        log_error "CA certificate verification failed"
        return 1
    fi
}

#
# Dedicated Borg SSH Server setup (for remote agents)
#

setup_borg_sshd() {
    print_section "Setting up Dedicated Borg SSH Server"

    local setup_script="${PHPBORG_ROOT}/bin/setup-borg-sshd.sh"

    # Check if script exists
    if [ ! -f "${setup_script}" ]; then
        log_error "Borg SSH setup script not found: ${setup_script}"
        return 1
    fi

    # Make script executable
    chmod +x "${setup_script}"

    # Run the setup script
    log_info "Running Borg SSH server setup..."
    if bash "${setup_script}"; then
        log_success "Borg SSH server configured"

        # Add phpborg user to phpborg-borg group for authorized_keys management
        if getent group phpborg-borg > /dev/null 2>&1; then
            usermod -aG phpborg-borg phpborg 2>/dev/null || true
            log_info "Added phpborg to phpborg-borg group"
        fi

        save_state "setup_borg_sshd" "completed"
        return 0
    else
        log_error "Borg SSH server setup failed"
        return 1
    fi
}

#
# Docker service setup
#

setup_docker() {
    print_section "Configuring Docker"

    # Start Docker
    log_info "Starting Docker service"
    if run_cmd "systemctl start docker"; then
        log_success "Docker started"
    else
        log_error "Failed to start Docker"
        return 1
    fi

    # Enable Docker at boot
    log_info "Enabling Docker at boot"
    if run_cmd "systemctl enable docker"; then
        log_success "Docker enabled at boot"
    else
        log_warn "Failed to enable Docker at boot"
    fi

    # Add phpborg user to docker group
    log_info "Adding phpborg user to docker group"
    if usermod -aG docker phpborg; then
        log_success "phpborg added to docker group"
    else
        log_warn "Failed to add phpborg to docker group"
    fi

    # Test Docker
    if docker ps &>/dev/null; then
        log_success "Docker is running"
        save_state "setup_docker" "completed"
        return 0
    else
        log_error "Docker is not responding"
        return 1
    fi
}

#
# Create log directories
#

create_log_directories() {
    print_section "Creating Log Directories"

    local log_dirs=(
        "${PHPBORG_ROOT}/var/log"
        "${PHPBORG_ROOT}/logs"
        "/var/log/phpborg"
    )

    for dir in "${log_dirs[@]}"; do
        if [ ! -d "${dir}" ]; then
            log_info "Creating directory: ${dir}"
            mkdir -p "${dir}"
            chown phpborg:phpborg "${dir}"
            chmod 755 "${dir}"
        else
            log_info "Directory already exists: ${dir}"
        fi
    done

    # Create main log file with correct permissions
    local main_log="/var/log/phpborg/phpborg.log"
    if [ ! -f "${main_log}" ]; then
        log_info "Creating log file: ${main_log}"
        touch "${main_log}"
        chown phpborg:phpborg "${main_log}"
        chmod 644 "${main_log}"
    fi

    log_success "Log directories created"
    save_state "create_log_directories" "completed"
    return 0
}

#
# Create phpBorg internal backup directory
#

create_phpborg_backup_directory() {
    print_section "Creating phpBorg Internal Backup Directory"

    local backup_dir="/var/lib/phpborg/backups"

    if [ ! -d "${backup_dir}" ]; then
        log_info "Creating directory: ${backup_dir}"
        mkdir -p "${backup_dir}"
        chown phpborg:phpborg "${backup_dir}"
        chmod 755 "${backup_dir}"
        log_success "Internal backup directory created"
    else
        log_info "Directory already exists: ${backup_dir}"
        # Ensure correct ownership
        chown phpborg:phpborg "${backup_dir}"
    fi

    save_state "create_phpborg_backup_directory" "completed"
    return 0
}

#
# Create runtime directories
#

create_runtime_directories() {
    print_section "Creating Runtime Directories"

    local runtime_dirs=(
        "/tmp/phpborg_instant_recovery"
        "/tmp/phpborg_overlay"
        "${PHPBORG_ROOT}/var/cache"
        "${PHPBORG_ROOT}/var/sessions"
    )

    for dir in "${runtime_dirs[@]}"; do
        if [ ! -d "${dir}" ]; then
            log_info "Creating directory: ${dir}"
            mkdir -p "${dir}"
            chown phpborg:phpborg "${dir}"
            chmod 755 "${dir}"
        else
            log_info "Directory already exists: ${dir}"
        fi
    done

    log_success "Runtime directories created"
    save_state "create_runtime_directories" "completed"
    return 0
}

#
# Main services setup orchestrator
#

setup_services() {
    print_header "Services Setup"

    local errors=0

    # Create phpborg user
    if ! is_step_completed "create_phpborg_user"; then
        create_phpborg_user || errors=$((errors + 1))
    else
        log_info "phpborg user already created (skipped)"
    fi

    # Setup sudoers
    if ! is_step_completed "setup_sudoers_workers"; then
        setup_sudoers_workers || errors=$((errors + 1))
    else
        log_info "Workers sudoers already configured (skipped)"
    fi

    if ! is_step_completed "setup_sudoers_backup_server"; then
        setup_sudoers_backup_server || errors=$((errors + 1))
    else
        log_info "Backup server sudoers already configured (skipped)"
    fi

    if ! is_step_completed "setup_sudoers_agent_worker"; then
        setup_sudoers_agent_worker || errors=$((errors + 1))
    else
        log_info "Agent worker sudoers already configured (skipped)"
    fi

    # Create directories
    if ! is_step_completed "create_log_directories"; then
        create_log_directories || errors=$((errors + 1))
    else
        log_info "Log directories already created (skipped)"
    fi

    if ! is_step_completed "create_runtime_directories"; then
        create_runtime_directories || errors=$((errors + 1))
    else
        log_info "Runtime directories already created (skipped)"
    fi

    # Create phpBorg internal backup directory (owned by phpborg, separate from /opt/backups)
    if ! is_step_completed "create_phpborg_backup_directory"; then
        create_phpborg_backup_directory || errors=$((errors + 1))
    else
        log_info "phpBorg backup directory already created (skipped)"
    fi

    # Install systemd service files
    if ! is_step_completed "install_scheduler_service"; then
        install_scheduler_service || errors=$((errors + 1))
    else
        log_info "Scheduler service already installed (skipped)"
    fi

    if ! is_step_completed "install_worker_service"; then
        install_worker_service || errors=$((errors + 1))
    else
        log_info "Worker service already installed (skipped)"
    fi

    if ! is_step_completed "install_workers_target"; then
        install_workers_target || errors=$((errors + 1))
    else
        log_info "Workers target already installed (skipped)"
    fi

    if ! is_step_completed "install_agent_worker_service"; then
        install_agent_worker_service || errors=$((errors + 1))
    else
        log_info "Agent worker service already installed (skipped)"
    fi

    # Install restart path unit (for auto-restart after updates)
    if ! is_step_completed "install_restart_path_unit"; then
        install_restart_path_unit || errors=$((errors + 1))
    else
        log_info "Restart path unit already installed (skipped)"
    fi

    # Reload systemd
    reload_systemd || errors=$((errors + 1))

    # Enable services
    if ! is_step_completed "enable_services"; then
        enable_services || errors=$((errors + 1))
    else
        log_info "Services already enabled (skipped)"
    fi

    # Setup Redis
    if ! is_step_completed "setup_redis"; then
        setup_redis || errors=$((errors + 1))
    else
        log_info "Redis already configured (skipped)"
    fi

    # Setup Docker
    if ! is_step_completed "setup_docker"; then
        setup_docker || errors=$((errors + 1))
    else
        log_info "Docker already configured (skipped)"
    fi

    # Setup dedicated Borg SSH server for remote agents
    if ! is_step_completed "setup_borg_sshd"; then
        setup_borg_sshd || errors=$((errors + 1))
    else
        log_info "Borg SSH server already configured (skipped)"
    fi

    # Setup Certificate Authority for agent mTLS
    if ! is_step_completed "setup_certificate_authority"; then
        setup_certificate_authority || errors=$((errors + 1))
    else
        log_info "Certificate Authority already configured (skipped)"
    fi

    # Start services
    if ! is_step_completed "start_services"; then
        start_services || errors=$((errors + 1))
    else
        log_info "Services already started (skipped)"
    fi

    if [ ${errors} -eq 0 ]; then
        log_success "Services setup completed successfully"
        save_state "services_setup" "completed"
        return 0
    else
        log_error "Services setup completed with ${errors} error(s)"
        return 1
    fi
}
