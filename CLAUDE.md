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

### Worker System
- Worker background traite les jobs de backup/suppression/setup serveur
- Handlers : `BackupCreateHandler`, `ArchiveDeleteHandler`, `ServerSetupHandler`
- Logs détaillés et suivi de progression en temps réel

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
# Démarrer le worker
php bin/console worker:start

# Redémarrer après modifications handlers
pkill -f "worker:start" && php bin/console worker:start

# Frontend dev
cd frontend && npm run dev
```

## 📁 Structure des fichiers clés

### Handlers Jobs
- `/src/Service/Queue/Handlers/BackupCreateHandler.php` - Création backups
- `/src/Service/Queue/Handlers/ArchiveDeleteHandler.php` - Suppression archives  
- `/src/Service/Queue/Handlers/ServerSetupHandler.php` - Setup serveurs SSH
- `/src/Command/WorkerStartCommand.php` - Enregistrement des handlers

### API Controllers  
- `/src/Api/Controller/BackupJobController.php` - CRUD jobs programmés + run()
- `/src/Api/Controller/BackupController.php` - CRUD archives + delete via job
- `/src/Api/Controller/BackupWizardController.php` - Wizard création backup

### Repositories
- `/src/Repository/ArchiveRepository.php` - avec findAllWithDetails()
- `/src/Repository/BorgRepositoryRepository.php` - avec findByRepoId()
- `/src/Repository/BackupJobRepository.php` - Jobs programmés

### Services
- `/src/Service/Backup/BorgExecutor.php` - avec deleteArchive()
- `/src/Service/Backup/BackupService.php` - executeBackupWithRepository()

### Frontend
- `/frontend/src/views/BackupJobsView.vue` - Liste jobs + Run Now
- `/frontend/src/views/BackupsView.vue` - Liste archives + suppression 
- `/frontend/src/stores/backups.js` - Store Pinia
- `/frontend/src/services/backups.js` - API calls

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

**Dernière session** : Implémentation suppression archives (9a2fa72)
**Prochaines étapes possibles** : Restore d'archives, gestion de la rétention, monitoring avancé