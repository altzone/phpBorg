package executor

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"time"

	"github.com/phpborg/phpborg-agent/internal/config"
)

// Executor handles command execution for backup tasks
type Executor struct {
	config *config.Config
}

// NewExecutor creates a new command executor
func NewExecutor(cfg *config.Config) *Executor {
	return &Executor{config: cfg}
}

// CommandResult holds the result of a command execution
type CommandResult struct {
	Stdout   string
	Stderr   string
	ExitCode int
	Duration time.Duration
	Error    error
}

// Run executes a command with timeout and returns the result
func (e *Executor) Run(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
	start := time.Now()

	// Create context with timeout
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, command, args...)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	// Set process group for proper cleanup
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	err := cmd.Run()
	duration := time.Since(start)

	result := &CommandResult{
		Stdout:   stdout.String(),
		Stderr:   stderr.String(),
		Duration: duration,
	}

	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			result.ExitCode = exitErr.ExitCode()
		} else if ctx.Err() == context.DeadlineExceeded {
			result.ExitCode = -1
			result.Error = fmt.Errorf("command timed out after %v", timeout)
		} else {
			result.ExitCode = -1
			result.Error = err
		}
	}

	return result
}

// RunWithSudo executes a command with sudo
func (e *Executor) RunWithSudo(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
	sudoArgs := append([]string{command}, args...)
	return e.Run(ctx, "sudo", sudoArgs, timeout)
}

// BorgCreate executes a borg create command
func (e *Executor) BorgCreate(ctx context.Context, repoPath string, archiveName string, paths []string, excludes []string, compression string) *CommandResult {
	args := []string{
		"create",
		"--verbose",
		"--stats",
		"--progress",
	}

	// Add compression
	if compression != "" {
		args = append(args, "--compression", compression)
	}

	// Add excludes
	for _, exclude := range excludes {
		args = append(args, "--exclude", exclude)
	}

	// Add repository and archive name
	args = append(args, fmt.Sprintf("%s::%s", repoPath, archiveName))

	// Add paths to backup
	args = append(args, paths...)

	// Set environment for borg SSH connection
	env := e.getBorgEnv()

	return e.runWithEnv(ctx, "borg", args, env, 4*time.Hour)
}

// BorgList lists archives in a repository
func (e *Executor) BorgList(ctx context.Context, repoPath string) *CommandResult {
	args := []string{"list", "--json", repoPath}
	env := e.getBorgEnv()
	return e.runWithEnv(ctx, "borg", args, env, 5*time.Minute)
}

// BorgInfo gets information about a repository or archive
func (e *Executor) BorgInfo(ctx context.Context, repoPath string, archiveName string) *CommandResult {
	target := repoPath
	if archiveName != "" {
		target = fmt.Sprintf("%s::%s", repoPath, archiveName)
	}

	args := []string{"info", "--json", target}
	env := e.getBorgEnv()
	return e.runWithEnv(ctx, "borg", args, env, 5*time.Minute)
}

// BorgExtract extracts files from an archive
func (e *Executor) BorgExtract(ctx context.Context, repoPath string, archiveName string, destPath string, patterns []string) *CommandResult {
	args := []string{
		"extract",
		"--verbose",
		"--progress",
	}

	// Add repository and archive
	args = append(args, fmt.Sprintf("%s::%s", repoPath, archiveName))

	// Add patterns if specified
	args = append(args, patterns...)

	// Change to destination directory
	env := e.getBorgEnv()

	// Create destination if it doesn't exist
	os.MkdirAll(destPath, 0755)

	return e.runWithEnvAndDir(ctx, "borg", args, env, destPath, 4*time.Hour)
}

// getBorgEnv returns environment variables for borg commands
func (e *Executor) getBorgEnv() []string {
	env := os.Environ()

	// SSH command with custom port and key
	sshCmd := fmt.Sprintf("ssh -p %d -i %s -o StrictHostKeyChecking=no",
		e.config.BorgSSH.Port,
		e.config.BorgSSH.PrivateKeyPath,
	)
	env = append(env, "BORG_RSH="+sshCmd)

	// Remote path format for phpBorg server
	remotePath := fmt.Sprintf("%s@%s:%s",
		e.config.BorgSSH.User,
		e.config.BorgSSH.Host,
		e.config.BorgSSH.BackupPath,
	)
	env = append(env, "BORG_REPO="+remotePath)

	return env
}

// runWithEnv executes a command with custom environment
func (e *Executor) runWithEnv(ctx context.Context, command string, args []string, env []string, timeout time.Duration) *CommandResult {
	return e.runWithEnvAndDir(ctx, command, args, env, "", timeout)
}

// runWithEnvAndDir executes a command with custom environment and working directory
func (e *Executor) runWithEnvAndDir(ctx context.Context, command string, args []string, env []string, dir string, timeout time.Duration) *CommandResult {
	start := time.Now()

	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, command, args...)
	cmd.Env = env
	if dir != "" {
		cmd.Dir = dir
	}

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	err := cmd.Run()
	duration := time.Since(start)

	result := &CommandResult{
		Stdout:   stdout.String(),
		Stderr:   stderr.String(),
		Duration: duration,
	}

	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			result.ExitCode = exitErr.ExitCode()
		} else if ctx.Err() == context.DeadlineExceeded {
			result.ExitCode = -1
			result.Error = fmt.Errorf("command timed out after %v", timeout)
		} else {
			result.ExitCode = -1
			result.Error = err
		}
	}

	return result
}

// DetectCapabilities detects system capabilities for backup operations
// Returns a structure compatible with phpBorg server expectations
func (e *Executor) DetectCapabilities(ctx context.Context) map[string]interface{} {
	caps := make(map[string]interface{})

	// Detect snapshot capabilities
	caps["snapshots"] = e.detectSnapshots(ctx)

	// Detect databases
	caps["databases"] = e.detectDatabases(ctx)

	// Detect Docker
	caps["docker"] = e.detectDocker(ctx)

	// Get filesystem info
	caps["filesystem"] = e.detectFilesystem(ctx)

	return caps
}

// detectSnapshots detects available snapshot methods (LVM, ZFS, Btrfs)
func (e *Executor) detectSnapshots(ctx context.Context) []map[string]interface{} {
	snapshots := []map[string]interface{}{}

	// Check for LVM
	if result := e.Run(ctx, "which", []string{"lvcreate"}, 10*time.Second); result.ExitCode == 0 {
		if lvResult := e.RunWithSudo(ctx, "lvs", []string{"--noheadings", "-o", "lv_name,vg_name,lv_path"}, 10*time.Second); lvResult.ExitCode == 0 {
			lvs := strings.TrimSpace(lvResult.Stdout)
			if lvs != "" {
				lines := strings.Split(lvs, "\n")
				volumeCount := 0
				for _, line := range lines {
					if strings.TrimSpace(line) != "" {
						volumeCount++
					}
				}
				snapshots = append(snapshots, map[string]interface{}{
					"type":        "lvm",
					"name":        "LVM Snapshot",
					"available":   true,
					"description": "Logical Volume Manager snapshots for consistent backups",
					"details":     fmt.Sprintf("%d volume(s) available", volumeCount),
				})
			}
		}
	}

	// Check for ZFS
	if result := e.Run(ctx, "which", []string{"zfs"}, 10*time.Second); result.ExitCode == 0 {
		if zfsResult := e.Run(ctx, "zfs", []string{"list", "-H", "-o", "name"}, 10*time.Second); zfsResult.ExitCode == 0 {
			datasets := strings.TrimSpace(zfsResult.Stdout)
			if datasets != "" {
				lines := strings.Split(datasets, "\n")
				datasetCount := 0
				for _, line := range lines {
					if strings.TrimSpace(line) != "" {
						datasetCount++
					}
				}
				snapshots = append(snapshots, map[string]interface{}{
					"type":        "zfs",
					"name":        "ZFS Snapshot",
					"available":   true,
					"description": "ZFS dataset snapshots with instant creation",
					"details":     fmt.Sprintf("%d dataset(s) available", datasetCount),
				})
			}
		}
	}

	// Check for Btrfs
	if result := e.Run(ctx, "which", []string{"btrfs"}, 10*time.Second); result.ExitCode == 0 {
		if btrfsResult := e.RunWithSudo(ctx, "btrfs", []string{"subvolume", "list", "/"}, 10*time.Second); btrfsResult.ExitCode == 0 {
			snapshots = append(snapshots, map[string]interface{}{
				"type":        "btrfs",
				"name":        "Btrfs Snapshot",
				"available":   true,
				"description": "Btrfs subvolume snapshots with CoW efficiency",
				"details":     "Btrfs filesystem detected",
			})
		}
	}

	return snapshots
}

// detectDatabases detects installed databases
func (e *Executor) detectDatabases(ctx context.Context) []map[string]interface{} {
	databases := []map[string]interface{}{}

	// Check for MySQL/MariaDB
	if result := e.Run(ctx, "which", []string{"mysql"}, 10*time.Second); result.ExitCode == 0 {
		db := map[string]interface{}{
			"type":     "mysql",
			"name":     "MySQL/MariaDB",
			"detected": true,
			"running":  false,
		}

		// Get version
		if verResult := e.Run(ctx, "mysql", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
			db["version"] = strings.TrimSpace(verResult.Stdout)
		}

		// Check if running
		if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mysql"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		} else if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mariadb"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		}

		databases = append(databases, db)
	}

	// Check for PostgreSQL
	if result := e.Run(ctx, "which", []string{"psql"}, 10*time.Second); result.ExitCode == 0 {
		db := map[string]interface{}{
			"type":     "postgresql",
			"name":     "PostgreSQL",
			"detected": true,
			"running":  false,
		}

		if verResult := e.Run(ctx, "psql", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
			db["version"] = strings.TrimSpace(verResult.Stdout)
		}

		if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "postgresql"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		}

		databases = append(databases, db)
	}

	// Check for MongoDB
	if result := e.Run(ctx, "which", []string{"mongod"}, 10*time.Second); result.ExitCode == 0 {
		db := map[string]interface{}{
			"type":     "mongodb",
			"name":     "MongoDB",
			"detected": true,
			"running":  false,
		}

		if verResult := e.Run(ctx, "mongod", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
			lines := strings.Split(verResult.Stdout, "\n")
			if len(lines) > 0 {
				db["version"] = strings.TrimSpace(lines[0])
			}
		}

		if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mongod"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		}

		databases = append(databases, db)
	}

	// Check for Redis
	if result := e.Run(ctx, "which", []string{"redis-server"}, 10*time.Second); result.ExitCode == 0 {
		db := map[string]interface{}{
			"type":     "redis",
			"name":     "Redis",
			"detected": true,
			"running":  false,
		}

		if verResult := e.Run(ctx, "redis-server", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
			db["version"] = strings.TrimSpace(verResult.Stdout)
		}

		if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "redis"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		} else if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "redis-server"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
			db["running"] = true
		}

		databases = append(databases, db)
	}

	return databases
}

// detectDocker detects Docker environment
func (e *Executor) detectDocker(ctx context.Context) map[string]interface{} {
	docker := map[string]interface{}{
		"installed":       false,
		"running":         false,
		"container_count": 0,
	}

	if result := e.Run(ctx, "which", []string{"docker"}, 10*time.Second); result.ExitCode != 0 {
		return docker
	}

	docker["installed"] = true

	// Get version
	if verResult := e.Run(ctx, "docker", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
		docker["version"] = strings.TrimSpace(verResult.Stdout)
	}

	// Check if running
	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "docker"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		docker["running"] = true

		// Count containers
		if psResult := e.Run(ctx, "docker", []string{"ps", "-a", "-q"}, 10*time.Second); psResult.ExitCode == 0 {
			output := strings.TrimSpace(psResult.Stdout)
			if output != "" {
				lines := strings.Split(output, "\n")
				docker["container_count"] = len(lines)
			}
		}
	}

	return docker
}

// detectFilesystem detects filesystem information
func (e *Executor) detectFilesystem(ctx context.Context) map[string]interface{} {
	fs := map[string]interface{}{
		"mounts": []map[string]interface{}{},
	}

	if result := e.Run(ctx, "df", []string{"-h"}, 10*time.Second); result.ExitCode == 0 {
		mounts := []map[string]interface{}{}
		lines := strings.Split(result.Stdout, "\n")

		for i, line := range lines {
			if i == 0 {
				continue // Skip header
			}
			fields := strings.Fields(line)
			if len(fields) >= 6 {
				mounts = append(mounts, map[string]interface{}{
					"filesystem":  fields[0],
					"size":        fields[1],
					"used":        fields[2],
					"available":   fields[3],
					"use_percent": fields[4],
					"mount":       fields[5],
				})
			}
		}
		fs["mounts"] = mounts
	}

	return fs
}

// GetOSInfo returns operating system information
func (e *Executor) GetOSInfo(ctx context.Context) string {
	// Try lsb_release first
	if result := e.Run(ctx, "lsb_release", []string{"-ds"}, 5*time.Second); result.ExitCode == 0 {
		return strings.TrimSpace(result.Stdout)
	}

	// Fall back to /etc/os-release
	if data, err := os.ReadFile("/etc/os-release"); err == nil {
		lines := strings.Split(string(data), "\n")
		for _, line := range lines {
			if strings.HasPrefix(line, "PRETTY_NAME=") {
				name := strings.TrimPrefix(line, "PRETTY_NAME=")
				return strings.Trim(name, "\"")
			}
		}
	}

	// Fall back to uname
	if result := e.Run(ctx, "uname", []string{"-a"}, 5*time.Second); result.ExitCode == 0 {
		return strings.TrimSpace(result.Stdout)
	}

	return "Unknown"
}
