<?php
/**
 * phpBorgCore  - Core lib to use Borg backup with Php
 */


/**
* Class and Function List:
* Function list:
* - __construct()
* - secondsToTime()
* - generateRandomString()
* - getSrv()
* - getIdSrv()
* - backupParams()
* - myExec()
* - borgExec()
* - pruneArchive()
* - parseLog()
* - startReport()
* - checkRemote()
* - updateRepo()
* - snapMysql()
* - removeLvmSnap()
* - checkRepo()
* - backup()
* - getInput()
* - addDb()
* - addSrv()
* Classes list:
* - Core
*/

namespace phpBorg;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use DirectoryIterator;

/**
 * Class Core
 * @package phpBorg
 */
class Core {
    /**
     * @var \stdClass
     */
    public $params;

    /**
     * @var
     */
    private $parse_err;

    /**
     * Parametres du serveur courant (table servers)
     * @var \stdClass|null
     */
    public $serverParams;

    /**
     * Parametres du repository courant (table repository)
     * @var \stdClass|null
     */
    public $repoParams;

    /**
     * Parametres base de donnees du serveur courant (table db_info)
     * @var \stdClass|null
     */
    public $dbParams;

    /**
     * Dernier retour JSON de "borg info"
     * @var \stdClass|null
     */
    public $logs;

    /**
     * Class constructor
     * @param string $borg_binary_path - path of borg executable binary
     * @param string $borg_backup_path - root path of backup repository
     * @param string $borg_config_path - relative path of repository config file
     * @param string $borg_archive_dir - name of backup repository archive
     * @param string $borg_srv_ip_pub  - Public IP of Backup server
     * @param string $borg_srv_ip_priv - Private IP of backup server
     * @param string $borg_srv_ip_tunnel - Adresse du serveur dans le maillage WireGuard
     */
    public function __construct($borg_binary_path = '/usr/bin/borg', $borg_config_path = 'conf/borg.conf', $borg_srv_ip_pub = '91.200.205.105', $borg_srv_ip_priv = '10.10.70.70', $borg_backup_path = '/data/backups', $borg_archive_dir = 'backup', $borg_lvmsnap_name = 'phpborg', $borg_srv_ip_tunnel = '10.90.0.16') {
        $this->params = new \stdClass;
        $this->params->borg_binary_path  = $borg_binary_path;
        $this->params->borg_config_path  = $borg_config_path;
        $this->params->borg_srv_ip_pub   = $borg_srv_ip_pub;
        $this->params->borg_srv_ip_priv  = $borg_srv_ip_priv;
        $this->params->borg_srv_ip_tunnel = $borg_srv_ip_tunnel;
        $this->params->borg_backup_path  = $borg_backup_path;
        $this->params->borg_archive_dir  = $borg_archive_dir;
        $this->params->borg_lvmsnap_name = $borg_lvmsnap_name;
    }

    /**
     * loadSettings Method (adresses du serveur de sauvegarde, depuis la base)
     *
     * Les adresses etaient ecrites en dur dans le constructeur. Elles vivent
     * desormais dans la table settings, ce qui permet d'en ajouter ou d'en
     * changer sans toucher au code. Les valeurs du constructeur restent en
     * repli : si la table est absente ou vide, le comportement est inchange.
     *
     * @param Db $db
     * @param logWriter|null $log
     * @return void
     */
    public function loadSettings($db, $log = null) {
        $connues = array('borg_srv_ip_priv', 'borg_srv_ip_pub', 'borg_srv_ip_tunnel');

        // sql_err(0) : une table settings absente ne doit pas arreter phpBorg
        $strict = $db->sql_err;
        $db->sql_err = 0;
        $rows = @$db->query("SELECT `key`, `value` FROM settings")->fetchAll();
        $db->sql_err = $strict;

        if (empty($rows)) {
            if ($log) $log->warning("Table settings vide ou absente : adresses par defaut du code");
            return;
        }
        foreach ($rows as $r) {
            $k = isset($r['key']) ? $r['key'] : null;
            $v = isset($r['value']) ? trim($r['value']) : '';
            if ($k !== null && $v !== '' && in_array($k, $connues, true)) {
                $this->params->$k = $v;
            }
        }
    }

    /**
     * callbackAddress Method (adresse que la machine sauvegardee utilise pour
     * rappeler le serveur de sauvegarde)
     *
     * Ordre de priorite :
     *   1. servers.callback_ip, si renseignee : elle prime sur tout
     *   2. le mode declare dans servers.backuptype
     *   3. le reseau prive, comme avant
     *
     * @param object $srvRow Ligne de la table servers
     * @return string
     */
    public function callbackAddress($srvRow) {
        if (!empty($srvRow->callback_ip)) return trim($srvRow->callback_ip);

        $mode = isset($srvRow->backuptype) ? $srvRow->backuptype : '';
        switch ($mode) {
            case 'external': return $this->params->borg_srv_ip_pub;
            case 'tunnel':   return $this->params->borg_srv_ip_tunnel;
            case 'internal': return $this->params->borg_srv_ip_priv;
        }
        return $this->params->borg_srv_ip_priv;
    }

    /**
     * sshTarget Method (adresse utilisee pour JOINDRE la machine sauvegardee)
     *
     * servers.host porte deux roles : l'adresse a joindre, mais aussi le nom
     * du compte Unix proprietaire du depot sur le serveur de sauvegarde
     * (chown, et le "user@" du rappel). On ne peut donc pas y ecrire une IP
     * sans casser le second role.
     *
     * servers.ssh_host, quand elle est renseignee, ne porte que le premier :
     * c'est ce qui permet de joindre une machine par son adresse de maillage
     * sans toucher a /etc/hosts ni au compte.
     *
     * @return string
     */
    public function sshTarget() {
        if (!empty($this->serverParams->ssh_host)) return trim($this->serverParams->ssh_host);
        return $this->serverParams->host;
    }

    /**
     * serverAddresses Method (toutes les adresses connues du serveur de
     * sauvegarde, pour le nettoyage des known_hosts distants)
     *
     * @return array
     */
    public function serverAddresses() {
        $l = array();
        foreach (array('borg_srv_ip_priv', 'borg_srv_ip_pub', 'borg_srv_ip_tunnel') as $k) {
            if (!empty($this->params->$k)) $l[] = $this->params->$k;
        }
        return array_values(array_unique($l));
    }

    /**
     * secondToTime Method (to get good formated time)
     * @param int $inputSeconds
     * @return string
     */
    private function secondsToTime($inputSeconds) {
        // borg renvoie une duree flottante : l'arrondir evite le
        // "Deprecated: Implicit conversion from float to int" sur les modulos
        $inputSeconds = (int) round((float) $inputSeconds);
        $secondsInAMinute = 60;
        $secondsInAnHour  = 60 * $secondsInAMinute;
        $secondsInADay    = 24 * $secondsInAnHour;

        // Extract days
        $days = floor($inputSeconds / $secondsInADay);

        // Extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours = floor($hourSeconds / $secondsInAnHour);

        // Extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes = floor($minuteSeconds / $secondsInAMinute);

        // Extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds = ceil($remainingSeconds);

        // Format and return
        $timeParts = [];
        $sections = ['d' => (int)$days, 'h' => (int)$hours, 'm' => (int)$minutes, 's' => (int)$seconds, ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $timeParts[] = $value . '' . $name . ($value == 1 ? '' : '');
            }
        }

        return implode('', $timeParts);
    }
    /**
     * generateRandomString Method (to make private passphrase)
     * @param int $length
     * @return string
     */

    private function generateRandomString($length = 64) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.=';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0;$i < $length;$i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1) ];
        }
        return $randomString;
    }

    public function generateCertificate($srv) {
	    $certificateData = array(
		    "countryName" => "US",
		    "countryName" => "US",
		    "localityName" => "Houston",
		    "organizationName" => "DevDungeon.com",
		    "organizationalUnitName" => "Development",
		    "commonName" => "DevDungeon",
		    "emailAddress" => "nanodano@devdungeon.com"
	    );
	    // Generate certificate
	    $privateKey = openssl_pkey_new();
	    $certificate = openssl_csr_new($certificateData, $privateKey);
	    $certificate = openssl_csr_sign($certificate, null, $privateKey, 999 );
	    
	    // Generate PEM file
	    $pem_passphrase = 'abracadabra'; // empty for no passphrase
	    $pem = array();
	    openssl_x509_export($certificate, $pem[0]);
	    openssl_pkey_export($privateKey, $pem[1], $pem_passphrase);
	    $pem = implode($pem);
	    
	    // Save PEM file
	    $pemfile = './server.pem';
	    file_put_contents($pemfile, $pem);
    }

    /**
     * getSrv Method (Get all servers name in db)
     * @param Db $db
     * @return array
     */
    public function getSrv($db) {
        $srv = array();
        foreach ($db->query("SELECT name,id from servers WHERE active = 1")->fetchAll() as $listsrv) {
            $srv[] = ['name' => $listsrv['name'], 'type' => 'backup', 'id' => $listsrv['id']];
            // active = 0 permet de suspendre la sauvegarde base d'un serveur
            // sans toucher a sa sauvegarde fichiers
            if (!empty($db->query("SELECT id from db_info WHERE server_id='" . $listsrv['id'] . "' AND active = 1")->fetchArray() ['id'])) $srv[] = ['name' => $listsrv['name'], 'type' => 'mysql', 'id' => $listsrv['id']];
        }
        return $srv;

    }

    /**
     * getIdSrv (Return id of server by name)
     * @param string $srv 
     * @param Db $db
     * @return array
     */
    public function getIdSrv($srv, $db) {
        return $db->query("SELECT name,id from servers WHERE active = 1 AND name='" . $srv . "'")->fetchArray() ['id'];

    }

    /**
     * parseConfig Method (Parse config file from repository)
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|int
     */
    public function backupParams($srv, $type, $db, $log) {
        $checkrepo = (object)$db->query("SELECT * from servers WHERE name='" . $srv . "'")->fetchArray();
        if ($checkrepo->id) {
            $this->serverParams = new \stdClass;
            $this->repoParams   = new \stdClass;
            if ($type == "mysql" || $type == "postgres") {
                $this->dbParams = new \stdClass;
                if (!$this->dbParams = (object)$db->query("SELECT * from db_info WHERE server_id='" . $checkrepo->id . "' AND type='" . $type . "'")->fetchArray()) {
                    $log->error("Error, server does not have config for $type", $srv);
                    $this->serverParams=$this->repoParams=$this->dbParams = null;
                    return false;
                }
            }
            $this->serverParams = $checkrepo;
            if ($this->repoParams = (object)$db->query("SELECT * from repository WHERE server_id='" . $checkrepo->id . "' AND type='" . $type . "'")->fetchArray()) {
                if (empty($this->repoParams->id)) return false;
                $exclude = $backup_path = "";
                $back = explode(',', $this->repoParams->backup_path);
                foreach ($back as $yy) {
                    $backup_path .= trim($yy) . " ";
                }
                $this->repoParams->backup_path = $backup_path;
                $this->repoParams->passphrase = base64_decode($this->repoParams->passphrase);
                if ($this->repoParams->exclude) {
                    $ex = explode(',', $this->repoParams->exclude);
                    foreach ($ex as $zz) {
                        $exclude .= "--exclude " . trim($zz) . " ";
                    }

                    $this->repoParams->exclude = $exclude;
                }
                else $this->repoParams->exclude = null;

                // Le mode est memorise avant d'etre remplace par une adresse :
                // le repli du mode tunnel doit savoir d'ou il part, et le
                // journal doit pouvoir nommer le mode plutot qu'une IP.
                $this->serverParams->backupmode = !empty($this->serverParams->backuptype)
                                                ? $this->serverParams->backuptype : 'internal';
                $this->serverParams->backuptype = $this->callbackAddress($this->serverParams);
                // Trace du repli eventuel, relue par backup() pour le rapport
                $this->serverParams->fallback_note = '';
                return $this->serverParams;
            }
            else {
                $log->error("Error, server does not exist or config file is incorect", $srv);
		$this->serverParams=$this->repoParams=$this->dbParams = null;
                return false;
            }
        }
    }

    /**
     * myExec Method (run program from shell with return management)
     * @param $cmd
     * @param string $input
     * @return array
     */
    private function myExec($cmd, $input = '') {
        $proc = proc_open($cmd, array(
            0 => array(
                'pipe',
                'r'
            ) ,
            1 => array(
                'pipe',
                'w'
            ) ,
            2 => array(
                'pipe',
                'w'
            )
        ) , $pipes);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rtn = proc_close($proc);
        return array(
            'stdout' => $stdout,
            'stderr' => $stderr,
            'return' => $rtn
        );
    }
    /**
     * isTransientError Method (erreur reseau/SSH passagere, qui merite un reessai)
     *
     * Le serveur de sauvegarde est expose sur Internet et subit du brute-force
     * SSH : quand MaxStartups sature, sshd rejette les connexions legitimes de
     * borg. Ces echecs sont passagers et ne doivent pas perdre une sauvegarde.
     *
     * @param array $e Retour de myExec()
     * @return bool
     */
    private function isTransientError($e) {
        $out = strtolower(($e['stdout'] ?? '') . ' ' . ($e['stderr'] ?? ''));

        $patterns = array(
            'ssh_exchange_identification',
            'kex_exchange_identification',
            'connection closed by remote host',
            'connection reset by peer',
            'connection timed out',
            'connection refused',
            'broken pipe',
            'timed out waiting for',
            'is borg working on the server',
            'temporary failure in name resolution',
        );
        foreach ($patterns as $needle) {
            if (strpos($out, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * myExecRetry Method (execute une commande en reessayant sur erreur passagere)
     *
     * @param string $cmd
     * @param string $srv
     * @param logWriter $log
     * @param string $label Libelle affiche dans le journal
     * @param array $okCodes Codes de retour consideres comme un succes
     * @return array Retour de myExec()
     */
    private function myExecRetry($cmd, $srv, $log, $label, $okCodes = array(0), $input = '') {
        $cfgRetry = Config::get('backup', 'retries', 3);
        $cfgDelay = Config::get('backup', 'retry_delay', 30);

        $tries = max(1, (int)$cfgRetry);
        $delay = max(1, (int)$cfgDelay);

        for ($i = 1; $i <= $tries; $i++) {
            $e = $this->myExec($cmd, $input);

            if (in_array((int)$e['return'], $okCodes, true)) return $e;
            if (!$this->isTransientError($e))                return $e;
            if ($i >= $tries)                                return $e;

            // Backoff progressif : 30s, 60s, 90s...
            $wait = $delay * $i;
            $log->warning("$label : echec passager (tentative $i/$tries), "
                        . "nouvelle tentative dans {$wait}s", $srv);
            sleep($wait);
        }

        return $e;
    }

    /**
     * secretCmd Method (commande shell recevant ses secrets sur stdin)
     *
     * Jusqu'ici les commandes etaient construites avec
     *     export BORG_PASSPHRASE='<passphrase>'; borg ...
     * ce qui inscrivait la passphrase dans la ligne de commande : n'importe
     * quel utilisateur de la machine pouvait la lire dans un simple "ps", sur
     * le serveur de sauvegarde comme sur chaque machine sauvegardee.
     *
     * Les secrets sont desormais ecrits sur l'entree standard du shell, qui
     * les lit avec "read". Ils n'apparaissent plus dans argv ; ils restent
     * dans l'environnement du processus, donc dans /proc/<pid>/environ, mais
     * celui-ci n'est lisible que par root et le proprietaire, alors que "ps"
     * l'est par tout le monde.
     *
     * @param array $vars Noms des variables, dans l'ordre d'envoi sur stdin
     * @param string $suite Commande a executer une fois les variables posees
     * @return string
     */
    private function secretCmd($vars, $suite) {
        $lect = '';
        foreach ($vars as $v) {
            // IFS vide et -r : ni troncature d'espaces, ni interpretation des \
            $lect .= 'IFS= read -r ' . $v . '; export ' . $v . '; ';
        }
        return $lect . $suite;
    }

    /**
     * secretInput Method (valeurs a envoyer sur stdin, une par ligne)
     *
     * @param array $valeurs
     * @return string
     */
    private function secretInput($valeurs) {
        return implode("\n", $valeurs) . "\n";
    }

    /**
     * localBorg Method (commande borg locale, passphrase hors ligne de commande)
     *
     * @param string $args Arguments passes a borg
     * @return array [commande, entree standard]
     */
    private function localBorg($args) {
        return array(
            'sh -c ' . escapeshellarg($this->secretCmd(
                array('BORG_PASSPHRASE'),
                'exec ' . $this->params->borg_binary_path . ' ' . $args
            )),
            $this->secretInput(array($this->repoParams->passphrase)),
        );
    }

    /**
     * borgExec Method (execute borg with arguments)
     * @param string $verb
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|void
     */
    public function borgExec($verb, $arg, $srv, $type, $db, $log) {
        if (!$this->backupParams($srv, $type, $db, $log)) {
            echo "Error, repository config for '$srv' does not exist";
            $log->error("Error, repository config does not exist", $srv);
            return;
        }
        list($cmd, $in) = $this->localBorg("$verb " . $arg);
        $e = $this->myExec($cmd, $in);
        print $e['stdout'];
        // borg utilise le code 1 pour de simples avertissements
        if ($e['return'] != 0 && $e['return'] != 1) {
            $log->error("borg $verb a echoue (code " . $e['return'] . "): " . trim($e['stderr']), $srv);
            fwrite(STDERR, "Erreur borg $verb : " . trim($e['stderr']) . "\n");
            return false;
        }
        return true;
    }

    /**
     * pruneArchive Method (archive retention management)
     * @param int $keepday
     * @param string $srv
     * @param string $type
     * @param Db $db
     * @param logWriter $log
     * @return array|bool|void
     */
    public function pruneArchive($keepday, $srv, $type, $db, $log) {
        $log->info("Checking $type retention rules", $srv);
        $deleted = NULL;
        $keepday = $keepday - 1;
        $log->info("Retention policy : keep $keepday  per days, ", $srv);
        list($cmd, $in) = $this->localBorg("prune --save-space --force --list --keep-daily=$keepday --keep-weekly=4 --keep-monthly=6 " . $this->repoParams->repo_path);
        $e = $this->myExec($cmd, $in);
        if ($e['return'] == 0) {
            $separator = "\r\n";
            $line = strtok($e['stderr'], $separator);
            while ($line !== false) {
                if (preg_match('/Pruning archive/', $line)) {
                    $id = substr(stristr($line, '[') , 1, strpos($line, ']') - strlen($line));
                    $name = explode(" ", $line) [2];
                    $log->info("removing backup $name", $srv);
                    $db->query("DELETE from archives WHERE archive_id='$id'");
                    if ($db->sql_error()) $log->error("Unable to delete archive in DB: " . $db->sql_error() , $srv);
                }
                $line = strtok($separator);
            }
            return;
        }
        else {
            $log->error("PRUNE ERROR=>\nSTDOUT:\n$e[stdout]\n\nSTDERR:\n$e[stderr]", $srv);
            return 1;
        }
    }

    /**
     * parseLog Method (read output of Borg to get info)
     * @param string $srv
     * @param string $file
     * @param logWriter $log
     * @return array
     */
    public function parseLog($srv, $file, $log) {
        $log->info("Parsing log to extract info", $srv);
        list($cmd, $in) = $this->localBorg("info $file --json");
        $e = $this->myExec($cmd, $in);
        $json = $e['stdout'];
        if ($e['return'] == 0) {
            $this->logs = new \stdClass;
            $this->logs = (object)json_decode($json);

            if (isset($this->logs->archives)) foreach ($this->logs->archives as $archives) {
                $this->logs->archives = new \stdClass;
                $this->logs->archives = $archives;
            }
            return $this->logs;
        }
        else {
            $this->logs = null;
            return array(
                'error' => 1,
                'stderr' => $e['stderr'],
                'stdout' => $e['stdout']
            );
        }

    }

    /**
     * syncArchives Method - Synchronize borg archives with MySQL database
     * Lists archives from borg repo and inserts missing ones into the archives table
     * @param string $srv Server name
     * @param string $type backup or mysql
     * @param Db $db Database instance
     * @param LogWriter $log Logger
     * @return int Number of archives synced
     */
    public function syncArchives($srv, $type, $db, $log) {
        $log->info("Syncing archives for $srv ($type)", $srv);

        if (!$this->backupParams($srv, $type, $db, $log)) {
            $log->error("Cannot load repo config for $srv ($type)", $srv);
            return 0;
        }

        $repoPath = $this->repoParams->repo_path;
        $repoId = $this->repoParams->repo_id;

        // List archives from borg
        list($cmd, $in) = $this->localBorg("list $repoPath --json");
        $e = $this->myExec($cmd, $in);

        if ($e['return'] != 0) {
            $log->error("borg list failed: " . $e['stderr'], $srv);
            return 0;
        }

        $data = json_decode($e['stdout']);
        if (!$data || !isset($data->archives)) {
            $log->error("Cannot parse borg list output", $srv);
            return 0;
        }

        // Get existing archives from MySQL
        $existing = [];
        $rows = $db->query("SELECT nom FROM archives WHERE repo_id = ?", $repoId)->fetchAll();
        foreach ($rows as $row) {
            $existing[$row['nom']] = true;
        }

        // Build set of borg archive names
        $borgArchives = [];
        foreach ($data->archives as $archive) {
            $name = $archive->name ?? $archive->archive ?? '';
            if ($name) $borgArchives[$name] = true;
        }

        // Delete MySQL archives that no longer exist in borg
        $deleted = 0;
        foreach ($existing as $name => $v) {
            if (!isset($borgArchives[$name])) {
                $db->query("DELETE FROM archives WHERE repo_id = ? AND nom = ?", $repoId, $name);
                if (!$db->sql_error()) {
                    $deleted++;
                    $log->info("Removed orphan archive: $name", $srv);
                }
            }
        }
        if ($deleted > 0) {
            $log->info("$deleted orphan archives removed", $srv);
        }

        // Insert missing archives into MySQL
        $synced = 0;
        foreach ($borgArchives as $name => $v) {
            if (isset($existing[$name])) continue;

            // Get detailed info for this archive
            list($cmd, $in) = $this->localBorg("info $repoPath::$name --json");
            $info = $this->myExec($cmd, $in);

            if ($info['return'] != 0 && $info['return'] != 1) continue;

            $archiveInfo = json_decode($info['stdout']);
            if (!$archiveInfo || !isset($archiveInfo->archives[0])) continue;

            $a = $archiveInfo->archives[0];
            $stats = $a->stats ?? (object)[];

            $db->query("INSERT IGNORE INTO archives
                (`id`, `repo_id`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $repoId,
                $name,
                $a->id ?? '',
                $a->duration ?? 0,
                $a->start ?? null,
                $a->end ?? null,
                $stats->compressed_size ?? 0,
                $stats->deduplicated_size ?? 0,
                $stats->original_size ?? 0,
                $stats->nfiles ?? 0
            );

            if (!$db->sql_error()) {
                $synced++;
                $log->info("Synced archive: $name", $srv);
            }
        }

        // Update repo stats
        $this->updateRepo((object)['repo' => $repoPath, 'host' => $srv], $db, $log);

        $log->info("Sync complete: $synced new archives imported", $srv);
        return $synced;
    }

    /**
     * startReport Method (Create line entry for task backup and return sql logId)
     * @param Db $db
     * @param int $server_id
     * @param string $type
     * @return int
     */
    public function startReport($db, $server_id, $type) {
        $db->query("INSERT INTO `report` (`server_id`,`type`,`start`) VALUES ('$server_id','$type',Now())");
        return $db->insertId();
    }

    /**
     * checkRemote Method (Create line entry for task backup and return sql logId)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function checkRemote($srv, $log) {
        $log->info("Checking back ssh connexion", $srv);

        $mode = isset($this->serverParams->backupmode) ? $this->serverParams->backupmode : '';

        $e = $this->testCallback($srv, $log, $this->serverParams->backuptype);
        if ($e['return'] == 0) return 1;

        // Repli : le maillage WireGuard est recent, on ne lui confie pas les
        // sauvegardes sans filet. Uniquement depuis le tunnel vers l'adresse
        // publique : jamais l'inverse, le repli va du plus fragile vers le
        // plus sur. Une adresse de rappel explicite (callback_ip) est un choix
        // delibere de l'administrateur et n'est pas contournee.
        $repli = $this->params->borg_srv_ip_pub;
        if ($mode === 'tunnel' && empty($this->serverParams->callback_ip)
            && !empty($repli) && $repli !== $this->serverParams->backuptype) {

            $log->warning("Rappel par le tunnel (" . $this->serverParams->backuptype
                        . ") indisponible, tentative sur l'adresse publique ($repli)", $srv);

            $e2 = $this->testCallback($srv, $log, $repli);
            if ($e2['return'] == 0) {
                $note = "Tunnel indisponible : sauvegarde repliee sur $repli\n";
                $log->warning(trim($note), $srv);
                $this->serverParams->backuptype    = $repli;
                $this->serverParams->fallback_note = $note;
                return 1;
            }
            $log->error("Repli sur l'adresse publique egalement indisponible", $srv);
        }

        $log->error("Back ssh connexion error Return code ($e[return])\n $e[stderr]\n $e[stdout]", $srv);
        return;
    }

    /**
     * testCallback Method (la machine sauvegardee peut-elle rappeler le
     * serveur de sauvegarde a cette adresse ?)
     *
     * @param string $srv
     * @param logWriter $log
     * @param string $adresse
     * @return array Retour de myExec()
     */
    private function testCallback($srv, $log, $adresse) {
        $cmd = "ssh -p " . $this->serverParams->port
             . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=10' -o 'StrictHostKeyChecking=no' "
             . $this->sshTarget()
             . " \"ssh -q -o 'BatchMode=yes' -o 'ConnectTimeout=10' -o 'StrictHostKeyChecking=no' "
             . $this->serverParams->host . "@" . $adresse . " 'echo 2>&1'\"";

        return $this->myExecRetry($cmd, $srv, $log, 'Verification SSH (' . $adresse . ')');
    }

    /**
     * updateRepo Method (update MySQL repository statistic )
     * @param object $config
     * @param Db $db
     * @param logWriter $log
     * @return array|object
     */

    public function updateRepo($config, $db, $log) {
        $srv  = $config->host;
        $info = $this->parseLog($srv, $config->repo, $log);
        if (!is_object($info) || !isset($info->cache->stats)) {
            $log->error("Impossible de lire les statistiques du repository", $srv);
            return;
        }
        // repo_id absent (appel depuis syncArchives) : le retrouver par chemin
        if (empty($config->repo_id)) {
            if (!empty($this->repoParams->repo_id)) {
                $config->repo_id = $this->repoParams->repo_id;
            } else {
                $log->error("repo_id inconnu, mise a jour du repository ignoree", $srv);
                return;
            }
        }
        $log->info("Updating repository informations", $srv);
        $db->query("UPDATE IGNORE repository
                                SET
                                   `size`      = '" . $info->cache->stats->total_size . "',
                                   `dsize`     = '" . $info->cache->stats->unique_csize . "',
                                   `csize`     = '" . $info->cache->stats->total_csize . "',
                                   `ttuchunks` = '" . $info->cache->stats->total_unique_chunks . "',
                                   `ttchunks`  = '" . $info->cache->stats->total_chunks . "',
                                   `modified`  = NOW()
                                WHERE
                                   `repo_id`   = '" . $config->repo_id . "'
                          ");
        if ($db->sql_error()) {
            $err = "PARSELOG ERROR=>SQL:\n" . $db->sql_error();
            $log->error($err, $srv);
        }
    }

    /**
     * snapMysql Method (Create LVM snapshot for MySQL atomic backup)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function snapMysql($srv, $log) {
        $log->info("Starting DB Backup", $srv);
        $log->info("Sync MySQL database and create LVM snapshot", $srv);
        $e = $this->myExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->sshTarget() . " \"
                        a=( \`mount\` );[[ \\\${a[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && umount -fl /" . $this->params->borg_lvmsnap_name . " 1>&2
                        b=( \`lvs\` )  ;[[ \\\${b[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && lvremove -f /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " 1>&2
                        mysql -u" . $this->dbParams->db_user . " -p" . $this->dbParams->db_pass . " -h " . $this->dbParams->db_host . " -e 'flush tables with read lock;
                                system lvcreate -s /dev/" . $this->dbParams->vg_name . "/" . $this->dbParams->lvm_part . " -n " . $this->params->borg_lvmsnap_name . " -L" . $this->dbParams->lvsize . " 1>&2;
                                unlock tables;'
                        [[ ! -d /" . $this->params->borg_lvmsnap_name . " ]] && mkdir -p /" . $this->params->borg_lvmsnap_name . " 1>&2;mount /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " /" . $this->params->borg_lvmsnap_name . " 1>&2\"");
        if ($e['return'] == '0') {
            $log->info("LVM snapshot created", $srv);
            return 1;
        }
        else {
            $log->error("LVM snapshot ERROR:", $srv);
            $log->error("STDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'], $srv);
            return 0;
        }

    }

    /**
     * dumpOptions Method (options mysqldump effectives)
     *
     * Par defaut le dump ne pose AUCUN verrou :
     *   --single-transaction  isole le dump dans une transaction ; les moteurs
     *                         transactionnels (InnoDB) restent coherents sans
     *                         bloquer les ecritures
     *   --skip-lock-tables    supprime le LOCK TABLES que mysqldump poserait
     *                         sinon sur chaque base
     *
     * Limite a connaitre : --single-transaction ne couvre pas les tables non
     * transactionnelles (MyISAM, Aria). Si de telles tables sont ecrites
     * pendant le dump, leur contenu peut etre incoherent. C'est le prix d'une
     * sauvegarde sans verrou ; dump_opts permet de changer ce compromis.
     *
     * @return string
     */
    private function dumpOptions() {
        $opts = isset($this->dbParams->dump_opts) ? trim($this->dbParams->dump_opts) : '';
        if ($opts !== '') return $opts;

        return '--single-transaction --skip-lock-tables --quick --hex-blob '
             . '--routines --triggers --events --default-character-set=utf8mb4 '
             . '--all-databases';
    }

    /**
     * buildDumpCommand Method (mysqldump redirige dans borg via stdin)
     *
     * Le dump ne touche jamais le disque de la machine sauvegardee : il part
     * directement dans borg par un tube. Cela evite d'avoir besoin d'espace
     * libre, et c'est la seule option quand la machine n'a pas de LVM.
     *
     * @param string $archivename
     * @param logWriter $log
     * @param string $srv
     * @return string
     */
    private function buildDumpCommand($archivename, $log, $srv) {
        $port = $this->serverParams->port;
        $host = $this->serverParams->host;   // compte Unix sur le serveur de sauvegarde
        $cible = $this->sshTarget();         // adresse a joindre
        $dest = "ssh://" . $host . "@" . $this->serverParams->backuptype
              . $this->repoParams->repo_path . "::" . $archivename;

        // MYSQL_PWD plutot que -p en ligne de commande : le mot de passe
        // n'apparait pas dans le ps de la machine sauvegardee.
        // borg memorise l'emplacement du depot et refuse un changement d'URL
        // sans confirmation interactive. Or changer de mode de rappel change
        // l'URL : en BatchMode la sauvegarde echouerait des la bascule. Les
        // adresses viennent de notre propre base et l'authenticite du serveur
        // reste garantie par la cle d'hote SSH.
        $inner = "set -o pipefail; export BORG_RELOCATED_REPO_ACCESS_IS_OK=yes; "
               . $this->secretCmd(array('BORG_PASSPHRASE', 'MYSQL_PWD'), '')
               . "mysqldump -u" . escapeshellarg($this->dbParams->db_user)
               . " -h " . escapeshellarg($this->dbParams->db_host) . " "
               . $this->dumpOptions() . " | "
               . $this->params->borg_binary_path . " create --lock-wait 600 "
               . "--compression " . $this->repoParams->compression . " "
               . "--stdin-name dump.sql " . escapeshellarg($dest) . " -";

        $log->info("Dump sans verrou : " . $this->dumpOptions(), $srv);

        // Pas de -tt : un TTY altererait le flux transmis a borg, et empecherait
        // la lecture propre des secrets sur l'entree standard.
        // bash -c pour disposer de pipefail, que sh ne garantit pas.
        $cmd = "ssh -p " . $port . " -o 'BatchMode=yes' -o 'ConnectTimeout=10' "
             . escapeshellarg($cible) . " " . escapeshellarg("bash -c " . escapeshellarg($inner));

        return array($cmd, $this->secretInput(array(
            $this->repoParams->passphrase,
            $this->dbParams->db_pass,
        )));
    }

    /**
     * removeLvmSnap Method (Remove LVM snapshot)
     * @param string $srv
     * @param logWriter $log
     * @return bool
     */
    public function removeLvmSnap($srv, $log) {
        $log->info("Removing LVM snapshot", $srv);
        $e = $this->myExec("ssh -p " . $this->serverParams->port . " -tt -o 'BatchMode=yes' -o 'ConnectTimeout=5' " . $this->sshTarget() . " \"
                        a=( \`mount\` );[[ \\\${a[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && umount -fl /" . $this->params->borg_lvmsnap_name . " 1>&2
                        b=( \`lvs\` )  ;[[ \\\${b[*]} =~ " . $this->params->borg_lvmsnap_name . " ]] && lvremove -f /dev/" . $this->dbParams->vg_name . "/" . $this->params->borg_lvmsnap_name . " 1>&2\"");

        if ($e['return'] == '0') {
            $log->info("LVM snapshot removed", $srv);
        }
        else {
            $log->error("LVM snapshot remove ERROR:", $srv);
            $log->error("STDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'], $srv);
        }
        return;
    }

    /**
     * checkRepo Method (Check if repository exist and create if not)
     * @param string $srv
     * @param logWriter $log
     * @param Db $db
     * @param object $cfg
     * @return bool
     */
    public function checkRepo($srv, $log, $db, $cfg) {
        $log->info("Check $cfg->type repository", $srv);
        if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type)) {
            echo "Creating $cfg->type repository\n";
            mkdir($this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type);
            $passphrase = $this->generateRandomString();
            $exec = $this->myExec(
                'sh -c ' . escapeshellarg($this->secretCmd(array('BORG_PASSPHRASE'),
                    'cd ' . escapeshellarg($this->params->borg_backup_path . '/' . $srv) . '; exec '
                  . $this->params->borg_binary_path . ' init ' . escapeshellarg($cfg->type)
                  . ' -e ' . escapeshellarg($cfg->encryption))),
                $this->secretInput(array($passphrase)));
            if ($exec['return'] == 0) {
                $repoconfig = new \StdClass;
                $repoconfig = (object)parse_ini_file($this->params->borg_backup_path . "/$srv/$cfg->type/config");
                echo "$cfg->type repository created\n";
                $db->query("INSERT IGNORE INTO repository
	        		(`server_id`,`repo_id`,`type`,`retention`,`encryption`,`passphrase`,`repo_path`,`compression`, `ratelimit`, `backup_path`, `exclude`, `modified`)
                            VALUES
                           	('" . $cfg->server_id . "',
                                 '" . $repoconfig->id . "',
                                 '" . $cfg->type . "',
                                 '" . $cfg->keep . "',
                                 '" . $cfg->encryption . "',TO_BASE64('" . $passphrase . "'),
                                 '" . $this->params->borg_backup_path . '/' . $srv . '/' . $cfg->type . "',
                                 '" . $cfg->compression . "',
                                 '" . $cfg->ratelimit . "',
                                 '" . $cfg->mysql_path . "',
                                 '" . $cfg->exclude . "',
                                 NOW()
                                )
			   ");
                if ($cfg->type == "mysql" || $cfg->type == "postgres") {
                    $db->query("UPDATE db_info set repo_id='" . $repoconfig->id . "' WHERE server_id='" . $cfg->server_id . "' AND type='" . $cfg->type . "'");
                }
            }
            else {
                echo "Error When creating $cfg->type DB repository:\n" . $exec['stdout'] . "\n" . $exec['stderr'];
                return 1;
            }

        }
        else {
            echo "$cfg->type DB Repository already exist";
            return;

        }
    }

    /**
     * backup Method (run backup for specified server)
     * @param $srv
     * @param logWriter $log
     * @param Db $db
     * @param int $reportId
     * @param string $type
     * @return array|object
     */

    public function backup($srv, $log, $db, $reportId, $type = 'backup') {
        $backuperror = 0;
        $tmplog      = '';
        $info        = null;
        $log->info("Starting backup:  $srv ($type)", $srv);
        if (!$this->backupParams($srv, $type, $db, $log)) {
            $log->error("Error, repository config does not exist", $srv);
            $tmplog = "Error,$srv $type repository config does not exist\n";
            $db->query("UPDATE IGNORE report set `error`='1', `log` = ?, `end` = NOW() WHERE id = ?", $tmplog, (int)$reportId);
            return (object)['error' => 1, 'log' => $tmplog, 'osize' => 0, 'csize' => 0,
                            'dsize' => 0, 'dur' => 0, 'nbarchive' => 0, 'nfiles' => 0];
        }
        else {
            if ($this->checkRemote($srv, $log)) {
                $db->query("UPDATE IGNORE report  set `curpos`= '" . $this->serverParams->host . "' WHERE id=" . $reportId);
                $this->pruneArchive($this->repoParams->retention, $srv, $type, $db, $log);
                $archivename = $type . "_" . date("Y-m-d_H:i:s");
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repoParams->repo_path));
                foreach ($iterator as $item) {
                    chmod($item, 0700);
                    chgrp($item, $this->serverParams->host);
                    chown($item, $this->serverParams->host);
                }
                $_type = $snap_path = null;

                // Deux facons de sauvegarder une base :
                //   lvm  : snapshot LVM puis sauvegarde des fichiers (defaut)
                //   dump : mysqldump envoye directement dans borg via stdin
                // Le mode dump est le seul possible quand la machine n'a pas de
                // LVM, ou quand le VG n'a plus la place d'accueillir un snapshot.
                $dumpMode = ($type == "mysql")
                          && isset($this->dbParams->method)
                          && $this->dbParams->method === 'dump';

                if ($type == "mysql" && !$dumpMode) {
                    if (!$this->snapMysql($srv, $log)) {
                        $tmplog = "$srv => Echec du snapshot LVM, sauvegarde MySQL abandonnee\n";
                        $db->query("UPDATE IGNORE report set `error`='1', `log` = ?, `end` = NOW() WHERE id = ?", $tmplog, (int)$reportId);
                        return (object)['error' => 1, 'log' => $tmplog, 'osize' => 0, 'csize' => 0,
                                        'dsize' => 0, 'dur' => 0, 'nbarchive' => 0, 'nfiles' => 0];
                    }
                    $snap_path = "/" . $this->params->borg_lvmsnap_name;
                }
                if ($type != "backup") $_type = $type;
                else $_type = "data";

                $log->info("Running $_type Backup" . ($dumpMode ? " (mysqldump)" : "") . " ...", $srv);
                $tmplog = $backuperror = '';

                $stdin = '';
                if ($dumpMode) {
                    list($cmd, $stdin) = $this->buildDumpCommand($archivename, $log, $srv);
                } else {
                    // --lock-wait : si une connexion precedente a laisse un verrou,
                    // attendre sa liberation plutot que d'echouer immediatement.
                    // Pas de -tt : le TTY empecherait le shell distant de lire la
                    // passphrase sur l'entree standard.
                    // Voir buildDumpCommand() : un changement de mode de rappel
                    // change l'URL du depot, que borg refuse sans confirmation.
                    $distant = 'export BORG_RELOCATED_REPO_ACCESS_IS_OK=yes; '
                             . $this->secretCmd(array('BORG_PASSPHRASE'),
                        'exec ' . $this->params->borg_binary_path . ' create --lock-wait 600'
                      . ' --compression ' . $this->repoParams->compression . ' '
                      . $this->repoParams->exclude
                      . ' ssh://' . $this->serverParams->host . '@' . $this->serverParams->backuptype
                      . $this->repoParams->repo_path . '::' . $archivename . ' '
                      . $snap_path . $this->repoParams->backup_path);

                    $cmd = "ssh -p " . $this->serverParams->port
                         . " -o 'BatchMode=yes' -o 'ConnectTimeout=10' "
                         . escapeshellarg($this->sshTarget()) . ' '
                         . escapeshellarg('sh -c ' . escapeshellarg($distant));
                    $stdin = $this->secretInput(array($this->repoParams->passphrase));
                }
                $e = $this->myExecRetry($cmd, $srv, $log, 'Sauvegarde borg', array(0, 1), $stdin);
		// NE PAS journaliser la commande : elle contient la passphrase du repository
                if ($e['return'] == '0' || $e['return'] == '1') {
                    if ($type == "mysql" && !$dumpMode) $this->removeLvmSnap($srv, $log);
                    $info = $this->parseLog($srv, $this->repoParams->repo_path . "::$archivename", $log);
                    if (is_object($info)) {
                        $durx = $this->secondsToTime($info->archives->duration);
                        $log->info("Backup completed in $durx", $srv);
                        $db->query("INSERT IGNORE INTO archives
                                  	(`id`, `repo_id`, `nom`, `archive_id`, `dur`, `start`, `end`, `csize`, `dsize`, `osize`, `nfiles`)
                                    VALUE
                                        ( NULL,
                                          '" . $this->repoParams->repo_id . "',
                                          '" . $archivename . "',
                                          '" . $info->archives->id . "',
                                          '" . $info->archives->duration . "',
                                          '" . $info->archives->start . "',
                                          '" . $info->archives->end . "',
                                          '" . $info->archives->stats->compressed_size . "',
                                          '" . $info->archives->stats->deduplicated_size . "',
                                          '" . $info->archives->stats->original_size . "',
                                          '" . $info->archives->stats->nfiles . "'
                                         )
                                    ");
                        if ($db->sql_error()) {
                            $err = $srv . "=>\nPARSELOG ERROR=>SQL:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }
                        $db->query("UPDATE IGNORE repository
                                    SET
                                    	`size`      = '" . $info->cache->stats->total_size . "',
                                        `dsize`     = '" . $info->cache->stats->unique_csize . "',
                                        `csize`     = '" . $info->cache->stats->total_csize . "',
                                        `ttuchunks` = '" . $info->cache->stats->total_unique_chunks . "',
                                        `ttchunks`  = '" . $info->cache->stats->total_chunks . "',
                                        `modified`  = NOW()
                                    WHERE
                                        `repo_id`   = '" . $this->repoParams->repo_id . "'
                                   ");
                        if ($db->sql_error()) {
                            $err = $srv . "=>\nPARSELOG ERROR=>SQL:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }

                        $db->query("UPDATE IGNORE report
                                    SET
                                   	 `osize`      = '" . $info->archives->stats->original_size . "',
                                         `csize`      = '" . $info->archives->stats->compressed_size . "',
                                         `dsize`      = '" . $info->archives->stats->deduplicated_size . "',
                                         `dur`        = '" . $info->archives->duration . "',
                                         `nb_archive` = 1,
                                         `nfiles`     = '" . $info->archives->stats->nfiles . "'
                                     WHERE
                                         `id`               = '" . $reportId . "'
                                   ");
                        if ($db->sql_error()) {
                            $err = $srv . "=>\nSQL UPDATE ERROR:\n" . $db->sql_error();
                            $tmplog .= $err;
                            $log->error($err, $srv);
                        }
                        // Un repli reussi ne doit pas passer inapercu : la
                        // sauvegarde a fonctionne, mais par un autre chemin.
                        if (!empty($this->serverParams->fallback_note)) {
                            $tmplog .= $this->serverParams->fallback_note;
                            $db->query("UPDATE IGNORE report set `log` = CONCAT(COALESCE(`log`,''), ?) WHERE id = ?",
                                       $this->serverParams->fallback_note, (int)$reportId);
                        }
                        $db->query("UPDATE IGNORE report set `end` = NOW() WHERE id = ?", (int)$reportId);
                    }
                    else {
                        $log->error("PARSELOG ERROR\n STDERR:$info[stderr]\nSTDOUT:$info[stdout]", $srv);
                        $backuperror = 1;
                        $tmplog = "$srv =>\nBACKUPCONFIG ERROR\n STDERR:" . $info['stderr'] . "STDOUT:" . $info['stdout'] . "\n";
                        $db->query("UPDATE IGNORE report  set `error`='1', `log` = ?, `end` = NOW() WHERE id= ?", "$tmplog", "$reportId");

                    }
                }
                else {
                    $log->error("BACKUP ERROR STDOUT:$e[stdout]\nSTDERR:$e[stderr]", $srv);
                    $backuperror = 1;
                    $tmplog = "$srv =>\nSTDOUT:" . $e['stdout'] . "\nSTDERR:" . $e['stderr'];
                    $db->query("UPDATE IGNORE report  set `error`='1', `log` = ?, `end` = NOW() WHERE id= ?", "$tmplog", "$reportId");

                }
            }
            else {
                $tmplog = "$srv Connexion error SKIP BACKUP !\n";
                $db->query("UPDATE IGNORE report set `error`='1', `log` = ?, `end` = NOW() WHERE id = ?", $tmplog, (int)$reportId);
                $log->error("Connexion error SKIP BACKUP !", $srv);
                return (object)['error' => 1, 'log' => $tmplog, 'osize' => 0, 'csize' => 0,
                                'dsize' => 0, 'dur' => 0, 'nbarchive' => 0, 'nfiles' => 0];
            }

        }
        return (object)[
            'error' => $backuperror,
            'log' => $tmplog,
            'osize' => isset($info) && is_object($info) ? $info->archives->stats->original_size : 0,
            'csize' => isset($info) && is_object($info) ? $info->archives->stats->compressed_size : 0,
            'dsize' => isset($info) && is_object($info) ? $info->archives->stats->deduplicated_size : 0,
            'dur' => isset($info) && is_object($info) ? $info->archives->duration : 0,
            'nbarchive' => 1,
            'nfiles' => isset($info) && is_object($info) ? $info->archives->stats->nfiles : 0,
        ];
    }

    /**
     * getInput Method (Get input from console)
     * @return int
     */
    public function getInput() {
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        return trim($line);
    }


    public function selectServer($db) {
        $servers = $db->query("SELECT name FROM servers ORDER BY name")->fetchAll();
        $options = "";
        foreach ($servers as $key => $server) {
            $options .= "$key '{$server['name']}' ";
        }

        $cmd = "export TERM=linux; dialog --clear --title 'Sélectionner un serveur' --menu 'Choisissez un serveur:' 15 50 10 $options 3>&1 1>&2 2>&3";
        $choice = shell_exec($cmd);

        if ($choice !== null && isset($servers[trim($choice)])) {
            return $servers[trim($choice)]['name'];
        } else {
            exit(0);
        }
    }

    public function mountMenu($srv, $type, $db, $log) {
        while (true) {
            $list = $db->query("SELECT * FROM archives WHERE repo_id IN (SELECT repository.repo_id FROM servers LEFT JOIN repository ON servers.id = repository.server_id WHERE servers.name = '$srv' AND type = '$type') ORDER BY end")->fetchAll();
            $options = "";
            foreach ($list as $key => $backup) {
                $options .= "$key '{$backup['end']}' ";
            }
            $options .= "R 'Retour à la liste des serveurs' ";
            $options .= "Q 'Quitter' ";


            $cmd = "export TERM=linux; dialog --clear --title 'Sélectionner un backup pour $srv' --menu 'Choisissez un backup:' 20 60 15 $options 3>&1 1>&2 2>&3";
            $choice = trim(shell_exec($cmd));

            if ($choice === 'R') {
                return $this->selectServer($db);
            } elseif ($choice === 'Q') {
                return false;
            } elseif (isset($list[$choice])) {
                $selected_backup = $list[$choice]['nom'];
                echo shell_exec("export TERM=linux; dialog --msgbox 'Montage de $selected_backup pour $srv...' 10 50");
                $this->mountBackup($selected_backup, $srv, $type, $db, $log);
            } else {
                echo shell_exec("export TERM=linux; dialog --msgbox 'Choix invalide. Veuillez réessayer.' 10 50");
            }
        }
    }

    private function mountBackup($backup,$srv,$type,$db,$log) {
	$restore=$this->params->borg_backup_path . "/" . $srv . "/restore";
	echo "Mounting $srv's $backup in ". $restore ." Please Wait ...\n";
	$arg=$this->params->borg_backup_path . "/" . $srv . "/".$type."::$backup $restore";
	$this->borgExec('mount',$arg,$srv,$type,$db,$log);
	echo "=> Backup was succesfuly mounted, type exit to umount\n\n\n";
	passthru('cd '.$restore.' ; PS1="[\[\033[32m\]\w]\[\033[0m\]\n\[\033[1;36m\]'.$srv.' BACKUP\[\033[1;33m\]-> \[\033[0m\]" bash --noprofile --norc -i');
	echo "Unmounting $srv Backup --> ";
	$this->borgExec('umount',$restore,$srv,$type,$db,$log);
	echo "[OK]";
	echo "\n=> Backup session finished\n Do you want to mount another backup? [Y / N] Default No :";
	return $this->getInput();
    }



    /**
     * addDb Method (Add Mysql config from existing server to backup database)
     * @param Db $db
     * @param logWriter $log
     * @return bool
     */
    public function addDb($db, $log) {
        echo "[ PARAMETERS ]\n";
        echo "   - Enter the server name : ";
        $srv = $this->getInput();
        if (!$server = (object)$db->query("SELECT * from servers WHERE name='" . $srv . "'")->fetchArray()) die("Server $srv not managed");
        echo "   -Select type: 1 - Mysql  | 2 - Postgres (default 1) : ";
        $_type = $this->getInput();
        switch ($_type) {
            case 1:
                $cfg = new \StdClass;
                $cfg->type = "mysql";
                echo "Enter VG name (default vg) : ";
                $cfg->vg = $this->getInput();
                if (!$cfg->vg) $cfg->vg = 'vg';
                echo "Enter LV name (default root) : ";
                $cfg->lv = $this->getInput();
                if (!$cfg->lv) $cfg->lv = 'root';
                echo "Enter MySQL host (default 127.0.0.1) : ";
                $cfg->db_host = $this->getInput();
                if (!$cfg->db_host) $cfg->db_host = '127.0.0.1';
                echo "Enter MySQL username : ";
                $cfg->db_user = $this->getInput();
                echo "Enter MySQL password : ";
                $cfg->db_pass = $this->getInput();
                echo "Enter MySQL data path (default /var/lib/mysql) : ";
                $cfg->mysql_path = $this->getInput();
                if (!$cfg->mysql_path) $cfg->mysql_path = '/var/lib/mysql';
                echo "   - Enter number of retention point (default 8) : ";
                $cfg->keep = $this->getInput();
                if (!$cfg->keep) $cfg->keep = '8';
                $cfg->encryption = "repokey";
                $cfg->compression = "lz4";
                $cfg->ratelimit = "0";
                $cfg->exclude = "";
                $cfg->server_id = $server->id;
                echo "\n";
                $db->query("INSERT INTO `db_info`
                                                (`type`, `server_id`, `db_host`, `db_user`, `db_pass`, `vg_name`, `lvm_part`, `lvsize`, `mysql_path`)
                                            VALUES
                                                ( '" . $cfg->type . "',
                                                  '" . $server->id . "',
                                                  '" . $cfg->db_host . "',
                                                  '" . $cfg->db_user . "',
                                                  '" . $cfg->db_pass . "',
                                                  '" . $cfg->vg . "',
                                                  '" . $cfg->lv . "',
                                                  '500M',
                                                  '" . $cfg->mysql_path . "'
                                                )
                                           ");

                $this->checkRepo($srv, $log, $db, $cfg);
            }
        }

        /**
         * addSrv Method (Add a server to backup)
         * @return bool
         */
        public function addSrv($db, $log) {
            echo "[ PARAMETERS ]\n";
            echo "   - Enter the server name : ";
            $srv = $this->getInput();

            echo "   - Enter number of retention point (default 8) : ";
            $keep = $this->getInput();
            if (!$keep) $keep = 8;

            echo "   - Specify SSH port (default 22) : ";
            $sshport = $this->getInput();
            if (!$sshport) $sshport = 22;
            echo "\n\n[ REMOTE CONFIG ]\n";
            echo "   - Connecting to $srv\n";
            echo "   - Making SSH key ===================> ";
            $exec = $this->myExec('ssh -tt  -o "StrictHostKeyChecking=no" -p ' . $sshport . " " . $srv . " \"if [ ! -f /root/.ssh/id_rsa ]; then ssh-keygen -t rsa -b 2048 -f /root/.ssh/id_rsa -N '' &> /dev/null && echo '[OK]' || echo 'Failed to create key'; else  echo '[SKIP] key already exist'; fi\"");
            if ($exec['return'] == 0) {
                echo $exec['stdout'];
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                die;
            }
            echo "   - Get SSH key ======================> ";
	    $exec = $this->myExec('ssh -tt -p ' . $sshport . " " . $srv . " \"cat /root/.ssh/id_rsa.pub\"");
	    // Toutes les adresses connues du serveur de sauvegarde, et non plus
	    // deux valeurs ecrites en dur : un mode ajoute ne doit pas laisser
	    // une entree known_hosts perimee derriere lui.
	    $purge = '';
	    foreach ($this->serverAddresses() as $adr) {
	        $purge .= 'ssh-keygen -f /root/.ssh/known_hosts -R ' . escapeshellarg($adr) . ';';
	    }
	    $this->myExec('ssh -tt -p ' . $sshport . " " . escapeshellarg($srv) . ' ' . escapeshellarg($purge));
            if ($exec['return'] == 0) {
                $sshkey = $exec['stdout'];
                echo "[OK]\n";
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                die;
            }
            echo "   - Installation of BorgBackup =======> ";
            $exec = $this->myExec('ssh -tt -p ' . $sshport . " " . $srv . " \" if [ `uname -m` == 'i686' ]; then plateforme='32'; else plateforme='64'; fi; if [ ! -f /usr/bin/borg ]; then wget --no-check-certificate -q -O /usr/bin/borg https://github.com/borgbackup/borg/releases/download/1.1.7/borg-linux\\\$plateforme  ; chmod +x /usr/bin/borg && echo '[OK]' || echo '[FAIL] =>  Unable to install BorgBackup' ; else echo '[SKIP] BorgBackup already installed'; fi\"");
            if ($exec['return'] == 0) {
                echo $exec['stdout'];
            }
            else {
                echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                //die;
	    }

            echo "\n\n[ LOCAL CONFIG ]\n";
            echo "   - Creating User ====================> ";
            if (!posix_getpwnam($srv)) {
                $exec = $this->myExec('useradd -d ' . $this->params->borg_backup_path . '/' . $srv . ' -m ' . $srv);
                if (posix_getpwnam($srv)) echo "[OK]\n";
                else {
                    echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                    die;
                }

            }
            else {
                echo "[SKIP] User '$srv' already exist.\n";
            }
            echo "   - Config SSH key ===================> ";

            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/.ssh')) mkdir($this->params->borg_backup_path . '/' . $srv . '/.ssh');
            file_put_contents($this->params->borg_backup_path . '/' . $srv . '/.ssh/authorized_keys', $sshkey);
            if (file_exists($this->params->borg_backup_path . '/' . $srv . '/.ssh/authorized_keys')) echo "[OK]\n";
            echo "   - Creating repository ==============> ";
            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/backup')) {
                $passphrase = $this->generateRandomString();
                $encryption = "repokey";
                $exec = $this->myExec(
                    'sh -c ' . escapeshellarg($this->secretCmd(array('BORG_PASSPHRASE'),
                        'cd ' . escapeshellarg($this->params->borg_backup_path . '/' . $srv) . '; '
                      . $this->params->borg_binary_path . ' init backup -e ' . escapeshellarg($encryption)
                      . ' && echo "[OK]" || echo "[FAIL]"')),
                    $this->secretInput(array($passphrase)));
                if ($exec['return'] == 0) {
                    echo $exec['stdout'];
                }
                else {
                    echo "Error: " . $exec['stdout'] . "\n" . $exec['stderr'] . "\n";
                    die;
                }

            }
            else {
                echo "[SKIP] Repository already exist\n";
            }
            echo "   - Creating restore directory =======> ";
            if (!file_exists($this->params->borg_backup_path . '/' . $srv . '/restore')) {
                mkdir($this->params->borg_backup_path . '/' . $srv . '/restore');
                echo "[OK]\n";
            }
            else {
                echo "[SKIP] Restore directory already exist\n";
            }
            echo "   - Add server configuration to DB  ==> ";
	    $check = $db->query("SELECT id,name from servers where name='" . $srv . "'")->fetchArray();
            if (!$check) {
                $ratelimit = 0;
                $compression = "lz4";
                $db->query("INSERT INTO `servers`
                                        (`name`, `host`, `port`,`ssh_pub_key`, `active`)
                                    VALUES
                                        ( '" . $srv . "',
                                          '" . $srv . "',
                                          '" . $sshport . "',
                                          '" . $sshkey . "',
                                          1
                                        )
                                  ");
                if ($db->sql_error()) {
                    $log->error("Unable to insert server in DB: " . $db->sql_error() , $srv);
                    echo '[FAILED] SQL error';
                }
                else {
                    echo "[OK]\n";
                    $server_id = $db->insertId();
                }
            }
            else {
                echo "[SKIP] Configuration file already exist\n";
		//$repoconfig->id = $check['repo_id'];
		$server_id = $check['id'];
            }
            echo "   - Set the rights to repository =====> ";
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->params->borg_backup_path . '/' . $srv, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                chmod($item, 0700);
                chgrp($item, $srv);
                chown($item, $srv);
            }
            echo "[OK]\n";
            echo "   - Add repository in MySQL ==========> ";
            $repoconfig = new \StdClass;
            $repoconfig = (object)parse_ini_file($this->params->borg_backup_path . "/$srv/backup/config");
            $check_repo_sql = $db->query("SELECT id from repository WHERE repo_id='" . $repoconfig->id . "'")->fetchArray();
            if (!$check_repo_sql) {
                $db->query("INSERT IGNORE INTO repository
                                        (`server_id`,`repo_id`,`type`,`retention`,`encryption`,`passphrase`,`repo_path`,`compression`, `ratelimit`, `backup_path`, `exclude`, `modified`)
                                    VALUES
                                        ( '" . $server_id . "',
                                          '" . $repoconfig->id . "',
                                          'backup',
                                          '" . $keep . "',
                                          '" . $encryption . "',TO_BASE64('" . $passphrase . "'),
                                          '" . $this->params->borg_backup_path . '/' . $srv . '/backup' . "',
                                          '" . $compression . "',
                                          '" . $ratelimit . "',
                                          '/',
                                          ' /proc,/dev,/sys,/tmp,/run,/var/run,/lost+found,/var/cache/apt/archives,/var/lib/mysql,/var/lib/lxcfs',
                                          NOW()
                                        )
                                  ");
                if ($db->sql_error()) {
                    $log->error("Unable to insert repository in DB: " . $db->sql_error() , $srv);
                    echo '[FAILED] SQL error';
                }
                else {
                    echo "[OK]\n";
                }
            }
            else {
                echo "[SKIP] Repository exist in DB.\n";
            }

            echo "\n[FINISH] Server '$srv' Succesfuly added\n";

        }

    }
    

