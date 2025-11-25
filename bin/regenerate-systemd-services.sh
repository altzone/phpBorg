#!/bin/bash
#
# Régénère les fichiers de service systemd pour phpBorg
# Utilisé lors des mises à jour pour appliquer les changements de configuration
#

set -e

PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"
WORKER_POOL_SIZE="${WORKER_POOL_SIZE:-4}"

echo "[INFO] Régénération des services systemd phpBorg..."
echo "[INFO] PHPBORG_ROOT: ${PHPBORG_ROOT}"
echo "[INFO] WORKER_POOL_SIZE: ${WORKER_POOL_SIZE}"

# Vérifier qu'on est root
if [ "$EUID" -ne 0 ]; then
    echo "[ERROR] Ce script doit être exécuté en tant que root"
    exit 1
fi

# Sauvegarder les anciens fichiers
echo "[INFO] Sauvegarde des anciens fichiers de service..."
timestamp=$(date +%Y%m%d_%H%M%S)
backup_dir="/tmp/phpborg_systemd_backup_${timestamp}"
mkdir -p "${backup_dir}"

for service_file in /etc/systemd/system/phpborg-*.service /etc/systemd/system/phpborg-*.target; do
    if [ -f "${service_file}" ]; then
        cp "${service_file}" "${backup_dir}/"
        echo "[INFO] Sauvegardé: $(basename ${service_file})"
    fi
done

# Régénérer le service scheduler
echo "[INFO] Génération de phpborg-scheduler.service..."
cat > /etc/systemd/system/phpborg-scheduler.service <<EOF
[Unit]
Description=phpBorg Scheduler Daemon
Documentation=https://github.com/altzone/phpBorg
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=phpborg
Group=phpborg
WorkingDirectory=${PHPBORG_ROOT}

# Load environment variables from .env
EnvironmentFile=${PHPBORG_ROOT}/.env

# Environment
Environment="PHP_ENV=production"

# Main command
ExecStart=/usr/bin/php8.3 ${PHPBORG_ROOT}/bin/phpborg scheduler:start

# Restart policy
Restart=always
RestartSec=10s

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phpborg-scheduler

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${PHPBORG_ROOT} /opt/backups /var/log/phpborg /tmp

# Resource limits
LimitNOFILE=65536
TimeoutStopSec=30s

[Install]
WantedBy=multi-user.target
EOF

echo "[SUCCESS] phpborg-scheduler.service créé"

# Régénérer le service worker (template)
echo "[INFO] Génération de phpborg-worker@.service..."
cat > /etc/systemd/system/phpborg-worker@.service <<EOF
[Unit]
Description=phpBorg Worker #%i
Documentation=https://github.com/altzone/phpBorg
After=network.target mariadb.service redis.service
PartOf=phpborg-workers.target

[Service]
Type=simple
User=phpborg
Group=phpborg
WorkingDirectory=${PHPBORG_ROOT}

# Load environment variables from .env
EnvironmentFile=${PHPBORG_ROOT}/.env

# Environment
Environment="PHP_ENV=production"
Environment="WORKER_ID=%i"

# Main command
ExecStart=/usr/bin/php8.3 ${PHPBORG_ROOT}/bin/phpborg worker:start --queue=default --worker-id=%i

# Restart policy
Restart=always
RestartSec=5s

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=phpborg-worker@%i

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${PHPBORG_ROOT} /opt/backups /var/log/phpborg /tmp

# Resource limits
LimitNOFILE=65536
TimeoutStopSec=30s

[Install]
WantedBy=phpborg-workers.target
EOF

echo "[SUCCESS] phpborg-worker@.service créé"

# Régénérer le target workers
echo "[INFO] Génération de phpborg-workers.target..."
wants_list=""
for i in $(seq 1 ${WORKER_POOL_SIZE}); do
    wants_list="${wants_list}phpborg-worker@${i}.service "
done

cat > /etc/systemd/system/phpborg-workers.target <<EOF
[Unit]
Description=phpBorg Worker Pool
Documentation=https://github.com/altzone/phpBorg
Wants=${wants_list}

[Install]
WantedBy=multi-user.target
EOF

echo "[SUCCESS] phpborg-workers.target créé"

# Recharger systemd
echo "[INFO] Rechargement de systemd..."
systemctl daemon-reload

echo "[SUCCESS] Services systemd régénérés avec succès !"
echo "[INFO] Sauvegarde des anciens fichiers dans: ${backup_dir}"
echo ""
echo "Pour appliquer les changements, redémarrez les services:"
echo "  sudo systemctl restart phpborg-scheduler"
echo "  sudo systemctl restart phpborg-workers.target"
