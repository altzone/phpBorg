# phpBorg Agent Makefile
# Builds the Linux agent (amd64/arm64).
# NOTE: Windows agent support was removed - there is no clean native Borg build for
# Windows. Windows clients will be revisited separately.

VERSION := $(shell grep 'const Version' cmd/phpborg-agent/main.go | cut -d'"' -f2)
BUILD_DATE := $(shell date -u +"%Y-%m-%dT%H:%M:%SZ")
GIT_COMMIT := $(shell git rev-parse --short HEAD 2>/dev/null || echo "unknown")

LDFLAGS := -ldflags "-s -w -X main.BuildDate=$(BUILD_DATE) -X main.GitCommit=$(GIT_COMMIT)"

# Output directory
BUILD_DIR := build

# Binary names
BINARY_NAME := phpborg-agent
BINARY_LINUX := $(BINARY_NAME)-linux-amd64
BINARY_LINUX_ARM64 := $(BINARY_NAME)-linux-arm64

.PHONY: all clean linux linux-arm64 deps test help

# Default target: build the Linux agents
all: clean deps linux linux-arm64
	@echo "Build complete. Binaries in $(BUILD_DIR)/"
	@ls -la $(BUILD_DIR)/

# Show help
help:
	@echo "phpBorg Agent Build System"
	@echo "=========================="
	@echo ""
	@echo "Targets:"
	@echo "  all           - Build the Linux agents (default)"
	@echo "  linux         - Build for Linux amd64"
	@echo "  linux-arm64   - Build for Linux arm64"
	@echo "  deps          - Download dependencies"
	@echo "  test          - Run tests"
	@echo "  clean         - Remove build artifacts"
	@echo "  release       - Create release archives"
	@echo ""
	@echo "Version: $(VERSION)"

# Clean build directory
clean:
	@echo "Cleaning build directory..."
	@rm -rf $(BUILD_DIR)
	@mkdir -p $(BUILD_DIR)

# Download dependencies
deps:
	@echo "Downloading dependencies..."
	@go mod download
	@go mod tidy

# Build for Linux amd64
linux:
	@echo "Building for Linux amd64..."
	@GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -buildvcs=false $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY_LINUX) ./cmd/phpborg-agent
	@echo "Built: $(BUILD_DIR)/$(BINARY_LINUX)"

# Build for Linux arm64
linux-arm64:
	@echo "Building for Linux arm64..."
	@GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -buildvcs=false $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY_LINUX_ARM64) ./cmd/phpborg-agent
	@echo "Built: $(BUILD_DIR)/$(BINARY_LINUX_ARM64)"

# Run tests
test:
	@echo "Running tests..."
	@go test -v ./...

# Build and copy to system location (Linux only)
install: linux
	@echo "Installing agent..."
	@sudo cp $(BUILD_DIR)/$(BINARY_LINUX) /usr/local/bin/$(BINARY_NAME)
	@sudo chmod +x /usr/local/bin/$(BINARY_NAME)
	@echo "Installed to /usr/local/bin/$(BINARY_NAME)"

# Create release archives
release: all
	@echo "Creating release archives..."
	@cd $(BUILD_DIR) && tar -czf $(BINARY_NAME)-$(VERSION)-linux-amd64.tar.gz $(BINARY_LINUX)
	@cd $(BUILD_DIR) && tar -czf $(BINARY_NAME)-$(VERSION)-linux-arm64.tar.gz $(BINARY_LINUX_ARM64)
	@echo "Release archives created in $(BUILD_DIR)/"
	@ls -la $(BUILD_DIR)/*.tar.gz

# Display version info
version:
	@echo "Version: $(VERSION)"
	@echo "Build Date: $(BUILD_DATE)"
	@echo "Git Commit: $(GIT_COMMIT)"
