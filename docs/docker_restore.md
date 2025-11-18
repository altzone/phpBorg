# Docker Restore - Spécifications Techniques

## 📋 Vue d'Ensemble

Le système de restore Docker de phpBorg permet de récupérer des environnements Docker complets ou partiels depuis des backups BorgBackup. Il supporte deux modes opératoires adaptés à différents profils utilisateurs et scénarios.

---

## 🎯 Modes de Restore

### Mode 1 : "Pro Safe" (Restore Sélectif)
**Public cible** : Administrateurs système expérimentés
**Cas d'usage** : Récupération sélective, migration, tests

**Caractéristiques** :
- Browse détaillé du contenu du backup
- Sélection granulaire (volumes, compose projects, configs)
- Alternative location par défaut (safe)
- Review du script d'exécution avant lancement
- Options avancées de path adaptation

### Mode 2 : "Express Recovery" (Disaster Recovery)
**Public cible** : Tous profils en situation d'urgence
**Cas d'usage** : Crash serveur, corruption massive, urgence production

**Caractéristiques** :
- One-click full restore
- Auto-detection et arrêt des containers conflictuels
- Restore in-place automatique
- Restart automatique des services
- Health check post-restore

---

## 📐 Architecture du Wizard (Frontend)

### Step 1 : Sélection du Backup
**Interface** :
- Liste des archives Docker disponibles
- Tri par date (plus récent en premier)
- Affichage des métadonnées :
  - Date/heure du backup
  - Taille (original / compressé / dedupliqué)
  - Nombre de fichiers
  - Statut (success / warning)

**Preview du contenu** :
```
📦 Archive: docker_2025-11-18_11-33-07
├── 🗄️  Volumes (6)
│   ├── freeradius_db_data (250 MB)
│   ├── freeradius_mariadb_data (180 MB)
│   ├── graylog_graylog_data (1.2 GB)
│   ├── graylog_mongodb_config (12 MB)
│   ├── graylog_mongodb_data (15 GB)
│   └── graylog_opensearch_data (8 GB)
├── 📂 Compose Projects (3)
│   ├── graylog (/opt/graylog)
│   ├── astop (/opt/astop)
│   └── asterisk-chris-alarme (/opt/asterisk-chris-alarme)
└── ⚙️  Configs
    ├── /etc/docker/daemon.json
    └── Container metadata JSON
```

---

### Step 2 : Type de Restore

**Options** :
- ○ **Full Environment Restore** (Mode Express)
  - Tout restore : volumes + compose + configs
  - Recommandé pour disaster recovery

- ○ **Volumes Only**
  - Restore uniquement les données des volumes
  - Ne touche pas aux compose files ni configs
  - Utile pour data recovery

- ○ **Compose Files Only**
  - Restore uniquement docker-compose.yml + Dockerfiles
  - Ne touche pas aux volumes
  - Utile pour rollback de configuration

- ○ **Custom Selection** (Mode Pro)
  - Checkboxes granulaires pour chaque élément
  - Maximum de flexibilité

---

### Step 3 : Destination

**Choix principal** :

#### Option A : Alternative Location (🟢 Safe - Recommandé)
```
Path: /opt/restore_YYYY-MM-DD_HH-mm/
Structure:
├── volumes/
│   ├── freeradius_db_data/
│   ├── graylog_mongodb_data/
│   └── ...
├── compose/
│   ├── graylog/
│   ├── astop/
│   └── ...
└── configs/
    └── docker/
```

**Avantages** :
- ✓ Aucun risque pour la production
- ✓ Permet review avant mise en production
- ✓ Possibilité de comparer avec l'existant

#### Option B : In-Place Restore (🔴 Dangerous)
```
Restore direct aux emplacements d'origine:
- /var/lib/docker/volumes/xxx/_data
- /opt/graylog/docker-compose.yml
- /etc/docker/daemon.json
```

**Warnings affichés** :
```
⚠️  ATTENTION : Restore In-Place
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Cette opération va ÉCRASER les données actuelles !

Containers actifs détectés :
  • graylog-mongodb (running)
  • graylog-opensearch (running)
  • freeradius (running)

Actions qui seront effectuées :
  1. Arrêt des 3 containers
  2. Écrasement des volumes existants
  3. Restart des containers

Protections disponibles :
  ☑ Snapshot LVM avant restore (recommandé)
  ☑ Backup de l'état actuel

⚠️  Sans ces protections, l'opération est IRRÉVERSIBLE !

[ ] Je comprends les risques et souhaite continuer
```

---

### Step 4 : Options Avancées

#### 4.1 - Si Alternative Location + Compose Files restaurés

**Question** : Comment adapter les paths dans les docker-compose.yml ?

**Option A : Restore tel quel** (paths originaux)
```yaml
# docker-compose.yml restauré inchangé
volumes:
  - /var/lib/docker/volumes/graylog_data/_data:/data
```
- L'utilisateur adapte manuellement
- Maximum de contrôle
- Requiert compétences Docker

**Option B : Auto-modify paths** (🟢 Recommandé)
```yaml
# docker-compose.yml automatiquement adapté
volumes:
  - /opt/restore_2025-11-18/volumes/graylog_data:/data
```
- Modification automatique des paths
- Compose file prêt à l'emploi
- `docker-compose up` fonctionne directement

**Option C : Generate new + keep original**
```
/opt/restore_2025-11-18/compose/graylog/
├── docker-compose.yml.original      # Backup original
└── docker-compose.yml               # Version adaptée
```
- Garde l'original en `.original`
- Génère version adaptée
- Meilleur des deux mondes

---

#### 4.2 - Si In-Place Restore

**Protections obligatoires** :

☑ **Auto-stop conflicting containers** (OBLIGATOIRE - non négociable)
- Détection automatique des containers utilisant les volumes
- Arrêt avant restore pour éviter corruption
- Tracking pour restart post-restore

**Protections optionnelles** :

☐ **Create LVM snapshot before restore** (si LVM disponible)
```bash
lvcreate -L <size> -s -n restore_snapshot_TIMESTAMP /dev/vg/lv
```
- Rollback possible en cas de problème
- Conservé 8 heures puis auto-delete

☐ **Backup current state before override**
```bash
borg create /tmp/pre_restore_backup_TIMESTAMP \
  /var/lib/docker/volumes/xxx \
  /opt/graylog/
```
- Backup Borg de l'état actuel
- Rollback possible
- Conservé 8 heures puis auto-delete

☑ **Auto-restart containers after restore** (recommandé)
- Redémarre automatiquement les containers arrêtés
- Dans l'ordre correct (depends_on)
- Health check post-restart

---

### Step 5 : Safety Checks & Conflicts

**Détections automatiques** :

🔍 **Containers actifs utilisant les ressources à restore**
```
Conflits détectés :

Volume: graylog_mongodb_data
  ↳ Utilisé par: graylog-mongodb (running)

Compose project: /opt/graylog
  ↳ Containers: graylog (running), graylog-mongodb (running)

⚠️ Ces containers seront arrêtés avant le restore
```

📊 **Espace disque requis**
```
Espace requis : 25 GB
Espace disponible : 120 GB
✓ Suffisant
```

⚠️ **Warnings contextuels**

Si **in-place sans snapshot ni backup** :
```
🔴 RISQUE ÉLEVÉ
Aucune protection activée ! L'opération sera irréversible.
Recommandation : Activer au moins une protection.
```

Si **volumes restaurés mais pas compose files** :
```
⚠️ ATTENTION
Vous restaurez les volumes mais pas les compose files.
Les containers utiliseront les anciennes configurations
avec les nouvelles données. Risque d'incompatibilité.
```

---

### Step 6 : Review & Confirmation

**Script Shell Généré**

Pour utilisateurs **non-advanced** (mode expliqué) :
```bash
#!/bin/bash
# Docker Restore Script - Generated by phpBorg
# Archive: docker_2025-11-18_11-33-07
# Mode: In-place restore with protections
# Generated: 2025-11-18 14:30:45

set -e  # Exit on error

echo "🛑 Step 1: Stopping conflicting containers..."
docker stop graylog-mongodb graylog-opensearch freeradius
# → Arrêt des 3 containers pour éviter corruption pendant restore

echo "📸 Step 2: Creating LVM snapshot..."
lvcreate -L 20G -s -n restore_snapshot_20251118_1430 /dev/vg_data/lv_docker
# → Snapshot de sécurité, permet rollback pendant 8h

echo "📦 Step 3: Extracting volumes from Borg archive..."
borg extract --progress ssh://phpborg@backup/repo::docker_2025-11-18 \
  var/lib/docker/volumes/graylog_mongodb_data
# → Extraction du volume MongoDB (15 GB)

echo "🔄 Step 4: Restarting containers..."
docker start freeradius graylog-opensearch graylog-mongodb
# → Redémarrage des containers avec données restaurées

echo "✓ Restore completed successfully!"
```

Pour utilisateurs **advanced** (script complet) :
```bash
#!/bin/bash
# Docker Restore Script - phpBorg v2.0
# Full technical details

set -euo pipefail
trap 'echo "❌ Error on line $LINENO"' ERR

# Configuration
ARCHIVE="docker_2025-11-18_11-33-07"
BORG_REPO="ssh://phpborg@10.10.70.70/opt/backups/services1/services1-docker"
RESTORE_MODE="in-place"
SNAPSHOT_SIZE="20G"
SNAPSHOT_NAME="restore_snapshot_$(date +%Y%m%d_%H%M)"

# Step 1: Pre-checks
check_disk_space() {
  required=25600  # MB
  available=$(df /var/lib/docker --output=avail | tail -1)
  [[ $available -gt $required ]] || { echo "Insufficient disk space"; exit 1; }
}

# Step 2: Stop containers
stop_containers() {
  containers=(graylog-mongodb graylog-opensearch freeradius)
  for c in "${containers[@]}"; do
    docker stop "$c" || echo "Warning: $c already stopped"
  done
}

# ... (full implementation)
```

**Résumé Final** :
```
╔════════════════════════════════════════════════╗
║     DOCKER RESTORE - SUMMARY                   ║
╠════════════════════════════════════════════════╣
║ Archive       : docker_2025-11-18_11-33-07     ║
║ Mode          : In-place restore               ║
║ Restore Type  : Full Environment               ║
║                                                ║
║ 📦 Items to restore:                           ║
║   • 6 Docker volumes (24.5 GB)                 ║
║   • 3 Compose projects                         ║
║   • Docker configs                             ║
║                                                ║
║ 🛑 Containers to stop:                         ║
║   • graylog-mongodb                            ║
║   • graylog-opensearch                         ║
║   • freeradius                                 ║
║                                                ║
║ 🛡️  Protections enabled:                       ║
║   ✓ LVM Snapshot (rollback available 8h)      ║
║   ✓ Pre-restore backup                         ║
║   ✓ Auto-restart containers                    ║
║                                                ║
║ ⏱️  Estimated duration: 8-12 minutes           ║
║ 💾 Disk space required: 25 GB                  ║
╚════════════════════════════════════════════════╝

[ Download Script ]  [ ⚠️  Execute Restore ]
```

---

## 🔧 Backend Implementation

### Database Schema

#### Table: `restore_operations`
```sql
CREATE TABLE restore_operations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archive_id INT NOT NULL,
  server_id INT NOT NULL,
  user_id INT NOT NULL,

  -- Configuration
  mode ENUM('express', 'pro_safe') NOT NULL,
  restore_type ENUM('full', 'volumes_only', 'compose_only', 'custom') NOT NULL,
  destination ENUM('in_place', 'alternative') NOT NULL,
  alternative_path VARCHAR(500),

  -- Options
  compose_path_adaptation ENUM('none', 'auto_modify', 'generate_new') DEFAULT 'none',
  selected_items JSON,  -- {volumes: [...], projects: [...], configs: [...]}

  -- Protections
  lvm_snapshot_created BOOLEAN DEFAULT FALSE,
  lvm_snapshot_name VARCHAR(100),
  pre_restore_backup_created BOOLEAN DEFAULT FALSE,
  pre_restore_backup_archive VARCHAR(100),
  auto_restart BOOLEAN DEFAULT TRUE,

  -- Containers
  stopped_containers JSON,  -- [{name, id, restart_order}]

  -- Execution
  status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
  started_at DATETIME,
  completed_at DATETIME,
  error_message TEXT,

  -- Script
  generated_script LONGTEXT,
  script_executed BOOLEAN DEFAULT FALSE,

  -- Rollback capability (8 hours)
  can_rollback_until DATETIME,
  rolled_back_at DATETIME,

  -- Tracking
  items_restored JSON,  -- Progress tracking
  bytes_restored BIGINT,

  created_at DATETIME NOT NULL,
  updated_at DATETIME,

  INDEX idx_archive (archive_id),
  INDEX idx_server (server_id),
  INDEX idx_status (status),
  INDEX idx_rollback (can_rollback_until),

  FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
  FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

### Service: `DockerRestoreService`

#### Méthodes Principales

**1. `analyzeArchive(archiveId): array`**
```php
/**
 * Analyze archive content to prepare restore
 * Returns structure with volumes, compose projects, configs
 */
return [
  'volumes' => [
    ['name' => 'graylog_mongodb_data', 'size' => 15000000000, 'path' => '...'],
    // ...
  ],
  'compose_projects' => [
    ['name' => 'graylog', 'path' => '/opt/graylog', 'containers' => [...]],
    // ...
  ],
  'configs' => [
    ['path' => '/etc/docker/daemon.json', 'size' => 1024],
    // ...
  ]
];
```

**2. `detectConflicts(serverId, selectedItems): array`**
```php
/**
 * Detect running containers using volumes/paths to restore
 */
return [
  'conflicts' => [
    ['volume' => 'graylog_mongodb_data', 'containers' => ['graylog-mongodb']],
    // ...
  ],
  'must_stop' => ['graylog-mongodb', 'graylog-opensearch'],
  'disk_space_ok' => true,
  'warnings' => [...]
];
```

**3. `generateRestoreScript(config): string`**
```php
/**
 * Generate bash script for restore operation
 * @param array $config - Full restore configuration
 * @param bool $advanced - Advanced mode (full script) or explained mode
 */
return "#!/bin/bash\n# Script content...";
```

**4. `executeRestore(operationId): void`**
```php
/**
 * Execute restore operation via job queue
 * - Create protection snapshots if requested
 * - Stop conflicting containers
 * - Extract from Borg archive
 * - Adapt paths if needed
 * - Restart containers
 * - Health checks
 */
```

**5. `rollbackRestore(operationId): void`**
```php
/**
 * Rollback a restore operation (within 8h window)
 * - Restore from LVM snapshot OR
 * - Restore from pre-restore backup
 * - Restart containers
 */
```

---

### Handler: `DockerRestoreHandler`

**Job Queue Handler** pour exécution asynchrone du restore

```php
class DockerRestoreHandler implements JobHandlerInterface
{
  public function handle(Job $job, JobQueue $queue): string
  {
    $operationId = $job->payload['operation_id'];

    try {
      // 1. Load operation config from DB
      $operation = $this->loadOperation($operationId);

      // 2. Pre-restore protections
      $this->createProtections($operation);

      // 3. Stop containers
      $this->stopContainers($operation);

      // 4. Extract from Borg
      $this->extractFromBorg($operation);

      // 5. Adapt paths (if needed)
      $this->adaptPaths($operation);

      // 6. Restart containers
      $this->restartContainers($operation);

      // 7. Health checks
      $this->healthChecks($operation);

      // 8. Set rollback window
      $this->setRollbackWindow($operation, '+8 hours');

      return 'Restore completed successfully';

    } catch (\Exception $e) {
      // Auto-rollback on error
      $this->autoRollback($operation);
      throw $e;
    }
  }
}
```

---

## 🎨 Frontend Components

### Component Structure

```
/frontend/src/views/
└── RestoreWizardView.vue           # Main wizard container

/frontend/src/components/restore/
├── ArchiveSelector.vue              # Step 1
├── RestoreTypeSelector.vue          # Step 2
├── DestinationSelector.vue          # Step 3
├── AdvancedOptions.vue              # Step 4
├── ConflictDetection.vue            # Step 5
└── RestoreConfirmation.vue          # Step 6

/frontend/src/components/restore/docker/
├── VolumeList.vue                   # Display volumes with checkboxes
├── ComposeProjectList.vue           # Display compose projects
├── ContainerConflict.vue            # Show container conflicts
└── PathAdaptationPreview.vue        # Preview path modifications
```

---

## 🔐 Sécurité & Permissions

**Restriction** : Admin uniquement (`ROLE_ADMIN`)

**Raisons** :
- Opération potentiellement destructive
- Arrêt de services en production
- Modification de configs système
- Risque de corruption de données

**Audit Trail** :
- Toutes les opérations loggées dans `restore_operations`
- Email de notification envoyé après chaque restore
- Script généré conservé pour audit

---

## 📧 Notifications Email

**Événements déclencheurs** :
- ✉️ Restore started
- ✉️ Restore completed (success)
- ✉️ Restore failed (with error details)
- ✉️ Restore rolled back

**Template Email** :
```
Subject: [phpBorg] Docker Restore Completed - services1

Docker Restore Operation Completed
═══════════════════════════════════

Server: services1
Archive: docker_2025-11-18_11-33-07
User: admin@example.com
Started: 2025-11-18 14:30:45
Completed: 2025-11-18 14:38:12
Duration: 7m 27s

Items Restored:
  • 6 volumes (24.5 GB)
  • 3 compose projects
  • Docker configs

Containers Restarted:
  ✓ graylog-mongodb
  ✓ graylog-opensearch
  ✓ freeradius

Protections:
  ✓ LVM Snapshot created: restore_snapshot_20251118_1430
  ✓ Rollback available until: 2025-11-18 22:30:45

Health Checks:
  ✓ All containers running
  ✓ MongoDB responding on port 27017
  ✓ OpenSearch cluster healthy

─────────────────────────────────────
View full details: https://phpborg.example.com/restores/42
```

---

## ⏱️ Rollback & Cleanup

### Rollback Window: 8 heures

**Mécanismes disponibles** :

1. **LVM Snapshot Rollback** (le plus rapide)
```bash
# Rollback
lvconvert --merge /dev/vg/restore_snapshot_TIMESTAMP
# Reboot ou démount/remount required
```

2. **Borg Backup Rollback**
```bash
# Restore from pre-restore backup
borg extract /tmp/pre_restore_backup_TIMESTAMP
```

**Auto-Cleanup après 8h** :
```bash
# Cron job
0 * * * * /opt/phpborg/bin/cleanup_restore_snapshots.sh

# Script removes:
# - LVM snapshots older than 8h
# - Pre-restore backups older than 8h
# - Marks operations as non-rollbackable
```

---

## 📊 Progress Tracking

**Real-time Progress Display** :

```
Docker Restore in Progress
═══════════════════════════════════

Step 1/6: Creating LVM Snapshot          [████████████████████] 100%
Step 2/6: Stopping Containers             [████████████████████] 100%
Step 3/6: Extracting from Borg Archive    [████████░░░░░░░░░░░░]  45%
  ↳ Extracting: graylog_mongodb_data (6.8 GB / 15 GB)
  ↳ Speed: 85 MB/s
  ↳ ETA: 2m 15s

Step 4/6: Adapting Paths                  [░░░░░░░░░░░░░░░░░░░░]   0%
Step 5/6: Restarting Containers           [░░░░░░░░░░░░░░░░░░░░]   0%
Step 6/6: Health Checks                   [░░░░░░░░░░░░░░░░░░░░]   0%

Overall Progress: 37% (ETA: 5m 30s)
```

**Job Status Polling** :
- Frontend poll `/api/restore-operations/{id}` every 2s
- WebSocket alternative pour real-time updates (future)

---

## 🧪 Tests Recommandés

### Scénarios de Test

1. **Happy Path - Alternative Location**
   - Restore volumes dans /opt/restore
   - Vérifier structure préservée
   - Path adaptation fonctionne

2. **Happy Path - In-Place avec Protections**
   - Snapshot LVM créé
   - Containers arrêtés et redémarrés
   - Données correctement restaurées

3. **Conflict Detection**
   - Containers running détectés
   - Blocage si pas d'arrêt auto

4. **Rollback**
   - Rollback LVM fonctionne
   - Rollback Borg fonctionne
   - État restauré correctement

5. **Error Handling**
   - Borg extraction échoue → auto-rollback
   - Container restart échoue → notification
   - Espace disque insuffisant → blocage

6. **Edge Cases**
   - Archive sans volumes
   - Archive sans compose projects
   - LVM non disponible
   - Rollback après 8h (doit échouer)

---

## 📚 Documentation Utilisateur

### Guide : "Restore Docker - Mode Pro"
### Guide : "Restore Docker - Mode Express"
### FAQ : "Rollback d'un Restore Docker"
### Troubleshooting : "Restore Failed - What to do?"

---

## 🚀 Roadmap Future

**V2 Features** :
- [ ] WebSocket pour progress en temps réel
- [ ] Restore différentiel (merge au lieu d'override)
- [ ] Restore multi-serveurs (restore d'un serveur A vers serveur B)
- [ ] Validation pre-restore (dry-run)
- [ ] Restore scheduler (planifier restore pour 3h du matin)
- [ ] Integration tests automatiques
- [ ] Docker Swarm / Kubernetes support

---

**Document Version** : 1.0
**Last Updated** : 2025-11-18
**Authors** : Claude + User
**Status** : ✅ Approved - Ready for Implementation
