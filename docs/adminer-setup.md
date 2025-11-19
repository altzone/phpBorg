# Adminer Setup for Instant Recovery

## 📋 Overview

phpBorg uses **Adminer** (lightweight database management tool) to provide web-based access to Instant Recovery database sessions. When you start an Instant Recovery session, phpBorg automatically launches an Adminer container that connects to the mounted database.

## 🏗️ Architecture

```
User clicks "Open Database Admin"
  ↓
Browser opens: http://SERVER_IP:{admin_port}/?phpborg_token={token}
  ↓
Adminer validates token via phpBorg API
  ↓
Access granted → User browses database (read-only)
```

## 🐳 Docker Image Build

The custom Adminer image is built automatically on first use. To build manually:

```bash
cd /opt/newphpborg/phpBorg
docker build -t phpborg/adminer:latest docker/adminer/
```

### Image Contents
- **Base**: `adminer:latest` (official Adminer image ~5MB)
- **Plugin**: `phpborg-auth-plugin.php` (custom authentication)
- **Design**: `pepa-linha-dark` (optional dark theme)

## 🔐 Security

### Token Authentication
- **Token generation**: 64-character random hex (32 bytes)
- **Storage**: `instant_recovery_sessions.admin_token`
- **Validation**: Via `POST /api/instant-recovery/validate-admin`
- **Expiration**: Token invalidated when session stops

### Network Isolation
- Adminer runs on random port (30000-40000 range)
- Only accessible via token-authenticated URL
- No password bypass - token validation required

### Port Randomization
```php
$adminPort = random_int(30000, 40000);
```
- Prevents port scanning attacks
- Unique port per session
- Avoids conflicts with phpBorg API (8080)

## 📝 Installation Steps

### 1. Build Docker Image

**Option A: Automatic** (recommended)
- Image builds automatically on first Instant Recovery start
- No manual action required

**Option B: Manual** (for testing)
```bash
cd /opt/newphpborg/phpBorg
docker build -t phpborg/adminer:latest docker/adminer/
```

### 2. Verify Image

```bash
docker images | grep phpborg/adminer
```

Expected output:
```
phpborg/adminer   latest    abc123def456    5 minutes ago    85.2MB
```

### 3. Test Adminer Container (Optional)

```bash
# Start test container
docker run -d --name test_adminer \
  --network host \
  -p 35000:8080 \
  phpborg/adminer:latest

# Check if running
docker ps | grep test_adminer

# Test access
curl -I http://127.0.0.1:35000/

# Cleanup
docker stop test_adminer && docker rm test_adminer
```

## 🔧 Configuration Files

### docker/adminer/Dockerfile
```dockerfile
FROM adminer:latest

# Copy custom phpBorg authentication plugin
COPY phpborg-auth-plugin.php /var/www/html/plugins-enabled/

# Set Adminer design (optional)
ENV ADMINER_DESIGN=pepa-linha-dark

# Expose port (will be overridden by docker run -p)
EXPOSE 8080
```

### docker/adminer/phpborg-auth-plugin.php
Custom PHP plugin that:
1. Extracts `phpborg_token` from URL query parameter
2. Validates token via phpBorg API (`POST /api/instant-recovery/validate-admin`)
3. Returns connection credentials on successful validation
4. Blocks access if token is invalid or session inactive

## 🚀 Usage Workflow

### 1. Start Instant Recovery
```bash
POST /api/instant-recovery/start
{
  "archive_id": 123,
  "deployment_location": "local"
}
```

Response includes `admin_token` and `admin_port`:
```json
{
  "session_id": 5,
  "admin_port": 35123,
  "admin_token": "a1b2c3d4e5f6..."
}
```

### 2. Access Adminer
Click "🗄️ Open Database Admin" in phpBorg UI, which opens:
```
http://91.200.205.105:35123/?phpborg_token=a1b2c3d4e5f6...&phpborg_server=127.0.0.1:15432&phpborg_username=postgres
```

### 3. Browse Database
- Adminer validates token automatically
- Full database browser interface
- Read-only access (Instant Recovery limitation)
- Query execution, table viewing, export, etc.

### 4. Stop Session
Clicking "Stop Session" in phpBorg will:
1. Stop PostgreSQL/MySQL container
2. Stop Adminer container
3. Unmount Borg archive
4. Invalidate admin token

## 🐛 Troubleshooting

### Problem: Adminer container fails to start

**Symptoms:**
- Error: "Failed to start Adminer container"
- Instant Recovery works but no admin access

**Solutions:**
```bash
# Check Docker is running
sudo systemctl status docker

# Check if port is available
netstat -tuln | grep 35000

# Check Docker logs
docker logs phpborg_adminer_session_5

# Rebuild image
docker rmi phpborg/adminer:latest
docker build -t phpborg/adminer:latest /opt/newphpborg/phpBorg/docker/adminer/
```

### Problem: "Access denied: Missing phpBorg authentication token"

**Cause:** URL accessed without `phpborg_token` parameter

**Solution:** Always access Adminer via phpBorg "Open Database Admin" button

### Problem: Token validation fails

**Symptoms:**
- Login fails despite correct URL
- Error in logs: "Token validation failed"

**Solutions:**
```bash
# Check API is accessible
curl -X POST http://127.0.0.1/api/instant-recovery/validate-admin \
  -H "Content-Type: application/json" \
  -d '{"token":"test123"}'

# Check session is active
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new \
  -e "SELECT id, status, admin_token FROM instant_recovery_sessions WHERE id=5;"
```

### Problem: Port conflict (8080 already in use)

**Cause:** phpBorg API uses port 8080

**Solution:** Adminer uses random ports (30000-40000), not 8080. If you see port conflicts:
```bash
# Check what's using the port
sudo lsof -i :35000

# Force remove conflicting container
docker stop $(docker ps -q --filter "publish=35000")
docker rm $(docker ps -aq --filter "publish=35000")
```

## 📊 Database Support

### PostgreSQL
- **Driver**: `pgsql`
- **Default user**: `postgres`
- **Default port**: 15432 (Instant Recovery)
- **Auth**: peer/trust (no password)

### MySQL/MariaDB
- **Driver**: `mysql`
- **Default user**: `root`
- **Default port**: 13306 (Instant Recovery)
- **Auth**: passwordless (Instant Recovery limitation)

### MongoDB
- **Driver**: `mongo`
- **Default user**: `admin`
- **Default port**: 27017
- **Auth**: passwordless (Instant Recovery limitation)

## 🔒 Security Best Practices

1. **Token Expiration**: Tokens are session-bound and expire automatically
2. **Port Randomization**: Reduces attack surface
3. **No Direct Access**: Must go through phpBorg authentication
4. **Read-Only Mode**: Instant Recovery databases are read-only
5. **Network Isolation**: Consider firewall rules to restrict Adminer ports

### Firewall Rules (Optional)
```bash
# Allow Adminer ports only from localhost
sudo ufw allow from 127.0.0.1 to any port 30000:40000

# Or allow from specific IP range
sudo ufw allow from 192.168.1.0/24 to any port 30000:40000
```

## 📈 Monitoring

### Check Running Adminer Containers
```bash
docker ps | grep phpborg_adminer
```

### View Adminer Logs
```bash
docker logs phpborg_adminer_session_5
```

### Check Session Status
```bash
mysql -u phpborg_new -p'4Re2q(kyjTwA2]FF' phpborg_new \
  -e "SELECT id, status, admin_port, admin_container_id, created_at
      FROM instant_recovery_sessions
      WHERE status IN ('starting', 'active');"
```

## 🗑️ Cleanup

### Manual Cleanup (if needed)
```bash
# Stop all Adminer containers
docker stop $(docker ps -q --filter "name=phpborg_adminer_session_*")

# Remove all Adminer containers
docker rm $(docker ps -aq --filter "name=phpborg_adminer_session_*")

# Remove Adminer image (will rebuild on next use)
docker rmi phpborg/adminer:latest
```

## 🚀 Deployment Checklist

- [ ] Docker installed and running
- [ ] phpBorg database schema updated (admin_port, admin_token, admin_container_id columns)
- [ ] API route `/instant-recovery/validate-admin` configured
- [ ] Adminer Dockerfile and plugin in `/docker/adminer/`
- [ ] Ports 30000-40000 available (or firewall configured)
- [ ] Test Instant Recovery + Adminer access
- [ ] Verify token validation works
- [ ] Check cleanup on session stop

## 📚 Additional Resources

- **Adminer Official**: https://www.adminer.org/
- **Adminer Plugins**: https://www.adminer.org/en/plugins/
- **Docker Hub**: https://hub.docker.com/_/adminer
- **phpBorg Docs**: `/docs/instant-recovery.md`

---

**Last Updated**: 2025-11-19
**Version**: 1.0.0
