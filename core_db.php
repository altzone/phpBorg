<?php

/**
 * # TODO
 *
 * 1. use classes
 * 2. store global mysql connection values as class properties
 */

/**
 * @param $server
 * @return false|mysqli
 */
function db_connect ($server) {
    switch ($server) {
        case "backup":
            $link = mysqli_connect('127.0.0.1', 'user', 'pass', 'db');
            return $link;
            break;
    }
}

/**
 * @param $reqsql
 * @param $db
 * @param int $cache
 * @return bool|mixed|mysqli_result
 */
function fsql ($reqsql, $db, $cache = 0){
    $mem = new Memcached();
    $mem->addServer("127.0.0.1", 11211);

    $querykey = "KEY" . md5($reqsql);
    $result = $mem->get($querykey);

    if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0) {
        $rez = mysqli_query($db,$reqsql) or die('MySQL error: <br>'.$reqsql.'<br>'.mysqli_error($db));
        $mem->set($querykey, $rez, $cache);

        $test = $mem->get($querykey);
        //print_r($test);
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
        $sql = mysqli_query($db,$reqsql) or die('MySQL error: <br>'.$reqsql.'<br>'.mysqli_error($db));
        $result = mysqli_fetch_object($sql);
        $mem->set($querykey, $result, $cache);
    }

    return $result;
}
