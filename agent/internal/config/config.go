package config

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"time"

	"gopkg.in/yaml.v3"
)

// GetDefaultConfigPath returns the platform-specific default config path
func GetDefaultConfigPath() string {
	if runtime.GOOS == "windows" {
		programData := os.Getenv("ProgramData")
		if programData == "" {
			programData = "C:\\ProgramData"
		}
		return filepath.Join(programData, "phpborg-agent", "config.yaml")
	}
	return "/etc/phpborg-agent/config.yaml"
}

// GetDefaultLogPath returns the platform-specific default log path
func GetDefaultLogPath() string {
	if runtime.GOOS == "windows" {
		programData := os.Getenv("ProgramData")
		if programData == "" {
			programData = "C:\\ProgramData"
		}
		return filepath.Join(programData, "phpborg-agent", "logs", "agent.log")
	}
	return "/var/log/phpborg-agent/agent.log"
}

// GetDefaultDataDir returns the platform-specific default data directory
func GetDefaultDataDir() string {
	if runtime.GOOS == "windows" {
		programData := os.Getenv("ProgramData")
		if programData == "" {
			programData = "C:\\ProgramData"
		}
		return filepath.Join(programData, "phpborg-agent", "data")
	}
	return "/var/lib/phpborg-agent"
}

// GetDefaultTempDir returns the platform-specific temp directory
func GetDefaultTempDir() string {
	if runtime.GOOS == "windows" {
		temp := os.Getenv("TEMP")
		if temp == "" {
			temp = os.Getenv("TMP")
		}
		if temp == "" {
			temp = "C:\\Windows\\Temp"
		}
		return temp
	}
	return "/tmp"
}

// Config holds all agent configuration
type Config struct {
	// Server connection
	Server ServerConfig `yaml:"server"`

	// Agent identity
	Agent AgentConfig `yaml:"agent"`

	// Borg SSH configuration
	BorgSSH BorgSSHConfig `yaml:"borg_ssh"`

	// Polling intervals
	Polling PollingConfig `yaml:"polling"`

	// Logging
	Logging LoggingConfig `yaml:"logging"`

	// TLS/mTLS configuration
	TLS TLSConfig `yaml:"tls"`
}

// ServerConfig holds phpBorg server connection details
type ServerConfig struct {
	// API endpoint URL (e.g., https://phpborg.example.com/api)
	URL string `yaml:"url"`

	// Skip TLS verification (for development only)
	InsecureSkipVerify bool `yaml:"insecure_skip_verify"`
}

// AgentConfig holds agent identity information
type AgentConfig struct {
	// Unique agent identifier (UUID)
	UUID string `yaml:"uuid"`

	// Human-readable agent name
	Name string `yaml:"name"`

	// Maximum concurrent tasks
	MaxConcurrentTasks int `yaml:"max_concurrent_tasks"`

	// Agent version (set at runtime from main.go)
	Version string `yaml:"-"`
}

// BorgSSHConfig holds Borg SSH connection details
type BorgSSHConfig struct {
	// phpBorg server hostname for borg connections
	Host string `yaml:"host"`

	// SSH port for borg (default: 2222)
	Port int `yaml:"port"`

	// SSH user (default: phpborg-borg)
	User string `yaml:"user"`

	// Path to SSH private key
	PrivateKeyPath string `yaml:"private_key_path"`

	// Remote backup path on phpBorg server
	BackupPath string `yaml:"backup_path"`
}

// PollingConfig holds polling interval settings
type PollingConfig struct {
	// Task polling interval
	Interval time.Duration `yaml:"interval"`

	// Heartbeat interval
	HeartbeatInterval time.Duration `yaml:"heartbeat_interval"`
}

// LoggingConfig holds logging settings
type LoggingConfig struct {
	// Log level (debug, info, warn, error)
	Level string `yaml:"level"`

	// Log file path (empty = stdout)
	File string `yaml:"file"`
}

// TLSConfig holds mTLS certificate paths
type TLSConfig struct {
	// Path to client certificate
	CertFile string `yaml:"cert_file"`

	// Path to client private key
	KeyFile string `yaml:"key_file"`

	// Path to CA certificate
	CAFile string `yaml:"ca_file"`
}

// DefaultConfig returns a config with sensible defaults
func DefaultConfig() *Config {
	return &Config{
		Server: ServerConfig{
			InsecureSkipVerify: false,
		},
		Agent: AgentConfig{
			MaxConcurrentTasks: 2,
		},
		BorgSSH: BorgSSHConfig{
			Port: 2222,
			User: "phpborg-borg",
		},
		Polling: PollingConfig{
			Interval:          5 * time.Second,
			HeartbeatInterval: 60 * time.Second,
		},
		Logging: LoggingConfig{
			Level: "info",
		},
	}
}

// LoadFromFile loads configuration from a YAML file
func LoadFromFile(path string) (*Config, error) {
	config := DefaultConfig()

	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("failed to read config file: %w", err)
	}

	if err := yaml.Unmarshal(data, config); err != nil {
		return nil, fmt.Errorf("failed to parse config file: %w", err)
	}

	if err := config.Validate(); err != nil {
		return nil, fmt.Errorf("invalid configuration: %w", err)
	}

	return config, nil
}

// Validate checks if the configuration is valid
func (c *Config) Validate() error {
	if c.Server.URL == "" {
		return fmt.Errorf("server.url is required")
	}

	if c.Agent.UUID == "" {
		return fmt.Errorf("agent.uuid is required")
	}

	if c.Agent.Name == "" {
		return fmt.Errorf("agent.name is required")
	}

	// TLS is optional - if not configured, use Bearer token auth
	// Only validate TLS if any TLS field is set
	if c.TLS.CertFile != "" || c.TLS.KeyFile != "" || c.TLS.CAFile != "" {
		if c.TLS.CertFile == "" {
			return fmt.Errorf("tls.cert_file is required when using mTLS")
		}
		if c.TLS.KeyFile == "" {
			return fmt.Errorf("tls.key_file is required when using mTLS")
		}
		if c.TLS.CAFile == "" {
			return fmt.Errorf("tls.ca_file is required when using mTLS")
		}
	}

	return nil
}

// UseTLS returns true if mTLS is configured
func (c *Config) UseTLS() bool {
	return c.TLS.CertFile != "" && c.TLS.KeyFile != "" && c.TLS.CAFile != ""
}

// SaveToFile saves configuration to a YAML file
func (c *Config) SaveToFile(path string) error {
	data, err := yaml.Marshal(c)
	if err != nil {
		return fmt.Errorf("failed to marshal config: %w", err)
	}

	if err := os.WriteFile(path, data, 0600); err != nil {
		return fmt.Errorf("failed to write config file: %w", err)
	}

	return nil
}
