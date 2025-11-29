//go:build linux
// +build linux

package platform

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

func init() {
	Current = &LinuxPlatform{}
}

// LinuxPlatform implements Platform for Linux systems
type LinuxPlatform struct{}

func (p *LinuxPlatform) GetName() string {
	return "linux"
}

// Shell execution

func (p *LinuxPlatform) RunShell(ctx context.Context, command string, timeout time.Duration) *CommandResult {
	return p.RunCommand(ctx, "bash", []string{"-c", command}, timeout)
}

func (p *LinuxPlatform) RunCommand(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, command, args...)
	var stdout, stderr strings.Builder
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()
	exitCode := 0
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		} else {
			exitCode = -1
		}
	}

	return &CommandResult{
		Stdout:   stdout.String(),
		Stderr:   stderr.String(),
		ExitCode: exitCode,
		Error:    err,
	}
}

func (p *LinuxPlatform) RunAsAdmin(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
	sudoArgs := append([]string{command}, args...)
	return p.RunCommand(ctx, "sudo", sudoArgs, timeout)
}

func (p *LinuxPlatform) RunAsUser(ctx context.Context, username string, command string, timeout time.Duration) *CommandResult {
	return p.RunCommand(ctx, "su", []string{"-", username, "-c", command}, timeout)
}

// Path utilities

func (p *LinuxPlatform) GetConfigDir() string {
	return "/etc/phpborg-agent"
}

func (p *LinuxPlatform) GetDataDir() string {
	return "/var/lib/phpborg-agent"
}

func (p *LinuxPlatform) GetLogDir() string {
	return "/var/log/phpborg-agent"
}

func (p *LinuxPlatform) GetTempDir() string {
	return "/tmp"
}

func (p *LinuxPlatform) GetHomeDir(username string) string {
	if username == "" {
		if home := os.Getenv("HOME"); home != "" {
			return home
		}
		return "/root"
	}
	u, err := user.Lookup(username)
	if err != nil {
		return "/home/" + username
	}
	return u.HomeDir
}

func (p *LinuxPlatform) NormalizePath(path string) string {
	return filepath.Clean(path)
}

func (p *LinuxPlatform) PathExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

func (p *LinuxPlatform) IsAbsolutePath(path string) bool {
	return filepath.IsAbs(path)
}

// Service management

func (p *LinuxPlatform) GetServiceStatus(ctx context.Context, serviceName string) *ServiceStatus {
	status := &ServiceStatus{Name: serviceName}

	// Check if service exists
	result := p.RunCommand(ctx, "systemctl", []string{"cat", serviceName}, 5*time.Second)
	status.Exists = result.ExitCode == 0

	if !status.Exists {
		return status
	}

	// Check if running
	result = p.RunCommand(ctx, "systemctl", []string{"is-active", serviceName}, 5*time.Second)
	status.Running = strings.TrimSpace(result.Stdout) == "active"

	// Check start type
	result = p.RunCommand(ctx, "systemctl", []string{"is-enabled", serviceName}, 5*time.Second)
	enabledStatus := strings.TrimSpace(result.Stdout)
	switch enabledStatus {
	case "enabled":
		status.StartType = "automatic"
	case "disabled":
		status.StartType = "disabled"
	default:
		status.StartType = "manual"
	}

	return status
}

func (p *LinuxPlatform) StartService(ctx context.Context, serviceName string) error {
	result := p.RunAsAdmin(ctx, "systemctl", []string{"start", serviceName}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to start service: %s", result.Stderr)
	}
	return nil
}

func (p *LinuxPlatform) StopService(ctx context.Context, serviceName string) error {
	result := p.RunAsAdmin(ctx, "systemctl", []string{"stop", serviceName}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to stop service: %s", result.Stderr)
	}
	return nil
}

func (p *LinuxPlatform) RestartService(ctx context.Context, serviceName string) error {
	result := p.RunAsAdmin(ctx, "systemctl", []string{"restart", serviceName}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to restart service: %s", result.Stderr)
	}
	return nil
}

func (p *LinuxPlatform) InstallService(ctx context.Context, name, displayName, description, execPath string) error {
	// Create systemd service file
	serviceContent := fmt.Sprintf(`[Unit]
Description=%s
After=network.target

[Service]
Type=simple
ExecStart=%s
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
`, description, execPath)

	servicePath := fmt.Sprintf("/etc/systemd/system/%s.service", name)
	if err := os.WriteFile(servicePath, []byte(serviceContent), 0644); err != nil {
		return fmt.Errorf("failed to write service file: %w", err)
	}

	// Reload systemd
	result := p.RunAsAdmin(ctx, "systemctl", []string{"daemon-reload"}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to reload systemd: %s", result.Stderr)
	}

	// Enable service
	result = p.RunAsAdmin(ctx, "systemctl", []string{"enable", name}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to enable service: %s", result.Stderr)
	}

	return nil
}

func (p *LinuxPlatform) UninstallService(ctx context.Context, name string) error {
	// Stop service
	p.StopService(ctx, name)

	// Disable service
	p.RunAsAdmin(ctx, "systemctl", []string{"disable", name}, 30*time.Second)

	// Remove service file
	servicePath := fmt.Sprintf("/etc/systemd/system/%s.service", name)
	os.Remove(servicePath)

	// Reload systemd
	p.RunAsAdmin(ctx, "systemctl", []string{"daemon-reload"}, 30*time.Second)

	return nil
}

// System information

func (p *LinuxPlatform) GetSystemInfo(ctx context.Context) *SystemInfo {
	info := &SystemInfo{}

	// OS info
	if data, err := os.ReadFile("/etc/os-release"); err == nil {
		lines := strings.Split(string(data), "\n")
		for _, line := range lines {
			if strings.HasPrefix(line, "PRETTY_NAME=") {
				info.OS = strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), "\"")
			} else if strings.HasPrefix(line, "VERSION_ID=") {
				info.OSVersion = strings.Trim(strings.TrimPrefix(line, "VERSION_ID="), "\"")
			}
		}
	}

	// Architecture
	result := p.RunCommand(ctx, "uname", []string{"-m"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.Architecture = strings.TrimSpace(result.Stdout)
	}

	// Hostname
	if hostname, err := os.Hostname(); err == nil {
		info.Hostname = hostname
	}

	// CPU cores
	result = p.RunCommand(ctx, "nproc", nil, 5*time.Second)
	if result.ExitCode == 0 {
		info.CPUCores, _ = strconv.Atoi(strings.TrimSpace(result.Stdout))
	}

	// CPU model
	if data, err := os.ReadFile("/proc/cpuinfo"); err == nil {
		re := regexp.MustCompile(`model name\s*:\s*(.+)`)
		matches := re.FindStringSubmatch(string(data))
		if len(matches) > 1 {
			info.CPUModel = strings.TrimSpace(matches[1])
		}
	}

	// Memory
	if data, err := os.ReadFile("/proc/meminfo"); err == nil {
		scanner := bufio.NewScanner(strings.NewReader(string(data)))
		for scanner.Scan() {
			line := scanner.Text()
			if strings.HasPrefix(line, "MemTotal:") {
				fields := strings.Fields(line)
				if len(fields) >= 2 {
					kb, _ := strconv.ParseInt(fields[1], 10, 64)
					info.TotalMemoryMB = kb / 1024
				}
			} else if strings.HasPrefix(line, "MemAvailable:") {
				fields := strings.Fields(line)
				if len(fields) >= 2 {
					kb, _ := strconv.ParseInt(fields[1], 10, 64)
					info.FreeMemoryMB = kb / 1024
				}
			}
		}
	}

	// Uptime
	if data, err := os.ReadFile("/proc/uptime"); err == nil {
		fields := strings.Fields(string(data))
		if len(fields) >= 1 {
			seconds, _ := strconv.ParseFloat(fields[0], 64)
			info.Uptime = time.Duration(seconds) * time.Second
		}
	}

	// IP addresses
	result = p.RunCommand(ctx, "hostname", []string{"-I"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.IPAddresses = strings.Fields(strings.TrimSpace(result.Stdout))
	}

	return info
}

func (p *LinuxPlatform) GetDiskInfo(ctx context.Context, path string) *DiskInfo {
	result := p.RunShell(ctx, fmt.Sprintf("df -BG '%s' 2>/dev/null | tail -n1", path), 10*time.Second)
	if result.ExitCode != 0 {
		return nil
	}

	fields := strings.Fields(result.Stdout)
	if len(fields) < 6 {
		return nil
	}

	parseGB := func(s string) float64 {
		s = strings.TrimSuffix(s, "G")
		val, _ := strconv.ParseFloat(s, 64)
		return val
	}

	info := &DiskInfo{
		Device:     fields[0],
		Path:       fields[5],
		TotalGB:    parseGB(fields[1]),
		UsedGB:     parseGB(fields[2]),
		FreeGB:     parseGB(fields[3]),
	}

	if info.TotalGB > 0 {
		info.UsedPercent = (info.UsedGB / info.TotalGB) * 100
	}

	// Get filesystem type
	result = p.RunShell(ctx, fmt.Sprintf("df -T '%s' 2>/dev/null | tail -n1", path), 10*time.Second)
	if result.ExitCode == 0 {
		fields := strings.Fields(result.Stdout)
		if len(fields) >= 2 {
			info.Filesystem = fields[1]
		}
	}

	return info
}

func (p *LinuxPlatform) GetAllDisks(ctx context.Context) []*DiskInfo {
	result := p.RunCommand(ctx, "df", []string{"-BG", "--output=source,fstype,size,used,avail,target"}, 10*time.Second)
	if result.ExitCode != 0 {
		return nil
	}

	var disks []*DiskInfo
	lines := strings.Split(result.Stdout, "\n")
	for i, line := range lines {
		if i == 0 || strings.TrimSpace(line) == "" {
			continue // Skip header
		}
		fields := strings.Fields(line)
		if len(fields) < 6 {
			continue
		}

		parseGB := func(s string) float64 {
			s = strings.TrimSuffix(s, "G")
			val, _ := strconv.ParseFloat(s, 64)
			return val
		}

		disk := &DiskInfo{
			Device:     fields[0],
			Filesystem: fields[1],
			TotalGB:    parseGB(fields[2]),
			UsedGB:     parseGB(fields[3]),
			FreeGB:     parseGB(fields[4]),
			Path:       fields[5],
		}

		if disk.TotalGB > 0 {
			disk.UsedPercent = (disk.UsedGB / disk.TotalGB) * 100
		}

		disks = append(disks, disk)
	}

	return disks
}

// Database detection

func (p *LinuxPlatform) DetectMySQL(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "mysql"}

	// Check if MySQL/MariaDB service is running
	for _, svc := range []string{"mysql", "mariadb", "mysqld"} {
		status := p.GetServiceStatus(ctx, svc)
		if status.Running {
			info.Running = true
			break
		}
	}

	if !info.Running {
		return nil
	}

	// Get version
	result := p.RunCommand(ctx, "mysql", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.Version = strings.TrimSpace(result.Stdout)
	}

	// Find data directory
	configPaths := []string{
		"/etc/mysql/my.cnf",
		"/etc/my.cnf",
		"/etc/mysql/mysql.conf.d/mysqld.cnf",
	}
	for _, cfg := range configPaths {
		if p.PathExists(cfg) {
			info.ConfigFile = cfg
			// Try to extract datadir
			result := p.RunShell(ctx, fmt.Sprintf("grep -E '^datadir' '%s' 2>/dev/null | head -1", cfg), 5*time.Second)
			if result.ExitCode == 0 && strings.Contains(result.Stdout, "=") {
				parts := strings.SplitN(result.Stdout, "=", 2)
				if len(parts) == 2 {
					info.DataDir = strings.TrimSpace(parts[1])
				}
			}
			break
		}
	}

	// Default port
	info.Port = 3306
	info.Host = "localhost"
	info.SocketPath = "/var/run/mysqld/mysqld.sock"

	return info
}

func (p *LinuxPlatform) DetectPostgreSQL(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "postgresql"}

	// Check if PostgreSQL service is running
	status := p.GetServiceStatus(ctx, "postgresql")
	if !status.Running {
		return nil
	}
	info.Running = true

	// Get version
	result := p.RunCommand(ctx, "psql", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.Version = strings.TrimSpace(result.Stdout)
	}

	// Get data directory (run as postgres user)
	result = p.RunAsUser(ctx, "postgres", "psql -t -c 'SHOW data_directory'", 10*time.Second)
	if result.ExitCode == 0 {
		info.DataDir = strings.TrimSpace(result.Stdout)
	}

	// Default settings
	info.Port = 5432
	info.Host = "localhost"
	info.SocketPath = "/var/run/postgresql"

	return info
}

func (p *LinuxPlatform) DetectMongoDB(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "mongodb"}

	// Check if MongoDB service is running
	for _, svc := range []string{"mongod", "mongodb"} {
		status := p.GetServiceStatus(ctx, svc)
		if status.Running {
			info.Running = true
			break
		}
	}

	if !info.Running {
		return nil
	}

	// Get version
	result := p.RunCommand(ctx, "mongod", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		lines := strings.Split(result.Stdout, "\n")
		if len(lines) > 0 {
			info.Version = strings.TrimSpace(lines[0])
		}
	}

	// Config file
	if p.PathExists("/etc/mongod.conf") {
		info.ConfigFile = "/etc/mongod.conf"
	}

	// Default settings
	info.Port = 27017
	info.Host = "localhost"
	info.DataDir = "/var/lib/mongodb"

	return info
}

func (p *LinuxPlatform) DetectRedis(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "redis"}

	// Check if Redis service is running
	for _, svc := range []string{"redis", "redis-server"} {
		status := p.GetServiceStatus(ctx, svc)
		if status.Running {
			info.Running = true
			break
		}
	}

	if !info.Running {
		return nil
	}

	// Get version
	result := p.RunCommand(ctx, "redis-server", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.Version = strings.TrimSpace(result.Stdout)
	}

	// Default settings
	info.Port = 6379
	info.Host = "localhost"
	info.DataDir = "/var/lib/redis"
	info.SocketPath = "/var/run/redis/redis.sock"

	return info
}

func (p *LinuxPlatform) DetectMSSQL(ctx context.Context) *DatabaseInfo {
	// MSSQL is not common on Linux, but SQL Server does run on Linux
	info := &DatabaseInfo{Type: "mssql"}

	status := p.GetServiceStatus(ctx, "mssql-server")
	if !status.Running {
		return nil
	}
	info.Running = true

	info.Port = 1433
	info.Host = "localhost"
	info.DataDir = "/var/opt/mssql/data"

	return info
}

// Docker detection

func (p *LinuxPlatform) IsDockerAvailable(ctx context.Context) bool {
	result := p.RunCommand(ctx, "which", []string{"docker"}, 5*time.Second)
	if result.ExitCode != 0 {
		return false
	}

	status := p.GetServiceStatus(ctx, "docker")
	return status.Running
}

func (p *LinuxPlatform) GetDockerInfo(ctx context.Context) map[string]interface{} {
	if !p.IsDockerAvailable(ctx) {
		return nil
	}

	result := p.RunCommand(ctx, "docker", []string{"info", "--format", "{{json .}}"}, 30*time.Second)
	if result.ExitCode != 0 {
		return nil
	}

	// Return raw JSON for now
	return map[string]interface{}{
		"raw": result.Stdout,
	}
}

// Snapshot capabilities

func (p *LinuxPlatform) HasSnapshotCapability(ctx context.Context) bool {
	// Check for LVM
	result := p.RunCommand(ctx, "which", []string{"lvcreate"}, 5*time.Second)
	if result.ExitCode == 0 {
		return true
	}

	// Check for ZFS
	result = p.RunCommand(ctx, "which", []string{"zfs"}, 5*time.Second)
	if result.ExitCode == 0 {
		return true
	}

	// Check for Btrfs
	result = p.RunCommand(ctx, "which", []string{"btrfs"}, 5*time.Second)
	return result.ExitCode == 0
}

func (p *LinuxPlatform) CreateSnapshot(ctx context.Context, sourcePath, snapshotName string) (string, error) {
	// This is a simplified implementation - real implementation would detect the filesystem type
	// and use appropriate snapshot mechanism (LVM, ZFS, Btrfs)
	return "", fmt.Errorf("snapshot creation not implemented for this filesystem")
}

func (p *LinuxPlatform) DeleteSnapshot(ctx context.Context, snapshotPath string) error {
	return fmt.Errorf("snapshot deletion not implemented for this filesystem")
}

// SSH utilities

func (p *LinuxPlatform) GetDefaultSSHKeyPath() string {
	home := p.GetHomeDir("")
	return filepath.Join(home, ".ssh", "id_rsa")
}

func (p *LinuxPlatform) GenerateSSHKey(ctx context.Context, keyPath string) error {
	// Create .ssh directory if needed
	sshDir := filepath.Dir(keyPath)
	if err := os.MkdirAll(sshDir, 0700); err != nil {
		return fmt.Errorf("failed to create .ssh directory: %w", err)
	}

	// Generate key
	result := p.RunCommand(ctx, "ssh-keygen", []string{
		"-t", "ed25519",
		"-f", keyPath,
		"-N", "", // No passphrase
		"-C", "phpborg-agent",
	}, 30*time.Second)

	if result.ExitCode != 0 {
		return fmt.Errorf("failed to generate SSH key: %s", result.Stderr)
	}

	return nil
}

// Process management

func (p *LinuxPlatform) GetCurrentProcessPath() string {
	path, err := os.Executable()
	if err != nil {
		return ""
	}
	return path
}

func (p *LinuxPlatform) SelfRestart(ctx context.Context) error {
	return p.RestartService(ctx, "phpborg-agent")
}
