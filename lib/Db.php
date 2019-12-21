<?php

namespace phpBorg;

use mysqli;

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
    public function __construct($dbhost = '127.0.0.1', $dbuser = 'phpborg', $dbpass = 'DwEyqr1c73dLS9Br', $dbname = 'phpborg', $charset = 'utf8') {
        $this->connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        if ($this->connection->connect_error) {
                die('Echec de la connexion - ' . $this->connection->connect_error);
        }
        $this->connection->set_charset($charset);
    }

    /**
     * @param $query
     * @return $this
     */
    public function query($query) {
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
