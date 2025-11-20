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
            echo "docker.io docker-compose"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "docker docker-compose"
            ;;
        arch|manjaro)
            echo "docker docker-compose"
            ;;
        alpine)
            echo "docker docker-compose"
            ;;
    esac
}

# BorgBackup packages
get_borg_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "borgbackup"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "borgbackup"
            ;;
        arch|manjaro)
            echo "borg"
            ;;
        alpine)
            echo "borgbackup"
            ;;
    esac
}

# Utility packages
get_utility_packages() {
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo"
            ;;
        rhel|centos|rocky|almalinux|fedora)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo"
            ;;
        arch|manjaro)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo"
            ;;
        alpine)
            echo "curl wget git unzip jq openssl fuse-overlayfs acl sudo"
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
        debian|ubuntu|linuxmint|pop)
            log_info "Adding Ondrej PHP PPA for PHP 8.3"

            # Remove old Sury repo if exists (archived since Aug 2025)
            if [ -f /etc/apt/sources.list.d/sury-php.list ]; then
                log_info "Removing archived Sury repository configuration"
                rm -f /etc/apt/sources.list.d/sury-php.list
                rm -f /etc/apt/trusted.gpg.d/sury-php.gpg
            fi

            # Install prerequisites
            run_cmd "apt-get install -y software-properties-common"

            # Use PPA (Launchpad) - sury.org is archived since Aug 2025
            log_info "Adding ppa:ondrej/php (recommended method)"
            if timeout 30 add-apt-repository ppa:ondrej/php -y >> "${INSTALL_LOG}" 2>&1; then
                log_success "PHP PPA added successfully"
                run_cmd "${PKG_UPDATE_CMD}"
            else
                log_warn "PPA add failed or timed out, PHP 8.3 may not be available"
                log_warn "Will attempt to install PHP from system repositories"
            fi
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
# Package installation
#

install_utilities() {
    print_section "Installing Utility Packages"

    local packages=$(get_utility_packages)
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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

    log_info "Downloading Composer installer"

    local expected_signature
    expected_signature=$(curl -fsSL https://composer.github.io/installer.sig)

    if ! curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php; then
        log_error "Failed to download Composer installer"
        return 1
    fi

    local actual_signature
    actual_signature=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")

    if [ "${expected_signature}" != "${actual_signature}" ]; then
        log_error "Invalid Composer installer signature"
        rm /tmp/composer-setup.php
        return 1
    fi

    log_info "Installing Composer globally"

    if php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >> "${INSTALL_LOG}" 2>&1; then
        rm /tmp/composer-setup.php
        detect_composer
        log_success "Composer ${COMPOSER_VERSION} installed"
        save_state "install_composer" "completed"
        return 0
    else
        log_error "Failed to install Composer"
        rm /tmp/composer-setup.php
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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
    log_info "Installing: ${packages}"

    if run_cmd "${PKG_INSTALL_CMD} ${packages}"; then
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

install_fuse_overlayfs() {
    print_section "Installing fuse-overlayfs"

    if [ "${FUSE_OVERLAYFS_INSTALLED}" = "1" ]; then
        log_info "fuse-overlayfs already installed"
        save_state "install_fuse_overlayfs" "completed"
        return 0
    fi

    log_info "Installing: fuse-overlayfs"

    if run_cmd "${PKG_INSTALL_CMD} fuse-overlayfs"; then
        detect_fuse_overlayfs
        log_success "fuse-overlayfs installed"
        save_state "install_fuse_overlayfs" "completed"
        return 0
    else
        log_error "Failed to install fuse-overlayfs"
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
    if run_cmd "${PKG_UPDATE_CMD}"; then
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
    install_fuse_overlayfs || errors=$((errors + 1))

    if [ ${errors} -eq 0 ]; then
        log_success "All dependencies installed successfully"
        save_state "dependencies" "completed"
        return 0
    else
        log_error "Failed to install some dependencies (${errors} error(s))"
        return 1
    fi
}
