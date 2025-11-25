#!/bin/bash
#
# phpBorg Installation - Systemd Services Setup
# Configures phpborg-scheduler and phpborg-worker pool services
#

# Number of worker instances in the pool
WORKER_POOL_SIZE="${WORKER_POOL_SIZE:-4}"

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
# Install systemd service files
#

install_scheduler_service() {
    print_section "Installing Scheduler Service"

    local service_file="/etc/systemd/system/phpborg-scheduler.service"

    backup_file "${service_file}"

    log_info "Creating service file: ${service_file}"

    cat > "${service_file}" <<EOF
[Unit]
Description=phpBorg Scheduler Daemon
Documentation=https://github.com/altzone/phpBorg
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=phpborg
Group=phpborg
WorkingDirectory=${PHPBORG_ROOT}

# Load environment variables from .env
EnvironmentFile=${PHPBORG_ROOT}/.env

# Environment
Environment="PHP_ENV=production"

# Main command
ExecStart=/usr/bin/php8.3 ${PHPBORG_ROOT}/bin/phpborg scheduler:start

# Restart policy
Restart=always
RestartSec=10s

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phpborg-scheduler

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${PHPBORG_ROOT} /opt/backups /var/log/phpborg /tmp

# Resource limits
LimitNOFILE=65536
TimeoutStopSec=30s

[Install]
WantedBy=multi-user.target
EOF

    if [ $? -eq 0 ]; then
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

    local service_file="/etc/systemd/system/phpborg-worker@.service"

    backup_file "${service_file}"

    log_info "Creating service template: ${service_file}"

    cat > "${service_file}" <<EOF
[Unit]
Description=phpBorg Worker #%i
Documentation=https://github.com/altzone/phpBorg
After=network.target mariadb.service redis.service
PartOf=phpborg-workers.target

[Service]
Type=simple
User=phpborg
Group=phpborg
WorkingDirectory=${PHPBORG_ROOT}

# Load environment variables from .env
EnvironmentFile=${PHPBORG_ROOT}/.env

# Environment
Environment="PHP_ENV=production"
Environment="WORKER_ID=%i"

# Main command
ExecStart=/usr/bin/php8.3 ${PHPBORG_ROOT}/bin/phpborg worker:start --queue=default --worker-id=%i

# Restart policy
Restart=always
RestartSec=5s

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phpborg-worker@%i

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${PHPBORG_ROOT} /opt/backups /var/log/phpborg /tmp

# Resource limits
LimitNOFILE=65536
TimeoutStopSec=30s

[Install]
WantedBy=phpborg-workers.target
EOF

    if [ $? -eq 0 ]; then
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
