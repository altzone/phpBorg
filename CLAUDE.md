# phpBorg - Enterprise Backup System

## 🎯 Project Overview
phpBorg est un système de backup d'entreprise moderne comparable à Veeam/Acronis/Nakivo, basé sur BorgBackup avec une interface web Vue.js 3 et un backend PHP 8+.

## 🏗️ Architecture

### Backend (PHP 8+)
- **API REST** : Controllers dans `/src/Api/Controller/`
- **Job Queue** : Redis avec worker background pour les tâches Borg
- **Database** : MariaDB avec repositories pattern
- **SSH** : Architecture sécurisée avec clés déployées automatiquement
- **Borg** : BorgExecutor pour toutes les opérations de backup/restore

### Frontend (Vue.js 3)
- **Composition API** avec stores Pinia
- **TailwindCSS** pour le styling
- **Router** pour navigation SPA
- **Services** pour communication API

### Worker System (Architecture Professionnelle)
- **SchedulerWorker** : Daemon léger qui vérifie les schedules (60s) et collecte stats (15min)
- **Worker Pool** : 4 workers en parallèle via systemd (@1, @2, @3, @4) pour traiter jobs simultanés
- **Job Queue** : Redis avec opérations atomiques pour distribution des jobs
- **Handlers** : `BackupCreateHandler`, `ArchiveDeleteHandler`, `ServerSetupHandler`, `ServerStatsCollectHandler`, `StoragePoolAnalyzeHandler`
- **Systemd Services** :
  - `phpborg-scheduler.service` - Scheduler unique
  - `phpborg-worker@.service` - Template pour pool instances
  - `phpborg-workers.target` - Gestion groupe de workers
- **Logs** : journalctl avec rotation automatique
- **Sudoers** : Permissions configurées pour gestion via web interface

## 🚀 Fonctionnalités Récentes

### ✅ Backup Jobs avec "Run Now"
- **Vue** : `/frontend/src/views/BackupJobsView.vue`
- **API** : `POST /api/backup-jobs/:id/run` 
- **Handler** : Utilise `BackupCreateHandler` avec repository_id spécifique
- **Fonctionnalité** : Bouton "Exécuter maintenant" pour lancer un backup à la demande

### ✅ Repository Path Structure
- **Structure** : `<storage_pool_path>/<server_name>/<repository_name>`
- **Implémenté dans** : `BackupWizardController::create()`
- **Exemple** : `/opt/backups/virus/system` au lieu de `/opt/backups/repo_123`

### ✅ Suppression de Backups
- **Handler** : `ArchiveDeleteHandler` (nouveau)
- **API** : `DELETE /api/backups/:id` → crée job `archive_delete`
- **Worker** : Exécute `borg delete --stats --force repository::archive`
- **Frontend** : Modal française avec confirmations et warnings
- **Sécurité** : Gestion des archives corrompues (nom vide)

### ✅ Affichage Amélioré des Backups
- **Vue** : `/frontend/src/views/BackupsView.vue`
- **API** : `GET /api/backups` avec JOIN servers/repository
- **Affichage** : "virus - system" au lieu d'IDs techniques
- **Méthode** : `ArchiveRepository::findAllWithDetails()`

### ✅ Statistiques Système des Serveurs
- **Handler** : `ServerStatsCollectHandler` - Collecte stats via SSH
- **API** : `POST /api/servers/:id/collect-stats` - Déclenche collecte manuelle
- **API** : `GET /api/servers` - Retourne stats dans liste serveurs
- **Database** : Table `server_stats` avec métriques complètes
- **Frontend** : Accordéon dans cartes serveurs avec:
  - En-tête: OS + indicateurs rapides (CPU/RAM/Disk %)
  - Détails: Architecture, CPU model, barres de progression, uptime
- **Métriques collectées** :
  - Système: OS, kernel, hostname, architecture
  - CPU: cores, model, load average, usage%
  - RAM: total/used/available/free + swap
  - Disque: total/used/free + % utilisation
  - Network: IP address
  - Uptime: secondes + format humain + boot time
- **Collecte** : Manuelle via bouton "Refresh" ou "Collect now"

### ✅ Gestion des Workers (Worker Pool Management)
- **API Controller** : `WorkerController` - Gestion systemd services via API
- **API Endpoints** :
  - `GET /api/workers` - Liste tous les workers (scheduler + pool)
  - `GET /api/workers/:name` - Détails d'un worker spécifique
  - `POST /api/workers/:name/start` - Démarrer un worker
  - `POST /api/workers/:name/stop` - Arrêter un worker
  - `POST /api/workers/:name/restart` - Redémarrer un worker
  - `GET /api/workers/:name/logs` - Récupérer les logs (journalctl)
- **Frontend** : `/frontend/src/views/WorkersView.vue`
  - Cartes pour chaque worker avec status en temps réel
  - Indicateurs: active/inactive, PID, Memory, CPU, Uptime
  - Boutons Start/Stop/Restart par worker
  - Modal logs avec filtres (lines, since) et refresh
- **Services/Stores** :
  - `/frontend/src/services/workers.js` - API calls
  - `/frontend/src/stores/workers.js` - Store Pinia
- **Sécurité** : Admin only (ROLE_ADMIN required)
- **Sudoers** : `/etc/sudoers.d/phpborg-workers` - NOPASSWD pour systemctl/journalctl

## 🔧 Configuration

### Base de données
```
DB_HOST=127.0.0.1
DB_NAME=phpborg_new
DB_USER=phpborg_new
DB_PASSWORD=4Re2q(kyjTwA2]FF
```

### Commandes importantes
```bash
# Gestion des services systemd
sudo systemctl status phpborg-scheduler
sudo systemctl status phpborg-worker@1
sudo systemctl restart phpborg-worker@{1..4}
sudo systemctl stop phpborg-workers.target  # Arrête tous les workers

# Voir les logs
sudo journalctl -u phpborg-scheduler -f
sudo journalctl -u phpborg-worker@1 -f --since "1 hour ago"

# Installation/Réinstallation des services
sudo bash bin/install-services.sh 4

# Frontend dev
cd frontend && npm run dev
```

## 📁 Structure des fichiers clés

### Handlers Jobs
- `/src/Service/Queue/Handlers/BackupCreateHandler.php` - Création backups
- `/src/Service/Queue/Handlers/ArchiveDeleteHandler.php` - Suppression archives
- `/src/Service/Queue/Handlers/ServerSetupHandler.php` - Setup serveurs SSH
- `/src/Service/Queue/Handlers/ServerStatsCollectHandler.php` - Collecte stats système
- `/src/Service/Queue/Handlers/StoragePoolAnalyzeHandler.php` - Analyse storage pools
- `/src/Service/Queue/SchedulerWorker.php` - Scheduler daemon pour schedules + stats
- `/src/Command/WorkerStartCommand.php` - Enregistrement des handlers
- `/src/Command/SchedulerStartCommand.php` - Commande scheduler daemon

### API Controllers
- `/src/Api/Controller/BackupJobController.php` - CRUD jobs programmés + run()
- `/src/Api/Controller/BackupController.php` - CRUD archives + delete via job
- `/src/Api/Controller/BackupWizardController.php` - Wizard création backup
- `/src/Api/Controller/ServerController.php` - CRUD serveurs + collectStats()
- `/src/Api/Controller/WorkerController.php` - Gestion workers (start/stop/restart/logs)

### Repositories
- `/src/Repository/ArchiveRepository.php` - avec findAllWithDetails()
- `/src/Repository/BorgRepositoryRepository.php` - avec findByRepoId()
- `/src/Repository/BackupJobRepository.php` - Jobs programmés
- `/src/Repository/ServerStatsRepository.php` - Stats système serveurs

### Services
- `/src/Service/Backup/BorgExecutor.php` - avec deleteArchive()
- `/src/Service/Backup/BackupService.php` - executeBackupWithRepository()
- `/src/Service/Server/ServerStatsCollector.php` - Collecte métriques SSH

### Frontend
- `/frontend/src/views/BackupJobsView.vue` - Liste jobs + Run Now
- `/frontend/src/views/BackupsView.vue` - Liste archives + suppression
- `/frontend/src/views/ServersView.vue` - Liste serveurs + stats accordéon
- `/frontend/src/views/WorkersView.vue` - Gestion workers + modal logs
- `/frontend/src/stores/backups.js` - Store Pinia backups
- `/frontend/src/stores/server.js` - Store Pinia serveurs + collectStats()
- `/frontend/src/stores/workers.js` - Store Pinia workers
- `/frontend/src/services/backups.js` - API calls backups
- `/frontend/src/services/server.js` - API calls serveurs
- `/frontend/src/services/workers.js` - API calls workers

### Systemd Services
- `/systemd/phpborg-scheduler.service` - Service scheduler unique
- `/systemd/phpborg-worker@.service` - Template service pour worker pool
- `/systemd/phpborg-workers.target` - Target pour gestion groupe
- `/bin/install-services.sh` - Script installation automatique
- `/docs/sudoers-phpborg-workers` - Configuration sudoers

## 🐛 Debugging

### Worker non démarré
```bash
# Vérifier processus
ps aux | grep worker
# Logs worker
tail -f /var/log/phpborg_new.log
```

### Erreurs SQL
- Utiliser des placeholders positionnels `?` (pas de named `:param`)
- MariaDB avec mysqli driver

### Erreurs SSH
- Clés privées sur serveurs distants : `/root/.ssh/phpborg_backup`
- Clés publiques sur serveur backup avec restriction borg serve
- Worker s'exécute en tant que user `phpborg`

## 🔄 Workflow Complet

### Setup Serveur
1. Ajouter serveur → Job `server_setup`
2. Test SSH + Install Borg + Deploy keys + Configure authorized_keys

### Backup
1. Wizard → Création repository + Job `backup_create`
2. Scheduled jobs → Cron → Job `backup_create`
3. Manual "Run Now" → Job `backup_create` avec repository_id

### Suppression
1. Click croix rouge → Modal confirmation
2. API → Job `archive_delete`  
3. Worker → `borg delete` + Update DB + Stats

## 📊 Status Actuel
- ✅ Setup serveurs automatique
- ✅ Backups programmés et manuels
- ✅ Run Now fonctionnel
- ✅ Suppression d'archives opérationnelle
- ✅ UI française avec feedback détaillé
- ✅ Logs et monitoring complets
- ✅ Dark mode complet (Tailwind class-based)
- ✅ Statistiques système temps réel (OS, CPU, RAM, Disk, Uptime)
- ✅ Accordéon UI pour stats serveurs
- ✅ Worker Pool Architecture (Scheduler + 4 Workers parallèles)
- ✅ Gestion Workers via Dashboard (Start/Stop/Restart/Logs)
- ✅ Collecte automatique stats (serveurs + storage pools) toutes les 15min
- ✅ Internationalisation complète (i18n) français/anglais
- ✅ Notifications email avec templates HTML professionnels
- ✅ Nom d'application dynamique depuis settings
- ✅ Scheduled backups fonctionnels (fix timezone + server_id)
- ✅ Détection automatique d'authentification MySQL/PostgreSQL
- ✅ MongoDB LVM snapshot support (atomic backups)
- ✅ Reload capabilities depuis Backup Wizard
- ✅ DatabaseInfo auto-création depuis capabilities
- 🚧 **Instant Recovery** : Frontend complet + Job-based execution (en debug)
  - Bouton dans Restore Wizard (database backups only)
  - Modal dual-mode (Remote/Local deployment)
  - i18n FR/EN complet
  - Job queue pour sécurité (phpborg user vs www-data)
  - PostgreSQL read-only sur FUSE mount
  - **BLOCKER** : FUSE mount datadir detection (find returns empty)

**Dernière session** : Instant Recovery - Job Queue Refactoring + FUSE Mount Debug

**OBJECTIFS DE LA SESSION** :
Refactorer Instant Recovery pour utiliser le job queue system (sécurité), implémenter PostgreSQL read-only sur FUSE mount (sans copie), et résoudre problèmes FUSE permissions.

**IMPLÉMENTATIONS RÉALISÉES** :

1. **Refactoring Sécurité : Web → Job Queue** :
   - **Problème initial** : Exécution directe depuis web context avec `www-data` user
   - **Solution** : Passer par job queue avec `phpborg` user
   - Fichier : `/src/Api/Controller/InstantRecoveryController.php`
   - Changement : `$this->recoveryManager->startRecovery()` → `$this->jobQueue->push('instant_recovery_start', $payload)`
   - Retour HTTP 202 avec job_id au lieu de session directe

2. **Job Handlers** :
   - Fichier : `/src/Service/Queue/Handlers/InstantRecoveryStartHandler.php` (créé)
   - Fichier : `/src/Service/Queue/Handlers/InstantRecoveryStopHandler.php` (créé)
   - Signature correcte : `handle(Job $job, JobQueue $queue): string`
   - Enregistrement dans `/src/Command/WorkerStartCommand.php`
   - Exécution asynchrone par workers phpBorg

3. **PostgreSQL Read-Only Direct Mount** :
   - **Problème** : Impossible de copier 50TB de données (user feedback critique)
   - **Ancien approach** : OverlayFS sur FUSE mount → ÉCHEC (kernel limitation)
   - **Nouvelle approche** : PostgreSQL direct read-only sur FUSE mount
   - Fichier : `/src/Service/InstantRecovery/InstantRecoveryManager.php`
   - Options PostgreSQL read-only :
     ```
     -c default_transaction_read_only=on
     -c fsync=off
     -c full_page_writes=off
     -c max_wal_senders=0
     -c wal_level=minimal
     -c archive_mode=off
     ```
   - Avantage : Zero-copy instant recovery (comme Veeam)

4. **FUSE Mount Permission Fix** :
   - **Problème découvert** : `sudo find` ne peut pas accéder aux FUSE mounts user
   - **Root cause** : FUSE mounts sont user-specific (phpborg), root n'y a pas accès
   - **Solution** : Paramètre `$useSudo` dans `execute()` method
   - Usage : `false` pour opérations read-only (find, ls)
   - Usage : `true` pour opérations privileged (mount, pg_ctl)
   - Test manuel validé : `find /tmp/test_mount` (phpborg) → ✅ trouve datadir
   - Test manuel validé : `sudo find /tmp/test_mount` (root) → ❌ vide

5. **Sudoers Backup Server Update** :
   - Fichier : `/docs/sudoers-phpborg-backup-server` (mis à jour)
   - User changé : `www-data` → `phpborg` (worker context)
   - Permissions : borg mount/umount, mkdir, pg_ctl, overlay (deprecated)
   - Format corrigé : wildcards simplifiés pour compatibilité sudoers

6. **Frontend Job-Based Response** :
   - Fichier : `/frontend/src/services/instantRecovery.js`
   - Ajustement : `return response.data.data || response.data`
   - Supporte retour job info au lieu de session directe
   - Toast affiche job_id pour tracking

7. **Dynamic PostgreSQL Datadir Detection** :
   - Méthode : `findDataDirectoryInMount()`
   - Pattern find : `find {borgMount} -type d -path '*/var/lib/postgresql/*/main'`
   - Support multi-version PostgreSQL (8, 9, 10, 11, 12, 13, 14, 15, 16)
   - **BLOCKER ACTUEL** : find retourne vide malgré $useSudo=false

**FICHIERS CRÉÉS/MODIFIÉS** :
- `src/Service/Queue/Handlers/InstantRecoveryStartHandler.php` - **Créé**
- `src/Service/Queue/Handlers/InstantRecoveryStopHandler.php` - **Créé**
- `src/Service/InstantRecovery/InstantRecoveryManager.php` - Refacto complet (read-only PostgreSQL + FUSE fix)
- `src/Api/Controller/InstantRecoveryController.php` - Job queue integration
- `src/Command/WorkerStartCommand.php` - Enregistrement handlers
- `docs/sudoers-phpborg-backup-server` - User phpborg + permissions
- `frontend/src/services/instantRecovery.js` - Support job response

**PROBLÈMES RENCONTRÉS & RÉSOLUS** :
1. ❌ Handler signature mismatch → ✅ `handle(Job $job, JobQueue $queue): string`
2. ❌ Wrong method `enqueue()` → ✅ Changed to `push()`
3. ❌ `BaseController::success()` param order → ✅ `success($data, $message, 202)`
4. ❌ Frontend undefined job_id → ✅ Adjusted service return
5. ❌ OverlayFS mount failure → ✅ Abandoned, switched to read-only PostgreSQL
6. ❌ 50TB copy absurd (user feedback) → ✅ Zero-copy FUSE mount approach
7. ❌ Root can't access user FUSE → ✅ Added $useSudo parameter

**BLOCKER ACTUEL** :
- **Symptôme** : "Could not find postgresql data directory in backup"
- **Cause probable** : Le find retourne toujours vide malgré $useSudo=false
- **Tests manuels** :
  - `find /tmp/test_mount` (as phpborg) → ✅ works
  - `sudo find /tmp/test_mount` (as root) → ❌ empty
- **Code actuel** : `$useSudo=false` dans `findDataDirectoryInMount()`
- **Hypothèses à explorer** :
  1. exec() vs shell_exec() behavior différent
  2. Escape shellarg peut interférer avec glob patterns
  3. Permissions stderr non capturées
  4. Mount path incorrect ou non finalisé

**WORKFLOW ACTUEL** :
1. User click "⚡ Instant Recovery" → Modal sélection Remote/Local
2. Frontend → `POST /api/instant-recovery/start` → Job created (HTTP 202)
3. Worker phpborg → Pop job → `InstantRecoveryStartHandler::handle()`
4. Handler → Mount Borg archive via FUSE (✅ works)
5. Handler → Find PostgreSQL datadir (❌ **BLOCKER** - returns empty)
6. Handler → Start PostgreSQL read-only (⏸️ not reached)
7. Toast notification job_id (✅ works)

**TESTS RÉALISÉS** :
- ✅ Job queue integration (job created & picked by worker)
- ✅ FUSE mount works (log shows "Borg archive mounted successfully")
- ✅ Manual find as phpborg user (finds datadir)
- ❌ Automated find in handler (returns empty)
- ⏸️ PostgreSQL read-only startup (blocked by datadir detection)

**NEXT STEPS (TODO LIST)** :
- 🔴 Debug FUSE mount datadir detection - find command returns empty
- 🟡 Test alternative datadir detection methods (ls, manual path construction)
- 🟡 Add verbose logging to findDataDirectoryInMount for debugging
- 🟡 Verify FUSE mount is accessible by phpborg user after mount
- 🟡 Test PostgreSQL read-only startup on detected datadir
- 🟢 Implement MySQL/MariaDB instant recovery support
- 🟢 Implement MongoDB instant recovery support
- 🟢 Add active sessions list view in frontend
- 🟢 Add stop/cleanup session functionality in frontend

**Session précédente** : Détection Avancée Bases de Données & Snapshots Atomiques

**OBJECTIFS DE LA SESSION PRÉCÉDENTE** :
Améliorer la détection des bases de données avec support automatique des credentials et snapshots LVM atomiques pour MongoDB.

**IMPLÉMENTATIONS RÉALISÉES** :

1. **Auto-détection Authentification MySQL** :
   - Méthode `detectMysqlAuth()` dans `CapabilitiesDetectionHandler`
   - Test 1: `mysql -u root` sans mot de passe
   - Test 2: Lecture `/etc/mysql/debian.cnf` + extraction credentials
   - Test 3: Validation des credentials extraits
   - Retourne: `{method, working, user, password, host, port}`
   - Facilite wizard backup en pré-remplissant les credentials

2. **Auto-détection Authentification PostgreSQL** :
   - Méthode `detectPostgresqlAuth()` dans `CapabilitiesDetectionHandler`
   - Test 1: Peer auth avec `su - postgres -c "psql -c 'SELECT 1'"`
   - Test 2: Liste clusters via `pg_lscluster --no-header`
   - Retourne: `{method, working, peer_auth, clusters[], user, password}`
   - Clusters avec version, port, status, owner, data_directory

3. **MongoDB LVM Snapshot Support** :
   - Ajout `createMongoSnapshot()` dans `LvmSnapshotManager`
   - Refactoring `MongoDbBackupStrategy` : mongodump → LVM snapshot
   - Injection `LvmSnapshotManager` dans `Application.php`
   - MongoDB maintenant au même niveau que MySQL/PostgreSQL (atomic backups)

4. **DatabaseInfo Auto-création** :
   - Méthode `createDatabaseInfo()` dans `BackupWizardController`
   - Extraction automatique depuis capabilities : vg_name, lv_name, lvSize, datadir
   - Validation `snapshot_capable` avant autorisation backup
   - Liaison repository via `updateRepositoryId()`
   - Plus besoin de saisie manuelle des infos LVM

5. **Fix Snapshot Size Structure** :
   - Changement `snapshot_recommended_size` → `snapshot_size{}`
   - Structure: `{recommended_size, datadir_size, conservative, aggressive}`
   - Applied pour MySQL, PostgreSQL, MongoDB
   - Frontend affiche correctement les tailles (plus de "N/A")

6. **Fix Timeout PostgreSQL** :
   - Augmentation timeout `du -sb` : 10s → 60s (ligne 399)
   - Nécessaire pour gros datadirs PostgreSQL
   - Permet calcul correct de datadir_size

7. **Bouton Reload Capabilities** :
   - Ajout bouton "Reload Capabilities" dans BackupWizard Step 2
   - Fonction `reloadCapabilities()` avec polling job (30s timeout)
   - Spinner animation pendant détection
   - Permet refresh après config serveur sans quitter wizard

8. **Détections Supplémentaires** :
   - Ajout détection Redis complète
   - Ajout détection environnement Docker (containers, networks, volumes)
   - Architecture plus complète pour écosystème serveur

**FICHIERS MODIFIÉS** :
- `src/Service/Queue/Handlers/CapabilitiesDetectionHandler.php` - Détection auth + fixes
- `src/Service/Database/LvmSnapshotManager.php` - createMongoSnapshot()
- `src/Service/Database/MongoDbBackupStrategy.php` - Refacto LVM
- `src/Api/Controller/BackupWizardController.php` - createDatabaseInfo()
- `src/Application.php` - Injection LvmSnapshotManager
- `frontend/src/views/BackupWizardView.vue` - Reload button + UI improvements
- `frontend/src/i18n/locales/en.json` - Traductions DB detection
- `frontend/src/i18n/locales/fr.json` - Traductions DB detection

**WORKFLOW AMÉLIORÉ** :
1. User ajoute serveur → Capabilities detection automatique
2. Detection récupère auth MySQL (root/debian.cnf) et PostgreSQL (peer auth)
3. Wizard Backup Step 2 affiche databases détectées avec snapshot info
4. Si config manquante → "Reload Capabilities" sans quitter wizard
5. Création backup → DatabaseInfo auto-créé depuis capabilities
6. Backup exécuté → LVM snapshot automatique (MySQL/PostgreSQL/MongoDB)

**COMMIT DE LA SESSION** :
- `207d3fe` - feat: Enhance database detection with auth auto-detection and reload capabilities

**TESTS RÉALISÉS** :
- ✅ Trigger detection via API sur serveur "virus"
- ✅ Capabilities data récupérées avec PostgreSQL + MongoDB
- ✅ Snapshot sizes affichées correctement
- ⏸️ Auth detection code présent mais workers non redémarrés (à tester prochaine session)

**NEXT STEPS** :
- Redémarrer workers pour activer code auth detection
- Tester auth auto-detection MySQL + PostgreSQL
- Tester création DatabaseInfo depuis wizard
- Tester MongoDB LVM snapshot backup complet
- Utiliser auth détectée pour pré-remplir wizard credentials
- Afficher clusters PostgreSQL pour sélection multi-instance

**Session précédente** : Fix Critique Scheduled Backups (Timezone + server_id)
- **Commits** : `1274c12`, `25446e4`

**Prochaines étapes possibles** :
- Finaliser l'internationalisation des autres vues (Servers, Workers, Dashboard, etc.)
- Restore d'archives avec browse de fichiers
- Graphiques historiques des stats (CPU/RAM/Disk évolution)
- Gestion de la rétention automatique (prune)
- Alertes sur seuils critiques (CPU/RAM/Disk)
- Email digest quotidien/hebdomadaire des backups