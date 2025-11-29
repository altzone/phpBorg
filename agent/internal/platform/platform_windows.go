//go:build windows
// +build windows

package platform

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"golang.org/x/sys/windows/registry"
)

func init() {
	Current = &WindowsPlatform{}
}

// WindowsPlatform implements Platform for Windows systems
type WindowsPlatform struct{}

func (p *WindowsPlatform) GetName() string {
	return "windows"
}

// Shell execution

func (p *WindowsPlatform) RunShell(ctx context.Context, command string, timeout time.Duration) *CommandResult {
	// Use PowerShell for shell commands on Windows
	return p.RunCommand(ctx, "powershell.exe", []string{"-NoProfile", "-NonInteractive", "-Command", command}, timeout)
}

func (p *WindowsPlatform) RunCommand(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
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

func (p *WindowsPlatform) RunAsAdmin(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult {
	// On Windows, we assume the agent runs with admin privileges as a service
	// If elevation is needed, use runas or PowerShell Start-Process -Verb RunAs
	return p.RunCommand(ctx, command, args, timeout)
}

func (p *WindowsPlatform) RunAsUser(ctx context.Context, username string, command string, timeout time.Duration) *CommandResult {
	// On Windows, we typically don't switch users for database operations
	// Instead, we use SQL authentication or Windows authentication
	// For now, just run the command directly
	return p.RunShell(ctx, command, timeout)
}

// Path utilities

func (p *WindowsPlatform) GetConfigDir() string {
	programData := os.Getenv("ProgramData")
	if programData == "" {
		programData = "C:\\ProgramData"
	}
	return filepath.Join(programData, "phpborg-agent")
}

func (p *WindowsPlatform) GetDataDir() string {
	return filepath.Join(p.GetConfigDir(), "data")
}

func (p *WindowsPlatform) GetLogDir() string {
	return filepath.Join(p.GetConfigDir(), "logs")
}

func (p *WindowsPlatform) GetTempDir() string {
	temp := os.Getenv("TEMP")
	if temp == "" {
		temp = os.Getenv("TMP")
	}
	if temp == "" {
		temp = "C:\\Windows\\Temp"
	}
	return temp
}

func (p *WindowsPlatform) GetHomeDir(username string) string {
	if username == "" {
		if home := os.Getenv("USERPROFILE"); home != "" {
			return home
		}
		return "C:\\Users\\Default"
	}
	return filepath.Join("C:\\Users", username)
}

func (p *WindowsPlatform) NormalizePath(path string) string {
	// Convert forward slashes to backslashes
	path = strings.ReplaceAll(path, "/", "\\")
	return filepath.Clean(path)
}

func (p *WindowsPlatform) PathExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

func (p *WindowsPlatform) IsAbsolutePath(path string) bool {
	return filepath.IsAbs(path)
}

// Service management using sc.exe and PowerShell

func (p *WindowsPlatform) GetServiceStatus(ctx context.Context, serviceName string) *ServiceStatus {
	status := &ServiceStatus{Name: serviceName}

	// Use sc.exe to query service
	result := p.RunCommand(ctx, "sc.exe", []string{"query", serviceName}, 10*time.Second)
	if result.ExitCode != 0 {
		// Service doesn't exist
		status.Exists = false
		return status
	}

	status.Exists = true

	// Parse the output to determine if running
	if strings.Contains(result.Stdout, "RUNNING") {
		status.Running = true
	}

	// Get start type
	result = p.RunCommand(ctx, "sc.exe", []string{"qc", serviceName}, 10*time.Second)
	if result.ExitCode == 0 {
		if strings.Contains(result.Stdout, "AUTO_START") {
			status.StartType = "automatic"
		} else if strings.Contains(result.Stdout, "DEMAND_START") {
			status.StartType = "manual"
		} else if strings.Contains(result.Stdout, "DISABLED") {
			status.StartType = "disabled"
		}
	}

	return status
}

func (p *WindowsPlatform) StartService(ctx context.Context, serviceName string) error {
	result := p.RunCommand(ctx, "sc.exe", []string{"start", serviceName}, 60*time.Second)
	if result.ExitCode != 0 && !strings.Contains(result.Stdout, "RUNNING") {
		return fmt.Errorf("failed to start service: %s", result.Stderr)
	}
	return nil
}

func (p *WindowsPlatform) StopService(ctx context.Context, serviceName string) error {
	result := p.RunCommand(ctx, "sc.exe", []string{"stop", serviceName}, 60*time.Second)
	if result.ExitCode != 0 && !strings.Contains(result.Stdout, "STOPPED") {
		return fmt.Errorf("failed to stop service: %s", result.Stderr)
	}
	return nil
}

func (p *WindowsPlatform) RestartService(ctx context.Context, serviceName string) error {
	if err := p.StopService(ctx, serviceName); err != nil {
		// Ignore stop errors, service might not be running
	}
	time.Sleep(2 * time.Second)
	return p.StartService(ctx, serviceName)
}

func (p *WindowsPlatform) InstallService(ctx context.Context, name, displayName, description, execPath string) error {
	// Create service using sc.exe
	result := p.RunCommand(ctx, "sc.exe", []string{
		"create", name,
		"binPath=", execPath,
		"DisplayName=", displayName,
		"start=", "auto",
	}, 30*time.Second)

	if result.ExitCode != 0 {
		return fmt.Errorf("failed to create service: %s", result.Stderr)
	}

	// Set description
	p.RunCommand(ctx, "sc.exe", []string{
		"description", name, description,
	}, 10*time.Second)

	// Configure recovery options (restart on failure)
	p.RunCommand(ctx, "sc.exe", []string{
		"failure", name,
		"reset=", "86400",
		"actions=", "restart/60000/restart/60000/restart/60000",
	}, 10*time.Second)

	return nil
}

func (p *WindowsPlatform) UninstallService(ctx context.Context, name string) error {
	// Stop the service first
	p.StopService(ctx, name)
	time.Sleep(2 * time.Second)

	// Delete the service
	result := p.RunCommand(ctx, "sc.exe", []string{"delete", name}, 30*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to delete service: %s", result.Stderr)
	}

	return nil
}

// System information

func (p *WindowsPlatform) GetSystemInfo(ctx context.Context) *SystemInfo {
	info := &SystemInfo{}

	// OS info from registry
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows NT\CurrentVersion`, registry.QUERY_VALUE)
	if err == nil {
		defer k.Close()
		productName, _, _ := k.GetStringValue("ProductName")
		info.OS = productName

		// Try to get version
		currentBuild, _, _ := k.GetStringValue("CurrentBuild")
		displayVersion, _, _ := k.GetStringValue("DisplayVersion")
		if displayVersion != "" {
			info.OSVersion = displayVersion + " (Build " + currentBuild + ")"
		} else {
			info.OSVersion = "Build " + currentBuild
		}
	}

	// Architecture
	info.Architecture = os.Getenv("PROCESSOR_ARCHITECTURE")

	// Hostname
	if hostname, err := os.Hostname(); err == nil {
		info.Hostname = hostname
	}

	// CPU info using WMIC
	result := p.RunCommand(ctx, "wmic", []string{"cpu", "get", "NumberOfCores", "/value"}, 10*time.Second)
	if result.ExitCode == 0 {
		re := regexp.MustCompile(`NumberOfCores=(\d+)`)
		matches := re.FindStringSubmatch(result.Stdout)
		if len(matches) > 1 {
			info.CPUCores, _ = strconv.Atoi(matches[1])
		}
	}

	result = p.RunCommand(ctx, "wmic", []string{"cpu", "get", "Name", "/value"}, 10*time.Second)
	if result.ExitCode == 0 {
		re := regexp.MustCompile(`Name=(.+)`)
		matches := re.FindStringSubmatch(result.Stdout)
		if len(matches) > 1 {
			info.CPUModel = strings.TrimSpace(matches[1])
		}
	}

	// Memory using WMIC
	result = p.RunCommand(ctx, "wmic", []string{"OS", "get", "TotalVisibleMemorySize", "/value"}, 10*time.Second)
	if result.ExitCode == 0 {
		re := regexp.MustCompile(`TotalVisibleMemorySize=(\d+)`)
		matches := re.FindStringSubmatch(result.Stdout)
		if len(matches) > 1 {
			kb, _ := strconv.ParseInt(matches[1], 10, 64)
			info.TotalMemoryMB = kb / 1024
		}
	}

	result = p.RunCommand(ctx, "wmic", []string{"OS", "get", "FreePhysicalMemory", "/value"}, 10*time.Second)
	if result.ExitCode == 0 {
		re := regexp.MustCompile(`FreePhysicalMemory=(\d+)`)
		matches := re.FindStringSubmatch(result.Stdout)
		if len(matches) > 1 {
			kb, _ := strconv.ParseInt(matches[1], 10, 64)
			info.FreeMemoryMB = kb / 1024
		}
	}

	// Uptime using PowerShell
	result = p.RunShell(ctx, "(Get-CimInstance Win32_OperatingSystem).LastBootUpTime", 10*time.Second)
	if result.ExitCode == 0 {
		// Parse the boot time and calculate uptime
		// This is simplified - actual implementation would parse the datetime
	}

	// IP addresses using PowerShell
	result = p.RunShell(ctx, "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notlike '*Loopback*' }).IPAddress -join ' '", 10*time.Second)
	if result.ExitCode == 0 {
		info.IPAddresses = strings.Fields(strings.TrimSpace(result.Stdout))
	}

	return info
}

func (p *WindowsPlatform) GetDiskInfo(ctx context.Context, path string) *DiskInfo {
	// Extract drive letter
	driveLetter := ""
	if len(path) >= 2 && path[1] == ':' {
		driveLetter = string(path[0]) + ":"
	} else {
		driveLetter = "C:"
	}

	result := p.RunCommand(ctx, "wmic", []string{
		"logicaldisk", "where", fmt.Sprintf("DeviceID='%s'", driveLetter),
		"get", "Size,FreeSpace,FileSystem", "/value",
	}, 10*time.Second)

	if result.ExitCode != 0 {
		return nil
	}

	info := &DiskInfo{
		Device: driveLetter,
		Path:   driveLetter + "\\",
	}

	// Parse output
	reFS := regexp.MustCompile(`FileSystem=(\w+)`)
	if matches := reFS.FindStringSubmatch(result.Stdout); len(matches) > 1 {
		info.Filesystem = matches[1]
	}

	reSize := regexp.MustCompile(`Size=(\d+)`)
	if matches := reSize.FindStringSubmatch(result.Stdout); len(matches) > 1 {
		bytes, _ := strconv.ParseFloat(matches[1], 64)
		info.TotalGB = bytes / (1024 * 1024 * 1024)
	}

	reFree := regexp.MustCompile(`FreeSpace=(\d+)`)
	if matches := reFree.FindStringSubmatch(result.Stdout); len(matches) > 1 {
		bytes, _ := strconv.ParseFloat(matches[1], 64)
		info.FreeGB = bytes / (1024 * 1024 * 1024)
	}

	info.UsedGB = info.TotalGB - info.FreeGB
	if info.TotalGB > 0 {
		info.UsedPercent = (info.UsedGB / info.TotalGB) * 100
	}

	return info
}

func (p *WindowsPlatform) GetAllDisks(ctx context.Context) []*DiskInfo {
	result := p.RunCommand(ctx, "wmic", []string{
		"logicaldisk", "where", "DriveType=3",
		"get", "DeviceID,Size,FreeSpace,FileSystem", "/value",
	}, 30*time.Second)

	if result.ExitCode != 0 {
		return nil
	}

	var disks []*DiskInfo

	// Split by double newlines (each disk entry)
	entries := strings.Split(result.Stdout, "\r\n\r\n")
	for _, entry := range entries {
		if strings.TrimSpace(entry) == "" {
			continue
		}

		disk := &DiskInfo{}

		reID := regexp.MustCompile(`DeviceID=(\w:)`)
		if matches := reID.FindStringSubmatch(entry); len(matches) > 1 {
			disk.Device = matches[1]
			disk.Path = matches[1] + "\\"
		}

		reFS := regexp.MustCompile(`FileSystem=(\w+)`)
		if matches := reFS.FindStringSubmatch(entry); len(matches) > 1 {
			disk.Filesystem = matches[1]
		}

		reSize := regexp.MustCompile(`Size=(\d+)`)
		if matches := reSize.FindStringSubmatch(entry); len(matches) > 1 {
			bytes, _ := strconv.ParseFloat(matches[1], 64)
			disk.TotalGB = bytes / (1024 * 1024 * 1024)
		}

		reFree := regexp.MustCompile(`FreeSpace=(\d+)`)
		if matches := reFree.FindStringSubmatch(entry); len(matches) > 1 {
			bytes, _ := strconv.ParseFloat(matches[1], 64)
			disk.FreeGB = bytes / (1024 * 1024 * 1024)
		}

		if disk.Device != "" && disk.TotalGB > 0 {
			disk.UsedGB = disk.TotalGB - disk.FreeGB
			disk.UsedPercent = (disk.UsedGB / disk.TotalGB) * 100
			disks = append(disks, disk)
		}
	}

	return disks
}

// Database detection

func (p *WindowsPlatform) DetectMySQL(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "mysql"}

	// Check for MySQL service (various names)
	serviceNames := []string{"MySQL", "MySQL80", "MySQL57", "MariaDB"}
	for _, svc := range serviceNames {
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

	// Default Windows paths
	programData := os.Getenv("ProgramData")
	if programData == "" {
		programData = "C:\\ProgramData"
	}

	// Check common config locations
	configPaths := []string{
		filepath.Join(programData, "MySQL", "MySQL Server 8.0", "my.ini"),
		filepath.Join(programData, "MySQL", "MySQL Server 5.7", "my.ini"),
		"C:\\MySQL\\my.ini",
	}
	for _, cfg := range configPaths {
		if p.PathExists(cfg) {
			info.ConfigFile = cfg
			break
		}
	}

	// Default settings
	info.Port = 3306
	info.Host = "localhost"

	return info
}

func (p *WindowsPlatform) DetectPostgreSQL(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "postgresql"}

	// Check for PostgreSQL service
	serviceNames := []string{"postgresql-x64-16", "postgresql-x64-15", "postgresql-x64-14", "postgresql-x64-13", "postgresql"}
	for _, svc := range serviceNames {
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
	result := p.RunCommand(ctx, "psql", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		info.Version = strings.TrimSpace(result.Stdout)
	}

	// Default Windows paths
	programData := os.Getenv("ProgramData")
	info.DataDir = filepath.Join(programData, "PostgreSQL", "data")

	// Default settings
	info.Port = 5432
	info.Host = "localhost"

	return info
}

func (p *WindowsPlatform) DetectMongoDB(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "mongodb"}

	// Check for MongoDB service
	status := p.GetServiceStatus(ctx, "MongoDB")
	if !status.Running {
		return nil
	}
	info.Running = true

	// Get version
	result := p.RunCommand(ctx, "mongod", []string{"--version"}, 5*time.Second)
	if result.ExitCode == 0 {
		lines := strings.Split(result.Stdout, "\n")
		if len(lines) > 0 {
			info.Version = strings.TrimSpace(lines[0])
		}
	}

	// Default Windows paths
	programData := os.Getenv("ProgramData")
	info.DataDir = filepath.Join(programData, "MongoDB", "data")
	info.ConfigFile = filepath.Join(programData, "MongoDB", "mongod.cfg")

	// Default settings
	info.Port = 27017
	info.Host = "localhost"

	return info
}

func (p *WindowsPlatform) DetectRedis(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "redis"}

	// Check for Redis service
	status := p.GetServiceStatus(ctx, "Redis")
	if !status.Running {
		return nil
	}
	info.Running = true

	// Default settings
	info.Port = 6379
	info.Host = "localhost"

	return info
}

func (p *WindowsPlatform) DetectMSSQL(ctx context.Context) *DatabaseInfo {
	info := &DatabaseInfo{Type: "mssql"}

	// Check for SQL Server service
	serviceNames := []string{"MSSQLSERVER", "MSSQL$SQLEXPRESS", "MSSQL$MSSQLSERVER"}
	for _, svc := range serviceNames {
		status := p.GetServiceStatus(ctx, svc)
		if status.Running {
			info.Running = true
			break
		}
	}

	if !info.Running {
		return nil
	}

	// Get version using sqlcmd
	result := p.RunCommand(ctx, "sqlcmd", []string{"-Q", "SELECT @@VERSION", "-h", "-1"}, 10*time.Second)
	if result.ExitCode == 0 {
		info.Version = strings.TrimSpace(result.Stdout)
	}

	// Default settings
	info.Port = 1433
	info.Host = "localhost"

	return info
}

// Docker detection

func (p *WindowsPlatform) IsDockerAvailable(ctx context.Context) bool {
	// Check if docker command exists
	result := p.RunCommand(ctx, "where", []string{"docker"}, 5*time.Second)
	if result.ExitCode != 0 {
		return false
	}

	// Check if Docker Desktop service is running
	status := p.GetServiceStatus(ctx, "com.docker.service")
	return status.Running
}

func (p *WindowsPlatform) GetDockerInfo(ctx context.Context) map[string]interface{} {
	if !p.IsDockerAvailable(ctx) {
		return nil
	}

	result := p.RunCommand(ctx, "docker", []string{"info", "--format", "{{json .}}"}, 30*time.Second)
	if result.ExitCode != 0 {
		return nil
	}

	return map[string]interface{}{
		"raw": result.Stdout,
	}
}

// Snapshot capabilities - Windows uses VSS (Volume Shadow Copy Service)

func (p *WindowsPlatform) HasSnapshotCapability(ctx context.Context) bool {
	// VSS is always available on Windows (since Vista/Server 2003)
	result := p.RunCommand(ctx, "vssadmin", []string{"list", "providers"}, 10*time.Second)
	return result.ExitCode == 0
}

func (p *WindowsPlatform) CreateSnapshot(ctx context.Context, sourcePath, snapshotName string) (string, error) {
	// Extract drive letter
	driveLetter := ""
	if len(sourcePath) >= 2 && sourcePath[1] == ':' {
		driveLetter = string(sourcePath[0]) + ":"
	} else {
		return "", fmt.Errorf("invalid path: must include drive letter")
	}

	// Create VSS shadow copy using PowerShell
	script := fmt.Sprintf(`
$shadow = (Get-WmiObject -List Win32_ShadowCopy).Create("%s\", "ClientAccessible")
$shadowID = $shadow.ShadowID
$shadowPath = (Get-WmiObject Win32_ShadowCopy | Where-Object { $_.ID -eq $shadowID }).DeviceObject
Write-Output $shadowPath
`, driveLetter)

	result := p.RunShell(ctx, script, 120*time.Second)
	if result.ExitCode != 0 {
		return "", fmt.Errorf("failed to create VSS snapshot: %s", result.Stderr)
	}

	snapshotPath := strings.TrimSpace(result.Stdout)
	return snapshotPath, nil
}

func (p *WindowsPlatform) DeleteSnapshot(ctx context.Context, snapshotPath string) error {
	// Delete VSS shadow copy
	script := fmt.Sprintf(`
$shadow = Get-WmiObject Win32_ShadowCopy | Where-Object { $_.DeviceObject -eq "%s" }
if ($shadow) { $shadow.Delete() }
`, snapshotPath)

	result := p.RunShell(ctx, script, 60*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to delete VSS snapshot: %s", result.Stderr)
	}

	return nil
}

// SSH utilities

func (p *WindowsPlatform) GetDefaultSSHKeyPath() string {
	home := p.GetHomeDir("")
	return filepath.Join(home, ".ssh", "id_rsa")
}

func (p *WindowsPlatform) GenerateSSHKey(ctx context.Context, keyPath string) error {
	// Create .ssh directory if needed
	sshDir := filepath.Dir(keyPath)
	if err := os.MkdirAll(sshDir, 0700); err != nil {
		return fmt.Errorf("failed to create .ssh directory: %w", err)
	}

	// Check if OpenSSH is available (Windows 10 1809+ has it built-in)
	result := p.RunCommand(ctx, "where", []string{"ssh-keygen"}, 5*time.Second)
	if result.ExitCode == 0 {
		// Use OpenSSH ssh-keygen
		result = p.RunCommand(ctx, "ssh-keygen", []string{
			"-t", "ed25519",
			"-f", keyPath,
			"-N", "\"\"", // No passphrase
			"-C", "phpborg-agent",
		}, 30*time.Second)

		if result.ExitCode == 0 {
			return nil
		}
	}

	// Fallback: generate key using PowerShell/.NET (simplified RSA key)
	// This is a fallback for systems without OpenSSH
	script := fmt.Sprintf(`
$keyPath = "%s"
$sshDir = Split-Path -Parent $keyPath

# Try to use ssh-keygen if available
$sshKeygen = Get-Command ssh-keygen -ErrorAction SilentlyContinue
if ($sshKeygen) {
    & ssh-keygen -t ed25519 -f $keyPath -N '""' -C 'phpborg-agent'
    exit $LASTEXITCODE
}

Write-Error "ssh-keygen not found. Please install OpenSSH client."
exit 1
`, keyPath)

	result = p.RunShell(ctx, script, 60*time.Second)
	if result.ExitCode != 0 {
		return fmt.Errorf("failed to generate SSH key: %s", result.Stderr)
	}

	return nil
}

// Process management

func (p *WindowsPlatform) GetCurrentProcessPath() string {
	path, err := os.Executable()
	if err != nil {
		return ""
	}
	return path
}

func (p *WindowsPlatform) SelfRestart(ctx context.Context) error {
	return p.RestartService(ctx, "phpborg-agent")
}
