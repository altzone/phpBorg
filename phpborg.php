#!/usr/bin/php -q
<?php
$flog = fopen('/var/log/backup.log','a+');
declare(ticks = 1);
pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");


function secondsToTime($inputSeconds) {
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

    foreach ($sections as $name => $value){
        if ($value > 0){
            $timeParts[] = $value. ''.$name.($value == 1 ? '' : '');
        }
    }

    return implode('', $timeParts);
}


function signal_handler($signal) {
    switch($signal) {
        case SIGTERM:
        case SIGINT:
                fwrite($flog,"\nControlC detecté => Script terminé\n");
            exit;
    }
}

function parse($repo) {
        if (file_exists("$repo/conf/borg.conf")) {

                $exclude=$backup="";
                $config=parse_ini_file("$repo/conf/borg.conf");
                $ex=explode(',',$config['exclude']);
                foreach ($ex as $zz) {
                        $exclude.= "--exclude " . trim($zz) . " ";
                }
                $config['exclude']=$exclude;
                $back=explode(',',$config['backup']);
                foreach ($back as $yy) {
                        $backup.=trim($yy)." ";
                }
                $config['backup']=$backup;
                return $config;
        } else {
                return 0;
        }
}

function my_exec($cmd, $input='')
         {$proc=proc_open($cmd, array(0=>array('pipe', 'r'), 1=>array('pipe', 'w'), 2=>array('pipe', 'w')), $pipes);
          fwrite($pipes[0], $input);fclose($pipes[0]);
          $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
          $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
          $rtn=proc_close($proc);
          return array('stdout'=>$stdout,
                       'stderr'=>$stderr,
                       'return'=>$rtn
                      );
         }

function prune($keepday, $srv) {
        $deleted=NULL;
        $keepday=$keepday-1;
        $e=my_exec("/usr/bin/borg prune --save-space --force --list --keep-daily $keepday /data0/backup/$srv/backup");
        return ['return'=>$e['return'],'stdout'=>$e['stdout'],'stderr'=>$e['stderr']];
}

function parselog($file) {
        $e=my_exec("/usr/bin/borg info $file --json");
        $json=$e['stdout'];
        if ($e['return'] == 0) {
        $var=json_decode($json,true);
        $cmdline="";
        $commandline=$var['archives'][0]['command_line'];
        $duration=$var['archives'][0]['duration'];
        $start=$var['archives'][0]['start'];
        $end=$var['archives'][0]['end'];
        $id=$var['archives'][0]['id'];
        $csize=$var['archives'][0]['stats']['compressed_size'];
        $dsize=$var['archives'][0]['stats']['deduplicated_size'];
        $nbfile=$var['archives'][0]['stats']['nfiles'];
        $osize=$var['archives'][0]['stats']['original_size'];
        $total_chunks=$var['cache']['stats']['total_chunks'];
        $total_csize=$var['cache']['stats']['total_csize'];
        $total_size=$var['cache']['stats']['total_size'];
        $total_unique_chunks=$var['cache']['stats']['total_unique_chunks'];
        $unique_csize=$var['cache']['stats']['unique_csize'];
        $unique_size=$var['cache']['stats']['unique_size'];
        $encryption=$var['encryption']['mode'];
        $location=$var['repository']['location'];
        $last_modified=$var['repository']['last_modified'];

        foreach( $commandline as $value ) {
                $cmdline.="$value ";
        }
        return array('archive_id' => $id,'dur' => $duration,'start' => $start,'end' => $end,'csize' => $csize,'dsize' => $dsize,'nfiles' => $nbfile,'osize' => $osize,'ttchunks' =>$total_chunks,'ttcsize' => $total_csize,'ttsize' => $total_size,'ttuchunks' => $total_unique_chunks,'ucsize' => $unique_csize,'usize' => $unique_size,'location' => $location,'modified' => $last_modified,'encryption' => $encryption,'error' => NULL);
        }
        else return array('error' => 1, 'stderr' => $e['stderr'], 'stdout'=> $e['stdout']);
}


function db_connect ($server)
{
        switch ($server) {
    case "extranet":
        $link = mysqli_connect('mysql_host', 'backup', 'mysql_password', 'backup');
        return $link;
        break;
        }

}

function fsql ($reqsql,$db,$cache=0)
{
                mysqli_ping ($db);
               $rez=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error($db));
        return $rez;
}

function fsql_object ($reqsql,$db,$cache=0)
{
                mysqli_ping ($db);
                $sql=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error($db));
                $result=mysqli_fetch_object($sql);

        return $result;
}

function fsql_row ($reqsql,$db,$cache=0)
{
                mysqli_ping ($db);
                $sql=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error($db));
                $result=mysqli_fetch_row($sql);

        return $result;
}

function makestat($path) {
        $db=db_connect('extranet');
        $tmp=NULL;
        foreach(glob($path, GLOB_ONLYDIR) as $dir) {
                $json=shell_exec("/usr/bin/borg list $dir/backup --json");
                $var=json_decode($json,true);
                $backupfile=$var['archives'];
                foreach($backupfile as $value) {
                        $file=$dir."/backup::".$value['archive'];
                        $repo=$dir."/backup";
                        $info=parselog("$file");
                        $insert="INSERT IGNORE INTO archives VALUE (NULL,'$repo','$value[archive]','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')";
                        $update="UPDATE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]' where nom = '$repo'";
                        mysqli_query($db,$insert) or $tmp.=mysqli_error($db);
                        mysqli_query($db,$update) or $tmp.=mysqli_error($db);
                        echo $tmp;
                }
        }
}




if (!empty($argv[1])) {
        $param=$argv[1];
} else {
        echo "Usage: $argv[0] repoinfo / checkconf\n";
        exit(1);
}

if ($param == "prune") {
        $result=prune(3,"yodaa");
        echo "RETURN = $result[return]\n";
        if ($result['return'] == 0 ) {
                $separator = "\r\n";
                $line = strtok($result['stderr'], $separator);
                while ($line !== false) {
                        if (preg_match('/Would prune/',$line)) {
                                $id=substr(stristr($line,'['),1,-1);
                                $sql="DELETE from archives WHERE archive_id='$id'";
                                echo "DELEDETED\n";
                        }
                        $line = strtok( $separator );
                }
        } else {
                echo "ERREUR!!\n";
        }

}


if ($param == "archiveinfo") {
        $path="/data0/backup/*";
        if ($argv[2]) $path="/data0/backup/$argv[2]";
        makestat($path);
}


if ($param == "backup") {
        if (!empty($argv[2])) {
                $srv=$argv[2];
        } else {
                echo "Specifier le serveur a backup exemple:\n$argv[0] $argv[1] sbc-orange1\n";
                exit(1);
        }
        $db=db_connect('extranet');
        $config=parse("/data0/backup/$srv");
        if (!$config) die("Erreur, le serveur n'existe pas ou la conf n'est pas bonne\n");
        fsql("INSERT INTO `report` (`start`) VALUES (Now())",$db,"0");
        $nb_archive=1;
        $idlog=mysqli_insert_id($db);
        $error=0;
        $date = date("Y-m-d_H:i:s");
        //$e=my_exec("ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"");
        echo "ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"";
        die;
        if ($e['return'] == '0') {
                $info=parselog("$config[repo]::backup_$date");
                        if (!$info['error']) {
                                $archname="backup_$date";
                                $insert="INSERT IGNORE INTO archives (`id`, `repo`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`) VALUE (NULL,'$config[repo]','$archname','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')";
                                $update="UPDATE IGNORE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]' where nom = '$config[repo]'";
                                mysqli_query($db,$insert) or $tmplog.=mysqli_error($db);
                                mysqli_query($db,$update) or $tmplog.=mysqli_error($db);
                        } else {
                                $tmplog="$config[host] =>";
                                $tmplog.="\nPARSELOG ERROR\n STDERR:".$info['stderr']."STDOUT:".$info['stdout']."\n";
                        }
        } else {
                        $tmplog="$config[host] =>";
                        $tmplog.="\nSTDOUT:".$e['stdout']."\nSTDERR:".$e['stderr']."\n\n";
                        $error=1;
        }
        $tmplog.="\n";
        $_tmplog=mysqli_real_escape_string($db,$tmplog);
        mysqli_query($db,"UPDATE IGNORE report  set `end` = Now(), `status` = '$error', `log` = '$_tmplog', `nb_archive` = $nb_archive, `nfiles` = '$info[nfiles]', `csize` = '$info[csize]', `dsize` = '$info[dsize]', `osize` = '$info[osize]', `dur` = '$info[dur]'   WHERE ID = '$idlog'");
}

if ($param == "prune") {
$db = db_connect('extranet');
$error = $nb_archive = $tmplog = $dur = $osize = $csize = $dsize = $nfiles = NULL;
foreach (glob('/data0/backup/*', GLOB_ONLYDIR) as $dir) {
    $config = parse($dir);
    echo "Serveur $config[host]\n";
    if (!$config) {
             fwrite($flog,"PARSECONFIG => Erreur, le fichier $dir n'existe pas\n");
    } else {
             fwrite($flog,"Verification des regles de conservation\n");
            $result=prune($config['retention'],$config['host']);
            if ($result['return'] == 0) {
                     fwrite($flog,"$result[stderr]\n");
                    $separator = "\r\n";
                    $line = strtok($result['stderr'], $separator);
                    while ($line !== false) {
                            if (preg_match('/Pruning archive/',$line)) {
                                    $id=substr(stristr($line,'['),1,strpos($line,']')-strlen($line));
                                     fwrite($flog,"suppresion de $id\n");
                                    mysqli_query($db,"DELETE from archives WHERE archive_id='$id'") or $log=$config['host']."=>\nPRUNE ERROR=>SQL:\n.".mysqli_error($db);
                                     fwrite($flog,"$log");
                            }
                            $line = strtok( $separator );
                    }
            } else {
                     fwrite($flog,"=>\nPRUNE ERROR=>\nSTDOUT:\n$result[stdout]\n\nSTDERR:\n$result[stderr]\n");
            }
        }
}
}


if ($param == "full") {
     fwrite($flog,"Demarrage des backups\n");
    $db = db_connect('extranet');
    fsql("INSERT INTO `report` (`start`,`status`) VALUES (Now(),'1')", $db, "0");
    $idlog = mysqli_insert_id($db);
    $error = $nb_archive = $tmplog = $dur = $osize = $csize = $dsize = $nfiles = NULL;
    foreach (glob('/data0/backup/*', GLOB_ONLYDIR) as $dir) {
        $config = parse($dir);
        $nb_archive++;
         fwrite($flog,"Serveur $config[host]\n");
        if (!$config) {
                 fwrite($flog,"PARSECONFIG => Erreur, le fichier $dir n'existe pas\n");
                $tmplog .= "PARSECONFIG => Erreur, le fichier $dir n'existe pas\n";
                $_tmplog = mysqli_real_escape_string($db, $tmplog);
                mysqli_query($db, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");
        } else {
                mysqli_query($db, "UPDATE IGNORE report  set `curpos`= '$config[host]' WHERE id=$idlog");
                 fwrite($flog,"Verification des regles de conservation\n");
                $result=prune($config['retention'],$config['host']);
                if ($result['return'] == 0) {
                        $separator = "\r\n";
                        $line = strtok($result['stderr'], $separator);
                        while ($line !== false) {
                                if (preg_match('/Pruning archive/',$line)) {
                                        $id=substr(stristr($line,'['),1,strpos($line,']')-strlen($line));
                                         fwrite($flog,"suppresion de $id\n");
                                        mysqli_query($db,"DELETE from archives WHERE archive_id='$id'") or $tmplog.=$config['host']."=>\nPRUNE ERROR=>SQL:\n".mysqli_error($db);
                                }
                                $line = strtok( $separator );
                        }
                } else {
                         fwrite($flog,"=>\nPRUNE ERROR=>\nSTDOUT:\n$result[stdout]\n\nSTDERR:\n$result[stderr]\n");
                        $tmplog.=$config['host']."=>\nPRUNE ERROR=>\nSTDOUT:\n".$result['stdout']."\n\nSTDERR:\n".$result['stderr']."\n";
                        $_tmplog = mysqli_real_escape_string($db, $tmplog);
                        mysqli_query($db, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");
                }
                $date = date("Y-m-d_H:i:s");
                system("/bin/chown -R $config[host]:$config[host] $config[repo]");
                system("/bin/chmod -R 700 $config[repo]");
                 fwrite($flog,"Execution de la sauvegarde\n");
                $e    = my_exec("ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"");
                if ($e['return'] == '0') {
                        $info = parselog("$config[repo]::backup_$date");
                        if (!$info['error']) {
                                    $durx=secondsToTime($info['dur']);
                                    fwrite($flog,"Backup terminé en $durx secondes\n");
                                    $archname = "backup_$date";
                                    $dur    += $info['dur'];
                                    $osize  += $info['osize'];
                                    $csize  += $info['csize'];
                                    $dsize  += $info['dsize'];
                                    $nfiles += $info['nfiles'];
                                    $insert="INSERT IGNORE INTO archives (`id`, `repo`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`) VALUE (NULL,'$config[repo]','$archname','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')";
                                    $update="UPDATE IGNORE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]' where nom = '$config[repo]'";
                                    mysqli_query($db,$insert) or $tmplog.=mysqli_error($db);
                                    mysqli_query($db,$update) or $tmplog.=mysqli_error($db);
                                    mysqli_query($db, "UPDATE IGNORE report  set `osize`='$osize', `csize`='$csize', `dsize`='$dsize', `dur`='$dur', `nb_archive` = $nb_archive, `nfiles`='$nfiles' WHERE id=$idlog") or $tmplog.=$config['host']."=> SQL UPDATE ERROR:\n".mysqli_error($db);
                        } else {
                                     fwrite($flog,"\nPARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]\n");
                                    $tmplog = "$config[host] =>";
                                    $tmplog .= "\nPARSELOG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
                                    $_tmplog = mysqli_real_escape_string($db, $tmplog);
                                     mysqli_query($db, "UPDATE IGNORE report  set `error`='1', `log` = $_tmplog WHERE id=$idlog");

                        }
                } else {
                 fwrite($flog,"\n BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]\n\n");
                $tmplog = "$config[host] =>";
                $tmplog .= "\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'] . "\n\n";
                $_tmplog = mysqli_real_escape_string($db, $tmplog);
                mysqli_query($db, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");

                }
        }
    }
    $_tmplog = mysqli_real_escape_string($db, $tmplog);
    if ($error) $status=2;
    else $status=0;
    mysqli_query($db, "UPDATE IGNORE report  set `status` = '$status',`end` = Now(), `log` = '$_tmplog', `nb_archive` = $nb_archive, `nfiles` = '$nfiles', `csize` = '$csize', `dsize` = '$dsize', `osize` = '$osize', `dur` = '$dur'   WHERE ID = '$idlog'");
}



if ($param == "info") {
        if (!empty($argv[2])) {
                $file=$argv[2];
        } else {
                echo "Specifier le serveur a backup exemple:\n$argv[0] $argv[1] sbc-orange1\n";
                exit(1);
        }

        $log=parselog($file);
        print_r($log);
}
