# phpBorg Self-Backup & Update System - Design Document

## 🎯 Objectif
Créer un système complet de backup/restore/update pour phpBorg lui-même, permettant à l'utilisateur de :
1. **Backup** : Sauvegarder l'installation complète (code + DB + config) avec encryption optionnelle
2. **Restore** : Restaurer depuis un backup avec diff preview intelligent
3. **Update** : Mettre à jour vers la dernière version de master avec backup automatique et rollback d'urgence

---

## 🏗️ Architecture Globale

### Phase 1 : Backup System ✅ (En cours)
- [x] Database schema (table `phpborg_backups` + settings)
- [x] Entity `PhpBorgBackup` avec helpers
- [x] Repository `PhpBorgBackupRepository` avec CRUD + stats
- [ ] Service `PhpBorgBackupService` (helper pour backup/restore)
- [ ] Handler `PhpBorgBackupCreateHandler`
- [ ] Handler `PhpBorgBackupRestoreHandler`
- [ ] Handler `PhpBorgBackupCleanupHandler`
- [ ] API Controller `PhpBorgBackupController`
- [ ] Frontend UI dans Settings
- [ ] CLI `bin/emergency-restore.sh`
- [ ] Scheduled weekly backup (SchedulerWorker)
- [ ] Email notifications

### Phase 2 : Restore dans Setup Wizard
- [ ] Modifier install.sh pour détecter mode restore
- [ ] Ajouter step "Restore or Fresh Install?"
- [ ] Upload + verification hash
- [ ] Extraction + import DB + configs
- [ ] Skip étapes inutiles (DB setup, user creation)

### Phase 3 : Auto-Update System
- [ ] Check for updates (git fetch + compare commits)
- [ ] Display changelog (git log)
- [ ] Pre-update backup automatique (obligatoire)
- [ ] Update process (git pull + composer + npm + migrations)
- [ ] Health check post-update (auto-rollback si échec)
- [ ] CLI rollback d'urgence

---

## 🚨 PROBLÈME CRITIQUE : Exécution du backup

### Le Dilemme
**Problème** : Pour garantir la cohérence du backup, il faut arrêter les workers. Mais si on arrête les workers, qui va exécuter le job de backup ?

**User phpborg** : Seul user avec accès SSH keys, code source, et permissions BorgBackup
**User www-data** : Lance l'API, mais pas de permissions sur /home/phpborg

### Solution Retenue : Option 3 (Worker special "maintenance")

**Flow** :
```
1. User clique "Create Backup" dans UI
2. API (www-data) → Job queue push 'phpborg_backup_create'
3. Worker phpborg #1 pickup le job
4. Handler détecte type "system_maintenance"
5. Handler arrête workers #2, #3, #4 (garde #1 actif)
6. Handler exécute backup complet
7. Handler redémarre workers #2, #3, #4
8. Job completed
```

**Avantages** :
- ✅ Garde l'architecture job queue (SSE real-time)
- ✅ Worker #1 reste actif pour terminer le job
- ✅ Fenêtre réduite sans workers (autres jobs en pause)
- ✅ Pas besoin de sudo www-data → phpborg

**Configuration sudoers nécessaire** :
```bash
# phpborg peut stop/start workers (pour backup)
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl stop phpborg-worker@*
phpborg ALL=(ALL) NOPASSWD: /bin/systemctl start phpborg-worker@*
```

---

## 📦 Contenu du Backup

### Structure de l'archive `phpborg-backup-YYYY-MM-DD-HHmmss.tar.gz`

```
phpborg-backup-2025-01-24-143022/
├── metadata.json                    # Version info, hash, encryption status
├── code/                            # Tout /opt/newphpborg/phpBorg/
│   ├── src/
│   ├── vendor/
│   ├── public/
│   ├── frontend/dist/
│   ├── bin/
│   ├── migrations/
│   └── .env
├── database.sql                     # mysqldump complet
├── config/
│   ├── env.backup                   # .env
│   ├── sudoers/
│   │   ├── phpborg-workers
│   │   ├── phpborg-backup-server
│   │   └── phpborg-instant-recovery
│   └── systemd/
│       ├── phpborg-scheduler.service
│       ├── phpborg-worker@.service
│       └── phpborg-workers.target
├── ssh/
│   ├── id_rsa
│   ├── id_rsa.pub
│   └── authorized_keys
└── settings.json                    # Export table settings (pour restore rapide)
```

### metadata.json
```json
{
  "version": "1.0",
  "backup_date": "2025-01-24T14:30:22Z",
  "phpborg_version": "v1.2.3",
  "git_commit": "abc1234def5678",
  "php_version": "8.3.1",
  "mysql_version": "10.11.6-MariaDB",
  "node_version": "20.10.0",
  "borg_version": "1.2.7",
  "encrypted": true,
  "encryption_algo": "AES-256-CBC",
  "compression": "gzip",
  "total_size_bytes": 257891234,
  "files_count": 15847,
  "database_size_bytes": 45123456,
  "created_by_user_id": 1,
  "backup_type": "manual",
  "hash_sha256": "abc123...",
  "notes": null
}
```

---

## 🔐 Encryption (AES-256-CBC)

### Activation
- Setting `backup_encryption_enabled` = 1
- Setting `backup_encryption_passphrase` = "user_secret_password"

### Process
1. Créer tar.gz non-chiffré en /tmp
2. Chiffrer avec OpenSSL :
   ```bash
   openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 \
     -in backup.tar.gz \
     -out backup.tar.gz.enc \
     -pass pass:"$PASSPHRASE"
   ```
3. Supprimer tar.gz original
4. Renommer `.enc` en `.tar.gz` (extension reste .tar.gz)
5. Stocker flag `encrypted=1` en DB

### Restore
1. Détecter `encrypted=1` en DB
2. Demander passphrase à l'user
3. Déchiffrer :
   ```bash
   openssl enc -aes-256-cbc -d -pbkdf2 -iter 100000 \
     -in backup.tar.gz \
     -out backup-decrypted.tar.gz \
     -pass pass:"$PASSPHRASE"
   ```
4. Extraire normalement

---

## 🔄 Restore Process avec Diff Intelligent

### 1. Upload & Validation
- User upload backup.tar.gz (ou sélectionne depuis liste)
- Vérifier hash SHA256 (intégrité)
- Extraire metadata.json
- Afficher info backup

### 2. Diff Preview
**Modal "Restore Preview" avec warning détaillé** :

```
⚠️ Restore from Backup

Backup Information:
├── Date: 2025-01-20 14:30:22
├── Version: v1.2.3 (commit: abc1234)
├── Size: 245 MB (encrypted)
└── Type: Manual backup

Current Installation:
├── Version: v1.3.0 (commit: def5678)
├── Database records: 1,234 (vs 956 in backup)
└── Last updated: 2025-01-24 10:15:00

⚠️ CHANGES THAT WILL BE LOST:

📊 Database Changes:
├── 3 new servers added since backup
├── 15 new backups created since backup
├── 2 new users created since backup
└── 5 settings modified since backup

⚙️ Configuration Changes:
├── Email notifications enabled
├── Storage pool path changed: /opt/backups → /mnt/nas/backups
└── Encryption passphrase modified

🔄 Version Downgrade:
└── v1.3.0 → v1.2.3 (15 commits behind)

💾 Current Data Backup:
A backup of the current installation will be created automatically
before restore: phpborg-backup-2025-01-24-151022-pre_restore.tar.gz

[Cancel] [I understand, restore anyway]
```

### 3. Calcul du Diff
```php
// Compare metadata.json avec état actuel
$backupDate = new DateTime($metadata['backup_date']);

// Count DB records created since backup
$newServers = DB::query("SELECT COUNT(*) FROM servers WHERE created_at > ?", [$backupDate]);
$newBackups = DB::query("SELECT COUNT(*) FROM archives WHERE end > ?", [$backupDate]);
$newUsers = DB::query("SELECT COUNT(*) FROM users WHERE created_at > ?", [$backupDate]);

// Compare settings (hash des valeurs)
$currentSettings = SettingsRepo::findAll();
$backupSettings = json_decode($backup['settings.json']);
$modifiedSettings = array_diff_assoc($currentSettings, $backupSettings);

// Compare version
$versionDiff = [
    'from' => CURRENT_VERSION,
    'to' => $metadata['phpborg_version'],
    'commits_behind' => exec("git rev-list --count {$metadata['git_commit']}..HEAD")
];
```

### 4. Execution Restore
1. **Pre-restore backup** : Backup automatique de l'installation actuelle
2. **Stop workers** : systemctl stop phpborg-workers.target
3. **Restore DB** : mysql < backup/database.sql
4. **Restore code** : tar -xzf dans /opt/newphpborg/phpBorg/
5. **Restore configs** : .env, sudoers, systemd, SSH keys
6. **Permissions** : chown phpborg:phpborg recursively
7. **Composer** : composer install (si composer.lock existe)
8. **Start workers** : systemctl start phpborg-workers.target
9. **Health check** : Vérifier workers UP, DB accessible

---

## 🔧 Auto-Update System

### Settings UI
```
Settings > Updates

Current Version
├── Installed: v1.2.3 (commit: abc1234)
├── PHP: 8.3.1 | Node: 20.10.0 | Borg: 1.2.7
└── [Check for Updates] button

Available Update (si disponible)
├── New version: v1.3.0
├── 15 commits ahead:
│   - feat: Add instant recovery for MongoDB
│   - fix: Resolve SSE connection issues
│   - feat: Add server wizard
│   ...
├── [View Full Changelog] modal
├── ⚠️ Warning: Automatic backup will be created before update
└── [Update Now] button → Job queue

Update History
└── Table: Date | From | To | Status | [Rollback]
```

### Update Process (Handler PhpBorgUpdateHandler)

```php
1. Pre-checks
   ✓ Git repo clean (no uncommitted changes)
   ✓ Disk space >= 2x current size
   ✓ Dependencies available (composer, npm, git)

2. Backup (OBLIGATOIRE)
   → Create automatic backup (type: 'pre_update')
   → Store rollback info in Redis
   → Log: backup_id, git_commit_before

3. Stop workers
   → systemctl stop phpborg-worker@{2..4}
   → Keep worker #1 running (pour terminer le job)

4. Git update
   → git fetch origin
   → git checkout master
   → git pull origin master

5. Dependencies
   → composer install --no-dev --optimize-autoloader
   → cd frontend && npm ci
   → npm run build

6. Check new system dependencies
   → Compare install/lib/deps.sh avec installed packages
   → If missing deps: Run deps.sh install functions

7. Database migrations
   → Find new .sql files in migrations/
   → Run via bin/run-migration.php
   → Validate DB schema after each migration

8. Restart workers
   → systemctl start phpborg-worker@{2..4}

9. Health check (CRITICAL)
   → Workers running? systemctl is-active
   → DB accessible? Test query
   → Redis accessible? Test ping
   → API responsive? curl localhost/api/health
   → If ANY failure → AUTO ROLLBACK

10. Cleanup
    → Clear cache
    → Remove temp files
    → Send email notification

If health check fails:
→ Automatic rollback to pre-update backup
→ Email alert to admin
→ Log detailed error
```

### Rollback CLI d'Urgence

**Cas d'usage** : L'update a cassé PHP, MySQL, ou le web ne répond plus.

**Script** : `/opt/newphpborg/phpBorg/bin/emergency-restore.sh`

```bash
#!/bin/bash
# phpBorg Emergency Rollback
# Usage: sudo bash /opt/newphpborg/phpBorg/bin/emergency-restore.sh [backup_id]

set -e

echo "╔══════════════════════════════════════════════════════════╗"
echo "║       🚨 phpBorg Emergency Restore System 🚨             ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ This script must be run as root"
    exit 1
fi

BACKUP_DIR="/opt/backups/phpborg-self-backups"
PHPBORG_ROOT="/opt/newphpborg/phpBorg"

# If backup_id provided, use it
if [ -n "$1" ]; then
    BACKUP_FILE=$(find "$BACKUP_DIR" -name "*" -type f | grep "$1" | head -1)
    if [ -z "$BACKUP_FILE" ]; then
        echo "❌ Backup ID not found: $1"
        exit 1
    fi
else
    # List all backups
    echo "📦 Available Backups:"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    counter=1
    declare -A backups

    for backup in $(ls -t "$BACKUP_DIR"/*.tar.gz 2>/dev/null); do
        filename=$(basename "$backup")
        size=$(du -h "$backup" | cut -f1)
        date=$(echo "$filename" | grep -oP '\d{4}-\d{2}-\d{2}-\d{6}')
        type=$(echo "$filename" | grep -oP '(manual|pre_update|scheduled|pre_restore)')

        backups[$counter]="$backup"

        printf "%2d. %-50s %8s  %s  %s\n" \
            $counter \
            "$filename" \
            "$size" \
            "$date" \
            "[$type]"

        ((counter++))
    done

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""

    if [ ${#backups[@]} -eq 0 ]; then
        echo "❌ No backups found in $BACKUP_DIR"
        exit 1
    fi

    read -p "Select backup number (or 'q' to quit): " choice

    if [ "$choice" = "q" ]; then
        echo "Cancelled."
        exit 0
    fi

    BACKUP_FILE="${backups[$choice]}"

    if [ -z "$BACKUP_FILE" ]; then
        echo "❌ Invalid selection"
        exit 1
    fi
fi

echo ""
echo "📦 Selected backup: $(basename "$BACKUP_FILE")"
echo ""

# Extract metadata
TEMP_DIR=$(mktemp -d)
tar -xzf "$BACKUP_FILE" -C "$TEMP_DIR" metadata.json 2>/dev/null || true

if [ -f "$TEMP_DIR/metadata.json" ]; then
    echo "📋 Backup Information:"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    cat "$TEMP_DIR/metadata.json" | jq -r '
        "  Version: \(.phpborg_version) (commit: \(.git_commit))",
        "  Date: \(.backup_date)",
        "  Size: \(.total_size_bytes / 1024 / 1024 | floor) MB",
        "  Encrypted: \(if .encrypted then "Yes" else "No" end)",
        "  Type: \(.backup_type)"
    '
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
fi

# Confirmation
echo "⚠️  WARNING: This will:"
echo "  1. Stop all phpBorg workers"
echo "  2. Backup current installation to: phpborg-backup-$(date +%Y-%m-%d-%H%M%S)-pre_emergency_restore.tar.gz"
echo "  3. Replace all code and configuration"
echo "  4. Restore database (ALL CURRENT DATA WILL BE LOST)"
echo "  5. Restart all services"
echo ""
read -p "Continue with restore? Type 'YES' to confirm: " confirm

if [ "$confirm" != "YES" ]; then
    echo "Cancelled."
    rm -rf "$TEMP_DIR"
    exit 0
fi

echo ""
echo "🔄 Starting emergency restore..."
echo ""

# 1. Create backup of current installation
echo "[1/8] Creating backup of current installation..."
CURRENT_BACKUP="$BACKUP_DIR/phpborg-backup-$(date +%Y-%m-%d-%H%M%S)-pre_emergency_restore.tar.gz"
cd /opt/newphpborg
tar -czf "$CURRENT_BACKUP" phpBorg/ 2>/dev/null || echo "⚠️  Backup failed (continuing anyway)"

# 2. Stop all services
echo "[2/8] Stopping phpBorg services..."
systemctl stop phpborg-workers.target 2>/dev/null || true
systemctl stop phpborg-scheduler 2>/dev/null || true

# 3. Check if encrypted
ENCRYPTED=$(cat "$TEMP_DIR/metadata.json" | jq -r '.encrypted' 2>/dev/null || echo "false")

if [ "$ENCRYPTED" = "true" ]; then
    echo "[3/8] Backup is encrypted. Please enter passphrase:"
    read -s -p "Passphrase: " passphrase
    echo ""

    # Decrypt
    openssl enc -aes-256-cbc -d -pbkdf2 -iter 100000 \
        -in "$BACKUP_FILE" \
        -out "$TEMP_DIR/backup-decrypted.tar.gz" \
        -pass pass:"$passphrase"

    if [ $? -ne 0 ]; then
        echo "❌ Decryption failed. Wrong passphrase?"
        rm -rf "$TEMP_DIR"
        exit 1
    fi

    BACKUP_FILE="$TEMP_DIR/backup-decrypted.tar.gz"
fi

# 4. Extract backup
echo "[4/8] Extracting backup..."
cd "$PHPBORG_ROOT"
rm -rf code.old
mv -f src src.old 2>/dev/null || true
tar -xzf "$BACKUP_FILE" -C "$TEMP_DIR"

# 5. Restore code
echo "[5/8] Restoring code..."
rsync -a --delete "$TEMP_DIR/code/" "$PHPBORG_ROOT/"

# 6. Restore database
echo "[6/8] Restoring database..."
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new < "$TEMP_DIR/database.sql"

# 7. Restore configs
echo "[7/8] Restoring configurations..."
cp "$TEMP_DIR/config/env.backup" "$PHPBORG_ROOT/.env"
cp -r "$TEMP_DIR/config/sudoers/"* /etc/sudoers.d/
cp -r "$TEMP_DIR/config/systemd/"* /etc/systemd/system/
cp -r "$TEMP_DIR/ssh/"* /home/phpborg/.ssh/
chmod 600 /home/phpborg/.ssh/id_rsa
chown -R phpborg:phpborg /home/phpborg/.ssh/
systemctl daemon-reload

# 8. Restart services
echo "[8/8] Restarting services..."
systemctl start phpborg-scheduler
systemctl start phpborg-workers.target

# Health check
sleep 3
echo ""
echo "🔍 Health Check:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if systemctl is-active --quiet phpborg-scheduler; then
    echo "✓ Scheduler: Running"
else
    echo "✗ Scheduler: Failed"
fi

for i in {1..4}; do
    if systemctl is-active --quiet phpborg-worker@$i; then
        echo "✓ Worker #$i: Running"
    else
        echo "✗ Worker #$i: Failed"
    fi
done

# Cleanup
rm -rf "$TEMP_DIR"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "✅ Emergency restore completed!"
echo ""
echo "📝 Next steps:"
echo "  1. Check logs: sudo journalctl -u phpborg-scheduler -f"
echo "  2. Access web interface: http://your-server/"
echo "  3. Verify data integrity"
echo ""
echo "💾 Backup of previous installation:"
echo "  $CURRENT_BACKUP"
echo ""
```

---

## 📧 Email Notifications

### Events à notifier
1. **Backup Created** (manual, scheduled, pre_update)
2. **Backup Failed**
3. **Restore Completed**
4. **Restore Failed**
5. **Update Completed**
6. **Update Failed + Auto-rollback**
7. **Cleanup Executed** (old backups deleted)

### Template Email (FR/EN)
```
Subject: [phpBorg] Backup Created Successfully

Bonjour,

Un nouveau backup phpBorg a été créé avec succès :

📦 Backup Information:
  - Filename: phpborg-backup-2025-01-24-143022.tar.gz
  - Size: 245 MB
  - Type: Manual backup
  - Encrypted: Yes
  - Created: 2025-01-24 14:30:22
  - Created by: admin@example.com

📊 Backup Content:
  - phpBorg version: v1.2.3
  - Database size: 45 MB (1,234 records)
  - Files: 15,847
  - Hash (SHA256): abc123def456...

💾 Location:
  /opt/backups/phpborg-self-backups/phpborg-backup-2025-01-24-143022.tar.gz

🔄 Retention Policy:
  - Keeping last 3 backups
  - Older backups will be automatically deleted

---
phpBorg Backup System
https://your-server/settings/backup
```

---

## 🎨 Frontend UI

### Settings > Backup & Restore

```vue
<template>
  <div class="space-y-8">
    <!-- Backup Configuration -->
    <section class="card">
      <h2>⚙️ Backup Configuration</h2>

      <div class="form-group">
        <label>Storage Path</label>
        <input v-model="settings.backup_storage_path" />
        <p class="help">Path where phpBorg backups are stored</p>
      </div>

      <div class="form-group">
        <label>
          <input type="checkbox" v-model="settings.backup_encryption_enabled" />
          Enable Encryption (AES-256-CBC)
        </label>
      </div>

      <div v-if="settings.backup_encryption_enabled" class="form-group">
        <label>Encryption Passphrase</label>
        <input type="password" v-model="settings.backup_encryption_passphrase" />
        <p class="help">⚠️ Keep this passphrase safe! You'll need it to restore encrypted backups.</p>
      </div>

      <div class="form-group">
        <label>Retention Policy</label>
        <input type="number" v-model="settings.backup_retention_count" min="1" max="10" />
        <p class="help">Number of backups to keep (older backups will be automatically deleted)</p>
      </div>

      <div class="form-group">
        <label>
          <input type="checkbox" v-model="settings.backup_scheduled_enabled" />
          Enable Weekly Scheduled Backups
        </label>
      </div>

      <div v-if="settings.backup_scheduled_enabled" class="grid grid-cols-2 gap-4">
        <div class="form-group">
          <label>Day of Week</label>
          <select v-model="settings.backup_scheduled_day">
            <option value="monday">Monday</option>
            <option value="tuesday">Tuesday</option>
            <option value="wednesday">Wednesday</option>
            <option value="thursday">Thursday</option>
            <option value="friday">Friday</option>
            <option value="saturday">Saturday</option>
            <option value="sunday">Sunday</option>
          </select>
        </div>

        <div class="form-group">
          <label>Time (HH:MM)</label>
          <input type="time" v-model="settings.backup_scheduled_time" />
        </div>
      </div>

      <button @click="saveSettings" class="btn-primary">
        Save Configuration
      </button>
    </section>

    <!-- Create Backup -->
    <section class="card">
      <h2>💾 Create Backup</h2>
      <p class="text-gray-600 dark:text-slate-400 mb-4">
        Create a complete backup of phpBorg (code, database, configuration, SSH keys)
      </p>

      <div v-if="backupInProgress" class="bg-blue-50 dark:bg-slate-800 rounded-lg p-4 mb-4">
        <div class="flex items-center gap-3 mb-2">
          <svg class="animate-spin h-5 w-5 text-blue-500" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="font-medium">{{ backupProgress.message }}</span>
        </div>
        <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-2">
          <div class="bg-blue-500 h-2 rounded-full transition-all" :style="{width: backupProgress.percent + '%'}"></div>
        </div>
      </div>

      <button @click="createBackup" :disabled="backupInProgress" class="btn-primary">
        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
          <path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293zM9 4a1 1 0 012 0v2H9V4z"></path>
        </svg>
        Create Backup Now
      </button>
    </section>

    <!-- Backup History -->
    <section class="card">
      <h2>📚 Backup History</h2>

      <div v-if="backups.length === 0" class="text-center py-8 text-gray-500">
        No backups found
      </div>

      <table v-else class="w-full">
        <thead>
          <tr>
            <th>Date</th>
            <th>Size</th>
            <th>Type</th>
            <th>Version</th>
            <th>Encrypted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="backup in backups" :key="backup.id">
            <td>{{ formatDate(backup.created_at) }}</td>
            <td>{{ backup.size_human }}</td>
            <td>
              <span class="badge" :class="badgeClass(backup.backup_type)">
                {{ backup.backup_type }}
              </span>
            </td>
            <td>{{ backup.phpborg_version }}</td>
            <td>
              <svg v-if="backup.encrypted" class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
              </svg>
              <svg v-else class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2H7V7a3 3 0 015.905-.75 1 1 0 001.937-.5A5.002 5.002 0 0010 2z"></path>
              </svg>
            </td>
            <td>
              <div class="flex gap-2">
                <button @click="downloadBackup(backup)" class="btn-sm">Download</button>
                <button @click="restoreBackup(backup)" class="btn-sm btn-warning">Restore</button>
                <button @click="deleteBackup(backup)" class="btn-sm btn-danger">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="mt-4 text-sm text-gray-600 dark:text-slate-400">
        <strong>Total:</strong> {{ backups.length }} backups
        <strong class="ml-4">Total Size:</strong> {{ formatSize(totalSize) }}
      </div>
    </section>

    <!-- Restore Modal -->
    <RestorePreviewModal
      v-if="showRestoreModal"
      :backup="selectedBackup"
      @close="showRestoreModal = false"
      @confirm="confirmRestore"
    />
  </div>
</template>
```

---

## 🗓️ TODO List

### Phase 1 : Backup System
- [x] Database schema (table + settings)
- [x] Entity PhpBorgBackup
- [x] Repository PhpBorgBackupRepository
- [ ] Service PhpBorgBackupService
  - [ ] createBackup() : Orchestrate backup process
  - [ ] restoreBackup() : Orchestrate restore process
  - [ ] encryptBackup() : AES-256-CBC encryption
  - [ ] decryptBackup() : AES-256-CBC decryption
  - [ ] calculateHash() : SHA256 hash
  - [ ] verifyIntegrity() : Check hash
  - [ ] extractMetadata() : Read metadata.json
  - [ ] calculateDiff() : Compare backup vs current
- [ ] Handler PhpBorgBackupCreateHandler
  - [ ] Stop workers #2, #3, #4
  - [ ] Dump database (mysqldump)
  - [ ] Create tar.gz (code + config + ssh)
  - [ ] Encrypt if enabled
  - [ ] Calculate hash
  - [ ] Save to DB
  - [ ] Restart workers
- [ ] Handler PhpBorgBackupRestoreHandler
  - [ ] Pre-restore backup
  - [ ] Stop workers
  - [ ] Restore DB
  - [ ] Restore code
  - [ ] Restore configs
  - [ ] Restart workers
  - [ ] Health check
- [ ] Handler PhpBorgBackupCleanupHandler
  - [ ] Find old backups (retention policy)
  - [ ] Delete files
  - [ ] Delete DB records
  - [ ] Email notification
- [ ] API Controller PhpBorgBackupController
  - [ ] POST /api/phpborg-backup/create
  - [ ] GET /api/phpborg-backup/list
  - [ ] GET /api/phpborg-backup/:id
  - [ ] GET /api/phpborg-backup/:id/download
  - [ ] POST /api/phpborg-backup/:id/restore
  - [ ] DELETE /api/phpborg-backup/:id
  - [ ] GET /api/phpborg-backup/stats
- [ ] Frontend Settings > Backup & Restore
  - [ ] Configuration form
  - [ ] Create backup button (SSE)
  - [ ] Backup history table
  - [ ] Restore modal with diff preview
  - [ ] Download button
  - [ ] Delete button
- [ ] i18n translations (FR/EN)
  - [ ] Backup settings
  - [ ] Backup actions
  - [ ] Restore warnings
  - [ ] Email templates
- [ ] CLI bin/emergency-restore.sh
  - [ ] List backups
  - [ ] Select backup
  - [ ] Display metadata
  - [ ] Confirmation prompt
  - [ ] Restore process
  - [ ] Health check
- [ ] Scheduled Weekly Backup
  - [ ] Add to SchedulerWorker
  - [ ] Check settings
  - [ ] Create job on schedule
- [ ] Email Notifications
  - [ ] Backup created
  - [ ] Backup failed
  - [ ] Restore completed
  - [ ] Restore failed

### Phase 2 : Restore dans Setup Wizard
- [ ] Modifier install.sh bootstrap
- [ ] Add restore detection
- [ ] Upload UI
- [ ] Hash verification
- [ ] Extract & restore
- [ ] Skip DB setup steps

### Phase 3 : Auto-Update System
- [ ] Check for updates (git fetch)
- [ ] Display changelog (git log)
- [ ] Pre-update backup
- [ ] Update process
- [ ] Health check
- [ ] Auto-rollback
- [ ] CLI rollback d'urgence

---

## 🔒 Sécurité

### Fichiers sensibles
- **.env** : Credentials DB, JWT secrets, API keys
- **SSH keys** : Accès aux serveurs
- **Backup passphrase** : Encryption key
- **Database dump** : Toutes les données

### Protection
1. **Permissions** :
   - Backups : chmod 600 (phpborg:phpborg only)
   - Storage dir : chmod 700
2. **Encryption** : AES-256-CBC avec PBKDF2 (100k iterations)
3. **Hash** : SHA256 pour vérifier intégrité
4. **Audit** : Logs de toutes les opérations backup/restore
5. **Access** : Admin role only

---

## 📚 Références

- OpenSSL encryption: https://wiki.openssl.org/index.php/Enc
- AES-256-CBC best practices: https://www.openssl.org/docs/man1.1.1/man1/openssl-enc.html
- Git operations: https://git-scm.com/docs
- Systemd service management: https://www.freedesktop.org/software/systemd/man/systemctl.html
- MariaDB mysqldump: https://mariadb.com/kb/en/mysqldump/

---

**Dernière mise à jour** : 2025-01-24
**Status** : Phase 1 en cours (Foundation completed, Handlers in progress)
