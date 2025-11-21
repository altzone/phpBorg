#!/bin/bash
#
# phpBorg Installation - OS & Service Detection
# Detects Linux distribution, package manager, and installed services
#

# OS Detection variables
export OS_TYPE=""           # linux, macos, etc
export OS_DISTRO=""         # debian, ubuntu, rhel, centos, fedora, arch, alpine
export OS_VERSION=""        # Version number
export OS_CODENAME=""       # Codename (e.g., bookworm, jammy)
export PKG_MANAGER=""       # apt, yum, dnf, pacman, apk
export PKG_UPDATE_CMD=""    # Command to update package cache
export PKG_INSTALL_CMD=""   # Command to install packages
export SERVICE_MANAGER=""   # systemd, openrc, init

#
# Detect Operating System
#

detect_os() {
    print_section "Detecting Operating System"

    # Detect OS type
    case "$(uname -s)" in
        Linux*)
            OS_TYPE="linux"
            ;;
        Darwin*)
            OS_TYPE="macos"
            error_exit "macOS is not supported yet"
            ;;
        *)
            error_exit "Unsupported operating system: $(uname -s)"
            ;;
    esac

    # Detect Linux distribution
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_DISTRO="${ID}"
        OS_VERSION="${VERSION_ID}"
        OS_CODENAME="${VERSION_CODENAME:-}"
    elif [ -f /etc/redhat-release ]; then
        OS_DISTRO="rhel"
        OS_VERSION=$(cat /etc/redhat-release | grep -oP '\d+\.\d+' | head -1)
    elif [ -f /etc/debian_version ]; then
        OS_DISTRO="debian"
        OS_VERSION=$(cat /etc/debian_version)
    else
        error_exit "Unable to detect Linux distribution"
    fi

    # Detect package manager
    case "${OS_DISTRO}" in
        debian|ubuntu|linuxmint|pop)
            PKG_MANAGER="apt"
            PKG_UPDATE_CMD="apt-get update"
            PKG_INSTALL_CMD="apt-get install -y"
            ;;
        rhel|centos|rocky|almalinux)
            if command -v dnf &> /dev/null; then
                PKG_MANAGER="dnf"
                PKG_UPDATE_CMD="dnf check-update"
                PKG_INSTALL_CMD="dnf install -y"
            else
                PKG_MANAGER="yum"
                PKG_UPDATE_CMD="yum check-update"
                PKG_INSTALL_CMD="yum install -y"
            fi
            ;;
        fedora)
            PKG_MANAGER="dnf"
            PKG_UPDATE_CMD="dnf check-update"
            PKG_INSTALL_CMD="dnf install -y"
            ;;
        arch|manjaro)
            PKG_MANAGER="pacman"
            PKG_UPDATE_CMD="pacman -Sy"
            PKG_INSTALL_CMD="pacman -S --noconfirm"
            ;;
        alpine)
            PKG_MANAGER="apk"
            PKG_UPDATE_CMD="apk update"
            PKG_INSTALL_CMD="apk add"
            ;;
        *)
            error_exit "Unsupported distribution: ${OS_DISTRO}"
            ;;
    esac

    # Detect service manager
    if command -v systemctl &> /dev/null; then
        SERVICE_MANAGER="systemd"
    elif command -v rc-service &> /dev/null; then
        SERVICE_MANAGER="openrc"
    else
        SERVICE_MANAGER="init"
        log_warn "Using legacy init system - some features may not work"
    fi

    log_success "OS detected: ${OS_DISTRO} ${OS_VERSION} (${OS_CODENAME})"
    log_info "Package manager: ${PKG_MANAGER}"
    log_info "Service manager: ${SERVICE_MANAGER}"

    save_state "os_detection" "completed" "{\"distro\":\"${OS_DISTRO}\",\"version\":\"${OS_VERSION}\"}"
}

#
# Check system requirements
#

check_system_requirements() {
    print_section "Checking System Requirements"

    local errors=0

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        log_error "This script must be run as root"
        errors=$((errors + 1))
    else
        log_success "Running as root"
    fi

    # Check CPU cores (minimum 2)
    local cpu_cores=$(get_cpu_cores)
    if [ "${cpu_cores}" -lt 2 ]; then
        log_warn "Only ${cpu_cores} CPU core(s) detected (recommended: 2+)"
    else
        log_success "CPU cores: ${cpu_cores}"
    fi

    # Check memory (minimum 2GB)
    local total_mem=$(get_total_memory)
    if [ "${total_mem}" -lt 2000 ]; then
        log_error "Only ${total_mem}MB RAM detected (minimum: 2GB)"
        errors=$((errors + 1))
    else
        log_success "Memory: ${total_mem}MB"
    fi

    # Check disk space (minimum 10GB free)
    local disk_space=$(df / | awk 'NR==2 {print $4}')
    local disk_space_gb=$((disk_space / 1024 / 1024))
    if [ "${disk_space_gb}" -lt 10 ]; then
        log_error "Only ${disk_space_gb}GB disk space available (minimum: 10GB)"
        errors=$((errors + 1))
    else
        log_success "Disk space: ${disk_space_gb}GB available"
    fi

    # Check internet connectivity
    if ping -c 1 8.8.8.8 &> /dev/null; then
        log_success "Internet connectivity: OK"
    else
        log_error "No internet connectivity"
        errors=$((errors + 1))
    fi

    if [ ${errors} -gt 0 ]; then
        error_exit "System requirements not met (${errors} error(s))"
    fi

    save_state "system_requirements" "completed"
}

#
# Detect installed services
#

detect_php() {
    # Try php8.3 first (preferred), then fallback to generic php
    if command -v php8.3 &> /dev/null; then
        local php_version=$(php8.3 -r 'echo PHP_VERSION;')
        export PHP_INSTALLED=1
        export PHP_VERSION="${php_version}"
        export PHP_BINARY=$(command -v php8.3)
        log_info "PHP ${php_version} detected at ${PHP_BINARY}"
        return 0
    elif command -v php &> /dev/null; then
        local php_version=$(php -r 'echo PHP_VERSION;')
        export PHP_INSTALLED=1
        export PHP_VERSION="${php_version}"
        export PHP_BINARY=$(command -v php)
        log_info "PHP ${php_version} detected at ${PHP_BINARY}"
        return 0
    else
        export PHP_INSTALLED=0
        log_debug "PHP not installed"
        return 1
    fi
}

detect_composer() {
    if command -v composer &> /dev/null; then
        local composer_version=$(composer --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
        export COMPOSER_INSTALLED=1
        export COMPOSER_VERSION="${composer_version}"
        export COMPOSER_BINARY=$(command -v composer)
        log_info "Composer ${composer_version} detected"
        return 0
    else
        export COMPOSER_INSTALLED=0
        log_debug "Composer not installed"
        return 1
    fi
}

detect_nodejs() {
    if command -v node &> /dev/null; then
        local node_version=$(node --version | tr -d 'v')
        export NODEJS_INSTALLED=1
        export NODEJS_VERSION="${node_version}"
        export NODEJS_BINARY=$(command -v node)
        log_info "Node.js ${node_version} detected"
        return 0
    else
        export NODEJS_INSTALLED=0
        log_debug "Node.js not installed"
        return 1
    fi
}

detect_npm() {
    if command -v npm &> /dev/null; then
        local npm_version=$(npm --version)
        export NPM_INSTALLED=1
        export NPM_VERSION="${npm_version}"
        export NPM_BINARY=$(command -v npm)
        log_info "npm ${npm_version} detected"
        return 0
    else
        export NPM_INSTALLED=0
        log_debug "npm not installed"
        return 1
    fi
}

detect_docker() {
    if command -v docker &> /dev/null; then
        local docker_version=$(docker --version | grep -oP '\d+\.\d+\.\d+' | head -1)
        export DOCKER_INSTALLED=1
        export DOCKER_VERSION="${docker_version}"
        export DOCKER_BINARY=$(command -v docker)
        log_info "Docker ${docker_version} detected"
        return 0
    else
        export DOCKER_INSTALLED=0
        log_debug "Docker not installed"
        return 1
    fi
}

detect_mysql() {
    if command -v mysql &> /dev/null; then
        local mysql_version=$(mysql --version | grep -oP '\d+\.\d+\.\d+' | head -1)
        export MYSQL_INSTALLED=1
        export MYSQL_VERSION="${mysql_version}"
        export MYSQL_BINARY=$(command -v mysql)
        log_info "MySQL/MariaDB ${mysql_version} detected"
        return 0
    else
        export MYSQL_INSTALLED=0
        log_debug "MySQL not installed"
        return 1
    fi
}

detect_redis() {
    if command -v redis-cli &> /dev/null; then
        local redis_version=$(redis-cli --version | grep -oP '\d+\.\d+\.\d+' | head -1)
        export REDIS_INSTALLED=1
        export REDIS_VERSION="${redis_version}"
        export REDIS_BINARY=$(command -v redis-server)
        log_info "Redis ${redis_version} detected"
        return 0
    else
        export REDIS_INSTALLED=0
        log_debug "Redis not installed"
        return 1
    fi
}

#
# Detect what's listening on port 80 using ss
#
detect_webserver_listening() {
    # Check what process is listening on port 80
    local listening_process=$(ss -tlnp 2>/dev/null | grep ':80 ' | grep -oP 'users:\(\("\K[^"]+' | head -1)

    if [ -n "${listening_process}" ]; then
        export WEBSERVER_LISTENING="${listening_process}"
        log_info "Port 80 is used by: ${listening_process}"
        return 0
    else
        export WEBSERVER_LISTENING=""
        log_debug "Nothing listening on port 80"
        return 1
    fi
}

detect_nginx() {
    # First check if nginx is actually listening (any port)
    if ss -tlnp 2>/dev/null | grep -q 'nginx'; then
        local nginx_version=$(nginx -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
        export NGINX_INSTALLED=1
        export NGINX_RUNNING=1
        export NGINX_VERSION="${nginx_version}"
        export NGINX_BINARY=$(command -v nginx)
        log_info "Nginx ${nginx_version} detected (listening)"
        return 0
    # Fallback: check if service is running
    elif systemctl is-active --quiet nginx 2>/dev/null; then
        local nginx_version=$(nginx -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
        export NGINX_INSTALLED=1
        export NGINX_RUNNING=1
        export NGINX_VERSION="${nginx_version}"
        export NGINX_BINARY=$(command -v nginx)
        log_info "Nginx ${nginx_version} detected (service running)"
        return 0
    # Just binary present but not running
    elif command -v nginx &> /dev/null; then
        local nginx_version=$(nginx -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
        export NGINX_INSTALLED=1
        export NGINX_RUNNING=0
        export NGINX_VERSION="${nginx_version}"
        export NGINX_BINARY=$(command -v nginx)
        log_info "Nginx ${nginx_version} detected (not running)"
        return 0
    else
        export NGINX_INSTALLED=0
        export NGINX_RUNNING=0
        log_debug "Nginx not installed"
        return 1
    fi
}

detect_apache() {
    # First check if apache is actually listening (any port)
    if ss -tlnp 2>/dev/null | grep -qE 'apache2|httpd'; then
        local apache_binary="apache2"
        command -v apache2 &>/dev/null || apache_binary="httpd"
        local apache_version=$(${apache_binary} -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
        export APACHE_INSTALLED=1
        export APACHE_RUNNING=1
        export APACHE_VERSION="${apache_version}"
        export APACHE_BINARY="${apache_binary}"
        log_info "Apache ${apache_version} detected (listening)"
        return 0
    # Fallback: check if service is running
    elif systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then
        local apache_binary="apache2"
        command -v apache2 &>/dev/null || apache_binary="httpd"
        local apache_version=$(${apache_binary} -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
        export APACHE_INSTALLED=1
        export APACHE_RUNNING=1
        export APACHE_VERSION="${apache_version}"
        export APACHE_BINARY="${apache_binary}"
        log_info "Apache ${apache_version} detected (service running)"
        return 0
    # Check if properly installed (binary + config dir)
    elif command -v apache2 &> /dev/null || command -v httpd &> /dev/null; then
        local apache_binary="apache2"
        command -v apache2 &>/dev/null || apache_binary="httpd"

        # Must have config directory with sites-available (Debian) or conf.d (RHEL)
        if [ -d "/etc/apache2/sites-available" ] || [ -d "/etc/httpd/conf.d" ]; then
            local apache_version=$(${apache_binary} -v 2>&1 | grep -oP '\d+\.\d+\.\d+' | head -1)
            export APACHE_INSTALLED=1
            export APACHE_RUNNING=0
            export APACHE_VERSION="${apache_version}"
            export APACHE_BINARY="${apache_binary}"
            log_info "Apache ${apache_version} detected (not running)"
            return 0
        else
            log_debug "Apache binary found but not properly configured (missing sites-available)"
        fi
    fi

    export APACHE_INSTALLED=0
    export APACHE_RUNNING=0
    log_debug "Apache not installed"
    return 1
}

detect_borgbackup() {
    if command -v borg &> /dev/null; then
        local borg_version=$(borg --version | grep -oP '\d+\.\d+\.\d+' | head -1)
        export BORG_INSTALLED=1
        export BORG_VERSION="${borg_version}"
        export BORG_BINARY=$(command -v borg)
        log_info "BorgBackup ${borg_version} detected"
        return 0
    else
        export BORG_INSTALLED=0
        log_debug "BorgBackup not installed"
        return 1
    fi
}

detect_fuse_overlayfs() {
    if command -v fuse-overlayfs &> /dev/null; then
        export FUSE_OVERLAYFS_INSTALLED=1
        export FUSE_OVERLAYFS_BINARY=$(command -v fuse-overlayfs)
        log_info "fuse-overlayfs detected"
        return 0
    else
        export FUSE_OVERLAYFS_INSTALLED=0
        log_debug "fuse-overlayfs not installed"
        return 1
    fi
}

#
# Detect all services at once
#

detect_all_services() {
    print_section "Detecting Installed Services"

    detect_php
    detect_composer
    detect_nodejs
    detect_npm
    detect_docker
    detect_mysql
    detect_redis
    detect_nginx
    detect_apache
    detect_borgbackup
    detect_fuse_overlayfs

    log_success "Service detection completed"
    save_state "service_detection" "completed"
}

#
# Web server selection
# Priority: running webserver > installed and configured > install nginx
#

select_webserver() {
    # Priority 1: Use webserver that's actually running on port 80
    if [ "${NGINX_RUNNING}" = "1" ]; then
        export WEBSERVER="nginx"
        log_info "Web server: nginx (running on port 80)"
        return
    elif [ "${APACHE_RUNNING}" = "1" ]; then
        export WEBSERVER="apache"
        log_info "Web server: apache (running on port 80)"
        return
    fi

    # Priority 2: Both installed and properly configured - ask user
    if [ "${NGINX_INSTALLED}" = "1" ] && [ "${APACHE_INSTALLED}" = "1" ]; then
        if [ "${INSTALL_MODE}" = "auto" ]; then
            export WEBSERVER="nginx"
            log_info "Web server: nginx (auto-selected, both available)"
        else
            echo ""
            echo "Both Nginx and Apache are installed."
            echo "1) Nginx (recommended)"
            echo "2) Apache"
            echo -ne "${CYAN}?${NC} Select web server [1]: "
            read choice < /dev/tty
            choice="${choice:-1}"
            case ${choice} in
                1) export WEBSERVER="nginx" ;;
                2) export WEBSERVER="apache" ;;
                *) export WEBSERVER="nginx" ;;
            esac
        fi
        return
    fi

    # Priority 3: One is installed and properly configured
    if [ "${NGINX_INSTALLED}" = "1" ]; then
        export WEBSERVER="nginx"
        log_info "Web server: nginx (installed)"
        return
    elif [ "${APACHE_INSTALLED}" = "1" ]; then
        export WEBSERVER="apache"
        log_info "Web server: apache (installed)"
        return
    fi

    # Priority 4: Nothing properly installed - will install nginx
    export WEBSERVER="nginx"
    log_info "Web server: nginx (will be installed)"
}
