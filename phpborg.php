#!/usr/bin/php -q
<?php
use phpBorg\Core;
use phpBorg\Db;
use phpBorg\LogWriter;


require 'lib/Core.php';
$run = new Core();

require 'lib/Db.php';
$db = new Db();

require 'lib/Logger.php';
$log = new LogWriter();

$log->info('Starting phpBorg');



if (!empty($argv[1])) {
    $param = $argv[1];
} else {
	echo "Usage: $argv[0] repoinfo / checkconf\n";
    exit(1);
}

if ($param == "prune") {
    $result = $run->prune(3, "yodaa");
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
    makeStat($path, $run, $db);
}


if ($param == "backup") {
    if (!empty($argv[2])) {
        $srv = $argv[2];
    } else {
        echo "Specify the server a backup example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }
    $run->backup($srv,$log,$db,$run->startReport($db));

}

if ($param == "prune") {
    $dbConnection = $db->db_connect('extranet');
    $error = $nb_archive = $tmplog = $dur = $osize = $csize = $dsize = $nfiles = NULL;

    foreach (glob('/data0/backup/*', GLOB_ONLYDIR) as $dir) {
        $config = $run->parse($dir);
        echo "Serveur $config[host]\n";

        if (!$config) {
            fwrite($flog, "PARSECONFIG => Error, the directory $dir does not exist\n");
        } else {
            fwrite($flog, "Verification of the rules of conservation\n");
            $result = $run->prune($config['retention'], $config['host']);
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
	$reportId=$run->startReport($db);
	$dur=$osize=$csize=$dsize=$nfiles=$nbarchive=$logs=NULL;

	foreach ($run->getSrv() as $srv) {
		$full  	    =  NULL;
		$db->query("UPDATE IGNORE report  set `curpos`='$srv' WHERE id=$reportId");
		$full       =  $run->backup($srv,$log,$db,$run->startReport($db));
                $dur        += $full->dur;
                $osize      += $full->osize;
                $csize      += $full->csize;
                $dsize      += $full->dsize;
		$nfiles     += $full->nfiles;
		$nbarchive  += $full->nbarchive;
		$logs       .= $full->log;
		$db->query("UPDATE IGNORE report  set `osize`='$osize', `csize`='$csize', `dsize`='$dsize', `dur`='$dur', `nb_archive` = '$nbarchive', `nfiles`='$nfiles', `error`= '$full->error' WHERE id=$reportId");
	}
	$db->query("UPDATE IGNORE report  set `end`=NOW(), `log` = ? , `curpos` = NULL WHERE id=$reportId",$logs);

}

if ($param == "info") {
    if (!empty($argv[2])) {
        $file = $argv[2];
    } else {
        echo "Specify the server you want to backup example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }

    $log = $run->parselog($file);
    print_r($log);
}
