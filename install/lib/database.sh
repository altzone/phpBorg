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
# Test MySQL connection with given credentials
#
test_mysql_connection() {
    local user="$1"
    local password="$2"
    local host="${3:-localhost}"

    if [ -z "${password}" ]; then
        mysql -u "${user}" -h "${host}" -e "SELECT 1" &>/dev/null
    else
        mysql -u "${user}" -p"${password}" -h "${host}" -e "SELECT 1" &>/dev/null
    fi
    return $?
}

#
# Prompt for MySQL credentials interactively
#
prompt_mysql_credentials() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║       MariaDB/MySQL Credentials Required                 ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "MariaDB is already installed and secured."
    echo "Please provide root (or admin) credentials to continue."
    echo ""

    local mysql_user mysql_pass mysql_host

    prompt "MySQL admin user" "root" mysql_user
    prompt_password "MySQL admin password" mysql_pass
    prompt "MySQL host" "localhost" mysql_host

    # Test connection
    echo ""
    log_info "Testing connection..."

    if test_mysql_connection "${mysql_user}" "${mysql_pass}" "${mysql_host}"; then
        log_success "Connection successful!"

        # Save credentials
        cat > /root/.my.cnf <<-EOF
[client]
user=${mysql_user}
password=${mysql_pass}
host=${mysql_host}
EOF
        chmod 600 /root/.my.cnf
        log_info "Credentials saved to /root/.my.cnf"
        return 0
    else
        log_error "Connection failed with provided credentials"
        return 1
    fi
}

#
# Show manual database setup instructions
#
show_manual_db_instructions() {
    local db_name="${1:-phpborg_new}"
    local db_user="${2:-phpborg_new}"
    local db_pass="${3:-CHANGE_ME_SECURE_PASSWORD}"

    echo ""
    echo -e "${YELLOW}╔══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${YELLOW}║          Manual Database Setup Required                  ║${NC}"
    echo -e "${YELLOW}╚══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Unable to connect to MySQL automatically."
    echo "Please run the following commands manually as MySQL root:"
    echo ""
    echo -e "${CYAN}┌──────────────────────────────────────────────────────────┐${NC}"
    echo -e "${GREEN}mysql -u root -p${NC}"
    echo ""
    echo -e "${WHITE}-- Create database${NC}"
    echo "CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo ""
    echo -e "${WHITE}-- Create user and grant privileges${NC}"
    echo "CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';"
    echo "GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost';"
    echo "FLUSH PRIVILEGES;"
    echo ""
    echo -e "${WHITE}-- Import schema${NC}"
    echo "USE ${db_name};"
    echo "SOURCE ${PHPBORG_ROOT}/install/schema.sql;"
    echo "SOURCE ${PHPBORG_ROOT}/install/data.sql;"
    echo -e "${CYAN}└──────────────────────────────────────────────────────────┘${NC}"
    echo ""
    echo "After running these commands, update ${PHPBORG_ROOT}/.env with:"
    echo "  DB_HOST=localhost"
    echo "  DB_NAME=${db_name}"
    echo "  DB_USER=${db_user}"
    echo "  DB_PASSWORD=${db_pass}"
    echo ""

    # Store values for later reference
    export MANUAL_DB_NAME="${db_name}"
    export MANUAL_DB_USER="${db_user}"
    export MANUAL_DB_PASS="${db_pass}"
}

#
# Secure MariaDB installation
#
secure_mariadb() {
    print_section "Securing MariaDB Installation"

    # Method 1: Check if root accessible without password
    if mysql -u root -e "SELECT 1" &>/dev/null; then
        log_info "MariaDB root accessible without password - securing now"

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
    fi

    # MariaDB is already secured - need to find credentials
    log_info "MariaDB already secured (root requires password)"

    # Method 2: Check for existing /root/.my.cnf
    if [ -f "/root/.my.cnf" ]; then
        if mysql -e "SELECT 1" &>/dev/null; then
            log_success "Using existing root credentials from /root/.my.cnf"
            save_state "secure_mariadb" "completed" "{\"root_password_file\":\"/root/.my.cnf\"}"
            return 0
        fi
    fi

    # Method 3: Try debian-sys-maint (Debian/Ubuntu)
    if [ -f "/etc/mysql/debian.cnf" ]; then
        log_info "Trying debian-sys-maint credentials..."
        local debian_user=$(grep "^user" /etc/mysql/debian.cnf | head -1 | awk -F'=' '{print $2}' | tr -d ' ')
        local debian_pass=$(grep "^password" /etc/mysql/debian.cnf | head -1 | awk -F'=' '{print $2}' | tr -d ' ')

        if [ -n "${debian_user}" ] && [ -n "${debian_pass}" ]; then
            if test_mysql_connection "${debian_user}" "${debian_pass}"; then
                log_success "Connected using debian-sys-maint credentials"

                cat > /root/.my.cnf <<-EOF
[client]
user=${debian_user}
password=${debian_pass}
EOF
                chmod 600 /root/.my.cnf
                log_info "Credentials saved to /root/.my.cnf"

                save_state "secure_mariadb" "completed" "{\"root_password_file\":\"/root/.my.cnf\",\"method\":\"debian-sys-maint\"}"
                return 0
            fi
        fi
    fi

    # Method 4: Interactive mode - ask user
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        echo ""
        log_warn "Could not find valid MySQL credentials automatically"

        if confirm "Do you want to enter MySQL credentials?" "y"; then
            if prompt_mysql_credentials; then
                save_state "secure_mariadb" "completed" "{\"root_password_file\":\"/root/.my.cnf\",\"method\":\"user-provided\"}"
                return 0
            fi

            # Second attempt failed - offer manual setup
            echo ""
            if confirm "Would you like to see manual setup instructions?" "y"; then
                prompt "Database name" "phpborg_new" MANUAL_DB_NAME
                prompt "Database user" "phpborg_new" MANUAL_DB_USER

                local gen_pass=$(openssl rand -base64 16 | tr -d "=+/")
                prompt "Database password" "${gen_pass}" MANUAL_DB_PASS

                show_manual_db_instructions "${MANUAL_DB_NAME}" "${MANUAL_DB_USER}" "${MANUAL_DB_PASS}"

                echo ""
                log_warn "Please complete manual setup before continuing"
                echo -n "Press Enter when ready to continue (or Ctrl+C to exit)..."
                read < /dev/tty

                # Mark as manual setup
                save_state "secure_mariadb" "completed" "{\"method\":\"manual\"}"
                return 0
            fi
        fi

        log_error "Cannot proceed without MySQL credentials"
        return 1
    else
        # Auto mode - fail gracefully
        log_error "MariaDB secured but no credentials available (auto mode)"
        log_error "Please run installer in interactive mode or provide credentials"
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

        -- Update password in case user already exists (idempotent reinstall)
        ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';

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
    password_hash=$(${PHP_BINARY} -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT);")

    # Check if admin already exists
    local existing_admin=$(mysql -N -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users WHERE username = '${ADMIN_USERNAME}'" 2>&1)

    if [ $? -ne 0 ]; then
        log_error "Failed to query users table: ${existing_admin}"
        return 1
    fi

    if [ "${existing_admin}" -gt 0 ] 2>/dev/null; then
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

    local insert_result=$(mysql "${DB_NAME}" 2>&1 <<-EOF
        INSERT INTO users (username, email, password, roles, created_at)
        VALUES (
            '${ADMIN_USERNAME}',
            '${ADMIN_EMAIL}',
            '${password_hash}',
            '["ROLE_ADMIN"]',
            NOW()
        );
EOF
)
    local insert_code=$?

    if [ ${insert_code} -eq 0 ]; then
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
        log_error "MySQL error: ${insert_result}"
        return 1
    fi
}

#
# Create default storage pool in database
#

create_default_storage_pool() {
    print_section "Creating Default Storage Pool in Database"

    local storage_path="${STORAGE_POOL_PATH:-/opt/backups}"

    # Create directory if it doesn't exist
    if [ ! -d "${storage_path}" ]; then
        log_info "Creating storage pool directory: ${storage_path}"
        mkdir -p "${storage_path}"
    fi

    # Set proper ownership and permissions
    # phpborg-borg owns the directory (borg server writes here)
    # phpborg group has access (workers need to manage repos)
    log_info "Setting permissions on storage pool: ${storage_path}"
    # phpborg-borg user may not exist yet (created later in services setup)
    # Use phpborg:phpborg for now, services.sh will fix permissions later
    if id "phpborg-borg" &>/dev/null; then
        chown phpborg-borg:phpborg "${storage_path}"
    else
        log_info "phpborg-borg user doesn't exist yet, using phpborg:phpborg (will be fixed later)"
        chown phpborg:phpborg "${storage_path}"
    fi
    chmod 770 "${storage_path}"

    # Check if default pool already exists
    local existing_pool=$(mysql -N -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.storage_pools WHERE path = '${storage_path}'" 2>&1)

    if [ "${existing_pool}" -gt 0 ] 2>/dev/null; then
        log_info "Default storage pool already exists in database"
        save_state "create_default_storage_pool" "completed"
        return 0
    fi

    log_info "Creating default storage pool: ${storage_path}"

    # Get filesystem info
    local capacity_total=$(df -B1 "${storage_path}" 2>/dev/null | awk 'NR==2 {print $2}')
    local capacity_used=$(df -B1 "${storage_path}" 2>/dev/null | awk 'NR==2 {print $3}')
    local available_bytes=$(df -B1 "${storage_path}" 2>/dev/null | awk 'NR==2 {print $4}')
    local fs_type=$(df -T "${storage_path}" 2>/dev/null | awk 'NR==2 {print $2}')

    # Insert storage pool
    local insert_result=$(mysql -e "
        INSERT INTO \`${DB_NAME}\`.storage_pools
            (name, path, description, capacity_total, capacity_used, available_bytes, filesystem_type, default_pool, active, created_at, updated_at)
        VALUES
            ('Default', '${storage_path}', 'Default backup storage pool', ${capacity_total:-0}, ${capacity_used:-0}, ${available_bytes:-0}, '${fs_type:-unknown}', 1, 1, NOW(), NOW());
    " 2>&1)

    if [ $? -eq 0 ]; then
        log_success "Default storage pool created in database"
        save_state "create_default_storage_pool" "completed"
        return 0
    else
        log_error "Failed to create default storage pool"
        log_error "MySQL error: ${insert_result}"
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

    # Ensure log directory exists
    mkdir -p /var/log/mysql
    chown mysql:mysql /var/log/mysql
    chmod 750 /var/log/mysql

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

# Slow query logging (useful for debugging)
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mariadb-slow.log
long_query_time = 2
log_queries_not_using_indexes = 0

# Binary logging disabled (not needed for phpBorg standalone)
# Enable if you need replication or point-in-time recovery
# log_bin = /var/log/mysql/mariadb-bin
# binlog_format = ROW
# expire_logs_days = 7
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

    # Create default storage pool in database
    if ! is_step_completed "create_default_storage_pool"; then
        create_default_storage_pool || errors=$((errors + 1))
    else
        log_info "Default storage pool already created (skipped)"
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
