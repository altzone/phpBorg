package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/ncruces/zenity"
)

const (
	AppTitle        = "phpBorg Agent Installer"
	BorgVersion     = "1.2.7"
	BorgDownloadURL = "https://github.com/borgbackup/borg/releases/download/%s/borg-windows64.exe"
	InstallDir      = "C:\\Program Files\\phpborg-agent"
	ConfigDir       = "C:\\ProgramData\\phpborg-agent"
	BorgDir         = "C:\\Program Files\\Borg"
	ServiceName     = "phpborg-agent"
)

// PreConfig holds pre-configured installation settings (from install-config.json)
type PreConfig struct {
	ServerURL string `json:"server_url"`
	AgentName string `json:"agent_name"`
	Token     string `json:"token"`
	AutoStart bool   `json:"auto_start"`
}

func main() {
	// Check if running on Windows
	if runtime.GOOS != "windows" {
		fmt.Println("This installer is for Windows only.")
		os.Exit(1)
	}

	// Check admin privileges
	if !isAdmin() {
		zenity.Error("Administrator privileges required!\n\nPlease right-click the installer and select 'Run as administrator'.",
			zenity.Title(AppTitle),
			zenity.ErrorIcon)
		relaunchAsAdmin()
		return
	}

	// Try to load pre-configuration
	preConfig := loadPreConfig()

	// Get installation parameters
	serverURL, agentName, token, err := getInstallParams(preConfig)
	if err != nil {
		return // User cancelled
	}

	// Confirm installation
	if !confirmInstall(serverURL, agentName) {
		return
	}

	// Run installation with progress
	if err := runInstallation(serverURL, agentName, token); err != nil {
		zenity.Error(fmt.Sprintf("Installation failed!\n\n%v", err),
			zenity.Title(AppTitle),
			zenity.ErrorIcon)
		return
	}

	// Success!
	zenity.Info(fmt.Sprintf("phpBorg Agent installed successfully!\n\n"+
		"Agent Name: %s\n"+
		"Server: %s\n\n"+
		"The agent is now running as a Windows service.",
		agentName, serverURL),
		zenity.Title(AppTitle),
		zenity.InfoIcon)
}

func loadPreConfig() *PreConfig {
	exePath, _ := os.Executable()
	exeDir := filepath.Dir(exePath)

	configPaths := []string{
		filepath.Join(exeDir, "install-config.json"),
		"install-config.json",
	}

	for _, path := range configPaths {
		if data, err := os.ReadFile(path); err == nil {
			var config PreConfig
			if err := json.Unmarshal(data, &config); err == nil {
				return &config
			}
		}
	}
	return nil
}

func getInstallParams(preConfig *PreConfig) (serverURL, agentName, token string, err error) {
	hostname, _ := os.Hostname()

	// Server URL
	if preConfig != nil && preConfig.ServerURL != "" {
		serverURL = preConfig.ServerURL
	} else {
		serverURL, err = zenity.Entry("Enter phpBorg Server URL:",
			zenity.Title(AppTitle),
			zenity.EntryText("https://"),
			zenity.Width(400))
		if err != nil {
			return "", "", "", err
		}
		if serverURL == "" {
			zenity.Error("Server URL is required!", zenity.Title(AppTitle))
			return "", "", "", fmt.Errorf("cancelled")
		}
	}

	// Agent Name
	if preConfig != nil && preConfig.AgentName != "" {
		agentName = preConfig.AgentName
	} else {
		agentName, err = zenity.Entry("Enter a name for this agent:",
			zenity.Title(AppTitle),
			zenity.EntryText(hostname),
			zenity.Width(400))
		if err != nil {
			return "", "", "", err
		}
		if agentName == "" {
			agentName = hostname
		}
	}

	// Registration Token
	if preConfig != nil && preConfig.Token != "" {
		token = preConfig.Token
	} else {
		token, err = zenity.Entry("Enter Registration Token (from phpBorg server):",
			zenity.Title(AppTitle),
			zenity.Width(400))
		if err != nil {
			return "", "", "", err
		}
		if token == "" {
			zenity.Error("Registration token is required!", zenity.Title(AppTitle))
			return "", "", "", fmt.Errorf("cancelled")
		}
	}

	return serverURL, agentName, token, nil
}

func confirmInstall(serverURL, agentName string) bool {
	return zenity.Question(
		fmt.Sprintf("Ready to install phpBorg Agent\n\n"+
			"Server: %s\n"+
			"Agent Name: %s\n"+
			"Install Location: %s\n\n"+
			"Click OK to start installation.",
			serverURL, agentName, InstallDir),
		zenity.Title(AppTitle),
		zenity.OKLabel("Install"),
		zenity.CancelLabel("Cancel"),
		zenity.QuestionIcon,
	) == nil
}

func runInstallation(serverURL, agentName, token string) error {
	steps := []struct {
		name string
		fn   func() error
	}{
		{"Creating directories...", createDirectories},
		{"Downloading Borg Backup...", downloadBorg},
		{"Downloading phpBorg Agent...", func() error { return downloadAgent(serverURL, token) }},
		{"Registering with server...", func() error { return registerAgent(serverURL, agentName, token) }},
		{"Installing Windows Service...", installService},
		{"Starting service...", startService},
	}

	// Create progress dialog
	dlg, err := zenity.Progress(
		zenity.Title(AppTitle),
		zenity.MaxValue(len(steps)),
		zenity.NoCancel(),
	)
	if err != nil {
		// If progress dialog fails, run without visual progress
		for _, step := range steps {
			if err := step.fn(); err != nil {
				return fmt.Errorf("%s: %w", step.name, err)
			}
		}
		return nil
	}
	defer dlg.Close()

	for i, step := range steps {
		dlg.Text(step.name)
		dlg.Value(i)

		if err := step.fn(); err != nil {
			return fmt.Errorf("%s: %w", step.name, err)
		}
	}

	dlg.Value(len(steps))
	dlg.Text("Installation complete!")
	dlg.Complete()
	time.Sleep(500 * time.Millisecond)

	return nil
}

func createDirectories() error {
	dirs := []string{
		InstallDir,
		ConfigDir,
		filepath.Join(ConfigDir, "logs"),
		filepath.Join(ConfigDir, "certs"),
		filepath.Join(ConfigDir, "ssh"),
		BorgDir,
	}
	for _, dir := range dirs {
		if err := os.MkdirAll(dir, 0755); err != nil {
			return fmt.Errorf("failed to create %s: %w", dir, err)
		}
	}
	return nil
}

func downloadBorg() error {
	destPath := filepath.Join(BorgDir, "borg.exe")

	// Check if already exists
	if _, err := os.Stat(destPath); err == nil {
		return nil // Already installed
	}

	url := fmt.Sprintf(BorgDownloadURL, BorgVersion)

	resp, err := http.Get(url)
	if err != nil {
		return fmt.Errorf("download failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download failed: HTTP %d", resp.StatusCode)
	}

	out, err := os.Create(destPath)
	if err != nil {
		return fmt.Errorf("failed to create file: %w", err)
	}
	defer out.Close()

	_, err = io.Copy(out, resp.Body)
	if err != nil {
		return fmt.Errorf("failed to write file: %w", err)
	}

	// Add to PATH
	addToPath(BorgDir)
	return nil
}

func downloadAgent(serverURL, token string) error {
	url := strings.TrimSuffix(serverURL, "/") + "/api/agent/download/windows"
	destPath := filepath.Join(InstallDir, "phpborg-agent.exe")

	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("X-Registration-Token", token)

	client := &http.Client{Timeout: 5 * time.Minute}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("download failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusUnauthorized {
		return fmt.Errorf("invalid registration token")
	}
	if resp.StatusCode == http.StatusNotFound {
		return fmt.Errorf("agent binary not found on server")
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download failed: HTTP %d", resp.StatusCode)
	}

	out, err := os.Create(destPath)
	if err != nil {
		return fmt.Errorf("failed to create file: %w", err)
	}
	defer out.Close()

	_, err = io.Copy(out, resp.Body)
	return err
}

func registerAgent(serverURL, agentName, token string) error {
	url := strings.TrimSuffix(serverURL, "/") + "/api/agent/register-token"
	hostname, _ := os.Hostname()

	data := map[string]interface{}{
		"name":               agentName,
		"registration_token": token,
		"os":                 "Windows",
		"os_version":         getWindowsVersion(),
		"hostname":           hostname,
		"architecture":       runtime.GOARCH,
		"agent_version":      "2.3.0",
	}

	jsonData, err := json.Marshal(data)
	if err != nil {
		return err
	}

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Post(url, "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		return fmt.Errorf("registration failed: %w", err)
	}
	defer resp.Body.Close()

	var result map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return fmt.Errorf("invalid response: %w", err)
	}

	if success, ok := result["success"].(bool); !ok || !success {
		if errData, ok := result["error"].(map[string]interface{}); ok {
			return fmt.Errorf("%v", errData["message"])
		}
		return fmt.Errorf("registration failed")
	}

	// Extract and save configuration
	respData, ok := result["data"].(map[string]interface{})
	if !ok {
		return fmt.Errorf("invalid response format")
	}

	return saveConfiguration(serverURL, agentName, respData)
}

func saveConfiguration(serverURL, agentName string, data map[string]interface{}) error {
	// Create config file
	config := fmt.Sprintf(`# phpBorg Agent Configuration
# Generated by installer on %s

server:
  url: %s/api
  insecure_skip_verify: false

agent:
  uuid: %s
  name: %s
  max_concurrent_tasks: 2

borg_ssh:
  host: %s
  port: %.0f
  user: %s
  private_key_path: %s
  backup_path: %s

polling:
  interval: 5s
  heartbeat_interval: 60s

logging:
  level: info
  file: %s

tls:
  cert_file: %s
  key_file: %s
  ca_file: %s
`,
		time.Now().Format("2006-01-02 15:04:05"),
		strings.TrimSuffix(serverURL, "/"),
		data["uuid"],
		agentName,
		data["borg_host"],
		data["borg_port"],
		data["borg_user"],
		filepath.Join(ConfigDir, "ssh", "id_rsa"),
		data["backup_path"],
		filepath.Join(ConfigDir, "logs", "agent.log"),
		filepath.Join(ConfigDir, "certs", "agent.crt"),
		filepath.Join(ConfigDir, "certs", "agent.key"),
		filepath.Join(ConfigDir, "certs", "ca.crt"),
	)

	configPath := filepath.Join(ConfigDir, "config.yaml")
	if err := os.WriteFile(configPath, []byte(config), 0644); err != nil {
		return err
	}

	// Save SSH key
	if sshKey, ok := data["ssh_private_key"].(string); ok && sshKey != "" {
		sshPath := filepath.Join(ConfigDir, "ssh", "id_rsa")
		os.WriteFile(sshPath, []byte(sshKey), 0600)
	}

	// Save certificates
	certsDir := filepath.Join(ConfigDir, "certs")
	if cert, ok := data["tls_cert"].(string); ok && cert != "" {
		os.WriteFile(filepath.Join(certsDir, "agent.crt"), []byte(cert), 0644)
	}
	if key, ok := data["tls_key"].(string); ok && key != "" {
		os.WriteFile(filepath.Join(certsDir, "agent.key"), []byte(key), 0600)
	}
	if ca, ok := data["ca_cert"].(string); ok && ca != "" {
		os.WriteFile(filepath.Join(certsDir, "ca.crt"), []byte(ca), 0644)
	}

	return nil
}

func installService() error {
	agentPath := filepath.Join(InstallDir, "phpborg-agent.exe")

	// Stop and delete existing service
	exec.Command("sc.exe", "stop", ServiceName).Run()
	time.Sleep(2 * time.Second)
	exec.Command("sc.exe", "delete", ServiceName).Run()
	time.Sleep(2 * time.Second)

	// Create service
	cmd := exec.Command("sc.exe", "create", ServiceName,
		fmt.Sprintf("binPath=%s", agentPath),
		"DisplayName=phpBorg Backup Agent",
		"start=auto")

	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("sc create failed: %s", string(output))
	}

	// Set description
	exec.Command("sc.exe", "description", ServiceName,
		"phpBorg backup agent for Windows systems").Run()

	// Configure recovery (restart on failure)
	exec.Command("sc.exe", "failure", ServiceName,
		"reset=86400",
		"actions=restart/60000/restart/60000/restart/60000").Run()

	return nil
}

func startService() error {
	cmd := exec.Command("sc.exe", "start", ServiceName)
	output, err := cmd.CombinedOutput()
	if err != nil && !strings.Contains(string(output), "RUNNING") {
		return fmt.Errorf("failed to start: %s", string(output))
	}
	return nil
}

func addToPath(dir string) {
	exec.Command("setx", "/M", "PATH", fmt.Sprintf("%%PATH%%;%s", dir)).Run()
}

func getWindowsVersion() string {
	cmd := exec.Command("cmd", "/c", "ver")
	output, _ := cmd.Output()
	return strings.TrimSpace(string(output))
}

func isAdmin() bool {
	_, err := os.Open("\\\\.\\PHYSICALDRIVE0")
	return err == nil
}

func relaunchAsAdmin() {
	exe, _ := os.Executable()
	exec.Command("powershell", "-Command",
		fmt.Sprintf("Start-Process -FilePath '%s' -Verb RunAs", exe)).Start()
}
