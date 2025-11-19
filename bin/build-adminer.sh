#!/bin/bash

##
# Build phpBorg Adminer Docker Image
##

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DOCKER_DIR="$PROJECT_ROOT/docker/adminer"

echo "🐳 Building phpBorg Adminer image..."
echo "   Dockerfile: $DOCKER_DIR/Dockerfile"
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Error: Docker is not running"
    echo "   Please start Docker and try again"
    exit 1
fi

# Build image
cd "$DOCKER_DIR"
docker build -t phpborg/adminer:latest .

# Verify build
if docker images | grep -q "phpborg/adminer"; then
    echo ""
    echo "✅ Image built successfully!"
    echo ""
    docker images | grep "phpborg/adminer"
    echo ""
    echo "🚀 Adminer is ready for Instant Recovery sessions"
else
    echo ""
    echo "❌ Failed to build image"
    exit 1
fi
