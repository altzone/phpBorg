//go:build linux
// +build linux

package main

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

const serviceName = "phpborg-agent"

func installAsService() error {
	// Get current executable path
	execPath, err := os.Executable()
	if err != nil {
		return fmt.Errorf("failed to get executable path: %w", err)
	}
	execPath, err = filepath.EvalSymlinks(execPath)
	if err != nil {
		return fmt.Errorf("failed to resolve symlinks: %w", err)
	}

	// Create systemd service file content
	serviceContent := fmt.Sprintf(`[Unit]
Description=phpBorg Backup Agent
After=network.target

[Service]
Type=simple
ExecStart=%s
Restart=always
RestartSec=10
User=root
WorkingDirectory=/

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phpborg-agent

# Security
NoNewPrivileges=false
ProtectSystem=false
ProtectHome=false

[Install]
WantedBy=multi-user.target
`, execPath)

	// Write service file
	servicePath := fmt.Sprintf("/etc/systemd/system/%s.service", serviceName)
	if err := os.WriteFile(servicePath, []byte(serviceContent), 0644); err != nil {
		return fmt.Errorf("failed to write service file: %w", err)
	}

	// Reload systemd
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	if err := exec.CommandContext(ctx, "systemctl", "daemon-reload").Run(); err != nil {
		return fmt.Errorf("failed to reload systemd: %w", err)
	}

	// Enable service
	if err := exec.CommandContext(ctx, "systemctl", "enable", serviceName).Run(); err != nil {
		return fmt.Errorf("failed to enable service: %w", err)
	}

	fmt.Printf("Service file created: %s\n", servicePath)
	fmt.Printf("Service enabled. Start with: systemctl start %s\n", serviceName)

	return nil
}

func uninstallServiceCmd() error {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	// Stop service (ignore errors)
	exec.CommandContext(ctx, "systemctl", "stop", serviceName).Run()

	// Disable service
	exec.CommandContext(ctx, "systemctl", "disable", serviceName).Run()

	// Remove service file
	servicePath := fmt.Sprintf("/etc/systemd/system/%s.service", serviceName)
	if err := os.Remove(servicePath); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("failed to remove service file: %w", err)
	}

	// Reload systemd
	exec.CommandContext(ctx, "systemctl", "daemon-reload").Run()

	fmt.Printf("Service %s uninstalled\n", serviceName)
	return nil
}
