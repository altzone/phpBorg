#!/bin/bash
# prepare-backup-directory.sh
# Called via sudo by agent-worker to prepare backup directories
# Validates that the path is under a valid storage pool

set -e

REPO_PATH="$1"

if [ -z "$REPO_PATH" ]; then
    echo "Usage: $0 <repo_path>" >&2
    exit 1
fi

# Validate path format (must start with /)
if [[ ! "$REPO_PATH" =~ ^/ ]]; then
    echo "Error: Path must be absolute" >&2
    exit 1
fi

# Security: prevent path traversal
if [[ "$REPO_PATH" =~ \.\. ]]; then
    echo "Error: Path traversal not allowed" >&2
    exit 1
fi

# Get valid storage pool paths from database
PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"
source "${PHPBORG_ROOT}/.env" 2>/dev/null || true

# Query storage pools from database
VALID_PATHS=$(mysql -u"${DB_USER:-phpborg}" -p"${DB_PASS}" -h"${DB_HOST:-localhost}" "${DB_NAME:-phpborg}" -N -e "SELECT path FROM storage_pools WHERE status = 'active'" 2>/dev/null || echo "/opt/backups")

# Check if repo path starts with a valid storage pool path
VALID=false
for POOL_PATH in $VALID_PATHS; do
    if [[ "$REPO_PATH" == "$POOL_PATH"* ]]; then
        VALID=true
        break
    fi
done

if [ "$VALID" != "true" ]; then
    echo "Error: Path is not under a valid storage pool" >&2
    exit 1
fi

# Create directory structure with correct ownership
REPO_DIR=$(dirname "$REPO_PATH")

mkdir -p "$REPO_DIR"
chown phpborg-borg:phpborg "$REPO_DIR"
chmod 770 "$REPO_DIR"

mkdir -p "$REPO_PATH"
chown -R phpborg-borg:phpborg "$REPO_PATH"
chmod 770 "$REPO_PATH"

echo "Directory prepared: $REPO_PATH"
