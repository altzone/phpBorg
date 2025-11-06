# Déploiement de l'architecture sécurisée phpBorg

## Prérequis

L'architecture sécurisée nécessite un utilisateur système dédié `phpborg` pour isoler les opérations de backup.

## Étapes de déploiement

### 1. Créer l'utilisateur phpborg

```bash
# Créer l'utilisateur avec son home directory
sudo useradd -m -s /bin/bash phpborg

# Créer la structure de répertoires nécessaire
sudo mkdir -p /home/phpborg/.ssh/keys
sudo mkdir -p /backup/borg

# Définir les permissions
sudo chown -R phpborg:phpborg /home/phpborg
sudo chmod 700 /home/phpborg/.ssh
sudo chmod 755 /home/phpborg/.ssh/keys

# Donner ownership du répertoire de backup
sudo chown phpborg:phpborg /backup/borg
```

### 2. Appliquer la migration de base de données

```bash
# En tant que root ou avec sudo
cd /opt/newphpborg/phpBorg
php bin/run-migration.php
```

Cela ajoutera les colonnes nécessaires à la table `servers` :
- `ssh_private_key_path`
- `ssh_keys_deployed`
- `backup_server_user`

### 3. Installer le service systemd

```bash
# Copier le fichier service
sudo cp phpborg-worker.service /etc/systemd/system/

# Recharger systemd
sudo systemctl daemon-reload

# Activer et démarrer le service
sudo systemctl enable phpborg-worker
sudo systemctl start phpborg-worker

# Vérifier le statut
sudo systemctl status phpborg-worker
```

### 4. Vérifier les logs

```bash
# Suivre les logs du worker
sudo journalctl -u phpborg-worker -f

# Voir les dernières entrées
sudo journalctl -u phpborg-worker -n 100
```

## Architecture de sécurité

### Isolation par serveur

Chaque serveur distant dispose de :
- **Sa propre paire de clés SSH** (Ed25519) stockée dans `/home/phpborg/.ssh/keys/{serverName}/`
- **Sa clé privée déployée** sur le serveur distant dans `/root/.ssh/phpborg_backup`
- **Son entrée authorized_keys restreinte** sur le serveur de backup

### Restrictions de sécurité

L'entrée dans `~phpborg/.ssh/authorized_keys` force :
```
command="borg serve --restrict-to-path /backup/borg/{serverName}",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty
```

Cela signifie que le serveur distant peut **UNIQUEMENT** :
- Exécuter `borg serve`
- Accéder à SON repository (pas aux autres)
- Aucune autre commande SSH n'est permise

### Workflow de backup

1. Worker (phpborg) → SSH root@serveur_distant
2. Serveur distant exécute : `borg create ssh://phpborg@backup-server/repo`
3. Connexion SSH utilise : `/root/.ssh/phpborg_backup`
4. Backup server accepte UNIQUEMENT `borg serve` pour ce repo

## Permissions requises

### Serveur de backup (phpBorg)
- User `phpborg` doit pouvoir :
  - Générer des clés SSH
  - Écrire dans `/home/phpborg/.ssh/`
  - Écrire dans `/backup/borg/`
  - Lire/écrire dans la base de données
  - Se connecter en SSH aux serveurs distants (en tant que root)

### Serveurs distants
- L'utilisateur utilisé pour la connexion (généralement root) doit :
  - Accepter connexions SSH depuis le serveur de backup
  - Avoir BorgBackup installé
  - Pouvoir se connecter au serveur de backup (clé privée déployée)

## Troubleshooting

### Le worker ne démarre pas

```bash
# Vérifier que l'utilisateur existe
id phpborg

# Vérifier les permissions
ls -la /home/phpborg/.ssh
ls -la /backup/borg

# Vérifier les logs
sudo journalctl -u phpborg-worker -n 50
```

### Erreur de connexion SSH

```bash
# Tester la connexion SSH depuis le serveur de backup
sudo su - phpborg
ssh -o BatchMode=yes root@serveur-distant echo "OK"

# Vérifier les clés générées
ls -la /home/phpborg/.ssh/keys/
```

### Backup échoue

```bash
# Vérifier que la clé est déployée sur le serveur distant
ssh root@serveur-distant "ls -la /root/.ssh/phpborg_backup"

# Vérifier authorized_keys sur backup server
cat /home/phpborg/.ssh/authorized_keys

# Tester borg serve manuellement
sudo su - phpborg
borg serve --restrict-to-path /backup/borg/nom-serveur
```

## Retour en arrière (rollback)

Si nécessaire, pour revenir à l'ancienne configuration :

```bash
# Éditer le fichier service
sudo nano /etc/systemd/system/phpborg-worker.service

# Changer User=phpborg en User=root
# Recharger et redémarrer
sudo systemctl daemon-reload
sudo systemctl restart phpborg-worker
```

**Note** : Cela annule les améliorations de sécurité.
