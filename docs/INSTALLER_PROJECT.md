# 🚀 phpBorg Universal Installer - Project Documentation

## 📋 Vue d'ensemble

Installeur professionnel multi-distribution pour phpBorg v1.0, conçu pour être **idempotent**, **intelligent** et **user-friendly**.

**Objectifs** :
- ✅ Installation automatique (mode auto) ou guidée (mode interactif)
- ✅ Support multi-distro (Debian, Ubuntu, RHEL, CentOS, Fedora, Arch, Alpine)
- ✅ Idempotence complète (peut être re-exécuté sans risque)
- ✅ Détection intelligente de l'environnement existant
- ✅ Rollback automatique en cas d'erreur
- ✅ Logging détaillé et state tracking

---

## 🏗️ Architecture

### Structure des fichiers

```
/opt/newphpborg/phpBorg/
├── install.sh                          # Script principal (entry point)
│
├── install/
│   ├── lib/                            # Modules réutilisables
│   │   ├── common.sh                   # ✅ Fonctions communes
│   │   ├── detect.sh                   # ✅ Détection OS/services
│   │   ├── deps.sh                     # ⏳ Installation dépendances
│   │   ├── database.sh                 # ⏳ Setup base de données
│   │   ├── services.sh                 # ⏳ Systemd services
│   │   ├── docker.sh                   # ⏳ Docker setup
│   │   ├── frontend.sh                 # ⏳ Frontend build
│   │   └── security.sh                 # ⏳ Security hardening
│   │
│   ├── configs/                        # Templates de configuration
│   │   ├── .env.template               # Environment variables
│   │   ├── nginx.conf.template         # Nginx vhost
│   │   ├── apache.conf.template        # Apache vhost
│   │   └── sudoers/                    # Sudoers rules
│   │       ├── phpborg-workers         # Workers management
│   │       └── phpborg-backup-server   # Backup operations
│   │
│   └── sql/                            # Database initialization
│       ├── schema.sql                  # Complete schema (no data)
│       └── initial_data.sql            # Admin user only
│
└── docs/
    └── INSTALLER_PROJECT.md            # This file
```

---

## 📦 Modules Détaillés

### ✅ 1. common.sh (Fonctions Communes)

**Status** : ✅ Complété (350 lignes)

**Fonctionnalités** :
- 🎨 **Colors & Output** : Codes couleurs, emojis, formatage
- 📝 **Logging** : Multi-niveau (INFO, SUCCESS, WARN, ERROR, DEBUG)
- ❓ **User Prompts** : Questions interactives avec defaults
- 💾 **State Management** : Tracking JSON pour idempotence
- 🔄 **Backup & Rollback** : Sauvegarde automatique avant modifications
- 🏃 **Command Execution** : Wrapper avec logging et error handling
- 🔧 **Service Management** : Vérification status systemd
- 🎯 **Progress Display** : Spinner, progress bar
- 📊 **System Info** : CPU, RAM, Disk space helpers

**Variables Exportées** :
```bash
INSTALL_LOG="/var/log/phpborg-install.log"
INSTALL_STATE="/tmp/phpborg-install-state.json"
INSTALL_BACKUP_DIR="/tmp/phpborg-install-backup-{timestamp}"
INSTALL_MODE="interactive|auto"
PHPBORG_ROOT="/opt/newphpborg/phpBorg"
```

**Fonctions Clés** :
```bash
log_info "message"          # Log informatif
log_success "message"        # Log succès
log_warn "message"           # Log warning
log_error "message"          # Log erreur
error_exit "message"         # Log error + exit

prompt "question" "default" VAR_NAME
prompt_password "question" VAR_NAME
confirm "question?" "y"      # Returns 0 if yes

save_state "step_name" "completed" "{json}"
get_state "step_name"        # Returns: not_started|running|completed|failed
is_step_completed "step"     # Returns 0 if completed

backup_file "/path/to/file"
backup_dir "/path/to/dir"

run_cmd "command"            # Execute with logging
run_cmd_silent "command"     # Execute silently

is_service_running "service"
is_service_enabled "service"

version_ge "1.2.3" "1.2.0"   # Returns 0 if $1 >= $2

spinner $PID "message"       # Show spinner while process runs
progress_bar 50 100          # Show progress bar

print_header "Title"         # Beautiful header
print_section "Section"      # Section separator
```

**Gestion d'Erreurs** :
- Tous les échecs loggés dans `/var/log/phpborg-install.log`
- Backup automatique avant modifications
- Trap EXIT pour cleanup
- State tracking pour reprendre après échec

---

### ✅ 2. detect.sh (Détection OS/Services)

**Status** : ✅ Complété (420 lignes)

**Fonctionnalités** :
- 🐧 **OS Detection** : Identification distro/version/codename
- 📦 **Package Manager** : Détection apt/yum/dnf/pacman/apk
- ⚙️ **Service Manager** : Detection systemd/openrc/init
- ✅ **System Requirements** : Validation CPU/RAM/Disk/Network
- 🔍 **Service Detection** : Tous les services requis
- 🌐 **Webserver Selection** : Nginx vs Apache (auto ou interactif)

**Variables Exportées** :
```bash
OS_TYPE="linux"
OS_DISTRO="debian|ubuntu|rhel|centos|fedora|arch|alpine"
OS_VERSION="12.0"
OS_CODENAME="bookworm"

PKG_MANAGER="apt|yum|dnf|pacman|apk"
PKG_UPDATE_CMD="apt-get update"
PKG_INSTALL_CMD="apt-get install -y"

SERVICE_MANAGER="systemd|openrc|init"

PHP_INSTALLED=0|1
PHP_VERSION="8.2.0"
PHP_BINARY="/usr/bin/php"

COMPOSER_INSTALLED=0|1
COMPOSER_VERSION="2.6.0"

NODEJS_INSTALLED=0|1
NODEJS_VERSION="20.0.0"

NPM_INSTALLED=0|1
DOCKER_INSTALLED=0|1
MYSQL_INSTALLED=0|1
REDIS_INSTALLED=0|1
NGINX_INSTALLED=0|1
APACHE_INSTALLED=0|1
BORG_INSTALLED=0|1
FUSE_OVERLAYFS_INSTALLED=0|1

WEBSERVER="nginx|apache"
```

**Fonctions Clés** :
```bash
detect_os()                  # Détecte OS + package manager
check_system_requirements()  # Vérifie CPU/RAM/Disk/Network

detect_php()                 # Détecte PHP (retourne 0 si installé)
detect_composer()
detect_nodejs()
detect_npm()
detect_docker()
detect_mysql()
detect_redis()
detect_nginx()
detect_apache()
detect_borgbackup()
detect_fuse_overlayfs()

detect_all_services()        # Détecte tous les services
select_webserver()           # Sélection Nginx vs Apache
```

**System Requirements** :
- ✅ Root user obligatoire
- ✅ Min 2 CPU cores (warning si moins)
- ✅ Min 2GB RAM (error si moins)
- ✅ Min 10GB disk space (error si moins)
- ✅ Connectivité internet (ping 8.8.8.8)

**Supported Distributions** :
```
✅ Debian 11, 12+
✅ Ubuntu 20.04, 22.04, 24.04
✅ RHEL 8, 9
✅ CentOS Stream 8, 9
✅ Rocky Linux 8, 9
✅ AlmaLinux 8, 9
✅ Fedora 38, 39, 40
✅ Arch Linux (latest)
✅ Alpine Linux 3.18+
```

---

### ⏳ 3. deps.sh (Installation Dépendances)

**Status** : ⏳ À créer (~600 lignes estimées)

**Fonctionnalités Prévues** :
- 📦 **PHP 8.1+** avec extensions (mysqli, pdo_mysql, redis, ssh2, curl, mbstring, xml, zip, gd)
- 🎼 **Composer** (latest stable)
- 📗 **Node.js 18+** + npm
- 🐳 **Docker** + Docker Compose
- 🗄️ **MariaDB/MySQL** (si pas déjà installé)
- 🔴 **Redis** (si pas déjà installé)
- 🌐 **Nginx/Apache** (selon sélection)
- 💾 **BorgBackup** (latest stable)
- 🔗 **fuse-overlayfs** (pour Instant Recovery)
- 🛠️ **Autres** : git, curl, wget, unzip, jq, openssl

**Fonctions Prévues** :
```bash
install_system_packages()    # Packages système de base
install_php()                # PHP + extensions
install_composer()           # Composer global
install_nodejs()             # Node.js + npm (via NodeSource)
install_docker()             # Docker + Docker Compose
install_mysql()              # MariaDB/MySQL server
install_redis()              # Redis server
install_webserver()          # Nginx ou Apache
install_borgbackup()         # BorgBackup
install_fuse_overlayfs()     # fuse-overlayfs
verify_dependencies()        # Vérification post-install
```

**Stratégie d'Installation** :
1. Update package cache
2. Install système packages (git, curl, etc.)
3. Add external repos si nécessaire (NodeSource, Docker, etc.)
4. Install chaque service avec vérification
5. Configuration minimale (start services)
6. Validation finale

**Idempotence** :
- Skip si déjà installé et version OK
- Upgrade si version trop ancienne (avec confirmation)
- Backup configs avant modifications

---

### ⏳ 4. database.sh (Setup Base de Données)

**Status** : ⏳ À créer (~400 lignes estimées)

**Fonctionnalités Prévues** :
- 🗄️ **Database Creation** : `phpborg_new`
- 👤 **User Creation** : `phpborg_new` avec password
- 📋 **Schema Import** : schema.sql complet (sans data)
- 🔐 **Admin User** : Création user admin par défaut
- ✅ **Validation** : Test connection + tables existantes
- 🔄 **Idempotence** : Skip si DB existe déjà

**Fonctions Prévues** :
```bash
generate_db_password()       # Génère mot de passe sécurisé
test_mysql_connection()      # Test connexion root
create_database()            # Crée database phpborg_new
create_database_user()       # Crée user phpborg_new
import_schema()              # Import schema.sql
create_admin_user()          # Crée admin user
verify_database()            # Vérifie tables + données
```

**Processus** :
1. Prompt/generate DB credentials
2. Test connexion MySQL root
3. Créer database (si pas existe)
4. Créer user (si pas existe)
5. Grant permissions
6. Import schema (idempotent - skip si tables existent)
7. Créer admin user (prompt username/password en interactif)
8. Vérification finale

**Schema SQL** :
- Export complet depuis DB actuelle
- Sans données (structure only)
- Avec contraintes, indexes, foreign keys
- Tables principales : users, servers, backup_jobs, archives, etc.

**Admin User** :
- Mode auto : `admin` / `random_password`
- Mode interactif : prompt username + password
- Stored hashed (password_hash PHP)
- Role : ROLE_ADMIN

---

### ⏳ 5. services.sh (Systemd Services)

**Status** : ⏳ À créer (~350 lignes estimées)

**Fonctionnalités Prévues** :
- 📋 **Service Files** : Copie vers `/etc/systemd/system/`
- 🔧 **Sudoers Rules** : Installation dans `/etc/sudoers.d/`
- 🔄 **Daemon Reload** : Recharge systemd
- ▶️ **Enable & Start** : Activation automatique
- ✅ **Validation** : Vérification status

**Services à Installer** :
```
phpborg-scheduler.service       # Scheduler daemon
phpborg-worker@.service         # Worker pool template
phpborg-workers.target          # Worker group target
```

**Sudoers Files** :
```
/etc/sudoers.d/phpborg-workers          # Workers management
/etc/sudoers.d/phpborg-backup-server    # Backup operations (Instant Recovery)
```

**Fonctions Prévues** :
```bash
install_systemd_services()   # Copie services + reload
install_sudoers_rules()      # Install sudoers + validation
start_services()             # Enable + start services
verify_services()            # Check all services running
```

**Worker Pool Configuration** :
- Prompt nombre de workers (default: 4)
- Validation min 1, max 16
- Start workers @1, @2, @3, @4, etc.

---

### ⏳ 6. docker.sh (Docker Setup)

**Status** : ⏳ À créer (~200 lignes estimées)

**Fonctionnalités Prévues** :
- 🐳 **Docker Install** : Si pas déjà installé
- 🎨 **Adminer Image** : Build `phpborg/adminer:latest`
- 🌐 **Network Setup** : Bridge network si nécessaire
- 👥 **User Groups** : Ajouter phpborg user au groupe docker
- ✅ **Validation** : Test `docker run hello-world`

**Fonctions Prévues** :
```bash
install_docker_repo()        # Add Docker official repo
install_docker_engine()      # Install Docker + Docker Compose
configure_docker()           # Add user to docker group, etc.
build_adminer_image()        # Build phpborg/adminer:latest
verify_docker()              # Test docker run
```

**Adminer Image** :
- Build depuis `docker/adminer/`
- Vérification image créée
- Test container start (sanity check)

---

### ⏳ 7. frontend.sh (Frontend Build)

**Status** : ⏳ À créer (~250 lignes estimées)

**Fonctionnalités Prévues** :
- 📦 **npm install** : Installation dépendances
- 🏗️ **npm run build** : Build production
- 📁 **Deploy** : Copie dist/ vers public/ (ou config webserver)
- ✅ **Validation** : Vérification fichiers générés

**Fonctions Prévues** :
```bash
install_frontend_deps()      # npm install dans frontend/
build_frontend()             # npm run build
deploy_frontend()            # Copie vers destination
verify_frontend()            # Check fichiers existent
```

**Processus** :
1. `cd frontend/`
2. `npm install` (avec spinner + progress)
3. `npm run build` (peut prendre 1-2 minutes)
4. Vérifier `dist/` généré
5. Copier vers destination selon webserver

---

### ⏳ 8. security.sh (Security Hardening)

**Status** : ⏳ À créer (~300 lignes estimées)

**Fonctionnalités Prévues** :
- 🔐 **Generate Secrets** : APP_KEY, JWT secrets
- 🔑 **SSH Keys** : Génération clés backup si besoin
- 📝 **File Permissions** : Chmod/chown correct
- 🛡️ **SELinux/AppArmor** : Disable temporairement ou config
- 🔥 **Firewall** : Optionnel (prompt user)
- ✅ **Audit Final** : Check permissions

**Fonctions Prévues** :
```bash
generate_app_key()           # Génère APP_KEY (32 bytes base64)
generate_jwt_secrets()       # Génère JWT_SECRET + JWT_REFRESH_SECRET
generate_ssh_keys()          # Génère SSH keys pour backups
set_file_permissions()       # Chmod/chown récursif
configure_selinux()          # SELinux config si actif
configure_firewall()         # ufw/firewalld optionnel
security_audit()             # Audit final
```

**Permissions** :
```bash
/opt/newphpborg/phpBorg/           → phpborg:phpborg 755
/opt/newphpborg/phpBorg/.env       → phpborg:phpborg 600
/opt/newphpborg/phpBorg/var/       → phpborg:phpborg 770
/opt/backups/                      → phpborg:phpborg 750
/var/log/phpborg_new.log           → phpborg:phpborg 644
```

**Firewall (Optionnel)** :
- Port 80 (HTTP)
- Port 443 (HTTPS) si SSL configuré
- Port 8080 (API)
- Ports 30000-40000 (Adminer Instant Recovery)

---

### ⏳ 9. install.sh (Script Principal)

**Status** : ⏳ À créer (~500 lignes estimées)

**Rôle** : Orchestrateur principal qui appelle tous les modules dans l'ordre

**Usage** :
```bash
# Mode interactif (défaut)
sudo ./install.sh

# Mode automatique (unattended)
sudo ./install.sh --auto

# Mode debug
sudo DEBUG=1 ./install.sh

# Réinstallation (skip checks)
sudo ./install.sh --force

# Dry run (simulation)
sudo ./install.sh --dry-run
```

**Flux d'Exécution** :
```
1. Parse CLI arguments
2. Initialize logging
3. Print welcome banner
4. Load all lib modules
5. Detect OS & services (detect.sh)
6. Check system requirements
7. Display installation plan
8. Confirm (if interactive)
9. Install dependencies (deps.sh)
10. Setup database (database.sh)
11. Configure environment (.env)
12. Setup Docker (docker.sh)
13. Build frontend (frontend.sh)
14. Install systemd services (services.sh)
15. Configure webserver
16. Security hardening (security.sh)
17. Start all services
18. Run health checks
19. Display final report
20. Show credentials & access URL
```

**CLI Arguments** :
```
--auto              Mode automatique (pas de prompts)
--force             Force réinstallation
--dry-run           Simulation (pas d'installation)
--skip-deps         Skip installation dépendances
--skip-frontend     Skip build frontend
--skip-docker       Skip Docker setup
--db-password=XXX   Password DB (pour mode auto)
--admin-password=X  Password admin (pour mode auto)
--webserver=nginx   Force webserver
--workers=4         Nombre de workers
--help              Affiche aide
```

**State Tracking** :
- Chaque étape sauvée dans JSON
- Reprend automatiquement après erreur
- Affiche progression avec progress bar

**Final Report** :
```
✓ Installation completed successfully!

Access URLs:
  Dashboard:  http://your-server-ip/
  API:        http://your-server-ip:8080/api/

Credentials:
  Admin User:     admin
  Admin Password: [generated_password]
  Database:       phpborg_new
  DB User:        phpborg_new
  DB Password:    [generated_password]

Services Status:
  ✓ phpborg-scheduler      active
  ✓ phpborg-worker@1       active
  ✓ phpborg-worker@2       active
  ✓ phpborg-worker@3       active
  ✓ phpborg-worker@4       active
  ✓ nginx                  active
  ✓ mysql                  active
  ✓ redis                  active

Next Steps:
  1. Save credentials in a secure location
  2. Change default admin password
  3. Configure first backup server
  4. Setup storage pools

Documentation: /opt/newphpborg/phpBorg/docs/
Logs: /var/log/phpborg-install.log
```

---

## 📋 Templates de Configuration

### .env.template

Variables d'environnement avec placeholders :
```ini
# Application
APP_ENV={{APP_ENV}}
APP_DEBUG={{APP_DEBUG}}
APP_KEY={{APP_KEY}}

# Database
DB_HOST={{DB_HOST}}
DB_PORT={{DB_PORT}}
DB_NAME={{DB_NAME}}
DB_USER={{DB_USER}}
DB_PASSWORD={{DB_PASSWORD}}

# Redis
REDIS_HOST={{REDIS_HOST}}
REDIS_PORT={{REDIS_PORT}}
REDIS_PASSWORD={{REDIS_PASSWORD}}

# JWT
JWT_SECRET={{JWT_SECRET}}
JWT_REFRESH_SECRET={{JWT_REFRESH_SECRET}}
JWT_ACCESS_EXPIRY={{JWT_ACCESS_EXPIRY}}
JWT_REFRESH_EXPIRY={{JWT_REFRESH_EXPIRY}}

# Storage
STORAGE_PATH={{STORAGE_PATH}}
BACKUP_PATH={{BACKUP_PATH}}

# Workers
WORKER_COUNT={{WORKER_COUNT}}
```

### nginx.conf.template

Vhost Nginx avec placeholders :
```nginx
server {
    listen 80;
    server_name {{SERVER_NAME}};
    root {{DOCUMENT_ROOT}};
    index index.html;

    # Frontend
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API
    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    # PHP API backend
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 🗄️ SQL Exports

### schema.sql

Export complet du schéma sans données :
```bash
mysqldump -u root -p \
  --no-data \
  --skip-add-drop-table \
  --skip-comments \
  phpborg_new > install/sql/schema.sql
```

**Contenu** :
- Toutes les tables (structure only)
- Indexes et contraintes
- Foreign keys
- Default values
- Auto-increments

### initial_data.sql

Données minimales :
```sql
-- Admin user (password: changeme)
INSERT INTO users (username, email, password, role, active, created_at)
VALUES (
  'admin',
  'admin@localhost',
  '$2y$10$...',  -- password_hash('changeme')
  'ROLE_ADMIN',
  1,
  NOW()
);

-- Default settings
INSERT INTO settings (category, `key`, value) VALUES
  ('app', 'name', 'phpBorg'),
  ('app', 'timezone', 'UTC'),
  ('backup', 'default_retention', '30'),
  ...
```

---

## 🔄 Idempotence Strategy

**Principe** : Le script peut être exécuté plusieurs fois sans effets secondaires

### State Tracking

Fichier JSON : `/tmp/phpborg-install-state.json`
```json
{
  "os_detection": {
    "status": "completed",
    "timestamp": "2025-11-19T18:00:00Z",
    "data": {"distro": "debian", "version": "12"}
  },
  "system_requirements": {
    "status": "completed",
    "timestamp": "2025-11-19T18:00:05Z"
  },
  "dependencies": {
    "status": "running",
    "timestamp": "2025-11-19T18:00:10Z"
  }
}
```

**Status possibles** :
- `not_started` : Étape pas encore commencée
- `running` : En cours d'exécution
- `completed` : Terminée avec succès
- `failed` : Échec (avec error message)

### Skip Logic

Avant chaque étape :
```bash
if is_step_completed "database_setup"; then
    log_info "Database already configured, skipping..."
    return 0
fi
```

### Backup Before Modify

Toute modification sauvegardée :
```bash
backup_file "/etc/nginx/sites-available/phpborg.conf"
# ... modify file ...
```

En cas d'erreur, restore depuis `/tmp/phpborg-install-backup-{timestamp}/`

---

## 🧪 Testing Strategy

### Manual Testing

Test sur chaque distro supportée :
```bash
# Debian 12
docker run -it debian:12 /bin/bash
curl -sSL https://raw.../install.sh | bash

# Ubuntu 24.04
docker run -it ubuntu:24.04 /bin/bash
curl -sSL https://raw.../install.sh | bash

# CentOS Stream 9
docker run -it quay.io/centos/centos:stream9 /bin/bash
curl -sSL https://raw.../install.sh | bash

# Etc...
```

### Automated Testing

Script de test : `install/test.sh`
```bash
#!/bin/bash
# Test installer on multiple distributions using Docker

DISTROS=(
    "debian:11"
    "debian:12"
    "ubuntu:20.04"
    "ubuntu:22.04"
    "ubuntu:24.04"
    "centos:stream9"
    "fedora:39"
)

for distro in "${DISTROS[@]}"; do
    echo "Testing on ${distro}..."
    docker run --rm -v $(pwd):/phpborg ${distro} \
        /phpborg/install.sh --auto --dry-run
done
```

### Validation Checks

Post-installation :
```bash
# Services running
systemctl is-active phpborg-scheduler
systemctl is-active phpborg-worker@{1..4}

# Database accessible
mysql -u phpborg_new -p phpborg_new -e "SELECT 1"

# API responding
curl http://localhost:8080/api/dashboard/stats

# Frontend accessible
curl http://localhost/

# Docker image exists
docker images | grep phpborg/adminer

# Permissions correct
ls -la /opt/newphpborg/phpBorg/.env  # Should be 600
```

---

## 📊 Estimated Sizes

```
common.sh         : ~350 lines ✅ DONE
detect.sh         : ~420 lines ✅ DONE
deps.sh           : ~600 lines ⏳ TODO
database.sh       : ~400 lines ⏳ TODO
services.sh       : ~350 lines ⏳ TODO
docker.sh         : ~200 lines ⏳ TODO
frontend.sh       : ~250 lines ⏳ TODO
security.sh       : ~300 lines ⏳ TODO
install.sh        : ~500 lines ⏳ TODO
─────────────────────────────────
Total             : ~3370 lines
```

**Templates & SQL** : ~1000 lignes supplémentaires

**Total estimé** : ~4500 lignes de code

---

## 🚀 Roadmap

### Phase 1 : Core Modules (Session Actuelle) ✅
- [x] common.sh - Fonctions communes
- [x] detect.sh - Détection OS/services
- [x] Documentation projet

### Phase 2 : Installation Logic (Prochaine Session)
- [ ] deps.sh - Installation dépendances
- [ ] database.sh - Setup base de données
- [ ] services.sh - Systemd services

### Phase 3 : Build & Deploy
- [ ] docker.sh - Docker setup
- [ ] frontend.sh - Frontend build
- [ ] security.sh - Security hardening

### Phase 4 : Orchestration
- [ ] install.sh - Script principal
- [ ] Templates configuration
- [ ] SQL exports

### Phase 5 : Testing & Polish
- [ ] Tests multi-distro
- [ ] Documentation utilisateur
- [ ] Video tutorial (optionnel)

---

## 📝 Notes Importantes

### Dépendances Système Critiques

**PHP Extensions Required** :
```
php-cli
php-fpm
php-mysql / php-mysqli
php-pdo
php-redis
php-ssh2
php-curl
php-mbstring
php-xml
php-zip
php-gd
php-intl
php-bcmath
```

**System Packages** :
```
git
curl
wget
unzip
jq
openssl
ca-certificates
software-properties-common (Debian/Ubuntu)
epel-release (RHEL/CentOS)
```

### Port Requirements

```
80      HTTP (Nginx/Apache)
443     HTTPS (if SSL configured)
3306    MySQL/MariaDB
6379    Redis
8080    phpBorg API
30000-40000  Adminer (Instant Recovery)
```

### Sudo Requirements

User `phpborg` needs NOPASSWD sudo for :
- systemctl (start/stop/restart workers)
- journalctl (read logs)
- borg (backup operations)
- mount/umount (Instant Recovery)
- docker (Instant Recovery containers)
- chown/chmod (Instant Recovery permissions)

**Fichiers sudoers** :
- `/etc/sudoers.d/phpborg-workers`
- `/etc/sudoers.d/phpborg-backup-server`

---

## 🎯 Success Criteria

Installation réussie si :
- ✅ Tous les services actifs
- ✅ Base de données accessible
- ✅ API répond (200 OK)
- ✅ Frontend chargé
- ✅ Admin user créé
- ✅ Docker fonctionnel
- ✅ Permissions correctes
- ✅ Logs sans erreurs critiques

---

## 📚 Références

- BorgBackup : https://borgbackup.readthedocs.io/
- Docker Install : https://docs.docker.com/engine/install/
- Node.js Install : https://github.com/nodesource/distributions
- PHP FPM : https://www.php.net/manual/en/install.fpm.php
- Systemd Services : https://www.freedesktop.org/software/systemd/man/systemd.service.html
- Nginx Config : https://nginx.org/en/docs/
- MariaDB Install : https://mariadb.org/download/

---

**Date de création** : 2025-11-19
**Auteur** : Claude Code
**Version** : 1.0.0 (Planning Document)
**Status** : 🚧 Work in Progress (Phase 1/5 complétée)
