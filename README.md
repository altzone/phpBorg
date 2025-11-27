# phpBorg - Enterprise Backup System

Modern enterprise backup solution based on BorgBackup with web interface, comparable to Veeam, Acronis, and Nakivo.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![Version](https://img.shields.io/badge/version-2.0.0-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg)
![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D.svg)
![Go](https://img.shields.io/badge/Go-1.21-00ADD8.svg)

---

## What's New in v2.0

### Pull-Based Agent Architecture

phpBorg 2.0 introduces a revolutionary **pull-based agent architecture** for secure communication with remote servers:

- **No inbound connections required** - Agents initiate all connections
- **Firewall-friendly** - Works behind NAT, corporate firewalls
- **mTLS authentication** - Mutual TLS with automatic certificate management
- **Auto-renewal** - Certificates renew automatically before expiration
- **Zero configuration** - Security is completely transparent to users

```
Traditional (SSH Push):          phpBorg 2.0 (Agent Pull):
┌────────┐    SSH    ┌────────┐  ┌────────┐   HTTPS   ┌────────┐
│ Server │ ───────>  │ Client │  │ Server │ <───────  │ Agent  │
└────────┘  Inbound  └────────┘  └────────┘ Outbound  └────────┘
            Required              Only!
```

---

## Features

### Backup & Recovery
- **Multiple Backup Types**: Files, MySQL/MariaDB, PostgreSQL, MongoDB, Docker containers
- **Atomic Snapshots**: LVM snapshot support for consistent database backups
- **Compression & Deduplication**: BorgBackup with advanced compression
- **Incremental Backups**: Fast incremental backups with block-level deduplication
- **Scheduled Backups**: Flexible cron-based scheduling
- **Retention Policies**: Automatic pruning based on age/count

### Instant Recovery
- **Zero-Copy Recovery**: Mount backups instantly without data copy (FUSE)
- **Database Instant Recovery**: PostgreSQL/MySQL read-only access via Docker
- **One-Click Adminer**: Integrated web-based database browser
- **Remote Deployment**: Deploy instant recovery to source or backup server

### Agent System (New in v2.0)
- **Pull-Based Communication**: Agents poll server, no inbound ports needed
- **mTLS Authentication**: Automatic certificate generation and renewal
- **Self-Updating Agent**: Binary updates pushed from server
- **Cross-Platform**: Go-based agent for Linux servers
- **Easy Deployment**: One-liner installation script

### Monitoring & Management
- **Real-Time Dashboard**: Live statistics with SSE (Server-Sent Events)
- **Worker Pool**: Parallel job processing with 4 workers
- **Job Queue**: Redis-based asynchronous job system
- **System Metrics**: CPU, RAM, Disk, Network monitoring per server
- **Email Notifications**: Customizable alerts for backup events
- **Agent Health**: Monitor all agents from central dashboard

### Modern Web Interface
- **Vue.js 3 SPA**: Responsive, modern UI with Composition API
- **Dark Mode**: Built-in dark theme
- **Internationalization**: English, French, and German (i18n ready)
- **Real-Time Updates**: SSE with automatic polling fallback
- **Wizards**: Step-by-step server and backup configuration

### Security
- **mTLS Agent Authentication**: Certificate-based mutual TLS
- **Automatic Certificate Renewal**: 30 days before expiration
- **Role-Based Access Control (RBAC)**: Admin and user roles
- **JWT Authentication**: Access and refresh tokens
- **Append-Only Borg Mode**: Ransomware protection for backups
- **Dedicated Borg SSH Server**: Isolated SSH daemon on port 2222

---

## Quick Installation

### One-Line Install (Ubuntu 22.04/24.04, Debian 12)

```bash
curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/master/install.sh | sudo bash
```

The installer will:
1. Install all dependencies (PHP, Go, Node.js, MariaDB, Redis, Docker, Borg)
2. Setup database and create admin user
3. Configure web server (Nginx)
4. Build frontend and compile Go agent
5. Generate Certificate Authority for mTLS
6. Setup dedicated Borg SSH server
7. Start all services

**Installation time**: 10-20 minutes

### Default Credentials

After installation, access the web interface at `http://your-server-ip`:
- **Username**: `admin`
- **Password**: *(displayed at end of installation)*

---

## Adding Remote Servers

phpBorg offers 3 methods to add servers, all deploying the phpborg-agent:

### Method 1: One-Liner Script (Recommended)

1. Go to **Servers > Add Server**
2. Enter server details
3. Select **"Curl Script"** method
4. Copy the generated command
5. Run on target server:

```bash
curl -sSL 'http://phpborg-server/api/server-wizard/install-script/TOKEN' | sudo bash
```

The script automatically:
- Installs BorgBackup and dependencies
- Creates phpborg-agent user
- Generates SSH keys for Borg access
- Downloads and installs the agent binary
- Configures mTLS certificates
- Starts the agent service

### Method 2: SSH Password (Automatic)

1. Enter server details with SSH password
2. phpBorg connects and installs everything automatically
3. Password used once, never stored

### Method 3: Manual SSH Key

1. Copy phpBorg's SSH public key
2. Add to target server's authorized_keys
3. Complete wizard

---

## Architecture

### Components Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                      phpBorg Server                              │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐ │
│  │ Web UI   │  │ REST API │  │ Workers  │  │ Borg SSH Server  │ │
│  │ (Vue.js) │  │  (PHP)   │  │ (4 pool) │  │   (Port 2222)    │ │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘ │
│                      │                              ▲            │
│                      │ mTLS                         │ SSH        │
│                      ▼                              │            │
├─────────────────────────────────────────────────────────────────┤
│                   Certificate Authority                          │
│              (Auto-generated, 10-year validity)                  │
└─────────────────────────────────────────────────────────────────┘
                       ▲
                       │ HTTPS (Pull)
                       │
┌──────────────────────┴──────────────────────┐
│              phpborg-agent (Go)              │
├─────────────────────────────────────────────┤
│  • Polls server for tasks every 5s          │
│  • Sends heartbeat every 60s                │
│  • Executes backups via local Borg          │
│  • mTLS auth with auto-renewal              │
│  • Self-updating binary                     │
└─────────────────────────────────────────────┘
```

### Backend (PHP 8.3+)
- Custom lightweight framework
- MariaDB with repository pattern
- Redis job queue with worker pool
- Certificate Manager for mTLS
- Agent Manager for SSH keys

### Agent (Go 1.21+)
- Lightweight daemon (~10MB binary)
- mTLS client authentication
- Task execution (backup, restore, capabilities)
- Automatic certificate renewal
- Self-update mechanism

### Frontend (Vue.js 3)
- Composition API with Pinia stores
- TailwindCSS for styling
- Vue Router for SPA navigation
- SSE for real-time updates

---

## Security Model

### mTLS Authentication Flow

```
1. Agent Registration:
   ┌─────────┐  Token   ┌─────────┐
   │  Agent  │ ───────> │ Server  │
   └─────────┘          └────┬────┘
                             │ Generate cert
                             ▼
   ┌─────────┐  Cert    ┌─────────┐
   │  Agent  │ <─────── │   CA    │
   └─────────┘  + Key   └─────────┘

2. All Future Requests:
   ┌─────────┐  mTLS    ┌─────────┐
   │  Agent  │ <──────> │ Server  │
   └─────────┘          └─────────┘
```

### Certificate Lifecycle

| Certificate | Validity | Renewal |
|-------------|----------|---------|
| CA Root | 10 years | Manual |
| Agent Cert | 1 year | Auto (30 days before expiry) |

### Borg SSH Security

Each agent gets restricted SSH access:
```bash
command="borg serve --restrict-to-path /opt/backups/AGENT_UUID --append-only",
restrict ssh-ed25519 AAAA... phpborg-agent-UUID
```

- **Path restriction**: Agent can only access its backup directory
- **Append-only mode**: Cannot delete existing backups (ransomware protection)
- **No shell access**: Only `borg serve` command allowed

---

## Requirements

### phpBorg Server
- **OS**: Ubuntu 22.04+, Debian 12+, RHEL 9+, Rocky Linux 9+
- **CPU**: 2+ cores (4+ recommended)
- **RAM**: 2GB (4GB+ recommended)
- **Disk**: 10GB free (+ backup storage)

### Agent (Remote Servers)
- **OS**: Any Linux with systemd
- **Disk**: 50MB for agent + Borg
- **Network**: Outbound HTTPS to phpBorg server

---

## Configuration

### Server Configuration

Main config: `/opt/phpborg/.env`

```env
# Database
DB_HOST=127.0.0.1
DB_NAME=phpborg
DB_USER=phpborg
DB_PASSWORD=xxx

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# JWT
JWT_SECRET=xxx
JWT_ACCESS_TOKEN_TTL=3600

# Borg
BORG_PASSPHRASE=xxx
BORG_SSH_PORT=2222
```

### Agent Configuration

Agent config: `/etc/phpborg-agent/config.yaml`

```yaml
server:
  url: https://phpborg-server/api
  insecure_skip_verify: false

agent:
  uuid: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
  name: my-server
  max_concurrent_tasks: 2

tls:
  cert_file: /etc/phpborg-agent/certs/agent.crt
  key_file: /etc/phpborg-agent/certs/agent.key
  ca_file: /etc/phpborg-agent/certs/ca.crt

polling:
  interval: 5s
  heartbeat_interval: 60s
```

---

## Service Management

### Server Services

```bash
# phpBorg services
sudo systemctl status phpborg-scheduler
sudo systemctl status phpborg-workers.target

# View logs
sudo journalctl -u phpborg-scheduler -f
sudo journalctl -u phpborg-worker@1 -f

# Borg SSH server
sudo systemctl status phpborg-borg-sshd
```

### Agent Service

```bash
# On remote servers
sudo systemctl status phpborg-agent
sudo journalctl -u phpborg-agent -f
sudo systemctl restart phpborg-agent
```

---

## API Reference

### Agent Gateway Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/agent/register` | Register new agent |
| POST | `/api/agent/heartbeat` | Agent heartbeat |
| GET | `/api/agent/tasks` | Poll for tasks |
| POST | `/api/agent/tasks/{id}/start` | Mark task started |
| POST | `/api/agent/tasks/{id}/complete` | Mark task completed |
| POST | `/api/agent/certificate/renew` | Renew mTLS certificate |
| POST | `/api/agent/update/check` | Check for updates |
| GET | `/api/agent/update/download` | Download agent binary |

---

## Troubleshooting

### Agent Won't Connect

```bash
# Check agent status
sudo systemctl status phpborg-agent
sudo journalctl -u phpborg-agent -f

# Verify certificates
ls -la /etc/phpborg-agent/certs/
openssl x509 -in /etc/phpborg-agent/certs/agent.crt -noout -dates

# Test connectivity
curl -v https://phpborg-server/api/health
```

### Certificate Issues

```bash
# Check CA on server
ls -la /etc/phpborg/certs/
openssl x509 -in /etc/phpborg/certs/ca.crt -noout -text

# Force certificate renewal (agent side)
sudo systemctl restart phpborg-agent
```

### Backup Failures

```bash
# Check Borg SSH access
ssh -p 2222 -i /etc/phpborg-agent/.ssh/id_ed25519 phpborg-borg@server "borg info"

# Check agent logs
sudo journalctl -u phpborg-agent --since "1 hour ago"
```

---

## Development

### Requirements
- PHP 8.3+
- Go 1.21+
- Node.js 20+
- MariaDB 10.11+
- Redis 7+
- Docker 24+

### Build Agent

```bash
cd agent
go mod tidy
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o phpborg-agent ./cmd/phpborg-agent
```

### Build Frontend

```bash
cd frontend
npm install
npm run build
```

---

## Contributing

Contributions are welcome! Areas for contribution:
- Additional database support (Oracle, SQL Server)
- More backup sources (VMware, Proxmox)
- Cloud storage backends (S3, Azure, GCS)
- Windows agent support
- Additional translations

---

## License

MIT License - see [LICENSE](LICENSE) file for details.

---

## Credits

Built with:
- [BorgBackup](https://www.borgbackup.org/) - Deduplicating backup program
- [Vue.js](https://vuejs.org/) - Progressive JavaScript framework
- [Go](https://go.dev/) - Agent runtime
- [TailwindCSS](https://tailwindcss.com/) - Utility-first CSS framework
- [MariaDB](https://mariadb.org/) - Open source database
- [Redis](https://redis.io/) - In-memory data structure store

---

## Roadmap

### v2.1 (Planned)
- [ ] VMware/Proxmox VM backup
- [ ] Cloud storage backends (S3, Azure, GCS)
- [ ] Windows agent support
- [ ] Advanced reporting and dashboards

### v2.2 (Planned)
- [ ] Multi-tenancy support
- [ ] Backup replication between sites
- [ ] Mobile app (iOS/Android)
- [ ] API documentation (OpenAPI/Swagger)

---

**Made with love by the phpBorg team**

Star us on GitHub if you find this project useful!
