<?php
namespace phpBorg;

/**
 * Cleanup - nettoyage des fichiers regenerables sur les machines sauvegardees.
 *
 * Principe de surete, dans l'ordre d'importance :
 *
 *  1. Liste blanche. La base ne contient JAMAIS de commande, seulement des
 *     mots-cles. La correspondance mot-cle -> commande vit ici, versionnee et
 *     relue. Une commande libre en base, c'est un "rm -rf" a une faute de
 *     frappe pres.
 *  2. Opt-in. servers.cleanup vaut NULL par defaut : sans configuration
 *     explicite, rien ne se passe sur aucune machine.
 *  3. Jamais apres un echec. L'appelant ne declenche le nettoyage que si la
 *     sauvegarde a reussi : si le nettoyage se passe mal, l'archive existe.
 *  4. Jamais bloquant. Chaque commande a son timeout ; une erreur produit une
 *     ligne dans le rapport, pas un echec de sauvegarde.
 *  5. Mesurable. df avant / apres, pour que le gain soit un fait et non une
 *     promesse.
 */
class Cleanup {

    /** @var array|null catalogue construit a la demande (voir catalogue()) */
    private static $cat = null;

    /**
     * Catalogue des operations autorisees.
     *
     * niveau  'A' aucun risque : ne detruit que du log ou du regenerable local
     *         'B' aucune perte de donnee, mais un cout (rebuild, re-pull)
     * mesure  commande shell ecrivant sur stdout un nombre d'octets
     * action  commande shell effectuant le nettoyage
     * docker  l'operation est ignoree si docker n'est pas present
     *
     * Les %JOKERS% sont remplaces par les reglages (voir substituer()).
     *
     * Note : toutes les chaines sont en quotes simples, pour qu'aucune
     * variable shell ($1, $vg...) ne soit interpretee par PHP.
     */
    public static function catalogue() {
        if (self::$cat !== null) return self::$cat;

        self::$cat = array(

        /* ---------------------------------------------------------- A --- */

        'journald' => array(
            'niveau' => 'A',
            'descr'  => 'Journaux systemd au-dela de %JOURNAL_KEEP%',
            // journald sait cloisonner ses journaux par "namespace" : netdata
            // en cree un, qui atteint la taille maximale de son cote sans
            // qu'un vacuum ordinaire ne le voie. On traite donc le journal
            // principal ET chaque namespace present.
            'mesure' => 'mid=$(cat /etc/machine-id 2>/dev/null); find /var/log/journal/$mid /var/log/journal/$mid.* -type f -name \'*@*.journal\' -mtime +%JOURNAL_DAYS% -printf \'%s\n\' 2>/dev/null | awk \'{s+=$1} END{print s+0}\'',
            'action' => 'mid=$(cat /etc/machine-id 2>/dev/null); journalctl --vacuum-time=%JOURNAL_KEEP% 2>&1 | tail -1; for d in /var/log/journal/$mid.*; do [ -d "$d" ] || continue; ns=${d##*.}; journalctl --namespace="$ns" --vacuum-time=%JOURNAL_KEEP% 2>&1 | tail -1; done',
        ),

        'journal-orphelins' => array(
            'niveau' => 'A',
            'descr'  => 'Journaux d\'une ancienne identite de la machine',
            // Repertoires /var/log/journal/<machine-id> qui ne correspondent
            // plus au machine-id courant : plus personne n'y ecrit et
            // journalctl ne les purge jamais.
            'mesure' => 'mid=$(cat /etc/machine-id 2>/dev/null); [ -n "$mid" ] || exit 0; for d in /var/log/journal/*/; do b=$(basename "$d"); case "$b" in "$mid"|"$mid".*) continue;; esac; du -sk "$d" 2>/dev/null; done | awk \'{s+=$1} END{print s*1024}\'',
            'action' => 'mid=$(cat /etc/machine-id 2>/dev/null); [ -n "$mid" ] || exit 0; for d in /var/log/journal/*/; do b=$(basename "$d"); case "$b" in "$mid"|"$mid".*) continue;; esac; rm -rf "$d" && echo "supprime $b"; done',
        ),

        'apt-cache' => array(
            'niveau' => 'A',
            'descr'  => 'Paquets .deb deja installes',
            'mesure' => 'du -sk /var/cache/apt/archives 2>/dev/null | awk \'{print $1*1024}\'',
            'action' => 'apt-get clean 2>&1 | tail -2',
        ),

        'logs-anciens' => array(
            'niveau' => 'A',
            'descr'  => 'Logs tournes de plus de %LOGS_AGE% jours',
            'mesure' => 'find /var/log -type f \\( -name \'*.gz\' -o -name \'*.xz\' -o -name \'*.bz2\' -o -name \'*.[0-9]\' -o -name \'*.old\' \\) -mtime +%LOGS_AGE% -printf \'%s\n\' 2>/dev/null | awk \'{s+=$1} END{print s+0}\'',
            'action' => 'find /var/log -type f \\( -name \'*.gz\' -o -name \'*.xz\' -o -name \'*.bz2\' -o -name \'*.[0-9]\' -o -name \'*.old\' \\) -mtime +%LOGS_AGE% -delete 2>&1 | tail -2',
        ),

        'coredump' => array(
            'niveau' => 'A',
            'descr'  => 'Vidages memoire',
            'mesure' => 'du -sk /var/crash /var/lib/systemd/coredump 2>/dev/null | awk \'{s+=$1} END{print s*1024}\'',
            'action' => 'find /var/crash /var/lib/systemd/coredump -type f -delete 2>&1 | tail -2',
        ),

        'docker-dangling' => array(
            'niveau' => 'A',
            'descr'  => 'Images Docker sans tag (<none>)',
            // Les couches etant partagees, cette somme majore le gain reel.
            'mesure' => 'docker image ls -f dangling=true --format \'{{.Size}}\' 2>/dev/null | awk \'{v=$1+0; u=$2; if(u=="kB")v*=1000; else if(u=="MB")v*=1000000; else if(u=="GB")v*=1000000000; else if(u=="TB")v*=1000000000000; s+=v} END{printf "%d\n", s}\'',
            'action' => 'docker image prune -f 2>&1 | tail -2',
            'docker' => true,
        ),

        'lvm-orphan' => array(
            'niveau' => 'A',
            'descr'  => 'Snapshot LVM %SNAPNAME% reste en place',
            // Ne cible QUE le nom utilise par phpBorg, jamais un autre LV.
            'mesure' => 'lvs --noheadings --units b --nosuffix -o lv_name,lv_size 2>/dev/null | awk \'$1=="%SNAPNAME%"{s+=$2} END{printf "%d\n", s+0}\'',
            'action' => 'mount | grep -q " /%SNAPNAME% " && umount -fl /%SNAPNAME% ; lvs --noheadings -o lv_name,vg_name 2>/dev/null | awk \'$1=="%SNAPNAME%"{print $2}\' | while read vg; do lvremove -f "/dev/$vg/%SNAPNAME%"; done 2>&1 | tail -3',
        ),

        /* ---------------------------------------------------------- B --- */

        'docker-buildcache' => array(
            'niveau' => 'B',
            'descr'  => 'Cache de construction Docker%CACHE_UNTIL_TXT%',
            'mesure' => 'docker system df --format \'{{.Type}}|{{.Size}}\' 2>/dev/null | awk -F\'|\' \'$1=="Build Cache"{n=$2; split(n,a," "); v=a[1]+0; if(n ~ /kB/)v*=1000; else if(n ~ /MB/)v*=1000000; else if(n ~ /GB/)v*=1000000000; else if(n ~ /TB/)v*=1000000000000; printf "%d\n", v}\'',
            'action' => 'docker builder prune -af %CACHE_UNTIL% 2>&1 | tail -2',
            'docker' => true,
        ),

        'docker-images' => array(
            'niveau' => 'B',
            'descr'  => 'Images Docker inutilisees%IMG_UNTIL_TXT%',
            'mesure' => 'docker system df --format \'{{.Type}}|{{.Reclaimable}}\' 2>/dev/null | awk -F\'|\' \'$1=="Images"{n=$2; split(n,a," "); v=a[1]+0; if(n ~ /kB/)v*=1000; else if(n ~ /MB/)v*=1000000; else if(n ~ /GB/)v*=1000000000; else if(n ~ /TB/)v*=1000000000000; printf "%d\n", v}\'',
            'action' => 'docker image prune -af %IMG_UNTIL% 2>&1 | tail -2',
            'docker' => true,
        ),

        'cache-langages' => array(
            'niveau' => 'B',
            'descr'  => 'Caches npm, yarn, pip, composer',
            'mesure' => 'du -sk /root/.npm /root/.cache/pip /root/.cache/composer /root/.composer/cache /root/.cache/yarn /home/*/.npm /home/*/.cache/pip /home/*/.cache/composer /home/*/.cache/yarn 2>/dev/null | awk \'{s+=$1} END{print s*1024}\'',
            'action' => 'rm -rf /root/.npm/_cacache /root/.cache/pip /root/.cache/composer /root/.composer/cache /root/.cache/yarn /home/*/.npm/_cacache /home/*/.cache/pip /home/*/.cache/composer /home/*/.cache/yarn 2>&1 | tail -2',
        ),
        );

        return self::$cat;
    }

    /** @var LogWriter */
    private $log;
    /** @var array reglages issus de settings */
    private $cfg;
    /** @var array profils nommes issus de settings */
    private $profils = array();

    public function __construct($log, $db = null) {
        $this->log = $log;
        $this->cfg = array(
            'timeout'      => 600,
            'journal_keep' => '30d',
            'logs_age'     => 90,
            'img_until'    => '720h',
            'cache_until'  => '168h',
            'snapname'     => 'phpborg',
        );
        if ($db) $this->chargerReglages($db);
    }

    /**
     * Lit les reglages et les profils depuis la table settings.
     * Les cles 'cleanup_profile_<nom>' definissent un profil reutilisable.
     */
    private function chargerReglages($db) {
        $strict = $db->sql_err;
        $db->sql_err = 0;
        $res  = @$db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'cleanup%'");
        $rows = $res ? $res->fetchAll() : array();
        $db->sql_err = $strict;
        if (empty($rows)) return;

        $connues = array('cleanup_timeout'      => 'timeout',
                         'cleanup_journal_keep' => 'journal_keep',
                         'cleanup_logs_age'     => 'logs_age',
                         'cleanup_images_until' => 'img_until',
                         'cleanup_cache_until'  => 'cache_until');

        foreach ($rows as $r) {
            $k = isset($r['key']) ? $r['key'] : '';
            $v = isset($r['value']) ? trim($r['value']) : '';
            if (strpos($k, 'cleanup_profile_') === 0) {
                $this->profils[substr($k, 16)] = $v;
            }
            elseif (isset($connues[$k]) && $v !== '') {
                $this->cfg[$connues[$k]] = $v;
            }
        }
    }

    /** Cles d'un niveau donne, dans l'ordre du catalogue. */
    public static function clesDuNiveau($niveau) {
        $out = array();
        foreach (self::catalogue() as $k => $c) if ($c['niveau'] === $niveau) $out[] = $k;
        return $out;
    }

    /** Niveau d'une cle. */
    public static function niveau($cle) {
        $cat = self::catalogue();
        return isset($cat[$cle]) ? $cat[$cle]['niveau'] : '?';
    }

    /** Profils nommes charges depuis settings. */
    public function profils() { return $this->profils; }

    /**
     * Resout une specification en liste de cles.
     *
     * Accepte : 'journald,apt-cache'      cles explicites
     *           '@docker'                 profil defini dans settings
     *           '@base,docker-images'     melange
     *           'A' / 'B'                 tout un niveau
     *
     * Une cle inconnue est ignoree avec un avertissement : une faute de frappe
     * ne doit pas faire executer autre chose que ce qui est ecrit.
     *
     * @param string $spec
     * @param int $profondeur garde-fou contre les profils circulaires
     * @return array
     */
    public function resoudre($spec, $profondeur = 0) {
        $out = array();
        if ($spec === null || trim($spec) === '' || $profondeur > 5) return $out;
        $cat = self::catalogue();

        foreach (explode(',', $spec) as $item) {
            $item = trim($item);
            if ($item === '') continue;

            if ($item === 'A' || $item === 'B') {
                $out = array_merge($out, self::clesDuNiveau($item));
            }
            elseif ($item[0] === '@') {
                $nom = substr($item, 1);
                if (isset($this->profils[$nom])) {
                    $out = array_merge($out, $this->resoudre($this->profils[$nom], $profondeur + 1));
                } else {
                    $this->log->warning("Profil de nettoyage inconnu : @$nom");
                }
            }
            elseif (isset($cat[$item])) {
                $out[] = $item;
            }
            else {
                $this->log->warning("Cle de nettoyage inconnue, ignoree : $item");
            }
        }
        // Conserve l'ordre du catalogue : le moins risque d'abord.
        $ordonne = array();
        foreach ($cat as $k => $c) if (in_array($k, $out, true)) $ordonne[] = $k;
        return $ordonne;
    }

    /** Remplace les jokers d'une commande par les reglages courants. */
    private function substituer($cmd) {
        $imgU   = trim($this->cfg['img_until']);
        $cacheU = trim($this->cfg['cache_until']);
        $rep = array(
            '%JOURNAL_KEEP%'    => $this->cfg['journal_keep'],
            '%JOURNAL_DAYS%'    => (int) rtrim($this->cfg['journal_keep'], 'dhms'),
            '%LOGS_AGE%'        => (int) $this->cfg['logs_age'],
            '%SNAPNAME%'        => $this->cfg['snapname'],
            '%IMG_UNTIL%'       => $imgU   !== '' ? '--filter until=' . $imgU   : '',
            '%CACHE_UNTIL%'     => $cacheU !== '' ? '--filter until=' . $cacheU : '',
            '%IMG_UNTIL_TXT%'   => $imgU   !== '' ? ' de plus de ' . $imgU      : ' (sans filtre d\'age)',
            '%CACHE_UNTIL_TXT%' => $cacheU !== '' ? ' de plus de ' . $cacheU    : ' (sans filtre d\'age)',
        );
        return strtr($cmd, $rep);
    }

    /** Libelle lisible d'une cle. */
    public function descr($cle) {
        $cat = self::catalogue();
        return isset($cat[$cle]) ? $this->substituer($cat[$cle]['descr']) : $cle;
    }

    /**
     * Construit le script execute sur la machine distante.
     *
     * Un seul script pour toute la machine : une seule session SSH, et les
     * mesures avant/apres encadrent reellement les actions.
     *
     * @param array $cles
     * @param bool $simulation
     * @return string
     */
    private function script($cles, $simulation) {
        $to  = (int) $this->cfg['timeout'];
        $cat = self::catalogue();

        $s = "#!/bin/bash\n"
           . "export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
           . "libre() { df -P / 2>/dev/null | awk 'NR==2{print \$4*1024}'; }\n"
           . "num() { read v; case \"\$v\" in ''|*[!0-9]*) echo 0;; *) echo \"\$v\";; esac; }\n"
           . "has_docker=0\n"
           . "command -v docker >/dev/null 2>&1 && timeout 20 docker info >/dev/null 2>&1 && has_docker=1\n"
           . "echo \"DF|avant|\$(libre)\"\n";

        foreach ($cles as $cle) {
            if (!isset($cat[$cle])) continue;
            $c      = $cat[$cle];
            $mesure = $this->substituer($c['mesure']);
            $action = $this->substituer($c['action']);

            $s .= "\n# --- $cle ---\n";
            if (!empty($c['docker'])) {
                $s .= "if [ \$has_docker -eq 0 ]; then echo \"K|$cle|skip|docker absent\"; else\n";
            }
            $s .= "av=\$( { timeout 60 bash -c " . escapeshellarg($mesure) . " 2>/dev/null; } | tail -1 | num )\n"
                . "echo \"K|$cle|avant|\$av\"\n";

            if (!$simulation) {
                $s .= "out=\$(timeout $to bash -c " . escapeshellarg($action) . " 2>&1); rc=\$?\n"
                    . "echo \"K|$cle|rc|\$rc\"\n"
                    . "echo \"\$out\" | head -3 | while IFS= read -r l; do [ -n \"\$l\" ] && echo \"K|$cle|msg|\$l\"; done\n"
                    . "ap=\$( { timeout 60 bash -c " . escapeshellarg($mesure) . " 2>/dev/null; } | tail -1 | num )\n"
                    . "echo \"K|$cle|apres|\$ap\"\n";
            }
            if (!empty($c['docker'])) $s .= "fi\n";
        }

        $s .= "\necho \"DF|apres|\$(libre)\"\n";
        return $s;
    }

    /**
     * Execute (ou simule) le nettoyage sur une machine.
     *
     * @param string $srv    nom du serveur, pour le journal
     * @param string $target cible SSH (Core::sshTarget())
     * @param int    $port
     * @param array  $cles
     * @param bool   $simulation
     * @return array
     */
    public function executer($srv, $target, $port, $cles, $simulation = true) {
        $res = array('srv' => $srv, 'simulation' => $simulation, 'cles' => array(),
                     'df_avant' => 0, 'df_apres' => 0, 'total' => 0,
                     'erreur' => '', 'injoignable' => false);

        if (empty($cles)) return $res;

        $script = $this->script($cles, $simulation);
        // Le script enchaine ses propres timeouts : une simulation ne fait que
        // mesurer (60 s par cle), une execution agit en plus.
        $global = $simulation
                ? 60 * count($cles) + 60
                : (((int) $this->cfg['timeout']) + 120) * count($cles) + 60;

        // ServerAlive : si la machine se tait en cours de route, on ne reste
        // pas accroche a une session morte jusqu'au timeout global.
        $cmd = 'timeout ' . $global . ' ssh -p ' . (int) $port
             . ' -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=10'
             . ' -o ServerAliveInterval=15 -o ServerAliveCountMax=4 '
             . escapeshellarg($target) . " 'bash -s'";

        $proc = proc_open($cmd, array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $p);
        if (!is_resource($proc)) {
            $res['erreur'] = 'impossible de lancer ssh';
            return $res;
        }
        fwrite($p[0], $script);
        fclose($p[0]);
        $stdout = stream_get_contents($p[1]); fclose($p[1]);
        $stderr = stream_get_contents($p[2]); fclose($p[2]);
        $rc = proc_close($proc);

        if (trim($stdout) === '') {
            $res['injoignable'] = true;
            $res['erreur'] = trim($stderr) !== '' ? trim($stderr) : "aucune reponse (code $rc)";
            $this->log->warning("Nettoyage impossible sur $srv : " . $res['erreur'], $srv);
            return $res;
        }

        foreach (explode("\n", $stdout) as $ligne) {
            $f = explode('|', trim($ligne));
            if ($f[0] === 'DF' && isset($f[2])) {
                $res[$f[1] === 'avant' ? 'df_avant' : 'df_apres'] = (float) $f[2];
            }
            elseif ($f[0] === 'K' && isset($f[3])) {
                $cle = $f[1];
                if (!isset($res['cles'][$cle])) {
                    $res['cles'][$cle] = array('avant' => 0, 'apres' => null, 'rc' => null,
                                               'msg' => array(), 'skip' => '');
                }
                switch ($f[2]) {
                    case 'avant': $res['cles'][$cle]['avant'] = (float) $f[3]; break;
                    case 'apres': $res['cles'][$cle]['apres'] = (float) $f[3]; break;
                    case 'rc':    $res['cles'][$cle]['rc']    = (int) $f[3];   break;
                    case 'skip':  $res['cles'][$cle]['skip']  = $f[3];         break;
                    case 'msg':   $res['cles'][$cle]['msg'][] = implode('|', array_slice($f, 3)); break;
                }
            }
        }

        // Gain retenu : l'espace rendu au systeme de fichiers, pas la somme des
        // estimations. C'est la seule mesure qui ne se trompe pas.
        if ($simulation) {
            $t = 0;
            foreach ($res['cles'] as $c) $t += $c['avant'];
            $res['total'] = $t;
        } else {
            $res['total'] = max(0, $res['df_apres'] - $res['df_avant']);
        }
        return $res;
    }

    /** Formate un nombre d'octets. */
    public static function octets($n) {
        $n = (float) $n;
        if ($n >= 1073741824) return number_format($n / 1073741824, 1, ',', ' ') . ' Go';
        if ($n >= 1048576)    return number_format($n / 1048576, 1, ',', ' ') . ' Mo';
        if ($n >= 1024)       return number_format($n / 1024, 0, ',', ' ') . ' ko';
        return ((int) $n) . ' o';
    }
}
