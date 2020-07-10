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
	if (empty($argv[2])) {
		echo "Usage: $argv[0] info repository (ex $argv[0] info sbc-orange1\n";
		exit(1);
	}
        $srv = $argv[2];
        if (@$argv[3] == "mysql") $type="mysql";
        else $type='backup';

	$run->borgExec('info',$srv,$type,$db,$log);
}

if ($param == "list") {
        if (empty($argv[2])) {
                echo "Usage: $argv[0] list repository (ex $argv[0] list sbc-orange1\n";
                exit(1);
        }
        $srv = $argv[2];
        if (@$argv[3] == "mysql") $type="mysql";
        else $type='backup';
	$arg=$run->params->borg_backup_path . "/" . $srv . "/".$type;
        $run->borgExec('list',$arg,$srv,$type,$db,$log);
}

if ($param == "mount") {
	if (empty($argv[2])) {
		echo "Usage: $argv[0] mount repository (ex $argv[0] mount sbc-orange1\n";
		exit(1);
	}
	$srv = $argv[2];
	if (@$argv[3] == "mysql") $type="mysql";
	else $type="backup";
	$run->mountMenu($srv,$type,$db,$log);
}



if ($param == "add") {
	$run->addSrv($db,$log);
}


if ($param == "dbadd") {
	        $run->addDb($db,$log);
}



if ($param == "backup") {
    if (!empty($argv[2])) {
	    $srv = $argv[2];
	    if (@$argv[3] == "mysql") $type="mysql";
	    else $type='backup';
    } else {
        echo "Specify the server to backup, example:\n$argv[0] $argv[1] sbc-orange1\n";
        exit(1);
    }

    $run->backup($srv,$log,$db,$run->startReport($db,$run->getIdSrv($srv,$db),$type),$type);

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
        foreach ($run->getSrv($db) as $srv) {
		if ($config = $run->repoConfig($srv,$db, $log)) {
			$run->pruneArchive($config->retention,$srv,$db,$log);
			$run->updateRepo($config,$db,$log);
		}

	}
    } else {
                if ($config = $run->repoConfig($srv,$db, $log)) {
                        $run->pruneArchive($config->retention,$srv,$db,$log);
                        $run->updateRepo($config,$db,$log);
                }
	}




}


if ($param == "full") {
	$reportId=$run->startReport($db,"0",'full');
	$dur=$osize=$csize=$dsize=$nfiles=$nbarchive=$logs=NULL;

	foreach ($run->getSrv($db) as $srv) {
		$full  	    =  NULL;
		$start_time = microtime(true);
		$db->query("UPDATE IGNORE report  set `curpos`='$srv[name]' WHERE id=$reportId");
		$full       =  $run->backup($srv['name'],$log,$db,$run->startReport($db,$srv['id'],$srv['type']),$srv['type']);
		$osize      += @$full->osize;
		$csize      += @$full->csize;
		$dsize      += @$full->dsize;
		$nfiles     += @$full->nfiles;
		$nbarchive  += @$full->nbarchive;
		$logs       .= @$full->log;
		$end_time    = microtime(true);
		$dur	     = round($end_time - $start_time);
		$db->query("UPDATE IGNORE report  set `osize`='$osize', `csize`='$csize', `dsize`='$dsize', `dur`='$dur',`end`='NOW()', `nb_archive` = '$nbarchive', `nfiles`='$nfiles', `error`= '$full->error' WHERE id=$reportId");
	}
	$db->query("UPDATE IGNORE report  set `end`=NOW(), `log` = ? , `curpos` = NULL WHERE id=$reportId",$logs);

}
