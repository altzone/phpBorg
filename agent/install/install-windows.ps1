#Requires -RunAsAdministrator
<#
.SYNOPSIS
    phpBorg Agent Installer for Windows

.DESCRIPTION
    This script downloads and installs the phpBorg backup agent and Borg Backup on Windows.
    It configures the agent as a Windows service for automatic startup.

.PARAMETER ServerUrl
    The URL of the phpBorg server (e.g., https://phpborg.example.com)

.PARAMETER AgentName
    A friendly name for this agent (e.g., "Windows-Server-01")

.PARAMETER RegistrationToken
    The registration token from phpBorg server

.PARAMETER BorgVersion
    Version of Borg Backup to install (default: 1.2.7)

.EXAMPLE
    .\install-windows.ps1 -ServerUrl "https://phpborg.example.com" -AgentName "My-Windows-Server" -RegistrationToken "abc123"

.NOTES
    Requires PowerShell 5.1 or later and Administrator privileges.
    Compatible with Windows 10/11 and Windows Server 2016+.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [string]$ServerUrl,

    [Parameter(Mandatory=$true)]
    [string]$AgentName,

    [Parameter(Mandatory=$true)]
    [string]$RegistrationToken,

    [Parameter(Mandatory=$false)]
    [string]$BorgVersion = "1.2.7",

    [Parameter(Mandatory=$false)]
    [switch]$SkipBorgInstall,

    [Parameter(Mandatory=$false)]
    [switch]$Force
)

# Script configuration
$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"  # Faster downloads

# Paths
$InstallDir = "$env:ProgramFiles\phpborg-agent"
$ConfigDir = "$env:ProgramData\phpborg-agent"
$LogDir = "$ConfigDir\logs"
$BorgDir = "$env:ProgramFiles\Borg"
$TempDir = "$env:TEMP\phpborg-install"

# URLs
$BorgDownloadUrl = "https://github.com/borgbackup/borg/releases/download/$BorgVersion/borg-windows64.exe"

# Service configuration
$ServiceName = "phpborg-agent"
$ServiceDisplayName = "phpBorg Backup Agent"
$ServiceDescription = "Automated backup agent for phpBorg backup management system"

# ============================================================================
# Helper Functions
# ============================================================================

function Write-Banner {
    $banner = @"

    ____  __          ____
   / __ \/ /_  ____  / __ )____  _________ _
  / /_/ / __ \/ __ \/ __  / __ \/ ___/ __  /
 / ____/ / / / /_/ / /_/ / /_/ / /  / /_/ /
/_/   /_/ /_/ .___/_____/\____/_/   \__, /
           /_/                     /____/

        Windows Agent Installer v1.0

"@
    Write-Host $banner -ForegroundColor Cyan
}

function Write-Step {
    param([string]$Message)
    Write-Host "`n[$((Get-Date).ToString('HH:mm:ss'))] " -NoNewline -ForegroundColor DarkGray
    Write-Host $Message -ForegroundColor Green
}

function Write-Info {
    param([string]$Message)
    Write-Host "    $Message" -ForegroundColor White
}

function Write-Warning {
    param([string]$Message)
    Write-Host "    [!] $Message" -ForegroundColor Yellow
}

function Write-Error {
    param([string]$Message)
    Write-Host "    [X] $Message" -ForegroundColor Red
}

function Test-AdminPrivileges {
    $currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-InternetConnection {
    try {
        $response = Invoke-WebRequest -Uri "https://github.com" -UseBasicParsing -TimeoutSec 10
        return $response.StatusCode -eq 200
    } catch {
        return $false
    }
}

function Get-AgentDownloadUrl {
    # Remove trailing slash from server URL
    $baseUrl = $ServerUrl.TrimEnd('/')
    return "$baseUrl/api/agent/download/windows"
}

# ============================================================================
# Installation Functions
# ============================================================================

function Install-Borg {
    Write-Step "Installing Borg Backup $BorgVersion..."

    # Create Borg directory
    if (-not (Test-Path $BorgDir)) {
        New-Item -ItemType Directory -Path $BorgDir -Force | Out-Null
    }

    $borgExe = "$BorgDir\borg.exe"

    # Check if already installed
    if ((Test-Path $borgExe) -and -not $Force) {
        $existingVersion = & $borgExe --version 2>$null
        if ($existingVersion) {
            Write-Info "Borg already installed: $existingVersion"
            return
        }
    }

    # Download Borg
    Write-Info "Downloading Borg from GitHub..."
    $tempBorg = "$TempDir\borg.exe"

    try {
        Invoke-WebRequest -Uri $BorgDownloadUrl -OutFile $tempBorg -UseBasicParsing
    } catch {
        Write-Error "Failed to download Borg: $_"
        throw
    }

    # Install Borg
    Write-Info "Installing Borg to $BorgDir..."
    Copy-Item $tempBorg $borgExe -Force

    # Verify installation
    $version = & $borgExe --version 2>$null
    if ($version) {
        Write-Info "Borg installed successfully: $version"
    } else {
        Write-Error "Borg installation verification failed"
        throw "Borg installation failed"
    }

    # Add to PATH if not already there
    $currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
    if ($currentPath -notlike "*$BorgDir*") {
        Write-Info "Adding Borg to system PATH..."
        [Environment]::SetEnvironmentVariable("Path", "$currentPath;$BorgDir", "Machine")
    }
}

function Install-Agent {
    Write-Step "Installing phpBorg Agent..."

    # Create directories
    foreach ($dir in @($InstallDir, $ConfigDir, $LogDir)) {
        if (-not (Test-Path $dir)) {
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
        }
    }

    $agentExe = "$InstallDir\phpborg-agent.exe"

    # Download agent
    Write-Info "Downloading phpBorg Agent..."
    $downloadUrl = Get-AgentDownloadUrl
    $tempAgent = "$TempDir\phpborg-agent.exe"

    try {
        # Add registration token as header for authentication
        $headers = @{
            "X-Registration-Token" = $RegistrationToken
        }
        Invoke-WebRequest -Uri $downloadUrl -OutFile $tempAgent -Headers $headers -UseBasicParsing
    } catch {
        Write-Error "Failed to download agent: $_"
        Write-Info "URL: $downloadUrl"
        throw
    }

    # Stop existing service if running
    $existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existingService -and $existingService.Status -eq "Running") {
        Write-Info "Stopping existing service..."
        Stop-Service -Name $ServiceName -Force
        Start-Sleep -Seconds 3
    }

    # Install agent
    Write-Info "Installing agent to $InstallDir..."
    Copy-Item $tempAgent $agentExe -Force

    # Verify installation
    $version = & $agentExe --version 2>$null
    if ($version) {
        Write-Info "Agent installed successfully: $version"
    } else {
        Write-Error "Agent installation verification failed"
        throw "Agent installation failed"
    }
}

function Register-Agent {
    Write-Step "Registering agent with phpBorg server..."

    $baseUrl = $ServerUrl.TrimEnd('/')
    $registerUrl = "$baseUrl/api/agent/register"

    # Collect system information
    $osInfo = Get-CimInstance Win32_OperatingSystem
    $computerInfo = Get-CimInstance Win32_ComputerSystem
    $cpuInfo = Get-CimInstance Win32_Processor | Select-Object -First 1

    $registrationData = @{
        name = $AgentName
        registration_token = $RegistrationToken
        os = "Windows"
        os_version = $osInfo.Caption
        hostname = $env:COMPUTERNAME
        architecture = $env:PROCESSOR_ARCHITECTURE
        cpu_cores = $computerInfo.NumberOfLogicalProcessors
        cpu_model = $cpuInfo.Name
        total_memory_mb = [math]::Round($computerInfo.TotalPhysicalMemory / 1MB)
        agent_version = (& "$InstallDir\phpborg-agent.exe" --version 2>$null).Split(" ")[-1]
    } | ConvertTo-Json

    Write-Info "Sending registration request..."

    try {
        $response = Invoke-RestMethod -Uri $registerUrl -Method Post -Body $registrationData -ContentType "application/json"

        if ($response.success) {
            Write-Info "Registration successful!"
            return $response.data
        } else {
            Write-Error "Registration failed: $($response.message)"
            throw "Registration failed"
        }
    } catch {
        Write-Error "Failed to register agent: $_"
        throw
    }
}

function Create-AgentConfig {
    param(
        [hashtable]$RegistrationData
    )

    Write-Step "Creating agent configuration..."

    $configPath = "$ConfigDir\config.yaml"

    # Generate configuration
    $config = @"
# phpBorg Agent Configuration
# Generated by installer on $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")

server:
  url: $($ServerUrl.TrimEnd('/'))/api
  insecure_skip_verify: false

agent:
  uuid: $($RegistrationData.uuid)
  name: $AgentName
  max_concurrent_tasks: 2

borg_ssh:
  host: $($RegistrationData.borg_host)
  port: $($RegistrationData.borg_port)
  user: $($RegistrationData.borg_user)
  private_key_path: $ConfigDir\ssh\id_rsa
  backup_path: $($RegistrationData.backup_path)

polling:
  interval: 5s
  heartbeat_interval: 60s

logging:
  level: info
  file: $LogDir\agent.log

tls:
  cert_file: $ConfigDir\certs\agent.crt
  key_file: $ConfigDir\certs\agent.key
  ca_file: $ConfigDir\certs\ca.crt
"@

    # Write configuration
    $config | Out-File -FilePath $configPath -Encoding UTF8 -Force
    Write-Info "Configuration written to: $configPath"

    # Create SSH directory and key
    $sshDir = "$ConfigDir\ssh"
    if (-not (Test-Path $sshDir)) {
        New-Item -ItemType Directory -Path $sshDir -Force | Out-Null
    }

    # Save SSH private key if provided
    if ($RegistrationData.ssh_private_key) {
        $RegistrationData.ssh_private_key | Out-File -FilePath "$sshDir\id_rsa" -Encoding ASCII -Force
        Write-Info "SSH key saved to: $sshDir\id_rsa"
    }

    # Create certs directory
    $certsDir = "$ConfigDir\certs"
    if (-not (Test-Path $certsDir)) {
        New-Item -ItemType Directory -Path $certsDir -Force | Out-Null
    }

    # Save certificates if provided
    if ($RegistrationData.tls_cert) {
        $RegistrationData.tls_cert | Out-File -FilePath "$certsDir\agent.crt" -Encoding ASCII -Force
    }
    if ($RegistrationData.tls_key) {
        $RegistrationData.tls_key | Out-File -FilePath "$certsDir\agent.key" -Encoding ASCII -Force
    }
    if ($RegistrationData.ca_cert) {
        $RegistrationData.ca_cert | Out-File -FilePath "$certsDir\ca.crt" -Encoding ASCII -Force
    }
}

function Install-Service {
    Write-Step "Installing Windows Service..."

    $agentExe = "$InstallDir\phpborg-agent.exe"

    # Remove existing service if present
    $existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Info "Removing existing service..."
        if ($existingService.Status -eq "Running") {
            Stop-Service -Name $ServiceName -Force
            Start-Sleep -Seconds 3
        }
        & sc.exe delete $ServiceName | Out-Null
        Start-Sleep -Seconds 2
    }

    # Create service
    Write-Info "Creating service..."
    $result = & sc.exe create $ServiceName binPath= "`"$agentExe`"" DisplayName= "$ServiceDisplayName" start= auto
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Failed to create service: $result"
        throw "Service creation failed"
    }

    # Set description
    & sc.exe description $ServiceName "$ServiceDescription" | Out-Null

    # Configure recovery options (restart on failure)
    & sc.exe failure $ServiceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null

    Write-Info "Service installed: $ServiceDisplayName"
}

function Start-AgentService {
    Write-Step "Starting phpBorg Agent service..."

    Start-Service -Name $ServiceName
    Start-Sleep -Seconds 3

    $service = Get-Service -Name $ServiceName
    if ($service.Status -eq "Running") {
        Write-Info "Service started successfully!"
    } else {
        Write-Warning "Service may not have started correctly. Status: $($service.Status)"
        Write-Info "Check logs at: $LogDir\agent.log"
    }
}

function Add-FirewallRules {
    Write-Step "Configuring Windows Firewall..."

    # Allow outbound connections for Borg SSH
    $ruleName = "phpBorg Agent - Borg SSH (Outbound)"
    $existingRule = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if (-not $existingRule) {
        New-NetFirewallRule -DisplayName $ruleName `
            -Direction Outbound `
            -Protocol TCP `
            -RemotePort 2222 `
            -Action Allow `
            -Profile Any | Out-Null
        Write-Info "Firewall rule added: $ruleName"
    } else {
        Write-Info "Firewall rule already exists: $ruleName"
    }
}

function Show-Summary {
    Write-Host "`n" -NoNewline
    Write-Host "============================================" -ForegroundColor Cyan
    Write-Host "   Installation Complete!" -ForegroundColor Green
    Write-Host "============================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  Agent Name:     $AgentName" -ForegroundColor White
    Write-Host "  Server:         $ServerUrl" -ForegroundColor White
    Write-Host "  Install Dir:    $InstallDir" -ForegroundColor White
    Write-Host "  Config Dir:     $ConfigDir" -ForegroundColor White
    Write-Host "  Log File:       $LogDir\agent.log" -ForegroundColor White
    Write-Host ""
    Write-Host "  Service Name:   $ServiceName" -ForegroundColor White
    Write-Host "  Service Status: $((Get-Service -Name $ServiceName).Status)" -ForegroundColor White
    Write-Host ""
    Write-Host "Useful Commands:" -ForegroundColor Yellow
    Write-Host "  View service status:  Get-Service $ServiceName" -ForegroundColor Gray
    Write-Host "  Stop service:         Stop-Service $ServiceName" -ForegroundColor Gray
    Write-Host "  Start service:        Start-Service $ServiceName" -ForegroundColor Gray
    Write-Host "  View logs:            Get-Content $LogDir\agent.log -Tail 50" -ForegroundColor Gray
    Write-Host ""
}

# ============================================================================
# Main Installation Flow
# ============================================================================

function Main {
    Write-Banner

    # Check prerequisites
    Write-Step "Checking prerequisites..."

    if (-not (Test-AdminPrivileges)) {
        Write-Error "This script requires Administrator privileges."
        Write-Info "Please run PowerShell as Administrator and try again."
        exit 1
    }
    Write-Info "Running with Administrator privileges"

    if (-not (Test-InternetConnection)) {
        Write-Error "No internet connection detected."
        Write-Info "Please check your network connection and try again."
        exit 1
    }
    Write-Info "Internet connection verified"

    # Create temp directory
    if (Test-Path $TempDir) {
        Remove-Item $TempDir -Recurse -Force
    }
    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null

    try {
        # Install Borg
        if (-not $SkipBorgInstall) {
            Install-Borg
        } else {
            Write-Step "Skipping Borg installation (--SkipBorgInstall)"
        }

        # Install Agent
        Install-Agent

        # Register with server
        $registrationData = Register-Agent

        # Create configuration
        Create-AgentConfig -RegistrationData $registrationData

        # Install service
        Install-Service

        # Configure firewall
        Add-FirewallRules

        # Start service
        Start-AgentService

        # Show summary
        Show-Summary

    } catch {
        Write-Host ""
        Write-Error "Installation failed: $_"
        Write-Host ""
        Write-Info "For troubleshooting, check:"
        Write-Info "  - Network connectivity to $ServerUrl"
        Write-Info "  - Registration token validity"
        Write-Info "  - Windows Event Log (Application)"
        exit 1
    } finally {
        # Cleanup
        if (Test-Path $TempDir) {
            Remove-Item $TempDir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

# Run main function
Main
