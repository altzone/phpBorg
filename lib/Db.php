<?php

namespace phpBorg;

use mysqli;

require_once __DIR__ . '/Config.php';

/**
 * Class Db
 * @package phpBorg
 */
Class Db
{

    /**
     * @var mysqli
     */
    protected $connection;

    /**
     * Identifiants memorises, pour pouvoir se reconnecter.
     * @var array
     */
    protected $dsn = array();

    /**
     * @var \mysqli_stmt::result_metadata
     */
    protected $query;

    /**
     * @var int
     */
    public    $query_count = 0;

    /**
     * @var int
     */
    public    $sql_err = 1;

    /**
     * Db constructor.
     * @param string $dbhost
     * @param string $dbuser
     * @param string $dbpass
     * @param string $dbname
     * @param string $charset
     */
    public function __construct($dbhost = null, $dbuser = null, $dbpass = null, $dbname = null, $charset = null) {
        // Les identifiants viennent de conf/phpborg.conf ; les arguments restent
        // acceptes pour compatibilite avec les appels existants.
        $cfg = Config::get('db');
        if ($dbhost  === null) $dbhost  = isset($cfg['host'])    ? $cfg['host']    : '127.0.0.1';
        if ($dbuser  === null) $dbuser  = isset($cfg['user'])    ? $cfg['user']    : 'phpborg';
        if ($dbpass  === null) $dbpass  = isset($cfg['pass'])    ? $cfg['pass']    : '';
        if ($dbname  === null) $dbname  = isset($cfg['name'])    ? $cfg['name']    : 'phpborg';
        if ($charset === null) $charset = isset($cfg['charset']) ? $cfg['charset'] : 'utf8';

        $this->dsn = array($dbhost, $dbuser, $dbpass, $dbname, $charset);
        $this->connect();
    }

    /**
     * Ouvre la connexion a partir des identifiants memorises.
     * @return void
     */
    private function connect() {
        list($h, $u, $p, $n, $c) = $this->dsn;
        $this->connection = new mysqli($h, $u, $p, $n);
        if ($this->connection->connect_error) {
                die('Echec de la connexion - ' . $this->connection->connect_error);
        }
        $this->connection->set_charset($c);
    }

    /**
     * Retablit la connexion si le serveur l'a fermee.
     *
     * Un "full" ouvre sa connexion a 22h et l'utilise encore a la fin du run.
     * Le 28/09/2026, une tache bloquee a porte ce run a 12h33 : au-dela de
     * wait_timeout, MySQL avait ferme la connexion et l'agregation finale a
     * leve "MySQL server has gone away". Le run est mort apres avoir termine
     * ses 70 taches, sans clore son rapport ni envoyer de mail.
     *
     * @return void
     */
    private function ensureConnected() {
        // mysqli est en mode exception : une connexion fermee par le serveur
        // leve mysqli_sql_exception au lieu de renvoyer false. Le try/catch
        // est donc indispensable, l'operateur @ ne suffirait pas.
        try {
            if ($this->connection instanceof mysqli
                && $this->connection->query('SELECT 1') !== false) return;
        } catch (\Throwable $e) {
            // connexion perdue : on la refait ci-dessous
        }
        try { if ($this->connection instanceof mysqli) $this->connection->close(); }
        catch (\Throwable $e) { /* deja fermee */ }
        $this->connect();
    }

    /**
     * @param $query
     * @return $this
     */
    public function query($query) {
        $this->ensureConnected();
        if ($this->query = $this->connection->prepare($query)) {
                if (func_num_args() > 1) {
                        $x = func_get_args();
                        $args = array_slice($x, 1);
                        $types = '';
                        $args_ref = array();
                        foreach ($args as $k => &$arg) {
                                if (is_array($args[$k])) {
                                        foreach ($args[$k] as $j => &$a) {
                                                $types .= $this->_gettype($args[$k][$j]);
                                                $args_ref[] = &$a;
                                        }
                                } else {
                                        $types .= $this->_gettype($args[$k]);
                                        $args_ref[] = &$arg;
                                }
                        }
                        array_unshift($args_ref, $types);
                        call_user_func_array(array($this->query, 'bind_param'), $args_ref);
                }
                $this->query->execute();
                if ($this->query->errno) {
                        printf("Échec de la requete : %s\n", $this->query->error);
                        if ($this->sql_err) die();
                }
                $this->query_count++;
        } else {
		printf("erreur dans la requete: %s\n", $this->connection->error);
                if ($this->sql_err) die();
        }
        return $this;
    }

    /**
     * @return array
     */
    public function fetchAll() {
            $params = array();
            $row    = array();
            $meta = $this->query->result_metadata();
            while ($field = $meta->fetch_field()) {
                $params[] = &$row[$field->name];
            }
            call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            $result[] = $r;
        }
        $this->query->close();
                return $result;
        }

    /**
     * @return array
     */
    public function fetchArray() {
        $params = array();
        $row    = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
                while ($this->query->fetch()) {
                        foreach ($row as $key => $val) {
                                $result[$key] = $val;
                        }
                }
        $this->query->close();
                return $result;
        }

    /**
     * @return string
     */
    public function sql_error() {
            return $this->connection->error;
    }

    /**
     * @return int
     */
    public function numRows() {
            $this->query->store_result();
            return $this->query->num_rows;
    }

    /**
     * @return bool
     */
    public function close() {
            return $this->connection->close();
    }

    /**
     * @return int
     */
    public function affectedRows() {
            return $this->query->affected_rows;
    }

    /**
     * @return int
     */
    public function insertId() {
        return $this->query->insert_id;
    }

    /**
     * @param $var
     * @return string
     */
    private function _gettype($var) {
        if(is_string($var)) return 's';
        if(is_float($var)) return 'd';
        if(is_int($var)) return 'i';
        return 'b';
    }

    /**
     * @param $sql_err
     */
    public function sql_err ($sql_err) {
            $this->sql_err = $sql_err;
    }

}
