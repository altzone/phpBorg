<?php
/**
 * phpBorgReport - Construction et envoi des rapports de sauvegarde
 */

namespace phpBorg;

require_once __DIR__ . '/Config.php';

/**
 * Class Report
 * @package phpBorg
 */
class Report
{
    /** @var Db */
    private $db;

    /** @var logWriter */
    private $log;

    /** @var array Parametres [alert] */
    private $cfg;

    /**
     * Report constructor.
     * @param Db $db
     * @param logWriter $log
     */
    public function __construct($db, $log) {
        $this->db  = $db;
        $this->log = $log;
        $this->cfg = Config::get('alert');
    }

    /**
     * Determine si une ligne de rapport est un echec.
     * Un backup reussi renseigne toujours nb_archive = 1 : une ligne sans
     * erreur mais sans archive est un echec silencieux (cas snapMysql).
     * @param array $row
     * @return bool
     */
    public static function isFailure($row) {
        if (!empty($row['error'])) return true;
        return (int)(isset($row['nb_archive']) ? $row['nb_archive'] : 0) < 1;
    }

    /**
     * Message d'erreur affichable pour une ligne de rapport
     * @param array $row
     * @return string
     */
    public static function failureReason($row) {
        $log = isset($row['log']) ? (string)$row['log'] : '';
        if (trim($log) !== '') return self::cleanError($log);
        if (empty($row['error'])) {
            return "Aucune archive creee et aucune erreur remontee : la sauvegarde\n"
                 . "s'est interrompue sans rien signaler (voir /var/log/phpborg.log).";
        }
        return self::cleanError($log);
    }

    /**
     * Lit une cle de la section [alert]
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function cfg($key, $default = null) {
        return isset($this->cfg[$key]) ? $this->cfg[$key] : $default;
    }

    /* ------------------------------------------------------------------ */
    /* Collecte                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Recupere les sous-rapports d'un run "full"
     * Les lignes creees pendant le run ont un id superieur a celui du full.
     * @param int $fullReportId
     * @return array
     */
    public function fullRows($fullReportId) {
        return $this->db->query(
            "SELECT r.id, r.server_id, r.type, r.start, r.end, r.dur, r.nfiles,
                    r.osize, r.csize, r.dsize, r.nb_archive, r.error, r.log,
                    COALESCE(s.name, CONCAT('id:', r.server_id)) AS name
             FROM report r
             LEFT JOIN servers s ON s.id = r.server_id
             WHERE r.id > ? AND r.type <> 'full'
             ORDER BY r.id ASC",
            (int)$fullReportId
        )->fetchAll();
    }

    /**
     * Recupere une ligne de rapport unitaire
     * @param int $reportId
     * @return array
     */
    public function singleRow($reportId) {
        $rows = $this->db->query(
            "SELECT r.id, r.server_id, r.type, r.start, r.end, r.dur, r.nfiles,
                    r.osize, r.csize, r.dsize, r.nb_archive, r.error, r.log,
                    COALESCE(s.name, CONCAT('id:', r.server_id)) AS name
             FROM report r
             LEFT JOIN servers s ON s.id = r.server_id
             WHERE r.id = ?",
            (int)$reportId
        )->fetchAll();
        return $rows;
    }

    /**
     * Serveurs actifs dont la derniere archive depasse le seuil d'anciennete
     * (ou qui n'ont jamais eu d'archive)
     * @param int|null $days
     * @return array
     */
    public function staleServers($days = null) {
        if ($days === null) $days = (int)$this->cfg('stale_days', 2);
        return $this->db->query(
            "SELECT x.name, x.type, x.dernier, x.nb,
                    CASE WHEN x.dernier IS NULL THEN NULL
                         ELSE DATEDIFF(NOW(), x.dernier) END AS jours
             FROM (
                SELECT s.name AS name, r.type AS type,
                       MAX(a.end) AS dernier, COUNT(a.id) AS nb
                FROM servers s
                JOIN repository r ON r.server_id = s.id
                LEFT JOIN archives a ON a.repo_id = r.repo_id
                WHERE s.active = 1
                GROUP BY s.name, r.type
             ) x
             WHERE x.dernier IS NULL OR x.dernier < DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY x.dernier IS NULL DESC, x.dernier ASC",
            (int)$days
        )->fetchAll();
    }

    /**
     * Etat d'occupation du volume qui heberge les depots borg.
     * Un disque plein fait echouer toutes les sauvegardes en masse
     * (erreur borg "No space left on device"), il faut le voir venir.
     *
     * @param string|null $path
     * @return array|null
     */
    public function diskStatus($path = null) {
        if ($path === null) {
            $path = Config::get('backup', 'repo_path', '/data/backups');
        }
        if (!is_dir($path)) return null;

        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) return null;

        $used = $total - $free;
        return array(
            'path'         => $path,
            'total'        => $total,
            'free'         => $free,
            'used'         => $used,
            'used_percent' => round($used / $total * 100, 1),
            'free_percent' => round($free / $total * 100, 1),
        );
    }

    /**
     * Indique si le disque a franchi le seuil d'alerte
     * @param array|null $disk
     * @return bool
     */
    public function diskCritical($disk) {
        if ($disk === null) return false;
        $seuil = (float)$this->cfg('disk_warn_percent', 90);
        return $disk['used_percent'] >= $seuil;
    }

    /**
     * Date de demarrage du dernier run "full"
     * @return array|null
     */
    public function lastFull() {
        $rows = $this->db->query(
            "SELECT id, start, end, curpos,
                    TIMESTAMPDIFF(HOUR, start, NOW()) AS age_hours
             FROM report WHERE type = 'full' ORDER BY id DESC LIMIT 1"
        )->fetchAll();
        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * Dernier run "full" reellement termine
     * @return array|null
     */
    public function lastCompletedFull() {
        $rows = $this->db->query(
            "SELECT id, start, end, TIMESTAMPDIFF(HOUR, end, NOW()) AS age_hours
             FROM report WHERE type = 'full' AND end IS NOT NULL
             ORDER BY id DESC LIMIT 1"
        )->fetchAll();
        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * Marque un run comme interrompu et alerte immediatement.
     * Appele depuis le gestionnaire de signal de phpborg.php.
     * @param int $reportId
     * @param string $signal
     * @param string|null $curpos
     * @return void
     */
    public function sendInterrupted($reportId, $signal, $kind = 'full', $curpos = null) {
        $note = "Run interrompu par $signal"
              . ($curpos ? " pendant le traitement de $curpos" : '') . "\n";

        $this->db->query(
            "UPDATE IGNORE report SET `end` = NOW(), `error` = GREATEST(COALESCE(`error`,0),1),
                    `log` = CONCAT(COALESCE(`log`,''), ?) WHERE id = ?",
            $note, (int)$reportId
        );

        // Un full agrege ses sous-rapports ; un backup unitaire n'a que sa ligne
        $rows   = ($kind === 'full') ? $this->fullRows($reportId) : $this->singleRow($reportId);
        $failed = array();
        $ok     = array();
        foreach ($rows as $r) {
            if (self::isFailure($r)) $failed[] = $r;
            else $ok[] = $r;
        }

        $titre   = ($kind === 'full') ? 'Sauvegarde complete INTERROMPUE' : 'Sauvegarde INTERROMPUE';
        $subject = $this->subject('INTERROMPU - ' . $kind . ' arrete par ' . $signal);

        $extra = '<div style="padding:12px;background:#fff4e5;border-left:3px solid #d69200;'
               . 'border-radius:3px;margin-bottom:16px;font-size:14px;font-weight:700;color:#6b4700">'
               . htmlspecialchars(trim($note), ENT_QUOTES, 'UTF-8')
               . '<br>Les serveurs non encore traites n\'ont pas ete sauvegardes.</div>';

        $html = $this->renderHtml($titre, 'ECHEC', $failed, $ok, array(), 0);
        $html = str_replace('<div style="border:1px solid #ddd;border-top:none;'
                          . 'border-radius:0 0 6px 6px;padding:20px">',
                            '<div style="border:1px solid #ddd;border-top:none;'
                          . 'border-radius:0 0 6px 6px;padding:20px">' . $extra, $html);

        $text = trim($note) . "\nLes serveurs non encore traites n'ont pas ete sauvegardes.\n\n"
              . $this->renderText($titre, 'ECHEC', $failed, $ok, array(), 0);

        $this->deliver($subject, $html, $text);
    }

    /* ------------------------------------------------------------------ */
    /* Envoi                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Construit et envoie le rapport d'un run "full"
     * @param int $fullReportId
     * @param float $durationSeconds
     * @return bool
     */
    public function sendFull($fullReportId, $durationSeconds = 0) {
        $rows   = $this->fullRows($fullReportId);
        $stale  = $this->staleServers();
        $failed = array();
        $ok     = array();

        foreach ($rows as $r) {
            if (self::isFailure($r)) $failed[] = $r;
            else $ok[] = $r;
        }

        $nbFail = count($failed);
        $nbOk   = count($ok);

        if ($nbFail === 0 && !$this->cfg('send_on_success', 1) && empty($stale)) {
            $this->log->info("Rapport non envoye (tout est OK, send_on_success=0)", 'REPORT');
            return true;
        }

        $status  = $nbFail > 0 ? 'ECHEC' : 'OK';
        $subject = $this->subject(
            ($nbFail > 0 ? 'FULL - ' . $nbFail . ' ECHEC' . ($nbFail > 1 ? 'S' : '') . ' / ' . $nbOk . ' OK'
                         : 'FULL - ' . $nbOk . ' OK')
        );

        $html = $this->renderHtml('Sauvegarde complete', $status, $failed, $ok, $stale, $durationSeconds);
        $text = $this->renderText('Sauvegarde complete', $status, $failed, $ok, $stale, $durationSeconds);

        return $this->deliver($subject, $html, $text);
    }

    /**
     * Construit et envoie le rapport d'un backup unitaire
     * @param int $reportId
     * @param string $srv
     * @param string $type
     * @param float $durationSeconds
     * @return bool
     */
    public function sendSingle($reportId, $srv, $type, $durationSeconds = 0) {
        $rows   = $this->singleRow($reportId);
        $failed = array();
        $ok     = array();

        foreach ($rows as $r) {
            if (self::isFailure($r)) $failed[] = $r;
            else $ok[] = $r;
        }

        $nbFail = count($failed);

        if ($nbFail === 0 && !$this->cfg('send_on_success', 1)) {
            $this->log->info("Rapport non envoye (backup OK, send_on_success=0)", 'REPORT');
            return true;
        }

        $status  = $nbFail > 0 ? 'ECHEC' : 'OK';
        $subject = $this->subject(
            ($nbFail > 0 ? 'ECHEC - ' : 'OK - ') . $srv . ' (' . $type . ')'
        );

        $html = $this->renderHtml("Sauvegarde $srv ($type)", $status, $failed, $ok, array(), $durationSeconds);
        $text = $this->renderText("Sauvegarde $srv ($type)", $status, $failed, $ok, array(), $durationSeconds);

        return $this->deliver($subject, $html, $text);
    }

    /**
     * Watchdog : alerte si aucun full recent, ou si des serveurs decrochent.
     * N'envoie un mail que s'il y a un probleme.
     * @return bool true si aucun probleme, false sinon
     */
    public function sendCheck() {
        $maxAge   = (int)$this->cfg('max_full_age_hours', 26);
        $last     = $this->lastFull();
        $stale    = $this->staleServers();
        $problems = array();
        // Libelles courts, pour que le sujet du mail nomme le vrai probleme
        $labels   = array();

        if ($last === null) {
            $problems[] = "Aucun run 'full' n'a jamais ete enregistre en base.";
            $labels[]   = 'aucune sauvegarde enregistree';
        } else {
            $age = $last['age_hours'] === null ? null : (int)$last['age_hours'];

            if ($age === null || $age > $maxAge) {
                $affiche = $age === null ? '?' : $age;
                $problems[] = "Le dernier 'full' remonte a {$affiche} h (seuil : {$maxAge} h) - "
                            . "demarre le " . $last['start'] . ". LES SAUVEGARDES NE TOURNENT PLUS.";
                $labels[]   = 'aucune sauvegarde depuis ' . $affiche . 'h';
            } elseif ($last['end'] === null && $age > 0 && !$this->isRunning()) {
                // Demarre mais jamais termine et plus aucun processus : run interrompu
                $problems[] = "Le run 'full' du " . $last['start'] . " n'a jamais ete termine"
                            . ($last['curpos'] ? " (arrete sur " . $last['curpos'] . ")" : '')
                            . " et aucun processus phpborg ne tourne : RUN INTERROMPU.";
                $labels[]   = 'run interrompu';
            }

            // Un full termine mais trop ancien alors qu'un autre a demarre
            $done = $this->lastCompletedFull();
            if ($done !== null && (int)$done['age_hours'] > $maxAge && empty($problems)) {
                $problems[] = "Le dernier 'full' reellement termine date du " . $done['end']
                            . " (il y a " . (int)$done['age_hours'] . " h).";
                $labels[]   = 'dernier full complet il y a ' . (int)$done['age_hours'] . 'h';
            }
        }

        $disk = $this->diskStatus();
        if ($this->diskCritical($disk)) {
            $problems[] = sprintf(
                "Le volume de sauvegarde %s est occupe a %s%% (%s libres sur %s). "
                . "Au-dela, borg echoue avec 'No space left on device' et TOUTES "
                . "les sauvegardes tombent.",
                $disk['path'], $disk['used_percent'],
                self::bytes($disk['free']), self::bytes($disk['total'])
            );
            $labels[] = 'volume a ' . $disk['used_percent'] . '%';
        }

        if (empty($problems) && empty($stale)) {
            $this->log->info("Controle OK : dernier full il y a {$last['age_hours']}h, "
                . "aucun serveur en retard"
                . ($disk ? ", disque a {$disk['used_percent']}%" : ''), 'CHECK');
            return true;
        }

        if (!empty($stale)) $labels[] = count($stale) . ' serveur(s) en retard';
        $subject = $this->subject('ALERTE - ' . implode(', ', $labels));

        $html = $this->renderCheckHtml($problems, $stale, $last, $maxAge);
        $text = $this->renderCheckText($problems, $stale, $last, $maxAge);

        $this->deliver($subject, $html, $text);
        return false;
    }

    /**
     * Indique si un run 'full' est actuellement en cours (verrou pose)
     * @return bool
     */
    public function isRunning() {
        $dir  = is_dir('/run/lock') ? '/run/lock' : sys_get_temp_dir();
        $file = $dir . '/phpborg-full.lock';
        if (!file_exists($file)) return false;

        $fh = @fopen($file, 'c');
        if ($fh === false) return false;
        // Si le verrou peut etre pris, c'est que personne ne le detient
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) flock($fh, LOCK_UN);
        fclose($fh);
        return !$free;
    }

    /**
     * Prefixe le sujet
     * @param string $s
     * @return string
     */
    private function subject($s) {
        return trim((string)$this->cfg('subject_prefix', '[phpBorg]')) . ' ' . $s;
    }

    /**
     * Envoie le mail via le Mailer, sans jamais faire echouer l'appelant
     * @param string $subject
     * @param string $html
     * @param string $text
     * @return bool
     */
    private function deliver($subject, $html, $text) {
        $mailer = new Mailer($this->log);
        if (!$mailer->send($subject, $html, $text)) {
            $this->log->error("Envoi du rapport impossible: " . $mailer->error, 'REPORT');
            // Le rapport reste consultable dans le log meme si le mail ne part pas
            $this->log->error("Contenu du rapport non transmis:\n" . $text, 'REPORT');
            return false;
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Formatage                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Formate une taille en octets
     * @param int|null $bytes
     * @return string
     */
    public static function bytes($bytes) {
        $bytes = (float)$bytes;
        if ($bytes <= 0) return '-';
        $units = array('o', 'Ko', 'Mo', 'Go', 'To', 'Po');
        $i = (int)floor(log($bytes, 1024));
        if ($i >= count($units)) $i = count($units) - 1;
        return round($bytes / pow(1024, $i), $i > 1 ? 2 : 0) . ' ' . $units[$i];
    }

    /**
     * Formate une duree en secondes
     * @param int|float|null $sec
     * @return string
     */
    public static function duration($sec) {
        $sec = (int)round((float)$sec);
        if ($sec <= 0) return '-';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        if ($h > 0) return sprintf('%dh%02dm%02ds', $h, $m, $s);
        if ($m > 0) return sprintf('%dm%02ds', $m, $s);
        return $s . 's';
    }

    /**
     * Nettoie un message d'erreur pour l'affichage
     * @param string $log
     * @param int $max
     * @return string
     */
    public static function cleanError($log, $max = 600) {
        $log = (string)$log;
        $log = str_replace(array('\\n', "\r"), array("\n", ''), $log);
        $log = preg_replace("/\n{3,}/", "\n\n", $log);
        $log = trim($log);
        if ($log === '') return 'Aucun detail (voir /var/log/phpborg.log)';
        if (strlen($log) > $max) $log = substr($log, 0, $max) . "\n[...]";
        return $log;
    }

    /* ------------------------------------------------------------------ */
    /* Rendu HTML                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $title
     * @param string $status
     * @param array $failed
     * @param array $ok
     * @param array $stale
     * @param float $dur
     * @return string
     */
    private function renderHtml($title, $status, $failed, $ok, $stale, $dur) {
        $isFail  = ($status !== 'OK');
        $banner  = $isFail ? '#b3261e' : '#1e7a34';
        $label   = $isFail ? count($failed) . ' SAUVEGARDE(S) EN ECHEC' : 'TOUTES LES SAUVEGARDES SONT OK';
        $host    = htmlspecialchars(gethostname(), ENT_QUOTES, 'UTF-8');

        $h  = $this->htmlHead();
        $h .= '<div style="background:' . $banner . ';color:#fff;padding:16px 20px;border-radius:6px 6px 0 0">';
        $h .= '<div style="font-size:20px;font-weight:700">' . $label . '</div>';
        $h .= '<div style="font-size:13px;opacity:.9;margin-top:4px">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . ' &middot; ' . date('d/m/Y H:i')
            . ($dur > 0 ? ' &middot; duree ' . self::duration($dur) : '')
            . ' &middot; ' . $host . '</div>';
        $h .= '</div>';
        $h .= '<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;padding:20px">';

        // Totaux
        $totO = $totC = $totD = $totF = 0;
        foreach ($ok as $r) {
            $totO += (float)$r['osize']; $totC += (float)$r['csize'];
            $totD += (float)$r['dsize']; $totF += (float)$r['nfiles'];
        }

        $h .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px">';
        $h .= '<tr>';
        $h .= $this->statCell('Reussites', count($ok), '#1e7a34');
        $h .= $this->statCell('Echecs', count($failed), count($failed) > 0 ? '#b3261e' : '#666');
        $h .= $this->statCell('Donnees', self::bytes($totO), '#333');
        $h .= $this->statCell('Stockees (dedup.)', self::bytes($totD), '#333');
        $h .= '</tr></table>';

        $disk = $this->diskStatus();
        if ($disk !== null) {
            $crit  = $this->diskCritical($disk);
            $coul  = $crit ? '#b3261e' : ($disk['used_percent'] >= 80 ? '#8a5a00' : '#1e7a34');
            $fond  = $crit ? '#fdf1f0' : ($disk['used_percent'] >= 80 ? '#fff4e5' : '#f2f8f3');
            $h .= '<div style="padding:10px 12px;background:' . $fond . ';border-left:3px solid '
                . $coul . ';border-radius:3px;margin-bottom:20px;font-size:13px">'
                . '<strong>Volume de sauvegarde</strong> ' . htmlspecialchars($disk['path'], ENT_QUOTES, 'UTF-8')
                . ' : <strong style="color:' . $coul . '">' . $disk['used_percent'] . '%</strong> occupe, '
                . self::bytes($disk['free']) . ' libres sur ' . self::bytes($disk['total'])
                . ($crit ? '<br><strong style="color:#b3261e">Seuil critique atteint : '
                         . 'risque d\'echec massif par manque d\'espace.</strong>' : '')
                . '</div>';
        }

        // Echecs
        if (!empty($failed)) {
            $h .= '<h2 style="font-size:15px;color:#b3261e;margin:24px 0 8px;'
                . 'border-bottom:2px solid #b3261e;padding-bottom:6px">ECHECS (' . count($failed) . ')</h2>';
            foreach ($failed as $r) {
                $h .= '<div style="margin-bottom:14px;padding:10px;background:#fdf1f0;'
                    . 'border-left:3px solid #b3261e;border-radius:3px">';
                $h .= '<div style="font-weight:700;font-size:14px">'
                    . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8')
                    . ' <span style="font-weight:400;color:#666">(' . htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8') . ')</span></div>';
                $h .= '<pre style="margin:6px 0 0;white-space:pre-wrap;word-break:break-word;'
                    . 'font-family:monospace;font-size:12px;color:#5f1a15">'
                    . htmlspecialchars(self::failureReason($r), ENT_QUOTES, 'UTF-8') . '</pre>';
                $h .= '</div>';
            }
        }

        // Abandonnes
        if (!empty($stale)) {
            $h .= '<h2 style="font-size:15px;color:#8a5a00;margin:24px 0 8px;'
                . 'border-bottom:2px solid #d69200;padding-bottom:6px">SERVEURS SANS SAUVEGARDE RECENTE ('
                . count($stale) . ')</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
            $h .= '<tr style="background:#f5f5f5"><th style="' . $this->th() . '">Serveur</th>'
                . '<th style="' . $this->th() . '">Type</th>'
                . '<th style="' . $this->th() . '">Derniere archive</th>'
                . '<th style="' . $this->th() . '">Retard</th></tr>';
            foreach ($stale as $s) {
                $jamais = ($s['dernier'] === null);
                $h .= '<tr>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars((string)$s['type'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . ($jamais ? '<em>jamais</em>' : htmlspecialchars($s['dernier'], ENT_QUOTES, 'UTF-8')) . '</td>';
                $h .= '<td style="' . $this->td() . ';color:#b3261e;font-weight:700">'
                    . ($jamais ? 'aucune archive' : (int)$s['jours'] . ' j') . '</td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        }

        // Reussites
        if (!empty($ok)) {
            $h .= '<h2 style="font-size:15px;color:#1e7a34;margin:24px 0 8px;'
                . 'border-bottom:2px solid #1e7a34;padding-bottom:6px">REUSSITES (' . count($ok) . ')</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:12px">';
            $h .= '<tr style="background:#f5f5f5">'
                . '<th style="' . $this->th() . '">Serveur</th>'
                . '<th style="' . $this->th() . '">Type</th>'
                . '<th style="' . $this->th() . '">Duree</th>'
                . '<th style="' . $this->th() . '">Donnees</th>'
                . '<th style="' . $this->th() . '">Compressees</th>'
                . '<th style="' . $this->th() . '">Dedupliquees</th>'
                . '<th style="' . $this->th() . '">Fichiers</th></tr>';
            foreach ($ok as $r) {
                $h .= '<tr>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . self::duration($r['dur']) . '</td>';
                $h .= '<td style="' . $this->td() . '">' . self::bytes($r['osize']) . '</td>';
                $h .= '<td style="' . $this->td() . '">' . self::bytes($r['csize']) . '</td>';
                $h .= '<td style="' . $this->td() . '">' . self::bytes($r['dsize']) . '</td>';
                $h .= '<td style="' . $this->td() . '">' . ($r['nfiles'] !== null ? number_format((float)$r['nfiles'], 0, ',', ' ') : '-') . '</td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        }

        $h .= $this->htmlFoot();
        return $h;
    }

    /**
     * @param array $problems
     * @param array $stale
     * @param array|null $last
     * @param int $maxAge
     * @return string
     */
    private function renderCheckHtml($problems, $stale, $last, $maxAge) {
        $host = htmlspecialchars(gethostname(), ENT_QUOTES, 'UTF-8');

        $h  = $this->htmlHead();
        $h .= '<div style="background:#b3261e;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0">';
        $h .= '<div style="font-size:20px;font-weight:700">ALERTE SAUVEGARDES</div>';
        $h .= '<div style="font-size:13px;opacity:.9;margin-top:4px">Controle du '
            . date('d/m/Y H:i') . ' &middot; ' . $host . '</div>';
        $h .= '</div>';
        $h .= '<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;padding:20px">';

        if (!empty($problems)) {
            foreach ($problems as $p) {
                $h .= '<div style="padding:12px;background:#fdf1f0;border-left:3px solid #b3261e;'
                    . 'border-radius:3px;margin-bottom:12px;font-size:14px;font-weight:700;color:#5f1a15">'
                    . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $h .= '<p style="font-size:13px;color:#444">A verifier : la tache cron de 22h '
                . '(<code>crontab -l</code>), le verrou <code>/run/lock/phpborg.lock</code>, '
                . 'et <code>/var/log/phpborg.log</code>.</p>';
        } else {
            $h .= '<p style="font-size:13px">Dernier run complet : <strong>'
                . htmlspecialchars($last['start'], ENT_QUOTES, 'UTF-8') . '</strong> (il y a '
                . (int)$last['age_hours'] . ' h, seuil ' . $maxAge . ' h).</p>';
        }

        if (!empty($stale)) {
            $h .= '<h2 style="font-size:15px;color:#8a5a00;margin:24px 0 8px;'
                . 'border-bottom:2px solid #d69200;padding-bottom:6px">SERVEURS SANS SAUVEGARDE RECENTE ('
                . count($stale) . ')</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
            $h .= '<tr style="background:#f5f5f5"><th style="' . $this->th() . '">Serveur</th>'
                . '<th style="' . $this->th() . '">Type</th>'
                . '<th style="' . $this->th() . '">Derniere archive</th>'
                . '<th style="' . $this->th() . '">Retard</th></tr>';
            foreach ($stale as $s) {
                $jamais = ($s['dernier'] === null);
                $h .= '<tr>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . htmlspecialchars((string)$s['type'], ENT_QUOTES, 'UTF-8') . '</td>';
                $h .= '<td style="' . $this->td() . '">' . ($jamais ? '<em>jamais</em>' : htmlspecialchars($s['dernier'], ENT_QUOTES, 'UTF-8')) . '</td>';
                $h .= '<td style="' . $this->td() . ';color:#b3261e;font-weight:700">'
                    . ($jamais ? 'aucune archive' : (int)$s['jours'] . ' j') . '</td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        }

        $h .= $this->htmlFoot();
        return $h;
    }

    /**
     * @return string
     */
    private function htmlHead() {
        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
             . 'max-width:900px;margin:0 auto;color:#222">';
    }

    /**
     * @return string
     */
    private function htmlFoot() {
        return '<p style="margin-top:24px;padding-top:12px;border-top:1px solid #eee;'
             . 'font-size:11px;color:#888">phpBorg &middot; journal complet dans '
             . '<code>/var/log/phpborg.log</code></p></div></div>';
    }

    /**
     * @return string
     */
    private function th() {
        return 'text-align:left;padding:6px 8px;border-bottom:2px solid #ddd;font-weight:600';
    }

    /**
     * @return string
     */
    private function td() {
        return 'padding:5px 8px;border-bottom:1px solid #eee';
    }

    /**
     * @param string $label
     * @param mixed $value
     * @param string $color
     * @return string
     */
    private function statCell($label, $value, $color) {
        return '<td style="padding:10px;background:#f7f7f7;border-radius:4px;text-align:center">'
             . '<div style="font-size:22px;font-weight:700;color:' . $color . '">'
             . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</div>'
             . '<div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.5px">'
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
    }

    /* ------------------------------------------------------------------ */
    /* Rendu texte                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $title
     * @param string $status
     * @param array $failed
     * @param array $ok
     * @param array $stale
     * @param float $dur
     * @return string
     */
    private function renderText($title, $status, $failed, $ok, $stale, $dur) {
        $t  = strtoupper($title) . "\n";
        $t .= str_repeat('=', 70) . "\n";
        $t .= 'Machine  : ' . gethostname() . "\n";
        $t .= 'Date     : ' . date('d/m/Y H:i') . "\n";
        if ($dur > 0) $t .= 'Duree    : ' . self::duration($dur) . "\n";
        $t .= 'Resultat : ' . count($ok) . ' OK, ' . count($failed) . " en echec\n";
        $disk = $this->diskStatus();
        if ($disk !== null) {
            $t .= 'Volume   : ' . $disk['path'] . ' ' . $disk['used_percent'] . '% occupe, '
                . self::bytes($disk['free']) . ' libres sur ' . self::bytes($disk['total'])
                . ($this->diskCritical($disk) ? '  <<< SEUIL CRITIQUE' : '') . "\n";
        }
        $t .= "\n";

        if (!empty($failed)) {
            $t .= "ECHECS (" . count($failed) . ")\n" . str_repeat('-', 70) . "\n";
            foreach ($failed as $r) {
                $t .= '* ' . $r['name'] . ' (' . $r['type'] . ")\n";
                foreach (explode("\n", self::failureReason($r)) as $l) {
                    $t .= '    ' . $l . "\n";
                }
                $t .= "\n";
            }
        }

        if (!empty($stale)) {
            $t .= 'SERVEURS SANS SAUVEGARDE RECENTE (' . count($stale) . ")\n" . str_repeat('-', 70) . "\n";
            foreach ($stale as $s) {
                $t .= sprintf("* %-24s %-8s %s\n", $s['name'], (string)$s['type'],
                    $s['dernier'] === null ? 'aucune archive' : $s['dernier'] . '  (' . (int)$s['jours'] . ' j)');
            }
            $t .= "\n";
        }

        if (!empty($ok)) {
            $t .= 'REUSSITES (' . count($ok) . ")\n" . str_repeat('-', 70) . "\n";
            foreach ($ok as $r) {
                $t .= sprintf("* %-24s %-8s %-10s %10s\n", $r['name'], $r['type'],
                    self::duration($r['dur']), self::bytes($r['osize']));
            }
            $t .= "\n";
        }

        $t .= "Journal complet : /var/log/phpborg.log\n";
        return $t;
    }

    /**
     * @param array $problems
     * @param array $stale
     * @param array|null $last
     * @param int $maxAge
     * @return string
     */
    private function renderCheckText($problems, $stale, $last, $maxAge) {
        $t  = "ALERTE SAUVEGARDES\n" . str_repeat('=', 70) . "\n";
        $t .= 'Machine : ' . gethostname() . "\n";
        $t .= 'Date    : ' . date('d/m/Y H:i') . "\n\n";

        if (!empty($problems)) {
            foreach ($problems as $p) $t .= '!! ' . $p . "\n";
            $t .= "\nA verifier : la tache cron de 22h (crontab -l), le verrou\n"
                . "/run/lock/phpborg.lock, et /var/log/phpborg.log\n\n";
        } elseif ($last !== null) {
            $t .= 'Dernier run complet : ' . $last['start'] . ' (il y a '
                . (int)$last['age_hours'] . ' h, seuil ' . $maxAge . " h)\n\n";
        }

        if (!empty($stale)) {
            $t .= 'SERVEURS SANS SAUVEGARDE RECENTE (' . count($stale) . ")\n" . str_repeat('-', 70) . "\n";
            foreach ($stale as $s) {
                $t .= sprintf("* %-24s %-8s %s\n", $s['name'], (string)$s['type'],
                    $s['dernier'] === null ? 'aucune archive' : $s['dernier'] . '  (' . (int)$s['jours'] . ' j)');
            }
            $t .= "\n";
        }

        $t .= "Journal complet : /var/log/phpborg.log\n";
        return $t;
    }
}
