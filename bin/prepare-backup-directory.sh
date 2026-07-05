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

# NOTE (Bug 18): storage-pool membership is validated in PHP by
# RefreshAgentAuthorizedKeysHandler (where the DB connection already works),
# BEFORE this script is invoked. This script no longer queries the database:
# the previous query used the wrong column ('status' instead of 'active') and
# the wrong credentials (localhost socket instead of DB_HOST=127.0.0.1), which
# made every repository outside /opt/backups fail. We keep only the absolute-path
# and path-traversal checks above as defence-in-depth.

# Create directory structure with correct ownership (owner AND group phpborg-borg,
# consistent with how borg accesses local repositories).
REPO_DIR=$(dirname "$REPO_PATH")

mkdir -p "$REPO_DIR"
chown phpborg-borg:phpborg-borg "$REPO_DIR"
chmod 770 "$REPO_DIR"

mkdir -p "$REPO_PATH"
chown -R phpborg-borg:phpborg-borg "$REPO_PATH"
chmod 770 "$REPO_PATH"

echo "Directory prepared: $REPO_PATH"
