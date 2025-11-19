#!/bin/bash
#
# phpBorg Installation - Common Functions
# Provides utilities for logging, colors, prompts, and error handling
#

# Colors
export RED='\033[0;31m'
export GREEN='\033[0;32m'
export YELLOW='\033[1;33m'
export BLUE='\033[0;34m'
export CYAN='\033[0;36m'
export NC='\033[0m' # No Color

# Installation state
export INSTALL_LOG="/var/log/phpborg-install.log"
export INSTALL_STATE="/tmp/phpborg-install-state.json"
export INSTALL_BACKUP_DIR="/tmp/phpborg-install-backup-$(date +%s)"

# Installation mode (auto or interactive)
export INSTALL_MODE="${INSTALL_MODE:-interactive}"

# Installation directory
export PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"

#
# Logging functions
#

log() {
    local level="$1"
    shift
    local message="$@"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    echo "[${timestamp}] [${level}] ${message}" >> "${INSTALL_LOG}"

    case "${level}" in
        INFO)
            echo -e "${CYAN}ℹ${NC} ${message}"
            ;;
        SUCCESS)
            echo -e "${GREEN}✓${NC} ${message}"
            ;;
        WARN)
            echo -e "${YELLOW}⚠${NC} ${message}"
            ;;
        ERROR)
            echo -e "${RED}✗${NC} ${message}"
            ;;
        DEBUG)
            [ "${DEBUG:-0}" = "1" ] && echo -e "${BLUE}⋮${NC} ${message}"
            ;;
    esac
}

log_info() { log "INFO" "$@"; }
log_success() { log "SUCCESS" "$@"; }
log_warn() { log "WARN" "$@"; }
log_error() { log "ERROR" "$@"; }
log_debug() { log "DEBUG" "$@"; }

#
# Error handling
#

error_exit() {
    log_error "$@"
    echo ""
    echo -e "${RED}Installation failed!${NC}"
    echo -e "Check logs: ${INSTALL_LOG}"
    echo ""
    exit 1
}

#
# User prompts
#

prompt() {
    local question="$1"
    local default="$2"
    local var_name="$3"

    # Auto mode: use default
    if [ "${INSTALL_MODE}" = "auto" ]; then
        eval "${var_name}='${default}'"
        log_debug "Auto mode: ${var_name}=${default}"
        return
    fi

    # Interactive mode: ask user
    if [ -n "${default}" ]; then
        read -p "$(echo -e ${CYAN}?${NC}) ${question} [${default}]: " response
        response="${response:-${default}}"
    else
        read -p "$(echo -e ${CYAN}?${NC}) ${question}: " response
    fi

    eval "${var_name}='${response}'"
}

prompt_password() {
    local question="$1"
    local var_name="$2"
    local default="$3"

    # Auto mode: generate random password if no default
    if [ "${INSTALL_MODE}" = "auto" ]; then
        if [ -n "${default}" ]; then
            eval "${var_name}='${default}'"
        else
            local random_pwd=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-24)
            eval "${var_name}='${random_pwd}'"
        fi
        return
    fi

    # Interactive mode: ask with masked input
    read -sp "$(echo -e ${CYAN}?${NC}) ${question}: " response
    echo ""

    if [ -z "${response}" ] && [ -n "${default}" ]; then
        response="${default}"
    fi

    eval "${var_name}='${response}'"
}

confirm() {
    local question="$1"
    local default="${2:-y}"

    # Auto mode: always yes
    if [ "${INSTALL_MODE}" = "auto" ]; then
        return 0
    fi

    # Interactive mode: ask user
    local prompt_text="$(echo -e ${CYAN}?${NC}) ${question} [y/N]: "
    if [ "${default}" = "y" ]; then
        prompt_text="$(echo -e ${CYAN}?${NC}) ${question} [Y/n]: "
    fi

    read -p "${prompt_text}" response
    response="${response:-${default}}"

    [[ "${response}" =~ ^[Yy] ]]
}

#
# State management (for idempotence)
#

save_state() {
    local step="$1"
    local status="$2"
    local data="${3:-{}}"

    # Create state file if not exists
    if [ ! -f "${INSTALL_STATE}" ]; then
        echo '{}' > "${INSTALL_STATE}"
    fi

    # Update state using jq
    if command -v jq &> /dev/null; then
        local timestamp=$(date -Iseconds)
        jq --arg step "${step}" \
           --arg status "${status}" \
           --arg timestamp "${timestamp}" \
           --argjson data "${data}" \
           '.[$step] = {status: $status, timestamp: $timestamp, data: $data}' \
           "${INSTALL_STATE}" > "${INSTALL_STATE}.tmp"
        mv "${INSTALL_STATE}.tmp" "${INSTALL_STATE}"
    fi
}

get_state() {
    local step="$1"

    if [ ! -f "${INSTALL_STATE}" ]; then
        echo "not_started"
        return
    fi

    if command -v jq &> /dev/null; then
        jq -r ".\"${step}\".status // \"not_started\"" "${INSTALL_STATE}"
    else
        echo "not_started"
    fi
}

is_step_completed() {
    local step="$1"
    [ "$(get_state "${step}")" = "completed" ]
}

#
# Backup & Rollback
#

backup_file() {
    local file="$1"

    if [ -f "${file}" ]; then
        local backup_path="${INSTALL_BACKUP_DIR}/$(dirname ${file})"
        mkdir -p "${backup_path}"
        cp -a "${file}" "${backup_path}/"
        log_debug "Backed up: ${file}"
    fi
}

backup_dir() {
    local dir="$1"

    if [ -d "${dir}" ]; then
        local backup_path="${INSTALL_BACKUP_DIR}/$(dirname ${dir})"
        mkdir -p "${backup_path}"
        cp -ar "${dir}" "${backup_path}/"
        log_debug "Backed up: ${dir}"
    fi
}

#
# Command execution with error handling
#

run_cmd() {
    local cmd="$@"

    log_debug "Running: ${cmd}"

    if eval "${cmd}" >> "${INSTALL_LOG}" 2>&1; then
        return 0
    else
        local exit_code=$?
        log_error "Command failed (exit ${exit_code}): ${cmd}"
        return ${exit_code}
    fi
}

run_cmd_silent() {
    eval "$@" > /dev/null 2>&1
}

#
# Service management
#

is_service_running() {
    local service="$1"
    systemctl is-active --quiet "${service}" 2>/dev/null
}

is_service_enabled() {
    local service="$1"
    systemctl is-enabled --quiet "${service}" 2>/dev/null
}

#
# Version comparison
#

version_ge() {
    # Returns 0 if $1 >= $2
    [ "$(printf '%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

#
# Spinner (for long operations)
#

spinner() {
    local pid=$1
    local message="${2:-Processing...}"
    local delay=0.1
    local spinstr='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'

    echo -n " "
    while ps -p $pid > /dev/null 2>&1; do
        local temp=${spinstr#?}
        printf "\r%s %s" "${spinstr:0:1}" "${message}"
        spinstr=$temp${spinstr%"$temp"}
        sleep $delay
    done
    printf "\r"
}

#
# Progress bar
#

progress_bar() {
    local current=$1
    local total=$2
    local width=50
    local percent=$((current * 100 / total))
    local filled=$((width * current / total))
    local empty=$((width - filled))

    printf "\r["
    printf "%${filled}s" | tr ' ' '='
    printf "%${empty}s" | tr ' ' ' '
    printf "] %3d%%" ${percent}
}

#
# Header/Section display
#

print_header() {
    local title="$1"
    local width=60

    echo ""
    echo -e "${BLUE}┌$(printf '─%.0s' $(seq 1 $((width-2))))┐${NC}"
    printf "${BLUE}│${NC}%-$((width-2))s${BLUE}│${NC}\n" " ${title}"
    echo -e "${BLUE}└$(printf '─%.0s' $(seq 1 $((width-2))))┘${NC}"
    echo ""
}

print_section() {
    local title="$1"
    echo ""
    echo -e "${CYAN}▸ ${title}${NC}"
    echo ""
}

#
# System info
#

get_total_memory() {
    free -m | awk '/^Mem:/{print $2}'
}

get_cpu_cores() {
    nproc
}

get_disk_space() {
    df -h / | awk 'NR==2 {print $4}'
}

#
# Initialize logging
#

init_logging() {
    # Create log directory if needed
    mkdir -p "$(dirname ${INSTALL_LOG})"

    # Rotate old log if exists
    if [ -f "${INSTALL_LOG}" ]; then
        mv "${INSTALL_LOG}" "${INSTALL_LOG}.old"
    fi

    # Start new log
    {
        echo "========================================="
        echo "phpBorg Installation Log"
        echo "Started: $(date)"
        echo "Mode: ${INSTALL_MODE}"
        echo "========================================="
        echo ""
    } > "${INSTALL_LOG}"

    # Create backup directory
    mkdir -p "${INSTALL_BACKUP_DIR}"
}

#
# Cleanup on exit
#

cleanup() {
    log_debug "Cleanup called"
    # Add cleanup tasks here if needed
}

trap cleanup EXIT
