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
                    r.osize, r.csize, r.dsize, r.nb_archive, r.error, r.log, r.cleanup_freed,
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
                    r.osize, r.csize, r.dsize, r.nb_archive, r.error, r.log, r.cleanup_freed,
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
                  AND NOT EXISTS (SELECT 1 FROM db_info d
                                  WHERE d.server_id = s.id AND d.type = r.type
                                    AND d.active = 0)
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
    public function markInterrupted($reportId, $signal, $curpos = null) {
        $note = "Run interrompu par $signal"
              . ($curpos ? " pendant le traitement de $curpos" : '') . "\n";
        $this->db->query(
            "UPDATE IGNORE report SET `end` = NOW(), `error` = GREATEST(COALESCE(`error`,0),1),
                    `log` = CONCAT(COALESCE(`log`,''), ?) WHERE id = ?",
            $note, (int)$reportId
        );
    }

    /**
     * Marque un run comme interrompu et alerte immediatement.
     * @param int $reportId
     * @param string $signal
     * @param string $kind
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

        $titre   = ($kind === 'full') ? 'Sauvegarde complète INTERROMPUE' : 'Sauvegarde INTERROMPUE';
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
        // Rejouer un rapport ("phpborg report") ne fournit pas la duree :
        // la relire en base plutot que de l'omettre du bandeau.
        if ($durationSeconds <= 0) {
            $r = $this->db->query(
                "SELECT TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())) AS sec
                 FROM report WHERE id = ?", (int)$fullReportId
            )->fetchArray();
            if (!empty($r['sec'])) $durationSeconds = (int)$r['sec'];
        }

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

        $html = $this->renderHtml('Sauvegarde complète', $status, $failed, $ok, $stale, $durationSeconds);
        $text = $this->renderText('Sauvegarde complète', $status, $failed, $ok, $stale, $durationSeconds);

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
        if ($durationSeconds <= 0) {
            $r = $this->db->query(
                "SELECT TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())) AS sec
                 FROM report WHERE id = ?", (int)$reportId
            )->fetchArray();
            if (!empty($r['sec'])) $durationSeconds = (int)$r['sec'];
        }

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
        // Peu de decimales : sur telephone chaque caractere compte, et
        // "185 Mo" se lit aussi bien que "185.48 Mo"
        $dec = ($i <= 2) ? 0 : (($i === 3) ? 1 : 2);
        return round($bytes / pow(1024, $i), $dec) . ' ' . $units[$i];
    }

    /**
     * Formate un nombre de fichiers de facon compacte.
     * "16 464 819 fichiers" passe a la ligne sur telephone et iOS le prend
     * pour un numero de telephone (il le souligne en bleu) : on abrege.
     * @param int|float|null $n
     * @return string
     */
    public static function files($n) {
        $n = (float)$n;
        if ($n <= 0) return '-';
        if ($n < 10000)    return number_format($n, 0, ',', ' ');
        if ($n < 1000000)  return round($n / 1000) . ' k';
        return str_replace('.', ',', (string)round($n / 1000000, 1)) . ' M';
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
    /*                                                                     */
    /* Concu pour etre lisible sur telephone : pas de tableau large qui     */
    /* deborde, pas de media query (Gmail ne les applique pas toujours).    */
    /* Chaque ligne de serveur tient sur deux lignes de texte, et les       */
    /* compteurs se placent en 2x2 plutot qu'en 4 colonnes serrees.         */
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
        $isFail = ($status !== 'OK');
        $banner = $isFail ? '#b3261e' : '#1e7a34';
        $label  = $isFail
                ? count($failed) . ' sauvegarde' . (count($failed) > 1 ? 's' : '') . ' en échec'
                : 'Toutes les sauvegardes sont OK';
        $host   = $this->esc(gethostname());

        $totO = $totD = $totC = 0;
        foreach ($ok as $r) {
            $totO += (float)$r['osize'];
            $totD += (float)$r['dsize'];
            if (isset($r['cleanup_freed'])) $totC += (float)$r['cleanup_freed'];
        }

        $sous = $this->esc($title) . ' &middot; ' . date('d/m/Y H:i')
              . ($dur > 0 ? ' &middot; ' . self::duration($dur) : '')
              . ' &middot; ' . $host;

        $h  = $this->htmlHead();
        $h .= $this->banner($banner, $label, $sous);
        $h .= '<div style="border:1px solid #e0e0e0;border-top:none;'
            . 'border-radius:0 0 6px 6px;padding:11px 10px">';

        $cases = array(
            array('Réussites', count($ok),            '#1e7a34'),
            array('Échecs',    count($failed),        count($failed) > 0 ? '#b3261e' : '#888'),
            array('Données',   self::bytes($totO),    '#333'),
            array('Stocké',    self::bytes($totD),    '#333'),
        );
        // La tuile n'apparait que si un nettoyage a reellement libere quelque
        // chose : inutile d'afficher un zero permanent sur un parc non configure.
        if ($totC > 0) $cases[] = array('Libéré', self::bytes($totC), '#1e7a34');
        $h .= $this->statGrid($cases);

        $h .= $this->diskBanner();

        if (!empty($failed)) {
            $h .= $this->sectionTitle('Échecs (' . count($failed) . ')', '#b3261e');
            foreach ($failed as $r) {
                $h .= '<div style="margin-bottom:12px;padding:10px;background:#fdf1f0;'
                    . 'border-left:3px solid #b3261e;border-radius:3px">'
                    . '<div style="font-weight:700;font-size:14px;color:#5f1a15">'
                    . $this->esc($r['name']) . ' ' . $this->badge($r['type'], '#b3261e')
                    . '</div>'
                    . '<div style="margin:6px 0 0;white-space:pre-wrap;word-break:break-word;'
                    . 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;'
                    . 'line-height:1.5;color:#5f1a15">'
                    . $this->esc(self::failureReason($r)) . '</div></div>';
            }
        }

        if (!empty($stale)) {
            $h .= $this->sectionTitle('Sans sauvegarde récente (' . count($stale) . ')', '#d69200');
            $h .= $this->staleList($stale);
        }

        if (!empty($ok)) {
            $h .= $this->sectionTitle('Réussites (' . count($ok) . ')', '#1e7a34');
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" '
                . 'style="width:100%;border-collapse:collapse">';
            foreach ($ok as $r) {
                $detail = self::bytes($r['osize']) . ' &rarr; ' . self::bytes($r['csize'])
                        . ' &rarr; ' . self::bytes($r['dsize']);
                if ($r['nfiles'] !== null && (float)$r['nfiles'] > 0) {
                    $detail .= ' &middot; ' . self::files($r['nfiles']) . ' fich.';
                }
                if (!empty($r['cleanup_freed'])) {
                    $detail .= ' &middot; nettoyé ' . self::bytes($r['cleanup_freed']);
                }
                $h .= '<tr>'
                    . '<td style="padding:8px 5px 8px 0;border-bottom:1px solid #eee;vertical-align:top">'
                    . '<div style="font-size:14px;font-weight:600;color:#1a1a1a">'
                    . $this->esc($r['name']) . ' ' . $this->badge($r['type'], '#6b7280') . '</div>'
                    . '<div style="font-size:12px;color:#666;margin-top:3px;line-height:1.5">'
                    . $detail . '</div>'
                    . '</td>'
                    . '<td style="padding:9px 0;border-bottom:1px solid #eee;vertical-align:top;'
                    . 'text-align:right;white-space:nowrap;font-size:13px;color:#555">'
                    . self::duration($r['dur']) . '</td>'
                    . '</tr>';
            }
            $h .= '</table>';
        }

        return $h . $this->htmlFoot();
    }

    /**
     * @param array $problems
     * @param array $stale
     * @param array|null $last
     * @param int $maxAge
     * @return string
     */
    private function renderCheckHtml($problems, $stale, $last, $maxAge) {
        $h  = $this->htmlHead();
        $h .= $this->banner('#b3261e', 'Alerte sauvegardes',
                            'Contrôle du ' . date('d/m/Y H:i') . ' &middot; ' . $this->esc(gethostname()));
        $h .= '<div style="border:1px solid #e0e0e0;border-top:none;'
            . 'border-radius:0 0 6px 6px;padding:11px 10px">';

        if (!empty($problems)) {
            foreach ($problems as $p) {
                $h .= '<div style="padding:11px;background:#fdf1f0;border-left:3px solid #b3261e;'
                    . 'border-radius:3px;margin-bottom:10px;font-size:14px;line-height:1.5;'
                    . 'font-weight:600;color:#5f1a15">' . $this->esc($p) . '</div>';
            }
            $h .= '<p style="font-size:13px;color:#444;line-height:1.6;margin:12px 0">'
                . 'À vérifier : la tâche cron de 22h (<code>crontab -l</code>), le verrou '
                . '<code>/run/lock/phpborg-full.lock</code>, et <code>/var/log/phpborg.log</code>.</p>';
        } elseif ($last !== null) {
            $h .= '<p style="font-size:13px;line-height:1.6;margin:0 0 12px">Dernier run complet : <strong>'
                . $this->esc($last['start']) . '</strong> (il y a ' . (int)$last['age_hours']
                . ' h, seuil ' . $maxAge . ' h).</p>';
        }

        $h .= $this->diskBanner();

        if (!empty($stale)) {
            $h .= $this->sectionTitle('Sans sauvegarde récente (' . count($stale) . ')', '#d69200');
            $h .= $this->staleList($stale);
        }

        return $h . $this->htmlFoot();
    }

    /* ------------------------------------------------------------------ */
    /* Briques de rendu                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Echappement HTML
     * @param mixed $v
     * @return string
     */
    private function esc($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Bandeau de tete
     * @param string $color
     * @param string $titre
     * @param string $sousTitre deja echappe
     * @return string
     */
    private function banner($color, $titre, $sousTitre) {
        return '<div style="background:' . $color . ';color:#ffffff;padding:15px 16px;'
             . 'border-radius:6px 6px 0 0">'
             . '<div style="font-size:19px;font-weight:700;line-height:1.3">'
             . $this->esc($titre) . '</div>'
             . '<div style="font-size:13px;opacity:.92;margin-top:5px;line-height:1.5">'
             . $sousTitre . '</div></div>';
    }

    /**
     * Compteurs en 2x2 : quatre colonnes deviennent illisibles sur telephone
     * @param array $cells [[label, valeur, couleur], ...]
     * @return string
     */
    private function statGrid($cells) {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" '
           . 'style="width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px 14px">';
        $i = 0;
        foreach ($cells as $c) {
            if ($i % 2 === 0) $h .= '<tr>';
            $h .= '<td width="50%" style="width:50%;padding:11px 8px;background:#f5f6f7;'
                . 'border-radius:5px;text-align:center">'
                . '<div style="font-size:21px;font-weight:700;line-height:1.2;color:' . $c[2] . '">'
                . $this->esc($c[1]) . '</div>'
                . '<div style="font-size:11px;color:#666;text-transform:uppercase;'
                . 'letter-spacing:.4px;margin-top:3px">' . $this->esc($c[0]) . '</div></td>';
            if ($i % 2 === 1) $h .= '</tr>';
            $i++;
        }
        if ($i % 2 === 1) $h .= '<td width="50%"></td></tr>';
        return $h . '</table>';
    }

    /**
     * Pastille de type (backup / mysql)
     * @param string $texte
     * @param string $color
     * @return string
     */
    private function badge($texte, $color) {
        return '<span style="display:inline-block;font-size:11px;font-weight:600;color:#ffffff;'
             . 'background:' . $color . ';padding:1px 6px;border-radius:3px;'
             . 'vertical-align:middle">' . $this->esc($texte) . '</span>';
    }

    /**
     * Titre de section
     * @param string $texte
     * @param string $color
     * @return string
     */
    private function sectionTitle($texte, $color) {
        return '<div style="font-size:15px;font-weight:700;color:' . $color . ';'
             . 'margin:22px 0 9px;padding-bottom:5px;border-bottom:2px solid ' . $color . '">'
             . $this->esc($texte) . '</div>';
    }

    /**
     * Liste des serveurs en retard, empilee plutot qu'en tableau
     * @param array $stale
     * @return string
     */
    private function staleList($stale) {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" '
           . 'style="width:100%;border-collapse:collapse">';
        foreach ($stale as $s) {
            $jamais = ($s['dernier'] === null);
            $h .= '<tr>'
                . '<td style="padding:8px 5px 8px 0;border-bottom:1px solid #eee;vertical-align:top">'
                . '<div style="font-size:14px;font-weight:600">' . $this->esc($s['name']) . ' '
                . $this->badge((string)$s['type'], '#6b7280') . '</div>'
                . '<div style="font-size:12px;color:#666;margin-top:3px">'
                . ($jamais ? '<em>aucune archive</em>' : 'dernière : ' . $this->esc($s['dernier']))
                . '</div></td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;vertical-align:top;'
                . 'text-align:right;white-space:nowrap;font-size:14px;font-weight:700;color:#b3261e">'
                . ($jamais ? '&mdash;' : (int)$s['jours'] . ' j') . '</td>'
                . '</tr>';
        }
        return $h . '</table>';
    }

    /**
     * Bandeau d'occupation du volume
     * @return string
     */
    private function diskBanner() {
        $disk = $this->diskStatus();
        if ($disk === null) return '';

        $crit = $this->diskCritical($disk);
        $coul = $crit ? '#b3261e' : ($disk['used_percent'] >= 80 ? '#8a5a00' : '#1e7a34');
        $fond = $crit ? '#fdf1f0' : ($disk['used_percent'] >= 80 ? '#fff8ec' : '#f2f8f3');

        return '<div style="padding:10px 11px;background:' . $fond . ';border-left:3px solid '
             . $coul . ';border-radius:3px;margin-bottom:14px;font-size:13px;line-height:1.6">'
             . '<strong>Volume</strong> ' . $this->esc($disk['path']) . '<br>'
             . '<strong style="color:' . $coul . ';font-size:15px">' . $disk['used_percent'] . '%</strong>'
             . ' occupé &middot; ' . self::bytes($disk['free']) . ' libres sur '
             . self::bytes($disk['total'])
             . ($crit ? '<br><strong style="color:#b3261e">Seuil critique : risque d\'échec '
                      . 'massif par manque d\'espace.</strong>' : '')
             . '</div>';
    }

    /**
     * @return string
     */
    private function htmlHead() {
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,'
             . 'Helvetica,Arial,sans-serif;max-width:680px;margin:0 auto;padding:6px;'
             . 'color:#222;-webkit-text-size-adjust:100%">';
    }

    /**
     * @return string
     */
    private function htmlFoot() {
        return '<div style="margin-top:20px;padding-top:11px;border-top:1px solid #eee;'
             . 'font-size:11px;color:#888;line-height:1.6">phpBorg &middot; journal complet dans '
             . '<code>/var/log/phpborg.log</code></div></div></div>';
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
        $libere = 0;
        foreach ($ok as $r) if (!empty($r['cleanup_freed'])) $libere += (float)$r['cleanup_freed'];
        if ($libere > 0) $t .= 'Libere   : ' . self::bytes($libere) . " par le nettoyage\n";
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
                $t .= sprintf("* %-24s %-8s %-10s %10s%s\n", $r['name'], $r['type'],
                    self::duration($r['dur']), self::bytes($r['osize']),
                    empty($r['cleanup_freed']) ? '' : '  nettoye ' . self::bytes($r['cleanup_freed']));
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
