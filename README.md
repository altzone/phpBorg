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

