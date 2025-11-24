#!/bin/bash
#
# phpBorg Emergency Restore Script
#
# This script allows you to restore phpBorg from a backup when the web interface
# is not accessible. It provides an interactive menu to select and restore backups.
#
# Usage: sudo bash bin/emergency-restore.sh
#
# Requirements:
# - Root access (sudo)
# - Database credentials
# - Backup files accessible
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHPBORG_ROOT="$(dirname "$SCRIPT_DIR")"

# Default backup path
DEFAULT_BACKUP_PATH="/opt/backups/phpborg-self-backups"

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}❌ This script must be run as root (sudo)${NC}"
   exit 1
fi

# Banner
echo -e "${CYAN}${BOLD}"
cat << "EOF"
╔═══════════════════════════════════════════════════════╗
║     phpBorg Emergency Restore Utility                 ║
║     Restore phpBorg from backup via CLI               ║
╚═══════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

# Load environment variables
ENV_FILE="${PHPBORG_ROOT}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo -e "${RED}❌ .env file not found: $ENV_FILE${NC}"
    exit 1
fi

echo -e "${BLUE}📋 Loading configuration...${NC}"
source "$ENV_FILE"

# Database credentials
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-phpborg}"
DB_USER="${DB_USER:-phpborg}"
DB_PASSWORD="${DB_PASSWORD}"

if [[ -z "$DB_PASSWORD" ]]; then
    echo -e "${RED}❌ DB_PASSWORD not found in .env${NC}"
    exit 1
fi

# Ask for backup directory
echo ""
echo -e "${YELLOW}📁 Backup Directory${NC}"
read -p "Enter backup directory path [${DEFAULT_BACKUP_PATH}]: " BACKUP_PATH
BACKUP_PATH="${BACKUP_PATH:-$DEFAULT_BACKUP_PATH}"

if [[ ! -d "$BACKUP_PATH" ]]; then
    echo -e "${RED}❌ Backup directory not found: $BACKUP_PATH${NC}"
    exit 1
fi

# List available backups
echo ""
echo -e "${BLUE}🔍 Scanning for backups...${NC}"
mapfile -t BACKUPS < <(find "$BACKUP_PATH" -maxdepth 1 -type f \( -name "phpborg_backup_*.tar.gz" -o -name "phpborg_backup_*.tar.gz.enc" \) | sort -r)

if [[ ${#BACKUPS[@]} -eq 0 ]]; then
    echo -e "${RED}❌ No backups found in $BACKUP_PATH${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Found ${#BACKUPS[@]} backup(s)${NC}"
echo ""

# Display backups menu
echo -e "${CYAN}${BOLD}Available Backups:${NC}"
echo ""
for i in "${!BACKUPS[@]}"; do
    backup_file="${BACKUPS[$i]}"
    backup_name=$(basename "$backup_file")
    backup_size=$(du -h "$backup_file" | cut -f1)
    backup_date=$(stat -c %y "$backup_file" | cut -d' ' -f1,2 | cut -d'.' -f1)

    # Check if encrypted
    if [[ "$backup_file" == *.enc ]]; then
        encrypted="${YELLOW}🔒 Encrypted${NC}"
    else
        encrypted="${GREEN}🔓 Unencrypted${NC}"
    fi

    echo -e "${BOLD}[$((i+1))]${NC} $backup_name"
    echo -e "    📅 Created: $backup_date"
    echo -e "    💾 Size: $backup_size"
    echo -e "    $encrypted"
    echo ""
done

# Select backup
echo -e "${YELLOW}${BOLD}Select a backup to restore:${NC}"
read -p "Enter number [1-${#BACKUPS[@]}] or 'q' to quit: " choice

if [[ "$choice" == "q" ]] || [[ "$choice" == "Q" ]]; then
    echo -e "${YELLOW}Cancelled by user${NC}"
    exit 0
fi

if ! [[ "$choice" =~ ^[0-9]+$ ]] || [[ $choice -lt 1 ]] || [[ $choice -gt ${#BACKUPS[@]} ]]; then
    echo -e "${RED}❌ Invalid choice${NC}"
    exit 1
fi

SELECTED_BACKUP="${BACKUPS[$((choice-1))]}"
SELECTED_NAME=$(basename "$SELECTED_BACKUP")

echo ""
echo -e "${CYAN}Selected backup: ${BOLD}$SELECTED_NAME${NC}"

# Check if encrypted
IS_ENCRYPTED=false
if [[ "$SELECTED_BACKUP" == *.enc ]]; then
    IS_ENCRYPTED=true
    echo -e "${YELLOW}🔒 This backup is encrypted${NC}"
    read -sp "Enter decryption passphrase: " PASSPHRASE
    echo ""

    if [[ -z "$PASSPHRASE" ]]; then
        echo -e "${RED}❌ Passphrase cannot be empty${NC}"
        exit 1
    fi
fi

# Final warning
echo ""
echo -e "${RED}${BOLD}⚠️  WARNING ⚠️${NC}"
echo -e "${RED}This will REPLACE ALL CURRENT DATA:${NC}"
echo -e "  • Database: ${BOLD}$DB_NAME${NC}"
echo -e "  • Code: ${BOLD}$PHPBORG_ROOT${NC}"
echo -e "  • Configs: ${BOLD}.env, SSH keys, systemd${NC}"
echo ""
echo -e "${YELLOW}This operation is ${BOLD}IRREVERSIBLE${NC}${YELLOW}!${NC}"
echo ""
read -p "Type 'YES' to confirm restore: " confirm

if [[ "$confirm" != "YES" ]]; then
    echo -e "${YELLOW}Cancelled by user${NC}"
    exit 0
fi

# Create temporary directory
TEMP_DIR="/tmp/phpborg_emergency_restore_$$"
echo ""
echo -e "${BLUE}📦 Creating temporary directory...${NC}"
mkdir -p "$TEMP_DIR"

# Cleanup function
cleanup() {
    echo -e "${BLUE}🧹 Cleaning up temporary files...${NC}"
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

# Extract backup
echo -e "${BLUE}📤 Extracting backup...${NC}"
if [[ "$IS_ENCRYPTED" == true ]]; then
    # Decrypt first
    echo -e "${BLUE}🔓 Decrypting backup...${NC}"
    DECRYPTED_FILE="$TEMP_DIR/decrypted.tar.gz"
    if ! openssl enc -aes-256-cbc -d -salt -pbkdf2 -iter 100000 \
        -in "$SELECTED_BACKUP" \
        -out "$DECRYPTED_FILE" \
        -pass pass:"$PASSPHRASE" 2>/dev/null; then
        echo -e "${RED}❌ Decryption failed. Wrong passphrase?${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Decryption successful${NC}"
    EXTRACT_FILE="$DECRYPTED_FILE"
else
    EXTRACT_FILE="$SELECTED_BACKUP"
fi

# Extract tar.gz
if ! tar -xzf "$EXTRACT_FILE" -C "$TEMP_DIR" 2>/dev/null; then
    echo -e "${RED}❌ Failed to extract backup${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Extraction successful${NC}"

# Verify metadata
METADATA_FILE="$TEMP_DIR/metadata.json"
if [[ ! -f "$METADATA_FILE" ]]; then
    echo -e "${RED}❌ Invalid backup: metadata.json not found${NC}"
    exit 1
fi

# Display backup info
echo ""
echo -e "${CYAN}${BOLD}Backup Information:${NC}"
if command -v jq &> /dev/null; then
    echo -e "${BLUE}Created:${NC} $(jq -r '.created_at' "$METADATA_FILE")"
    echo -e "${BLUE}Type:${NC} $(jq -r '.backup_type' "$METADATA_FILE")"
    echo -e "${BLUE}phpBorg Version:${NC} $(jq -r '.versions.phpborg_version' "$METADATA_FILE")"
else
    cat "$METADATA_FILE"
fi
echo ""

# Stop workers
echo -e "${BLUE}⏸️  Stopping phpBorg workers...${NC}"
systemctl stop phpborg-worker@{1..4} 2>/dev/null || true
systemctl stop phpborg-scheduler 2>/dev/null || true
echo -e "${GREEN}✓ Workers stopped${NC}"

# Restore database
echo -e "${BLUE}🗄️  Restoring database...${NC}"
DB_DUMP="$TEMP_DIR/database.sql"
if [[ ! -f "$DB_DUMP" ]]; then
    echo -e "${RED}❌ Database dump not found in backup${NC}"
    exit 1
fi

if ! mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASSWORD" "$DB_NAME" < "$DB_DUMP" 2>&1; then
    echo -e "${RED}❌ Database restore failed${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Database restored${NC}"

# Restore codebase
echo -e "${BLUE}📂 Restoring codebase...${NC}"
if [[ -d "$TEMP_DIR/phpborg" ]]; then
    rsync -av --delete \
        --exclude=node_modules \
        --exclude=vendor \
        --exclude=.git \
        --exclude=frontend/node_modules \
        --exclude=frontend/dist \
        --exclude=tmp \
        "$TEMP_DIR/phpborg/" "$PHPBORG_ROOT/" 2>&1 | grep -v "^$" || true
    echo -e "${GREEN}✓ Codebase restored${NC}"
else
    echo -e "${YELLOW}⚠️  Codebase not found in backup${NC}"
fi

# Restore SSH keys
echo -e "${BLUE}🔑 Restoring SSH keys...${NC}"
if [[ -d "$TEMP_DIR/ssh_keys" ]]; then
    cp -f "$TEMP_DIR/ssh_keys"/phpborg_backup* /root/.ssh/ 2>/dev/null || true
    chmod 600 /root/.ssh/phpborg_backup* 2>/dev/null || true
    echo -e "${GREEN}✓ SSH keys restored${NC}"
else
    echo -e "${YELLOW}⚠️  SSH keys not found in backup${NC}"
fi

# Restore systemd services
echo -e "${BLUE}⚙️  Restoring systemd services...${NC}"
if [[ -d "$TEMP_DIR/systemd" ]]; then
    cp -f "$TEMP_DIR/systemd"/phpborg-* /etc/systemd/system/ 2>/dev/null || true
    systemctl daemon-reload
    echo -e "${GREEN}✓ Systemd services restored${NC}"
else
    echo -e "${YELLOW}⚠️  Systemd services not found in backup${NC}"
fi

# Restart workers
echo -e "${BLUE}▶️  Restarting phpBorg workers...${NC}"
systemctl start phpborg-scheduler
systemctl start phpborg-worker@{1..4}
echo -e "${GREEN}✓ Workers restarted${NC}"

# Success
echo ""
echo -e "${GREEN}${BOLD}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║     ✅ Restore completed successfully!                ║${NC}"
echo -e "${GREEN}${BOLD}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${CYAN}Next steps:${NC}"
echo -e "  1. Verify the application works: ${BOLD}http://your-server${NC}"
echo -e "  2. Check workers status: ${BOLD}systemctl status phpborg-worker@*${NC}"
echo -e "  3. Review logs: ${BOLD}tail -f /var/log/phpborg_new.log${NC}"
echo ""
echo -e "${YELLOW}Note: You may need to clear your browser cache${NC}"
echo ""
