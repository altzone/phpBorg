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

**Dernière session** : Internationalisation & Notifications Email
- **i18n (vue-i18n v9)** : Implémentation complète français/anglais
  - BackupWizard: traduction des 9 steps (serveur, type, source, snapshot, storage, repo, retention, schedule, review)
  - BackupJobs: traduction des types de schedule (daily → quotidien)
  - LanguageSwitcher: composant de changement de langue dans le menu
  - Support des computed properties pour traductions réactives
  - Fix des sections JSON dupliquées (retention, review)

- **Système de Notifications Email** :
  - `EmailService`: Service SMTP générique avec PHPMailer
  - `BackupNotificationService`: Notifications backup avec templates HTML
  - Templates professionnels: gradient colors, badges, tableaux de statistiques
  - Emails de succès: durée, tailles (original/compressé/dédupliqué), nb fichiers
  - Emails d'échec: détails erreur + suggestions de résolution
  - Setting `notification.email` configurable dans Settings > General
  - Respect des flags `notify_on_success` et `notify_on_failure` des backup jobs
  - Intégration automatique dans `BackupCreateHandler`
  - Utilise `app.name` depuis settings dans les emails

- **Améliorations UI** :
  - Nom d'app dynamique dans le menu (depuis Settings > General > App name)
  - Fix computed property accessors (.value en JavaScript, pas en template)
  - Traduction cohérente des schedules dans toutes les vues

**Commits de la session** :
1. `5ce272f` - feat: Complete French/English i18n implementation for backup wizard
2. `240581f` - feat: Add i18n integration, restore wizard, and various improvements
3. `6707871` - feat: Add email notifications for backup jobs with beautiful HTML templates
4. `689d9d2` - fix: Translate schedule types in backup jobs list (daily → quotidien)
5. `a24dc87` - fix: Use correct camelCase property names in BackupNotificationService
6. `f9e7a7f` - feat: Add configurable notification email setting

**Prochaines étapes possibles** :
- Finaliser l'internationalisation des autres vues (Servers, Workers, Dashboard, etc.)
- Restore d'archives avec browse de fichiers
- Graphiques historiques des stats (CPU/RAM/Disk évolution)
- Gestion de la rétention automatique (prune)
- Alertes sur seuils critiques (CPU/RAM/Disk)
- Email digest quotidien/hebdomadaire des backups