# phpBorg - Enterprise Backup System

Modern enterprise backup solution based on BorgBackup with web interface, comparable to Veeam, Acronis, and Nakivo.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg)
![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D.svg)

---

## ✨ Features

### 🔐 Backup & Recovery
- **Multiple Backup Types**: Files, MySQL/MariaDB, PostgreSQL, MongoDB, Docker containers
- **Atomic Snapshots**: LVM snapshot support for consistent database backups
- **Compression & Deduplication**: BorgBackup with advanced compression
- **Incremental Backups**: Fast incremental backups with block-level deduplication
- **Scheduled Backups**: Flexible cron-based scheduling
- **Retention Policies**: Automatic pruning based on age/count

### ⚡ Instant Recovery
- **Zero-Copy Recovery**: Mount backups instantly without data copy (FUSE)
- **Database Instant Recovery**: PostgreSQL/MySQL read-only access via Docker
- **One-Click Adminer**: Integrated web-based database browser
- **Remote Deployment**: Deploy instant recovery to source or backup server

### 📊 Monitoring & Management
- **Real-Time Dashboard**: Live statistics with SSE (Server-Sent Events)
- **Worker Pool**: Parallel job processing with 4 workers
- **Job Queue**: Redis-based asynchronous job system
- **System Metrics**: CPU, RAM, Disk, Network monitoring per server
- **Email Notifications**: Customizable alerts for backup events

### 🌐 Modern Web Interface
- **Vue.js 3 SPA**: Responsive, modern UI with Composition API
- **Dark Mode**: Built-in dark theme
- **Internationalization**: English and French (i18n ready)
- **Real-Time Updates**: SSE with automatic polling fallback
- **Wizards**: Step-by-step backup configuration

### 🔒 Security
- **Role-Based Access Control (RBAC)**: Admin and user roles
- **JWT Authentication**: Access and refresh tokens
- **SSH Key Management**: Automated ED25519 key deployment
- **Encrypted Credentials**: Secure database credential storage
- **Sudoers Configuration**: Minimal privilege escalation

---

## 🚀 Quick Installation

### One-Line Install (Ubuntu 22.04)

```bash
curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/main/bootstrap.sh | sudo bash
```

That's it! The script will:
1. Install all dependencies (PHP, Node.js, MariaDB, Redis, Docker, Borg)
2. Setup database and create admin user
3. Configure web server (Nginx)
4. Build and deploy frontend
5. Start all services

**Installation time**: 10-20 minutes

---

## 📋 Requirements

- **OS**: Ubuntu 22.04+, Debian 12+, RHEL 9+, Rocky Linux 9+, Fedora 38+
- **CPU**: 2+ cores (4+ recommended)
- **RAM**: 2GB (4GB+ recommended)
- **Disk**: 10GB free (50GB+ for backup storage)
- **Network**: Internet connection for installation

---

## 📖 Documentation

- **[Installation Guide](INSTALL.md)** - Complete installation instructions
- **[Installer Project](docs/INSTALLER_PROJECT.md)** - Technical documentation
- **[Project Instructions](CLAUDE.md)** - Development notes and architecture

---

## 🏗️ Architecture

### Backend (PHP 8.3+)
- **Framework**: Custom lightweight framework
- **Database**: MariaDB with repository pattern
- **Job Queue**: Redis with worker pool
- **SSH**: Automated key deployment and management
- **Borg**: BorgExecutor for all backup/restore operations

### Frontend (Vue.js 3)
- **Composition API** with Pinia stores
- **TailwindCSS** for styling
- **Vue Router** for SPA navigation
- **SSE** for real-time updates

### Workers (Systemd)
- **Scheduler**: Lightweight daemon (60s check interval)
- **Worker Pool**: 4 parallel workers via systemd templates
- **Handlers**: BackupCreate, ArchiveDelete, ServerSetup, StatsCollect, InstantRecovery

---

## 🎯 Key Features Explained

### Instant Recovery (Killer Feature)

Zero-copy database recovery comparable to Veeam/Nakivo:

```bash
# User clicks "⚡ Instant Recovery" in web interface
# phpBorg mounts backup via FUSE (no data copy!)
# PostgreSQL/MySQL starts in read-only mode
# Adminer available for instant queries
# All in < 30 seconds, works with TB of data!
```

**Use Cases**:
- Query historical data without impacting production
- Emergency data access during outages
- Test and development on real data
- Backup validation and verification

### Worker Pool Architecture

Professional-grade job processing:

```
SchedulerWorker (singleton)
  ↓ Checks schedules every 60s
  ↓ Collects stats every 15min
  ↓ Pushes jobs to Redis queue

Worker Pool (4 instances)
  ↓ Pop jobs from Redis
  ↓ Execute handlers in parallel
  ↓ Update job status
  ↓ Log to journalctl
```

---

## 🔧 Management

### Service Management

```bash
# Check status
sudo systemctl status phpborg-scheduler
sudo systemctl status phpborg-workers.target

# View logs
sudo journalctl -u phpborg-scheduler -f
sudo journalctl -u phpborg-worker@1 -f

# Restart workers
sudo systemctl restart phpborg-workers.target
```

### Configuration

Main configuration: `/opt/newphpborg/phpBorg/.env`

```env
# Database
DB_HOST=127.0.0.1
DB_NAME=phpborg_new
DB_USER=phpborg_new
DB_PASSWORD=xxx

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# JWT
JWT_SECRET=xxx
JWT_ACCESS_TOKEN_TTL=3600

# Backups
BORG_PASSPHRASE=xxx
BACKUP_RETENTION_DAYS=30
```

---

## 🛠️ Development

### Requirements
- PHP 8.3+
- Composer
- Node.js 20+
- MariaDB 10.11+
- Redis 7+
- Docker 24+

### Setup Development Environment

```bash
# Clone repository
git clone https://github.com/altzone/phpBorg.git
cd phpBorg

# Install backend dependencies
composer install

# Install frontend dependencies
cd frontend
npm install

# Build frontend
npm run build

# Start dev server
npm run dev
```

---

## 📊 Statistics

- **~15,000 lines** of PHP code
- **~5,000 lines** of Vue.js code
- **~2,200 lines** of Bash installer
- **22 database tables**
- **50+ API endpoints**
- **10+ background job handlers**

---

## 🤝 Contributing

Contributions are welcome! Please read our contributing guidelines and submit pull requests.

### Areas for Contribution
- Additional database support (Oracle, SQL Server)
- More backup sources (VMware, Proxmox)
- Cloud storage backends (S3, Azure, GCS)
- Mobile app
- Additional translations

---

## 📄 License

MIT License - see [LICENSE](LICENSE) file for details.

---

## 🙏 Credits

Built with:
- [BorgBackup](https://www.borgbackup.org/) - Deduplicating backup program
- [Vue.js](https://vuejs.org/) - Progressive JavaScript framework
- [TailwindCSS](https://tailwindcss.com/) - Utility-first CSS framework
- [MariaDB](https://mariadb.org/) - Open source database
- [Redis](https://redis.io/) - In-memory data structure store
- [Docker](https://www.docker.com/) - Container platform

---

## 📞 Support

- **Documentation**: [INSTALL.md](INSTALL.md)
- **Issues**: [GitHub Issues](https://github.com/altzone/phpBorg/issues)
- **Discussions**: [GitHub Discussions](https://github.com/altzone/phpBorg/discussions)

---

## 🗺️ Roadmap

### v1.1 (Planned)
- [ ] VMware/Proxmox VM backup
- [ ] Cloud storage backends (S3, Azure, GCS)
- [ ] Multi-tenancy support
- [ ] Advanced reporting and dashboards
- [ ] Backup verification and testing automation

### v1.2 (Planned)
- [ ] Mobile app (iOS/Android)
- [ ] Backup replication between sites
- [ ] Advanced retention policies
- [ ] Backup encryption key management
- [ ] API documentation (OpenAPI/Swagger)

---

**Made with ❤️ by the phpBorg team**

⭐ Star us on GitHub if you find this project useful!
