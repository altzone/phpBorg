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
use phpBorg\Status;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$base = __DIR__;

require $base . '/lib/Config.php';
require $base . '/lib/Cleanup.php';
require $base . '/lib/Core.php';
require $base . '/lib/Db.php';
require $base . '/lib/Logger.php';
require $base . '/lib/Mailer.php';
require $base . '/lib/Report.php';
require $base . '/lib/Status.php';

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
// Adresses du serveur de sauvegarde : la base prime sur les valeurs du code
$run->loadSettings($db);
$log    = new LogWriter();
$report = new Report($db, $log);
$status = new Status($db, $log);

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
        // Le descripteur du verrou est herite par les sous-processus (ssh,
        // borg...). Si le run meurt en laissant un descendant derriere lui, le
        // verrou reste tenu alors que plus rien ne le justifie, et le run
        // suivant serait bloque indefiniment. On le detecte en relisant le pid
        // inscrit au depart : s'il n'existe plus, le verrou est perime.
        $ancien = (int) trim((string) @file_get_contents($file));
        $vivant = ($ancien > 0 && function_exists('posix_kill'))
                ? @posix_kill($ancien, 0) : ($ancien > 0);

        if ($vivant) {
            $msg = "Une execution '$name' est deja en cours (pid $ancien), abandon.";
            $log->warning($msg);
            fwrite(STDERR, $msg . "\n");
            exit(0);
        }

        $log->warning("Verrou perime sur $file (processus $ancien disparu), reprise");
        fclose($phpborg_lock_handle);
        // Remplacer l'inode : les descendants qui tiennent encore l'ancien
        // descripteur ne genent plus le nouveau fichier.
        @unlink($file);
        $phpborg_lock_handle = @fopen($file, 'c');
        if ($phpborg_lock_handle === false
            || !flock($phpborg_lock_handle, LOCK_EX | LOCK_NB)) {
            $msg = "Une execution '$name' est deja en cours ($file), abandon.";
            $log->warning($msg);
            fwrite(STDERR, $msg . "\n");
            exit(0);
        }
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
function phpborg_trap_signals($report, $reportId, $log, $kind, $noMail = false) {
    if (!function_exists('pcntl_async_signals')) {
        $log->warning("Extension pcntl absente : une interruption ne sera pas signalee");
        return;
    }
    pcntl_async_signals(true);

    $handler = function ($signo) use ($report, $reportId, $log, $kind, $noMail) {
        global $phpborg_enfants;

        $names  = array(SIGINT => 'SIGINT (Ctrl+C)', SIGTERM => 'SIGTERM', SIGHUP => 'SIGHUP');
        $signal = isset($names[$signo]) ? $names[$signo] : ('signal ' . $signo);

        $log->error("Run '$kind' interrompu par $signal", 'CORE');

        // Terminer les sauvegardes lancees par ce run : sans cela elles
        // continueraient en orphelines, hors de tout suivi.
        if (!empty($phpborg_enfants)) {
            $groupes = array();
            foreach ($phpborg_enfants as $w) {
                if (!is_resource($w['proc'])) continue;
                $st = @proc_get_status($w['proc']);
                if (!$st || !$st['running']) continue;
                $log->warning("Arret de la sauvegarde en cours : " . $w['tache']['name']
                            . " (" . $w['tache']['type'] . ")");
                if (!empty($w['pid'])) {
                    phpborg_kill_tree($w['pid'], SIGTERM);
                    $groupes[] = $w['pid'];
                }
                @proc_terminate($w['proc'], SIGTERM);
            }
            // Laisser aux processus le temps de se fermer proprement
            sleep(5);
            foreach ($groupes as $pid) phpborg_kill_tree($pid, SIGKILL);
            foreach ($phpborg_enfants as $w) {
                if (is_resource($w['proc'])) @proc_terminate($w['proc'], SIGKILL);
            }
        }

        // Un worker lance par le full ne doit pas alerter de son cote :
        // le run complet emet une seule alerte pour l'ensemble.
        if ($noMail) {
            $report->markInterrupted($reportId, $signal);
        } else {
            $report->sendInterrupted($reportId, $signal, $kind);
        }
        exit(130);
    };

    pcntl_signal(SIGINT,  $handler);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGHUP,  $handler);
}

/**
 * Termine un processus et toute sa descendance.
 *
 * Un worker de sauvegarde lance ssh, et localement borg prune / borg info.
 * Tuer le seul worker laisserait ces descendants tourner, reparentes a init,
 * en gardant ouvert le descripteur du verrou du run.
 *
 * @param int $pid
 * @param int $signal
 * @return void
 */
function phpborg_kill_tree($pid, $signal) {
    if ($pid <= 1) return;

    $enfants = array();
    $out = @shell_exec('pgrep -P ' . (int)$pid . ' 2>/dev/null');
    if (is_string($out)) {
        foreach (preg_split('/\s+/', trim($out)) as $c) {
            if ($c !== '' && ctype_digit($c)) $enfants[] = (int)$c;
        }
    }
    // Les descendants d'abord : le pere ne peut plus en creer de nouveaux
    foreach ($enfants as $c) phpborg_kill_tree($c, $signal);

    if (function_exists('posix_kill')) @posix_kill($pid, $signal);
}

/**
 * Affiche l'aide
 * @param string $bin
 * @return void
 */
/**
 * Detail du nettoyage d'une machine, pour la sortie terminal.
 *
 * @param array $r resultat de Cleanup::executer()
 * @param phpBorg\Cleanup $clean
 * @return string
 */
function phpborg_cleanup_detail($r, $clean) {
    $o = "\n== " . $r['srv'] . " ==\n";
    if (!empty($r['injoignable'])) {
        return $o . "  INJOIGNABLE : " . $r['erreur'] . "\n";
    }

    $cfg = trim((string)$r['config']);
    $o .= "  Configuration : " . ($cfg === '' ? "(aucune, opt-in non fait)" : $cfg) . "\n";
    if (!empty($r['actives'])) $o .= "  Cles actives  : " . implode(', ', $r['actives']) . "\n";
    $o .= "\n";

    $o .= sprintf("  %-2s %-19s %12s %12s  %s\n", '', 'cle', 'estime', 'apres', 'etat');
    $o .= "  " . str_repeat('-', 62) . "\n";

    foreach ($r['cles'] as $cle => $c) {
        $actif = in_array($cle, $r['actives'], true) ? '*' : ' ';
        if (!empty($c['skip'])) {
            $o .= sprintf("  %s%s %-19s %12s %12s  %s\n", $actif,
                          phpBorg\Cleanup::niveau($cle), $cle, '-', '-', $c['skip']);
            continue;
        }
        $etat = '';
        if ($c['rc'] !== null) $etat = ($c['rc'] === 0) ? 'ok' : 'code ' . $c['rc'];
        $o .= sprintf("  %s%s %-19s %12s %12s  %s\n", $actif,
                      phpBorg\Cleanup::niveau($cle), $cle,
                      phpBorg\Cleanup::octets($c['avant']),
                      $c['apres'] === null ? '-' : phpBorg\Cleanup::octets($c['apres']),
                      $etat);
        foreach ($c['msg'] as $m) $o .= "       | " . $m . "\n";
    }

    $o .= "  " . str_repeat('-', 62) . "\n";
    if (!empty($r['simulation'])) {
        $o .= "  Estimation cumulee : " . phpBorg\Cleanup::octets($r['total']) . "\n";
        $o .= "  (les postes Docker se recouvrent partiellement : c'est un ordre de grandeur)\n";
    } else {
        $o .= sprintf("  Espace libre : %s -> %s   gain reel %s\n",
                      phpBorg\Cleanup::octets($r['df_avant']),
                      phpBorg\Cleanup::octets($r['df_apres']),
                      phpBorg\Cleanup::octets($r['total']));
    }
    $o .= "\n  * = cle activee en base    A = sans risque    B = cout sans perte\n";
    return $o;
}

/**
 * Synthese parc, triee par gain decroissant.
 *
 * @param array $res
 * @param phpBorg\Cleanup $clean
 * @param bool $dryRun
 * @return string
 */
function phpborg_cleanup_synthese($res, $clean, $dryRun) {
    $cat    = phpBorg\Cleanup::catalogue();
    $cles   = array_keys($cat);
    $totaux = array_fill_keys($cles, 0);
    $grand  = 0;
    $ko     = array();

    // Largeur des colonnes : abreviation de chaque cle
    $abbr = array();
    foreach ($cles as $k) {
        $p = explode('-', $k);
        $abbr[$k] = strtoupper(count($p) > 1 ? substr($p[0],0,2) . substr($p[1],0,2) : substr($k,0,4));
    }

    $o  = "\n";
    $o .= ($dryRun ? "SIMULATION" : "EXECUTION") . " du nettoyage - " . count($res) . " machines\n";
    $o .= str_repeat('=', 118) . "\n";
    $o .= sprintf("%-18s", 'machine');
    foreach ($cles as $k) $o .= sprintf("%9s", $abbr[$k]);
    $o .= sprintf("%11s  %s\n", 'TOTAL', 'config');
    $o .= str_repeat('-', 118) . "\n";

    foreach ($res as $r) {
        if (!empty($r['injoignable'])) { $ko[] = $r['srv'] . ' (' . $r['erreur'] . ')'; continue; }
        if ($r['total'] <= 0 && empty($r['config'])) {
            // machine sans rien a nettoyer : on la compte mais sans ligne
            $grand += 0;
        }
        $o .= sprintf("%-18s", substr($r['srv'], 0, 18));
        foreach ($cles as $k) {
            $v = isset($r['cles'][$k]['avant']) ? $r['cles'][$k]['avant'] : 0;
            if (isset($r['cles'][$k]['skip']) && $r['cles'][$k]['skip'] !== '') $txt = '.';
            elseif ($v <= 0) $txt = '-';
            else $txt = phpborg_cleanup_court($v);
            $totaux[$k] += $v;
            $o .= sprintf("%9s", $txt);
        }
        $grand += $r['total'];
        $o .= sprintf("%11s  %s\n", phpborg_cleanup_court($r['total']),
                      trim((string)$r['config']) === '' ? '' : $r['config']);
    }

    $o .= str_repeat('-', 118) . "\n";
    $o .= sprintf("%-18s", 'TOTAL PARC');
    foreach ($cles as $k) $o .= sprintf("%9s", phpborg_cleanup_court($totaux[$k]));
    $o .= sprintf("%11s\n", phpborg_cleanup_court($grand));
    $o .= str_repeat('=', 118) . "\n\n";

    $o .= "Legende des colonnes :\n";
    foreach ($cles as $k) {
        $o .= sprintf("  %-6s [%s] %-18s %s\n", $abbr[$k], $cat[$k]['niveau'], $k, $clean->descr($k));
    }
    $o .= "\n  '.' = docker absent    '-' = rien a nettoyer\n";

    if (!empty($ko)) {
        $o .= "\nMachines injoignables (" . count($ko) . ") :\n";
        foreach ($ko as $m) $o .= "  - $m\n";
    }

    if ($dryRun) {
        $o .= "\nRien n'a ete modifie. Les postes Docker se recouvrent partiellement :\n";
        $o .= "le gain reel se mesure au df avant/apres lors de la premiere execution.\n";
    }
    return $o;
}

/** Format compact pour les tableaux (colonnes etroites). */
function phpborg_cleanup_court($n) {
    $n = (float)$n;
    if ($n <= 0)          return '-';
    if ($n >= 1073741824) return number_format($n / 1073741824, 1, ',', '') . 'G';
    if ($n >= 1048576)    return number_format($n / 1048576, 0, ',', '') . 'M';
    if ($n >= 1024)       return number_format($n / 1024, 0, ',', '') . 'k';
    return ((int)$n) . 'o';
}

function phpborg_usage($bin) {
    echo <<<TXT
Usage: $bin <commande> [arguments]

  full                        Sauvegarde tous les serveurs actifs, puis envoie le rapport
  backup <serveur> [mysql]    Sauvegarde un serveur, puis envoie le rapport
                              (--no-mail pour ne pas envoyer de rapport)
  check                       Controle de sante : alerte si aucune sauvegarde recente
  report [id]                 Renvoie le rapport du run 'full' indique (dernier par defaut)
  json [serveur]              Publie l'etat des sauvegardes au format JSON
  testmail                    Envoie un mail de test pour valider la configuration SMTP
  prune <serveur|all> [mysql] Applique la retention et met a jour les statistiques
  sync <serveur|all> [mysql]  Resynchronise les archives borg avec la base
  info <serveur> [mysql]      Affiche les informations du repository
  list <serveur> [mysql]      Liste les archives du repository
  mount [serveur] [mysql]     Monte une archive de facon interactive
  cleanup <serveur|all>       Nettoie les fichiers regenerables des machines
                              --dry-run   simule et chiffre, ne touche a rien
                              --keys=<..> force des cles (defaut : config du serveur)
                              -pN         N machines en parallele pour 'all'
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

// --no-mail : utilise par le "full" pour ses sous-processus, qui ne doivent
// pas envoyer un rapport chacun ; seul le run complet en emet un.
$noMail = in_array('--no-mail', $argv, true);

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
    phpborg_trap_signals($report, $reportId, $log, 'backup', $noMail);

    $status->publish($srv);                      // backup_in_progress = true
    $result   = $run->backup($srv, $log, $db, $reportId, $type);
    $duration = microtime(true) - $start;
    $status->publish($srv);                      // etat final

    if (!$noMail) $report->sendSingle($reportId, $srv, $type, $duration);

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

    $parallel = max(1, (int)Config::get('backup', 'parallel', 1));

    // Les taches les plus longues d'abord : lancer cezame-fle (pres de 2 h) en
    // dernier repousserait la fin du run d'autant. La duree vient de
    // l'historique ; une tache inconnue passe en tete par prudence.
    $taches = $run->getSrv($db);
    $durees = array();
    foreach ($db->query(
        "SELECT r.server_id, r.type, AVG(r.dur) AS moy
         FROM report r
         WHERE r.type <> 'full' AND r.dur IS NOT NULL AND r.dur > 0
           AND r.start > DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY r.server_id, r.type")->fetchAll() as $d) {
        $durees[$d['server_id'] . '/' . $d['type']] = (float)$d['moy'];
    }
    usort($taches, function ($a, $b) use ($durees) {
        $da = isset($durees[$a['id'] . '/' . $a['type']]) ? $durees[$a['id'] . '/' . $a['type']] : PHP_INT_MAX;
        $dbb = isset($durees[$b['id'] . '/' . $b['type']]) ? $durees[$b['id'] . '/' . $b['type']] : PHP_INT_MAX;
        if ($da === $dbb) return 0;
        return ($da < $dbb) ? 1 : -1;
    });

    $total = count($taches);
    $log->info("Full : $total taches, $parallel en parallele");

    /**
     * Lance une sauvegarde dans un processus separe.
     * On reutilise la commande "backup", deja eprouvee et protegee par son
     * propre verrou, plutot que de rendre Core::backup() concurrent.
     * @param array $t
     * @return array|null
     */
    $lancer = function ($t) use ($base, $log) {
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg($base . '/phpborg.php')
             . ' backup ' . escapeshellarg($t['name'])
             . ($t['type'] === 'mysql' ? ' mysql' : '')
             . ' --no-mail';
        $desc = array(1 => array('file', '/dev/null', 'a'), 2 => array('file', '/dev/null', 'a'));
        $proc = @proc_open($cmd, $desc, $pipes, $base);
        if (!is_resource($proc)) {
            $log->error("Impossible de lancer la sauvegarde de " . $t['name'] . " (" . $t['type'] . ")");
            return null;
        }
        $st = proc_get_status($proc);
        $log->info("Demarrage " . $t['name'] . " (" . $t['type'] . ")");
        return array('proc' => $proc, 'tache' => $t, 'debut' => microtime(true),
                     'pid' => ($st && !empty($st['pid'])) ? (int)$st['pid'] : 0);
    };

    // Globale : le gestionnaire de signaux doit pouvoir les terminer
    global $phpborg_enfants;
    $phpborg_enfants = array();
    $encours = &$phpborg_enfants;
    $faits   = 0;

    while (!empty($taches) || !empty($encours)) {
        // Remplir les emplacements libres
        while (count($encours) < $parallel && !empty($taches)) {
            $t = array_shift($taches);
            $w = $lancer($t);
            if ($w === null) { $faits++; continue; }
            $encours[] = $w;
        }

        if (empty($encours)) break;

        // curpos : ce qui tourne reellement, visible depuis viewstatus
        $noms = array();
        foreach ($encours as $w) $noms[] = $w['tache']['name'];
        $db->query("UPDATE IGNORE report set `curpos` = ? WHERE id = ?",
                   substr(implode(', ', $noms), 0, 50), (int)$reportId);

        // Attendre qu'au moins un processus se termine
        $fini = false;
        while (!$fini) {
            foreach ($encours as $k => $w) {
                $st = proc_get_status($w['proc']);
                if ($st === false || !$st['running']) {
                    proc_close($w['proc']);
                    $faits++;
                    $log->info(sprintf("Termine %s (%s) en %s [%d/%d]",
                        $w['tache']['name'], $w['tache']['type'],
                        Report::duration(microtime(true) - $w['debut']), $faits, $total));
                    unset($encours[$k]);
                    $encours = array_values($encours);
                    $fini = true;
                    break;
                }
            }
            if (!$fini) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                usleep(500000);
            }
        }
    }

    // Totaux recalcules depuis les sous-rapports : source unique de verite,
    // qu'ils aient ete produits en serie ou en parallele.
    $duration = microtime(true) - $startAll;
    $agg = $db->query(
        "SELECT COUNT(*) AS nb,
                SUM(COALESCE(nb_archive,0)) AS archives,
                SUM(COALESCE(osize,0))  AS osize,
                SUM(COALESCE(csize,0))  AS csize,
                SUM(COALESCE(dsize,0))  AS dsize,
                SUM(COALESCE(nfiles,0)) AS nfiles,
                SUM(CASE WHEN COALESCE(error,0) <> 0 OR COALESCE(nb_archive,0) < 1
                         THEN 1 ELSE 0 END) AS erreurs
         FROM report WHERE id > ? AND type <> 'full'", (int)$reportId
    )->fetchArray();

    $errors = (int)(isset($agg['erreurs']) ? $agg['erreurs'] : 0);

    $db->query(
        "UPDATE IGNORE report SET `osize` = ?, `csize` = ?, `dsize` = ?, `dur` = ?,
                `nb_archive` = ?, `nfiles` = ?, `error` = ?, `end` = NOW(), `curpos` = NULL
         WHERE id = ?",
        (int)$agg['osize'], (int)$agg['csize'], (int)$agg['dsize'], (int)round($duration),
        (int)$agg['archives'], (int)$agg['nfiles'], $errors, (int)$reportId
    );

    $log->info("Full backup termine en " . Report::duration($duration)
             . " : $errors erreur(s) sur " . (int)$agg['archives'] . " archive(s)"
             . " ($parallel en parallele)");

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

/* ------------------------------------------------------------- cleanup --- */
elseif ($param == "cleanup") {
    if (empty($argv[2])) {
        echo "Usage: $bin cleanup <serveur|all> [--dry-run] [--keys=<cles>] [-pN]\n\n";
        echo "Cles disponibles :\n";
        foreach (phpBorg\Cleanup::catalogue() as $k => $c) {
            printf("  [%s] %-18s %s\n", $c['niveau'], $k, $c['descr']);
        }
        echo "\nNiveaux : A = aucun risque, B = aucune perte de donnee mais un cout\n";
        echo "Raccourcis : 'A', 'B', ou '@profil' defini dans la table settings.\n";
        exit(1);
    }

    $cible    = $argv[2];
    $dryRun   = in_array('--dry-run', $argv, true);
    $jsonOut  = in_array('--json', $argv, true);
    $forceCles = null;
    $parallel  = 6;
    foreach ($argv as $a) {
        if (strpos($a, '--keys=') === 0) $forceCles = substr($a, 7);
        elseif (preg_match('/^-p(\d+)$/', $a, $m)) $parallel = max(1, (int)$m[1]);
    }

    $clean = new phpBorg\Cleanup($log, $db);

    /**
     * Nettoie une machine et retourne le resultat structure.
     * En simulation on evalue tout le catalogue, pour pouvoir decider ;
     * en execution reelle on n'execute que ce qui est configure.
     */
    $faireUn = function ($row) use ($clean, $log, $dryRun, $forceCles) {
        $spec = $forceCles !== null ? $forceCles : $row['cleanup'];

        if ($dryRun && $forceCles === null) {
            $cles = array_keys(phpBorg\Cleanup::catalogue());   // tout evaluer
        } else {
            $cles = $clean->resoudre($spec);
        }

        $r = $clean->executer($row['name'],
                              !empty($row['ssh_host']) ? $row['ssh_host'] : $row['host'],
                              (int)$row['port'], $cles, $dryRun);
        $r['config']  = trim((string)$row['cleanup']);
        $r['actives'] = $clean->resoudre($row['cleanup']);
        return $r;
    };

    /* ----- une seule machine ----- */
    if ($cible !== 'all') {
        $row = $db->query("SELECT name, host, ssh_host, port, cleanup FROM servers
                           WHERE name = ? AND active = 1", $cible)->fetchArray();
        if (empty($row)) {
            echo "Serveur '$cible' inconnu ou inactif.\n";
            exit(1);
        }
        if (!$dryRun) phpborg_lock('cleanup-' . $cible, $log);

        $r = $faireUn($row);
        if ($jsonOut) { echo json_encode($r) . "\n"; exit(0); }
        echo phpborg_cleanup_detail($r, $clean);
        exit($r['injoignable'] ? 1 : 0);
    }

    /* ----- tout le parc, en parallele ----- */
    if (!$dryRun) phpborg_lock('cleanup-all', $log);

    $rows = $db->query("SELECT name, host, ssh_host, port, cleanup FROM servers
                        WHERE active = 1 ORDER BY name")->fetchAll();
    $file = $rows;
    $encours = array();
    $res     = array();
    $tmp     = sys_get_temp_dir();

    $log->info("Nettoyage " . ($dryRun ? "(simulation)" : "") . " : " . count($rows) . " machines, $parallel en parallele");
    fwrite(STDERR, "Sondage de " . count($rows) . " machines ($parallel en parallele)...\n");

    while (!empty($file) || !empty($encours)) {
        while (count($encours) < $parallel && !empty($file)) {
            $row = array_shift($file);
            $out = tempnam($tmp, 'phpborg-clean-');
            $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' '
                 . escapeshellarg($base . '/phpborg.php')
                 . ' cleanup ' . escapeshellarg($row['name']) . ' --json'
                 . ($dryRun ? ' --dry-run' : '')
                 . ($forceCles !== null ? ' --keys=' . escapeshellarg($forceCles) : '');
            $desc = array(1 => array('file', $out, 'w'), 2 => array('file', '/dev/null', 'a'));
            $proc = @proc_open($cmd, $desc, $pipes, $base);
            if (!is_resource($proc)) { @unlink($out); continue; }
            $encours[] = array('proc' => $proc, 'out' => $out, 'name' => $row['name']);
        }
        if (empty($encours)) break;

        $fini = false;
        while (!$fini) {
            foreach ($encours as $k => $w) {
                $st = proc_get_status($w['proc']);
                if ($st === false || !$st['running']) {
                    proc_close($w['proc']);
                    $j = @json_decode(@file_get_contents($w['out']), true);
                    @unlink($w['out']);
                    if (is_array($j)) $res[] = $j;
                    else $res[] = array('srv' => $w['name'], 'injoignable' => true,
                                        'erreur' => 'pas de reponse', 'cles' => array(),
                                        'total' => 0, 'config' => '', 'actives' => array(),
                                        'df_avant' => 0, 'df_apres' => 0, 'simulation' => $dryRun);
                    fwrite(STDERR, '.');
                    unset($encours[$k]);
                    $fini = true;
                }
            }
            if (!$fini) usleep(300000);
        }
        $encours = array_values($encours);
    }
    fwrite(STDERR, "\n\n");

    usort($res, function ($a, $b) { return ($b['total'] == $a['total']) ? 0 : (($b['total'] < $a['total']) ? -1 : 1); });
    echo phpborg_cleanup_synthese($res, $clean, $dryRun);
    exit(0);
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

/* ---------------------------------------------------------------- json --- */
elseif ($param == "json") {
    if (!empty($argv[2])) {
        if ($status->publish($argv[2])) {
            echo "Etat publie pour " . $argv[2] . "\n";
            exit(0);
        }
        echo "Publication impossible pour " . $argv[2] . "\n";
        exit(1);
    }
    $n = $status->publishAll();
    echo "$n fichier(s) d'etat publie(s)\n";
    exit($n > 0 ? 0 : 1);
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
