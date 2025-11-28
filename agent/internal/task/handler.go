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
	"strconv"
	"strings"
	"time"

	"github.com/phpborg/phpborg-agent/internal/api"
	"github.com/phpborg/phpborg-agent/internal/config"
	"github.com/phpborg/phpborg-agent/internal/executor"
)

// Note: executor package is imported above for BorgProgress type

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
	case "stats_collect":
		result, exitCode, taskErr = h.handleStatsCollect(taskCtx, task)
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
	passphrase, _ := task.Payload["passphrase"].(string)

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
	h.client.UpdateProgress(ctx, task.ID, 5, "Starting backup...")

	// Track last update time for throttling
	var lastUpdate time.Time

	// Create progress callback for real-time updates
	progressCallback := func(progress executor.BorgProgress) {
		// Throttle updates to max 1 per second
		if time.Since(lastUpdate) < time.Second {
			return
		}
		lastUpdate = time.Now()

		// Calculate approximate progress (borg doesn't give total, so we estimate)
		// Progress starts at 10% and goes up to 90% during backup
		progressPercent := 10

		// Send detailed progress info
		info := api.ProgressInfo{
			FilesCount:       progress.NFiles,
			OriginalSize:     progress.OriginalSize,
			CompressedSize:   progress.CompressedSize,
			DeduplicatedSize: progress.DeduplicatedSize,
			CurrentPath:      progress.Path,
		}

		// Format a human-readable message
		info.Message = fmt.Sprintf("Backing up: %d files, %s processed",
			progress.NFiles,
			formatBytes(progress.OriginalSize),
		)

		if err := h.client.UpdateProgressWithInfo(ctx, task.ID, progressPercent, info); err != nil {
			log.Printf("[BACKUP] Failed to send progress update: %v", err)
		}
	}

	// Execute borg create with progress streaming
	result := h.executor.BorgCreateWithProgress(ctx, repoPath, archiveName, paths, excludes, compression, passphrase, progressCallback)

	// Borg exit codes:
	// 0 = success
	// 1 = warnings (e.g., permission denied on some files) - backup still succeeded
	// 2 = fatal error
	if result.ExitCode > 1 {
		return nil, result.ExitCode, fmt.Errorf("borg create failed: %s", result.Stderr)
	}

	// Check if there were warnings (exit code 1)
	hasWarnings := result.ExitCode == 1

	if hasWarnings {
		h.client.UpdateProgress(ctx, task.ID, 95, "Backup completed with warnings (some files skipped)")
	} else {
		h.client.UpdateProgress(ctx, task.ID, 95, "Backup completed successfully")
	}

	return map[string]interface{}{
		"stdout":       result.Stdout,
		"stderr":       result.Stderr,
		"duration":     result.Duration.String(),
		"has_warnings": hasWarnings,
		"exit_code":    result.ExitCode,
	}, 0, nil
}

// formatBytes formats bytes into human-readable string
func formatBytes(bytes int64) string {
	if bytes == 0 {
		return "0 B"
	}

	units := []string{"B", "KB", "MB", "GB", "TB"}
	i := 0
	size := float64(bytes)

	for size >= 1024 && i < len(units)-1 {
		size /= 1024
		i++
	}

	return fmt.Sprintf("%.1f %s", size, units[i])
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

// handleStatsCollect handles a stats collection task
func (h *Handler) handleStatsCollect(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	log.Printf("[STATS] Collecting system stats...")
	h.client.UpdateProgress(ctx, task.ID, 10, "Collecting system information...")

	stats := make(map[string]interface{})

	// OS Information
	h.client.UpdateProgress(ctx, task.ID, 20, "Collecting OS info...")
	osInfo := h.executor.GetOSInfo(ctx)
	stats["os_info"] = osInfo

	// Parse OS info to extract distribution and version
	osResult := h.executor.Run(ctx, "cat", []string{"/etc/os-release"}, 10*time.Second)
	if osResult.ExitCode == 0 {
		for _, line := range strings.Split(osResult.Stdout, "\n") {
			if strings.HasPrefix(line, "NAME=") {
				stats["os_distribution"] = strings.Trim(strings.TrimPrefix(line, "NAME="), "\"")
			}
			if strings.HasPrefix(line, "VERSION=") {
				stats["os_version"] = strings.Trim(strings.TrimPrefix(line, "VERSION="), "\"")
			}
		}
	}

	// Kernel version
	kernelResult := h.executor.Run(ctx, "uname", []string{"-r"}, 10*time.Second)
	if kernelResult.ExitCode == 0 {
		stats["kernel_version"] = strings.TrimSpace(kernelResult.Stdout)
	}

	// Hostname
	hostnameResult := h.executor.Run(ctx, "hostname", nil, 10*time.Second)
	if hostnameResult.ExitCode == 0 {
		stats["hostname"] = strings.TrimSpace(hostnameResult.Stdout)
	}

	// Architecture
	archResult := h.executor.Run(ctx, "uname", []string{"-m"}, 10*time.Second)
	if archResult.ExitCode == 0 {
		stats["architecture"] = strings.TrimSpace(archResult.Stdout)
	}

	// CPU Information
	h.client.UpdateProgress(ctx, task.ID, 40, "Collecting CPU info...")

	// CPU cores
	nprocResult := h.executor.Run(ctx, "nproc", nil, 10*time.Second)
	if nprocResult.ExitCode == 0 {
		if cores, err := strconv.Atoi(strings.TrimSpace(nprocResult.Stdout)); err == nil {
			stats["cpu_cores"] = cores
		}
	}

	// CPU model
	cpuModelResult := h.executor.Run(ctx, "sh", []string{"-c", `grep "model name" /proc/cpuinfo | head -1 | cut -d":" -f2`}, 10*time.Second)
	if cpuModelResult.ExitCode == 0 {
		stats["cpu_model"] = strings.TrimSpace(cpuModelResult.Stdout)
	}

	// Load averages
	loadResult := h.executor.Run(ctx, "cat", []string{"/proc/loadavg"}, 10*time.Second)
	if loadResult.ExitCode == 0 {
		loads := strings.Fields(loadResult.Stdout)
		if len(loads) >= 3 {
			if load1, err := strconv.ParseFloat(loads[0], 64); err == nil {
				stats["cpu_load_1"] = load1
			}
			if load5, err := strconv.ParseFloat(loads[1], 64); err == nil {
				stats["cpu_load_5"] = load5
			}
			if load15, err := strconv.ParseFloat(loads[2], 64); err == nil {
				stats["cpu_load_15"] = load15
			}
		}
	}

	// Memory Information
	h.client.UpdateProgress(ctx, task.ID, 60, "Collecting memory info...")
	memResult := h.executor.Run(ctx, "free", []string{"-m"}, 10*time.Second)
	if memResult.ExitCode == 0 {
		for _, line := range strings.Split(memResult.Stdout, "\n") {
			fields := strings.Fields(line)
			if len(fields) >= 4 && fields[0] == "Mem:" {
				if total, err := strconv.Atoi(fields[1]); err == nil {
					stats["memory_total_mb"] = total
				}
				if used, err := strconv.Atoi(fields[2]); err == nil {
					stats["memory_used_mb"] = used
				}
				if free, err := strconv.Atoi(fields[3]); err == nil {
					stats["memory_free_mb"] = free
				}
				if len(fields) >= 7 {
					if available, err := strconv.Atoi(fields[6]); err == nil {
						stats["memory_available_mb"] = available
					}
				}
				if total, ok := stats["memory_total_mb"].(int); ok && total > 0 {
					if used, ok := stats["memory_used_mb"].(int); ok {
						stats["memory_percent"] = float64(used) / float64(total) * 100
					}
				}
			}
			if len(fields) >= 3 && fields[0] == "Swap:" {
				if total, err := strconv.Atoi(fields[1]); err == nil {
					stats["swap_total_mb"] = total
				}
				if used, err := strconv.Atoi(fields[2]); err == nil {
					stats["swap_used_mb"] = used
				}
			}
		}
	}

	// Disk Information
	h.client.UpdateProgress(ctx, task.ID, 80, "Collecting disk info...")
	dfResult := h.executor.Run(ctx, "df", []string{"-BG", "/"}, 10*time.Second)
	if dfResult.ExitCode == 0 {
		lines := strings.Split(dfResult.Stdout, "\n")
		if len(lines) >= 2 {
			fields := strings.Fields(lines[1])
			if len(fields) >= 5 {
				// Parse sizes (remove trailing G)
				if total, err := strconv.Atoi(strings.TrimSuffix(fields[1], "G")); err == nil {
					stats["disk_total_gb"] = total
				}
				if used, err := strconv.Atoi(strings.TrimSuffix(fields[2], "G")); err == nil {
					stats["disk_used_gb"] = used
				}
				if free, err := strconv.Atoi(strings.TrimSuffix(fields[3], "G")); err == nil {
					stats["disk_free_gb"] = free
				}
				// Parse percentage (remove trailing %)
				if percent, err := strconv.Atoi(strings.TrimSuffix(fields[4], "%")); err == nil {
					stats["disk_percent"] = percent
				}
			}
		}
	}

	// Uptime
	uptimeResult := h.executor.Run(ctx, "cat", []string{"/proc/uptime"}, 10*time.Second)
	if uptimeResult.ExitCode == 0 {
		uptimeParts := strings.Fields(uptimeResult.Stdout)
		if len(uptimeParts) >= 1 {
			if uptimeFloat, err := strconv.ParseFloat(uptimeParts[0], 64); err == nil {
				uptimeSeconds := int(uptimeFloat)
				stats["uptime_seconds"] = uptimeSeconds
				stats["uptime_human"] = formatUptime(uptimeSeconds)
			}
		}
	}

	// IP Address
	ipResult := h.executor.Run(ctx, "hostname", []string{"-I"}, 10*time.Second)
	if ipResult.ExitCode == 0 {
		ips := strings.Fields(ipResult.Stdout)
		if len(ips) > 0 {
			stats["ip_address"] = ips[0]
		}
	}

	h.client.UpdateProgress(ctx, task.ID, 100, "Stats collection completed")
	log.Printf("[STATS] Stats collection completed: %d metrics", len(stats))

	return stats, 0, nil
}

// formatUptime converts seconds to human-readable string
func formatUptime(seconds int) string {
	if seconds < 60 {
		return fmt.Sprintf("%d seconds", seconds)
	}

	minutes := seconds / 60
	hours := minutes / 60
	days := hours / 24

	if days > 0 {
		hours = hours % 24
		return fmt.Sprintf("%d days, %d hours", days, hours)
	}

	if hours > 0 {
		minutes = minutes % 60
		return fmt.Sprintf("%d hours, %d minutes", hours, minutes)
	}

	return fmt.Sprintf("%d minutes", minutes)
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

	// Create temporary file for new binary in /tmp (works with systemd PrivateTmp)
	tmpFile := "/tmp/.phpborg-agent.new"

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

	// Backup current binary to /tmp (systemd protects /usr/local/bin)
	backupFile := "/tmp/.phpborg-agent.backup"
	if err := copyFile(currentBinary, backupFile); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to backup current binary: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 85, "Replacing binary...")

	// Rename running binary first (Linux allows this even while executing)
	oldBinary := currentBinary + ".old"
	os.Remove(oldBinary) // Remove any previous .old file
	if err := os.Rename(currentBinary, oldBinary); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to rename current binary: %w", err)
	}

	// Copy new binary to destination
	if err := copyFile(tmpFile, currentBinary); err != nil {
		// Try to restore old binary
		os.Rename(oldBinary, currentBinary)
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to install new binary: %w", err)
	}

	// Set ownership to match old binary (agent user)
	if err := os.Chmod(currentBinary, 0755); err != nil {
		log.Printf("[UPDATE] Warning: failed to set permissions: %v", err)
	}

	os.Remove(tmpFile)    // Clean up temp file
	os.Remove(oldBinary)  // Clean up old binary

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
