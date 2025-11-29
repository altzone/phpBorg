// Package platform provides OS-specific implementations for the phpBorg agent.
// This abstraction layer allows the agent to run on both Linux and Windows.
package platform

import (
	"context"
	"time"
)

// CommandResult holds the result of a command execution
type CommandResult struct {
	Stdout   string
	Stderr   string
	ExitCode int
	Error    error
}

// ServiceStatus represents the status of a system service
type ServiceStatus struct {
	Name      string
	Running   bool
	Exists    bool
	StartType string // "automatic", "manual", "disabled"
}

// SystemInfo contains OS and hardware information
type SystemInfo struct {
	OS           string
	OSVersion    string
	Architecture string
	Hostname     string
	CPUCores     int
	CPUModel     string
	TotalMemoryMB int64
	FreeMemoryMB  int64
	Uptime       time.Duration
	IPAddresses  []string
}

// DiskInfo contains information about a disk/volume
type DiskInfo struct {
	Path       string
	Device     string
	Filesystem string
	TotalGB    float64
	UsedGB     float64
	FreeGB     float64
	UsedPercent float64
}

// DatabaseInfo contains detected database information
type DatabaseInfo struct {
	Type       string // mysql, postgresql, mongodb, redis, mssql
	Version    string
	Running    bool
	DataDir    string
	ConfigFile string
	Port       int
	Host       string
	SocketPath string // Unix socket (Linux) or named pipe (Windows)
}

// Platform defines the interface for OS-specific operations
type Platform interface {
	// GetName returns the platform name ("linux" or "windows")
	GetName() string

	// Shell execution
	RunShell(ctx context.Context, command string, timeout time.Duration) *CommandResult
	RunCommand(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult
	RunAsAdmin(ctx context.Context, command string, args []string, timeout time.Duration) *CommandResult
	RunAsUser(ctx context.Context, user string, command string, timeout time.Duration) *CommandResult

	// Path utilities
	GetConfigDir() string
	GetDataDir() string
	GetLogDir() string
	GetTempDir() string
	GetHomeDir(username string) string
	NormalizePath(path string) string
	PathExists(path string) bool
	IsAbsolutePath(path string) bool

	// Service management
	GetServiceStatus(ctx context.Context, serviceName string) *ServiceStatus
	StartService(ctx context.Context, serviceName string) error
	StopService(ctx context.Context, serviceName string) error
	RestartService(ctx context.Context, serviceName string) error
	InstallService(ctx context.Context, name, displayName, description, execPath string) error
	UninstallService(ctx context.Context, name string) error

	// System information
	GetSystemInfo(ctx context.Context) *SystemInfo
	GetDiskInfo(ctx context.Context, path string) *DiskInfo
	GetAllDisks(ctx context.Context) []*DiskInfo

	// Database detection
	DetectMySQL(ctx context.Context) *DatabaseInfo
	DetectPostgreSQL(ctx context.Context) *DatabaseInfo
	DetectMongoDB(ctx context.Context) *DatabaseInfo
	DetectRedis(ctx context.Context) *DatabaseInfo
	DetectMSSQL(ctx context.Context) *DatabaseInfo // Windows-specific

	// Docker detection
	IsDockerAvailable(ctx context.Context) bool
	GetDockerInfo(ctx context.Context) map[string]interface{}

	// Snapshot capabilities (LVM on Linux, VSS on Windows)
	HasSnapshotCapability(ctx context.Context) bool
	CreateSnapshot(ctx context.Context, sourcePath, snapshotName string) (string, error)
	DeleteSnapshot(ctx context.Context, snapshotPath string) error

	// SSH utilities
	GetDefaultSSHKeyPath() string
	GenerateSSHKey(ctx context.Context, keyPath string) error

	// Process management
	GetCurrentProcessPath() string
	SelfRestart(ctx context.Context) error
}

// Current holds the platform-specific implementation
// It is set during package initialization based on the runtime OS
var Current Platform

// Helper function to check if we're on Windows
func IsWindows() bool {
	return Current.GetName() == "windows"
}

// Helper function to check if we're on Linux
func IsLinux() bool {
	return Current.GetName() == "linux"
}
