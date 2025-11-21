#!/bin/bash
#
# phpBorg Universal Installer
# Version 1.0.3
#
# Supports: Debian, Ubuntu, RHEL, CentOS, Fedora, Arch, Alpine
# Modes: Interactive, Automatic
# Features: Idempotent, Multi-distribution, State tracking
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/master/install.sh | sudo bash
#

# Exit on error during bootstrap only
set -e

# Installation directory (can be overridden via environment)
export PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"

# Installation mode (interactive or auto)
export INSTALL_MODE="${INSTALL_MODE:-interactive}"

# GitHub repository
GITHUB_REPO="altzone/phpBorg"
GITHUB_BRANCH="${GITHUB_BRANCH:-master}"

#
# Check if running as root
#
check_root() {
    if [ "$EUID" -ne 0 ]; then
        echo "ERROR: This script must be run as root"
        echo "Usage: curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/master/install.sh | sudo bash"
        exit 1
    fi
}

#
# Install git if not present
#
install_git() {
    if command -v git &> /dev/null; then
        return 0
    fi

    echo "→ Git not found, installing..."

    if command -v apt-get &> /dev/null; then
        apt-get update -qq && apt-get install -y -qq git
    elif command -v dnf &> /dev/null; then
        dnf install -y -q git
    elif command -v yum &> /dev/null; then
        yum install -y -q git
    elif command -v pacman &> /dev/null; then
        pacman -Sy --noconfirm --quiet git
    elif command -v apk &> /dev/null; then
        apk add --quiet git
    else
        echo "ERROR: Cannot detect package manager to install git."
        echo "Please install git manually and re-run the installer."
        exit 1
    fi

    # Verify installation
    if ! command -v git &> /dev/null; then
        echo "ERROR: Failed to install git"
        exit 1
    fi

    echo "✓ Git installed"
}

#
# Bootstrap: Clone repository if not present
#
bootstrap_repository() {
    # Check if we already have the full installation
    if [ -f "${PHPBORG_ROOT}/install/lib/common.sh" ]; then
        return 0
    fi

    echo ""
    echo "╔══════════════════════════════════════════════════════════╗"
    echo "║         phpBorg Universal Installer - Bootstrap          ║"
    echo "╚══════════════════════════════════════════════════════════╝"
    echo ""

    # Check root first
    check_root

    # Install git if needed
    install_git

    # Create parent directory
    mkdir -p "$(dirname ${PHPBORG_ROOT})"

    # Clone or update repository
    if [ -d "${PHPBORG_ROOT}/.git" ]; then
        echo "→ Updating existing repository..."
        cd "${PHPBORG_ROOT}"
        git fetch origin --quiet
        git checkout "${GITHUB_BRANCH}" --quiet 2>/dev/null || true
        git reset --hard "origin/${GITHUB_BRANCH}" --quiet
        echo "✓ Repository updated"
    else
        # Remove incomplete installation if exists
        if [ -d "${PHPBORG_ROOT}" ]; then
            echo "→ Removing incomplete installation..."
            rm -rf "${PHPBORG_ROOT}"
        fi
        echo "→ Cloning phpBorg repository from GitHub..."
        git clone -b "${GITHUB_BRANCH}" --quiet "https://github.com/${GITHUB_REPO}.git" "${PHPBORG_ROOT}"
        echo "✓ Repository cloned to ${PHPBORG_ROOT}"
    fi

    echo ""
    echo "→ Starting installation..."
    echo ""

    # Re-execute the script from the cloned repository
    exec "${PHPBORG_ROOT}/install.sh" "$@"
}

# Always run bootstrap check
bootstrap_repository "$@"

# After bootstrap, disable exit on error (we handle errors ourselves)
set +e

# Load common functions
if [ -f "${PHPBORG_ROOT}/install/lib/common.sh" ]; then
    source "${PHPBORG_ROOT}/install/lib/common.sh"
else
    echo "ERROR: common.sh not found"
    exit 1
fi

# Initialize logging
init_logging

#
# Welcome banner
#

print_welcome() {
    clear
    echo -e "${BLUE}"
    cat << "EOF"
    ____  __  ______  ____
   / __ \/ / / / __ \/ __ )____  _________ _
  / /_/ / /_/ / /_/ / __  / __ \/ ___/ __ `/
 / ____/ __  / ____/ /_/ / /_/ / /  / /_/ /
/_/   /_/ /_/_/   /_____/\____/_/   \__, /
                                   /____/
EOF
    echo -e "${NC}"
    echo -e "${CYAN}Universal Installer v1.0.0${NC}"
    echo -e "${CYAN}Enterprise Backup System${NC}"
    echo ""
    echo -e "${YELLOW}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

#
# Pre-installation checks
#

pre_installation_checks() {
    print_header "Pre-Installation Checks"

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        error_exit "This script must be run as root"
    fi

    log_success "Running as root"

    # Check if in correct directory
    if [ ! -f "${PHPBORG_ROOT}/composer.json" ]; then
        error_exit "phpBorg source code not found in ${PHPBORG_ROOT}"
    fi

    log_success "phpBorg source code found"

    # Display installation mode
    if [ "${INSTALL_MODE}" = "auto" ]; then
        log_info "Installation mode: AUTOMATIC"
    else
        log_info "Installation mode: INTERACTIVE"
    fi

    log_success "Pre-installation checks passed"
}

#
# Installation summary
#

print_installation_summary() {
    print_header "Installation Summary"

    echo ""
    echo -e "${CYAN}The following components will be installed:${NC}"
    echo ""
    echo "  • System dependencies (PHP 8.1+, Node.js 18+, MariaDB, Redis, Docker, Borg)"
    echo "  • Composer dependencies"
    echo "  • Database setup and schema import"
    echo "  • Admin user creation"
    echo "  • Systemd services (scheduler + worker pool)"
    echo "  • Web server configuration (Nginx or Apache)"
    echo "  • Frontend build and deployment"
    echo ""
    echo -e "${CYAN}Installation location:${NC} ${PHPBORG_ROOT}"
    echo -e "${CYAN}Installation mode:${NC} ${INSTALL_MODE}"
    echo ""
    echo -e "${YELLOW}═══════════════════════════════════════════════════════════${NC}"
    echo ""

    if [ "${INSTALL_MODE}" = "interactive" ]; then
        if ! confirm "Continue with installation?" "y"; then
            echo ""
            log_info "Installation cancelled by user"
            exit 0
        fi
    fi

    echo ""
}

#
# Load installation modules
#

load_modules() {
    local modules=(
        "detect"
        "deps"
        "database"
        "application"
        "services"
        "webserver"
        "frontend"
    )

    for module in "${modules[@]}"; do
        local module_file="${PHPBORG_ROOT}/install/lib/${module}.sh"
        if [ -f "${module_file}" ]; then
            source "${module_file}"
            log_debug "Loaded module: ${module}.sh"
        else
            error_exit "Module not found: ${module_file}"
        fi
    done

    log_success "All modules loaded"
}

#
# Main installation process
#

run_installation() {
    print_header "Starting Installation"

    local start_time=$(date +%s)
    local errors=0

    # Phase 1: System Detection
    if ! is_step_completed "os_detection"; then
        detect_os || errors=$((errors + 1))
    else
        log_info "OS detection already completed (skipped)"
    fi

    if ! is_step_completed "system_requirements"; then
        check_system_requirements || errors=$((errors + 1))
    else
        log_info "System requirements already checked (skipped)"
    fi

    if ! is_step_completed "service_detection"; then
        detect_all_services || errors=$((errors + 1))
    else
        log_info "Service detection already completed (skipped)"
    fi

    # Web server selection
    select_webserver

    # Phase 2: Dependencies Installation
    if ! is_step_completed "dependencies"; then
        install_all_dependencies || errors=$((errors + 1))
    else
        log_info "Dependencies already installed (skipped)"
    fi

    # Phase 3: Database Setup
    if ! is_step_completed "database_setup"; then
        setup_database || errors=$((errors + 1))
    else
        log_info "Database already setup (skipped)"
    fi

    # Phase 4: Application Setup
    if ! is_step_completed "application_setup"; then
        setup_application || errors=$((errors + 1))
    else
        log_info "Application already setup (skipped)"
    fi

    # Phase 5: Services Setup
    if ! is_step_completed "services_setup"; then
        setup_services || errors=$((errors + 1))
    else
        log_info "Services already setup (skipped)"
    fi

    # Phase 6: Web Server Setup
    if ! is_step_completed "webserver_setup"; then
        setup_webserver || errors=$((errors + 1))
    else
        log_info "Web server already setup (skipped)"
    fi

    # Phase 7: Frontend Setup
    if ! is_step_completed "frontend_setup"; then
        setup_frontend || errors=$((errors + 1))
    else
        log_info "Frontend already setup (skipped)"
    fi

    # Calculate installation time
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    local minutes=$((duration / 60))
    local seconds=$((duration % 60))

    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"

    if [ ${errors} -eq 0 ]; then
        print_header "✓ Installation Complete!"
        log_success "phpBorg installed successfully in ${minutes}m ${seconds}s"
    else
        print_header "⚠ Installation Completed with Warnings"
        log_warn "Installation completed with ${errors} error(s) in ${minutes}m ${seconds}s"
    fi

    echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

#
# Post-installation information
#

print_post_installation_info() {
    print_header "Post-Installation Information"

    echo ""
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}Installation Complete!${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo ""

    # Admin credentials
    if [ -f "${PHPBORG_ROOT}/.install-credentials" ]; then
        echo -e "${GREEN}Admin Credentials:${NC}"
        echo -e "${YELLOW}────────────────────────────────────────────────────────${NC}"
        cat "${PHPBORG_ROOT}/.install-credentials"
        echo -e "${YELLOW}────────────────────────────────────────────────────────${NC}"
        echo ""
        echo -e "${RED}⚠ IMPORTANT: Save these credentials and delete the file!${NC}"
        echo -e "   ${PHPBORG_ROOT}/.install-credentials"
        echo ""
    fi

    # Access information
    echo -e "${GREEN}Web Interface:${NC}"
    if [ "${DOMAIN}" = "localhost" ]; then
        echo "  • http://localhost"
        echo "  • http://127.0.0.1"
    else
        echo "  • http://${DOMAIN}"
    fi
    echo ""

    # Service status
    echo -e "${GREEN}Services:${NC}"
    echo "  • Scheduler: $(systemctl is-active phpborg-scheduler)"
    echo "  • Workers: $(systemctl is-active phpborg-workers.target)"
    echo "  • MariaDB: $(systemctl is-active mariadb)"
    echo "  • Redis: $(systemctl is-active redis-server || systemctl is-active redis)"
    echo "  • ${WEBSERVER^}: $(systemctl is-active ${WEBSERVER})"
    echo ""

    # Useful commands
    echo -e "${GREEN}Useful Commands:${NC}"
    echo "  • Check service status:    systemctl status phpborg-scheduler"
    echo "  • View logs:               journalctl -u phpborg-scheduler -f"
    echo "  • Restart workers:         systemctl restart phpborg-workers.target"
    echo "  • View application logs:   tail -f ${PHPBORG_ROOT}/var/log/*.log"
    echo ""

    # Next steps
    echo -e "${GREEN}Next Steps:${NC}"
    echo "  1. Access the web interface and login with admin credentials"
    echo "  2. Configure your first backup server"
    echo "  3. Create a backup job"
    echo "  4. Configure SSL/TLS for production (optional)"
    echo "  5. Setup email notifications (optional)"
    echo ""

    # Documentation
    echo -e "${GREEN}Documentation:${NC}"
    echo "  • Installation log: ${INSTALL_LOG}"
    echo "  • State file: ${INSTALL_STATE}"
    echo "  • Backup directory: ${INSTALL_BACKUP_DIR}"
    echo ""

    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${GREEN}Thank you for choosing phpBorg!${NC}"
    echo ""
}

#
# Cleanup on exit
#

cleanup_on_exit() {
    local exit_code=$?

    if [ ${exit_code} -ne 0 ]; then
        echo ""
        echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
        echo -e "${RED}Installation Failed!${NC}"
        echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
        echo ""
        echo -e "${YELLOW}Troubleshooting:${NC}"
        echo "  • Check installation log: ${INSTALL_LOG}"
        echo "  • Check state file: ${INSTALL_STATE}"
        echo "  • Restore from backup: ${INSTALL_BACKUP_DIR}"
        echo "  • Re-run installer (idempotent - safe to retry)"
        echo ""
        echo -e "${YELLOW}Support:${NC}"
        echo "  • GitHub: https://github.com/altzone/phpBorg"
        echo "  • Issues: https://github.com/altzone/phpBorg/issues"
        echo ""
    fi
}

trap cleanup_on_exit EXIT

#
# Main
#

main() {
    # Display welcome banner
    print_welcome

    # Pre-installation checks
    pre_installation_checks

    # Load installation modules
    load_modules

    # Display installation summary
    print_installation_summary

    # Run installation
    run_installation

    # Display post-installation information
    print_post_installation_info
}

# Run main function
main "$@"
