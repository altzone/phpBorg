#!/bin/bash
#
# Régénère les fichiers de service systemd pour phpBorg
# Copie les fichiers du repo vers /etc/systemd/system/
# Utilisé lors des mises à jour pour appliquer les changements de configuration
#

set -e

PHPBORG_ROOT="${PHPBORG_ROOT:-/opt/newphpborg/phpBorg}"
SYSTEMD_DIR="${PHPBORG_ROOT}/systemd"
WORKER_POOL_SIZE="${WORKER_POOL_SIZE:-4}"

echo "[INFO] Régénération des services systemd phpBorg..."
echo "[INFO] PHPBORG_ROOT: ${PHPBORG_ROOT}"
echo "[INFO] SYSTEMD_DIR: ${SYSTEMD_DIR}"
echo "[INFO] WORKER_POOL_SIZE: ${WORKER_POOL_SIZE}"

# Vérifier qu'on est root
if [ "$EUID" -ne 0 ]; then
    echo "[ERROR] Ce script doit être exécuté en tant que root"
    exit 1
fi

# Vérifier que le répertoire systemd existe
if [ ! -d "${SYSTEMD_DIR}" ]; then
    echo "[ERROR] Répertoire systemd non trouvé: ${SYSTEMD_DIR}"
    exit 1
fi

# Sauvegarder les anciens fichiers
echo "[INFO] Sauvegarde des anciens fichiers de service..."
timestamp=$(date +%Y%m%d_%H%M%S)
backup_dir="/tmp/phpborg_systemd_backup_${timestamp}"
mkdir -p "${backup_dir}"

for service_file in /etc/systemd/system/phpborg-*.service /etc/systemd/system/phpborg-*.target /etc/systemd/system/phpborg-*.path; do
    if [ -f "${service_file}" ]; then
        cp "${service_file}" "${backup_dir}/"
        echo "[INFO] Sauvegardé: $(basename ${service_file})"
    fi
done

# Copier les fichiers depuis le repo avec remplacement des chemins
echo "[INFO] Copie des fichiers systemd depuis le repo..."
TEMPLATE_PATH="/opt/newphpborg/phpBorg"

for svc_file in "${SYSTEMD_DIR}"/*.service "${SYSTEMD_DIR}"/*.target "${SYSTEMD_DIR}"/*.path; do
    if [ -f "${svc_file}" ]; then
        filename=$(basename "${svc_file}")
        # Copy and replace template path with actual PHPBORG_ROOT
        sed "s|${TEMPLATE_PATH}|${PHPBORG_ROOT}|g" "${svc_file}" > "/etc/systemd/system/${filename}"
        chmod 644 "/etc/systemd/system/${filename}"
        echo "[SUCCESS] Copié: ${filename}"
    fi
done

# Régénérer le target workers avec le nombre de workers configuré
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

echo "[SUCCESS] phpborg-workers.target créé avec ${WORKER_POOL_SIZE} workers"

# Recharger systemd
echo "[INFO] Rechargement de systemd..."
systemctl daemon-reload

echo "[SUCCESS] Services systemd régénérés avec succès !"
echo "[INFO] Sauvegarde des anciens fichiers dans: ${backup_dir}"
echo ""
echo "Pour appliquer les changements, redémarrez les services:"
echo "  sudo systemctl restart phpborg-scheduler"
echo "  sudo systemctl restart phpborg-workers.target"
