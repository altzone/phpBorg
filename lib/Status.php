<?php
/**
 * phpBorgStatus - Etat des sauvegardes d'un serveur, au format JSON
 *
 * Destine a une supervision exterieure : le fichier est ecrit sur disque et
 * servi tel quel par nginx. Aucun PHP ni acces base n'est sollicite a chaque
 * requete, et le superviseur peut interroger aussi souvent qu'il le souhaite.
 *
 * Le fichier est reecrit au debut ET a la fin de chaque sauvegarde : sans la
 * premiere ecriture, backup_in_progress resterait toujours a false.
 */

namespace phpBorg;

require_once __DIR__ . '/Config.php';

/**
 * Class Status
 * @package phpBorg
 */
class Status
{
    /** @var Db */
    private $db;

    /** @var logWriter */
    private $log;

    /**
     * Status constructor.
     * @param Db $db
     * @param logWriter $log
     */
    public function __construct($db, $log) {
        $this->db  = $db;
        $this->log = $log;
    }

    /**
     * Repertoire de publication des fichiers d'etat
     * @return string
     */
    private function dir() {
        return rtrim((string)Config::get('status', 'dir', '/var/www/phpborg-status'), '/');
    }

    /**
     * Serveurs pour lesquels un fichier est publie (liste, ou * pour tous)
     * @return array
     */
    private function serveursPublies() {
        $v = trim((string)Config::get('status', 'servers', ''));
        if ($v === '' ) return array();
        if ($v === '*') return array('*');
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }

    /**
     * Indique si un serveur doit etre publie
     * @param string $srv
     * @return bool
     */
    public function estPublie($srv) {
        $l = $this->serveursPublies();
        if (empty($l)) return false;
        return in_array('*', $l, true) || in_array($srv, $l, true);
    }

    /**
     * Construit l'etat d'un serveur
     * @param string $srv
     * @return array|null
     */
    public function build($srv) {
        $server = $this->db->query(
            "SELECT id, name, active FROM servers WHERE name = ?", $srv
        )->fetchArray();
        if (empty($server['id'])) return null;

        $seuil = (int)Config::get('alert', 'stale_days', 2);
        $depots = array();
        $globalOk = true;
        $alerte   = false;
        $enCours  = false;

        $rows = $this->db->query(
            "SELECT r.type, r.repo_id, r.retention,
                    (SELECT COUNT(*) FROM archives a WHERE a.repo_id = r.repo_id) AS nb,
                    (SELECT MAX(a.end) FROM archives a WHERE a.repo_id = r.repo_id) AS derniere
             FROM repository r WHERE r.server_id = ?", (int)$server['id']
        )->fetchAll();

        foreach ($rows as $r) {
            $type = $r['type'];

            // Sauvegarde base volontairement suspendue : ne pas la signaler en panne
            $off = $this->db->query(
                "SELECT COUNT(*) AS c FROM db_info
                 WHERE server_id = ? AND type = ? AND active = 0",
                (int)$server['id'], $type
            )->fetchArray();
            if (!empty($off['c'])) continue;

            // Dernier run connu pour ce couple serveur/type
            $run = $this->db->query(
                "SELECT id, start, end, dur, error, nb_archive, osize, csize, dsize, nfiles, log
                 FROM report WHERE server_id = ? AND type = ?
                 ORDER BY id DESC LIMIT 1", (int)$server['id'], $type
            )->fetchArray();

            $tourne = (!empty($run) && $run['end'] === null);
            if ($tourne) $enCours = true;

            $derniere = !empty($r['derniere']) ? $r['derniere'] : null;
            $ageH = $derniere !== null
                  ? (int)floor((time() - strtotime($derniere)) / 3600) : null;

            // Distinguer "le dernier run a echoue" de "il n'y a plus de
            // sauvegarde valide" : dans le premier cas les donnees restent
            // protegees par l'archive precedente, le superviseur ne doit pas
            // declencher la meme alerte que dans le second.
            $echecDernier = (!empty($run) && !empty($run['error']));
            if ($derniere === null)          $etat = 'never';   // jamais sauvegarde
            elseif ($ageH > $seuil * 24)     $etat = 'stale';   // plus d'archive recente
            elseif ($echecDernier)           $etat = 'warning'; // echec, mais archive recente
            else                             $etat = 'ok';

            // Seuls never et stale sont bloquants pour l'etat global
            if (!$tourne && in_array($etat, array('never', 'stale'), true)) {
                $globalOk = false;
            }
            if ($etat === 'warning') $alerte = true;

            $depots[$type] = array(
                'status'             => $etat,
                'in_progress'        => $tourne,
                'last_success'       => $derniere !== null ? date('c', strtotime($derniere)) : null,
                'age_hours'          => $ageH,
                'archives_count'     => (int)$r['nb'],
                'retention_days'     => (int)$r['retention'],
                'last_run_start'     => !empty($run['start']) ? date('c', strtotime($run['start'])) : null,
                'last_run_end'       => !empty($run['end'])   ? date('c', strtotime($run['end']))   : null,
                'duration_seconds'   => isset($run['dur'])    ? (int)$run['dur'] : null,
                'original_size'      => isset($run['osize'])  ? (int)$run['osize'] : null,
                'compressed_size'    => isset($run['csize'])  ? (int)$run['csize'] : null,
                'deduplicated_size'  => isset($run['dsize'])  ? (int)$run['dsize'] : null,
                'files'              => isset($run['nfiles']) ? (int)$run['nfiles'] : null,
                'error'              => !empty($run['error'])
                                      ? trim(str_replace('\\n', ' ', (string)$run['log'] ?? '')) ?: 'echec'
                                      : null,
            );
        }

        if (empty($depots)) return null;

        return array(
            'server'             => $server['name'],
            'generated_at'       => date('c'),
            'backup_in_progress' => $enCours,
            // ok | warning | error | running
            //   ok      : sauvegardes a jour
            //   warning : dernier run en echec, mais une archive recente existe
            //   error   : plus aucune archive recente (ou jamais sauvegarde)
            //   running : sauvegarde en cours
            'status'             => $enCours ? 'running'
                                  : (!$globalOk ? 'error' : ($alerte ? 'warning' : 'ok')),
            'stale_after_days'   => $seuil,
            'repositories'       => $depots,
        );
    }

    /**
     * Ecrit le fichier d'etat d'un serveur.
     * L'ecriture passe par un fichier temporaire puis un rename : le
     * superviseur ne peut jamais lire un JSON tronque.
     *
     * @param string $srv
     * @return bool
     */
    public function publish($srv) {
        if (!$this->estPublie($srv)) return true;

        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->log->error("Repertoire d'etat inaccessible : $dir", $srv);
            return false;
        }

        $data = $this->build($srv);
        if ($data === null) {
            $this->log->warning("Aucun depot a publier pour $srv", $srv);
            return false;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->log->error("Encodage JSON impossible pour $srv", $srv);
            return false;
        }

        $cible = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $srv) . '.json';
        $tmp   = $cible . '.tmp';

        if (@file_put_contents($tmp, $json . "\n") === false) {
            $this->log->error("Ecriture impossible : $tmp", $srv);
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $cible)) {
            @unlink($tmp);
            $this->log->error("Publication impossible : $cible", $srv);
            return false;
        }
        return true;
    }

    /**
     * Publie tous les serveurs concernes
     * @return int nombre de fichiers ecrits
     */
    public function publishAll() {
        $n = 0;
        $liste = $this->serveursPublies();
        if (empty($liste)) return 0;

        if (in_array('*', $liste, true)) {
            $liste = array();
            foreach ($this->db->query("SELECT name FROM servers WHERE active = 1")->fetchAll() as $s) {
                $liste[] = $s['name'];
            }
        }
        foreach ($liste as $srv) {
            if ($this->publish($srv)) $n++;
        }
        return $n;
    }
}
