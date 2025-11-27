package task

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"github.com/phpborg/phpborg-agent/internal/api"
	"github.com/phpborg/phpborg-agent/internal/config"
	"github.com/phpborg/phpborg-agent/internal/executor"
)

// Handler processes tasks received from the phpBorg server
type Handler struct {
	config   *config.Config
	client   *api.Client
	executor *executor.Executor
}

// NewHandler creates a new task handler
func NewHandler(cfg *config.Config, client *api.Client, exec *executor.Executor) *Handler {
	return &Handler{
		config:   cfg,
		client:   client,
		executor: exec,
	}
}

// ProcessTask handles a single task
func (h *Handler) ProcessTask(ctx context.Context, task api.Task) error {
	log.Printf("[TASK] Processing task %d: type=%s priority=%s", task.ID, task.Type, task.Priority)

	// Mark task as started
	if err := h.client.StartTask(ctx, task.ID); err != nil {
		return fmt.Errorf("failed to start task: %w", err)
	}

	// Create task context with timeout
	timeout := time.Duration(task.TimeoutSeconds) * time.Second
	if timeout == 0 {
		timeout = 1 * time.Hour
	}
	taskCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	// Execute task based on type
	var result map[string]interface{}
	var taskErr error
	var exitCode int

	switch task.Type {
	case "backup_create":
		result, exitCode, taskErr = h.handleBackupCreate(taskCtx, task)
	case "backup_restore":
		result, exitCode, taskErr = h.handleBackupRestore(taskCtx, task)
	case "capabilities_detect":
		result, exitCode, taskErr = h.handleCapabilitiesDetect(taskCtx, task)
	case "agent_update":
		result, exitCode, taskErr = h.handleAgentUpdate(taskCtx, task)
	case "test":
		result, exitCode, taskErr = h.handleTest(taskCtx, task)
	default:
		taskErr = fmt.Errorf("unknown task type: %s", task.Type)
		exitCode = 1
	}

	// Report result
	if taskErr != nil {
		log.Printf("[TASK] Task %d failed: %v", task.ID, taskErr)
		if err := h.client.FailTask(ctx, task.ID, taskErr.Error(), exitCode); err != nil {
			log.Printf("[TASK] Failed to report failure: %v", err)
		}
		return taskErr
	}

	log.Printf("[TASK] Task %d completed successfully", task.ID)
	if err := h.client.CompleteTask(ctx, task.ID, result, exitCode); err != nil {
		log.Printf("[TASK] Failed to report completion: %v", err)
		return err
	}

	return nil
}

// handleBackupCreate handles a backup creation task
func (h *Handler) handleBackupCreate(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	// Extract parameters from payload
	repoPath, _ := task.Payload["repo_path"].(string)
	archiveName, _ := task.Payload["archive_name"].(string)

	pathsRaw, _ := task.Payload["paths"].([]interface{})
	paths := make([]string, len(pathsRaw))
	for i, p := range pathsRaw {
		paths[i], _ = p.(string)
	}

	excludesRaw, _ := task.Payload["excludes"].([]interface{})
	excludes := make([]string, len(excludesRaw))
	for i, e := range excludesRaw {
		excludes[i], _ = e.(string)
	}

	compression, _ := task.Payload["compression"].(string)

	if repoPath == "" || archiveName == "" || len(paths) == 0 {
		return nil, 1, fmt.Errorf("missing required parameters: repo_path, archive_name, paths")
	}

	// Update progress
	h.client.UpdateProgress(ctx, task.ID, 10, "Starting backup...")

	// Execute borg create
	result := h.executor.BorgCreate(ctx, repoPath, archiveName, paths, excludes, compression)

	if result.ExitCode != 0 {
		return nil, result.ExitCode, fmt.Errorf("borg create failed: %s", result.Stderr)
	}

	h.client.UpdateProgress(ctx, task.ID, 90, "Backup completed, collecting stats...")

	return map[string]interface{}{
		"stdout":   result.Stdout,
		"stderr":   result.Stderr,
		"duration": result.Duration.String(),
	}, 0, nil
}

// handleBackupRestore handles a backup restoration task
func (h *Handler) handleBackupRestore(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	repoPath, _ := task.Payload["repo_path"].(string)
	archiveName, _ := task.Payload["archive_name"].(string)
	destPath, _ := task.Payload["dest_path"].(string)

	patternsRaw, _ := task.Payload["patterns"].([]interface{})
	patterns := make([]string, len(patternsRaw))
	for i, p := range patternsRaw {
		patterns[i], _ = p.(string)
	}

	if repoPath == "" || archiveName == "" {
		return nil, 1, fmt.Errorf("missing required parameters: repo_path, archive_name")
	}

	if destPath == "" {
		destPath = "/var/restore"
	}

	h.client.UpdateProgress(ctx, task.ID, 10, "Starting restore...")

	result := h.executor.BorgExtract(ctx, repoPath, archiveName, destPath, patterns)

	if result.ExitCode != 0 {
		return nil, result.ExitCode, fmt.Errorf("borg extract failed: %s", result.Stderr)
	}

	h.client.UpdateProgress(ctx, task.ID, 90, "Restore completed")

	return map[string]interface{}{
		"stdout":    result.Stdout,
		"stderr":    result.Stderr,
		"duration":  result.Duration.String(),
		"dest_path": destPath,
	}, 0, nil
}

// handleCapabilitiesDetect detects system capabilities
func (h *Handler) handleCapabilitiesDetect(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	h.client.UpdateProgress(ctx, task.ID, 50, "Detecting capabilities...")

	caps := h.executor.DetectCapabilities(ctx)
	osInfo := h.executor.GetOSInfo(ctx)

	return map[string]interface{}{
		"capabilities": caps,
		"os_info":      osInfo,
	}, 0, nil
}

// handleTest handles a test task (for debugging)
func (h *Handler) handleTest(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	message, _ := task.Payload["message"].(string)
	if message == "" {
		message = "Test task executed successfully"
	}

	// Simulate some work
	for i := 0; i <= 100; i += 20 {
		select {
		case <-ctx.Done():
			return nil, 1, ctx.Err()
		default:
			h.client.UpdateProgress(ctx, task.ID, i, fmt.Sprintf("Test progress: %d%%", i))
			time.Sleep(500 * time.Millisecond)
		}
	}

	return map[string]interface{}{
		"message": message,
		"time":    time.Now().Format(time.RFC3339),
	}, 0, nil
}

// handleAgentUpdate handles an agent self-update task
// This downloads the new binary, verifies it, replaces the current binary,
// and restarts the agent via systemd
func (h *Handler) handleAgentUpdate(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	// Extract parameters
	expectedChecksum, _ := task.Payload["checksum"].(string)
	newVersion, _ := task.Payload["version"].(string)
	forceUpdate, _ := task.Payload["force"].(bool)

	if expectedChecksum == "" && !forceUpdate {
		return nil, 1, fmt.Errorf("checksum required for update (or set force=true)")
	}

	h.client.UpdateProgress(ctx, task.ID, 10, "Preparing update...")

	// Get current binary path
	currentBinary, err := os.Executable()
	if err != nil {
		return nil, 1, fmt.Errorf("failed to get current executable path: %w", err)
	}
	currentBinary, err = filepath.EvalSymlinks(currentBinary)
	if err != nil {
		return nil, 1, fmt.Errorf("failed to resolve executable path: %w", err)
	}

	// Create temporary file for new binary
	tmpDir := filepath.Dir(currentBinary)
	tmpFile := filepath.Join(tmpDir, ".phpborg-agent.new")

	h.client.UpdateProgress(ctx, task.ID, 20, "Downloading new binary...")

	// Download new binary
	if err := h.client.DownloadUpdate(ctx, tmpFile); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to download update: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 60, "Verifying checksum...")

	// Verify checksum
	if expectedChecksum != "" {
		actualChecksum, err := fileChecksum(tmpFile)
		if err != nil {
			os.Remove(tmpFile)
			return nil, 1, fmt.Errorf("failed to calculate checksum: %w", err)
		}

		if actualChecksum != expectedChecksum {
			os.Remove(tmpFile)
			return nil, 1, fmt.Errorf("checksum mismatch: expected %s, got %s", expectedChecksum, actualChecksum)
		}
	}

	h.client.UpdateProgress(ctx, task.ID, 70, "Making binary executable...")

	// Make executable
	if err := os.Chmod(tmpFile, 0755); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to set executable permission: %w", err)
	}

	// Verify the new binary works
	h.client.UpdateProgress(ctx, task.ID, 75, "Verifying new binary...")
	cmd := exec.CommandContext(ctx, tmpFile, "-version")
	if output, err := cmd.CombinedOutput(); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("new binary verification failed: %v (output: %s)", err, string(output))
	}

	h.client.UpdateProgress(ctx, task.ID, 80, "Backing up current binary...")

	// Backup current binary
	backupFile := currentBinary + ".backup"
	if err := copyFile(currentBinary, backupFile); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to backup current binary: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 85, "Replacing binary...")

	// Replace binary (atomic rename)
	if err := os.Rename(tmpFile, currentBinary); err != nil {
		// Try to restore backup
		os.Rename(backupFile, currentBinary)
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to replace binary: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 90, "Restarting agent via systemd...")

	// Restart via systemd (this will kill us, but the task is essentially done)
	// We use a background process so we can return the result first
	go func() {
		time.Sleep(2 * time.Second) // Give time to send result
		restartCmd := exec.Command("systemctl", "restart", "phpborg-agent")
		if err := restartCmd.Run(); err != nil {
			log.Printf("[UPDATE] Failed to restart via systemd: %v", err)
			// Try SIGHUP as fallback
			if pid := os.Getpid(); pid > 0 {
				proc, _ := os.FindProcess(pid)
				proc.Signal(os.Interrupt)
			}
		}
	}()

	// Clean up backup after successful update
	os.Remove(backupFile)

	return map[string]interface{}{
		"previous_version": h.config.Agent.Version,
		"new_version":      newVersion,
		"binary_path":      currentBinary,
		"status":           "updated",
		"message":          "Agent updated successfully, restarting...",
	}, 0, nil
}

// fileChecksum calculates SHA256 checksum of a file
func fileChecksum(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}

// copyFile copies a file from src to dst
func copyFile(src, dst string) error {
	sourceFile, err := os.Open(src)
	if err != nil {
		return err
	}
	defer sourceFile.Close()

	destFile, err := os.Create(dst)
	if err != nil {
		return err
	}
	defer destFile.Close()

	_, err = io.Copy(destFile, sourceFile)
	if err != nil {
		return err
	}

	// Preserve permissions
	sourceInfo, err := os.Stat(src)
	if err != nil {
		return err
	}
	return os.Chmod(dst, sourceInfo.Mode())
}
