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
func (e *Executor) DetectCapabilities(ctx context.Context) map[string]interface{} {
	caps := make(map[string]interface{})

	// Check for borg
	if result := e.Run(ctx, "which", []string{"borg"}, 10*time.Second); result.ExitCode == 0 {
		caps["borg"] = true
		if verResult := e.Run(ctx, "borg", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
			caps["borg_version"] = strings.TrimSpace(verResult.Stdout)
		}
	} else {
		caps["borg"] = false
	}

	// Check for LVM
	if result := e.RunWithSudo(ctx, "which", []string{"lvcreate"}, 10*time.Second); result.ExitCode == 0 {
		caps["lvm"] = true
	} else {
		caps["lvm"] = false
	}

	// Check for Docker
	if result := e.Run(ctx, "which", []string{"docker"}, 10*time.Second); result.ExitCode == 0 {
		caps["docker"] = true
	} else {
		caps["docker"] = false
	}

	// Check for MySQL
	if result := e.Run(ctx, "which", []string{"mysqldump"}, 10*time.Second); result.ExitCode == 0 {
		caps["mysql"] = true
	} else {
		caps["mysql"] = false
	}

	// Check for PostgreSQL
	if result := e.Run(ctx, "which", []string{"pg_dump"}, 10*time.Second); result.ExitCode == 0 {
		caps["postgresql"] = true
	} else {
		caps["postgresql"] = false
	}

	return caps
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
