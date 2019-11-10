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

if ($param == "info") {
    makeStat($path, $run, $db); //@TODO undefined
}

if ($param == "add") {
	$run->addSrv();
}



if ($param == "backup") {
    if (!empty($argv[2])) {
        $srv = $argv[2];
    } else {
        echo "Specify the server to backup, example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }
    $run->backup($srv,$log,$db,$run->startReport($db));

}

if ($param == "prune") {
    if (!empty($argv[2])) {
        $srv = $argv[2];
    } else {
        echo 'Specify server name to prune  or "all" to prune all server,  example:'."\n$argv[0] $argv[1] srvname\n";
        exit(1);
    }
    if ($srv == "all") {
	    $log->info("Starting prune for ALL servers...");
        foreach ($run->getSrv() as $srv) {
		if ($config = $run->repoConfig($srv,$log)) {
			$run->pruneArchive($config->retention,$srv,$db,$log);
			$run->updateRepo($config,$db,$log);
		}

	}
    } else {
                if ($config = $run->repoConfig($srv,$log)) {
                        $run->pruneArchive($config->retention,$srv,$db,$log);
                        $run->updateRepo($config,$db,$log);
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

