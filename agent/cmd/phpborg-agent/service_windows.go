//go:build windows
// +build windows

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
const serviceDisplayName = "phpBorg Backup Agent"
const serviceDescription = "phpBorg agent for Windows backup management"

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

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	// Create service using sc.exe
	cmd := exec.CommandContext(ctx, "sc.exe", "create", serviceName,
		fmt.Sprintf("binPath=%s", execPath),
		fmt.Sprintf("DisplayName=%s", serviceDisplayName),
		"start=auto",
	)
	if output, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("failed to create service: %s - %w", string(output), err)
	}

	// Set service description
	cmd = exec.CommandContext(ctx, "sc.exe", "description", serviceName, serviceDescription)
	cmd.Run() // Ignore errors for description

	// Configure recovery options (restart on failure)
	cmd = exec.CommandContext(ctx, "sc.exe", "failure", serviceName,
		"reset=86400",
		"actions=restart/60000/restart/60000/restart/60000",
	)
	cmd.Run() // Ignore errors for failure config

	fmt.Printf("Service '%s' installed successfully\n", serviceName)
	fmt.Println("Start the service with: sc.exe start phpborg-agent")
	fmt.Println("Or via Services management console (services.msc)")

	return nil
}

func uninstallServiceCmd() error {
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	// Stop the service first (ignore errors)
	cmd := exec.CommandContext(ctx, "sc.exe", "stop", serviceName)
	cmd.Run()

	// Wait a bit for the service to stop
	time.Sleep(3 * time.Second)

	// Delete the service
	cmd = exec.CommandContext(ctx, "sc.exe", "delete", serviceName)
	if output, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("failed to delete service: %s - %w", string(output), err)
	}

	fmt.Printf("Service '%s' uninstalled successfully\n", serviceName)
	return nil
}
