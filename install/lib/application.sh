#!/bin/bash
#
# phpBorg Installation - Application Setup
# Installs Composer dependencies, configures application, sets permissions
#

#
# Install Composer dependencies
#

install_composer_dependencies() {
    print_section "Installing Composer Dependencies"

    cd "${PHPBORG_ROOT}" || {
        log_error "Failed to change directory to ${PHPBORG_ROOT}"
        return 1
    }

    # Check if composer.json exists
    if [ ! -f "composer.json" ]; then
        log_error "composer.json not found in ${PHPBORG_ROOT}"
        return 1
    fi

    # Check if vendor already exists
    if [ -d "vendor" ] && [ "${INSTALL_MODE}" = "interactive" ]; then
        if ! confirm "Vendor directory exists. Reinstall dependencies?" "n"; then
            log_info "Skipping composer install"
            save_state "install_composer_dependencies" "completed"
            return 0
        fi
    fi

    # Add git safe directory exception for phpborg user
    su - phpborg -c "git config --global --add safe.directory ${PHPBORG_ROOT}" >> "${INSTALL_LOG}" 2>&1

    # Create composer cache directory
    mkdir -p /var/lib/phpborg/.cache/composer
    chown -R phpborg:phpborg /var/lib/phpborg/.cache

    # Ensure phpborg can write to application directory (for composer.lock)
    chown -R phpborg:phpborg ${PHPBORG_ROOT}

    # Run composer install
    log_info "Running composer install (this may take a few minutes)..."

    # Force PHP 8.3 for composer by setting COMPOSER_PHP_PATH
    if su - phpborg -c "cd ${PHPBORG_ROOT} && php8.3 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction" >> "${INSTALL_LOG}" 2>&1; then
        log_success "Composer dependencies installed"
        save_state "install_composer_dependencies" "completed"
        return 0
    else
        log_error "Failed to install composer dependencies"
        return 1
    fi
}

#
# Generate .env file
#

generate_env_file() {
    print_section "Generating .env Configuration"

    local env_file="${PHPBORG_ROOT}/.env"
    local template_file="${PHPBORG_ROOT}/install/templates/.env.template"

    # Check if template exists
    if [ ! -f "${template_file}" ]; then
        log_error "Template file not found: ${template_file}"
        return 1
    fi

    # Backup existing .env
    if [ -f "${env_file}" ]; then
        backup_file "${env_file}"
        log_warn "Existing .env file backed up"

        if [ "${INSTALL_MODE}" = "interactive" ]; then
            if ! confirm "Overwrite existing .env file?" "n"; then
                log_info "Keeping existing .env file"
                save_state "generate_env_file" "completed"
                return 0
            fi
        fi
    fi

    # Generate secrets
    log_info "Generating application secrets..."

    local app_secret=$(openssl rand -hex 32)
    local jwt_secret=$(openssl rand -hex 32)
    local borg_passphrase=$(openssl rand -base64 32 | tr -d "=+/")

    # Copy template and replace placeholders
    log_info "Creating .env file from template"

    sed -e "s|__INSTALL_DATE__|$(date '+%Y-%m-%d %H:%M:%S')|g" \
        -e "s|__APP_SECRET__|${app_secret}|g" \
        -e "s|__JWT_SECRET__|${jwt_secret}|g" \
        -e "s|__BORG_PASSPHRASE__|${borg_passphrase}|g" \
        -e "s|__DB_NAME__|${DB_NAME}|g" \
        -e "s|__DB_USER__|${DB_USER}|g" \
        -e "s|__DB_PASSWORD__|${DB_PASSWORD}|g" \
        "${template_file}" > "${env_file}"

    if [ $? -eq 0 ]; then
        chmod 600 "${env_file}"
        chown phpborg:phpborg "${env_file}"
        log_success ".env file created"
        save_state "generate_env_file" "completed"
        return 0
    else
        log_error "Failed to create .env file"
        return 1
    fi
}

#
# Set file permissions
#

set_permissions() {
    print_section "Setting File Permissions"

    cd "${PHPBORG_ROOT}" || return 1

    # Set ownership
    log_info "Setting ownership to phpborg:phpborg"
    chown -R phpborg:phpborg "${PHPBORG_ROOT}"

    # Set directory permissions
    log_info "Setting directory permissions"
    find "${PHPBORG_ROOT}" -type d -exec chmod 755 {} \;

    # Set file permissions
    log_info "Setting file permissions"
    find "${PHPBORG_ROOT}" -type f -exec chmod 644 {} \;

    # Make scripts executable
    log_info "Making scripts executable"
    chmod +x "${PHPBORG_ROOT}/bin/console"
    if [ -d "${PHPBORG_ROOT}/bin" ]; then
        find "${PHPBORG_ROOT}/bin" -type f -name "*.sh" -exec chmod +x {} \;
    fi

    # Secure sensitive files
    log_info "Securing sensitive files"
    chmod 600 "${PHPBORG_ROOT}/.env"
    if [ -f "${PHPBORG_ROOT}/.install-credentials" ]; then
        chmod 600 "${PHPBORG_ROOT}/.install-credentials"
    fi

    # Writable directories
    log_info "Setting writable directories"
    local writable_dirs=(
        "var"
        "var/cache"
        "var/log"
        "var/sessions"
        "logs"
        "public/uploads"
    )

    for dir in "${writable_dirs[@]}"; do
        if [ -d "${PHPBORG_ROOT}/${dir}" ]; then
            chmod 775 "${PHPBORG_ROOT}/${dir}"
        else
            mkdir -p "${PHPBORG_ROOT}/${dir}"
            chmod 775 "${PHPBORG_ROOT}/${dir}"
            chown phpborg:phpborg "${PHPBORG_ROOT}/${dir}"
        fi
    done

    log_success "File permissions set"
    save_state "set_permissions" "completed"
    return 0
}

#
# Setup SSH keys for Borg
#

setup_ssh_keys() {
    print_section "Setting up SSH Keys for BorgBackup"

    local ssh_dir="/var/lib/phpborg/.ssh"
    local key_file="${ssh_dir}/id_ed25519"

    # Create SSH directory
    if [ ! -d "${ssh_dir}" ]; then
        log_info "Creating SSH directory: ${ssh_dir}"
        mkdir -p "${ssh_dir}"
        chown phpborg:phpborg "${ssh_dir}"
        chmod 700 "${ssh_dir}"
    fi

    # Check if key already exists
    if [ -f "${key_file}" ]; then
        log_info "SSH key already exists"

        if [ "${INSTALL_MODE}" = "interactive" ]; then
            if ! confirm "Generate new SSH key?" "n"; then
                log_info "Keeping existing SSH key"
                save_state "setup_ssh_keys" "completed"
                return 0
            fi
        else
            log_info "Keeping existing SSH key (auto mode)"
            save_state "setup_ssh_keys" "completed"
            return 0
        fi
    fi

    # Generate SSH key
    log_info "Generating ED25519 SSH key pair"

    # Generate key as root, then fix ownership
    if ssh-keygen -t ed25519 -f "${key_file}" -N '' -C 'phpborg@backup-server' >> "${INSTALL_LOG}" 2>&1; then
        chown phpborg:phpborg "${key_file}" "${key_file}.pub"
        chmod 600 "${key_file}"
        chmod 644 "${key_file}.pub"
        log_success "SSH key pair generated"
        log_info "Public key: ${key_file}.pub"

        # Display public key
        echo ""
        echo -e "${CYAN}════════════════════════════════════════════════════════${NC}"
        echo -e "${CYAN}SSH Public Key (add this to remote servers):${NC}"
        echo -e "${CYAN}════════════════════════════════════════════════════════${NC}"
        cat "${key_file}.pub"
        echo -e "${CYAN}════════════════════════════════════════════════════════${NC}"
        echo ""

        save_state "setup_ssh_keys" "completed"
        return 0
    else
        log_error "Failed to generate SSH key"
        return 1
    fi
}

#
# Run database migrations (if any)
#

run_migrations() {
    print_section "Running Database Migrations"

    cd "${PHPBORG_ROOT}" || return 1

    # Check if migration command exists
    if [ ! -f "bin/console" ]; then
        log_warn "bin/console not found, skipping migrations"
        save_state "run_migrations" "completed"
        return 0
    fi

    log_info "Running migrations..."

    if su - phpborg -c "cd ${PHPBORG_ROOT} && php8.3 bin/phpborg db:migrate --no-interaction" >> "${INSTALL_LOG}" 2>&1; then
        log_success "Migrations completed"
        save_state "run_migrations" "completed"
        return 0
    else
        log_warn "Migrations failed or not applicable"
        save_state "run_migrations" "completed"
        return 0
    fi
}

#
# Clear application cache
#

clear_cache() {
    print_section "Clearing Application Cache"

    cd "${PHPBORG_ROOT}" || return 1

    # Remove cache directories
    log_info "Removing cache files"

    local cache_dirs=(
        "var/cache"
        "var/sessions"
    )

    for dir in "${cache_dirs[@]}"; do
        if [ -d "${PHPBORG_ROOT}/${dir}" ]; then
            rm -rf "${PHPBORG_ROOT}/${dir}"/*
            log_info "Cleared: ${dir}"
        fi
    done

    # Recreate cache directories with correct permissions
    for dir in "${cache_dirs[@]}"; do
        mkdir -p "${PHPBORG_ROOT}/${dir}"
        chown phpborg:phpborg "${PHPBORG_ROOT}/${dir}"
        chmod 775 "${PHPBORG_ROOT}/${dir}"
    done

    log_success "Cache cleared"
    save_state "clear_cache" "completed"
    return 0
}

#
# Generate application key/secrets
#

generate_app_secrets() {
    print_section "Generating Application Secrets"

    cd "${PHPBORG_ROOT}" || return 1

    # Check if console command exists
    if [ ! -f "bin/console" ]; then
        log_warn "bin/console not found, skipping secret generation"
        save_state "generate_app_secrets" "completed"
        return 0
    fi

    # Generate secrets
    log_info "Generating application secrets..."

    if su - phpborg -c "cd ${PHPBORG_ROOT} && php8.3 bin/phpborg app:generate-secrets" >> "${INSTALL_LOG}" 2>&1; then
        log_success "Application secrets generated"
    else
        log_debug "No secret generation command or already generated"
    fi

    save_state "generate_app_secrets" "completed"
    return 0
}

#
# Setup log rotation
#

setup_log_rotation() {
    print_section "Setting up Log Rotation"

    local logrotate_file="/etc/logrotate.d/phpborg"

    backup_file "${logrotate_file}"

    log_info "Creating logrotate configuration: ${logrotate_file}"

    cat > "${logrotate_file}" <<EOF
/var/log/phpborg/*.log ${PHPBORG_ROOT}/var/log/*.log ${PHPBORG_ROOT}/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0644 phpborg phpborg
    sharedscripts
    postrotate
        systemctl reload phpborg-scheduler phpborg-worker@* 2>/dev/null || true
    endscript
}
EOF

    if [ $? -eq 0 ]; then
        log_success "Log rotation configured"
        save_state "setup_log_rotation" "completed"
        return 0
    else
        log_error "Failed to setup log rotation"
        return 1
    fi
}

#
# Create initial storage pool directory
#

create_storage_pool() {
    print_section "Creating Default Storage Pool"

    local storage_dir="${STORAGE_POOL_PATH:-/opt/backups}"

    if [ -d "${storage_dir}" ]; then
        log_info "Storage pool already exists: ${storage_dir}"
        save_state "create_storage_pool" "completed"
        return 0
    fi

    # Prompt for storage location in interactive mode
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        prompt "Storage pool path" "/opt/backups" storage_dir
    fi

    log_info "Creating storage pool directory: ${storage_dir}"

    if mkdir -p "${storage_dir}"; then
        chown phpborg:phpborg "${storage_dir}"
        chmod 755 "${storage_dir}"
        log_success "Storage pool created: ${storage_dir}"

        # Export for use by other scripts
        export STORAGE_POOL_PATH="${storage_dir}"

        save_state "create_storage_pool" "completed" "{\"path\":\"${storage_dir}\"}"
        return 0
    else
        log_error "Failed to create storage pool directory"
        return 1
    fi
}

#
# Optimize PHP-FPM configuration
#

optimize_php_fpm() {
    print_section "Optimizing PHP-FPM Configuration"

    # Detect PHP version for config path
    local php_version=$(${PHP_BINARY} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    local pool_file="/etc/php/${php_version}/fpm/pool.d/phpborg.conf"

    # Check if PHP-FPM config directory exists
    if [ ! -d "/etc/php/${php_version}/fpm/pool.d" ]; then
        log_warn "PHP-FPM config directory not found, skipping optimization"
        save_state "optimize_php_fpm" "completed"
        return 0
    fi

    backup_file "${pool_file}"

    # Remove old socket files to prevent conflicts
    rm -f /run/php/phpborg-*-fpm.sock

    log_info "Creating PHP-FPM pool: ${pool_file}"

    cat > "${pool_file}" <<EOF
[phpborg]
user = phpborg
group = phpborg
listen = /run/php/phpborg-${php_version}-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

php_admin_value[error_log] = /var/log/phpborg/php-fpm.log
php_admin_flag[log_errors] = on

php_value[session.save_handler] = files
php_value[session.save_path] = ${PHPBORG_ROOT}/var/sessions

request_terminate_timeout = 300s
request_slowlog_timeout = 10s
slowlog = /var/log/phpborg/php-fpm-slow.log

catch_workers_output = yes
decorate_workers_output = no
EOF

    if [ $? -eq 0 ]; then
        log_success "PHP-FPM pool created"

        # Restart PHP-FPM
        log_info "Restarting PHP-FPM"
        if systemctl restart "php${php_version}-fpm"; then
            log_success "PHP-FPM restarted"
        else
            log_warn "Failed to restart PHP-FPM"
        fi

        save_state "optimize_php_fpm" "completed"
        return 0
    else
        log_error "Failed to create PHP-FPM pool"
        return 1
    fi
}

#
# Main application setup orchestrator
#

setup_application() {
    print_header "Application Setup"

    local errors=0

    # Create phpborg user FIRST (required for all subsequent operations)
    if ! is_step_completed "create_phpborg_user"; then
        print_section "Creating phpBorg System User"

        # Check if user already exists
        if id "phpborg" &>/dev/null; then
            log_info "User 'phpborg' already exists"
            save_state "create_phpborg_user" "completed"
        else
            # Create system user with home directory
            log_info "Creating system user: phpborg"

            if useradd --system --create-home --home-dir /var/lib/phpborg \
                       --shell /bin/bash --comment "phpBorg Worker User" phpborg; then
                log_success "User 'phpborg' created"

                # Set correct ownership of phpBorg installation
                log_info "Setting ownership of ${PHPBORG_ROOT}"
                chown -R phpborg:phpborg "${PHPBORG_ROOT}"

                save_state "create_phpborg_user" "completed"
            else
                log_error "Failed to create user 'phpborg'"
                errors=$((errors + 1))
            fi
        fi
    else
        log_info "phpborg user already created (skipped)"
    fi

    # Install Node.js via NVM for phpborg user
    if ! is_step_completed "install_nodejs_nvm"; then
        print_section "Installing Node.js via NVM"

        # Check if NVM is already installed
        if su - phpborg -c '[ -d ~/.nvm ]' 2>/dev/null; then
            log_info "NVM already installed for phpborg user"

            # Check if Node.js is installed
            if su - phpborg -c 'source ~/.nvm/nvm.sh && node --version' >> "${INSTALL_LOG}" 2>&1; then
                local node_version=$(su - phpborg -c 'source ~/.nvm/nvm.sh && node --version' 2>/dev/null)
                log_success "Node.js ${node_version} already installed via NVM"
                save_state "install_nodejs_nvm" "completed"
            else
                # Install Node.js 20
                log_info "Installing Node.js 20 via NVM..."
                if su - phpborg -c 'source ~/.nvm/nvm.sh && nvm install 20 && nvm use 20 && nvm alias default 20' >> "${INSTALL_LOG}" 2>&1; then
                    log_success "Node.js 20 installed via NVM"
                    save_state "install_nodejs_nvm" "completed"
                else
                    log_error "Failed to install Node.js via NVM"
                    errors=$((errors + 1))
                fi
            fi
        else
            # Install NVM as phpborg user
            log_info "Installing NVM for phpborg user..."

            # Ensure home directory has correct permissions
            chown -R phpborg:phpborg /var/lib/phpborg
            chmod 755 /var/lib/phpborg

            if su - phpborg -c 'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash' >> "${INSTALL_LOG}" 2>&1; then
                log_success "NVM installed"

                # Source NVM and install Node.js 20
                log_info "Installing Node.js 20 via NVM..."
                if su - phpborg -c 'source ~/.nvm/nvm.sh && nvm install 20 && nvm use 20 && nvm alias default 20' >> "${INSTALL_LOG}" 2>&1; then
                    log_success "Node.js 20 installed via NVM"
                    save_state "install_nodejs_nvm" "completed"
                else
                    log_error "Failed to install Node.js via NVM"
                    errors=$((errors + 1))
                fi
            else
                log_error "Failed to install NVM"
                errors=$((errors + 1))
            fi
        fi
    else
        log_info "Node.js NVM already installed (skipped)"
    fi

    # Install Composer dependencies
    if ! is_step_completed "install_composer_dependencies"; then
        install_composer_dependencies || errors=$((errors + 1))
    else
        log_info "Composer dependencies already installed (skipped)"
    fi

    # Generate .env file
    if ! is_step_completed "generate_env_file"; then
        generate_env_file || errors=$((errors + 1))
    else
        log_info ".env file already generated (skipped)"
    fi

    # Set permissions
    if ! is_step_completed "set_permissions"; then
        set_permissions || errors=$((errors + 1))
    else
        log_info "Permissions already set (skipped)"
    fi

    # Setup SSH keys
    if ! is_step_completed "setup_ssh_keys"; then
        setup_ssh_keys || errors=$((errors + 1))
    else
        log_info "SSH keys already configured (skipped)"
    fi

    # Run migrations
    if ! is_step_completed "run_migrations"; then
        run_migrations || errors=$((errors + 1))
    else
        log_info "Migrations already run (skipped)"
    fi

    # Generate app secrets
    if ! is_step_completed "generate_app_secrets"; then
        generate_app_secrets || errors=$((errors + 1))
    else
        log_info "App secrets already generated (skipped)"
    fi

    # Clear cache
    if ! is_step_completed "clear_cache"; then
        clear_cache || errors=$((errors + 1))
    else
        log_info "Cache already cleared (skipped)"
    fi

    # Setup log rotation
    if ! is_step_completed "setup_log_rotation"; then
        setup_log_rotation || errors=$((errors + 1))
    else
        log_info "Log rotation already configured (skipped)"
    fi

    # Create storage pool
    if ! is_step_completed "create_storage_pool"; then
        create_storage_pool || errors=$((errors + 1))
    else
        log_info "Storage pool already created (skipped)"
    fi

    # Optimize PHP-FPM
    if ! is_step_completed "optimize_php_fpm"; then
        optimize_php_fpm || errors=$((errors + 1))
    else
        log_info "PHP-FPM already optimized (skipped)"
    fi

    if [ ${errors} -eq 0 ]; then
        log_success "Application setup completed successfully"
        save_state "application_setup" "completed"
        return 0
    else
        log_error "Application setup completed with ${errors} error(s)"
        return 1
    fi
}
