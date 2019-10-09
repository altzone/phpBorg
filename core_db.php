<?php
function db_pg_connect($server) {
        switch ($server) {
        case "local":
                $db_conn = pg_connect("host=0.0.0.0 port=5432 user=user password=pass dbname=db");
                if (!$db_conn) {
                  return "Failed connecting to postgres database $database\n";
                  exit;
                }
                return $db_conn;
        break;
        }

}

function fpsql($sql,$dbh) {
        return  pg_query($dbh,$sql);
}



function db_connect ($server)
{
        switch ($server) {
    case "backup":
        $link = mysqli_connect('127.0.0.1', 'user', 'pass', 'db');
        return $link;
        break;

         }

}

function fsql ($reqsql,$db,$cache=0)
{
$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211);

        $querykey = "KEY" . md5($reqsql);
        $result = $mem->get($querykey);
        if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0) {
               $rez=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error($db));
               $mem->set($querykey, $rez, $cache);

$test=$mem->get($querykey);
//print_r($test);
        }
        else {  $rez=$result; }
        return $rez;


}

function fsql_object ($reqsql,$db,$cache=0)
{
$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211);

        $querykey = "KEY" . md5($reqsql);
        $result = $mem->get($querykey);
        if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0 ) {
                $sql=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error($db));
                $result=mysqli_fetch_object($sql);
                $mem->set($querykey, $result, $cache);
        }

        return $result;


}

function fsql_array ($reqsql,$db,$cache=0)
{
$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211);
        $data=Array();
        $querykey = "KEY" . md5($reqsql);
        $data = $mem->get($querykey);
        if (($mem->getResultCode() == Memcached::RES_NOTFOUND) || $cache == 0 ) {
                $sql=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error());
                while ($data[] = mysqli_fetch_object($sql));
                $mem->set($querykey, $data, $cache);
        }

        return $data;


}



function fsql_row ($reqsql,$db,$cache=0)
{
$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211);

        $querykey = "KEY" . md5($reqsql);
        $result = $mem->get($querykey);
        if (! $result || $cache == 0) {
                $sql=mysqli_query($db,$reqsql) or die('Erreur SQL !<br>'.$reqsql.'<br>'.mysqli_error());
                $result=mysqli_fetch_row($sql);
                $mem->set($querykey, $result, $cache);
        }

        return $result;


}
?>
