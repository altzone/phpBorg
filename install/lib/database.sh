#!/bin/bash
#
# phpBorg Installation - Database Setup
# Configures MariaDB, creates database and user, imports schema
#

#
# MariaDB service management
#

start_mariadb() {
    print_section "Starting MariaDB Service"

    case "${SERVICE_MANAGER}" in
        systemd)
            if is_service_running "mariadb"; then
                log_info "MariaDB already running"
                return 0
            fi

            if run_cmd "systemctl start mariadb"; then
                log_success "MariaDB started"
                return 0
            else
                log_error "Failed to start MariaDB"
                return 1
            fi
            ;;
        openrc)
            if run_cmd "rc-service mariadb start"; then
                log_success "MariaDB started"
                return 0
            else
                log_error "Failed to start MariaDB"
                return 1
            fi
            ;;
        *)
            if run_cmd "service mariadb start" || run_cmd "service mysql start"; then
                log_success "MariaDB started"
                return 0
            else
                log_error "Failed to start MariaDB"
                return 1
            fi
            ;;
    esac
}

enable_mariadb() {
    print_section "Enabling MariaDB Service"

    case "${SERVICE_MANAGER}" in
        systemd)
            if is_service_enabled "mariadb"; then
                log_info "MariaDB already enabled"
                return 0
            fi

            if run_cmd "systemctl enable mariadb"; then
                log_success "MariaDB enabled at boot"
                return 0
            else
                log_error "Failed to enable MariaDB"
                return 1
            fi
            ;;
        openrc)
            if run_cmd "rc-update add mariadb default"; then
                log_success "MariaDB enabled at boot"
                return 0
            else
                log_error "Failed to enable MariaDB"
                return 1
            fi
            ;;
        *)
            log_warn "Auto-start configuration not supported"
            return 0
            ;;
    esac
}

#
# Secure MariaDB installation
#

secure_mariadb() {
    print_section "Securing MariaDB Installation"

    # Check if already secured
    if mysql -u root -e "SELECT 1" &>/dev/null; then
        log_info "MariaDB root accessible without password - securing now"
    else
        log_info "MariaDB already secured (root requires password)"
        return 0
    fi

    # Generate secure root password if not provided
    if [ -z "${DB_ROOT_PASSWORD}" ]; then
        DB_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-24)
        log_info "Generated root password"
    fi

    # Execute secure installation commands
    log_info "Setting root password and removing anonymous users"

    mysql -u root <<-EOF
        -- Set root password
        ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASSWORD}';

        -- Remove anonymous users
        DELETE FROM mysql.user WHERE User='';

        -- Disallow root login remotely
        DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

        -- Remove test database
        DROP DATABASE IF EXISTS test;
        DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

        -- Reload privilege tables
        FLUSH PRIVILEGES;
EOF

    if [ $? -eq 0 ]; then
        # Save root password to file
        cat > /root/.my.cnf <<-EOF
[client]
user=root
password=${DB_ROOT_PASSWORD}
EOF
        chmod 600 /root/.my.cnf

        log_success "MariaDB secured"
        log_info "Root password saved to /root/.my.cnf"

        save_state "secure_mariadb" "completed" "{\"root_password_file\":\"/root/.my.cnf\"}"
        return 0
    else
        log_error "Failed to secure MariaDB"
        return 1
    fi
}

#
# Database and user creation
#

create_database() {
    print_section "Creating phpBorg Database"

    # Prompt for database details in interactive mode
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        prompt "Database name" "phpborg_new" DB_NAME
        prompt "Database user" "phpborg_new" DB_USER
        prompt_password "Database password" DB_PASSWORD

        if [ -z "${DB_PASSWORD}" ]; then
            DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-24)
            log_info "Generated database password"
        fi
    else
        # Auto mode: use defaults
        DB_NAME="${DB_NAME:-phpborg_new}"
        DB_USER="${DB_USER:-phpborg_new}"
        if [ -z "${DB_PASSWORD}" ]; then
            DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-24)
        fi
    fi

    # Check if database already exists
    if mysql -e "USE ${DB_NAME}" 2>/dev/null; then
        log_warn "Database '${DB_NAME}' already exists"

        if [ "${INSTALL_MODE}" = "interactive" ]; then
            if confirm "Drop and recreate database?" "n"; then
                log_info "Dropping existing database"
                mysql -e "DROP DATABASE ${DB_NAME}"
            else
                log_info "Keeping existing database"
                save_state "create_database" "completed" "{\"db_name\":\"${DB_NAME}\",\"db_user\":\"${DB_USER}\"}"
                return 0
            fi
        else
            log_info "Keeping existing database (auto mode)"
            save_state "create_database" "completed" "{\"db_name\":\"${DB_NAME}\",\"db_user\":\"${DB_USER}\"}"
            return 0
        fi
    fi

    # Create database
    log_info "Creating database: ${DB_NAME}"

    mysql <<-EOF
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

        -- Create user if not exists
        CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';

        -- Grant privileges
        GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';

        -- Reload privileges
        FLUSH PRIVILEGES;
EOF

    if [ $? -eq 0 ]; then
        log_success "Database '${DB_NAME}' created"
        log_success "User '${DB_USER}' created with full privileges"

        # Export for use by other scripts
        export DB_NAME DB_USER DB_PASSWORD

        save_state "create_database" "completed" "{\"db_name\":\"${DB_NAME}\",\"db_user\":\"${DB_USER}\"}"
        return 0
    else
        log_error "Failed to create database"
        return 1
    fi
}

#
# Schema import
#

import_schema() {
    print_section "Importing Database Schema"

    local schema_file="${PHPBORG_ROOT}/install/schema.sql"

    # Check if schema file exists
    if [ ! -f "${schema_file}" ]; then
        log_error "Schema file not found: ${schema_file}"
        return 1
    fi

    # Check if database is empty
    local table_count=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}'")

    if [ "${table_count}" -gt 0 ]; then
        log_warn "Database '${DB_NAME}' already contains ${table_count} table(s)"

        if [ "${INSTALL_MODE}" = "interactive" ]; then
            if ! confirm "Import schema anyway? (this may cause errors)" "n"; then
                log_info "Skipping schema import"
                save_state "import_schema" "completed"
                return 0
            fi
        else
            log_info "Skipping schema import (auto mode)"
            save_state "import_schema" "completed"
            return 0
        fi
    fi

    # Import schema
    log_info "Importing schema from: ${schema_file}"

    if mysql "${DB_NAME}" < "${schema_file}" 2>> "${INSTALL_LOG}"; then
        local imported_tables=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}'")
        log_success "Schema imported (${imported_tables} tables)"

        save_state "import_schema" "completed" "{\"tables\":${imported_tables}}"
        return 0
    else
        log_error "Failed to import schema"
        return 1
    fi
}

#
# Import initial data
#

import_initial_data() {
    print_section "Importing Initial Data"

    local data_file="${PHPBORG_ROOT}/install/data.sql"

    # Data file is optional
    if [ ! -f "${data_file}" ]; then
        log_info "No initial data file found (${data_file})"
        save_state "import_initial_data" "completed"
        return 0
    fi

    log_info "Importing initial data from: ${data_file}"

    if mysql "${DB_NAME}" < "${data_file}" 2>> "${INSTALL_LOG}"; then
        log_success "Initial data imported"
        save_state "import_initial_data" "completed"
        return 0
    else
        log_error "Failed to import initial data"
        return 1
    fi
}

#
# Create admin user
#

create_admin_user() {
    print_section "Creating Admin User"

    # Prompt for admin credentials in interactive mode
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        prompt "Admin username" "admin" ADMIN_USERNAME
        prompt "Admin email" "admin@localhost" ADMIN_EMAIL
        prompt_password "Admin password" ADMIN_PASSWORD

        if [ -z "${ADMIN_PASSWORD}" ]; then
            ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -d "=+/" | cut -c1-16)
            log_info "Generated admin password: ${ADMIN_PASSWORD}"
        fi
    else
        # Auto mode: use defaults
        ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
        ADMIN_EMAIL="${ADMIN_EMAIL:-admin@localhost}"
        if [ -z "${ADMIN_PASSWORD}" ]; then
            ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -d "=+/" | cut -c1-16)
        fi
    fi

    # Hash password (PHP bcrypt)
    local password_hash
    password_hash=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT);")

    # Check if admin already exists
    local existing_admin=$(mysql -N -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users WHERE username = '${ADMIN_USERNAME}'")

    if [ "${existing_admin}" -gt 0 ]; then
        log_warn "Admin user '${ADMIN_USERNAME}' already exists"

        if [ "${INSTALL_MODE}" = "interactive" ]; then
            if confirm "Update password?" "n"; then
                mysql "${DB_NAME}" <<-EOF
                    UPDATE users
                    SET password = '${password_hash}',
                        email = '${ADMIN_EMAIL}',
                        updated_at = NOW()
                    WHERE username = '${ADMIN_USERNAME}';
EOF
                log_success "Admin password updated"
            fi
        fi

        save_state "create_admin_user" "completed"
        return 0
    fi

    # Create admin user
    log_info "Creating admin user: ${ADMIN_USERNAME}"

    mysql "${DB_NAME}" <<-EOF
        INSERT INTO users (username, email, password, role, created_at, updated_at)
        VALUES (
            '${ADMIN_USERNAME}',
            '${ADMIN_EMAIL}',
            '${password_hash}',
            'ROLE_ADMIN',
            NOW(),
            NOW()
        );
EOF

    if [ $? -eq 0 ]; then
        log_success "Admin user created"
        log_info "Username: ${ADMIN_USERNAME}"
        log_info "Email: ${ADMIN_EMAIL}"
        log_info "Password: ${ADMIN_PASSWORD}"

        # Save credentials to file
        cat > "${PHPBORG_ROOT}/.install-credentials" <<-EOF
Admin Credentials
=================
Username: ${ADMIN_USERNAME}
Email: ${ADMIN_EMAIL}
Password: ${ADMIN_PASSWORD}

Database: ${DB_NAME}
DB User: ${DB_USER}
DB Password: ${DB_PASSWORD}

IMPORTANT: Save these credentials securely and delete this file!
EOF
        chmod 600 "${PHPBORG_ROOT}/.install-credentials"

        log_success "Credentials saved to: ${PHPBORG_ROOT}/.install-credentials"

        # Export for use by other scripts
        export ADMIN_USERNAME ADMIN_EMAIL ADMIN_PASSWORD

        save_state "create_admin_user" "completed" "{\"username\":\"${ADMIN_USERNAME}\"}"
        return 0
    else
        log_error "Failed to create admin user"
        return 1
    fi
}

#
# Database optimization
#

optimize_mariadb() {
    print_section "Optimizing MariaDB Configuration"

    local my_cnf="/etc/mysql/mariadb.conf.d/99-phpborg.cnf"

    # Create optimization config
    log_info "Creating MariaDB configuration: ${my_cnf}"

    backup_file "${my_cnf}"

    cat > "${my_cnf}" <<-'EOF'
[mysqld]
# phpBorg Optimization Settings

# Connection settings
max_connections = 200
connect_timeout = 10
wait_timeout = 600
max_allowed_packet = 64M

# Buffer sizes
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_log_buffer_size = 16M
innodb_flush_log_at_trx_commit = 2

# Query cache (disabled in MariaDB 10.5+, but kept for compatibility)
query_cache_type = 0
query_cache_size = 0

# Temp tables
tmp_table_size = 64M
max_heap_table_size = 64M

# Performance schema
performance_schema = ON

# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mariadb-slow.log
long_query_time = 2
log_queries_not_using_indexes = 0

# Binary logging (for replication/backup)
log_bin = /var/log/mysql/mariadb-bin
log_bin_index = /var/log/mysql/mariadb-bin.index
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 100M
EOF

    if [ $? -eq 0 ]; then
        log_success "MariaDB configuration created"

        # Restart MariaDB to apply changes
        log_info "Restarting MariaDB to apply changes"
        if run_cmd "systemctl restart mariadb"; then
            log_success "MariaDB restarted"
            save_state "optimize_mariadb" "completed"
            return 0
        else
            log_error "Failed to restart MariaDB"
            return 1
        fi
    else
        log_error "Failed to create MariaDB configuration"
        return 1
    fi
}

#
# Main database setup orchestrator
#

setup_database() {
    print_header "Database Setup"

    local errors=0

    # Start and enable MariaDB
    start_mariadb || errors=$((errors + 1))
    enable_mariadb || errors=$((errors + 1))

    # Secure installation
    if ! is_step_completed "secure_mariadb"; then
        secure_mariadb || errors=$((errors + 1))
    else
        log_info "MariaDB already secured (skipped)"
    fi

    # Create database and user
    if ! is_step_completed "create_database"; then
        create_database || errors=$((errors + 1))
    else
        log_info "Database already created (skipped)"
    fi

    # Import schema
    if ! is_step_completed "import_schema"; then
        import_schema || errors=$((errors + 1))
    else
        log_info "Schema already imported (skipped)"
    fi

    # Import initial data
    if ! is_step_completed "import_initial_data"; then
        import_initial_data || errors=$((errors + 1))
    else
        log_info "Initial data already imported (skipped)"
    fi

    # Create admin user
    if ! is_step_completed "create_admin_user"; then
        create_admin_user || errors=$((errors + 1))
    else
        log_info "Admin user already created (skipped)"
    fi

    # Optimize configuration
    if ! is_step_completed "optimize_mariadb"; then
        optimize_mariadb || errors=$((errors + 1))
    else
        log_info "MariaDB already optimized (skipped)"
    fi

    if [ ${errors} -eq 0 ]; then
        log_success "Database setup completed successfully"
        save_state "database_setup" "completed"
        return 0
    else
        log_error "Database setup completed with ${errors} error(s)"
        return 1
    fi
}
