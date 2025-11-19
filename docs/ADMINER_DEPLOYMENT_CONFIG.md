# 📦 Configuration Adminer - Déploiement phpBorg

Ce fichier contient TOUTES les configurations nécessaires pour déployer Adminer avec phpBorg Instant Recovery.

---

## 🗂️ Fichiers à déployer

### 1. Docker - Adminer custom image

**Chemin**: `/opt/newphpborg/phpBorg/docker/adminer/`

```bash
# Créer le répertoire
mkdir -p /opt/newphpborg/phpBorg/docker/adminer

# Copier les fichiers suivants
```

#### `docker/adminer/Dockerfile`
```dockerfile
FROM adminer:latest

# Copy custom phpBorg authentication plugin
COPY phpborg-auth-plugin.php /var/www/html/plugins-enabled/

# Set Adminer design (optional)
ENV ADMINER_DESIGN=pepa-linha-dark

# Expose port (will be overridden by docker run -p)
EXPOSE 8080
```

#### `docker/adminer/phpborg-auth-plugin.php`
Voir le fichier complet dans `/opt/newphpborg/phpBorg/docker/adminer/phpborg-auth-plugin.php`

---

## 📊 Base de données - Migrations SQL

### Migration: Ajouter colonnes Adminer

**Fichier**: À exécuter sur la base `phpborg_new`

```sql
-- Add Adminer support columns to instant_recovery_sessions table
ALTER TABLE instant_recovery_sessions
  ADD COLUMN admin_port INT NULL AFTER db_port,
  ADD COLUMN admin_token VARCHAR(64) NULL AFTER admin_port,
  ADD COLUMN admin_container_id VARCHAR(64) NULL AFTER admin_token;
```

**Vérification**:
```sql
DESCRIBE instant_recovery_sessions;
```

Colonnes attendues:
- `admin_port` (INT, NULL)
- `admin_token` (VARCHAR(64), NULL)
- `admin_container_id` (VARCHAR(64), NULL)

---

## 🔧 Build & Installation

### 1. Build de l'image Docker

**Option A: Script automatique** (recommandé)
```bash
cd /opt/newphpborg/phpBorg
bash bin/build-adminer.sh
```

**Option B: Build manuel**
```bash
cd /opt/newphpborg/phpBorg
docker build -t phpborg/adminer:latest docker/adminer/
```

**Vérification**:
```bash
docker images | grep phpborg/adminer
# Doit afficher: phpborg/adminer   latest   ...
```

---

## 🔐 Sécurité - Ports

### Ports utilisés

| Service | Port(s) | Usage |
|---------|---------|-------|
| phpBorg API | 8080 | API Backend |
| Adminer | 30000-40000 | Port aléatoire par session |
| PostgreSQL IR | 15432+ | Base montée (Instant Recovery) |
| MySQL IR | 13306+ | Base montée (Instant Recovery) |

### Firewall (optionnel mais recommandé)

```bash
# Autoriser Adminer uniquement depuis localhost
sudo ufw allow from 127.0.0.1 to any port 30000:40000

# OU depuis un réseau spécifique
sudo ufw allow from 192.168.1.0/24 to any port 30000:40000

# Vérifier règles
sudo ufw status numbered
```

---

## 🧪 Tests

### Test 1: Vérifier l'image Docker

```bash
docker images | grep phpborg/adminer
```

**Attendu**: Image présente avec tag `latest`

### Test 2: Vérifier colonnes BDD

```bash
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new \
  -e "SHOW COLUMNS FROM instant_recovery_sessions LIKE 'admin%';"
```

**Attendu**: 3 colonnes (admin_port, admin_token, admin_container_id)

### Test 3: Vérifier route API

```bash
# Doit retourner 400 (Missing token) - c'est normal
curl -X POST http://127.0.0.1/api/instant-recovery/validate-admin \
  -H "Content-Type: application/json" \
  -d '{"token":"test"}'
```

**Attendu**: `{"success":false,"error":{"message":"Invalid token"},...}`

### Test 4: Créer Instant Recovery et tester Adminer

1. Créer une session Instant Recovery depuis l'UI
2. Vérifier que le bouton "🗄️ Admin" apparaît dans la TaskBar
3. Cliquer sur "🗄️ Admin" → Doit ouvrir Adminer dans un nouvel onglet
4. Adminer doit se connecter automatiquement à la base

---

## 🐛 Troubleshooting

### Problème: Image Adminer non trouvée

**Symptôme**: `Error: failed to start adminer container`

**Solution**:
```bash
# Rebuild image
docker rmi phpborg/adminer:latest
bash bin/build-adminer.sh
```

### Problème: Port déjà utilisé

**Symptôme**: `bind: address already in use`

**Solution**: Adminer utilise des ports aléatoires (30000-40000), vérifier:
```bash
netstat -tuln | grep -E '3[0-9]{4}'
```

Si conflit persistant:
```bash
# Arrêter tous les Adminer
docker stop $(docker ps -q --filter "name=phpborg_adminer_session_*")
docker rm $(docker ps -aq --filter "name=phpborg_adminer_session_*")
```

### Problème: Token validation échoue

**Symptôme**: Adminer affiche "Invalid token"

**Solution**:
```bash
# Vérifier que la session existe et a un token
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new \
  -e "SELECT id, status, admin_port, admin_token FROM instant_recovery_sessions WHERE status='active';"
```

Si vide → Session n'est pas active ou token non généré

### Problème: Plugin Adminer non chargé

**Symptôme**: Adminer demande login/password normalement

**Solution**:
```bash
# Vérifier que le plugin est dans l'image
docker run --rm phpborg/adminer:latest ls -la /var/www/html/plugins-enabled/
# Doit afficher: phpborg-auth-plugin.php
```

Si absent → Rebuild l'image

---

## 📋 Checklist Déploiement

- [ ] Docker installé et fonctionnel (`docker --version`)
- [ ] Base de données migrée (colonnes admin_* présentes)
- [ ] Image phpborg/adminer:latest buildée
- [ ] Route API `/instant-recovery/validate-admin` configurée
- [ ] Frontend contient bouton "🗄️ Admin" dans TaskBar
- [ ] Test Instant Recovery + Adminer réussi
- [ ] Ports 30000-40000 disponibles (ou firewall configuré)
- [ ] Documentation `adminer-setup.md` lue

---

## 📚 Documentation complète

Pour plus de détails, voir:
- **Setup complet**: `/docs/adminer-setup.md`
- **Instant Recovery**: `/docs/instant-recovery.md` (TODO)
- **Architecture**: `CLAUDE.md` (section Instant Recovery)

---

## 🚀 Déploiement rapide (TL;DR)

```bash
# 1. Migration BDD
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new <<EOF
ALTER TABLE instant_recovery_sessions
  ADD COLUMN admin_port INT NULL AFTER db_port,
  ADD COLUMN admin_token VARCHAR(64) NULL AFTER admin_port,
  ADD COLUMN admin_container_id VARCHAR(64) NULL AFTER admin_token;
EOF

# 2. Build Adminer image
cd /opt/newphpborg/phpBorg
bash bin/build-adminer.sh

# 3. Tester
# → Créer Instant Recovery depuis UI
# → Cliquer "🗄️ Admin" dans TaskBar
# → Vérifier accès Adminer

echo "✅ Adminer deployed successfully!"
```

---

**Date de création**: 2025-11-19
**Version**: 1.0.0
**Auteur**: Claude Code
