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
	AppTitle    = "phpBorg Agent Installer"
	ServiceName = "phpborg-agent"
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
		"The agent is running inside WSL (Ubuntu).\n"+
		"It can backup Windows files via /mnt/c/, /mnt/d/, etc.",
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
		fmt.Sprintf("Ready to install phpBorg Agent via WSL\n\n"+
			"Server: %s\n"+
			"Agent Name: %s\n\n"+
			"This will:\n"+
			"1. Enable WSL if not already enabled\n"+
			"2. Install Ubuntu in WSL\n"+
			"3. Install Borg Backup and phpBorg Agent\n\n"+
			"Windows files will be accessible via /mnt/c/, /mnt/d/, etc.\n\n"+
			"Click OK to start installation.",
			serverURL, agentName),
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
		{"Checking WSL status...", checkWSL},
		{"Installing Ubuntu in WSL...", installUbuntu},
		{"Installing Borg Backup...", installBorgInWSL},
		{"Downloading phpBorg Agent...", func() error { return downloadAgentInWSL(serverURL) }},
		{"Registering with server...", func() error { return registerAgentInWSL(serverURL, agentName, token) }},
		{"Starting agent service...", startAgentInWSL},
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

// checkWSL checks if WSL is available and enables it if needed
func checkWSL() error {
	// Check if wsl command exists
	cmd := exec.Command("wsl", "--status")
	if err := cmd.Run(); err != nil {
		// WSL not installed, try to enable it
		fmt.Println("WSL not found, enabling...")

		// Enable WSL feature
		enableCmd := exec.Command("powershell", "-Command",
			"Enable-WindowsOptionalFeature -Online -FeatureName Microsoft-Windows-Subsystem-Linux -NoRestart")
		if output, err := enableCmd.CombinedOutput(); err != nil {
			return fmt.Errorf("failed to enable WSL: %s", string(output))
		}

		// Enable Virtual Machine Platform
		vmCmd := exec.Command("powershell", "-Command",
			"Enable-WindowsOptionalFeature -Online -FeatureName VirtualMachinePlatform -NoRestart")
		vmCmd.Run() // Ignore error, might not be needed

		// Set WSL 2 as default
		exec.Command("wsl", "--set-default-version", "2").Run()
	}
	return nil
}

// installUbuntu installs Ubuntu in WSL if not present
func installUbuntu() error {
	// Check if Ubuntu is already installed
	cmd := exec.Command("wsl", "-l", "-q")
	output, _ := cmd.Output()
	if strings.Contains(strings.ToLower(string(output)), "ubuntu") {
		fmt.Println("Ubuntu already installed in WSL")
		return nil
	}

	// Install Ubuntu
	installCmd := exec.Command("wsl", "--install", "-d", "Ubuntu", "--no-launch")
	if output, err := installCmd.CombinedOutput(); err != nil {
		// Try alternative method
		altCmd := exec.Command("powershell", "-Command",
			"Invoke-WebRequest -Uri https://aka.ms/wslubuntu2204 -OutFile ubuntu.appx -UseBasicParsing; Add-AppxPackage .\\ubuntu.appx")
		if altOutput, altErr := altCmd.CombinedOutput(); altErr != nil {
			return fmt.Errorf("failed to install Ubuntu: %s / %s", string(output), string(altOutput))
		}
	}

	// Initialize Ubuntu (creates default user)
	// We'll use root for the agent
	time.Sleep(5 * time.Second)

	return nil
}

// installBorgInWSL installs Borg Backup inside WSL
func installBorgInWSL() error {
	script := `
apt-get update -qq
apt-get install -y borgbackup curl
`
	return runInWSL(script)
}

// downloadAgentInWSL downloads the phpBorg agent inside WSL
func downloadAgentInWSL(serverURL string) error {
	serverURL = strings.TrimSuffix(serverURL, "/")

	script := fmt.Sprintf(`
mkdir -p /opt/phpborg-agent /etc/phpborg-agent/certs /etc/phpborg-agent/ssh /var/log/phpborg-agent

# Download agent binary
curl -sSL "%s/api/downloads/phpborg-agent" -o /opt/phpborg-agent/phpborg-agent
chmod +x /opt/phpborg-agent/phpborg-agent

# Create symlink
ln -sf /opt/phpborg-agent/phpborg-agent /usr/local/bin/phpborg-agent
`, serverURL)

	return runInWSL(script)
}

// registerAgentInWSL registers the agent with the server
func registerAgentInWSL(serverURL, agentName, token string) error {
	serverURL = strings.TrimSuffix(serverURL, "/")
	hostname, _ := os.Hostname()

	// Get Windows version
	winVer := getWindowsVersion()

	// Create registration payload
	payload := map[string]interface{}{
		"name":               agentName,
		"registration_token": token,
		"os":                 "Windows (WSL)",
		"os_version":         winVer,
		"hostname":           hostname,
		"architecture":       runtime.GOARCH,
		"agent_version":      "2.3.3",
	}

	jsonData, _ := json.Marshal(payload)

	// Register via HTTP
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Post(serverURL+"/api/agent/register-token", "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		return fmt.Errorf("registration failed: %w", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	var result map[string]interface{}
	if err := json.Unmarshal(body, &result); err != nil {
		return fmt.Errorf("invalid response: %w", err)
	}

	if success, ok := result["success"].(bool); !ok || !success {
		if errData, ok := result["error"].(map[string]interface{}); ok {
			return fmt.Errorf("%v", errData["message"])
		}
		return fmt.Errorf("registration failed: %s", string(body))
	}

	// Extract configuration data
	respData, ok := result["data"].(map[string]interface{})
	if !ok {
		return fmt.Errorf("invalid response format")
	}

	// Create configuration file in WSL
	return createConfigInWSL(serverURL, agentName, respData)
}

// createConfigInWSL creates the agent configuration inside WSL
func createConfigInWSL(serverURL, agentName string, data map[string]interface{}) error {
	uuid := fmt.Sprintf("%v", data["uuid"])
	borgHost := fmt.Sprintf("%v", data["borg_host"])
	borgPort := fmt.Sprintf("%.0f", data["borg_port"])
	borgUser := fmt.Sprintf("%v", data["borg_user"])
	backupPath := fmt.Sprintf("%v", data["backup_path"])

	// Create config YAML
	config := fmt.Sprintf(`server:
  url: %s/api
  insecure_skip_verify: false

agent:
  uuid: %s
  name: %s
  max_concurrent_tasks: 2

borg_ssh:
  host: %s
  port: %s
  user: %s
  private_key_path: /etc/phpborg-agent/ssh/id_rsa
  backup_path: %s

polling:
  interval: 5s
  heartbeat_interval: 60s

logging:
  level: info
  file: /var/log/phpborg-agent/agent.log

tls:
  cert_file: /etc/phpborg-agent/certs/agent.crt
  key_file: /etc/phpborg-agent/certs/agent.key
  ca_file: /etc/phpborg-agent/certs/ca.crt
`, strings.TrimSuffix(serverURL, "/"), uuid, agentName, borgHost, borgPort, borgUser, backupPath)

	// Write config
	configScript := fmt.Sprintf(`cat > /etc/phpborg-agent/config.yaml << 'EOFCONFIG'
%s
EOFCONFIG
`, config)
	if err := runInWSL(configScript); err != nil {
		return err
	}

	// Write SSH key
	if sshKey, ok := data["ssh_private_key"].(string); ok && sshKey != "" {
		sshScript := fmt.Sprintf(`cat > /etc/phpborg-agent/ssh/id_rsa << 'EOFSSH'
%s
EOFSSH
chmod 600 /etc/phpborg-agent/ssh/id_rsa
`, sshKey)
		if err := runInWSL(sshScript); err != nil {
			return err
		}
	}

	// Write certificates
	if cert, ok := data["tls_cert"].(string); ok && cert != "" {
		certScript := fmt.Sprintf(`cat > /etc/phpborg-agent/certs/agent.crt << 'EOFCERT'
%s
EOFCERT
`, cert)
		runInWSL(certScript)
	}

	if key, ok := data["tls_key"].(string); ok && key != "" {
		keyScript := fmt.Sprintf(`cat > /etc/phpborg-agent/certs/agent.key << 'EOFKEY'
%s
EOFKEY
chmod 600 /etc/phpborg-agent/certs/agent.key
`, key)
		runInWSL(keyScript)
	}

	if ca, ok := data["ca_cert"].(string); ok && ca != "" {
		caScript := fmt.Sprintf(`cat > /etc/phpborg-agent/certs/ca.crt << 'EOFCA'
%s
EOFCA
`, ca)
		runInWSL(caScript)
	}

	return nil
}

// startAgentInWSL starts the agent service inside WSL
func startAgentInWSL() error {
	// Create systemd service file
	serviceScript := `
cat > /etc/systemd/system/phpborg-agent.service << 'EOF'
[Unit]
Description=phpBorg Backup Agent
After=network.target

[Service]
Type=simple
ExecStart=/opt/phpborg-agent/phpborg-agent -config /etc/phpborg-agent/config.yaml
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable phpborg-agent
systemctl start phpborg-agent
`
	if err := runInWSL(serviceScript); err != nil {
		// Fallback: run directly without systemd (WSL1 doesn't have systemd)
		fallbackScript := `
nohup /opt/phpborg-agent/phpborg-agent -config /etc/phpborg-agent/config.yaml > /var/log/phpborg-agent/agent.log 2>&1 &
echo $! > /var/run/phpborg-agent.pid
`
		return runInWSL(fallbackScript)
	}
	return nil
}

// runInWSL executes a bash script inside WSL Ubuntu
func runInWSL(script string) error {
	cmd := exec.Command("wsl", "-d", "Ubuntu", "-u", "root", "bash", "-c", script)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %s", err, string(output))
	}
	return nil
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
