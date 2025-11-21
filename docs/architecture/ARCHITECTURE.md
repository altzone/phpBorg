# phpBorg 2.0 - Architecture Moderne

## 🏗️ Vue d'ensemble

phpBorg 2.0 utilise une architecture moderne découplée avec :
- **Backend** : API REST PHP 8.3+ (Symfony-like)
- **Frontend** : Vue.js 3 + Composition API + Pinia
- **Auth** : JWT avec refresh tokens + rôles/permissions
- **Temps Réel** : Server-Sent Events (SSE)
- **Queue** : Redis + Worker systemd
- **Database** : MySQL/MariaDB

---

## 📁 Structure du Projet

```
phpBorg/
├── api/                          # Backend API REST
│   ├── public/
│   │   └── index.php            # Entry point API
│   ├── src/
│   │   ├── Controller/          # API Controllers
│   │   │   ├── AuthController.php
│   │   │   ├── ServerController.php
│   │   │   ├── BackupController.php
│   │   │   ├── JobController.php
│   │   │   └── SSEController.php
│   │   ├── Middleware/
│   │   │   ├── JWTMiddleware.php
│   │   │   └── CorsMiddleware.php
│   │   ├── Service/
│   │   │   ├── JWTService.php
│   │   │   ├── AuthService.php
│   │   │   └── QueueService.php
│   │   └── Router/
│   │       └── ApiRouter.php
│   └── config/
│       └── routes.php
├── frontend/                     # Frontend Vue.js
│   ├── src/
│   │   ├── components/          # Vue components
│   │   ├── views/               # Pages
│   │   ├── stores/              # Pinia stores
│   │   ├── router/              # Vue Router
│   │   ├── services/            # API services
│   │   └── App.vue
│   ├── package.json
│   └── vite.config.js
├── worker/                       # Queue worker
│   ├── BackupWorker.php
│   └── phpborg-worker.service   # Systemd service
├── src/                          # Core logic (partagé)
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   └── Queue/
│       ├── Job/
│       │   ├── BackupJob.php
│       │   ├── PruneJob.php
│       │   └── ServerTestJob.php
│       ├── QueueManager.php
│       └── RedisQueue.php
└── public/                       # Assets statiques
    └── dist/                     # Build Vue.js
```

---

## 🔐 Authentification JWT

### Flow d'authentification

```
1. User → POST /api/auth/login {username, password}
2. API valide credentials
3. API génère access token (15min) + refresh token (7j)
4. API retourne {access_token, refresh_token, user}
5. Frontend stocke tokens dans localStorage
6. Chaque requête : Authorization: Bearer <access_token>
7. Si token expiré → POST /api/auth/refresh {refresh_token}
```

### Structure JWT Payload

```json
{
  "sub": 123,              // User ID
  "username": "admin",
  "roles": ["ROLE_ADMIN"],
  "permissions": ["backup.create", "server.manage"],
  "iat": 1699000000,       // Issued at
  "exp": 1699000900        // Expires at (15min)
}
```

### Rôles & Permissions

```php
ROLE_ADMIN:
  - backup.*
  - server.*
  - user.*
  - config.*
  - logs.*

ROLE_OPERATOR:
  - backup.view
  - backup.create
  - server.view
  - logs.view

ROLE_VIEWER:
  - backup.view
  - server.view
  - logs.view (read-only)
```

---

## 🌐 API REST Endpoints

### Authentication
```
POST   /api/auth/login           # Login
POST   /api/auth/refresh         # Refresh token
POST   /api/auth/logout          # Logout (invalidate token)
GET    /api/auth/me              # Current user info
```

### Servers
```
GET    /api/servers              # Liste serveurs
GET    /api/servers/:id          # Détails serveur
POST   /api/servers              # Ajouter serveur
PUT    /api/servers/:id          # Modifier serveur
DELETE /api/servers/:id          # Supprimer serveur
POST   /api/servers/:id/test     # Test connexion SSH
```

### Backups
```
GET    /api/backups              # Liste backups (avec filtres)
GET    /api/backups/:id          # Détails backup
POST   /api/backups              # Lancer backup (→ Queue)
GET    /api/servers/:id/backups  # Backups d'un serveur
```

### Archives
```
GET    /api/archives             # Liste archives
GET    /api/archives/:id         # Détails archive
POST   /api/archives/:id/mount   # Monter archive
DELETE /api/archives/:id/prune   # Supprimer archive
```

### Jobs (Queue)
```
GET    /api/jobs                 # Liste jobs
GET    /api/jobs/:id             # Status job
DELETE /api/jobs/:id             # Cancel job
GET    /api/jobs/running         # Jobs en cours
```

### Logs
```
GET    /api/logs                 # Liste logs (avec filtres)
GET    /api/logs/tail            # Tail logs (SSE)
```

### Statistics
```
GET    /api/stats/dashboard      # Stats dashboard
GET    /api/stats/server/:id     # Stats serveur
GET    /api/stats/efficiency     # Compression/dedup
```

### SSE (Server-Sent Events)
```
GET    /api/sse/logs             # Stream logs
GET    /api/sse/jobs             # Stream job updates
GET    /api/sse/backups          # Stream backup progress
```

---

## ⚡ Queue System (Redis)

### Architecture

```
┌─────────────┐      ┌───────────┐      ┌──────────────┐
│   API       │─────▶│   Redis   │◀─────│   Worker     │
│ (Producer)  │      │  (Queue)  │      │  (Consumer)  │
└─────────────┘      └───────────┘      └──────────────┘
      │                                         │
      │ 1. Enqueue job                         │ 3. Process job
      │    {type, payload, priority}           │    Execute backup
      │                                         │    Update status
      └─────────────────────────────────────────┘
               2. Job stored with status

Status Flow:
pending → running → completed/failed
```

### Job Structure

```json
{
  "id": "uuid-v4",
  "type": "backup",
  "status": "pending",
  "priority": 10,
  "payload": {
    "server_id": 1,
    "type": "filesystem",
    "user_id": 123
  },
  "result": null,
  "error": null,
  "created_at": "2025-11-06 12:00:00",
  "started_at": null,
  "completed_at": null,
  "attempts": 0,
  "max_attempts": 3
}
```

### Worker Systemd Service

```ini
[Unit]
Description=phpBorg Queue Worker
After=network.target redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/phpBorg
ExecStart=/usr/bin/php /var/www/phpBorg/worker/BackupWorker.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

---

## 📡 Server-Sent Events (SSE)

### Flow SSE

```
Frontend                           Backend
   │                                  │
   │  1. EventSource('/api/sse/logs')│
   ├─────────────────────────────────▶│
   │                                  │ 2. Keep connection open
   │                                  │    Set headers:
   │                                  │    Content-Type: text/event-stream
   │                                  │    Cache-Control: no-cache
   │                                  │
   │  3. ◀─── event: log             │
   │         data: {"message": "..."}│
   │                                  │
   │  4. ◀─── event: log             │
   │         data: {"message": "..."}│
   │                                  │
   │  5. Connection stays open        │
   │     until timeout or close       │
```

### Implementation PHP

```php
class SSEController {
    public function streamLogs() {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        while (true) {
            $logs = $this->getNewLogs();

            foreach ($logs as $log) {
                echo "event: log\n";
                echo "data: " . json_encode($log) . "\n\n";
                flush();
            }

            sleep(1);

            if (connection_aborted()) break;
        }
    }
}
```

### Implementation Vue.js

```javascript
const eventSource = new EventSource('/api/sse/logs');

eventSource.addEventListener('log', (event) => {
  const log = JSON.parse(event.data);
  logs.value.push(log);
});

eventSource.addEventListener('error', () => {
  eventSource.close();
});
```

---

## 🎨 Frontend Vue.js

### Stack

- **Vue 3** (Composition API)
- **Pinia** (State management)
- **Vue Router** (Routing)
- **Axios** (HTTP client)
- **Vite** (Build tool)
- **TailwindCSS** ou **Bootstrap 5** (Styling)

### Structure

```
frontend/src/
├── components/
│   ├── layout/
│   │   ├── Navbar.vue
│   │   ├── Sidebar.vue
│   │   └── Footer.vue
│   ├── servers/
│   │   ├── ServerList.vue
│   │   ├── ServerForm.vue
│   │   └── ServerCard.vue
│   ├── backups/
│   │   ├── BackupList.vue
│   │   ├── BackupProgress.vue
│   │   └── ArchiveTable.vue
│   └── common/
│       ├── Loading.vue
│       ├── Alert.vue
│       └── Modal.vue
├── views/
│   ├── Dashboard.vue
│   ├── Login.vue
│   ├── Servers.vue
│   ├── Backups.vue
│   └── Logs.vue
├── stores/
│   ├── auth.js
│   ├── servers.js
│   ├── backups.js
│   └── jobs.js
├── services/
│   ├── api.js              # Axios instance
│   ├── auth.service.js
│   ├── server.service.js
│   └── backup.service.js
└── router/
    └── index.js
```

---

## 🚀 Plan de Développement Phase par Phase

### **Phase 1 : API + Auth + Frontend Base** (Priorité 1)
**Durée estimée : 4-6h**

**Backend :**
- [ ] Router API
- [ ] JWT Service
- [ ] AuthController (login, refresh, logout)
- [ ] Middleware JWT
- [ ] CORS middleware
- [ ] User table + roles table

**Frontend :**
- [ ] Setup Vite + Vue 3
- [ ] Page Login
- [ ] Auth store (Pinia)
- [ ] API service avec interceptors
- [ ] Router avec guards
- [ ] Layout de base

**Livrables :**
- Login fonctionnel
- Tokens stockés
- Routes protégées
- Dashboard simple

---

### **Phase 2 : Servers Management** (Priorité 1)
**Durée estimée : 3-4h**

**Backend :**
- [ ] ServerController (CRUD)
- [ ] Permissions check

**Frontend :**
- [ ] Page liste serveurs
- [ ] Formulaire ajout serveur
- [ ] Formulaire édition
- [ ] Suppression avec confirmation
- [ ] Test SSH (call API)

**Livrables :**
- CRUD serveurs complet via web
- Validation formulaires
- Feedback utilisateur

---

### **Phase 3 : Queue System + Worker** (Priorité 1)
**Durée estimée : 4-5h**

**Backend :**
- [ ] Redis integration
- [ ] QueueManager
- [ ] Job classes (BackupJob, PruneJob)
- [ ] BackupController → enqueue job
- [ ] JobController (list, status)

**Worker :**
- [ ] BackupWorker.php
- [ ] Job processor
- [ ] Error handling
- [ ] Retry logic
- [ ] Systemd service file

**Frontend :**
- [ ] Bouton "Lancer Backup"
- [ ] Liste des jobs
- [ ] Status badges

**Livrables :**
- Queue fonctionnelle
- Worker systemd
- Jobs visibles dans UI

---

### **Phase 4 : SSE + Real-time Updates** (Priorité 1)
**Durée estimée : 3-4h**

**Backend :**
- [ ] SSEController
- [ ] Stream logs
- [ ] Stream job updates

**Frontend :**
- [ ] EventSource service
- [ ] Composant LogViewer
- [ ] Auto-refresh job status
- [ ] Notifications toast

**Livrables :**
- Logs en temps réel
- Status jobs live
- Notifications

---

### **Phase 5 : Backups & Archives** (Priorité 2)
**Durée estimée : 3-4h**

- [ ] Liste backups/archives
- [ ] Filtres & recherche
- [ ] Détails archive
- [ ] Graphiques stats

---

### **Phase 6 : Configuration & Setup** (Priorité 2)
**Durée estimée : 2-3h**

- [ ] Page setup web
- [ ] Config .env
- [ ] Paramètres globaux

---

### **Phase 7 : Alerting (Optional)** (Priorité 3)
**Durée estimée : 3-4h**

- [ ] Config SMTP
- [ ] Règles alerting
- [ ] Templates emails

---

## 📦 Dépendances

### Backend (Composer)
```json
{
  "require": {
    "firebase/php-jwt": "^6.0",
    "predis/predis": "^2.0",
    "ramsey/uuid": "^4.7"
  }
}
```

### Frontend (NPM)
```json
{
  "dependencies": {
    "vue": "^3.3.0",
    "vue-router": "^4.2.0",
    "pinia": "^2.1.0",
    "axios": "^1.5.0"
  },
  "devDependencies": {
    "vite": "^4.4.0",
    "@vitejs/plugin-vue": "^4.3.0",
    "tailwindcss": "^3.3.0"
  }
}
```

### Système
```bash
# Redis
apt install redis-server

# Systemd (déjà installé)

# Node.js (pour build Vue)
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install nodejs
```

---

## 🎯 Prochaines Étapes

1. **Valider l'architecture** avec toi
2. **Phase 1** : Commencer par API + Auth + Frontend base
3. Tester, itérer, améliorer
4. **Phase 2** : Servers Management
5. Et ainsi de suite...

**Qu'en penses-tu de cette architecture ? On commence par la Phase 1 ?** 🚀
