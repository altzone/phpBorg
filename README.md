# phpBorg
PHP frontend to using Borg Backup. (Manage, statistics, logs and config)  
## Info
This PHP script use BorgBackup (documentation: https://borgbackup.readthedocs.io/)  
BorgBackup project: https://github.com/borgbackup/borg  
  
### Screenshot
![alt text](https://github.com/altzone/phpBorg/blob/master/phpBorg.png)
  
  
## :package: INSTALLATION
#### :warning: Requirements
* PHP 7 or later
  * php-cli
  * php-mysql
* Web server with PHP to view status page
* MariaDB or MySQL server
* Borg Backup >= 1.1.7

Clone repository:
```sh
git clone https://github.com/altzone/phpBorg.git
```
## :arrow_forward: Usage:   
#### Add server
```sh
./phpborg.php add
```
Result:
```
[ PARAMETERS ]
   - Enter the server name : altzone.net
   - Enter number of retention point (default 8) :
   - Specify SSH port (default 22) :


[ REMOTE CONFIG ]
   - Connecting to altzone.net
   - Making SSH key ===================> [SKIP] key already exist
   - Get SSH key ======================> [OK]
   - Installation of BorgBackup =======> [SKIP] BorgBackup already installed


[ LOCAL CONFIG ]
   - Creating User ====================> [OK]
   - Creating repository ==============> [OK]
   - Creating restore directory =======> [OK]
   - Creating configuration directory => [OK]
   - Making configuration file ========> [OK]
   - Set the rights to repository =====> [OK]

 Server altzone.net Succesfuly added
 ```
 #### backup a server:
 ``` sh
 ./phpborg.php backup altzone.net
 ```
 Log Ouput:
 ```
[18-Nov-2019 22:39:15] : [INFO] [CORE] - Starting backup:  altzone.net
[18-Nov-2019 22:39:15] : [INFO] [ALTZONE.NET] - Checking backlink ssh connexion
[18-Nov-2019 22:39:16] : [INFO] [ALTZONE.NET] - Checking retention rules
[18-Nov-2019 22:39:20] : [INFO] [ALTZONE.NET] - removing backup backup_2019-11-05_03:32:12
[18-Nov-2019 22:39:20] : [INFO] [ALTZONE.NET] - Running Backup ...
[18-Nov-2019 22:39:39] : [INFO] [ALTZONE.NET] - Parsing log to extract info
[18-Nov-2019 22:39:41] : [INFO] [ALTZONE.NET] - Backup completed in 13s
 ```
 #### Show repository informations:
 ``` sh
 ./phpborg.php info fax
 ```
 Ouput:
 ```
Repository ID: 74c43fbfcdb87e84c275c59c1359fa92b75874abe517639ccb70bff4c859ef0b
Location: /data/backups/fax/backup
Encrypted: Yes (repokey)
Cache: /root/.cache/borg/74c43fbfcdb87e84c275c59c1359fa92b75874abe517639ccb70bff4c859ef0b
Security dir: /root/.config/borg/security/74c43fbfcdb87e84c275c59c1359fa92b75874abe517639ccb70bff4c859ef0b
------------------------------------------------------------------------------
                       Original size      Compressed size    Deduplicated size
All archives:               46.53 GB             30.08 GB              3.31 GB

                       Unique chunks         Total chunks
Chunk index:                  112870              1305760
 ```
 #### List repository backup point:
 ``` sh
 ./phpborg.php list fax
 ```
 Ouput:
 ```
backup_2019-12-21_17:26:28           Sat, 2019-12-21 17:26:31 [5a81af602d56b372bc5068587dd20ae35a48ecd678ae3b2fbf59152169abeaeb]
backup_2019-12-29_01:11:04           Sun, 2019-12-29 01:11:08 [fe7141e57759d9a0b415c9cfe174be3f4124be7d9798ffb2e7d9cc65e751aae8]
backup_2019-12-30_01:19:21           Mon, 2019-12-30 01:19:25 [2a1bd3c5df0f8694f77c8cbc9cbdf919c2319d81a09ba27bfcd0fa75cbb5fff6]
backup_2019-12-31_01:17:25           Tue, 2019-12-31 01:17:28 [d701c3ff18f4d72f881da9cfaa602e83096bbd5c28102e73f1f99143bac4d947]
backup_2020-01-01_01:21:29           Wed, 2020-01-01 01:21:33 [6a1b847942b44b49a1c8dd3cf0519899828a34a32dad27692515b2f39c3a6f6d]
backup_2020-01-02_01:26:41           Thu, 2020-01-02 01:26:44 [6113ff175d74cfd13d9a88885ad612bc33e953894ca5f0a4ef64ed2f3abebc7c]
backup_2020-01-03_01:23:32           Fri, 2020-01-03 01:23:36 [aee5c6205c03e4902ef63fd4ac3f5fd07ca67d96702b4e464bc21e90bad58190]
backup_2020-01-04_01:19:16           Sat, 2020-01-04 01:19:19 [96547b09b517b5d783b0aeb70d2cf6bb8423c7afee60ff964f14a84221a6691b]
backup_2020-01-06_01:18:20           Mon, 2020-01-06 01:18:23 [c6b5c101327962f0efbe7729911c82b8c8498dcd23d40732b1cf5be7f4fed574]

 ```
 #### Mount repository backup point:
 THis option mount the archive and drop a shell, type exit to quit restore shell.
 ``` sh
 ./phpborg.php mount fax
 ```
 Ouput:
 ```
 [ Backup Choice ]
 0 - 2019-12-21 17:02:59
 1 - 2020-06-07 23:01:36
 2 - 2020-06-07 23:01:50
 3 - 2020-06-14 01:19:17

-------------------------
 Enter Backup number to mount : 3

Mounting fax's backup_2020-06-14_01:17:39 in /data/backups/fax/restore Please Wait ...
=> Backup was succesfuly mounted, type exit to umount


[/data/backups/fax/restore]
fax BACKUP->
```
if you want to mount other backup then type exit :
```
Unmounting fax Backup --> [OK]
=> Backup session finished
 Do you want to mount another backup? [Y / N] Default No :
 ```
 

 
 ## :wrench: CONFIGURATION
 #### Config PATH  
 For the moment, config path is store on PHP class:
 /lib/Core.php: line 36:
 ```
 public function __construct($borg_binary_path='/usr/bin/borg',$borg_config_path = 'conf/borg.conf',$borg_srv_ip_pub  = '91.200.204.28',$borg_srv_ip_priv = '10.10.69.15',$borg_backup_path = '/data0/backup',$borg_archive_dir = 'backup')
```
#### DB config  
For the moment, DB config is store on PHP class:
/lib/Db.php: line 42:
```
public function __construct($dbhost = '10.10.30.60', $dbuser = 'backup', $dbpass = 'QSDJSQDKJSQDJK34434', $dbname = 'backup', $charset = 'utf8')
```


## :broom: Nettoyage post-sauvegarde

phpBorg peut supprimer, sur les machines sauvegardées, les fichiers qui se
régénèrent seuls : journaux, caches de paquets, cache de construction Docker.
Le nettoyage tourne **après** la sauvegarde et **seulement si elle a réussi** :
si le nettoyage se passe mal, l'archive de la nuit existe déjà.

### Principe de sûreté

La base ne contient jamais de commande, seulement des mots-clés. La
correspondance mot-clé → commande vit dans `lib/Cleanup.php`, versionnée et
relue. Une commande libre en base, ce serait un `rm -rf` à une faute de frappe
près.

La colonne `servers.cleanup` vaut `NULL` par défaut : sans configuration
explicite, rien ne se passe sur aucune machine.

### Clés disponibles

Niveau **A** — aucun risque, ne détruit que du log ou du régénérable local :

| clé | effet |
|---|---|
| `journald` | purge les journaux systemd au-delà de la rétention, **tous namespaces compris** (netdata en crée un, invisible d'un `vacuum` ordinaire) |
| `journal-orphelins` | supprime les journaux d'une ancienne identité de la machine, que `journalctl` ne purge jamais |
| `apt-cache` | `apt-get clean` |
| `logs-anciens` | logs tournés (`*.gz`, `*.1`…) au-delà de l'âge configuré |
| `coredump` | `/var/crash` et `/var/lib/systemd/coredump` |
| `docker-dangling` | images Docker sans tag (`<none>`) |
| `lvm-orphan` | snapshot LVM `phpborg` resté en place après un arrêt brutal |

Niveau **B** — aucune perte de données, mais un coût (rebuild, re-pull) :

| clé | effet |
|---|---|
| `docker-buildcache` | cache de construction Docker, avec filtre d'âge |
| `docker-images` | images Docker inutilisées, avec filtre d'âge |
| `cache-langages` | caches npm, yarn, pip, composer |

Volontairement **absents** : `docker volume prune` (un volume dit orphelin est
souvent un volume de base dont le conteneur a été recréé — perte définitive),
`docker system prune -a` (trop large) et `apt-get autoremove` (peut retirer un
noyau).

### Configuration

Une machine peut cumuler autant de clés que voulu. `servers.cleanup` accepte :

```
journald,apt-cache            des clés explicites
@docker                       un profil nommé, défini dans settings
@base,docker-images           un mélange des deux
A                             tout le niveau A
```

Les profils vivent dans `settings`, sous la forme `cleanup_profile_<nom>`, et
peuvent se référencer entre eux :

```sql
cleanup_profile_base    = journald,journal-orphelins,apt-cache,logs-anciens,coredump,lvm-orphan
cleanup_profile_docker  = @base,docker-dangling,docker-buildcache,docker-images
cleanup_profile_builder = @base,docker-dangling,docker-images
```

Réglages globaux, également dans `settings` :

| clé | défaut | rôle |
|---|---|---|
| `cleanup_timeout` | `600` | délai maximal par opération, en secondes |
| `cleanup_journal_keep` | `30d` | rétention des journaux systemd |
| `cleanup_logs_age` | `90` | âge minimal des logs tournés supprimés, en jours |
| `cleanup_images_until` | `720h` | âge minimal des images Docker purgées (vide = aucun filtre) |
| `cleanup_cache_until` | `168h` | âge minimal du cache de build purgé (vide = aucun filtre) |

### Utilisation

```bash
phpborg cleanup <serveur> --dry-run     # simule et chiffre, ne touche à rien
phpborg cleanup all --dry-run -p8       # tout le parc, 8 machines en parallèle
phpborg cleanup <serveur>               # exécute la configuration du serveur
phpborg cleanup <serveur> --keys=journald,apt-cache
```

En simulation, **tout le catalogue est évalué** pour permettre de décider, et
les clés réellement activées en base sont marquées d'une étoile. En exécution
réelle, seules les clés configurées sont appliquées.

Le gain retenu est l'espace effectivement rendu au système de fichiers (`df`
avant / après), pas la somme des estimations : les postes Docker se recouvrent
partiellement. Il est enregistré dans `report.cleanup_freed` et apparaît dans
le rapport de sauvegarde.
