# phpBorg
PHP frontend to using Borg Backup. (Manage, statistics, logs and config)  
## Info
This PHP script use BorgBackup (documentation: https://borgbackup.readthedocs.io/)  
BorgBackup project: https://github.com/borgbackup/borg  
  
### Screenshot
![alt text](https://github.com/altzone/phpBorg/blob/master/phpBorg.png)
  
  
### :package: INSTALLATION
#### :warning: Requirements
* PHP 7 or later
  * php-cli
  * php-mysql
* Web server with PHP to view status page
* MariaDB or MySQL server

Clone repository:
```sh
git clone https://github.com/altzone/phpBorg.git
```
### :arrow_forward: Usage:   
Add server
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
 
 


