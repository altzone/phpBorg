#!/bin/bash
#
# phpBorg Bootstrap Installer
# One-line installer for phpBorg v1.0
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/main/bootstrap.sh | sudo bash
#
# Or with branch:
#   curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/claude/modernize-backup-app-php-011CUoNEPYmChtfxp7Kuz9yV/bootstrap.sh | sudo bash
#
# Or download and run:
#   wget https://raw.githubusercontent.com/altzone/phpBorg/main/bootstrap.sh
#   sudo bash bootstrap.sh
#

# Don't exit on error - we handle errors explicitly with error_exit
set +e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
INSTALL_DIR="${INSTALL_DIR:-/opt/newphpborg}"
REPO_URL="${REPO_URL:-https://github.com/altzone/phpBorg.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"

#
# Print banner
#

print_banner() {
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
    echo -e "${CYAN}Bootstrap Installer v1.0${NC}"
    echo -e "${CYAN}Enterprise Backup System${NC}"
    echo ""
    echo -e "${YELLOW}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

#
# Logging
#

log_info() {
    echo -e "${CYAN}ℹ${NC} $@"
}

log_success() {
    echo -e "${GREEN}✓${NC} $@"
}

log_warn() {
    echo -e "${YELLOW}⚠${NC} $@"
}

log_error() {
    echo -e "${RED}✗${NC} $@"
}

error_exit() {
    log_error "$@"
    echo ""
    echo -e "${RED}Bootstrap failed!${NC}"
    exit 1
}

#
# Pre-checks
#

check_root() {
    if [ "$EUID" -ne 0 ]; then
        error_exit "This script must be run as root. Use: sudo bash bootstrap.sh"
    fi
    log_success "Running as root"
}

check_os() {
    if [ ! -f /etc/os-release ]; then
        error_exit "Unable to detect OS (no /etc/os-release)"
    fi

    . /etc/os-release
    log_success "Detected OS: ${ID} ${VERSION_ID}"

    # Check if supported
    case "${ID}" in
        ubuntu|debian|centos|rhel|fedora|rocky|almalinux|arch|manjaro|alpine)
            log_success "OS is supported"
            ;;
        *)
            log_warn "OS '${ID}' is not officially tested but may work"
            ;;
    esac
}

check_internet() {
    log_info "Checking internet connectivity..."
    if ping -c 1 8.8.8.8 &> /dev/null; then
        log_success "Internet connection OK"
    else
        error_exit "No internet connection"
    fi
}

#
# Install prerequisites
#

install_prerequisites() {
    echo ""
    echo -e "${CYAN}▸ Installing Prerequisites${NC}"
    echo ""

    # Detect package manager
    if command -v apt-get &> /dev/null; then
        log_info "Using apt package manager"
        apt-get update -qq
        apt-get install -y -qq git curl wget > /dev/null 2>&1
    elif command -v dnf &> /dev/null; then
        log_info "Using dnf package manager"
        dnf install -y -q git curl wget > /dev/null 2>&1
    elif command -v yum &> /dev/null; then
        log_info "Using yum package manager"
        yum install -y -q git curl wget > /dev/null 2>&1
    elif command -v pacman &> /dev/null; then
        log_info "Using pacman package manager"
        pacman -Sy --noconfirm --needed git curl wget > /dev/null 2>&1
    elif command -v apk &> /dev/null; then
        log_info "Using apk package manager"
        apk add --no-cache git curl wget > /dev/null 2>&1
    else
        error_exit "No supported package manager found (apt, dnf, yum, pacman, apk)"
    fi

    log_success "Prerequisites installed (git, curl, wget)"
}

#
# Clone repository
#

clone_repository() {
    echo ""
    echo -e "${CYAN}▸ Cloning phpBorg Repository${NC}"
    echo ""

    # Create install directory
    if [ ! -d "${INSTALL_DIR}" ]; then
        log_info "Creating directory: ${INSTALL_DIR}"
        mkdir -p "${INSTALL_DIR}"
    fi

    cd "${INSTALL_DIR}" || error_exit "Failed to change to ${INSTALL_DIR}"

    # Check if already cloned
    if [ -d "phpBorg/.git" ]; then
        log_warn "phpBorg already exists, updating..."
        cd phpBorg || error_exit "Failed to change to phpBorg directory"

        # Check for local modifications
        if ! git diff-index --quiet HEAD -- 2>/dev/null; then
            log_warn "Local modifications detected, stashing changes..."
            git stash save "Bootstrap auto-stash $(date +%Y%m%d_%H%M%S)" > /dev/null 2>&1
        fi

        # Fetch latest changes
        git fetch origin > /dev/null 2>&1 || log_warn "Failed to fetch updates"

        # Try to checkout the branch
        if git checkout "${REPO_BRANCH}" > /dev/null 2>&1; then
            git pull origin "${REPO_BRANCH}" > /dev/null 2>&1 || log_warn "Failed to pull updates"
            log_success "Repository updated to ${REPO_BRANCH}"
        else
            # Branch doesn't exist, try default branch
            log_warn "Branch '${REPO_BRANCH}' not found, using current branch"
            git pull > /dev/null 2>&1 || log_warn "Failed to pull updates"
            log_success "Repository updated"
        fi
    else
        # Clone repository
        log_info "Cloning from: ${REPO_URL}"
        log_info "Branch: ${REPO_BRANCH}"

        if git clone -q -b "${REPO_BRANCH}" "${REPO_URL}" phpBorg; then
            log_success "Repository cloned"
            cd phpBorg
        else
            # Try without branch (might be main/master)
            log_warn "Branch '${REPO_BRANCH}' not found, trying default branch..."
            if git clone -q "${REPO_URL}" phpBorg; then
                log_success "Repository cloned (default branch)"
                cd phpBorg
            else
                error_exit "Failed to clone repository"
            fi
        fi
    fi

    # Verify installer exists
    if [ ! -f "install.sh" ]; then
        error_exit "install.sh not found in repository"
    fi

    log_success "Repository ready: ${INSTALL_DIR}/phpBorg"
}

#
# Set permissions
#

set_permissions() {
    log_info "Setting permissions..."
    chmod +x install.sh
    log_success "Permissions set"
}

#
# Display info
#

display_info() {
    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}Bootstrap Complete!${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${CYAN}Installation directory:${NC} ${INSTALL_DIR}/phpBorg"
    echo -e "${CYAN}Repository:${NC} ${REPO_URL}"
    echo -e "${CYAN}Branch:${NC} ${REPO_BRANCH}"
    echo ""
    echo -e "${CYAN}Next step:${NC} The main installer will now start..."
    echo ""
    echo -e "${YELLOW}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

#
# Run main installer
#

run_installer() {
    log_info "Starting main installer..."
    echo ""

    # Verify we're in the right directory
    if [ ! -f "install.sh" ]; then
        error_exit "install.sh not found. Current directory: $(pwd)"
    fi

    sleep 1

    # Run the main installer
    bash install.sh

    local exit_code=$?

    if [ ${exit_code} -eq 0 ]; then
        echo ""
        echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
        echo -e "${GREEN}🎉 phpBorg Installation Complete!${NC}"
        echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
        echo ""
    else
        echo ""
        echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
        echo -e "${RED}Installation encountered errors (exit code: ${exit_code})${NC}"
        echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
        echo ""
        echo -e "${YELLOW}Troubleshooting:${NC}"
        echo "  • Check logs: /var/log/phpborg-install.log"
        echo "  • Re-run: cd ${INSTALL_DIR}/phpBorg && sudo bash install.sh"
        echo "  • Support: https://github.com/altzone/phpBorg/issues"
        echo ""
    fi

    exit ${exit_code}
}

#
# Main
#

main() {
    # Display banner
    print_banner

    # Pre-checks
    log_info "Running pre-installation checks..."
    check_root
    check_os
    check_internet

    # Install prerequisites
    install_prerequisites

    # Clone repository
    clone_repository

    # Set permissions
    set_permissions

    # Display info
    display_info

    # Run main installer
    run_installer
}

# Run main function
main "$@"
