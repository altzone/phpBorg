<?php
/**
* phpBorgCore  - Core lib to use Borg backup with Php
*/

namespace phpBorg;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use DirectoryIterator;

/**
 * Class Core
 * @package phpBorg
 */
class Core
{
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
    public function __construct($borg_binary_path='/usr/bin/borg',$borg_config_path = 'conf/borg.conf',$borg_srv_ip_pub  = '91.200.204.28',$borg_srv_ip_priv = '10.10.69.15',$borg_backup_path = '/data0/backup',$borg_archive_dir = 'backup') {
            $this->params = new \stdClass;
            $this->params->borg_binary_path  = $borg_binary_path;
            $this->params->borg_config_path  = $borg_config_path;
            $this->params->borg_srv_ip_pub   = $borg_srv_ip_pub;
            $this->params->borg_srv_ip_priv  = $borg_srv_ip_priv;
            $this->params->borg_backup_path  = $borg_backup_path;
            $this->params->borg_archive_dir  = $borg_archive_dir;
	}

    /**
     * secondToTime Method (to get good formated time)
     * @param int $inputSeconds
     * @return string
     */
    private function secondsToTime($inputSeconds)
    {
        $secondsInAMinute = 60;
        $secondsInAnHour = 60 * $secondsInAMinute;
        $secondsInADay = 24 * $secondsInAnHour;

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
        $sections = [
            'd' => (int)$days,
            'h' => (int)$hours,
            'm' => (int)$minutes,
            's' => (int)$seconds,
        ];

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
	    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ/;#@&$%*:!]}[{';
	    $charactersLength = strlen($characters);
	    $randomString = '';
	    for ($i = 0; $i < $length; $i++) {
		    $randomString .= $characters[rand(0, $charactersLength - 1)];
	    }
	    return $randomString;
    }



    /**
     * @return array
     */
    public function getSrv($db) {
	    foreach($db->query("SELECT name from servers WHERE active = 1")->fetchAll() as $listsrv) {
		    $srv[]=$listsrv['name'];
	    }
	    return $srv;

    }


    /**
     * parseConfig Method (Parse config file from repository)
     * @param string $srv
     * @param logWriter $log
     * @return array|bool|int
     */
    public function repoConfig($srv,$db, $log)
    {
	$checkrepo= $db->query("SELECT id from servers WHERE name='".$srv."'")->fetchArray();
	if ($checkrepo) {
            $this->configBackup = new \stdClass;
            $exclude = $backup_path = "";
	    $this->configBackup =(object)$db->query("SELECT servers.*,repository.location AS repo from servers LEFT JOIN repository ON servers.repo_id = repository.repo_id WHERE servers.name='".$srv."'")->fetchArray();
            $back = explode(',', $this->configBackup->backup_path);
            foreach ($back as $yy) {
                $backup_path .= trim($yy) . " ";
            }
	    $this->configBackup->backup_path = $backup_path;
	    $this->configBackup->passphrase = base64_decode($this->configBackup->passphrase);
            $ex = explode(',', $this->configBackup->exclude);
            foreach ($ex as $zz) {
                $exclude .= "--exclude " . trim($zz) . " ";
            }
	    $this->configBackup->exclude = $exclude;

            if (isset($this->configBackup->backuptype)) {
                switch ($this->configBackup->backuptype) {
                    case "internal":
                        $this->configBackup->backuptype = $this->params->borg_srv_ip_priv;
                        break;
                    case "external":
                        $this->configBackup->backuptype = $this->params->borg_srv_ip_pub;
                        break;
                }
            } else {
                $this->configBackup->backuptype = $this->params->borg_srv_ip_priv;
            }
            return $this->configBackup;
        } else {
            $log->error("Error, server does not exist or config file is incorect",$srv);
            $this->configBackup = null;
            return false;
        }
    }

	/**
	 * myExec Method (run program from shell with return management)
     	 * @param $cmd
     	 * @param string $input
     	 * @return array
     	*/
        private function myExec($cmd, $input='') {
                $proc=proc_open($cmd,   array(  0=>array('pipe', 'r'),
                                                1=>array('pipe', 'w'),
                                                2=>array('pipe', 'w')),
                                                $pipes);
                fwrite($pipes[0], $input);fclose($pipes[0]);
                $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
                $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
                $rtn=proc_close($proc);
                return array('stdout'=>$stdout,
                             'stderr'=>$stderr,
                             'return'=>$rtn);
                 }

     /**
      * pruneArchive Method (archive retention management)
      * @param int $keepday
      * @param string $srv
      * @param Db $db
      * @param logWriter $log
      * @return array|bool|void
     */
    public function pruneArchive($keepday, $srv,$db,$log) {
	    	$log->info("Checking retention rules",$srv);
                $deleted = NULL;
                $keepday = $keepday-1;
		$e       = $this->myExec("export BORG_PASSPHRASE='".$this->configBackup->passphrase."';".$this->params->borg_binary_path." prune --save-space --force --list --keep-daily $keepday ".$this->params->borg_backup_path."/".$srv."/".$this->params->borg_archive_dir);
		if ($e['return'] == 0 ) {
			$separator = "\r\n";
			$line = strtok($e['stderr'], $separator);
			while ($line !== false) {
				if (preg_match('/Pruning archive/',$line)) {
					$id=substr(stristr($line,'['),1,strpos($line,']')-strlen($line));
					$name= explode(" ",$line)[2];
					$log->info("removing backup $name",$srv);
					$db->query("DELETE from archives WHERE archive_id='$id'");
					if ($db->sql_error()) $log->error("Unable to delete archive in DB: ".$db->sql_error(),$srv);
				}
				$line = strtok( $separator );
			}
			return;
		} else {
			$log->error("PRUNE ERROR=>\nSTDOUT:\n$e[stdout]\n\nSTDERR:\n$e[stderr]",$srv);
			return 1;
		}
        }

	/**
	 * parseLog Method (read output of Borg to get info)
     * @param $file
     * @return array
     */
    public function parseLog($srv,$file,$log) {
	    $log->info("Parsing log to extract info",$srv);
		$e=$this->myExec("export BORG_PASSPHRASE='".$this->configBackup->passphrase."';".$this->params->borg_binary_path." info $file --json");
                $json=$e['stdout'];
		if ($e['return'] == 0) {
			$this->logs = new \stdClass;
			$this->logs = (object) json_decode($json);
			
			if(isset($this->logs->archives))foreach ($this->logs->archives as $archives) {
				$this->logs->archives = new \stdClass;
				$this->logs->archives = $archives;
			}
			return $this->logs;
		} else {
			$this->logs = null;
			return array('error' => 1, 'stderr' => $e['stderr'], 'stdout'=> $e['stdout']);
		}

	}
     /**
      * startReport Method (Create line entry for task backup and return sql logId)
      * @param Db $db
      * @return int
     */
	public function startReport($db) {
		$db->query("INSERT INTO `report` (`start`) VALUES (Now())");
		return $db->insertId();
	}
     /**
      * checkRemote Method (Create line entry for task backup and return sql logId)
      * @param Db $db
      * @return int
     */
        public function checkRemote($srv,$log) {
		$log->info("Checking back ssh connexion",$srv);
		$e=$this->MyExec("ssh -p " . $this->configBackup->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->configBackup->host . " \"ssh -q -o 'BatchMode=yes' -o 'ConnectTimeout=3' " .$this->configBackup->host."@".$this->configBackup->backuptype." 'echo 2>&1'\"");
		if ($e['return'] == 0) {
			return 1;
		} else {
		$log->error("Back ssh connexion error Return code ($e[return])\n $e[stderr]\n $e[stdout]",$srv);
			return;
		}
        }

     /**
      * updateRepo Method (update MySQL repository statistic )
      * @param $repo
      * @param object $log
      * @param object $db
      * @return array|object
     */

        public function updateRepo($config,$db,$log) {
		$info=$this->parseLog($config->host,$config->repo,$log);
		$log->info("Updating repository informations",$config->host);
		$db->query("UPDATE IGNORE repository set 
			   `size`      = '".$info->cache->stats->total_size."',
			   `dsize`     = '".$info->cache->stats->unique_csize."',
			   `csize`     = '".$info->cache->stats->total_csize."',
			   `ttuchunks` = '".$info->cache->stats->total_unique_chunks."',
			   `ttchunks`  = '".$info->cache->stats->total_chunks."',
			    modified=NOW() 
			WHERE repo_id = '".$config->repo_id."'");
		if ($db->sql_error()) {
                	$err="PARSELOG ERROR=>SQL:\n".$db->sql_error();
                        $log->error($err,$srv);
		}
	}



     /**
      * backup Method (run backup for specified server)
      * @param $srv
      * @param object $log
      * @param object $db
      * @param $reportId
      * @return array|object
     */

	public function backup($srv,$log,$db,$reportId) {
	$log->info("Starting backup:  $srv");
	if (!$this->repoConfig($srv,$db,$log)) {
                $log->error("PARSECONFIG => Error, config file does not exist",$srv);
		$db->query("UPDATE IGNORE report  set `error`='1' WHERE id=$reportId");
		return;
        } else {
		if ($this->checkRemote($srv,$log)) {	
	                $db->query("UPDATE IGNORE report  set `curpos`= '".$this->configBackup->host."' WHERE id=".$reportId);
        	        $this->pruneArchive($this->configBackup->retention,$srv,$db,$log);
			$archivename="backup_".date("Y-m-d_H:i:s");
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->configBackup->repo));
	
			foreach($iterator as $item) {
				chmod($item, 0700);
				chgrp($item, $this->configBackup->host);
				chown($item, $this->configBackup->host);
			}
			$log->info("Running Backup ...",$srv);
			$tmplog = $backuperror= '';
			$e    = $this->myExec("ssh -p " . $this->configBackup->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->configBackup->host . " \"export BORG_PASSPHRASE='".$this->configBackup->passphrase."';/usr/bin/borg create  --compression " . $this->configBackup->compression . " " . $this->configBackup->exclude . " ssh://" . $this->configBackup->host . "@" . $this->configBackup->backuptype.$this->configBackup->repo . "::$archivename " . $this->configBackup->backup_path ."\"");
			if ($e['return'] == '0') {
	                        $info = $this->parseLog($srv,$this->configBackup->repo . "::$archivename",$log);
				if (is_object($info)) {
	                                    $durx=$this->secondsToTime($info->archives->duration);
					    $log->info("Backup completed in $durx",$srv);
	                                    $db->query("INSERT IGNORE INTO archives (`id`, `repo`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`) 
						VALUE ( NULL,
							'" . $this->configBackup->repo . "',
							'$archivename',
							'".$info->archives->id."',
							'".$info->archives->duration."',
							'".$info->archives->start."]',
							'".$info->archives->end."',
							'".$info->archives->stats->compressed_size."]',
							'".$info->archives->stats->deduplicated_size."',
							'".$info->archives->stats->original_size."',
							'".$info->archives->stats->nfiles."'
						)");
					    if ($db->sql_error()) {
						    $err=$config['host']."=>\nPARSELOG ERROR=>SQL:\n".$db->sql_error();
						    $tmplog.=$err;
						    $log->error($err,$srv);
					    }
	                                    $db->query("UPDATE IGNORE repository set 
							`size`      = '".$info->cache->stats->total_size."',
							`dsize`     = '".$info->cache->stats->unique_csize."',
							`csize`     = '".$info->cache->stats->total_csize."',
							`ttuchunks` = '".$info->cache->stats->total_unique_chunks."',
							`ttchunks`  = '".$info->cache->stats->total_chunks."',
							 modified   = NOW() 
							 WHERE nom  = '".$this->configBackup->repo ."'");
					    if ($db->sql_error()) {
						    $err=$config['host']."=>\nPARSELOG ERROR=>SQL:\n".$db->sql_error();
					 	    $tmplog.=$err;
						    $log->error($err,$srv);
					    }
	
	                                    $db->query("UPDATE IGNORE report  set 
							`osize`      = '".$info->archives->stats->original_size."',
							`csize`      = '".$info->archives->stats->compressed_size."',
							`dsize`      = '".$info->archives->stats->deduplicated_size."',
							`dur`        = '".$info->archives->duration."',
							`nb_archive` = 1,
							`nfiles`     = '".$info->archives->stats->nfiles."'
							 WHERE id    = $reportId");
					    if ($db->sql_error()) {
						    $err=$config['host']."=>\nSQL UPDATE ERROR:\n".$db->sql_error();
						    $tmplog.=$err;
						    $log->error($err,$srv);
					    }
	                        } else {
					$log->error("\nPARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]",$srv);
					$backuperror=1;
	                                    $tmplog = "$config[host] =>\nPARSELOG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
	                                    $db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog" , "$reportId");
	
        	                }
                	} else {
			$log->error("BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]",$srv);
			$backuperror=1;
                	$tmplog = "$srv =>\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'];
                	$db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog" , "$reportId");

			}
		} else {
			$log->error("Connexion error SKIP BACKUP !",$srv);
			return;
		}
	
	}
	return (object) ['error' => $backuperror, 'log' => $tmplog, 'osize'=>$info->archives->stats->original_size , 'csize' => $info->archives->stats->compressed_size , 'dsize'=> $info->archives->stats->deduplicated_size, 'dur'=> $info->archives->duration, 'nbarchive' => 1, 'nfiles' => $info->archives->stats->nfiles];
}

     /**
      * getInput Method (Add a server to backup)
      * @return int
     */
        public function getInput() {
                $handle = fopen ("php://stdin","r");
                $line = fgets($handle);
		return trim($line);
	}


     /**
      * addSrv Method (Add a server to backup)
      * @return int
     */
	public function addSrv($db,$log) {
		echo "[ PARAMETERS ]\n";
		echo "   - Enter the server name : ";
		$srv  = $this->getInput();

		echo "   - Enter number of retention point (default 8) : ";
		$keep = $this->getInput();
		if (!$keep) $keep=8;
		
		echo "   - Specify SSH port (default 22) : ";
		$sshport = $this->getInput();
		if (!$sshport) $sshport=22;
		echo "\n\n[ REMOTE CONFIG ]\n";
		echo "   - Connecting to $srv\n";
		echo "   - Making SSH key ===================> ";
		$exec=$this->myExec('ssh -tt -p '.$sshport." ".$srv." \"if [ ! -f /root/.ssh/id_rsa ]; then ssh-keygen -t rsa -b 2048 -f /root/.ssh/id_rsa -N '' &> /dev/null && echo '[OK]' || echo 'Failed to create key'; else  echo '[SKIP] key already exist'; fi\"");
		if ($exec['return'] == 0) {
			echo $exec['stdout'];
		} else {
			echo "Error: ".$exec['stdout']."\n".$exec['stderr']."\n";
			die;
		}
		echo "   - Get SSH key ======================> ";
			$exec=$this->myExec('ssh -tt -p '.$sshport." ".$srv." \"cat /root/.ssh/id_rsa.pub\"");
		if ($exec['return'] == 0) {
			$sshkey=$exec['stdout'];
			echo "[OK]\n";
		} else {
			echo "Error: ".$exec['stdout']."\n".$exec['stderr']."\n";
			die;
		}
		echo "   - Installation of BorgBackup =======> ";
		$exec=$this->myExec('ssh -tt -p '.$sshport." ".$srv." \"if [ `uname -m` == 'i686' ]; then plateforme='32'; else plateforme='64'; fi; if [ ! -f /usr/bin/borg ]; then wget --no-check-certificate -q -O /usr/bin/borg https://github.com/borgbackup/borg/releases/download/1.1.7/borg-linux\\\$plateforme  ; chmod +x /usr/bin/borg && echo '[OK]' || echo '[FAIL] =>  Unable to install BorgBackup' ; else echo '[SKIP] BorgBackup already installed'; fi\"");
                if ($exec['return'] == 0) {
                        echo $exec['stdout'];
                } else {
                        echo "Error: ".$exec['stdout']."\n".$exec['stderr']."\n";
                        die;
		}
		echo "\n\n[ LOCAL CONFIG ]\n";
		echo "   - Creating User ====================> ";
		if (!posix_getpwnam($srv)) {
			$exec=$this->myExec('useradd -d '.$this->params->borg_backup_path.'/'.$srv.' -m '.$srv);
			if (posix_getpwnam($srv)) echo "[OK]\n";

		} else {
			echo "[SKIP] User '$srv' already exist.\n";
		}
		echo "   - Config SSH key ===================> ";

		if (!file_exists($this->params->borg_backup_path.'/'.$srv.'/.ssh')) mkdir($this->params->borg_backup_path.'/'.$srv.'/.ssh');
		file_put_contents($this->params->borg_backup_path.'/'.$srv.'/.ssh/authorized_keys', $sshkey);
		if (file_exists($this->params->borg_backup_path.'/'.$srv.'/.ssh/authorized_keys')) echo "[OK]\n";
		echo "   - Creating repository ==============> ";
		if (!file_exists($this->params->borg_backup_path.'/'.$srv.'/backup')) {
			$passphrase=$this->generateRandomString();
			$encryption="repokey";
			$exec=$this->myExec('cd '.$this->params->borg_backup_path.'/'.$srv.';export BORG_PASSPHRASE="'.$passphrase.'";borg init backup -e '.$encryption.' && echo "[OK]" || echo "[FAIL]"');
	                if ($exec['return'] == 0) {
	                        echo $exec['stdout'];
        	        } else {
                	        echo "Error: ".$exec['stdout']."\n".$exec['stderr']."\n";
                        	die;
                	}

		} else {
			echo "[SKIP] Repository already exist\n";
		}
		echo "   - Creating restore directory =======> ";
		if (!file_exists($this->params->borg_backup_path.'/'.$srv.'/restore')) {
			mkdir($this->params->borg_backup_path.'/'.$srv.'/restore');
			echo "[OK]\n";
		} else {
			echo "[SKIP] Restore directory already exist\n";
		}
		echo "   - Creating configuration directory => ";
		if (!file_exists($this->params->borg_backup_path.'/'.$srv.'/conf')) {
			mkdir($this->params->borg_backup_path.'/'.$srv.'/conf');
			echo "[OK]\n";
		} else {
			echo "[SKIP] conf directory already exist\n";

		}
		echo "   - Add server configuration to DB  ==> ";
		$check = $db->query("SELECT repo_id from servers where name='".$srv."'")->fetchArray();
		$repoconfig= new \StdClass;
		if (!$check) {
			$repoconfig=(object) parse_ini_file($this->params->borg_backup_path . "/$srv/backup/config");
			$ratelimit=0;
			$compression="lz4";
			$db->query("INSERT INTO `servers` (`name`, `host`, `port`, `repo_id`, `compression`, `ratelimit`, `backup_path`, `exclude`, `retention`, `ssh_pub_key`,`passphrase`, `active`) VALUES
				('".$srv."', '".$srv."',".$sshport.", '".$repoconfig->id."', '".$compression."', ".$ratelimit.", '/', ' /proc,/dev,/sys,/tmp,/run,/var/run,/lost+found,/var/cache/apt/archives,/var/lib/mysql,/var/lib/lxcfs ',".$keep.", '".$sshkey."',TO_BASE64('".$passphrase."'), 1)");
                        if ($db->sql_error()) {
                                $log->error("Unable to insert repository in DB: ".$db->sql_error(),$srv);
                                echo '[FAILED] SQL error';
                        } else {
                                echo "[OK]\n";
                        }
		} else {
			echo "[SKIP] Configuration file already exist\n";
			$repoconfig->id=$check['repo_id'];
		}
		echo "   - Set the rights to repository =====> ";
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->params->borg_backup_path.'/'.$srv));
                foreach($iterator as $item) {
			chmod($item, 0700);
			chgrp($item, $srv);
			chown($item, $srv);
		}
		echo "[OK]\n";
		echo "   - Add repository in MySQL ==========> ";
		$check_repo_sql=$db->query("SELECT id from repository WHERE repo_id='".$repoconfig->id."'")->fetchArray();
		if (!$check_repo_sql) {
			$db->query("INSERT IGNORE INTO repository (`repo_id`,`encryption`,`location`,`modified`) VALUES ('".$repoconfig->id."','$encryption','".$this->params->borg_backup_path.'/'.$srv.'/backup'."',NOW())");
			if ($db->sql_error()) {
				$log->error("Unable to insert repository in DB: ".$db->sql_error(),$srv);
				echo '[FAILED] SQL error';
			} else {
				echo "[OK]\n";
			}
		} else {
			echo "[SKIP] Repository exist in DB.\n";
		}


		echo"\n[FINISH] Server '$srv' Succesfuly added\n";

	}

}

