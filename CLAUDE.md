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

**Dernière session** : Fix Critique Scheduled Backups (Timezone + server_id)

**PROBLÈME CRITIQUE IDENTIFIÉ** :
Les backups programmés ne s'exécutaient pas alors que "Run Now" fonctionnait parfaitement.

**ROOT CAUSES** :
1. **Timezone Mismatch (1 heure de décalage)** :
   - PHP utilisait UTC (`APP_TIMEZONE=UTC` dans .env)
   - MySQL utilisait Europe/Paris (CET, +0100)
   - Résultat: `findDueJobs()` comparait `next_run_at = 10:41` avec `NOW() = 09:41` (UTC)
   - Les backups semblaient "dans le futur" alors qu'ils étaient dus

2. **Missing server_id dans payload** :
   - SchedulerWorker ne récupérait pas le server_id du repository
   - Erreur: "Missing server_id in job payload" → backup failed silently
   - "Run Now" l'incluait, d'où la confusion

**SOLUTIONS APPLIQUÉES** :
1. **Fix Timezone** :
   - Changé `.env`: `APP_TIMEZONE=UTC` → `APP_TIMEZONE=Europe/Paris`
   - PHP et MySQL maintenant synchronisés sur Europe/Paris
   - Scheduler détecte correctement les jobs dus

2. **Fix server_id** :
   - Ajouté `BorgRepositoryRepository` au SchedulerWorker (constructor injection)
   - Modified `checkSchedules()`: fetch repository avant de créer job
   - Extraction `server_id` du repository comme fait dans "Run Now"
   - Mis à jour `SchedulerStartCommand` pour injecter la dépendance

3. **Debug Logging** :
   - Ajouté log INFO: "Schedule check: found X due job(s)"
   - Permet de vérifier que scheduler tourne chaque 60s
   - Debug facilité pour troubleshooting futur

**TESTS & VALIDATION** :
```
[10:48:48] Schedule check: found 1 due job(s)
[10:48:48] Found 1 due backup job(s)
[10:48:48] Queued backup job #13 as queue job #614
```
✅ Scheduled backups fonctionnent maintenant correctement
✅ Payload contient server_id + repository_id + type
✅ Notifications email envoyées correctement

**Commits de la session** :
1. `1274c12` - fix: Add server_id to scheduled backup jobs and improve timezone display
2. `25446e4` - fix: Add debug logging and fix timezone mismatch for scheduled backups

**Session précédente** : Internationalisation & Notifications Email
- **i18n (vue-i18n v9)** : Implémentation complète français/anglais
- **Système de Notifications Email** : Templates HTML professionnels avec statistiques
- **Commits** : `5ce272f`, `240581f`, `6707871`, `689d9d2`, `a24dc87`, `f9e7a7f`

**Prochaines étapes possibles** :
- Finaliser l'internationalisation des autres vues (Servers, Workers, Dashboard, etc.)
- Restore d'archives avec browse de fichiers
- Graphiques historiques des stats (CPU/RAM/Disk évolution)
- Gestion de la rétention automatique (prune)
- Alertes sur seuils critiques (CPU/RAM/Disk)
- Email digest quotidien/hebdomadaire des backups