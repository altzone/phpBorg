#!/usr/bin/php -q
<?php

use phpBorg\Cli;
use phpBorg\Db;

$flog = fopen('/var/log/backup.log', 'a+');
declare(ticks=1);
pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");


require 'lib/Cli.php';
$cli = new Cli();

require 'lib/Db.php';
$db = new Db();

/**
 * @param string $path
 * @param Cli $cli
 * @param Db $db
 */
function makeStat($path, $cli, $db)
{
    $dbConnection = $db->db_connect('extranet');
    $tmp = NULL;
    foreach (glob($path, GLOB_ONLYDIR) as $dir) {
        $json = shell_exec("/usr/bin/borg list $dir/backup --json");
        $var = json_decode($json, true);
        $backupfile = $var['archives'];
        foreach ($backupfile as $value) {
            $file = $dir . "/backup::" . $value['archive'];
            $repo = $dir . "/backup";
            $info = $cli->parselog("$file");
            $insert = "INSERT IGNORE INTO archives VALUE (NULL,'$repo','$value[archive]','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')";
            $update = "UPDATE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]' where nom = '$repo'";
            mysqli_query($dbConnection, $insert) or $tmp .= mysqli_error($dbConnection);
            mysqli_query($dbConnection, $update) or $tmp .= mysqli_error($dbConnection);
            echo $tmp;
        }
    }
}

if (!empty($argv[1])) {
    $param = $argv[1];
} else {
    echo "Usage: $argv[0] repoinfo / checkconf\n";
    exit(1);
}

if ($param == "prune") {
    $result = $cli->prune(3, "yodaa");
    echo "RETURN = $result[return]\n";
    if ($result['return'] == 0) {
        $separator = "\r\n";
        $line = strtok($result['stderr'], $separator);
        while ($line !== false) {
            if (preg_match('/Would prune/', $line)) {
                $id = substr(stristr($line, '['), 1, -1);
                $sql = "DELETE from archives WHERE archive_id='$id'";
                echo "DELEDETED\n";
            }
            $line = strtok($separator);
        }
    } else {
        echo "ERREUR!!\n";
    }

}


if ($param == "archiveinfo") {
    $path = "/data0/backup/*";
    if ($argv[2]) $path = "/data0/backup/$argv[2]";
    makeStat($path, $cli, $db);
}


if ($param == "backup") {
    if (!empty($argv[2])) {
        $srv = $argv[2];
    } else {
        echo "Specify the server a backup example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }

    $dbConnection = $db->db_connect('extranet');
    $config = $cli->parse("/data0/backup/$srv");

    if (!$config) die("Error, the server does not exist or the conf is not good\n");
    $db->fsql("INSERT INTO `report` (`start`) VALUES (Now())", $db, "0");
    $nb_archive = 1;
    $idlog = mysqli_insert_id($dbConnection);
    $error = 0;
    $date = date("Y-m-d_H:i:s");
    //$e=my_exec("ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"");
    echo "ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"";
    die;
}

if ($param == "prune") {
    $dbConnection = $db->db_connect('extranet');
    $error = $nb_archive = $tmplog = $dur = $osize = $csize = $dsize = $nfiles = NULL;

    foreach (glob('/data0/backup/*', GLOB_ONLYDIR) as $dir) {
        $config = $cli->parse($dir);
        echo "Serveur $config[host]\n";

        if (!$config) {
            fwrite($flog, "PARSECONFIG => Error, the directory $dir does not exist\n");
        } else {
            fwrite($flog, "Verification of the rules of conservation\n");
            $result = $cli->prune($config['retention'], $config['host']);
            if ($result['return'] == 0) {
                fwrite($flog, "$result[stderr]\n");
                $separator = "\r\n";
                $line = strtok($result['stderr'], $separator);
                while ($line !== false) {
                    if (preg_match('/Pruning archive/', $line)) {
                        $id = substr(stristr($line, '['), 1, strpos($line, ']') - strlen($line));
                        fwrite($flog, "suppressing the $id\n");
                        mysqli_query($dbConnection, "DELETE from archives WHERE archive_id='$id'") or $log = $config['host'] . "=>\nPRUNE ERROR=>SQL:\n." . mysqli_error($dbConnection);
                        fwrite($flog, "$log");
                    }
                    $line = strtok($separator);
                }
            } else {
                fwrite($flog, "=>\nPRUNE ERROR=>\nSTDOUT:\n$result[stdout]\n\nSTDERR:\n$result[stderr]\n");
            }
        }
    }
}


if ($param == "full") {
    fwrite($flog, "Starting backups\n");
    $dbConnection = $db->db_connect('extranet');
    $db->fsql("INSERT INTO `report` (`start`,`status`) VALUES (Now(),'1')", $db, "0");
    $idlog = mysqli_insert_id($dbConnection);
    $error = $nb_archive = $tmplog = $dur = $osize = $csize = $dsize = $nfiles = NULL;
    foreach (glob('/data0/backup/*', GLOB_ONLYDIR) as $dir) {
        $config = $cli->parse($dir);
        $nb_archive++;
        fwrite($flog, "Server $config[host]\n");
        if (!$config) {
            fwrite($flog, "PARSECONFIG => Error, the file $dir does not exist\n");
            $tmplog .= "PARSECONFIG => Error, the file $dir does not exist\n";
            $_tmplog = mysqli_real_escape_string($dbConnection, $tmplog);
            mysqli_query($dbConnection, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");
        } else {
            mysqli_query($dbConnection, "UPDATE IGNORE report  set `curpos`= '$config[host]' WHERE id=$idlog");
            fwrite($flog, "Verification of the rules of conservation\n");
            $result = $cli->prune($config['retention'], $config['host']);
            if ($result['return'] == 0) {
                $separator = "\r\n";
                $line = strtok($result['stderr'], $separator);
                while ($line !== false) {
                    if (preg_match('/Pruning archive/', $line)) {
                        $id = substr(stristr($line, '['), 1, strpos($line, ']') - strlen($line));
                        fwrite($flog, "suppression of $id\n");
                        mysqli_query($dbConnection, "DELETE from archives WHERE archive_id='$id'") or $tmplog .= $config['host'] . "=>\nPRUNE ERROR=>SQL:\n" . mysqli_error($dbConnection);
                    }
                    $line = strtok($separator);
                }
            } else {
                fwrite($flog, "=>\nPRUNE ERROR=>\nSTDOUT:\n$result[stdout]\n\nSTDERR:\n$result[stderr]\n");
                $tmplog .= $config['host'] . "=>\nPRUNE ERROR=>\nSTDOUT:\n" . $result['stdout'] . "\n\nSTDERR:\n" . $result['stderr'] . "\n";
                $_tmplog = mysqli_real_escape_string($dbConnection, $tmplog);
                mysqli_query($dbConnection, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");
            }
            $date = date("Y-m-d_H:i:s");
            system("/bin/chown -R $config[host]:$config[host] $config[repo]");
            system("/bin/chmod -R 700 $config[repo]");
            fwrite($flog, "Execution of the backup\n");
            $e = $cli->my_exec("ssh -p $config[port] -tt $config[host] \"/usr/bin/borg create  --compression $config[compression] $config[exclude] ssh://$config[host]@10.10.69.15$config[repo]::backup_$date $config[backup]\"");
            if ($e['return'] == '0') {
                $info = $cli->parselog("$config[repo]::backup_$date");
                if (!$info['error']) {
                    $durx = $cli->secondsToTime($info['dur']);
                    fwrite($flog, "Backup terminé en $durx secondes\n");
                    $archname = "backup_$date";
                    $dur += $info['dur'];
                    $osize += $info['osize'];
                    $csize += $info['csize'];
                    $dsize += $info['dsize'];
                    $nfiles += $info['nfiles'];
                    $insert = "INSERT IGNORE INTO archives (`id`, `repo`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`) VALUE (NULL,'$config[repo]','$archname','$info[archive_id]','$info[dur]','$info[start]','$info[end]','$info[csize]','$info[dsize]','$info[osize]','$info[nfiles]')";
                    $update = "UPDATE IGNORE repository set `size` = '$info[ttsize]', `dsize` = '$info[ucsize]',`csize`= '$info[ttcsize]', `ttuchunks` = '$info[ttuchunks]', `ttchunks` = '$info[ttchunks]' where nom = '$config[repo]'";
                    mysqli_query($dbConnection, $insert) or $tmplog .= mysqli_error($dbConnection);
                    mysqli_query($dbConnection, $update) or $tmplog .= mysqli_error($dbConnection);
                    mysqli_query($dbConnection, "UPDATE IGNORE report  set `osize`='$osize', `csize`='$csize', `dsize`='$dsize', `dur`='$dur', `nb_archive` = $nb_archive, `nfiles`='$nfiles' WHERE id=$idlog") or $tmplog .= $config['host'] . "=> SQL UPDATE ERROR:\n" . mysqli_error($dbConnection);
                } else {
                    fwrite($flog, "\nPARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]\n");
                    $tmplog = "$config[host] =>";
                    $tmplog .= "\nPARSELOG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
                    $_tmplog = mysqli_real_escape_string($dbConnection, $tmplog);
                    mysqli_query($dbConnection, "UPDATE IGNORE report  set `error`='1', `log` = $_tmplog WHERE id=$idlog");

                }
            } else {
                fwrite($flog, "\n BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]\n\n");
                $tmplog = "$config[host] =>";
                $tmplog .= "\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'] . "\n\n";
                $_tmplog = mysqli_real_escape_string($dbConnection, $tmplog);
                mysqli_query($dbConnection, "UPDATE IGNORE report  set `error`='1', `log` = '$_tmplog' WHERE id=$idlog");

            }
        }
    }
    $_tmplog = mysqli_real_escape_string($dbConnection, $tmplog);
    if ($error) {
        $status = 2;
    } else {
        $status = 0;
    }
    mysqli_query($dbConnection, "UPDATE IGNORE report  set `status` = '$status',`end` = Now(), `log` = '$_tmplog', `nb_archive` = $nb_archive, `nfiles` = '$nfiles', `csize` = '$csize', `dsize` = '$dsize', `osize` = '$osize', `dur` = '$dur'   WHERE ID = '$idlog'");
}


if ($param == "info") {
    if (!empty($argv[2])) {
        $file = $argv[2];
    } else {
        echo "Specify the server a backup example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }

    $log = $cli->parselog($file);
    print_r($log);
}
