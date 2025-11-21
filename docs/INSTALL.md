# phpBorg Installation Guide

Complete installation guide for phpBorg v1.0 - Enterprise Backup System

## 📋 Table of Contents

- [System Requirements](#system-requirements)
- [Quick Start (Ubuntu 22.04)](#quick-start-ubuntu-2204)
- [Supported Distributions](#supported-distributions)
- [Installation Modes](#installation-modes)
- [Step-by-Step Installation](#step-by-step-installation)
- [Post-Installation](#post-installation)
- [Troubleshooting](#troubleshooting)
- [Uninstallation](#uninstallation)

---

## 🖥️ System Requirements

### Minimum Requirements
- **CPU**: 2 cores
- **RAM**: 2 GB
- **Disk**: 10 GB free space
- **OS**: Linux (64-bit)
- **Network**: Internet connection

### Recommended Requirements
- **CPU**: 4+ cores
- **RAM**: 4+ GB
- **Disk**: 50+ GB free space (depending on backup storage needs)
- **OS**: Ubuntu 22.04 LTS or Debian 12

### What Will Be Installed

The installer will automatically install and configure:

- **Runtime**: PHP 8.3, Node.js 20, Composer
- **Database**: MariaDB 10.11+
- **Cache**: Redis 7+
- **Container**: Docker 24+
- **Backup**: BorgBackup 1.2+, fuse-overlayfs
- **Web Server**: Nginx (default) or Apache
- **Services**: phpborg-scheduler, phpborg-worker pool (4 workers)

---

## 🚀 Quick Start (Ubuntu 22.04)

### ⚡ One-Line Installation (Recommended)

The fastest way to install phpBorg on a fresh Ubuntu 22.04 server:

```bash
curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/main/bootstrap.sh | sudo bash
```

**With specific branch**:
```bash
export REPO_BRANCH=claude/modernize-backup-app-php-011CUoNEPYmChtfxp7Kuz9yV
curl -fsSL https://raw.githubusercontent.com/altzone/phpBorg/${REPO_BRANCH}/bootstrap.sh | sudo bash
```

**Or download first (more cautious)**:
```bash
wget https://raw.githubusercontent.com/altzone/phpBorg/main/bootstrap.sh
less bootstrap.sh  # Review the script
sudo bash bootstrap.sh
```

The bootstrap script will:
1. ✓ Check system requirements
2. ✓ Install prerequisites (git, curl, wget)
3. ✓ Clone phpBorg repository
4. ✓ Run the main installer automatically

---

### 📖 Manual Installation

If you prefer manual installation or need more control:

```bash
# 1. Update system
sudo apt update && sudo apt upgrade -y

# 2. Install git and basic tools
sudo apt install -y git curl wget

# 3. Clone phpBorg repository
cd /opt
sudo mkdir -p newphpborg
sudo chown $USER:$USER newphpborg
cd newphpborg
git clone https://github.com/altzone/phpBorg.git
cd phpBorg

# 4. Checkout the installer branch (if not merged to main yet)
# git checkout claude/modernize-backup-app-php-011CUoNEPYmChtfxp7Kuz9yV

# 5. Run the installer as root
sudo bash install.sh
```

### Installation Time

- **First-time installation**: 10-20 minutes (depending on internet speed)
- **Re-running after failure**: 2-5 minutes (idempotent, skips completed steps)

### What Happens During Installation

The installer will:

1. ✓ Detect your OS and installed services
2. ✓ Check system requirements
3. ✓ Install all dependencies (PHP, Node.js, MariaDB, Redis, Docker, Borg)
4. ✓ Setup and secure MariaDB
5. ✓ Create database and import schema
6. ✓ Create admin user
7. ✓ Install Composer and npm dependencies
8. ✓ Generate .env configuration with secure secrets
9. ✓ Setup SSH keys for BorgBackup
10. ✓ Install systemd services (scheduler + worker pool)
11. ✓ Configure web server (Nginx or Apache)
12. ✓ Build and deploy Vue.js frontend
13. ✓ Start all services

---

## 🐧 Supported Distributions

The installer supports multiple Linux distributions:

| Distribution | Package Manager | Tested | Notes |
|--------------|----------------|--------|-------|
| **Ubuntu 22.04** | apt | ✅ Yes | Recommended |
| **Ubuntu 24.04** | apt | ✅ Yes | Latest LTS |
| **Debian 12 (Bookworm)** | apt | ✅ Yes | Recommended |
| **Debian 11 (Bullseye)** | apt | ⚠️ Partial | Older packages |
| **RHEL 9** | dnf | 🧪 Untested | Should work |
| **Rocky Linux 9** | dnf | 🧪 Untested | Should work |
| **AlmaLinux 9** | dnf | 🧪 Untested | Should work |
| **Fedora 38+** | dnf | 🧪 Untested | Should work |
| **Arch Linux** | pacman | 🧪 Untested | Experimental |
| **Alpine Linux** | apk | 🧪 Untested | Experimental |

---

## ⚙️ Installation Modes

### Interactive Mode (Default)

The installer will ask questions during installation:

```bash
sudo bash install.sh
```

You'll be prompted for:
- Database name, user, and password
- Admin username, email, and password
- Server domain/IP
- Web server choice (Nginx or Apache)
- Storage pool location
- Service startup confirmation

### Automatic Mode (Silent)

For automated deployments, use automatic mode with environment variables:

```bash
# Set installation mode
export INSTALL_MODE=auto

# Optional: Override defaults
export DB_NAME=phpborg
export DB_USER=phpborg
export DB_PASSWORD=SecurePassword123
export ADMIN_USERNAME=admin
export ADMIN_PASSWORD=AdminPass123
export DOMAIN=backup.example.com
export WEBSERVER=nginx
export STORAGE_POOL_PATH=/backup/storage

# Run installer
sudo -E bash install.sh
```

---

## 📝 Step-by-Step Installation

### 1. Prepare the System

```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install git if not present
sudo apt install -y git

# Optional: Set hostname
sudo hostnamectl set-hostname backup-server
```

### 2. Clone Repository

```bash
# Create installation directory
sudo mkdir -p /opt/newphpborg
sudo chown $USER:$USER /opt/newphpborg

# Clone from GitHub
cd /opt/newphpborg
git clone https://github.com/altzone/phpBorg.git
cd phpBorg

# Verify files
ls -la install/
```

Expected files:
```
install/
├── lib/
│   ├── common.sh        # Common functions
│   ├── detect.sh        # OS detection
│   ├── deps.sh          # Dependencies
│   ├── database.sh      # Database setup
│   ├── application.sh   # App configuration
│   ├── services.sh      # Systemd services
│   ├── webserver.sh     # Web server config
│   └── frontend.sh      # Frontend build
├── templates/
│   ├── .env.template
│   ├── nginx.conf.template
│   └── apache.conf.template
├── schema.sql           # Database schema
└── data.sql             # Initial data (optional)
```

### 3. Run the Installer

```bash
# Make installer executable (should already be)
chmod +x install.sh

# Run as root
sudo bash install.sh
```

### 4. Follow Installation Prompts

In **interactive mode**, you'll see prompts like:

```
? Database name [phpborg_new]: my_backup_db
? Database user [phpborg_new]: backup_user
? Database password: ********
? Admin username [admin]: admin
? Admin email [admin@localhost]: admin@example.com
? Admin password: ********
? Server domain/IP [localhost]: backup.example.com
? Storage pool path [/opt/backups]: /backup/storage
? Start phpBorg services now? [Y/n]: Y
```

### 5. Monitor Installation

The installer displays progress with colored output:

- 🔵 **Info** - General information
- ✅ **Success** - Step completed
- ⚠️ **Warning** - Non-critical issue
- ❌ **Error** - Critical error

All actions are logged to: `/var/log/phpborg-install.log`

### 6. Installation Complete

Upon successful installation, you'll see:

```
═══════════════════════════════════════════════════════════
✓ Installation Complete!
═══════════════════════════════════════════════════════════

Admin Credentials:
────────────────────────────────────────────────────────
Username: admin
Email: admin@example.com
Password: [your-password]

Database: phpborg_new
DB User: phpborg_new
DB Password: [generated-password]
────────────────────────────────────────────────────────

Web Interface:
  • http://localhost

Services:
  • Scheduler: active
  • Workers: active
  • MariaDB: active
  • Redis: active
  • Nginx: active

Useful Commands:
  • Check service status:    systemctl status phpborg-scheduler
  • View logs:               journalctl -u phpborg-scheduler -f
  • Restart workers:         systemctl restart phpborg-workers.target
```

---

## 🎯 Post-Installation

### 1. Save Credentials

**IMPORTANT**: Save your credentials immediately!

```bash
# Credentials are stored in (delete after saving):
cat /opt/newphpborg/phpBorg/.install-credentials

# Delete the file after saving
sudo rm /opt/newphpborg/phpBorg/.install-credentials
```

### 2. Access Web Interface

Open your browser and navigate to:

- **Local**: http://localhost or http://127.0.0.1
- **Remote**: http://your-server-ip or http://your-domain.com

Login with admin credentials.

### 3. Verify Services

```bash
# Check all phpBorg services
sudo systemctl status phpborg-scheduler
sudo systemctl status phpborg-worker@1
sudo systemctl status phpborg-worker@2
sudo systemctl status phpborg-worker@3
sudo systemctl status phpborg-worker@4

# Or use the target
sudo systemctl status phpborg-workers.target

# Check dependencies
sudo systemctl status mariadb
sudo systemctl status redis-server
sudo systemctl status nginx
sudo systemctl status docker
```

### 4. Check Logs

```bash
# Scheduler logs
sudo journalctl -u phpborg-scheduler -f

# Worker logs
sudo journalctl -u phpborg-worker@1 -f

# Application logs
tail -f /opt/newphpborg/phpBorg/var/log/*.log

# Installation log
less /var/log/phpborg-install.log
```

### 5. Configure Your First Backup

1. **Add a Server**:
   - Go to "Servers" → "Add Server"
   - Enter SSH details (hostname, port, credentials)
   - phpBorg will automatically setup SSH keys and install Borg

2. **Create Storage Pool** (if not using default):
   - Go to "Storage" → "Add Pool"
   - Configure path and retention

3. **Create Backup Job**:
   - Go to "Backups" → "Create Backup"
   - Use the wizard to configure:
     - Source server
     - Backup type (files, database, Docker)
     - Schedule (cron expression)
     - Retention policy

4. **Run Your First Backup**:
   - Click "Run Now" on your backup job
   - Monitor progress in real-time

### 6. Configure SSL/TLS (Production)

For production use, configure HTTPS with Let's Encrypt:

```bash
# Install certbot
sudo apt install -y certbot python3-certbot-nginx

# Obtain certificate (Nginx)
sudo certbot --nginx -d backup.example.com

# Or for Apache
sudo certbot --apache -d backup.example.com

# Certbot will automatically configure SSL and enable auto-renewal
```

Then uncomment SSL configuration in:
- Nginx: `/etc/nginx/sites-available/phpborg`
- Apache: `/etc/apache2/sites-available/phpborg.conf`

### 7. Configure Email Notifications (Optional)

Edit `/opt/newphpborg/phpBorg/.env`:

```bash
MAIL_ENABLED=true
MAIL_FROM=phpborg@example.com
MAIL_FROM_NAME=phpBorg Backup System
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=your-smtp-user
SMTP_PASSWORD=your-smtp-password
SMTP_ENCRYPTION=tls
```

Restart services:
```bash
sudo systemctl restart phpborg-scheduler phpborg-workers.target
```

---

## 🔧 Troubleshooting

### Installation Failed

The installer is **idempotent** - safe to re-run:

```bash
# Check the error in the log
tail -50 /var/log/phpborg-install.log

# Fix the issue (e.g., disk space, network)

# Re-run installer (it will skip completed steps)
sudo bash install.sh
```

### State File

Installation progress is tracked in: `/tmp/phpborg-install-state.json`

```bash
# View installation state
cat /tmp/phpborg-install-state.json | jq .

# Reset installation (start from scratch)
sudo rm /tmp/phpborg-install-state.json
sudo bash install.sh
```

### Common Issues

#### Port 80 Already in Use

```bash
# Check what's using port 80
sudo lsof -i :80

# Stop conflicting service
sudo systemctl stop apache2  # or nginx

# Re-run installer
sudo bash install.sh
```

#### Database Connection Failed

```bash
# Check MariaDB status
sudo systemctl status mariadb

# Check credentials
sudo mysql -u root

# Reset MariaDB root password if needed
sudo mysql_secure_installation
```

#### Frontend Build Failed

```bash
# Check Node.js version
node --version  # Should be 18+

# Manually build frontend
cd /opt/newphpborg/phpBorg/frontend
npm install --legacy-peer-deps
npm run build

# Copy to public
cp -r dist/* ../public/
```

#### Services Won't Start

```bash
# Check systemd service status
sudo systemctl status phpborg-scheduler

# View detailed logs
sudo journalctl -u phpborg-scheduler -xe

# Check permissions
ls -la /opt/newphpborg/phpBorg
# Should be owned by phpborg:phpborg

# Fix permissions
sudo chown -R phpborg:phpborg /opt/newphpborg/phpBorg
```

### Restore from Backup

If something goes wrong, restore from backup:

```bash
# Backups are stored in (with timestamp):
ls -la /tmp/phpborg-install-backup-*

# Example: Restore nginx config
sudo cp /tmp/phpborg-install-backup-*/etc/nginx/sites-available/phpborg \
        /etc/nginx/sites-available/phpborg
```

---

## 🗑️ Uninstallation

To completely remove phpBorg:

```bash
# Stop and disable services
sudo systemctl stop phpborg-workers.target phpborg-scheduler
sudo systemctl disable phpborg-workers.target phpborg-scheduler

# Remove systemd services
sudo rm /etc/systemd/system/phpborg-*
sudo systemctl daemon-reload

# Remove web server config
sudo rm /etc/nginx/sites-enabled/phpborg
sudo rm /etc/nginx/sites-available/phpborg
sudo systemctl reload nginx

# Remove database
sudo mysql -e "DROP DATABASE phpborg_new;"
sudo mysql -e "DROP USER 'phpborg_new'@'localhost';"

# Remove user and files
sudo userdel -r phpborg
sudo rm -rf /opt/newphpborg/phpBorg
sudo rm -rf /var/log/phpborg
sudo rm -rf /tmp/phpborg_*

# Remove sudoers
sudo rm /etc/sudoers.d/phpborg-*

# Optional: Remove dependencies (if not used by other apps)
# sudo apt remove --purge php* composer nodejs npm mariadb-server redis-server borgbackup
```

---

## 📚 Additional Resources

- **Documentation**: `/opt/newphpborg/phpBorg/docs/`
- **GitHub**: https://github.com/altzone/phpBorg
- **Issues**: https://github.com/altzone/phpBorg/issues
- **Installation Log**: `/var/log/phpborg-install.log`
- **State File**: `/tmp/phpborg-install-state.json`

---

## 🎉 Next Steps

After successful installation:

1. ✅ Login to web interface
2. ✅ Add your first server
3. ✅ Create a backup job
4. ✅ Run a test backup
5. ✅ Setup email notifications
6. ✅ Configure SSL/TLS
7. ✅ Schedule regular backups

**Enjoy phpBorg!** 🚀

---

## ⚖️ License

phpBorg is open-source software licensed under the MIT license.

## 💬 Support

For help and support:
- GitHub Issues: https://github.com/altzone/phpBorg/issues
- Documentation: `/opt/newphpborg/phpBorg/docs/`

---

*Installation guide for phpBorg v1.0 - Last updated: 2025-11-20*
