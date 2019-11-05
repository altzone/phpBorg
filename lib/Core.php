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
     * @return array
     */
    public function getSrv() {
                $srv=[];
                foreach (new DirectoryIterator($this->params->borg_backup_path) as $fileInfo) {
                        if($fileInfo->isDot()) continue;
                        if ($fileInfo->isDir())  $srv[]=$fileInfo->getFilename();
                }
		//return $srv;
		return ['sql-buzz','ns-cache2'];
        }


    /**
     * parseConfig Method (Parse config file from repository)
     * @param string $srv
     * @param logWriter $log
     * @return array|bool|int
     */
    private function repoConfig($srv, $log)
    {
        if (file_exists($this->params->borg_backup_path . "/$srv/" . $this->params->borg_config_path)) {
            $this->configBackup = new \stdClass;
            $exclude = $backup = "";

            $this->configBackup = (object) parse_ini_file($this->params->borg_backup_path . "/$srv/" . $this->params->borg_config_path);
            $ex = explode(',', $this->configBackup->exclude);
            foreach ($ex as $zz) {
                $exclude .= "--exclude " . trim($zz) . " ";
            }
            $this->configBackup->exclude = $exclude;
            $back = explode(',', $this->configBackup->backup);
            foreach ($back as $yy) {
                $backup .= trim($yy) . " ";
            }
            $this->configBackup->backup = $backup;

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
            return 1;
        } else {
            $log->error("Error, server '$srv' does not exist or config file is incorect");
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
                $deleted = NULL;
                $keepday = $keepday-1;
		$e       = $this->myExec($this->params->borg_binary_path." prune --save-space --force --list --keep-daily $keepday ".$this->params->borg_backup_path."/".$srv."/".$this->params->borg_archive_dir);
		if ($e['return'] == 0 ) {
			$separator = "\r\n";
			$line = strtok($e['stderr'], $separator);
			while ($line !== false) {
				if (preg_match('/Pruning archive/',$line)) {
					$id=substr(stristr($line,'['),1,strpos($line,']')-strlen($line));
					$log->info("suppresion de $id");
					$db->query("DELETE from archives WHERE archive_id='$id'");
					if ($db->sql_error()) $log->error("Unable to delete archive in DB: ".$db->sql_error());
				}
				$line = strtok( $separator );
			}
			return;
		} else {
			$log->error("PRUNE ERROR=>\nSTDOUT:\n$e[stdout]\n\nSTDERR:\n$e[stderr]");
			return 1;
		}
        }

	/**
	 * parseLog Method (read output of Borg to get info)
     * @param $file
     * @return array
     */
        public function parseLog($file) {
		$e=$this->myExec($this->params->borg_binary_path." info $file --json");
                $json=$e['stdout'];
                if ($e['return'] == 0) {
                        $var                    = json_decode($json,true);
                        $cmdline                = "";
                        $commandline            = $var['archives'][0]['command_line'];
                        $duration               = $var['archives'][0]['duration'];
                        $start                  = $var['archives'][0]['start'];
                        $end                    = $var['archives'][0]['end'];
                        $id                     = $var['archives'][0]['id'];
                        $csize                  = $var['archives'][0]['stats']['compressed_size'];
                        $dsize                  = $var['archives'][0]['stats']['deduplicated_size'];
                        $nbfile                 = $var['archives'][0]['stats']['nfiles'];
                        $osize                  = $var['archives'][0]['stats']['original_size'];
                        $total_chunks           = $var['cache']['stats']['total_chunks'];
                        $total_csize            = $var['cache']['stats']['total_csize'];
                        $total_size             = $var['cache']['stats']['total_size'];
                        $total_unique_chunks    = $var['cache']['stats']['total_unique_chunks'];
                        $unique_csize           = $var['cache']['stats']['unique_csize'];
                        $unique_size            = $var['cache']['stats']['unique_size'];
                        $encryption             = $var['encryption']['mode'];
                        $location               = $var['repository']['location'];
                        $last_modified          = $var['repository']['last_modified'];

                        foreach( $commandline as $value ) {
                                $cmdline.="$value ";
                        }
                        return array(   'archive_id' => $id,
                                        'dur'        => $duration,
                                        'start'      => $start,
                                        'end'        => $end,
                                        'csize'      => $csize,
                                        'dsize'      => $dsize,
                                        'nfiles'     => $nbfile,
                                        'osize'      => $osize,
                                        'ttchunks'   => $total_chunks,
                                        'ttcsize'    => $total_csize,
                                        'ttsize'     => $total_size,
                                        'ttuchunks'  => $total_unique_chunks,
                                        'ucsize'     => $unique_csize,
                                        'usize'      => $unique_size,
                                        'location'   => $location,
                                        'modified'   => $last_modified,
                                        'encryption' => $encryption,
                                        'error'      => NULL);
                }
                else return array('error' => 1, 'stderr' => $e['stderr'], 'stdout'=> $e['stdout']);
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
      * backup Method (run backup for specified server)
      * @param $srv
      * @param object $log
      * @param object $db
      * @param $reportId
      * @return array|object
     */

	public function backup($srv,$log,$db,$reportId) {
	$log->info("Starting backup:  $srv");
	if (!$this->repoConfig($srv,$log)) {
                $log->error("PARSECONFIG => Error, config file for server: '$srv' does not exist");
                $db->query("UPDATE IGNORE report  set `error`='1' WHERE id=$reportId");
        } else {
                $db->query("UPDATE IGNORE report  set `curpos`= '".$this->configBackup->host."' WHERE id=".$reportId);
		$log->info("Checking retention rules");
                $this->pruneArchive($this->configBackup->retention,$srv,$db,$log);
		$archivename="backup_".date("Y-m-d_H:i:s");
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->configBackup->repo));

		foreach($iterator as $item) {
			chmod($item, 0700);
			chgrp($item, $this->configBackup->host);
			chown($item, $this->configBackup->host);
		}
		$log->info("Running Backup ...");
		$tmplog = $backuperror= '';
                $e    = $this->myExec("ssh -p " . $this->configBackup->port . " -tt " . $this->configBackup->host . " \"/usr/bin/borg create  --compression " . $this->configBackup->compression . " " . $this->configBackup->exclude . " ssh://" . $this->configBackup->host . "@" . $this->configBackup->backuptype.$this->configBackup->repo . "::$archivename " . $this->configBackup->backup ."\"");
		if ($e['return'] == '0') {
			$log->info("Parsing log to extract info");
                        $info = $this->parseLog($this->configBackup->repo . "::$archivename");
                        if (!$info['error']) {
                                    $durx=$this->secondsToTime($info['dur']);
				    $log->info("Backup completed in $durx");
                                    $db->query("INSERT IGNORE INTO archives (`id`, `repo`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`) VALUE (NULL,'" . $this->configBackup->repo . "','$archivename','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')");
				    if ($db->sql_error()) {
					    $err=$config['host']."=>\nPARSELOG ERROR=>SQL:\n".$db->sql_error();
					    $tmplog.=$err;
					    $log->error($err);
				    }
                                    $db->query("UPDATE IGNORE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]', modified=NOW() where nom = '".$this->configBackup->repo ."'");
				    if ($db->sql_error()) {
					    $err=$config['host']."=>\nPARSELOG ERROR=>SQL:\n".$db->sql_error();
				 	    $tmplog.=$err;
					    $log->error($err);
				    }

                                    $db->query("UPDATE IGNORE report  set `osize`='".$info['osize']."', `csize`='".$info['csize']."', `dsize`='".$info['dsize']."', `dur`='".$info['dur']."', `nb_archive` = 1, `nfiles`='".$info['nfiles']."' WHERE id=$reportId");
				    if ($db->sql_error()) {
					    $err=$config['host']."=>\nSQL UPDATE ERROR:\n".$db->sql_error();
					    $tmplog.=$err;
					    $log->error($err);
				    }
                        } else {
				$log->error("\nPARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]");
				$backuperror=1;
                                    $tmplog = "$config[host] =>\nPARSELOG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
                                    $db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog" , "$reportId");

                        }
                } else {
			$log->error("BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]");
			$backuperror=1;
                $tmplog = "$srv =>\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'];
                $db->query("UPDATE IGNORE report  set `error`='1', `log` = ? WHERE id= ?", "$tmplog" , "$reportId");

                }
	}
	return (object) ['error' => $backuperror, 'log' => $tmplog, 'osize'=> $info['osize'], 'csize' => $info['csize'], 'dsize'=> $info['dsize'], 'dur'=> $info['dur'], 'nbarchive' => 1, 'nfiles' => $info['nfiles']];
}



}

