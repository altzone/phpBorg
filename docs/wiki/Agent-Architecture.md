# phpBorg Agent Architecture

## Overview

phpBorg 2.0 introduces a **pull-based agent architecture** for secure communication between the backup server and remote clients. This modern approach eliminates the need for inbound connections to client servers, making it firewall-friendly and more secure.

## Architecture Comparison

### Traditional SSH-based Approach (Legacy)

```
┌─────────────────┐         SSH (port 22)          ┌─────────────────┐
│                 │ ──────────────────────────────>│                 │
│  phpBorg Server │         Borg over SSH          │  Remote Client  │
│                 │ ──────────────────────────────>│                 │
└─────────────────┘                                └─────────────────┘
```

**Limitations:**
- Requires SSH access from server to all clients
- Firewall rules needed on each client
- SSH credentials management complexity
- Not suitable for NAT/restricted environments

### New Agent-based Approach (Recommended)

```
┌─────────────────┐                                ┌─────────────────┐
│                 │                                │                 │
│  phpBorg Server │<───────── HTTPS (pull) ────────│  phpborg-agent  │
│                 │                                │                 │
│  ┌───────────┐  │                                │  ┌───────────┐  │
│  │ Agent API │  │<─────── mTLS + Polling ────────│  │  Daemon   │  │
│  └───────────┘  │                                │  └───────────┘  │
│                 │                                │                 │
│  ┌───────────┐  │         SSH (port 2222)        │                 │
│  │ Borg SSHD │  │<───────── Borg Backup ─────────│  borg client    │
│  └───────────┘  │        (append-only)           │                 │
└─────────────────┘                                └─────────────────┘
```

**Advantages:**
- **No inbound connections** to clients required
- **Firewall-friendly**: Only outbound HTTPS from clients
- **NAT traversal**: Works behind any NAT/firewall
- **Centralized management**: All control from phpBorg UI
- **Secure**: mTLS authentication + append-only Borg access

## Components

### 1. phpBorg Server Components

| Component | Port | Description |
|-----------|------|-------------|
| **Web UI** | 443 | Administration interface |
| **Agent API** | 443 | REST API for agent communication (mTLS) |
| **Borg SSHD** | 2222 | Dedicated SSH server for Borg operations |

### 2. phpborg-agent (Client)

The agent is a lightweight Go daemon installed on each client server:

- **Binary**: `/usr/local/bin/phpborg-agent`
- **Config**: `/etc/phpborg-agent/config.yaml`
- **Certificates**: `/etc/phpborg-agent/certs/`
- **Service**: `systemd` managed (`phpborg-agent.service`)

## Communication Flow

### Agent Registration

```
1. Admin generates install script in phpBorg UI
2. Script executed on client server
3. Agent generates SSH keypair
4. Agent registers with phpBorg server
5. Server issues mTLS certificate
6. Server configures Borg SSH access (append-only)
7. Agent starts polling for tasks
```

### Task Execution

```
┌──────────┐     ┌──────────┐     ┌──────────┐
│  phpBorg │     │  Agent   │     │   Borg   │
│  Server  │     │  Daemon  │     │  Client  │
└────┬─────┘     └────┬─────┘     └────┬─────┘
     │                │                │
     │   GET /tasks   │                │
     │<───────────────│                │
     │                │                │
     │  Task: backup  │                │
     │───────────────>│                │
     │                │                │
     │                │  borg create   │
     │                │───────────────>│
     │                │                │
     │                │    (SSH 2222)  │
     │                │───────────────>│ phpBorg Server
     │                │                │
     │  POST /complete│                │
     │<───────────────│                │
     │                │                │
```

### Heartbeat & Monitoring

The agent sends periodic heartbeats to the server:

```json
POST /api/agent/heartbeat
{
  "version": "1.0.0",
  "os_info": "Debian GNU/Linux 12",
  "capabilities": {
    "borg_version": "1.2.6",
    "has_lvm": true,
    "has_docker": true
  }
}
```

## Security Model

### Authentication Layers

1. **mTLS (Mutual TLS)**
   - Each agent has a unique client certificate
   - Server verifies client identity on every request
   - Certificates issued during registration

2. **Borg SSH Access**
   - Dedicated SSH server on port 2222
   - Per-agent SSH keys with `command=` restrictions
   - Append-only mode prevents backup deletion

### Certificate Management

```
/var/lib/phpborg/certs/
├── ca/
│   ├── ca.crt          # Certificate Authority
│   └── ca.key          # CA private key
└── agents/
    └── {uuid}/
        ├── agent.crt   # Agent certificate
        └── agent.key   # Agent private key
```

### Borg SSH Restrictions

Each agent's SSH key is restricted to specific commands:

```bash
command="borg serve --restrict-to-path /backup/agents/{uuid} --append-only",
no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty
ssh-ed25519 AAAA... agent-{uuid}
```

## Installation Methods

### Method 1: One-liner Script (Recommended)

Generate the install command from phpBorg UI:

```bash
curl -fsSL https://phpborg.example.com/install/{token} | sudo bash
```

The script automatically:
- Installs dependencies (borg, curl)
- Downloads and installs phpborg-agent
- Generates SSH keypair
- Registers with phpBorg server
- Configures and starts the service

### Method 2: SSH Password (Automatic)

1. Enter server details in phpBorg wizard
2. Provide SSH password (used once, not stored)
3. phpBorg connects and installs agent automatically

### Method 3: Manual Installation

1. Copy SSH public key from phpBorg UI
2. Install borg on client
3. Download and configure agent manually
4. Register agent via API

## Agent Task Types

| Task Type | Description |
|-----------|-------------|
| `backup_create` | Create a new backup archive |
| `backup_restore` | Restore files from archive |
| `capabilities_detect` | Detect system capabilities |
| `agent_update` | Self-update the agent binary |
| `test` | Connectivity test |

## Agent Self-Update

The agent can update itself when triggered from phpBorg:

```
1. Server creates 'agent_update' task
2. Agent downloads new binary
3. Verifies SHA256 checksum
4. Backs up current binary
5. Atomic replacement
6. Restarts via systemd
```

## Configuration

### Agent Configuration (`/etc/phpborg-agent/config.yaml`)

```yaml
agent:
  uuid: "550e8400-e29b-41d4-a716-446655440000"
  name: "web-server-01"
  max_concurrent_tasks: 2

server:
  url: "https://phpborg.example.com/api"
  insecure_skip_verify: false

tls:
  cert_file: "/etc/phpborg-agent/certs/agent.crt"
  key_file: "/etc/phpborg-agent/certs/agent.key"
  ca_file: "/etc/phpborg-agent/certs/ca.crt"

polling:
  interval: 10s
  heartbeat_interval: 60s

logging:
  file: "/var/log/phpborg-agent.log"
  level: "info"
```

## API Endpoints

### Agent API (mTLS authenticated)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/agent/register` | Register new agent |
| POST | `/api/agent/heartbeat` | Send heartbeat |
| GET | `/api/agent/tasks` | Poll for pending tasks |
| POST | `/api/agent/tasks/{id}/start` | Mark task started |
| POST | `/api/agent/tasks/{id}/progress` | Update progress |
| POST | `/api/agent/tasks/{id}/complete` | Mark completed |
| POST | `/api/agent/tasks/{id}/fail` | Mark failed |
| POST | `/api/agent/update/check` | Check for updates |
| GET | `/api/agent/update/download` | Download binary |

## Troubleshooting

### Agent won't connect

1. Check agent logs: `journalctl -u phpborg-agent -f`
2. Verify certificates exist in `/etc/phpborg-agent/certs/`
3. Test connectivity: `curl -v https://phpborg.example.com/api/health`
4. Check firewall allows outbound HTTPS

### Backups failing

1. Check Borg SSH access: `ssh -p 2222 -i /etc/phpborg-agent/ssh/id_ed25519 phpborg@server borg info`
2. Verify backup path permissions
3. Check disk space on server

### Agent not updating

1. Verify agent binary exists in `/opt/phpborg/releases/agent/`
2. Check agent has write permissions to its binary path
3. Review systemd service configuration

## Migration from SSH-only

Existing servers using SSH-only mode can be migrated to agent mode:

1. Generate agent install script for the server
2. Run installer on client (keeps existing backups)
3. Change server's `connection_mode` to `agent`
4. Verify agent connectivity
5. Disable direct SSH access (optional)

## Best Practices

1. **Always use HTTPS** for the phpBorg server
2. **Rotate certificates** annually
3. **Monitor heartbeats** for agent health
4. **Use append-only mode** to protect against ransomware
5. **Keep agents updated** via the built-in update mechanism
6. **Restrict Borg SSH** to dedicated port (2222)

## Related Documentation

- [Security Architecture](./Security-Architecture.md)
- [Installation Guide](./Installation.md)
- [Backup Configuration](./Backup-Configuration.md)
- [API Reference](./API-Reference.md)
