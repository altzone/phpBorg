#!/usr/bin/php -q
<?php
/**
 * phpBorg - point d'entree en ligne de commande
 */

use phpBorg\Core;
use phpBorg\Db;
use phpBorg\LogWriter;
use phpBorg\Config;
use phpBorg\Report;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$base = __DIR__;

require $base . '/lib/Config.php';
require $base . '/lib/Core.php';
require $base . '/lib/Db.php';
require $base . '/lib/Logger.php';
require $base . '/lib/Mailer.php';
require $base . '/lib/Report.php';

Config::load();

// PHP tourne en UTC par defaut alors que MySQL et le systeme sont en heure
// locale : sans cela, les horodatages du journal et des rapports decalent
// de plusieurs heures par rapport a report.start.
$tz = Config::get('general', 'timezone', '');
if ($tz === '' && is_readable('/etc/timezone')) $tz = trim(file_get_contents('/etc/timezone'));
if ($tz === '') $tz = 'UTC';
if (!@date_default_timezone_set($tz)) date_default_timezone_set('UTC');

$run    = new Core();
$db     = new Db();
$log    = new LogWriter();
$report = new Report($db, $log);

/**
 * Pose un verrou exclusif pour empecher deux executions simultanees.
 * Le handle est volontairement conserve dans une globale : sa fermeture
 * libererait le verrou.
 *
 * @param string $name
 * @param logWriter $log
 * @return void
 */
function phpborg_lock($name, $log) {
    global $phpborg_lock_handle;
    $dir = is_dir('/run/lock') ? '/run/lock' : sys_get_temp_dir();
    $file = $dir . '/phpborg-' . $name . '.lock';

    $phpborg_lock_handle = @fopen($file, 'c');
    if ($phpborg_lock_handle === false) {
        $log->warning("Impossible d'ouvrir le verrou $file, execution sans verrou");
        return;
    }
    if (!flock($phpborg_lock_handle, LOCK_EX | LOCK_NB)) {
        $msg = "Une execution '$name' est deja en cours ($file), abandon.";
        $log->warning($msg);
        fwrite(STDERR, $msg . "\n");
        exit(0);
    }
    ftruncate($phpborg_lock_handle, 0);
    fwrite($phpborg_lock_handle, (string)getmypid());
    fflush($phpborg_lock_handle);
}

/**
 * Installe un gestionnaire de signaux pour qu'une interruption (Ctrl+C, kill,
 * arret du service) laisse une trace en base et declenche une alerte, au lieu
 * de disparaitre silencieusement.
 *
 * @param Report $report
 * @param int $reportId
 * @param logWriter $log
 * @param string $kind 'full' ou 'backup'
 * @return void
 */
function phpborg_trap_signals($report, $reportId, $log, $kind) {
    if (!function_exists('pcntl_async_signals')) {
        $log->warning("Extension pcntl absente : une interruption ne sera pas signalee");
        return;
    }
    pcntl_async_signals(true);

    $handler = function ($signo) use ($report, $reportId, $log, $kind) {
        $names  = array(SIGINT => 'SIGINT (Ctrl+C)', SIGTERM => 'SIGTERM', SIGHUP => 'SIGHUP');
        $signal = isset($names[$signo]) ? $names[$signo] : ('signal ' . $signo);

        $log->error("Run '$kind' interrompu par $signal", 'CORE');

        $report->sendInterrupted($reportId, $signal, $kind);
        exit(130);
    };

    pcntl_signal(SIGINT,  $handler);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGHUP,  $handler);
}

/**
 * Affiche l'aide
 * @param string $bin
 * @return void
 */
function phpborg_usage($bin) {
    echo <<<TXT
Usage: $bin <commande> [arguments]

  full                        Sauvegarde tous les serveurs actifs, puis envoie le rapport
  backup <serveur> [mysql]    Sauvegarde un serveur, puis envoie le rapport
  check                       Controle de sante : alerte si aucune sauvegarde recente
  report [id]                 Renvoie le rapport du run 'full' indique (dernier par defaut)
  testmail                    Envoie un mail de test pour valider la configuration SMTP
  prune <serveur|all> [mysql] Applique la retention et met a jour les statistiques
  sync <serveur|all> [mysql]  Resynchronise les archives borg avec la base
  info <serveur> [mysql]      Affiche les informations du repository
  list <serveur> [mysql]      Liste les archives du repository
  mount [serveur] [mysql]     Monte une archive de facon interactive
  add                         Ajoute un serveur
  dbadd                       Ajoute une configuration base de donnees a un serveur

TXT;
}

$bin = $argv[0];

if (empty($argv[1])) {
    phpborg_usage($bin);
    exit(1);
}

$param = $argv[1];
$log->info("Starting phpBorg ($param)");

/* ---------------------------------------------------------------- info --- */
if ($param == "info") {
    if (empty($argv[2])) {
        echo "Usage: $bin info <serveur> [mysql]\n";
        exit(1);
    }
    $srv  = $argv[2];
    $type = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";
    // borg attend le chemin du repository, pas le nom du serveur
    $arg  = $run->params->borg_backup_path . "/" . $srv . "/" . $type;
    $run->borgExec('info', $arg, $srv, $type, $db, $log);
}

/* ---------------------------------------------------------------- list --- */
elseif ($param == "list") {
    if (empty($argv[2])) {
        echo "Usage: $bin list <serveur> [mysql]\n";
        exit(1);
    }
    $srv  = $argv[2];
    $type = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";
    $arg  = $run->params->borg_backup_path . "/" . $srv . "/" . $type;
    $run->borgExec('list', $arg, $srv, $type, $db, $log);
}

/* --------------------------------------------------------------- mount --- */
elseif ($param == "mount") {
    $srv  = !empty($argv[2]) ? $argv[2] : $run->selectServer($db);
    $type = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";

    do {
        $srv = $run->mountMenu($srv, $type, $db, $log);
    } while ($srv !== false);
}

/* ----------------------------------------------------------------- add --- */
elseif ($param == "add") {
    $run->addSrv($db, $log);
}

elseif ($param == "dbadd") {
    $run->addDb($db, $log);
}

/* -------------------------------------------------------------- backup --- */
elseif ($param == "backup") {
    if (empty($argv[2])) {
        echo "Usage: $bin backup <serveur> [mysql]\n";
        exit(1);
    }
    $srv  = $argv[2];
    $type = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";

    phpborg_lock('backup-' . $srv . '-' . $type, $log);

    $serverId = $run->getIdSrv($srv, $db);
    if (empty($serverId)) {
        $msg = "Serveur '$srv' inconnu ou inactif.";
        $log->error($msg, $srv);
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }

    $start    = microtime(true);
    $reportId = $run->startReport($db, $serverId, $type);
    phpborg_trap_signals($report, $reportId, $log, 'backup');
    $result   = $run->backup($srv, $log, $db, $reportId, $type);
    $duration = microtime(true) - $start;

    $report->sendSingle($reportId, $srv, $type, $duration);

    exit(!empty($result->error) ? 1 : 0);
}

/* ---------------------------------------------------------------- sync --- */
elseif ($param == "sync") {
    if (empty($argv[2])) {
        echo "Usage: $bin sync <serveur|all> [mysql]\n";
        echo "Resynchronise les archives borg avec la base MySQL\n";
        exit(1);
    }
    $srv = $argv[2];

    if ($srv == "all") {
        $total = 0;
        foreach ($run->getSrv($db) as $s) {
            $total += $run->syncArchives($s['name'], $s['type'], $db, $log);
        }
        echo "Total synchronise : $total archives\n";
    } else {
        $type   = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";
        $synced = $run->syncArchives($srv, $type, $db, $log);
        echo "Synchronise : $synced archives pour $srv ($type)\n";
    }
}

/* --------------------------------------------------------------- prune --- */
elseif ($param == "prune") {
    if (empty($argv[2])) {
        echo "Usage: $bin prune <serveur|all> [mysql]\n";
        exit(1);
    }
    $target = $argv[2];

    /**
     * Applique la retention a un couple serveur/type
     * @param Core $run
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return bool
     */
    $pruneOne = function ($run, $srv, $type, $db, $log) {
        if (!$run->backupParams($srv, $type, $db, $log)) {
            $log->error("Configuration du repository introuvable, prune ignore", $srv);
            return false;
        }
        $run->pruneArchive($run->repoParams->retention, $srv, $type, $db, $log);
        $run->updateRepo((object)array(
            'host'    => $srv,
            'repo'    => $run->repoParams->repo_path,
            'repo_id' => $run->repoParams->repo_id,
        ), $db, $log);
        return true;
    };

    if ($target == "all") {
        phpborg_lock('prune', $log);
        $log->info("Starting prune for ALL servers...");
        foreach ($run->getSrv($db) as $s) {
            $pruneOne($run, $s['name'], $s['type'], $db, $log);
        }
    } else {
        $type = (isset($argv[3]) && $argv[3] == "mysql") ? "mysql" : "backup";
        phpborg_lock('prune-' . $target . '-' . $type, $log);
        $pruneOne($run, $target, $type, $db, $log);
    }
}

/* ---------------------------------------------------------------- full --- */
elseif ($param == "full") {
    phpborg_lock('full', $log);

    $startAll = microtime(true);
    $reportId = $run->startReport($db, "0", 'full');
    phpborg_trap_signals($report, $reportId, $log, 'full');

    $osize = $csize = $dsize = $nfiles = $nbarchive = 0;
    $logs  = '';
    $errors = 0;

    foreach ($run->getSrv($db) as $srv) {
        $db->query("UPDATE IGNORE report set `curpos` = ? WHERE id = ?", $srv['name'], (int)$reportId);

        $full = $run->backup(
            $srv['name'], $log, $db,
            $run->startReport($db, $srv['id'], $srv['type']),
            $srv['type']
        );

        // backup() renvoie toujours un objet, mais on reste defensif
        if (is_object($full)) {
            $osize     += (float)(isset($full->osize)     ? $full->osize     : 0);
            $csize     += (float)(isset($full->csize)     ? $full->csize     : 0);
            $dsize     += (float)(isset($full->dsize)     ? $full->dsize     : 0);
            $nfiles    += (float)(isset($full->nfiles)    ? $full->nfiles    : 0);
            $nbarchive += (int)  (isset($full->nbarchive) ? $full->nbarchive : 0);
            $logs      .=        (isset($full->log)       ? $full->log       : '');
            if (!empty($full->error)) $errors++;
        } else {
            $errors++;
            $logs .= $srv['name'] . " => retour inattendu de backup()\n";
        }

        $dur = round(microtime(true) - $startAll);
        $db->query(
            "UPDATE IGNORE report SET `osize` = ?, `csize` = ?, `dsize` = ?, `dur` = ?,
                    `nb_archive` = ?, `nfiles` = ?, `error` = ?
             WHERE id = ?",
            (int)$osize, (int)$csize, (int)$dsize, (int)$dur,
            (int)$nbarchive, (int)$nfiles, (int)$errors, (int)$reportId
        );
    }

    $duration = microtime(true) - $startAll;
    $db->query(
        "UPDATE IGNORE report SET `end` = NOW(), `log` = ?, `curpos` = NULL WHERE id = ?",
        $logs, (int)$reportId
    );

    $log->info("Full backup termine en " . Report::duration($duration)
             . " : $errors erreur(s) sur $nbarchive archive(s)");

    $report->sendFull($reportId, $duration);

    exit($errors > 0 ? 1 : 0);
}

/* --------------------------------------------------------------- check --- */
elseif ($param == "check") {
    $healthy = $report->sendCheck();
    if ($healthy) {
        echo "OK : les sauvegardes tournent, aucun serveur en retard.\n";
        exit(0);
    }
    echo "ALERTE : voir le rapport envoye par mail et /var/log/phpborg.log\n";
    exit(1);
}

/* -------------------------------------------------------------- report --- */
elseif ($param == "report") {
    if (!empty($argv[2])) {
        $reportId = (int)$argv[2];
    } else {
        $last = $db->query("SELECT id FROM report WHERE type = 'full' ORDER BY id DESC LIMIT 1")->fetchArray();
        if (empty($last['id'])) {
            echo "Aucun run 'full' en base.\n";
            exit(1);
        }
        $reportId = (int)$last['id'];
    }
    echo "Renvoi du rapport du run full #$reportId\n";
    exit($report->sendFull($reportId, 0) ? 0 : 1);
}

/* ------------------------------------------------------------ testmail --- */
elseif ($param == "testmail") {
    $mailer = new phpBorg\Mailer($log);
    $to     = !empty($argv[2]) ? $argv[2] : null;
    $dest   = $to !== null ? $to : Config::get('smtp', 'to');

    echo "Envoi d'un mail de test a $dest via "
       . Config::get('smtp', 'host') . ':' . Config::get('smtp', 'port')
       . ' (' . Config::get('smtp', 'secure') . ")...\n";

    $html = '<h2>phpBorg - test de configuration</h2>'
          . '<p>Si vous lisez ce message, les rapports de sauvegarde peuvent partir.</p>'
          . '<p>Machine : <code>' . htmlspecialchars(gethostname(), ENT_QUOTES, 'UTF-8') . '</code><br>'
          . 'Date : ' . date('d/m/Y H:i:s') . '</p>';

    if ($mailer->send(trim(Config::get('alert', 'subject_prefix', '[phpBorg]')) . ' Test de configuration',
                      $html, '', $to)) {
        echo "[OK] Mail envoye.\n";
        exit(0);
    }
    echo "[ECHEC] " . $mailer->error . "\n";
    foreach ($mailer->trace() as $line) echo "  $line\n";
    exit(1);
}

else {
    echo "Commande inconnue : $param\n\n";
    phpborg_usage($bin);
    exit(1);
}
