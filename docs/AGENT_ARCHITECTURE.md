# phpBorg Agent Architecture

This document provides technical details for implementing the phpBorg agent system.

## Table of Contents

1. [Overview](#overview)
2. [Components](#components)
3. [Communication Protocol](#communication-protocol)
4. [Agent Daemon (Go)](#agent-daemon-go)
5. [Server-Side Implementation](#server-side-implementation)
6. [Installation Flow](#installation-flow)
7. [Task Types](#task-types)
8. [Implementation Roadmap](#implementation-roadmap)

---

## Overview

The phpBorg agent system consists of:

1. **Agent Gateway API** (PHP) - Endpoints on phpBorg server for agent communication
2. **Dedicated SSH Borg Server** - Hardened sshd instance for borg data transfer
3. **phpborg-agent daemon** (Go) - Lightweight daemon running on backup clients
4. **Certificate Authority** - mTLS certificate management

---

## Components

### Server-Side (phpBorg)

```
phpBorg Server
├── src/Api/Controller/
│   └── AgentController.php          # Agent API endpoints
├── src/Service/Agent/
│   ├── AgentManager.php             # Agent lifecycle management
│   ├── TaskManager.php              # Task queue for agents
│   ├── CertificateManager.php       # CA and certificate operations
│   └── AuthorizedKeysManager.php    # SSH key management
├── src/Entity/
│   ├── Agent.php                    # Agent entity
│   └── AgentTask.php                # Task entity
└── install/scripts/
    └── setup-borg-sshd.sh           # Dedicated SSH setup
```

### Client-Side (Agent)

```
phpborg-agent/                       # Go project
├── cmd/phpborg-agent/
│   └── main.go
├── internal/
│   ├── agent/
│   │   ├── agent.go                 # Main daemon loop
│   │   └── config.go                # Configuration
│   ├── api/
│   │   └── client.go                # mTLS HTTP client
│   ├── executor/
│   │   ├── executor.go              # Command execution
│   │   ├── whitelist.go             # Command validation
│   │   └── sudo.go                  # Sudo wrapper
│   ├── tasks/
│   │   ├── handler.go               # Task dispatcher
│   │   ├── backup.go                # Backup operations
│   │   ├── restore.go               # Restore operations
│   │   ├── capabilities.go          # System detection
│   │   ├── stats.go                 # Stats collection
│   │   └── database.go              # Database dumps
│   └── borg/
│       └── client.go                # Borg command wrapper
├── configs/
│   └── config.example.yaml
├── scripts/
│   └── install.sh.tmpl              # Installation template
├── Makefile
└── go.mod
```

---

## Communication Protocol

### Agent Registration Flow

```
┌─────────────┐                              ┌─────────────┐
│   phpBorg   │                              │   Agent     │
│   Server    │                              │   (new)     │
└──────┬──────┘                              └──────┬──────┘
       │                                            │
       │◀─── GET /agent/install-script/{token} ────│ (curl | sudo bash)
       │                                            │
       │──── Returns bash script with certs ───────▶│
       │                                            │
       │                    (agent installs and starts)
       │                                            │
       │◀─── POST /agent/register ─────────────────│ (mTLS)
       │     {hostname, os, borg_version, ...}     │
       │                                            │
       │──── {agent_id, ssh_host_key} ─────────────▶│
       │                                            │
       │     (agent now polls for tasks)           │
       │                                            │
```

### Task Execution Flow

```
┌─────────────┐                              ┌─────────────┐
│   phpBorg   │                              │   Agent     │
└──────┬──────┘                              └──────┬──────┘
       │                                            │
       │◀─── GET /agent/tasks ─────────────────────│ (poll every 30s)
       │                                            │
       │──── {tasks: [{id, type, payload}]} ───────▶│
       │                                            │
       │◀─── POST /agent/tasks/{id}/start ─────────│
       │                                            │
       │                  (agent executes task)     │
       │                                            │
       │◀─── POST /agent/tasks/{id}/progress ──────│ (periodic)
       │     {percent, message, output}            │
       │                                            │
       │◀─── POST /agent/tasks/{id}/complete ──────│ (or /fail)
       │     {result, duration, bytes}             │
       │                                            │
```

### Borg Backup Flow

```
┌─────────────┐                              ┌─────────────┐
│   phpBorg   │                              │   Agent     │
│  (SSH:2222) │                              │             │
└──────┬──────┘                              └──────┬──────┘
       │                                            │
       │                  (task received via API)   │
       │                                            │
       │◀─── SSH connect (Ed25519 key) ────────────│
       │                                            │
       │──── ForceCommand: borg serve ─────────────▶│
       │     --restrict-to-path /opt/backups/UUID  │
       │                                            │
       │◀─── borg create ssh://...::archive ───────│
       │     (data streams over SSH)               │
       │                                            │
       │──── borg serve writes to repo ────────────▶│
       │                                            │
       │◀─── SSH disconnect ───────────────────────│
       │                                            │
       │     (agent reports completion via API)    │
       │                                            │
```

---

## Agent Daemon (Go)

### Main Structure

```go
// cmd/phpborg-agent/main.go
package main

import (
    "context"
    "flag"
    "os"
    "os/signal"
    "syscall"

    "github.com/altzone/phpborg-agent/internal/agent"
)

func main() {
    configPath := flag.String("config", "/etc/phpborg-agent/config.yaml", "Config file path")
    flag.Parse()

    cfg, err := agent.LoadConfig(*configPath)
    if err != nil {
        log.Fatalf("Failed to load config: %v", err)
    }

    ctx, cancel := context.WithCancel(context.Background())
    defer cancel()

    // Handle shutdown signals
    sigCh := make(chan os.Signal, 1)
    signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
    go func() {
        <-sigCh
        log.Info("Shutdown signal received")
        cancel()
    }()

    a := agent.New(cfg)
    if err := a.Run(ctx); err != nil {
        log.Fatalf("Agent error: %v", err)
    }
}
```

### Agent Core

```go
// internal/agent/agent.go
package agent

import (
    "context"
    "time"

    "github.com/altzone/phpborg-agent/internal/api"
    "github.com/altzone/phpborg-agent/internal/tasks"
)

type Agent struct {
    config    *Config
    client    *api.Client
    executor  *tasks.Executor
    taskQueue chan *api.Task
}

func New(cfg *Config) *Agent {
    return &Agent{
        config:    cfg,
        client:    api.NewClient(cfg.Server.URL, cfg.TLS),
        executor:  tasks.NewExecutor(cfg.Executor),
        taskQueue: make(chan *api.Task, cfg.Agent.MaxConcurrentTasks),
    }
}

func (a *Agent) Run(ctx context.Context) error {
    // Register with server on startup
    if err := a.register(ctx); err != nil {
        return fmt.Errorf("registration failed: %w", err)
    }

    // Start task workers
    for i := 0; i < a.config.Agent.MaxConcurrentTasks; i++ {
        go a.taskWorker(ctx, i)
    }

    // Main polling loop
    ticker := time.NewTicker(a.config.Polling.Interval)
    defer ticker.Stop()

    for {
        select {
        case <-ctx.Done():
            return nil
        case <-ticker.C:
            a.pollTasks(ctx)
        }
    }
}

func (a *Agent) pollTasks(ctx context.Context) {
    tasks, err := a.client.GetTasks(ctx)
    if err != nil {
        log.Errorf("Failed to poll tasks: %v", err)
        return
    }

    for _, task := range tasks {
        select {
        case a.taskQueue <- task:
            log.Infof("Queued task %s (%s)", task.ID, task.Type)
        default:
            log.Warnf("Task queue full, skipping task %s", task.ID)
        }
    }
}

func (a *Agent) taskWorker(ctx context.Context, workerID int) {
    for {
        select {
        case <-ctx.Done():
            return
        case task := <-a.taskQueue:
            a.executeTask(ctx, task)
        }
    }
}

func (a *Agent) executeTask(ctx context.Context, task *api.Task) {
    log.Infof("Executing task %s (%s)", task.ID, task.Type)

    // Mark task as started
    a.client.TaskStart(ctx, task.ID)

    // Execute based on type
    result, err := a.executor.Execute(ctx, task, func(progress int, msg string) {
        a.client.TaskProgress(ctx, task.ID, progress, msg)
    })

    if err != nil {
        log.Errorf("Task %s failed: %v", task.ID, err)
        a.client.TaskFail(ctx, task.ID, err.Error())
        return
    }

    log.Infof("Task %s completed", task.ID)
    a.client.TaskComplete(ctx, task.ID, result)
}
```

### mTLS Client

```go
// internal/api/client.go
package api

import (
    "crypto/tls"
    "crypto/x509"
    "io/ioutil"
    "net/http"
)

type Client struct {
    baseURL    string
    httpClient *http.Client
}

func NewClient(baseURL string, tlsCfg TLSConfig) *Client {
    // Load CA certificate
    caCert, _ := ioutil.ReadFile(tlsCfg.CACert)
    caCertPool := x509.NewCertPool()
    caCertPool.AppendCertsFromPEM(caCert)

    // Load client certificate
    clientCert, _ := tls.LoadX509KeyPair(tlsCfg.ClientCert, tlsCfg.ClientKey)

    tlsConfig := &tls.Config{
        RootCAs:      caCertPool,
        Certificates: []tls.Certificate{clientCert},
        MinVersion:   tls.VersionTLS13,
    }

    return &Client{
        baseURL: baseURL,
        httpClient: &http.Client{
            Transport: &http.Transport{
                TLSClientConfig: tlsConfig,
            },
            Timeout: 30 * time.Second,
        },
    }
}
```

### Configuration

```yaml
# /etc/phpborg-agent/config.yaml
server:
  url: "https://phpborg.example.com"

polling:
  interval: 30s
  timeout: 10s

tls:
  ca_cert: "/etc/phpborg-agent/certs/ca.crt"
  client_cert: "/etc/phpborg-agent/certs/agent.crt"
  client_key: "/etc/phpborg-agent/certs/agent.key"

agent:
  id: "agent-uuid-here"
  max_concurrent_tasks: 2

borg:
  ssh_key: "/var/lib/phpborg-agent/.ssh/id_ed25519"
  ssh_host: "phpborg.example.com"
  ssh_port: 2222
  ssh_user: "phpborg-borg"

executor:
  sudo_path: "/usr/bin/sudo"
  shell: "/bin/bash"
  command_timeout: 3600s

logging:
  level: "info"
  file: "/var/log/phpborg-agent/agent.log"
  max_size_mb: 100
  max_backups: 5
```

---

## Server-Side Implementation

### Agent API Endpoints

```php
// src/Api/Controller/AgentController.php

/**
 * Agent Gateway API
 * All endpoints require mTLS client certificate
 */
class AgentController extends BaseController
{
    /**
     * POST /agent/register
     * Register a new agent or update existing
     */
    public function register(): void;

    /**
     * GET /agent/tasks
     * Get pending tasks for this agent
     */
    public function getTasks(): void;

    /**
     * POST /agent/tasks/{id}/start
     * Mark task as started
     */
    public function taskStart(): void;

    /**
     * POST /agent/tasks/{id}/progress
     * Update task progress
     */
    public function taskProgress(): void;

    /**
     * POST /agent/tasks/{id}/complete
     * Mark task as completed with result
     */
    public function taskComplete(): void;

    /**
     * POST /agent/tasks/{id}/fail
     * Mark task as failed with error
     */
    public function taskFail(): void;

    /**
     * GET /agent/config
     * Get agent configuration updates
     */
    public function getConfig(): void;
}
```

### Database Schema

```sql
-- Agent table
CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    os_info VARCHAR(255),
    borg_version VARCHAR(50),
    agent_version VARCHAR(50),
    ssh_public_key TEXT,
    certificate_fingerprint VARCHAR(64),
    append_only_mode BOOLEAN DEFAULT FALSE,
    last_seen_at DATETIME,
    registered_at DATETIME NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    INDEX idx_uuid (uuid),
    INDEX idx_active (active)
);

-- Agent tasks table
CREATE TABLE agent_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    agent_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    priority INT DEFAULT 5,
    payload JSON,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    progress INT DEFAULT 0,
    progress_message VARCHAR(255),
    result JSON,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(id),
    INDEX idx_agent_status (agent_id, status),
    INDEX idx_status_priority (status, priority DESC)
);
```

---

## Installation Flow

### 1. Generate Install Token (Web UI)

User clicks "Add Server" → chooses "Agent Install" method → gets one-liner:

```bash
curl -sSL https://phpborg.example.com/agent/install/TOKEN | sudo bash
```

### 2. Install Script Template

```bash
#!/bin/bash
# Auto-generated by phpBorg - contains embedded certificates

set -euo pipefail

AGENT_VERSION="%%AGENT_VERSION%%"
PHPBORG_SERVER="%%PHPBORG_SERVER%%"
AGENT_UUID="%%AGENT_UUID%%"
BORG_SSH_PORT="%%BORG_SSH_PORT%%"

# Embedded certificates
CA_CERT="%%CA_CERT_BASE64%%"
AGENT_CERT="%%AGENT_CERT_BASE64%%"
AGENT_KEY="%%AGENT_KEY_BASE64%%"
SSH_PRIVATE_KEY="%%SSH_KEY_BASE64%%"

echo "=== phpBorg Agent Installation ==="

# 1. Detect architecture
ARCH=$(uname -m)
case $ARCH in
    x86_64) ARCH="amd64" ;;
    aarch64) ARCH="arm64" ;;
    *) echo "Unsupported architecture: $ARCH"; exit 1 ;;
esac

# 2. Download agent binary
curl -sSL "${PHPBORG_SERVER}/downloads/phpborg-agent-linux-${ARCH}" \
    -o /usr/local/bin/phpborg-agent
chmod +x /usr/local/bin/phpborg-agent

# 3. Create user
useradd -r -m -d /var/lib/phpborg-agent -s /usr/sbin/nologin phpborg-agent 2>/dev/null || true
usermod -aG docker phpborg-agent 2>/dev/null || true

# 4. Create directories
mkdir -p /etc/phpborg-agent/certs
mkdir -p /var/lib/phpborg-agent/.ssh
mkdir -p /var/log/phpborg-agent

# 5. Install certificates
echo "$CA_CERT" | base64 -d > /etc/phpborg-agent/certs/ca.crt
echo "$AGENT_CERT" | base64 -d > /etc/phpborg-agent/certs/agent.crt
echo "$AGENT_KEY" | base64 -d > /etc/phpborg-agent/certs/agent.key
echo "$SSH_PRIVATE_KEY" | base64 -d > /var/lib/phpborg-agent/.ssh/id_ed25519

# 6. Set permissions
chmod 600 /etc/phpborg-agent/certs/agent.key
chmod 600 /var/lib/phpborg-agent/.ssh/id_ed25519
chown -R phpborg-agent:phpborg-agent /var/lib/phpborg-agent /var/log/phpborg-agent

# 7. Create config
cat > /etc/phpborg-agent/config.yaml << EOF
server:
  url: "${PHPBORG_SERVER}"
polling:
  interval: 30s
tls:
  ca_cert: "/etc/phpborg-agent/certs/ca.crt"
  client_cert: "/etc/phpborg-agent/certs/agent.crt"
  client_key: "/etc/phpborg-agent/certs/agent.key"
agent:
  id: "${AGENT_UUID}"
  max_concurrent_tasks: 2
borg:
  ssh_key: "/var/lib/phpborg-agent/.ssh/id_ed25519"
  ssh_host: "$(echo $PHPBORG_SERVER | sed 's|https://||')"
  ssh_port: ${BORG_SSH_PORT}
  ssh_user: "phpborg-borg"
logging:
  level: "info"
  file: "/var/log/phpborg-agent/agent.log"
EOF

# 8. Install sudoers
cat > /etc/sudoers.d/phpborg-agent << 'SUDOERS'
%%SUDOERS_CONTENT%%
SUDOERS
chmod 440 /etc/sudoers.d/phpborg-agent

# 9. Install systemd service
cat > /etc/systemd/system/phpborg-agent.service << 'EOF'
[Unit]
Description=phpBorg Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=phpborg-agent
ExecStart=/usr/local/bin/phpborg-agent -config /etc/phpborg-agent/config.yaml
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# 10. Start service
systemctl daemon-reload
systemctl enable phpborg-agent
systemctl start phpborg-agent

# 11. Install borg if needed
command -v borg &>/dev/null || apt-get update -qq && apt-get install -y borgbackup

echo ""
echo "=== Installation Complete ==="
echo "Agent UUID: ${AGENT_UUID}"
echo "Status: $(systemctl is-active phpborg-agent)"
```

---

## Task Types

### capabilities_detect

Detects installed software and capabilities on the server.

```json
{
  "type": "capabilities_detect",
  "payload": {}
}

// Result
{
  "os": {"name": "Ubuntu", "version": "22.04"},
  "databases": {
    "mysql": {"installed": true, "version": "8.0.35", "running": true},
    "postgresql": {"installed": false}
  },
  "docker": {"installed": true, "version": "24.0.7", "containers": 5},
  "lvm": {"installed": true, "volumes": [...]},
  "borg": {"installed": true, "version": "1.2.6"}
}
```

### backup_create

Execute a backup job.

```json
{
  "type": "backup_create",
  "payload": {
    "backup_job_id": 123,
    "repository": "/opt/backups/agent-uuid/repo1",
    "archive_name": "backup-2025-11-27-103000",
    "paths": ["/var/www", "/etc/nginx"],
    "excludes": ["*.log", "*.tmp", "node_modules"],
    "compression": "zstd,3",
    "one_file_system": true,
    "databases": [
      {"type": "mysql", "name": "wordpress", "all": false}
    ],
    "docker_stop": ["nginx", "php-fpm"],
    "pre_script": "/opt/scripts/pre-backup.sh",
    "post_script": "/opt/scripts/post-backup.sh"
  }
}
```

### stats_collect

Collect server statistics.

```json
{
  "type": "stats_collect",
  "payload": {}
}

// Result
{
  "cpu_percent": 45.2,
  "memory_total_mb": 8192,
  "memory_used_mb": 4096,
  "disk_total_gb": 500,
  "disk_used_gb": 234,
  "uptime_seconds": 1234567,
  "load_average": [1.5, 1.2, 0.9]
}
```

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1-2)

- [ ] **1.1** Create database migrations for `agents` and `agent_tasks` tables
- [ ] **1.2** Implement `CertificateManager` - CA setup, certificate generation
- [ ] **1.3** Implement `AuthorizedKeysManager` - SSH key management
- [ ] **1.4** Create `setup-borg-sshd.sh` installation script
- [ ] **1.5** Add dedicated SSH server to phpBorg installer

### Phase 2: Agent API (Week 2-3)

- [ ] **2.1** Implement `AgentController` with all endpoints
- [ ] **2.2** Add mTLS middleware for agent authentication
- [ ] **2.3** Implement `AgentManager` for agent lifecycle
- [ ] **2.4** Implement `TaskManager` for task distribution
- [ ] **2.5** Add agent installation script generator

### Phase 3: Go Agent (Week 3-4)

- [ ] **3.1** Setup Go project structure
- [ ] **3.2** Implement mTLS HTTP client
- [ ] **3.3** Implement main agent loop (polling)
- [ ] **3.4** Implement task executor with whitelist
- [ ] **3.5** Implement borg wrapper
- [ ] **3.6** Build for linux/amd64 and linux/arm64

### Phase 4: Task Handlers (Week 4-5)

- [ ] **4.1** Implement `capabilities_detect` task
- [ ] **4.2** Implement `stats_collect` task
- [ ] **4.3** Implement `backup_create` task
- [ ] **4.4** Implement `db_dump` tasks (MySQL, PostgreSQL, MongoDB)
- [ ] **4.5** Implement Docker stop/start tasks

### Phase 5: Integration (Week 5-6)

- [ ] **5.1** Update "Add Server" wizard for agent method
- [ ] **5.2** Update backup job execution to use agent tasks
- [ ] **5.3** Add agent status monitoring to UI
- [ ] **5.4** Add agent logs viewer
- [ ] **5.5** Migration path from SSH-based servers to agent-based

### Phase 6: Testing & Polish (Week 6-7)

- [ ] **6.1** Integration tests
- [ ] **6.2** Security audit
- [ ] **6.3** Performance testing
- [ ] **6.4** Documentation
- [ ] **6.5** Release preparation

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2025-11-27 | Initial architecture document |

