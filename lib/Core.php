<?php
/**
 * phpBorgCore  - Core lib to use Borg backup with Php
 */


/**
* Class and Function List:
* Function list:
* - __construct()
* - secondsToTime()
* - generateRandomString()
* - getSrv()
* - getIdSrv()
* - backupParams()
* - myExec()
* - borgExec()
* - pruneArchive()
* - parseLog()
* - startReport()
* - checkRemote()
* - updateRepo()
* - snapMysql()
* - removeLvmSnap()
* - checkRepo()
* - backup()
* - getInput()
* - addDb()
* - addSrv()
* Classes list:
* - Core
*/

namespace phpBorg;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use DirectoryIterator;

/**
 * Class Core
 * @package phpBorg
 */
class Core {
    /**
     * @var \stdClass
     */
    protected $params;

    /**
     * @var
     */
    private $parse_err;

    /**
     * Class constructor
     * @param string $borg_binary_path - path of borg executable binary
     * @param string $borg_backup_path - root path of backup repository
     * @param string $borg_config_path - relative path of repository config file
     * @param string $borg_archive_dir - name of backup repository archive
     * @param string $borg_srv_ip_pub  - Public IP of Backup server
     * @param string $borg_srv_ip_priv - Private IP of backup server
     */
    public function __construct($borg_binary_path = '/usr/bin/borg', $borg_config_path = 'conf/borg.conf', $borg_srv_ip_pub = '91.200.205.105', $borg_srv_ip_priv = '10.10.70.70', $borg_backup_path = '/data/backups', $borg_archive_dir = 'backup', $borg_lvmsnap_name = 'phpborg') {
        $this->params = new \stdClass;
        $this->params->borg_binary_path  = $borg_binary_path;
        $this->params->borg_config_path  = $borg_config_path;
        $this->params->borg_srv_ip_pub   = $borg_srv_ip_pub;
        $this->params->borg_srv_ip_priv  = $borg_srv_ip_priv;
        $this->params->borg_backup_path  = $borg_backup_path;
        $this->params->borg_archive_dir  = $borg_archive_dir;
        $this->params->borg_lvmsnap_name = $borg_lvmsnap_name;
    }

    /**
     * secondToTime Method (to get good formated time)
     * @param int $inputSeconds
     * @return string
     */
    private function secondsToTime($inputSeconds) {
        $secondsInAMinute = 60;
        $secondsInAnHour  = 60 * $secondsInAMinute;
        $secondsInADay    = 24 * $secondsInAnHour;

        // Extract days
        $days = floor($inputSeconds / $secondsInADay);

        // Extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours = floor($hourSeconds / $secondsInAnHour);

        // Extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes = floor($minuteSeconds / $secondsInAMinute);

        // Extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds = ceil($remainingSeconds);

        // Format and return
        $timeParts = [];
        $sections = ['d' => (int)$days, 'h' => (int)$hours, 'm' => (int)$minutes, 's' => (int)$seconds, ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $timeParts[] = $value . '' . $name . ($value == 1 ? '' : '');
            }
        }

        return implode('', $timeParts);
    }
    /**
     * generateRandomString Method (to make private passphrase)
     * @param int $length
     * @return string
     */

    private function generateRandomString($length = 64) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.=';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0;$i < $length;$i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1) ];
        }
        return $randomString;
    }

    public function generateCertificate($srv) {
	    $certificateData = array(
		    "countryName" => "US",
		    "countryName" => "US",
		    "localityName" => "Houston",
		    "organizationName" => "DevDungeon.com",
		    "organizationalUnitName" => "Development",
		    "commonName" => "DevDungeon",
		    "emailAddress" => "nanodano@devdungeon.com"
	    );
	    // Generate certificate
	    $privateKey = openssl_pkey_new();
	    $certificate = openssl_csr_new($certificateData, $privateKey);
	    $certificate = openssl_csr_sign($certificate, null, $privateKey, 999 );
	    
	    // Generate PEM file
	    $pem_passphrase = 'abracadabra'; // empty for no passphrase
	    $pem = array();
	    openssl_x509_export($certificate, $pem[0]);
	    openssl_pkey_export($privateKey, $pem[1], $pem_passphrase);
	    $pem = implode($pem);
	    
	    // Save PEM file
	    $pemfile = './server.pem';
	    file_put_contents($pemfile, $pem);
    }

    /**
     * getSrv Method (Get all servers name in db)
     * @param Db $db
     * @return array
     */
    public function getSrv($db) {
        foreach ($db->query("SELECT name,id from servers WHERE active = 1")->fetchAll() as $listsrv) {
            $srv[] = ['name' => $listsrv['name'], 'type' => 'backup', 'id' => $listsrv['id']];
            if (!empty($db->query("SELECT id from db_info WHERE server_id='" . $listsrv['id'] . "'")->fetchArray() ['id'])) $srv[] = ['name' => $listsrv['name'], 'type' => 'mysql', 'id' => $listsrv['id']];
        }
        return $srv;

    }

    /**
     * getIdSrv (Return id of server by name)
     * @param string $srv 
     * @param Db $db
     * @return array
     */
    public function getIdSrv($srv, $db) {
        return $db->query("SELECT name,id from servers WHERE active = 1 AND name='" . $srv . "'")->fetchArray() ['id'];

    }

    /**
     * parseConfig Method (Parse config file from repository)
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|int
     */
    public function backupParams($srv, $type, $db, $log) {
        $checkrepo = (object)$db->query("SELECT * from servers WHERE name='" . $srv . "'")->fetchArray();
        if ($checkrepo->id) {
            $this->serverParams = new \stdClass;
            $this->repoParams   = new \stdClass;
            if ($type == "mysql" || $type == "postgres") {
                $this->dbParams = new \stdClass;
                if (!$this->dbParams = (object)$db->query("SELECT * from db_info WHERE server_id='" . $checkrepo->id . "' AND type='" . $type . "'")->fetchArray()) {
                    $log->error("Error, server does not have config for $type", $srv);
                    $this->serverParams=$this->repoParams=$this->dbParams = null;
                    return false;
                }
            }
            $this->serverParams = $checkrepo;
            if ($this->repoParams = (object)$db->query("SELECT * from repository WHERE server_id='" . $checkrepo->id . "' AND type='" . $type . "'")->fetchArray()) {
                if (empty($this->repoParams->id)) return false;
                $exclude = $backup_path = "";
                $back = explode(',', $this->repoParams->backup_path);
                foreach ($back as $yy) {
                    $backup_path .= trim($yy) . " ";
                }
                $this->repoParams->backup_path = $backup_path;
                $this->repoParams->passphrase = base64_decode($this->repoParams->passphrase);
                if ($this->repoParams->exclude) {
                    $ex = explode(',', $this->repoParams->exclude);
                    foreach ($ex as $zz) {
                        $exclude .= "--exclude " . trim($zz) . " ";
                    }

                    $this->repoParams->exclude = $exclude;
                }
                else $this->repoParams->exclude = null;

                if (isset($this->serverParams->backuptype)) {
                    switch ($this->serverParams->backuptype) {
                        case "internal":
                            $this->serverParams->backuptype = $this->params->borg_srv_ip_priv;
                        break;
                        case "external":
                            $this->serverParams->backuptype = $this->params->borg_srv_ip_pub;
                        break;
                    }
                }
                else {
                    $this->serverParams->backuptype = $this->params->borg_srv_ip_priv;
                }
                return $this->serverParams;
            }
            else {
                $log->error("Error, server does not exist or config file is incorect", $srv);
		$this->serverParams=$this->repoParams=$this->dbParams = null;
                return false;
            }
        }
    }

    /**
     * myExec Method (run program from shell with return management)
     * @param $cmd
     * @param string $input
     * @return array
     */
    private function myExec($cmd, $input = '') {
        $proc = proc_open($cmd, array(
            0 => array(
                'pipe',
                'r'
            ) ,
            1 => array(
                'pipe',
                'w'
            ) ,
            2 => array(
                'pipe',
                'w'
            )
        ) , $pipes);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rtn = proc_close($proc);
        return array(
            'stdout' => $stdout,
            'stderr' => $stderr,
            'return' => $rtn
        );
    }
    /**
     * borgExec Method (execute borg with arguments)
     * @param string $verb
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|void
     */
    public function borgExec($verb, $srv, $type, $db, $log) {
        if (!$this->backupParams($srv, $type, $db, $log)) {
            echo "Error, repository config for '$srv' does not exist";
            $log->error("Error, repository config does not exist", $srv);
            return;
        }
        $e = $this->myExec("export BORG_PASSPHRASE='" . $this->repoParams->passphrase . "';" . $this->params->borg_binary_path . " $verb " . $this->repoParams->repo_path);
        print $e['stdout'];

    }

    /**
     * pruneArchive Method (archive retention management)
     * @param int $keepday
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|void
     */
    public function pruneArchive($keepday, $srv, $type, $db, $log) {
        $log->info("Checking $type retention rules", $srv);
        $deleted = NULL;
        $keepday = $keepday - 1;
        $log->info("Retention policy : keep $keepday  per days, ", $srv);
        $e = $this->myExec("export BORG_PASSPHRASE='" . $this->repoParams->passphrase . "';" . $this->params->borg_binary_path . " prune --save-space --force --list --keep-daily=$keepday --keep-weekly=4 --keep-monthly=1 " . $this->repoParams->repo_path);
        if ($e['return'] == 0) {
            $separator = "\r\n";
            $line = strtok($e['stderr'], $separator);
            while ($line !== false) {
                if (preg_match('/Pruning archive/', $line)) {
                    $id = substr(stristr($line, '[') , 1, strpos($line, ']') - strlen($line));
                    $name = explode(" ", $line) [2];
                    $log->info("removing backup $name", $srv);
                    $db->query("DELETE from archives WHERE archive_id='$id'");
                    if ($db->sql_error()) $log->error("Unable to delete archive in DB: " . $db->sql_error() , $srv);
                }
                $line = strtok($separator);
            }
            return;
        }
        else {
            $log->error("PRUNE ERROR=>\nSTDOUT:\n$e[stdout]\n\nSTDERR:\n$e[stderr]", $srv);
            return 1;
        }
    }

    /**
     * parseLog Method (read output of Borg to get info)
     * @param string $srv
     * @param string $file
     * @param logWriter $log
     * @return array
     */
    public function parseLog($srv, $file, $log) {
        $log->info("Parsing log to extract info", $srv);
        $e = $this->myExec("export BORG_PASSPHRASE='" . $this->repoParams->passphrase . "';" . $this->params->borg_binary_path . " info $file --json");
        $json = $e['stdout'];
        if ($e['return'] == 0) {
            $this->logs = new \stdClass;
            $this->logs = (object)json_decode($json);

            if (isset($this->logs->archives)) foreach ($this->logs->archives as $archives) {
                $this->logs->archives = new \stdClass;
                $this->logs->archives = $archives;
            }
            return $this->logs;
        }
        else {
            $this->logs = null;
            return array(
                'error' => 1,
                'stderr' => $e['stderr'],
                'stdout' => $e['stdout']
            );
        }

    }

    /**
     * startReport Method (Create line entry for task backup and return sql logId)
     * @param Db $db
     * @param int $server_id
     * @param string $type
     * @return int
     */
    public function startReport($db, $server_id, $type) {
        $db->query("INSERT INTO `report` (`server_id`,`type`,`start`) VALUES ('$server_id','$type',Now())");
        return $db->insertId();
    }

    /**
     * checkRemote Method (Create line entry for task backup and return sql logId)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function checkRemote($srv, $log) {
        $log->info("Checking back ssh connexion", $srv);
        $e = $this->MyExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' -o 'StrictHostKeyChecking=no' " . $this->serverParams->host . " \"ssh -q -o 'BatchMode=yes' -o 'ConnectTimeout=3' -o 'StrictHostKeyChecking=no' " . $this->serverParams->host . "@" . $this->serverParams->backuptype . " 'echo 2>&1'\"");
        if ($e['return'] == 0) {
            return 1;
        }
        else {
            $log->error("Back ssh connexion error Return code ($e[return])\n $e[stderr]\n $e[stdout]", $srv);
            return;
        }
    }

    /**
     * updateRepo Method (update MySQL repository statistic )
     * @param object $config
     * @param Db $db
     * @param logWriter $log
     * @return array|object
     */

    public function updateRepo($config, $db, $log) {
        $info = $this->parseLog($config->host, $config->repo, $log);
        $log->info("Updating repository informations", $config->host);
        $db->query("UPDATE IGNORE repository
                                SET
                                   `size`      = '" . $info->cache->stats->total_size . "',
                                   `dsize`     = '" . $info->cache->stats->unique_csize . "',
                                   `csize`     = '" . $info->cache->stats->total_csize . "',
                                   `ttuchunks` = '" . $info->cache->stats->total_unique_chunks . "',
                                   `ttchunks`  = '" . $info->cache->stats->total_chunks . "',
                                   `modified`  = NOW()
                                WHERE
                                   `repo_id`   = '" . $config->repo_id . "'
                          ");
        if ($db->sql_error()) {
            $err = "PARSELOG ERROR=>SQL:\n" . $db->sql_error();
            $log->error($err, $srv);
        }
    }

    /**
     * snapMysql Method (Create LVM snapshot for MySQL atomic backup)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function snapMysql($srv, $log) {
        $log->info("Starting DB Backup", $srv);
        $log->info("Sync MySQL database and create LVM snapshot", $srv);
        $e = $this->myExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->serverParams->host . " \"
                        a=( \`mount\` );[[ \\\${a[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && umount -fl /" . $this->params->borg_lvmsnap_name . " 1>&2
                        b=( \`lvs\` )  ;[[ \\\${b[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && lvremove -f /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " 1>&2
                        mysql -u" . $this->dbParams->db_user . " -p" . $this->dbParams->db_pass . " -h " . $this->dbParams->db_host . " -e 'flush tables with read lock;
                                system lvcreate -s /dev/" . $this->dbParams->vg_name . "/" . $this->dbParams->lvm_part . " -n " . $this->params->borg_lvmsnap_name . " -L" . $this->dbParams->lvsize . " 1>&2;
                                unlock tables;'
                        [[ ! -d /" . $this->params->borg_lvmsnap_name . " ]] && mkdir -p /" . $this->params->borg_lvmsnap_name . " 1>&2;mount /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " /" . $this->params->borg_lvmsnap_name . " 1>&2\"");
        if ($e['return'] == '0') {
            $log->info("LVM snapshot created", $srv);
            return 1;
        }
        else {
            $log->error("LVM snapshot ERROR:", $srv);
            $log->error("STDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'], $srv);
            return 0;
        }

    }

    /**
     * removeLvmSnap Method (Remove LVM snapshot)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function removeLvmSnap($srv, $log) {
        $log->info("Removing LVM snapshot", $srv);
        $e = $this->myExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->serverParams->host . " \"
                        a=( \`mount\` );[[ \\\${a[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && umount -fl /" . $this->params->borg_lvmsnap_name . " 1>&2
                        b=( \`lvs\` )  ;[[ \\\${b[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && lvremove -f /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " 1>&2\"");

        if ($e['return'] == '0') {
            $log->info("LVM snapshot removed", $srv);
        }
        else {
            $log->error("LVM snapshot remove ERROR:", $srv);
            $log->error("STDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'], $srv);
        }
        return;
    }

    /**
     * checkRepo Method (Check if repository exist and create if not)
     * @param string $srv
     * @param logWriter $log
     * @param Db $db
     * @param object $cfg
     * @return bool
     */
    public function checkRepo($srv, $log, $db, $cfg) {
        $log->info("Check $cfg->type repository", $srv);
        if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type)) {
            echo "Creating $cfg->type repository\n";
            mkdir($this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type);
            $passphrase = $this->generateRandomString();
            $exec = $this->myExec('cd ' . $this->params->borg_backup_path . '/' . $srv . ';export BORG_PASSPHRASE="' . $passphrase . '";' . $this->params->borg_binary_path . ' init ' . $cfg->type . ' -e ' . $cfg->encryption);
            if ($exec['return'] == 0) {
                $repoconfig = new \StdClass;
                $repoconfig = (object)parse_ini_file($this->params->borg_backup_path . "/$srv/$cfg->type/config");
                echo "$cfg->type repository created\n";
                $db->query("INSERT IGNORE INTO repository
	        		(`server_id`,`repo_id`,`type`,`retention`,`encryption`,`passphrase`,`repo_path`,`compression`, `ratelimit`, `backup_path`, `exclude`, `modified`)
                            VALUES
                           	('" . $cfg->server_id . "',
                                 '" . $repoconfig->id . "',
                                 '" . $cfg->type . "',
                                 '" . $cfg->keep . "',
                                 '" . $cfg->encryption . "',TO_BASE64('" . $passphrase . "'),
                                 '" . $this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type . "',
                                 '" . $cfg->compression . "',
                                 '" . $cfg->ratelimit . "',
                                 '" . $cfg->mysql_path . "',
                                 '" . $cfg->exclude . "',
                                 NOW()
                                )
			   ");
                if ($cfg->type == "mysql" || $cfg->type == "postgres") {
                    $db->query("UPDATE db_info set repo_id='" . $repoconfig->id . "' WHERE server_id='" . $cfg->server_id . "' AND type='" . $cfg->type . "'");
                }
            }
            else {
                echo "Error When creating $cfg->type DB repository:\n" . $exec['stdout'] . "\n" . $exec['stderr'];
                return 1;
            }

        }
        else {
            echo "$cfg->type DB Repository already exist";
            return;

        }
    }

    /**
     * backup Method (run backup for specified server)
     * @param $srv
     * @param logWriter $log
     * @param Db $db
     * @param int $reportId
     * @param string $type
     * @return array|object
     */

    public function backup($srv, $log, $db, $reportId, $type = 'backup') {
        $log->info("Starting backup:  $srv ($type)", $srv);
        if (!$this->backupParams($srv, $type, $db, $log)) {
            $log->error("Error, repository config does not exist", $srv);
            $tmplog = "Error,$srv $type repository config does not exist\n";
            $db->query("UPDATE IGNORE report  set `error`='1',log='$tmplog' WHERE id=$reportId");
            return (object)['error' => 1, 'log' => $tmplog];
        }
        else {
            if ($this->checkRemote($srv, $log)) {
                $db->query("UPDATE IGNORE report  set `curpos`= '" . $this->serverParams->host . "' WHERE id=" . $reportId);
                $this->pruneArchive($this->repoParams->retention, $srv, $type, $db, $log);
                $archivename = $type . "_" . date("Y-m-d_H:i:s");
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repoParams->repo_path));
                foreach ($iterator as $item) {
                    chmod($item, 0700);
                    chgrp($item, $this->serverParams->host);
                    chown($item, $this->serverParams->host);
                }
                $_type = $snap_path = null;
                if ($type == "mysql") {
                    if (!$this->snapMysql($srv, $log)) return;
                    $snap_path = "/" . $this->params->borg_lvmsnap_name;
                }
                if ($type != "backup") $_type = $type;
                else $_type = "data";

                $log->info("Running $_type Backup ...", $srv);
                $tmplog = $backuperror = '';
                $e = $this->myExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->serverParams->host . " \"export BORG_PASSPHRASE='" . $this->repoParams->passphrase . "'
                                                " . $this->params->borg_binary_path . " create  --compression " . $this->repoParams->compression . " " . $this->repoParams->exclude . " ssh://" . $this->serverParams->host . "@" . $this->serverParams->backuptype . $this->repoParams->repo_path . "::$archivename " . $snap_path . $this->repoParams->backup_path . "\"");
                if ($e['return'] == '0') {
                    if ($type == "mysql") $this->removeLvmSnap($srv, $log);
                    $info = $this->parseLog($srv, $this->repoParams->repo_path . "::$archivename", $log);
                    if (is_object($info)) {
                        $durx = $this->secondsToTime($info->archives->duration);
                        $log->info("Backup completed in $durx", $srv);
                        $db->query("INSERT IGNORE INTO archives
                                  	(`id`, `repo_id`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`)
                                    VALUE
                                        ( NULL,
                                          '" . $this->repoParams->repo_id . "',
                                          '" . $archivename . "',
                                          '" . $info->archives->id . "',
                                          '" . $info->archives->duration . "',
                                          '" . $info->archives->start . "',
                                          '" . $info->archives->end . "',
                                          '" . $info->archives->stats->compressed_size . "',
                                          '" . $info->archives->stats->deduplicated_size . "',
                                          '" . $info->archives->stats->original_size . "',
                                          '" . $info->archives->stats->nfiles . "'
                                         )
                                    ");
                        if ($db->sql_error()) {
                            $err = $config['host'] . "=>\nPARSELOG ERROR=>SQL:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }
                        $db->query("UPDATE IGNORE repository
                                    SET
                                    	`size`      = '" . $info->cache->stats->total_size . "',
                                        `dsize`     = '" . $info->cache->stats->unique_csize . "',
                                        `csize`     = '" . $info->cache->stats->total_csize . "',
                                        `ttuchunks` = '" . $info->cache->stats->total_unique_chunks . "',
                                        `ttchunks`  = '" . $info->cache->stats->total_chunks . "',
                                        `modified`  = NOW()
                                    WHERE
                                        `repo_id`   = '" . $this->repoParams->repo_id . "'
                                   ");
                        if ($db->sql_error()) {
                            $err = $config['host'] . "=>\nPARSELOG ERROR=>SQL:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }

                        $db->query("UPDATE IGNORE report
                                    SET
                                   	 `osize`      = '" . $info->archives->stats->original_size . "',
                                         `csize`      = '" . $info->archives->stats->compressed_size . "',
                                         `dsize`      = '" . $info->archives->stats->deduplicated_size . "',
                                         `dur`        = '" . $info->archives->duration . "',
                                         `nb_archive` = 1,
                                         `nfiles`     = '" . $info->archives->stats->nfiles . "'
                                     WHERE
                                         `id`               = '" . $reportId . "'
                                   ");
                        if ($db->sql_error()) {
                            $err = $config['host'] . "=>\nSQL UPDATE ERROR:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }
                    }
                    else {
                        $log->error("PARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]", $srv);
                        $backuperror = 1;
                        $tmplog = "$config[host] =>\nBACKUPCONFIG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
                        $db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog", "$reportId");

                    }
                }
                else {
                    $log->error("BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]", $srv);
                    $backuperror = 1;
                    $tmplog = "$srv =>\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'];
                    $db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog", "$reportId");

                }
            }
            else {
                $tmplog = "$srv Connexion error SKIP BACKUP !\n";
                $db->query("UPDATE IGNORE report  set `error`='1',log='$tmplog' WHERE id=$reportId");
                $log->error("Connexion error SKIP BACKUP !", $srv);
                return (object)['error' => $backuperror, 'log' => $tmplog];
            }

        }
        return (object)['error' => $backuperror, 'log' => $tmplog, 'osize' => $info->archives->stats->original_size, 'csize' => $info->archives->stats->compressed_size, 'dsize' => $info->archives->stats->deduplicated_size, 'dur' => $info->archives->duration, 'nbarchive' => 1, 'nfiles' => $info->archives->stats->nfiles];
    }

    /**
     * getInput Method (Get input from console)
     * @return int
     */
    public function getInput() {
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        return trim($line);
    }

    /**
     * addDb Method (Add Mysql config from existing server to backup database)
     * @param Db $db
     * @param logWriter $log
     * @return bool
     */
    public function addDb($db, $log) {
        echo "[ PARAMETERS ]\n";
        echo "   - Enter the server name : ";
        $srv = $this->getInput();
        if (!$server = (object)$db->query("SELECT * from servers WHERE name='" . $srv . "'")->fetchArray()) die("Server $srv not managed");
        echo "   -Select type: 1 - Mysql  | 2 - Postgres (default 1) : ";
        $_type = $this->getInput();
        switch ($_type) {
            case 1:
                $cfg = new \StdClass;
                $cfg->type = "mysql";
                echo "Enter VG name (default vg) : ";
                $cfg->vg = $this->getInput();
                if (!$cfg->vg) $cfg->vg = 'vg';
                echo "Enter LV name (default root) : ";
                $cfg->lv = $this->getInput();
                if (!$cfg->lv) $cfg->lv = 'root';
                echo "Enter MySQL host (default 127.0.0.1) : ";
                $cfg->db_host = $this->getInput();
                if (!$cfg->db_host) $cfg->db_host = '127.0.0.1';
                echo "Enter MySQL username : ";
                $cfg->db_user = $this->getInput();
                echo "Enter MySQL password : ";
                $cfg->db_pass = $this->getInput();
                echo "Enter MySQL data path (default /var/lib/mysql) : ";
                $cfg->mysql_path = $this->getInput();
                if (!$cfg->mysql_path) $cfg->mysql_path = '/var/lib/mysql';
                echo "   - Enter number of retention point (default 8) : ";
                $cfg->keep = $this->getInput();
                if (!$cfg->keep) $cfg->keep = '8';
                $cfg->encryption = "repokey";
                $cfg->compression = "lz4";
                $cfg->ratelimit = "0";
                $cfg->exclude = "";
                $cfg->server_id = $server->id;
                echo "\n";
                $db->query("INSERT INTO `db_info`
                                                (`type`, `server_id`, `db_host`, `db_user`, `db_pass`, `vg_name`, `lvm_part`, `lvsize`, `mysql_path`)
                                            VALUES
                                                ( '" . $cfg->type . "',
                                                  '" . $server->id . "',
                                                  '" . $cfg->db_host . "',
                                                  '" . $cfg->db_user . "',
                                                  '" . $cfg->db_pass . "',
                                                  '" . $cfg->vg . "',
                                                  '" . $cfg->lv . "',
                                                  '500M',
                                                  '" . $cfg->mysql_path . "'
                                                )
                                           ");

                $this->checkRepo($srv, $log, $db, $cfg);
            }
        }

        /**
         * addSrv Method (Add a server to backup)
         * @return bool
         */
        public function addSrv($db, $log) {
            echo "[ PARAMETERS ]\n";
            echo "   - Enter the server name : ";
            $srv = $this->getInput();

            echo "   - Enter number of retention point (default 8) : ";
            $keep = $this->getInput();
            if (!$keep) $keep = 8;

            echo "   - Specify SSH port (default 22) : ";
            $sshport = $this->getInput();
            if (!$sshport) $sshport = 22;
            echo "\n\n[ REMOTE CONFIG ]\n";
            echo "   - Connecting to $srv\n";
            echo "   - Making SSH key ===================> ";
            $exec = $this->myExec('ssh -tt  -o "StrictHostKeyChecking=no" -p ' . $sshport . " " . $srv . " \"if [ ! -f /root/.ssh/id_rsa ]; then ssh-keygen -t rsa -b 2048 -f /root/.ssh/id_rsa -N '' &> /dev/null && echo '[OK]' || echo 'Failed to create key'; else  echo '[SKIP] key already exist'; fi\"");
            if ($exec['return'] == 0) {
                echo $exec['stdout'];
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                die;
            }
            echo "   - Get SSH key ======================> ";
            $exec = $this->myExec('ssh -tt -p ' . $sshport . " " . $srv . " \"cat /root/.ssh/id_rsa.pub\"");
            if ($exec['return'] == 0) {
                $sshkey = $exec['stdout'];
                echo "[OK]\n";
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                die;
            }
            echo "   - Installation of BorgBackup =======> ";
            $exec = $this->myExec('ssh -tt -p ' . $sshport . " " . $srv . " \"if [ `uname -m` == 'i686' ]; then plateforme='32'; else plateforme='64'; fi; if [ ! -f /usr/bin/borg ]; then wget --no-check-certificate -q -O /usr/bin/borg https://github.com/borgbackup/borg/releases/download/1.1.7/borg-linux\\\$plateforme  ; chmod +x /usr/bin/borg && echo '[OK]' || echo '[FAIL] =>  Unable to install BorgBackup' ; else echo '[SKIP] BorgBackup already installed'; fi\"");
            if ($exec['return'] == 0) {
                echo $exec['stdout'];
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                die;
            }
            echo "\n\n[ LOCAL CONFIG ]\n";
            echo "   - Creating User ====================> ";
            if (!posix_getpwnam($srv)) {
                $exec = $this->myExec('useradd -d ' . $this->params->borg_backup_path . '/' . $srv . ' -m ' . $srv);
                if (posix_getpwnam($srv)) echo "[OK]\n";
                else {
                    echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                    die;
                }

            }
            else {
                echo "[SKIP] User '$srv' already exist.\n";
            }
            echo "   - Config SSH key ===================> ";

            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/.ssh')) mkdir($this->params->borg_backup_path . '/' . $srv . '/.ssh');
            file_put_contents($this->params->borg_backup_path . '/' . $srv . '/.ssh/authorized_keys', $sshkey);
            if (file_exists($this->params->borg_backup_path . '/' . $srv . '/.ssh/authorized_keys')) echo "[OK]\n";
            echo "   - Creating repository ==============> ";
            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/backup')) {
                $passphrase = $this->generateRandomString();
                $encryption = "repokey";
                $exec = $this->myExec('cd ' . $this->params->borg_backup_path . '/' . $srv . ';export BORG_PASSPHRASE="' . $passphrase . '";' . $this->params->borg_binary_path . ' init backup -e ' . $encryption . ' && echo "[OK]" || echo "[FAIL]"');
                if ($exec['return'] == 0) {
                    echo $exec['stdout'];
                }
                else {
                    echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                    die;
                }

            }
            else {
                echo "[SKIP] Repository already exist\n";
            }
            echo "   - Creating restore directory =======> ";
            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/restore')) {
                mkdir($this->params->borg_backup_path . '/' . $srv . '/restore');
                echo "[OK]\n";
            }
            else {
                echo "[SKIP] Restore directory already exist\n";
            }
            echo "   - Add server configuration to DB  ==> ";
            $check = $db->query("SELECT name from servers where name='" . $srv . "'")->fetchArray();
            if (!$check) {
                $ratelimit = 0;
                $compression = "lz4";
                $db->query("INSERT INTO `servers`
                                        (`name`, `host`, `port`,`ssh_pub_key`, `active`)
                                    VALUES
                                        ( '" . $srv . "',
                                          '" . $srv . "',
                                          '" . $sshport . "',
                                          '" . $sshkey . "',
                                          1
                                        )
                                  ");
                if ($db->sql_error()) {
                    $log->error("Unable to insert server in DB: " . $db->sql_error() , $srv);
                    echo '[FAILED] SQL error';
                }
                else {
                    echo "[OK]\n";
                    $server_id = $db->insertId();
                }
            }
            else {
                echo "[SKIP] Configuration file already exist\n";
                $repoconfig->id = $check['repo_id'];
            }
            echo "   - Set the rights to repository =====> ";
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->params->borg_backup_path . '/' . $srv, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                chmod($item, 0700);
                chgrp($item, $srv);
                chown($item, $srv);
            }
            echo "[OK]\n";
            echo "   - Add repository in MySQL ==========> ";
            $repoconfig = new \StdClass;
            $repoconfig = (object)parse_ini_file($this->params->borg_backup_path . "/$srv/backup/config");
            $check_repo_sql = $db->query("SELECT id from repository WHERE repo_id='" . $repoconfig->id . "'")->fetchArray();
            if (!$check_repo_sql) {
                $db->query("INSERT IGNORE INTO repository
                                        (`server_id`,`repo_id`,`type`,`retention`,`encryption`,`passphrase`,`repo_path`,`compression`, `ratelimit`, `backup_path`, `exclude`, `modified`)
                                    VALUES
                                        ( '" . $server_id . "',
                                          '" . $repoconfig->id . "',
                                          'backup',
                                          '" . $keep . "',
                                          '" . $encryption . "',TO_BASE64('" . $passphrase . "'),
                                          '" . $this->params->borg_backup_path . '/' . $srv . '/backup' . "',
                                          '" . $compression . "',
                                          '" . $ratelimit . "',
                                          '/',
                                          ' /proc,/dev,/sys,/tmp,/run,/var/run,/lost+found,/var/cache/apt/archives,/var/lib/mysql,/var/lib/lxcfs',
                                          NOW()
                                        )
                                  ");
                if ($db->sql_error()) {
                    $log->error("Unable to insert repository in DB: " . $db->sql_error() , $srv);
                    echo '[FAILED] SQL error';
                }
                else {
                    echo "[OK]\n";
                }
            }
            else {
                echo "[SKIP] Repository exist in DB.\n";
            }

            echo "\n[FINISH] Server '$srv' Succesfuly added\n";

        }

    }
    

