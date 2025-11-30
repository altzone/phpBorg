#!/bin/bash
#
# phpBorg Installation - Dependencies Installation
# Installs all required packages and services across multiple distributions
#

#
# Package name mappings for different distributions
#

# PHP packages
get_php_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            # Use php8.3-* packages to ensure correct version from Sury PPA
            echo "php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-pgsql php8.3-mongodb php8.3-redis php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "php php-cli php-fpm php-mysqlnd php-pgsql php-pecl-mongodb php-pecl-redis php-xml php-mbstring php-json php-gd php-intl php-bcmath php-opcache"
            ;;
        arch|manjaro)
            echo "php php-fpm php-gd php-intl php-redis"
            ;;
        alpine)
            echo "php81 php81-fpm php81-mysqli php81-pgsql php81-mongodb php81-redis php81-xml php81-mbstring php81-curl php81-zip php81-gd php81-intl php81-bcmath php81-opcache"
            ;;
    esac
}

# Node.js packages
get_nodejs_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "nodejs npm"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "nodejs npm"
            ;;
        arch|manjaro)
            echo "nodejs npm"
            ;;
        alpine)
            echo "nodejs npm"
            ;;
    esac
}

# Database packages
get_database_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "mariadb-server mariadb-client"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "mariadb-server mariadb"
            ;;
        arch|manjaro)
            echo "mariadb"
            ;;
        alpine)
            echo "mariadb mariadb-client"
            ;;
    esac
}

# Redis packages
get_redis_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "redis-server redis-tools"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "redis"
            ;;
        arch|manjaro)
            echo "redis"
            ;;
        alpine)
            echo "redis"
            ;;
    esac
}

# Web server packages
get_webserver_packages() {
    local webserver="$1"

    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            if [ "${webserver}" = "nginx" ]; then
                echo "nginx"
            else
                echo "apache2 libapache2-mod-php"
            fi
            ;;
        rhel|centos|rocky|almalinux|fedora)
            if [ "${webserver}" = "nginx" ]; then
                echo "nginx"
            else
                echo "httpd mod_ssl"
            fi
            ;;
        arch|manjaro)
            if [ "${webserver}" = "nginx" ]; then
                echo "nginx"
            else
                echo "apache php-apache"
            fi
            ;;
        alpine)
            if [ "${webserver}" = "nginx" ]; then
                echo "nginx"
            else
                echo "apache2 apache2-ssl"
            fi
            ;;
    esac
}

# Docker packages
get_docker_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "docker.io docker-compose docker-buildx"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "docker docker-compose docker-buildx-plugin"
            ;;
        arch|manjaro)
            echo "docker docker-compose docker-buildx"
            ;;
        alpine)
            echo "docker docker-compose docker-buildx-plugin"
            ;;
    esac
}

# BorgBackup packages (includes FUSE support for borg mount)
get_borg_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "borgbackup fuse3 python3-pyfuse3"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "borgbackup fuse3 python3-pyfuse3"
            ;;
        arch|manjaro)
            echo "borg fuse3 python-pyfuse3"
            ;;
        alpine)
            echo "borgbackup fuse3 py3-pyfuse3"
            ;;
    esac
}

# Go packages
get_golang_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "golang-go"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "golang"
            ;;
        arch|manjaro)
            echo "go"
            ;;
        alpine)
            echo "go"
            ;;
    esac
}

# Certbot packages (for Let's Encrypt SSL)
get_certbot_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "certbot python3-certbot-nginx python3-certbot-dns-cloudflare"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "certbot python3-certbot-nginx python3-certbot-dns-cloudflare"
            ;;
        arch|manjaro)
            echo "certbot certbot-nginx certbot-dns-cloudflare"
            ;;
        alpine)
            echo "certbot certbot-nginx certbot-dns-cloudflare"
            ;;
    esac
}

# Utility packages
get_utility_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo sshpass"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo sshpass"
            ;;
        arch|manjaro)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo sshpass"
            ;;
        alpine)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo sshpass"
            ;;
    esac
}

#
# Version checks
#

check_php_version() {
    if [ "${PHP_INSTALLED}" = "0" ]; then
        return 1
    fi

    local required="8.3"
    if version_ge "${PHP_VERSION}" "${required}"; then
        log_success "PHP ${PHP_VERSION} meets requirement (>= ${required})"
        return 0
    else
        log_warn "PHP ${PHP_VERSION} is too old (>= ${required} required)"
        return 1
    fi
}

check_nodejs_version() {
    if [ "${NODEJS_INSTALLED}" = "0" ]; then
        return 1
    fi

    local required="18.0.0"
    if version_ge "${NODEJS_VERSION}" "${required}"; then
        log_success "Node.js ${NODEJS_VERSION} meets requirement (>= ${required})"
        return 0
    else
        log_warn "Node.js ${NODEJS_VERSION} is too old (>= ${required} required)"
        return 1
    fi
}

check_docker_version() {
    if [ "${DOCKER_INSTALLED}" = "0" ]; then
        return 1
    fi

    local required="20.0.0"
    if version_ge "${DOCKER_VERSION}" "${required}"; then
        log_success "Docker ${DOCKER_VERSION} meets requirement (>= ${required})"
        return 0
    else
        log_warn "Docker ${DOCKER_VERSION} is too old (>= ${required} required)"
        return 1
    fi
}

check_borg_version() {
    if [ "${BORG_INSTALLED}" = "0" ]; then
        return 1
    fi

    local required="1.2.0"
    if version_ge "${BORG_VERSION}" "${required}"; then
        log_success "BorgBackup ${BORG_VERSION} meets requirement (>= ${required})"
        return 0
    else
        log_warn "BorgBackup ${BORG_VERSION} is too old (>= ${required} required)"
        return 1
    fi
}

#
# Repository setup for specific packages
#

setup_nodejs_repo() {
    print_section "Setting up Node.js Repository"

    # Skip if Node.js version is acceptable
    if check_nodejs_version 2>/dev/null; then
        log_info "Node.js repository setup not needed"
        return 0
    fi

    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            log_info "Adding NodeSource repository for Node.js 20.x"
            if ! run_cmd "curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"; then
                log_error "Failed to setup Node.js repository"
                return 1
            fi
            ;;
        rhel|centos|rocky|almalinux|fedora)
            log_info "Adding NodeSource repository for Node.js 20.x"
            if ! run_cmd "curl -fsSL https://rpm.nodesource.com/setup_20.x | bash -"; then
                log_error "Failed to setup Node.js repository"
                return 1
            fi
            ;;
        arch|manjaro)
            log_info "Node.js available in official repositories"
            ;;
        alpine)
            log_info "Node.js available in official repositories"
            ;;
    esac

    log_success "Node.js repository configured"
}

setup_docker_repo() {
    print_section "Setting up Docker Repository"

    # Skip if Docker is already installed
    if [ "${DOCKER_INSTALLED}" = "1" ]; then
        log_info "Docker repository setup not needed"
        return 0
    fi

    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            log_info "Adding Docker official repository"
            run_cmd "apt-get install -y ca-certificates curl gnupg"
            run_cmd "install -m 0755 -d /etc/apt/keyrings"
            run_cmd "curl -fsSL https://download.docker.com/linux/${OS_DISTRO}/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg"
            run_cmd "chmod a+r /etc/apt/keyrings/docker.gpg"

            echo \
              "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/${OS_DISTRO} \
              $(. /etc/os-release && echo "${VERSION_CODENAME}") stable" | \
              tee /etc/apt/sources.list.d/docker.list > /dev/null

            run_cmd "${PKG_UPDATE_CMD}"
            ;;
        rhel|centos|rocky|almalinux)
            log_info "Adding Docker official repository"
            run_cmd "yum install -y yum-utils"
            run_cmd "yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo"
            ;;
        fedora)
            log_info "Adding Docker official repository"
            run_cmd "dnf install -y dnf-plugins-core"
            run_cmd "dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo"
            ;;
        arch|manjaro)
            log_info "Docker available in official repositories"
            ;;
        alpine)
            log_info "Docker available in official repositories"
            ;;
    esac

    log_success "Docker repository configured"
}

setup_php_repo() {
    print_section "Setting up PHP Repository"

    # Detect PHP first to check current version
    detect_php

    # Skip if PHP version is acceptable
    if check_php_version 2>/dev/null; then
        log_info "PHP repository setup not needed"
        return 0
    fi

    case "${OS_DISTRO}" in
        ubuntu|linuxmint|pop)
            log_info "Adding Ondrej PHP PPA for PHP 8.3 (Ubuntu)"

            # Install prerequisites
            run_cmd "apt-get install -y gnupg2 ca-certificates lsb-release software-properties-common"

            # Add PPA the proper way
            run_cmd "add-apt-repository -y ppa:ondrej/php"

            log_success "PHP PPA configured"
            run_cmd "${PKG_UPDATE_CMD}"
            ;;
        debian)
            log_info "Adding Sury PHP repository for PHP 8.3 (Debian)"

            # Install prerequisites
            run_cmd "apt-get install -y gnupg2 ca-certificates lsb-release apt-transport-https"

            # Add Sury GPG key
            log_info "Adding Sury PHP GPG key"
            curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg

            # Add repository with signed-by
            local codename=$(lsb_release -sc)
            log_info "Adding Sury PHP repository for ${codename}"
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${codename} main" | tee /etc/apt/sources.list.d/sury-php.list > /dev/null

            log_success "PHP Sury repository configured"
            run_cmd "${PKG_UPDATE_CMD}"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            log_info "Adding Remi repository for PHP 8.3"
            run_cmd "${PKG_INSTALL_CMD} epel-release"
            run_cmd "${PKG_INSTALL_CMD} https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %{rhel}).rpm"
            run_cmd "${PKG_INSTALL_CMD} yum-utils"
            run_cmd "yum-config-manager --enable remi-php83"
            ;;
        arch|manjaro)
            log_info "PHP available in official repositories"
            ;;
        alpine)
            log_info "PHP available in official repositories"
            ;;
    esac

    log_success "PHP repository configured"
}

#
# Package installation with progress display
#

install_packages_with_progress() {
    local title="$1"
    local packages="$2"

    run_with_progress "${title}" "${PKG_INSTALL_CMD} ${packages}"
    return $?
}

install_utilities() {
    print_section "Installing Utility Packages"

    local packages=$(get_utility_packages)

    if install_packages_with_progress "Installing utilities" "${packages}"; then
        log_success "Utility packages installed"
        save_state "install_utilities" "completed"
        return 0
    else
        log_error "Failed to install utility packages"
        return 1
    fi
}

install_php() {
    print_section "Installing PHP"

    # Detect current PHP version
    detect_php

    # Skip if already acceptable
    if check_php_version 2>/dev/null; then
        log_info "PHP installation not needed"
        save_state "install_php" "completed"
        return 0
    fi

    # If PHP is installed but wrong version, need to upgrade
    if [ "${PHP_INSTALLED}" = "1" ]; then
        log_warn "PHP ${PHP_VERSION} found but PHP 8.3+ required - upgrading"
    fi

    # Setup repository first (will add PPA if needed)
    setup_php_repo

    local packages=$(get_php_packages)

    if install_packages_with_progress "Installing PHP 8.3" "${packages}"; then
        # Re-detect PHP
        detect_php

        if check_php_version; then
            log_success "PHP installed successfully"
            save_state "install_php" "completed"
            return 0
        else
            log_error "PHP installed but version check failed"
            return 1
        fi
    else
        log_error "Failed to install PHP"
        return 1
    fi
}

install_composer() {
    print_section "Installing Composer"

    if [ "${COMPOSER_INSTALLED}" = "1" ]; then
        log_info "Composer already installed"
        save_state "install_composer" "completed"
        return 0
    fi

    # Ensure PHP is detected (should be installed by now)
    detect_php
    if [ -z "${PHP_BINARY}" ] || [ ! -x "${PHP_BINARY}" ]; then
        log_error "PHP not found - cannot install Composer"
        return 1
    fi

    # Fast method: Download pre-built composer.phar directly
    # Much faster than using the installer script (seconds vs minutes)
    log_info "Downloading Composer binary (latest stable)"

    if curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer; then
        chmod +x /usr/local/bin/composer

        # Verify it works
        if /usr/local/bin/composer --version >> "${INSTALL_LOG}" 2>&1; then
            detect_composer
            log_success "Composer ${COMPOSER_VERSION} installed"
            save_state "install_composer" "completed"
            return 0
        else
            log_error "Composer binary downloaded but not working"
            rm -f /usr/local/bin/composer
            return 1
        fi
    else
        log_error "Failed to download Composer"
        return 1
    fi
}

install_nodejs() {
    print_section "Installing Node.js"

    # Skip if already acceptable
    if check_nodejs_version 2>/dev/null; then
        log_info "Node.js installation not needed"
        save_state "install_nodejs" "completed"
        return 0
    fi

    # Setup repository first
    setup_nodejs_repo

    local packages=$(get_nodejs_packages)

    if install_packages_with_progress "Installing Node.js" "${packages}"; then
        # Re-detect Node.js
        detect_nodejs
        detect_npm

        if check_nodejs_version; then
            log_success "Node.js ${NODEJS_VERSION} installed"
            save_state "install_nodejs" "completed"
            return 0
        else
            log_error "Node.js installed but version check failed"
            return 1
        fi
    else
        log_error "Failed to install Node.js"
        return 1
    fi
}

install_database() {
    print_section "Installing MariaDB"

    if [ "${MYSQL_INSTALLED}" = "1" ]; then
        log_info "MariaDB/MySQL already installed"
        save_state "install_database" "completed"
        return 0
    fi

    local packages=$(get_database_packages)

    if install_packages_with_progress "Installing MariaDB" "${packages}"; then
        detect_mysql
        log_success "MariaDB installed"
        save_state "install_database" "completed"
        return 0
    else
        log_error "Failed to install MariaDB"
        return 1
    fi
}

install_redis() {
    print_section "Installing Redis"

    if [ "${REDIS_INSTALLED}" = "1" ]; then
        log_info "Redis already installed"
        save_state "install_redis" "completed"
        return 0
    fi

    local packages=$(get_redis_packages)

    if install_packages_with_progress "Installing Redis" "${packages}"; then
        detect_redis
        log_success "Redis installed"
        save_state "install_redis" "completed"
        return 0
    else
        log_error "Failed to install Redis"
        return 1
    fi
}

install_webserver() {
    print_section "Installing Web Server"

    # Check if selected webserver is already installed
    if [ "${WEBSERVER}" = "nginx" ] && [ "${NGINX_INSTALLED}" = "1" ]; then
        log_info "Nginx already installed"
        save_state "install_webserver" "completed"
        return 0
    elif [ "${WEBSERVER}" = "apache" ] && [ "${APACHE_INSTALLED}" = "1" ]; then
        log_info "Apache already installed"
        save_state "install_webserver" "completed"
        return 0
    fi

    local packages=$(get_webserver_packages "${WEBSERVER}")
    local server_name="Nginx"
    [ "${WEBSERVER}" = "apache" ] && server_name="Apache"

    if install_packages_with_progress "Installing ${server_name}" "${packages}"; then
        if [ "${WEBSERVER}" = "nginx" ]; then
            detect_nginx
            log_success "Nginx installed"
        else
            detect_apache
            log_success "Apache installed"
        fi
        save_state "install_webserver" "completed"
        return 0
    else
        log_error "Failed to install web server"
        return 1
    fi
}

install_docker() {
    print_section "Installing Docker"

    # Skip if already acceptable
    if check_docker_version 2>/dev/null; then
        log_info "Docker installation not needed"
        save_state "install_docker" "completed"
        return 0
    fi

    # Setup repository first
    setup_docker_repo

    local packages=$(get_docker_packages)

    if install_packages_with_progress "Installing Docker" "${packages}"; then
        detect_docker

        if check_docker_version; then
            log_success "Docker installed"
            save_state "install_docker" "completed"
            return 0
        else
            log_error "Docker installed but version check failed"
            return 1
        fi
    else
        log_error "Failed to install Docker"
        return 1
    fi
}

install_borgbackup() {
    print_section "Installing BorgBackup"

    # Skip if already acceptable
    if check_borg_version 2>/dev/null; then
        log_info "BorgBackup installation not needed"
        save_state "install_borgbackup" "completed"
        return 0
    fi

    local packages=$(get_borg_packages)

    if install_packages_with_progress "Installing BorgBackup" "${packages}"; then
        detect_borgbackup

        if check_borg_version; then
            log_success "BorgBackup installed"
            save_state "install_borgbackup" "completed"
            return 0
        else
            log_error "BorgBackup installed but version check failed"
            return 1
        fi
    else
        log_error "Failed to install BorgBackup"
        return 1
    fi
}

configure_fuse() {
    print_section "Configuring FUSE"

    # Enable user_allow_other in fuse.conf for borg mount with allow_other option
    if [ -f /etc/fuse.conf ]; then
        if ! grep -q '^user_allow_other' /etc/fuse.conf; then
            log_info "Enabling user_allow_other in /etc/fuse.conf"
            echo 'user_allow_other' >> /etc/fuse.conf
            log_success "FUSE configured for user mounts"
        else
            log_info "user_allow_other already enabled in /etc/fuse.conf"
        fi
    else
        log_warn "/etc/fuse.conf not found, creating it"
        echo 'user_allow_other' > /etc/fuse.conf
        log_success "FUSE configured for user mounts"
    fi

    save_state "configure_fuse" "completed"
    return 0
}

install_fuse_overlayfs() {
    print_section "Installing fuse-overlayfs"

    if [ "${FUSE_OVERLAYFS_INSTALLED}" = "1" ]; then
        log_info "fuse-overlayfs already installed"
        save_state "install_fuse_overlayfs" "completed"
        return 0
    fi

    if install_packages_with_progress "Installing fuse-overlayfs" "fuse-overlayfs"; then
        detect_fuse_overlayfs
        log_success "fuse-overlayfs installed"
        save_state "install_fuse_overlayfs" "completed"
        return 0
    else
        log_error "Failed to install fuse-overlayfs"
        return 1
    fi
}

install_golang() {
    print_section "Installing Go (for agent build)"

    # Ensure make is installed (required for agent build)
    if ! command -v make &>/dev/null; then
        log_info "Installing make (required for agent build)"
        apt-get install -y make >> "${INSTALL_LOG}" 2>&1 || yum install -y make >> "${INSTALL_LOG}" 2>&1 || true
    fi

    # Check if Go is already installed
    if command -v go &>/dev/null; then
        local go_version=$(go version 2>/dev/null | awk '{print $3}' | sed 's/go//')
        log_info "Go already installed: ${go_version}"
        save_state "install_golang" "completed"
        return 0
    fi

    local packages=$(get_golang_packages)

    if install_packages_with_progress "Installing Go" "${packages}"; then
        if command -v go &>/dev/null; then
            local go_version=$(go version 2>/dev/null | awk '{print $3}' | sed 's/go//')
            log_success "Go installed: ${go_version}"
            save_state "install_golang" "completed"
            return 0
        else
            log_error "Go installed but not found in PATH"
            return 1
        fi
    else
        log_error "Failed to install Go"
        return 1
    fi
}

install_certbot() {
    print_section "Installing Certbot (Let's Encrypt)"

    # Check if certbot is already installed
    if command -v certbot &>/dev/null; then
        local certbot_version=$(certbot --version 2>&1 | awk '{print $2}')
        log_info "Certbot already installed: ${certbot_version}"
        save_state "install_certbot" "completed"
        return 0
    fi

    local packages=$(get_certbot_packages)

    if install_packages_with_progress "Installing Certbot" "${packages}"; then
        if command -v certbot &>/dev/null; then
            local certbot_version=$(certbot --version 2>&1 | awk '{print $2}')
            log_success "Certbot installed: ${certbot_version}"
            save_state "install_certbot" "completed"
            return 0
        else
            log_error "Certbot installed but not found in PATH"
            return 1
        fi
    else
        log_error "Failed to install Certbot"
        return 1
    fi
}

build_phpborg_agent() {
    print_section "Building phpBorg Agent (all platforms)"

    local agent_src="${PHPBORG_ROOT}/agent"
    local releases_dir="${PHPBORG_ROOT}/releases/agent"

    # Check if agent source exists
    if [ ! -d "${agent_src}" ]; then
        log_error "Agent source directory not found: ${agent_src}"
        return 1
    fi

    # Check if Go is available
    if ! command -v go &>/dev/null; then
        log_error "Go is not installed, cannot build agent"
        return 1
    fi

    # Create releases directory
    mkdir -p "${releases_dir}"

    # Build all agents using Makefile
    log_info "Compiling phpborg-agent for all platforms (Linux amd64/arm64, Windows, Installer)..."

    cd "${agent_src}"

    # Use make all to build everything
    if make all >> "${INSTALL_LOG}" 2>&1; then
        # Copy all built binaries to releases directory
        cp -f build/phpborg-agent-linux-amd64 "${releases_dir}/"
        cp -f build/phpborg-agent-linux-arm64 "${releases_dir}/"
        cp -f build/phpborg-agent-windows-amd64.exe "${releases_dir}/"
        cp -f build/phpborg-installer.exe "${releases_dir}/"

        # Set permissions
        chmod +x "${releases_dir}"/*
        chown phpborg:phpborg "${releases_dir}"/*

        # Generate checksums
        for binary in "${releases_dir}"/phpborg-agent-*; do
            sha256sum "${binary}" > "${binary}.sha256"
            chown phpborg:phpborg "${binary}.sha256"
        done

        log_success "All agents built successfully:"
        ls -lh "${releases_dir}"/*.exe "${releases_dir}"/phpborg-agent-* 2>/dev/null | while read line; do
            log_info "  $line"
        done

        save_state "build_agent" "completed"
        return 0
    else
        log_error "Failed to build phpborg-agent"
        return 1
    fi
}

#
# Main installation orchestrator
#

install_all_dependencies() {
    print_header "Installing Dependencies"

    # Update package cache
    print_section "Updating Package Cache"
    if run_with_progress "Updating package cache" "${PKG_UPDATE_CMD}"; then
        log_success "Package cache updated"
    else
        log_warn "Failed to update package cache (continuing anyway)"
    fi

    # Install in order
    local errors=0

    install_utilities || errors=$((errors + 1))
    install_php || errors=$((errors + 1))
    install_composer || errors=$((errors + 1))
    # Node.js installed via NVM in application setup (per-user, not system-wide)
    log_info "Node.js will be installed via NVM for phpborg user"
    install_database || errors=$((errors + 1))
    install_redis || errors=$((errors + 1))
    install_webserver || errors=$((errors + 1))
    install_docker || errors=$((errors + 1))
    install_borgbackup || errors=$((errors + 1))
    configure_fuse || errors=$((errors + 1))
    install_fuse_overlayfs || errors=$((errors + 1))
    install_golang || errors=$((errors + 1))
    install_certbot || errors=$((errors + 1))

    if [ ${errors} -eq 0 ]; then
        log_success "All dependencies installed successfully"
        save_state "dependencies" "completed"
        return 0
    else
        log_error "Failed to install some dependencies (${errors} error(s))"
        return 1
    fi
}
