# 📋 TODO LIST - phpBorg

## ✅ COMPLÉTÉ (dernières sessions)

- [x] Instant Recovery (PostgreSQL, MySQL, MongoDB, Elasticsearch)
- [x] Docker Restore (UI wizard 6 steps + backend)
- [x] Real-time backup progress + transfer rate
- [x] Orphaned archive recovery automatique
- [x] Docker auto-backup (Compose + standalone containers)
- [x] Database credentials auto-detection
- [x] Email notifications avec templates HTML
- [x] Internationalisation FR/EN complète
- [x] TaskBar flottante pour Instant Recovery sessions
- [x] backup_config snapshot architecture
- [x] Cron scheduler (SchedulerWorker systemd)
- [x] Restore Wizard (URL: /restore-wizard)

## 🔴 PRIORITÉ HAUTE

### Docker Restore - Testing (ACTUEL)
```
📌 USER REQUEST: "j'aimerai qu'on vois pour la restoration des docker et faire des tests"
```
- [ ] Créer backup Docker test complet
  - [ ] Setup serveur avec Docker volumes
  - [ ] Setup Docker Compose project
  - [ ] Lancer backup via wizard
  - [ ] Vérifier `actual_backed_up_items` dans archive metadata

- [ ] Tester Docker Restore end-to-end
  - [ ] Ouvrir Docker Restore wizard
  - [ ] Sélectionner archive backup
  - [ ] Vérifier analyse contenu (volumes, compose, configs)
  - [ ] Tester sélection items
  - [ ] Tester détection conflits
  - [ ] Vérifier génération script bash
  - [ ] Tester execution restore (volumes + compose)
  - [ ] Vérifier auto-restart containers
  - [ ] Tester LVM snapshot protection

- [ ] Debug si nécessaire
  - [ ] Vérifier logs workers pendant restore
  - [ ] Tester edge cases (volumes inexistants, conflicts, etc.)

### Docker Restore - Real-time Progress
```
📍 File: src/Service/Queue/Handlers/DockerRestoreHandler.php:324
TODO: Parse progress from stderr and update job progress in real-time
```
- [ ] Parser `borg extract --progress` output (JSON format)
- [ ] Update Redis job progress pendant extraction
- [ ] Frontend polling dans Docker Restore wizard
- [ ] Affichage files count, bytes extracted, ETA

## 🟡 PRIORITÉ MOYENNE

### Email Notifications
```
📍 File: src/Service/Email/BackupNotificationService.php:52
TODO: Add notification_email field to backup_jobs table
```
- [ ] Migration DB: ajouter colonne `notification_email` à `backup_jobs`
- [ ] UI dans Backup Wizard pour configurer email
- [ ] Support multiple emails (comma-separated)
- [ ] Email digest quotidien/hebdomadaire

### ~~Cron Expression Parser~~ ✅ FAIT
```
✅ Implémenté via SchedulerWorker systemd
```
- [x] Worker systemd qui vérifie schedules toutes les 60s
- [x] Support expressions cron standard
- [x] Logs dans journalctl
- **Note** : Le TODO dans BackupSchedule.php:309 est obsolète, déjà implémenté

### Instant Recovery Improvements
- [ ] Liste sessions actives (au-delà de TaskBar)
  - [ ] Vue dédiée `/instant-recovery/sessions`
  - [ ] Table avec toutes sessions actives
  - [ ] Actions: stop, cleanup, logs

- [ ] One-click database admin tools
  - [ ] phpPgAdmin pour PostgreSQL sessions
  - [ ] Adminer pour MySQL sessions
  - [ ] Integration dans TaskBar

- [ ] Mode Remote deployment
  - [ ] Instant recovery sur serveur source (pas backup server)
  - [ ] SSH tunnel pour connection
  - [ ] Sélection server dans modal

### ~~Restore Wizard~~ ✅ FAIT
```
✅ Implémenté - URL: /restore-wizard
```
- [x] Vue complète RestoreWizardView.vue
- [x] Browse archives disponibles
- [x] Sélection archive pour restore
- [x] Workflow restore complet
- **Note** : Le TODO ligne 798 est obsolète ou commentaire de code, le wizard est fonctionnel

### Améliorations Restore Wizard (optionnel)
- [ ] File browser granulaire (browse inside archive)
  - [ ] `borg list --json-lines` pour lister contenu archive
  - [ ] Tree view interactif avec expand/collapse
  - [ ] Sélection fichiers/dossiers individuels
  - [ ] Preview fichiers texte avant restore
  - [ ] Support restore partiel (selected paths only)

### Backup Templates
```
📍 File: frontend/src/views/BackupWizardView.vue:1996
TODO: Implement template saving
```
- [ ] Table `backup_templates` (name, config_json, user_id, created_at)
- [ ] Button "Save as Template" dans Backup Wizard step 6
- [ ] Modal pour naming template
- [ ] Dropdown "Load Template" dans step 1
- [ ] Share templates entre serveurs similaires

### Retention Management
- [ ] UI pour configurer retention policies
  - [ ] keep-daily, keep-weekly, keep-monthly, keep-yearly
  - [ ] Exclusion patterns
  - [ ] Preview dry-run avant apply

- [ ] Auto-prune via scheduler
  - [ ] Setting global: enable/disable auto-prune
  - [ ] Per-repository prune schedule
  - [ ] Job queue handler `PruneHandler`

- [ ] Stats espace libéré
  - [ ] Dashboard widget "Space saved by pruning"
  - [ ] Historique prune operations

## 🟢 PRIORITÉ BASSE

### API Improvements
```
📍 File: src/Api/Controller/BackupController.php:1016
TODO: This should query the repositories table
```
- [ ] Refactor pour utiliser `BorgRepositoryRepository`
- [ ] Éviter hardcoded repository IDs

```
📍 File: src/Api/Controller/InstantRecoveryController.php:140
TODO: get from database_info table
```
- [ ] Query `database_info` pour connection info
- [ ] Éviter default hardcoded values

### Monitoring & Alerting
- [ ] Graphiques historiques
  - [ ] CPU evolution (line chart)
  - [ ] RAM evolution (area chart)
  - [ ] Disk usage evolution (bar chart)
  - [ ] Backup size trends

- [ ] Alertes seuils critiques
  - [ ] CPU > 90% sustained
  - [ ] Disk > 85%
  - [ ] Memory swap usage > 50%
  - [ ] Email/webhook notifications

- [ ] Health check dashboard
  - [ ] Overall system health score
  - [ ] Last successful backup per server
  - [ ] Failed jobs count (last 24h)

- [ ] Predicted disk full
  - [ ] Linear regression sur disk usage
  - [ ] Alert "Disk full in X days"

### Performance Optimizations
- [ ] Cache capabilities en Redis
  - [ ] TTL 1 hour
  - [ ] Éviter SSH à chaque wizard load
  - [ ] Invalidation manuelle via "Reload" button

- [ ] Pagination BackupsView
  - [ ] 50 archives per page
  - [ ] Server-side pagination
  - [ ] Filters: server, date range, size range

- [ ] Lazy loading archive details
  - [ ] Load metadata only when expanding row
  - [ ] Virtual scrolling pour très longues listes

### Documentation
- [ ] User guide complet
  - [ ] Screenshots step-by-step
  - [ ] PDF export
  - [ ] Video tutorials (Loom/YouTube)

- [ ] API documentation
  - [ ] OpenAPI/Swagger spec
  - [ ] Auto-generated from code annotations
  - [ ] Interactive API explorer

- [ ] Developer guide
  - [ ] Architecture overview
  - [ ] Adding new backup strategies
  - [ ] Creating custom handlers

## 🗂️ CODE TODOs EXTRAITS

### Backend (TODOs actifs)
1. ~~`BackupSchedule.php:309` - Cron expression parser~~ ✅ FAIT (SchedulerWorker systemd)
2. `DockerRestoreHandler.php:324` - Real-time progress ⚠️ PRIORITÉ
3. `BackupNotificationService.php:52` - notification_email field
4. `BackupController.php:1016` - Query repositories table
5. `InstantRecoveryController.php:140` - Get from database_info

### Frontend (TODOs actifs)
1. ~~`RestoreWizardView.vue:798` - Restore modal/wizard~~ ✅ FAIT (restore-wizard URL)
2. `BackupWizardView.vue:1996` - Template saving

## 📊 PROGRESS METRICS

### Completed Features
- ✅ 10 job queue handlers (vs 5 initialement)
- ✅ 4 database types instant recovery
- ✅ 6-step Docker restore wizard
- ✅ Real-time progress tracking
- ✅ 100% i18n FR/EN

### Code Stats
- 📈 41 commits ahead of origin
- 📈 ~5000+ lignes ajoutées
- 📈 18 fichiers modifiés (dernière session)

### Next Milestone
🎯 **Docker Restore Testing & Validation** (priorité user actuelle)

---

**Dernière mise à jour**: 2025-01-19
**Status**: 🟢 En cours - Testing Docker Restore
