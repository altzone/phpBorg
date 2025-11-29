package executor

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
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

	// Set process group for proper cleanup (platform-specific)
	SetProcessGroup(cmd)

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

// RunShell executes a shell command string
func (e *Executor) RunShell(ctx context.Context, shellCmd string, timeout time.Duration) *CommandResult {
	return e.Run(ctx, "bash", []string{"-c", shellCmd}, timeout)
}

// RunShellWithSudo executes a shell command string with sudo
func (e *Executor) RunShellWithSudo(ctx context.Context, shellCmd string, timeout time.Duration) *CommandResult {
	return e.Run(ctx, "sudo", []string{"bash", "-c", shellCmd}, timeout)
}

// RunAsUser executes a command as a different user (su - user -c "command")
func (e *Executor) RunAsUser(ctx context.Context, user string, shellCmd string, timeout time.Duration) *CommandResult {
	return e.Run(ctx, "su", []string{"-", user, "-c", shellCmd}, timeout)
}

// DockerExec executes a command inside a Docker container
func (e *Executor) DockerExec(ctx context.Context, container string, command []string, timeout time.Duration) *CommandResult {
	args := append([]string{"exec", container}, command...)
	return e.Run(ctx, "docker", args, timeout)
}

// DockerExecShell executes a shell command inside a Docker container
func (e *Executor) DockerExecShell(ctx context.Context, container string, shellCmd string, timeout time.Duration) *CommandResult {
	return e.Run(ctx, "docker", []string{"exec", container, "sh", "-c", shellCmd}, timeout)
}

// PostgresDump creates a PostgreSQL dump as the postgres user
func (e *Executor) PostgresDump(ctx context.Context, database string, outputPath string, timeout time.Duration) *CommandResult {
	// pg_dump as postgres user, output to file
	cmd := fmt.Sprintf("pg_dump %s > %s", database, outputPath)
	return e.RunAsUser(ctx, "postgres", cmd, timeout)
}

// PostgresDumpAll creates a full PostgreSQL cluster dump as the postgres user
func (e *Executor) PostgresDumpAll(ctx context.Context, outputPath string, timeout time.Duration) *CommandResult {
	cmd := fmt.Sprintf("pg_dumpall > %s", outputPath)
	return e.RunAsUser(ctx, "postgres", cmd, timeout)
}

// MysqlDump creates a MySQL/MariaDB dump
func (e *Executor) MysqlDump(ctx context.Context, database string, outputPath string, user string, password string, timeout time.Duration) *CommandResult {
	var cmd string
	if password != "" {
		cmd = fmt.Sprintf("mysqldump -u%s -p%s %s > %s", user, password, database, outputPath)
	} else {
		cmd = fmt.Sprintf("mysqldump -u%s %s > %s", user, database, outputPath)
	}
	return e.RunShell(ctx, cmd, timeout)
}

// MysqlDumpAll creates a full MySQL/MariaDB dump of all databases
func (e *Executor) MysqlDumpAll(ctx context.Context, outputPath string, user string, password string, timeout time.Duration) *CommandResult {
	var cmd string
	if password != "" {
		cmd = fmt.Sprintf("mysqldump -u%s -p%s --all-databases > %s", user, password, outputPath)
	} else {
		cmd = fmt.Sprintf("mysqldump -u%s --all-databases > %s", user, outputPath)
	}
	return e.RunShell(ctx, cmd, timeout)
}

// BorgProgress represents parsed progress info from borg's JSON output
type BorgProgress struct {
	Type             string  `json:"type"`
	NFiles           int64   `json:"nfiles"`
	OriginalSize     int64   `json:"original_size"`
	CompressedSize   int64   `json:"compressed_size"`
	DeduplicatedSize int64   `json:"deduplicated_size"`
	Path             string  `json:"path"`
	Finished         bool    `json:"finished"`
	Time             float64 `json:"time"`
	Message          string  `json:"message"`
	Msgid            string  `json:"msgid"`
}

// ProgressCallback is called with progress updates during backup
type ProgressCallback func(progress BorgProgress)

// BorgCreate executes a borg create command (simple version without streaming)
func (e *Executor) BorgCreate(ctx context.Context, repoPath string, archiveName string, paths []string, excludes []string, compression string, passphrase string) *CommandResult {
	return e.BorgCreateWithProgress(ctx, repoPath, archiveName, paths, excludes, compression, passphrase, nil)
}

// BorgCreateWithProgress executes a borg create command with real-time progress streaming
func (e *Executor) BorgCreateWithProgress(ctx context.Context, repoPath string, archiveName string, paths []string, excludes []string, compression string, passphrase string, progressCallback ProgressCallback) *CommandResult {
	args := []string{
		"create",
		"--verbose",
		"--stats",
		"--progress",
		"--log-json",  // JSON output for machine parsing
	}

	// Add compression
	if compression != "" {
		args = append(args, "--compression", compression)
	}

	// Add excludes
	for _, exclude := range excludes {
		if exclude != "" {
			args = append(args, "--exclude", exclude)
		}
	}

	// Add repository and archive name
	args = append(args, fmt.Sprintf("%s::%s", repoPath, archiveName))

	// Add paths to backup
	args = append(args, paths...)

	// Set environment for borg SSH connection
	env := e.getBorgEnv()

	// Add passphrase if provided
	if passphrase != "" {
		env = append(env, "BORG_PASSPHRASE="+passphrase)
	}

	// If no callback, use simple execution
	if progressCallback == nil {
		return e.runWithEnv(ctx, "borg", args, env, 4*time.Hour)
	}

	// Use streaming execution with progress callback
	return e.runWithEnvAndProgress(ctx, "borg", args, env, 4*time.Hour, progressCallback)
}

// runWithEnvAndProgress executes a command with streaming progress updates
func (e *Executor) runWithEnvAndProgress(ctx context.Context, command string, args []string, env []string, timeout time.Duration, progressCallback ProgressCallback) *CommandResult {
	start := time.Now()

	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, command, args...)
	cmd.Env = env
	SetProcessGroup(cmd)

	var stdout bytes.Buffer
	cmd.Stdout = &stdout

	// Create pipe for stderr to read line by line
	stderrPipe, err := cmd.StderrPipe()
	if err != nil {
		return &CommandResult{
			ExitCode: -1,
			Duration: time.Since(start),
			Error:    fmt.Errorf("failed to create stderr pipe: %w", err),
		}
	}

	if err := cmd.Start(); err != nil {
		return &CommandResult{
			ExitCode: -1,
			Duration: time.Since(start),
			Error:    fmt.Errorf("failed to start command: %w", err),
		}
	}

	// Read stderr line by line and parse JSON progress
	var stderrBuf bytes.Buffer
	scanner := bufio.NewScanner(stderrPipe)
	for scanner.Scan() {
		line := scanner.Text()
		stderrBuf.WriteString(line + "\n")

		// Try to parse as JSON progress
		var progress BorgProgress
		if err := json.Unmarshal([]byte(line), &progress); err == nil {
			// Only send archive_progress events (not file_status)
			if progress.Type == "archive_progress" {
				progressCallback(progress)
			}
		}
	}

	err = cmd.Wait()
	duration := time.Since(start)

	result := &CommandResult{
		Stdout:   stdout.String(),
		Stderr:   stderrBuf.String(),
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
	SetProcessGroup(cmd)

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

	// Detect databases with full details
	caps["databases"] = e.detectDatabases(ctx)

	// Detect Docker with full details
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
		if btrfsResult := e.RunShellWithSudo(ctx, "btrfs subvolume list / 2>/dev/null", 10*time.Second); btrfsResult.ExitCode == 0 {
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

// detectDatabases detects installed databases with full details
func (e *Executor) detectDatabases(ctx context.Context) []map[string]interface{} {
	databases := []map[string]interface{}{}

	// Check for MySQL/MariaDB
	if mysqlInfo := e.detectMysql(ctx); mysqlInfo != nil {
		databases = append(databases, mysqlInfo)
	}

	// Check for PostgreSQL
	if pgInfo := e.detectPostgresql(ctx); pgInfo != nil {
		databases = append(databases, pgInfo)
	}

	// Check for MongoDB
	if mongoInfo := e.detectMongodb(ctx); mongoInfo != nil {
		databases = append(databases, mongoInfo)
	}

	// Check for Redis
	if redisInfo := e.detectRedis(ctx); redisInfo != nil {
		databases = append(databases, redisInfo)
	}

	return databases
}

// detectMysql detects MySQL/MariaDB with full details
func (e *Executor) detectMysql(ctx context.Context) map[string]interface{} {
	if result := e.Run(ctx, "which", []string{"mysql"}, 10*time.Second); result.ExitCode != 0 {
		return nil
	}

	info := map[string]interface{}{
		"type":                "mysql",
		"name":                "MySQL/MariaDB",
		"detected":            true,
		"running":             false,
		"version":             nil,
		"datadir":             nil,
		"datadir_detected":    false,
		"datadir_confidence":  "unknown",
		"datadir_candidates":  []map[string]interface{}{},
		"datadir_size":        nil,
		"volume":              nil,
		"snapshot_capable":    false,
	}

	// Get version
	if verResult := e.Run(ctx, "mysql", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
		info["version"] = strings.TrimSpace(verResult.Stdout)
	}

	// Check if MySQL is running
	running := false
	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mysql"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		running = true
	} else if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mariadb"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		running = true
	} else if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mysqld"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		running = true
	}
	info["running"] = running

	if running {
		// Detect MySQL auth
		info["auth"] = e.detectMysqlAuth(ctx)

		// Get datadir - try multiple methods with confidence tracking
		var datadir string
		confidence := "unknown"
		candidates := []map[string]interface{}{}

		// Method 1: Direct SQL query (HIGH confidence)
		if result := e.RunShell(ctx, `mysql -e "SELECT @@datadir" 2>/dev/null | tail -n1`, 10*time.Second); result.ExitCode == 0 {
			sqlDatadir := strings.TrimSpace(result.Stdout)
			if sqlDatadir != "" && !strings.Contains(sqlDatadir, "ERROR") && !strings.Contains(sqlDatadir, "datadir") {
				datadir = sqlDatadir
				confidence = "high"
				candidates = append(candidates, map[string]interface{}{
					"path": sqlDatadir, "method": "sql_query", "confidence": "high",
				})
			}
		}

		// Method 2: Parse my.cnf (MEDIUM confidence)
		if datadir == "" {
			if result := e.RunShell(ctx, `grep -E "^datadir" /etc/mysql/my.cnf /etc/my.cnf /etc/mysql/mysql.conf.d/*.cnf 2>/dev/null | head -n1 | cut -d= -f2`, 10*time.Second); result.ExitCode == 0 {
				confDatadir := strings.TrimSpace(result.Stdout)
				if confDatadir != "" {
					datadir = confDatadir
					confidence = "medium"
					candidates = append(candidates, map[string]interface{}{
						"path": confDatadir, "method": "config_file", "confidence": "medium",
					})
				}
			}
		}

		// Method 3: Check running process (MEDIUM confidence)
		if datadir == "" {
			if result := e.RunShell(ctx, `ps aux | grep mysqld | grep -oP -- '--datadir=\K[^ ]+' | head -n1`, 10*time.Second); result.ExitCode == 0 {
				procDatadir := strings.TrimSpace(result.Stdout)
				if procDatadir != "" {
					datadir = procDatadir
					confidence = "medium"
					candidates = append(candidates, map[string]interface{}{
						"path": procDatadir, "method": "process_args", "confidence": "medium",
					})
				}
			}
		}

		if datadir != "" {
			info["datadir"] = datadir
			info["datadir_detected"] = true
			info["datadir_confidence"] = confidence

			// Get datadir size
			if result := e.RunShell(ctx, fmt.Sprintf("du -sb %s 2>/dev/null | cut -f1", datadir), 30*time.Second); result.ExitCode == 0 {
				if sizeBytes, err := strconv.ParseInt(strings.TrimSpace(result.Stdout), 10, 64); err == nil {
					info["datadir_size"] = sizeBytes
					info["datadir_size_human"] = formatBytes(sizeBytes)
				}
			}

			// Detect volume and snapshot capability
			if volumeInfo := e.detectVolumeForPath(ctx, datadir); volumeInfo != nil {
				info["volume"] = volumeInfo
				info["snapshot_capable"] = volumeInfo["snapshot_capable"]
			}
		} else {
			// Add common locations as LOW confidence candidates
			candidates = append(candidates, map[string]interface{}{
				"path": "/var/lib/mysql", "method": "common_location", "confidence": "low",
			})
		}
		info["datadir_candidates"] = candidates
	}

	return info
}

// detectMysqlAuth detects MySQL authentication methods
func (e *Executor) detectMysqlAuth(ctx context.Context) map[string]interface{} {
	auth := map[string]interface{}{
		"method":   nil,
		"working":  false,
		"host":     "localhost",
		"port":     3306,
		"user":     nil,
		"password": nil,
		"socket":   nil,
	}

	// Method 1: Try root without password
	if result := e.RunShell(ctx, `mysql -u root -e "SELECT 1" 2>&1`, 5*time.Second); result.ExitCode == 0 && !strings.Contains(result.Stdout, "ERROR") {
		auth["method"] = "root_no_password"
		auth["working"] = true
		auth["user"] = "root"
		auth["password"] = ""
		return auth
	}

	// Method 2: Try debian.cnf (Debian/Ubuntu)
	if result := e.RunShell(ctx, "cat /etc/mysql/debian.cnf 2>/dev/null", 5*time.Second); result.ExitCode == 0 && result.Stdout != "" {
		debianCnf := result.Stdout

		// Extract user
		userRe := regexp.MustCompile(`user\s*=\s*(.+)`)
		passRe := regexp.MustCompile(`password\s*=\s*(.+)`)

		if userMatch := userRe.FindStringSubmatch(debianCnf); len(userMatch) > 1 {
			user := strings.TrimSpace(userMatch[1])
			password := ""
			if passMatch := passRe.FindStringSubmatch(debianCnf); len(passMatch) > 1 {
				password = strings.TrimSpace(passMatch[1])
			}

			// Test credentials
			testCmd := fmt.Sprintf("mysql -u%s -p%s -e 'SELECT 1' 2>&1", user, password)
			if testResult := e.RunShell(ctx, testCmd, 5*time.Second); testResult.ExitCode == 0 && !strings.Contains(testResult.Stdout, "ERROR") {
				auth["method"] = "debian_cnf"
				auth["working"] = true
				auth["user"] = user
				auth["password"] = password
				return auth
			}
		}
	}

	return auth
}

// detectPostgresql detects PostgreSQL with full details
func (e *Executor) detectPostgresql(ctx context.Context) map[string]interface{} {
	if result := e.Run(ctx, "which", []string{"psql"}, 10*time.Second); result.ExitCode != 0 {
		return nil
	}

	info := map[string]interface{}{
		"type":                "postgresql",
		"name":                "PostgreSQL",
		"detected":            true,
		"running":             false,
		"version":             nil,
		"datadir":             nil,
		"datadir_detected":    false,
		"datadir_confidence":  "unknown",
		"datadir_candidates":  []map[string]interface{}{},
		"datadir_size":        nil,
		"volume":              nil,
		"snapshot_capable":    false,
	}

	// Get version
	if verResult := e.Run(ctx, "psql", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
		info["version"] = strings.TrimSpace(verResult.Stdout)
	}

	// Check if running
	running := false
	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "postgresql"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		running = true
	}
	info["running"] = running

	if running {
		// Detect PostgreSQL auth
		info["auth"] = e.detectPostgresqlAuth(ctx)

		// Get datadir - try multiple methods
		var datadir string
		confidence := "unknown"
		candidates := []map[string]interface{}{}

		// Method 1: SQL query (HIGH confidence)
		if result := e.RunShellWithSudo(ctx, `su - postgres -c "psql -t -c 'SHOW data_directory'" 2>/dev/null`, 10*time.Second); result.ExitCode == 0 {
			sqlDatadir := strings.TrimSpace(result.Stdout)
			if sqlDatadir != "" && !strings.Contains(sqlDatadir, "ERROR") {
				datadir = sqlDatadir
				confidence = "high"
				candidates = append(candidates, map[string]interface{}{
					"path": sqlDatadir, "method": "sql_query", "confidence": "high",
				})
			}
		}

		// Method 2: Check process args (MEDIUM confidence)
		if datadir == "" {
			if result := e.RunShell(ctx, `ps aux | grep postgres | grep -oP -- '-D\s*\K[^ ]+' | head -n1`, 10*time.Second); result.ExitCode == 0 {
				procDatadir := strings.TrimSpace(result.Stdout)
				if procDatadir != "" {
					datadir = procDatadir
					confidence = "medium"
					candidates = append(candidates, map[string]interface{}{
						"path": procDatadir, "method": "process_args", "confidence": "medium",
					})
				}
			}
		}

		if datadir != "" {
			info["datadir"] = datadir
			info["datadir_detected"] = true
			info["datadir_confidence"] = confidence

			// Get datadir size
			if result := e.RunShell(ctx, fmt.Sprintf("du -sb %s 2>/dev/null | cut -f1", datadir), 60*time.Second); result.ExitCode == 0 {
				if sizeBytes, err := strconv.ParseInt(strings.TrimSpace(result.Stdout), 10, 64); err == nil {
					info["datadir_size"] = sizeBytes
					info["datadir_size_human"] = formatBytes(sizeBytes)
				}
			}

			// Detect volume
			if volumeInfo := e.detectVolumeForPath(ctx, datadir); volumeInfo != nil {
				info["volume"] = volumeInfo
				info["snapshot_capable"] = volumeInfo["snapshot_capable"]
			}
		} else {
			candidates = append(candidates, map[string]interface{}{
				"path": "/var/lib/postgresql", "method": "common_location", "confidence": "low",
			})
		}
		info["datadir_candidates"] = candidates
	}

	return info
}

// detectPostgresqlAuth detects PostgreSQL authentication methods
func (e *Executor) detectPostgresqlAuth(ctx context.Context) map[string]interface{} {
	auth := map[string]interface{}{
		"method":    nil,
		"working":   false,
		"peer_auth": false,
		"clusters":  []map[string]interface{}{},
		"user":      nil,
		"password":  nil,
	}

	// Method 1: Try peer authentication as postgres user
	if result := e.RunShellWithSudo(ctx, `su - postgres -c "psql -c 'SELECT 1'" 2>&1`, 5*time.Second); result.ExitCode == 0 && !strings.Contains(result.Stdout, "ERROR") {
		auth["method"] = "peer_auth"
		auth["working"] = true
		auth["peer_auth"] = true
		auth["user"] = "postgres"

		// List clusters if pg_lscluster is available
		if clusterResult := e.RunShellWithSudo(ctx, `su - postgres -c "pg_lscluster --no-header" 2>/dev/null`, 5*time.Second); clusterResult.ExitCode == 0 && clusterResult.Stdout != "" {
			clusters := []map[string]interface{}{}
			lines := strings.Split(strings.TrimSpace(clusterResult.Stdout), "\n")
			for _, line := range lines {
				parts := strings.Fields(line)
				if len(parts) >= 5 {
					cluster := map[string]interface{}{
						"version": parts[0],
						"cluster": parts[1],
						"port":    parts[2],
						"status":  parts[3],
						"owner":   parts[4],
					}
					if len(parts) >= 6 {
						cluster["data_directory"] = parts[5]
					}
					clusters = append(clusters, cluster)
				}
			}
			auth["clusters"] = clusters
		}
	}

	return auth
}

// detectMongodb detects MongoDB with full details
func (e *Executor) detectMongodb(ctx context.Context) map[string]interface{} {
	if result := e.Run(ctx, "which", []string{"mongod"}, 10*time.Second); result.ExitCode != 0 {
		return nil
	}

	info := map[string]interface{}{
		"type":                "mongodb",
		"name":                "MongoDB",
		"detected":            true,
		"running":             false,
		"version":             nil,
		"datadir":             nil,
		"datadir_detected":    false,
		"datadir_confidence":  "unknown",
		"datadir_candidates":  []map[string]interface{}{},
		"datadir_size":        nil,
		"volume":              nil,
		"snapshot_capable":    false,
	}

	// Get version
	if verResult := e.RunShell(ctx, "mongod --version 2>/dev/null | head -n1", 10*time.Second); verResult.ExitCode == 0 {
		info["version"] = strings.TrimSpace(verResult.Stdout)
	}

	// Check if running
	running := false
	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "mongod"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		running = true
	}
	info["running"] = running

	if running {
		var datadir string
		confidence := "unknown"
		candidates := []map[string]interface{}{}

		// Method 1: Parse mongod.conf (MEDIUM confidence)
		if result := e.RunShell(ctx, `grep -E "^\s*dbPath:" /etc/mongod.conf 2>/dev/null | awk '{print $2}'`, 10*time.Second); result.ExitCode == 0 {
			confDatadir := strings.TrimSpace(result.Stdout)
			if confDatadir != "" {
				datadir = confDatadir
				confidence = "medium"
				candidates = append(candidates, map[string]interface{}{
					"path": confDatadir, "method": "config_file", "confidence": "medium",
				})
			}
		}

		// Method 2: Check process args
		if datadir == "" {
			if result := e.RunShell(ctx, `ps aux | grep mongod | grep -oP -- '--dbpath[= ]\K[^ ]+' | head -n1`, 10*time.Second); result.ExitCode == 0 {
				procDatadir := strings.TrimSpace(result.Stdout)
				if procDatadir != "" {
					datadir = procDatadir
					confidence = "medium"
					candidates = append(candidates, map[string]interface{}{
						"path": procDatadir, "method": "process_args", "confidence": "medium",
					})
				}
			}
		}

		if datadir != "" {
			info["datadir"] = datadir
			info["datadir_detected"] = true
			info["datadir_confidence"] = confidence

			if result := e.RunShell(ctx, fmt.Sprintf("du -sb %s 2>/dev/null | cut -f1", datadir), 30*time.Second); result.ExitCode == 0 {
				if sizeBytes, err := strconv.ParseInt(strings.TrimSpace(result.Stdout), 10, 64); err == nil {
					info["datadir_size"] = sizeBytes
					info["datadir_size_human"] = formatBytes(sizeBytes)
				}
			}

			if volumeInfo := e.detectVolumeForPath(ctx, datadir); volumeInfo != nil {
				info["volume"] = volumeInfo
				info["snapshot_capable"] = volumeInfo["snapshot_capable"]
			}
		} else {
			candidates = append(candidates, map[string]interface{}{
				"path": "/var/lib/mongodb", "method": "common_location", "confidence": "low",
			})
		}
		info["datadir_candidates"] = candidates
	}

	return info
}

// detectRedis detects Redis with full details
func (e *Executor) detectRedis(ctx context.Context) map[string]interface{} {
	if result := e.Run(ctx, "which", []string{"redis-server"}, 10*time.Second); result.ExitCode != 0 {
		return nil
	}

	info := map[string]interface{}{
		"type":     "redis",
		"name":     "Redis",
		"detected": true,
		"running":  false,
		"version":  nil,
		"datadir":  "/var/lib/redis",
	}

	if verResult := e.Run(ctx, "redis-server", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
		info["version"] = strings.TrimSpace(verResult.Stdout)
	}

	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "redis"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		info["running"] = true
	} else if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "redis-server"}, 5*time.Second); svcResult.ExitCode == 0 && strings.TrimSpace(svcResult.Stdout) == "active" {
		info["running"] = true
	}

	return info
}

// detectVolumeForPath detects volume information for a given path (LVM, ZFS, Btrfs)
func (e *Executor) detectVolumeForPath(ctx context.Context, path string) map[string]interface{} {
	// Get the mount point for this path
	result := e.RunShell(ctx, fmt.Sprintf("df -P '%s' 2>/dev/null | tail -n1", path), 10*time.Second)
	if result.ExitCode != 0 {
		return nil
	}

	dfLine := strings.TrimSpace(result.Stdout)
	if dfLine == "" {
		return nil
	}

	parts := strings.Fields(dfLine)
	if len(parts) < 6 {
		return nil
	}

	device := parts[0]
	mountpoint := parts[5]

	volumeInfo := map[string]interface{}{
		"device":           device,
		"mountpoint":       mountpoint,
		"type":             "standard",
		"snapshot_capable": false,
	}

	// Check if it's LVM
	lvmRe := regexp.MustCompile(`/dev/mapper/(.+?)-(.+)|/dev/([^/]+)/([^/]+)`)
	if matches := lvmRe.FindStringSubmatch(device); len(matches) > 0 {
		// Get LVM details
		if lvResult := e.RunShellWithSudo(ctx, fmt.Sprintf("lvs --noheadings --units b -o lv_name,vg_name,lv_size,lv_path '%s' 2>/dev/null", device), 10*time.Second); lvResult.ExitCode == 0 {
			lvsOutput := strings.TrimSpace(lvResult.Stdout)
			if lvsOutput != "" {
				lvParts := strings.Fields(lvsOutput)
				if len(lvParts) >= 3 {
					volumeInfo["type"] = "lvm"
					volumeInfo["snapshot_capable"] = true
					volumeInfo["lv_name"] = lvParts[0]
					volumeInfo["vg_name"] = lvParts[1]
					volumeInfo["lv_size"] = strings.TrimSuffix(lvParts[2], "B")

					// Get VG free space
					if vgResult := e.RunShellWithSudo(ctx, fmt.Sprintf("vgs --noheadings --units b -o vg_free '%s' 2>/dev/null", lvParts[1]), 10*time.Second); vgResult.ExitCode == 0 {
						vgFree := strings.TrimSpace(vgResult.Stdout)
						if vgFree != "" {
							vgFree = strings.TrimSuffix(vgFree, "B")
							volumeInfo["vg_free"] = vgFree
							if vgFreeBytes, err := strconv.ParseInt(vgFree, 10, 64); err == nil {
								volumeInfo["vg_free_bytes"] = vgFreeBytes
								volumeInfo["vg_free_human"] = formatBytes(vgFreeBytes)
							}
						}
					}
				}
			}
		}
	}

	// Check if it's Btrfs
	if btrfsResult := e.RunShellWithSudo(ctx, fmt.Sprintf("btrfs filesystem show '%s' 2>/dev/null", mountpoint), 10*time.Second); btrfsResult.ExitCode == 0 && btrfsResult.Stdout != "" {
		volumeInfo["type"] = "btrfs"
		volumeInfo["snapshot_capable"] = true

		// Get Btrfs usage
		if usageResult := e.RunShellWithSudo(ctx, fmt.Sprintf("btrfs filesystem usage '%s' 2>/dev/null | grep -E 'Free.*estimated' | head -n1", mountpoint), 10*time.Second); usageResult.ExitCode == 0 {
			btrfsFree := strings.TrimSpace(usageResult.Stdout)
			if btrfsFree != "" {
				volumeInfo["btrfs_free"] = btrfsFree
			}
		}
	}

	// Check if it's ZFS
	if zfsResult := e.RunShell(ctx, fmt.Sprintf("zfs list -H -o name,avail '%s' 2>/dev/null", mountpoint), 10*time.Second); zfsResult.ExitCode == 0 && zfsResult.Stdout != "" {
		volumeInfo["type"] = "zfs"
		volumeInfo["snapshot_capable"] = true

		zfsParts := strings.Fields(strings.TrimSpace(zfsResult.Stdout))
		if len(zfsParts) >= 2 {
			volumeInfo["zfs_dataset"] = zfsParts[0]
			volumeInfo["zfs_avail"] = zfsParts[1]
		}
	}

	return volumeInfo
}

// detectDocker detects Docker environment with full details
func (e *Executor) detectDocker(ctx context.Context) map[string]interface{} {
	dockerInfo := map[string]interface{}{
		"installed":                  false,
		"running":                    false,
		"version":                    nil,
		"containers":                 []map[string]interface{}{},
		"networks":                   []map[string]interface{}{},
		"volumes":                    []map[string]interface{}{},
		"compose_projects":           map[string]interface{}{},
		"standalone_containers":      []map[string]interface{}{},
		"container_count":            0,
		"network_count":              0,
		"volume_count":               0,
		"compose_project_count":      0,
		"standalone_container_count": 0,
		"host_config": map[string]interface{}{
			"config_path":        "/etc/docker",
			"config_exists":      false,
			"daemon_json_exists": false,
			"daemon_json_path":   "/etc/docker/daemon.json",
		},
	}

	// Check if Docker is installed
	if result := e.Run(ctx, "which", []string{"docker"}, 10*time.Second); result.ExitCode != 0 {
		return dockerInfo
	}

	dockerInfo["installed"] = true

	// Get Docker version
	if verResult := e.Run(ctx, "docker", []string{"--version"}, 10*time.Second); verResult.ExitCode == 0 {
		dockerInfo["version"] = strings.TrimSpace(verResult.Stdout)
	}

	// Check if Docker daemon is running
	if svcResult := e.Run(ctx, "systemctl", []string{"is-active", "docker"}, 5*time.Second); svcResult.ExitCode != 0 || strings.TrimSpace(svcResult.Stdout) != "active" {
		return dockerInfo
	}

	dockerInfo["running"] = true

	// Check if we need sudo for docker commands (user not in docker group)
	needsSudo := false
	if testResult := e.Run(ctx, "docker", []string{"ps", "-q"}, 5*time.Second); testResult.ExitCode != 0 {
		// Try with sudo
		if sudoResult := e.RunWithSudo(ctx, "docker", []string{"ps", "-q"}, 5*time.Second); sudoResult.ExitCode == 0 {
			needsSudo = true
		}
	}

	// Detect Docker host configuration
	dockerInfo["host_config"] = e.detectDockerHostConfig(ctx)

	// Helper function to run docker commands with or without sudo
	runDocker := func(args []string, timeout time.Duration) *CommandResult {
		if needsSudo {
			return e.RunWithSudo(ctx, "docker", args, timeout)
		}
		return e.Run(ctx, "docker", args, timeout)
	}

	// Get ALL containers (running + stopped)
	containers := []map[string]interface{}{}
	composeProjects := map[string]interface{}{}
	standaloneContainers := []map[string]interface{}{}

	if psResult := runDocker([]string{"ps", "-a", "--format", "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.State}}"}, 15*time.Second); psResult.ExitCode == 0 {
		containerLines := strings.TrimSpace(psResult.Stdout)
		if containerLines != "" {
			lines := strings.Split(containerLines, "\n")
			for _, line := range lines {
				parts := strings.Split(line, "|")
				if len(parts) >= 5 {
					containerId := parts[0]
					containerName := parts[1]

					// Get detailed container info via inspect
					containerDetails := e.inspectDockerContainer(ctx, containerId, needsSudo)

					container := map[string]interface{}{
						"id":              containerId,
						"name":            containerName,
						"image":           parts[2],
						"status":          parts[3],
						"state":           parts[4],
						"volumes":         containerDetails["volumes"],
						"compose_project": containerDetails["compose_project"],
						"compose_file":    containerDetails["compose_file"],
						"working_dir":     containerDetails["working_dir"],
						"networks":        containerDetails["networks"],
						"is_standalone":   containerDetails["is_standalone"],
						"dockerfile_path": containerDetails["dockerfile_path"],
					}

					containers = append(containers, container)

					// Track compose projects
					if composeProject, ok := containerDetails["compose_project"].(string); ok && composeProject != "" {
						if _, exists := composeProjects[composeProject]; !exists {
							composeProjects[composeProject] = map[string]interface{}{
								"name":         composeProject,
								"working_dir":  containerDetails["working_dir"],
								"compose_file": containerDetails["compose_file"],
								"containers":   []string{},
							}
						}
						if project, ok := composeProjects[composeProject].(map[string]interface{}); ok {
							if existingContainers, ok := project["containers"].([]string); ok {
								project["containers"] = append(existingContainers, containerName)
							}
						}
					}

					// Track standalone containers
					if isStandalone, ok := containerDetails["is_standalone"].(bool); ok && isStandalone {
						if dockerfilePath, ok := containerDetails["dockerfile_path"].(string); ok && dockerfilePath != "" {
							standaloneContainers = append(standaloneContainers, map[string]interface{}{
								"name":            containerName,
								"image":           parts[2],
								"dockerfile_path": dockerfilePath,
								"volumes":         containerDetails["volumes"],
							})
						}
					}
				}
			}
		}
	}

	dockerInfo["containers"] = containers
	dockerInfo["compose_projects"] = composeProjects
	dockerInfo["standalone_containers"] = standaloneContainers

	// Get Docker networks
	dockerInfo["networks"] = e.detectDockerNetworks(ctx, needsSudo)

	// Get Docker volumes
	dockerInfo["volumes"] = e.detectDockerVolumes(ctx, needsSudo)

	// Update counts
	dockerInfo["container_count"] = len(containers)
	dockerInfo["network_count"] = len(dockerInfo["networks"].([]map[string]interface{}))
	dockerInfo["volume_count"] = len(dockerInfo["volumes"].([]map[string]interface{}))
	dockerInfo["compose_project_count"] = len(composeProjects)
	dockerInfo["standalone_container_count"] = len(standaloneContainers)

	return dockerInfo
}

// inspectDockerContainer gets detailed information about a container
func (e *Executor) inspectDockerContainer(ctx context.Context, containerId string, needsSudo bool) map[string]interface{} {
	details := map[string]interface{}{
		"volumes":         []map[string]interface{}{},
		"compose_project": nil,
		"compose_file":    nil,
		"working_dir":     nil,
		"networks":        []string{},
		"dockerfile_path": nil,
		"is_standalone":   false,
	}

	// Get container inspect data
	var result *CommandResult
	if needsSudo {
		result = e.RunWithSudo(ctx, "docker", []string{"inspect", containerId}, 10*time.Second)
	} else {
		result = e.Run(ctx, "docker", []string{"inspect", containerId}, 10*time.Second)
	}
	if result.ExitCode == 0 {
		inspectJson := strings.TrimSpace(result.Stdout)
		if inspectJson != "" {
			var inspectData []map[string]interface{}
			if err := json.Unmarshal([]byte(inspectJson), &inspectData); err == nil && len(inspectData) > 0 {
				container := inspectData[0]

				// Extract volumes (Mounts)
				volumes := []map[string]interface{}{}
				if mounts, ok := container["Mounts"].([]interface{}); ok {
					for _, m := range mounts {
						if mount, ok := m.(map[string]interface{}); ok {
							mountType, _ := mount["Type"].(string)
							if mountType == "bind" {
								if source, ok := mount["Source"].(string); ok {
									volumes = append(volumes, map[string]interface{}{
										"type":        "bind",
										"source":      source,
										"destination": mount["Destination"],
										"mode":        mount["Mode"],
									})
								}
							} else if mountType == "volume" {
								volumes = append(volumes, map[string]interface{}{
									"type":        "volume",
									"name":        mount["Name"],
									"source":      mount["Source"],
									"destination": mount["Destination"],
								})
							}
						}
					}
				}
				details["volumes"] = volumes

				// Extract compose project info from labels
				if config, ok := container["Config"].(map[string]interface{}); ok {
					if labels, ok := config["Labels"].(map[string]interface{}); ok {
						if project, ok := labels["com.docker.compose.project"].(string); ok {
							details["compose_project"] = project
						}
						if workDir, ok := labels["com.docker.compose.project.working_dir"].(string); ok {
							details["working_dir"] = workDir
						}
						if configFiles, ok := labels["com.docker.compose.project.config_files"].(string); ok {
							details["compose_file"] = configFiles
						}
						if dockerfile, ok := labels["dockerfile"].(string); ok {
							details["dockerfile_path"] = dockerfile
						} else if buildContext, ok := labels["build.context"].(string); ok {
							details["dockerfile_path"] = buildContext
						}
					}
				}

				// Detect standalone containers (not from compose)
				details["is_standalone"] = details["compose_project"] == nil

				// For standalone containers, try to find Dockerfile in bind mounts
				if isStandalone, ok := details["is_standalone"].(bool); ok && isStandalone && len(volumes) > 0 {
					if dockerfilePath := e.findDockerfileInMounts(ctx, volumes); dockerfilePath != "" {
						details["dockerfile_path"] = dockerfilePath
					}
				}

				// Extract networks
				if networkSettings, ok := container["NetworkSettings"].(map[string]interface{}); ok {
					if networks, ok := networkSettings["Networks"].(map[string]interface{}); ok {
						networkNames := []string{}
						for name := range networks {
							networkNames = append(networkNames, name)
						}
						details["networks"] = networkNames
					}
				}
			}
		}
	}

	return details
}

// findDockerfileInMounts tries to find Dockerfile in container bind mounts
func (e *Executor) findDockerfileInMounts(ctx context.Context, volumes []map[string]interface{}) string {
	for _, volume := range volumes {
		if volumeType, ok := volume["type"].(string); !ok || volumeType != "bind" {
			continue
		}

		if mountPath, ok := volume["source"].(string); ok {
			// Check for Dockerfile in the bind mount directory
			if result := e.RunShell(ctx, fmt.Sprintf("test -f %s/Dockerfile && echo 'found'", mountPath), 5*time.Second); result.ExitCode == 0 && strings.TrimSpace(result.Stdout) == "found" {
				return filepath.Join(mountPath, "Dockerfile")
			}

			// Check parent directory
			parentPath := filepath.Dir(mountPath)
			if result := e.RunShell(ctx, fmt.Sprintf("test -f %s/Dockerfile && echo 'found'", parentPath), 5*time.Second); result.ExitCode == 0 && strings.TrimSpace(result.Stdout) == "found" {
				return filepath.Join(parentPath, "Dockerfile")
			}
		}
	}

	return ""
}

// detectDockerNetworks detects Docker networks
func (e *Executor) detectDockerNetworks(ctx context.Context, needsSudo bool) []map[string]interface{} {
	networks := []map[string]interface{}{}

	var result *CommandResult
	if needsSudo {
		result = e.RunWithSudo(ctx, "docker", []string{"network", "ls", "--format", "{{.ID}}|{{.Name}}|{{.Driver}}|{{.Scope}}"}, 10*time.Second)
	} else {
		result = e.Run(ctx, "docker", []string{"network", "ls", "--format", "{{.ID}}|{{.Name}}|{{.Driver}}|{{.Scope}}"}, 10*time.Second)
	}
	if result.ExitCode == 0 {
		lines := strings.Split(strings.TrimSpace(result.Stdout), "\n")
		for _, line := range lines {
			parts := strings.Split(line, "|")
			if len(parts) >= 4 {
				networks = append(networks, map[string]interface{}{
					"id":     parts[0],
					"name":   parts[1],
					"driver": parts[2],
					"scope":  parts[3],
				})
			}
		}
	}

	return networks
}

// detectDockerVolumes detects Docker volumes
func (e *Executor) detectDockerVolumes(ctx context.Context, needsSudo bool) []map[string]interface{} {
	volumes := []map[string]interface{}{}

	var result *CommandResult
	if needsSudo {
		result = e.RunWithSudo(ctx, "docker", []string{"volume", "ls", "--format", "{{.Name}}|{{.Driver}}|{{.Mountpoint}}"}, 10*time.Second)
	} else {
		result = e.Run(ctx, "docker", []string{"volume", "ls", "--format", "{{.Name}}|{{.Driver}}|{{.Mountpoint}}"}, 10*time.Second)
	}
	if result.ExitCode == 0 {
		lines := strings.Split(strings.TrimSpace(result.Stdout), "\n")
		for _, line := range lines {
			parts := strings.Split(line, "|")
			if len(parts) >= 2 {
				mountpoint := ""
				if len(parts) >= 3 {
					mountpoint = parts[2]
				} else {
					mountpoint = "/var/lib/docker/volumes/" + parts[0]
				}
				volumes = append(volumes, map[string]interface{}{
					"name":       parts[0],
					"driver":     parts[1],
					"mountpoint": mountpoint,
				})
			}
		}
	}

	return volumes
}

// detectDockerHostConfig detects Docker host configuration
func (e *Executor) detectDockerHostConfig(ctx context.Context) map[string]interface{} {
	config := map[string]interface{}{
		"config_path":        "/etc/docker",
		"config_exists":      false,
		"daemon_json_exists": false,
		"daemon_json_path":   "/etc/docker/daemon.json",
	}

	// Check if /etc/docker exists
	if result := e.RunShell(ctx, `test -d /etc/docker && echo "exists"`, 5*time.Second); result.ExitCode == 0 && strings.TrimSpace(result.Stdout) == "exists" {
		config["config_exists"] = true
	}

	// Check if daemon.json exists
	if result := e.RunShell(ctx, `test -f /etc/docker/daemon.json && echo "exists"`, 5*time.Second); result.ExitCode == 0 && strings.TrimSpace(result.Stdout) == "exists" {
		config["daemon_json_exists"] = true
	}

	return config
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

// formatBytes formats bytes to human-readable format
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

	return fmt.Sprintf("%.2f %s", size, units[i])
}
