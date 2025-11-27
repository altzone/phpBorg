# phpBorg Security Architecture

This document describes the security architecture of phpBorg, an enterprise-grade backup solution designed with security as a primary concern.

## Table of Contents

1. [Overview](#overview)
2. [Agent Architecture](#agent-architecture)
3. [Communication Security](#communication-security)
4. [SSH Borg Server](#ssh-borg-server)
5. [Agent Security](#agent-security)
6. [Certificate Management](#certificate-management)
7. [Privilege Management](#privilege-management)
8. [Data Protection](#data-protection)
9. [Audit & Logging](#audit--logging)

---

## Overview

phpBorg uses a **pull-based agent architecture** where remote servers initiate all connections to the central phpBorg server. This design provides:

- **Zero inbound ports** required on backup clients
- **Mutual TLS (mTLS)** authentication for API communication
- **Dedicated hardened SSH** instance for Borg data transfer only
- **Principle of least privilege** throughout the system
- **Complete audit trail** of all operations

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            phpBorg Server                                    │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐   │
│   │              Agent Gateway API (HTTPS + mTLS)                       │   │
│   │                        Port 443                                     │   │
│   │                                                                     │   │
│   │   • Client certificate required (mutual TLS)                        │   │
│   │   • Task distribution, progress reporting, stats collection         │   │
│   │   • Certificate-based agent identification                          │   │
│   └────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐   │
│   │              Dedicated SSH Borg Server                              │   │
│   │                     Port 2222 (configurable)                        │   │
│   │                                                                     │   │
│   │   • Separate sshd instance (not system SSH)                         │   │
│   │   • Ed25519 keys only (no RSA, no passwords)                        │   │
│   │   • ForceCommand: borg serve --restrict-to-path                     │   │
│   │   • Each agent isolated to its own backup directory                 │   │
│   │   • Optional append-only mode (ransomware protection)               │   │
│   └────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   Storage: /opt/backups/{agent-uuid}/  (isolated per agent)                 │
└─────────────────────────────────────────────────────────────────────────────┘
                    ▲                              ▲
                    │ HTTPS (mTLS)                 │ SSH (port 2222)
                    │ Outbound from agent          │ Outbound from agent
                    │                              │
┌───────────────────┴──────────────────────────────┴──────────────────────────┐
│                         Remote Server (Client)                               │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐   │
│   │                    phpborg-agent daemon                             │   │
│   │                                                                     │   │
│   │   • Polls for tasks via HTTPS (outbound only)                       │   │
│   │   • Executes backups locally with restricted sudo                   │   │
│   │   • Streams backup data to phpBorg via SSH                          │   │
│   │   • Reports progress and results via HTTPS                          │   │
│   └────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   NO INBOUND PORTS REQUIRED                                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Agent Architecture

### Pull-Based Design

Unlike traditional backup solutions that require SSH access from the backup server to clients, phpBorg uses a **pull-based model**:

1. **Agent initiates all connections** - No inbound ports needed on client servers
2. **Firewall friendly** - Works through NAT, corporate firewalls
3. **Reduced attack surface** - No SSH daemon exposed for backup purposes

### Agent Components

| Component | Purpose |
|-----------|---------|
| `phpborg-agent` | Go daemon that polls for tasks and executes backups |
| `phpborg-agent` user | Dedicated system user with minimal privileges |
| Sudoers rules | Whitelist of allowed commands for backup operations |
| mTLS certificates | Client certificate for API authentication |
| SSH key pair | Ed25519 key for borg data transfer |

---

## Communication Security

### API Communication (mTLS)

All API communication between agents and the phpBorg server uses **mutual TLS (mTLS)**:

```
Agent                                    phpBorg Server
  │                                            │
  │──── TLS ClientHello ─────────────────────▶│
  │◀─── TLS ServerHello + Server Certificate ─│
  │──── Client Certificate ──────────────────▶│
  │◀─── Certificate Verification ─────────────│
  │──── Encrypted API Request ───────────────▶│
  │◀─── Encrypted API Response ──────────────│
```

**Security properties:**
- Server authenticates to client (standard TLS)
- Client authenticates to server (client certificate)
- Traffic encrypted with TLS 1.3
- Certificate pinning supported

### Supported Cipher Suites

```
TLS 1.3:
- TLS_AES_256_GCM_SHA384
- TLS_CHACHA20_POLY1305_SHA256
- TLS_AES_128_GCM_SHA256
```

---

## SSH Borg Server

phpBorg runs a **dedicated SSH instance** exclusively for Borg backup data transfer. This instance is completely separate from the system SSH daemon.

### Configuration

Location: `/etc/phpborg-sshd/sshd_config`

```sshd_config
# Network
Port 2222
Protocol 2

# Host Keys - Ed25519 ONLY
HostKey /etc/phpborg-sshd/host_keys/ssh_host_ed25519_key

# Authentication - Keys ONLY
PermitRootLogin no
PubkeyAuthentication yes
PasswordAuthentication no
PermitEmptyPasswords no
ChallengeResponseAuthentication no
UsePAM no

# Restrictions - EVERYTHING DISABLED
PermitTTY no
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
AllowStreamLocalForwarding no
GatewayPorts no
PermitTunnel no
PermitUserEnvironment no
PermitUserRC no

# Single user only
AllowUsers phpborg-borg

# Modern crypto only
KexAlgorithms curve25519-sha256,curve25519-sha256@libssh.org
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com
MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com
HostKeyAlgorithms ssh-ed25519

# Security limits
MaxAuthTries 3
MaxSessions 20
LoginGraceTime 30
```

### Key Restrictions

Each agent's SSH key in `authorized_keys` is restricted to only run `borg serve`:

```
command="borg serve --restrict-to-path /opt/backups/AGENT-UUID --append-only",restrict ssh-ed25519 AAAAC3... agent@hostname
```

The `restrict` option is equivalent to:
- `no-port-forwarding`
- `no-X11-forwarding`
- `no-agent-forwarding`
- `no-pty`
- `no-user-rc`

### Path Isolation

Each agent can **only access its own backup directory**:

```
/opt/backups/
├── agent-uuid-1/     # Agent 1 can ONLY access this
│   ├── repo1/
│   └── repo2/
├── agent-uuid-2/     # Agent 2 can ONLY access this
│   └── repo1/
└── agent-uuid-3/     # Agent 3 can ONLY access this
    └── repo1/
```

### Append-Only Mode (Ransomware Protection)

When enabled, `--append-only` prevents:
- Deletion of existing archives
- Modification of existing data
- Repository compaction/pruning from client

This protects against ransomware that might compromise a client server and attempt to delete backups.

---

## Agent Security

### Dedicated System User

The agent runs as a dedicated system user with minimal privileges:

```bash
User: phpborg-agent
Shell: /usr/sbin/nologin (no interactive login)
Home: /var/lib/phpborg-agent
Groups: docker (if Docker backups needed)
Password: disabled (key-only authentication)
```

### Sudoers Configuration

The agent can only execute a **whitelist of specific commands** via sudo:

```sudoers
# /etc/sudoers.d/phpborg-agent

Defaults:phpborg-agent !requiretty
Defaults:phpborg-agent env_reset

# === SYSTEM READ (safe) ===
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /etc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /proc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/df *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/du *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/find *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/which *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl is-active *

# === MYSQL/MARIADB (read-only dumps) ===
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysqldump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mariadb-dump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysql -e *

# === POSTGRESQL (read-only dumps) ===
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dump *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dumpall *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/psql -t -c *

# === BORG BACKUP ===
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg --version
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg create *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg extract *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/borg list *

# === LVM SNAPSHOTS (restricted naming) ===
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvcreate -L * -s -n phpborg_snap_* *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvremove -f /dev/*/phpborg_snap_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mount -o ro /dev/*/phpborg_snap_* /mnt/phpborg_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/umount /mnt/phpborg_*

# === RESTORE (restricted paths) ===
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mkdir -p /var/restore/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/rm -rf /var/restore/*
```

### Docker Access

Docker access is granted via **group membership**, not sudo:

```bash
usermod -aG docker phpborg-agent
```

This allows the agent to:
- List containers and volumes
- Stop/start containers for consistent backups
- Inspect container configurations

---

## Certificate Management

### Certificate Authority

phpBorg maintains its own **internal Certificate Authority (CA)**:

```
/etc/phpborg/certs/
├── ca.crt              # CA certificate (distributed to agents)
├── ca.key              # CA private key (never leaves server)
├── server.crt          # Server certificate
├── server.key          # Server private key
└── agents/
    ├── agent-uuid-1.crt
    ├── agent-uuid-1.key
    └── ...
```

### Certificate Lifecycle

1. **Generation**: When an agent is registered, phpBorg generates a unique certificate
2. **Distribution**: Certificate is embedded in the agent installation script
3. **Validation**: Each API request validates the client certificate
4. **Revocation**: Deleting the certificate from the database immediately revokes access
5. **Rotation**: Certificates can be rotated without agent reinstallation

### Certificate Properties

```
Type: X.509 v3
Key Algorithm: ECDSA P-256 or Ed25519
Validity: 1 year (configurable)
Subject: CN=agent-{uuid}
Extensions:
  - Key Usage: Digital Signature, Key Encipherment
  - Extended Key Usage: TLS Client Authentication
```

---

## Privilege Management

### Principle of Least Privilege

| Component | Runs As | Capabilities |
|-----------|---------|--------------|
| phpBorg Web UI | www-data | Web serving only |
| phpBorg API | www-data | Database, Redis, job queue |
| phpBorg Workers | phpborg | Backup operations, SSH |
| phpBorg Scheduler | phpborg | Job scheduling |
| phpborg-sshd | root (drops to phpborg-borg) | Borg serve only |
| phpborg-agent | phpborg-agent | Whitelisted commands only |

### Capability Restrictions

The agent daemon runs with restricted Linux capabilities:

```ini
# systemd service hardening
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
```

---

## Data Protection

### Encryption at Rest

Borg repositories support encryption:

```
Algorithms:
- AES-256-CTR for data encryption
- HMAC-SHA256 for authentication
- Argon2 for key derivation
```

Repository encryption is configured per-backup-job and the passphrase is stored encrypted in the phpBorg database.

### Encryption in Transit

All data transfer is encrypted:

| Channel | Encryption |
|---------|------------|
| API (HTTPS) | TLS 1.3 |
| Borg SSH | ChaCha20-Poly1305 or AES-256-GCM |

### Backup Integrity

Borg provides built-in integrity verification:

- Content-defined chunking with deduplication
- SHA256 checksums for all chunks
- HMAC authentication when encryption is enabled
- `borg check` for repository verification

---

## Audit & Logging

### phpBorg Server Logs

```
/var/log/phpborg/
├── phpborg.log          # Main application log
├── api.log              # API access log
├── agent-gateway.log    # Agent API log
└── borg-sshd.log        # Dedicated SSH server log
```

### Agent Logs

```
/var/log/phpborg-agent/
├── agent.log            # Main agent log
└── commands.log         # All executed commands
```

### Logged Events

- Agent registration/deregistration
- All API requests (with client certificate CN)
- SSH connections to borg server
- Backup start/complete/fail
- Command executions on agents
- Certificate issuance/revocation

### Log Format

```json
{
  "timestamp": "2025-11-27T10:30:00Z",
  "level": "INFO",
  "component": "AGENT_API",
  "agent_id": "uuid-1234",
  "action": "task_complete",
  "details": {
    "task_id": "task-5678",
    "task_type": "backup_create",
    "duration_ms": 45000,
    "bytes_transferred": 1073741824
  }
}
```

---

## Security Recommendations

### Network Security

1. **Firewall the Borg SSH port** (2222) to only allow agent IPs if possible
2. **Use a reverse proxy** (nginx/Caddy) for the API with rate limiting
3. **Enable fail2ban** for the dedicated SSH instance

### Operational Security

1. **Enable append-only mode** for critical backups
2. **Rotate certificates** annually
3. **Monitor agent logs** for anomalies
4. **Test restores** regularly
5. **Keep phpBorg updated** for security patches

### Backup Security

1. **Enable Borg encryption** for sensitive data
2. **Store encryption passphrases** securely (phpBorg encrypts them in DB)
3. **Implement 3-2-1 backup rule** where possible
4. **Verify backup integrity** with regular `borg check`

---

## Threat Model

### Threats Mitigated

| Threat | Mitigation |
|--------|------------|
| Compromised client server | Agent can only send data, cannot delete backups (append-only) |
| Man-in-the-middle | mTLS + SSH encryption |
| Unauthorized access to backups | Per-agent path isolation |
| Brute force attacks | Key-only auth, no passwords |
| Privilege escalation on agent | Minimal sudoers, no shell |
| Ransomware deleting backups | Append-only mode |

### Residual Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Compromised phpBorg server | High | Regular security updates, hardened OS |
| Stolen CA private key | High | HSM or encrypted storage recommended |
| Agent certificate theft | Medium | Certificate revocation, short validity |

---

## Compliance

phpBorg's security architecture supports compliance with:

- **GDPR** - Encryption, access controls, audit logs
- **SOC 2** - Security controls, monitoring
- **ISO 27001** - Information security management
- **HIPAA** - Encryption, access controls (healthcare data)

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2025-11-27 | Initial security architecture with agent model |

