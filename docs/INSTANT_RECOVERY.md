# Instant Recovery - Documentation

## 📋 Vue d'ensemble

**Instant Recovery** permet de monter un backup et démarrer une instance de base de données éphémère directement depuis le backup, sans restauration complète. Similaire à Veeam Instant Recovery.

### Cas d'usage

- **Test de backups** : Vérifier l'intégrité sans restauration complète
- **Requêtes ponctuelles** : Extraire des données spécifiques depuis un backup ancien
- **Analyse forensique** : Examiner l'état de la base à un instant T
- **Développement** : Tester avec données de production sans impact

### Fonctionnalités

✅ **PostgreSQL** : Supporté (version 1.0)
⏸️ **MySQL/MariaDB** : À implémenter
⏸️ **MongoDB** : À implémenter

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Borg Archive (backup compressé + dedupliqué)               │
│  borg repository::archive_2024_01_15                        │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ 1. borg mount (FUSE, read-only)
                 ↓
┌─────────────────────────────────────────────────────────────┐
│  /tmp/phpborg_instant_recovery/borg_mount_XXX               │
│  └── var/lib/postgresql/12/main/  (RO)                     │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ 2. mount -t overlay (RW layer on top)
                 ↓
┌─────────────────────────────────────────────────────────────┐
│  OverlayFS Merged View                                      │
│  ├── Lower: backup RO                                       │
│  ├── Upper: /tmp/overlay_upper_XXX (RW changes)            │
│  ├── Work: /tmp/overlay_work_XXX (metadata)                │
│  └── Merged: /tmp/overlay_merged_XXX                       │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ 3. pg_ctl start (custom port)
                 ↓
┌─────────────────────────────────────────────────────────────┐
│  PostgreSQL Instance Éphémère                               │
│  Port: 15432 (configurable)                                 │
│  Socket: /tmp/phpborg_pg_socket_15432/                     │
│  Connection: postgresql://localhost:15432/postgres          │
└─────────────────────────────────────────────────────────────┘
```

### Avantages de cette approche

1. **Zero-copy** : Pas de copie des données (FUSE mount direct)
2. **Copy-on-write** : Seules les modifications sont stockées (OverlayFS)
3. **Isolation** : Port custom, pas d'impact sur instance production
4. **Sécurité** : Backup original reste inaltéré (RO)
5. **Performance** : Démarrage quasi-instantané

## 📦 Installation

### 1. Déploiement du fichier sudoers sur serveurs distants

Les opérations d'Instant Recovery nécessitent des privilèges root sur les serveurs distants :

```bash
# Sur CHAQUE serveur distant où vous voulez utiliser Instant Recovery
sudo cp docs/sudoers-phpborg-instant-recovery /etc/sudoers.d/phpborg-instant-recovery
sudo chmod 440 /etc/sudoers.d/phpborg-instant-recovery
sudo visudo -c  # Vérifier la syntaxe
```

**Note** : Si vous SSH avec un utilisateur autre que `root`, modifiez le fichier pour remplacer `root` par votre utilisateur.

### 2. Vérification des prérequis serveur

Sur chaque serveur distant :

```bash
# Borg doit être installé
which borg
borg --version  # >= 1.2.0 recommandé

# Vérifier support OverlayFS (kernel >= 3.18)
grep overlay /proc/filesystems

# Vérifier PostgreSQL
which pg_ctl
sudo -u postgres pg_ctl --version

# Vérifier accès au repository Borg
export BORG_PASSPHRASE='your_passphrase'
borg list /path/to/repo
```

### 3. Migration base de données

La table `instant_recovery_sessions` doit exister :

```bash
cd /opt/newphpborg/phpBorg
mysql -h 127.0.0.1 -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new < migrations/008_instant_recovery_sessions.sql
```

## 🧪 Tests

### Test script automatique

```bash
# Script de test interactif
php /tmp/test_instant_recovery.php
```

Ce script va :
1. Trouver un backup PostgreSQL existant
2. Démarrer une session Instant Recovery
3. Vérifier le montage Borg et OverlayFS
4. Tester la connexion PostgreSQL
5. Lister les bases de données
6. Proposer d'arrêter ou laisser actif

### Test manuel via API

#### 1. Lister les backups disponibles

```bash
curl -H "Authorization: Bearer YOUR_JWT" \
  http://localhost/api/backups
```

#### 2. Démarrer Instant Recovery

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"archive_id": 123}' \
  http://localhost/api/instant-recovery/start
```

Réponse :
```json
{
  "success": true,
  "session": {
    "id": 1,
    "archive_id": 123,
    "db_type": "postgresql",
    "db_port": 15432,
    "connection_string": "postgresql://localhost:15432/postgres",
    "status": "active",
    "borg_mount_point": "/tmp/phpborg_instant_recovery/borg_mount_XXX",
    "overlay_merged_dir": "/tmp/phpborg_instant_recovery/overlay_merged_XXX"
  }
}
```

#### 3. Se connecter à l'instance

Sur le serveur distant :

```bash
# Via psql
psql -h localhost -p 15432 -U postgres

# Ou via socket
psql -h /tmp/phpborg_pg_socket_15432 -U postgres

# Tester quelques requêtes
SELECT version();
\l  # Lister les bases
\dt # Lister les tables
SELECT * FROM users LIMIT 10;
```

#### 4. Lister les sessions actives

```bash
curl -H "Authorization: Bearer YOUR_JWT" \
  http://localhost/api/instant-recovery/active
```

#### 5. Arrêter la session

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_JWT" \
  http://localhost/api/instant-recovery/stop/1
```

#### 6. Supprimer la session (arrête si active)

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_JWT" \
  http://localhost/api/instant-recovery/1
```

## 🔧 Dépannage

### Erreurs d'Installation/Configuration

#### Erreur : "Class InstantRecoveryManager not found"

**Cause :** Permissions restrictives sur le répertoire ou autoloader non régénéré

**Solution :**
```bash
# Vérifier et corriger les permissions
chmod 755 /opt/newphpborg/phpBorg/src/Service/InstantRecovery
chmod 644 /opt/newphpborg/phpBorg/src/Service/InstantRecovery/*.php

# Régénérer l'autoloader Composer
cd /opt/newphpborg/phpBorg
composer dump-autoload

# Vérifier que la classe est enregistrée
grep "InstantRecoveryManager" vendor/composer/autoload_classmap.php
```

#### Erreur : Sudoers syntax error avec wildcards

**Erreur :**
```
/etc/sudoers.d/phpborg-backup-server:31:74: expected a fully-qualified path name
www-data ALL=(ALL) NOPASSWD: /bin/mount -t overlay overlay -o lowerdir=*,upperdir=...
```

**Cause :** Sudoers n'autorise pas les wildcards dans les options de commandes (`-o lowerdir=*,...`)

**Solution :** Utiliser un wildcard plus large
```bash
# Remplacer cette ligne :
# /bin/mount -t overlay overlay -o lowerdir=*,upperdir=/tmp/...,workdir=/tmp/... /tmp/...

# Par cette ligne :
/bin/mount -t overlay overlay -o * /tmp/phpborg_instant_recovery/*
```

#### Erreur : Bouton Instant Recovery non visible

**Cause :** La table `archives` n'a pas de colonne `type`, donc la détection échoue

**Solution :** Le type est propagé depuis le repository lors du chargement
```javascript
// Dans RestoreWizardView.vue
async function selectRepository(repo) {
  const result = await backupService.list({ repo_id: repo.repo_id })
  // Propager le type du repository vers chaque archive
  archives.value = result.map(archive => ({
    ...archive,
    type: repo.type || archive.type
  }))
}
```

### Erreurs d'Exécution

### Erreur : "Failed to mount Borg archive"

```bash
# Vérifier que Borg peut lister le repository
sudo BORG_PASSPHRASE='password' borg list /path/to/repo

# Vérifier les permissions FUSE
cat /etc/fuse.conf  # user_allow_other doit être décommenté

# Vérifier qu'aucun mount fantôme existe
mount | grep phpborg
sudo borg umount /tmp/phpborg_instant_recovery/*
```

### Erreur : "Failed to mount OverlayFS"

```bash
# Vérifier support kernel
grep overlay /proc/filesystems

# Si absent, charger le module
sudo modprobe overlay

# Vérifier qu'aucun mount existe déjà
mount | grep overlay
sudo umount -f /tmp/phpborg_instant_recovery/overlay_merged_*
```

### Erreur : "Failed to start PostgreSQL"

```bash
# Vérifier les logs PostgreSQL
sudo cat /tmp/pg_instant_15432.log

# Problèmes fréquents :
# 1. Port déjà utilisé
netstat -tlnp | grep 15432
sudo lsof -i :15432

# 2. Permissions socket directory
ls -la /tmp/phpborg_pg_socket_15432/
sudo chown postgres:postgres /tmp/phpborg_pg_socket_15432/
sudo chmod 700 /tmp/phpborg_pg_socket_15432/

# 3. Data directory corrompu ou incompatible
sudo ls -la /tmp/phpborg_instant_recovery/overlay_merged_XXX/
```

### Erreur : "Permission denied" lors du sudo

```bash
# Vérifier que le fichier sudoers est déployé
ls -l /etc/sudoers.d/phpborg-instant-recovery

# Vérifier la syntaxe
sudo visudo -c -f /etc/sudoers.d/phpborg-instant-recovery

# Tester une commande sudo manuellement
sudo borg mount --help
sudo mount -t overlay --help
```

### Nettoyage manuel en cas de problème

```bash
# Arrêter PostgreSQL si encore actif
sudo -u postgres pg_ctl -D /tmp/phpborg_instant_recovery/overlay_merged_XXX stop

# Démonter OverlayFS
sudo umount -f /tmp/phpborg_instant_recovery/overlay_merged_*

# Démonter Borg
sudo borg umount /tmp/phpborg_instant_recovery/borg_mount_*
sudo umount -f /tmp/phpborg_instant_recovery/borg_mount_*

# Nettoyer les répertoires
sudo rm -rf /tmp/phpborg_instant_recovery/*
sudo rm -rf /tmp/phpborg_pg_socket_*

# Nettoyer les entrées DB si nécessaire
mysql -e "UPDATE instant_recovery_sessions SET status='stopped', stopped_at=NOW() WHERE status='active';"
```

## 📊 Monitoring

### Vérifier l'état des sessions actives

```sql
-- Via MySQL
SELECT
    id,
    archive_id,
    db_type,
    db_port,
    status,
    TIMESTAMPDIFF(MINUTE, started_at, NOW()) as uptime_minutes,
    connection_string
FROM instant_recovery_sessions
WHERE status = 'active';
```

### Vérifier les mounts système

```bash
# Sur le serveur distant
mount | grep phpborg
df -h | grep phpborg
```

### Vérifier les processus PostgreSQL

```bash
ps aux | grep postgres | grep instant
sudo netstat -tlnp | grep 1543  # Chercher ports 15432+
```

## 🚀 Intégration Frontend (✅ Implémentée)

### Endpoints API disponibles

- `GET /api/instant-recovery` - Liste toutes les sessions
- `GET /api/instant-recovery/active` - Liste sessions actives
- `GET /api/instant-recovery/:id` - Détails d'une session
- `POST /api/instant-recovery/start` - Démarrer (body: `{archive_id, deployment_location}`)
- `POST /api/instant-recovery/stop/:id` - Arrêter une session
- `DELETE /api/instant-recovery/:id` - Supprimer une session

### Workflow Frontend

L'interface Instant Recovery est intégrée dans le **Restore Wizard** (Vue 3 + Composition API).

#### 1. Service Frontend
Fichier : `/frontend/src/services/instantRecovery.js`

```javascript
export const instantRecoveryService = {
  async list() { /* ... */ },
  async listActive() { /* ... */ },
  async get(id) { /* ... */ },
  async start(archiveId, deploymentLocation) {
    const response = await api.post('/instant-recovery/start', {
      archive_id: archiveId,
      deployment_location: deploymentLocation
    })
    return response.data.data?.session || response.data.session
  },
  async stop(id) { /* ... */ },
  async delete(id) { /* ... */ }
}
```

#### 2. Interface Utilisateur

**Étape 1 : Sélection du Serveur**
- L'utilisateur choisit un serveur dans la liste

**Étape 2 : Sélection du Repository**
- L'utilisateur choisit un repository de type database (PostgreSQL, MySQL, MongoDB)

**Étape 3 : Sélection de l'Archive**
- Affichage de la liste des backups disponibles
- Pour chaque archive de **type database**, un bouton **⚡ Instant Recovery** apparaît
- Le bouton n'apparaît **pas** pour les backups de type "backup" (file-level backups)

**Étape 4 : Modal Deployment Location**

Lorsque l'utilisateur clique sur "⚡ Instant Recovery", une modal s'affiche pour choisir :

**Option 1 : Sur le serveur source (Remote)**
- L'instance sera démarrée sur le serveur d'origine
- Utilise l'environnement de production avec ses configurations
- Tags: "Environnement original", "SSH requis"

**Option 2 : Sur le serveur phpBorg (Local)**
- L'instance sera démarrée sur ce serveur de backup
- Pas de charge sur la production, accès direct au repository
- Tags: "Pas de charge prod", "Accès local direct"

#### 3. Détection Automatique des Bases de Données

Fichier : `/frontend/src/views/RestoreWizardView.vue`

```javascript
// Check if archive is a database type
function isDatabaseArchive(archive) {
  const dbTypes = ['postgresql', 'postgres', 'mysql', 'mariadb', 'mongodb']
  return archive.type && dbTypes.includes(archive.type.toLowerCase())
}
```

Le type est propagé depuis le repository lors de la sélection :

```javascript
async function selectRepository(repo) {
  selectedRepository.value = repo
  currentStep.value = 3
  loading.value = true
  try {
    const result = await backupService.list({ repo_id: repo.repo_id, limit: 100 })
    // Propagate repository type to each archive
    archives.value = result.map(archive => ({
      ...archive,
      type: repo.type || archive.type
    }))
  } finally {
    loading.value = false
  }
}
```

#### 4. Internationalisation (i18n)

**Fichiers :**
- `/frontend/src/i18n/locales/fr.json`
- `/frontend/src/i18n/locales/en.json`

**Clés disponibles :**
```json
{
  "restore_wizard": {
    "instant_recovery": {
      "button": "⚡ Instant Recovery",
      "starting": "Démarrage...",
      "modal_title": "Instant Recovery",
      "modal_description": "Démarrez une instance de base de données éphémère...",
      "deployment_label": "Où déployer l'instance ?",
      "remote_title": "Sur le serveur source ({server})",
      "remote_description": "L'instance sera démarrée sur le serveur d'origine...",
      "local_title": "Sur le serveur phpBorg (local)",
      "local_description": "L'instance sera démarrée sur ce serveur de backup...",
      "success_title": "Instance démarrée !",
      "success_message": "Instance {location} démarrée sur le port {port}...",
      "error_title": "Erreur de démarrage"
    }
  }
}
```

Support complet français et anglais avec messages contextuels.

## 🔧 Modes de Déploiement

### Mode Remote (Serveur Source)

**Configuration requise sur le serveur distant :**

```bash
# Déployer le fichier sudoers spécifique
sudo cp docs/sudoers-phpborg-instant-recovery /etc/sudoers.d/phpborg-instant-recovery
sudo chmod 440 /etc/sudoers.d/phpborg-instant-recovery
sudo visudo -c
```

**Permissions accordées :**
- Montage Borg FUSE : `borg mount`, `borg umount`
- Montage OverlayFS : `mount -t overlay`
- Gestion PostgreSQL : `pg_ctl start/stop` (as postgres user)
- Cleanup : `rm -rf`, `umount`, `kill`

**User SSH :** root (par défaut, configurable dans sudoers)

### Mode Local (Serveur Backup)

**Configuration requise sur le serveur phpBorg :**

```bash
# Déployer le fichier sudoers pour www-data
sudo cp docs/sudoers-phpborg-backup-server /etc/sudoers.d/phpborg-backup-server
sudo chmod 440 /etc/sudoers.d/phpborg-backup-server
sudo visudo -c
```

**Permissions accordées :**
- Même ensemble de commandes que le mode remote
- User : `www-data` (Apache/Nginx)
- Accès direct aux repositories locaux (pas de SSH)

**Différences clés :**
- Pas de latence SSH
- Pas de charge sur le serveur source
- Nécessite que le repository soit local (`/opt/backups/...`)

### Comparaison des modes

| Caractéristique | Remote | Local |
|----------------|--------|-------|
| Latence | SSH overhead | Direct |
| Charge serveur source | Oui (CPU/RAM/Disk) | Non |
| Repository requis | Accessible via SSH | Local uniquement |
| Permissions | sudoers sur remote | sudoers sur backup server |
| Réseau | Port DB exposé sur remote | Port DB sur backup server |
| Use case | Test en production | Test isolé |

## 📝 Prochaines étapes

1. ✅ Backend PostgreSQL complet
2. ✅ Frontend UI (bouton dans Restore Wizard)
3. ✅ Modal de sélection deployment location
4. ✅ Internationalisation FR/EN
5. ✅ Sudoers pour mode remote et local
6. ⏸️ Support MySQL/MariaDB
7. ⏸️ Support MongoDB
8. ⏸️ Vue dédiée "Instant Recovery Sessions" (liste, monitoring)
9. ⏸️ Intégration phpPgAdmin/phpMyAdmin dans iframe
10. ⏸️ Auto-stop après timeout inactivité (sécurité)
11. ⏸️ Métriques : CPU/RAM utilisés par instance éphémère
12. ⏸️ Logs streaming en temps réel

## 🔐 Sécurité

### Considérations

1. **Isolation réseau** : Instance écoute uniquement sur localhost
2. **Ports dédiés** : Pas de conflit avec production
3. **Read-only backup** : Borg mount est RO, modifications dans overlay uniquement
4. **Cleanup automatique** : Arrêt supprime overlay + unmount
5. **Sudoers restreints** : Permissions limitées à /tmp/phpborg_instant_recovery/*

### Recommandations

- Ne pas exposer les ports éphémères sur Internet
- Utiliser un VPN ou SSH tunnel pour accès distant
- Limiter durée de vie des sessions (auto-stop après 2h)
- Logger toutes les opérations Instant Recovery
- Nettoyer régulièrement les sessions orphelines

## 📚 Références

- [BorgBackup FUSE mounting](https://borgbackup.readthedocs.io/en/stable/usage/mount.html)
- [OverlayFS Documentation](https://docs.kernel.org/filesystems/overlayfs.html)
- [PostgreSQL pg_ctl](https://www.postgresql.org/docs/current/app-pg-ctl.html)
