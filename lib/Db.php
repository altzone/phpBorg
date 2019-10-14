<?php

namespace phpBorg;

use Memcached;

class Db
{
    /**
     * @param $server
     * @return false|\mysqli
     */
    function db_connect($server) {
        switch ($server) {
            case "backup":
                $link = mysqli_connect('127.0.0.1', 'user', 'pass', 'db');
                return $link;
                break;
        }
        return false;
    }

    /**
     * @param $reqsql
     * @param $db
     * @param int $cache
     * @return bool|mixed|\mysqli_result
     */
    function fsql ($reqsql, $db, $cache = 0){
        $mem = new Memcached();
        $mem->addServer("127.0.0.1", 11211);

        $querykey = "KEY" . md5($reqsql);
        $result = $mem->get($querykey);

        if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0) {
            $rez = mysqli_query($db, $reqsql) or die('MySQL error: <br>' . $reqsql . '<br>'. mysqli_error($db));
            $mem->set($querykey, $rez, $cache);
        } else {
            $rez = $result;
        }

        return $rez;
    }

    /**
     * @param $reqsql
     * @param $db
     * @param int $cache
     * @return mixed|object|null
     */
    function fsql_object ($reqsql, $db, $cache = 0) {
        $mem = new Memcached();
        $mem->addServer("127.0.0.1", 11211);

        $querykey = "KEY" . md5($reqsql);
        $result = $mem->get($querykey);
        if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0 ) {
            $sql = mysqli_query($db, $reqsql) or die('MySQL error: <br>' . $reqsql . '<br>' . mysqli_error($db));
            $result = mysqli_fetch_object($sql);
            $mem->set($querykey, $result, $cache);
        }

        return $result;
    }
}
