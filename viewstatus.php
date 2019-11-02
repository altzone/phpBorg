<?php
// USE WITH BOOTSTRAP3 + BOOTSTRAP-TABLE
use phpBorg\Db;

require 'lib/Db.php';
$db = new Db();

echo '
<!-- Latest compiled and minified CSS -->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

	<!-- Optional theme -->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">

	<!-- Latest compiled and minified JavaScript -->
	<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo="  crossorigin="anonymous"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
	<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/bs/dt-1.10.20/datatables.min.css"/>
 
<script type="text/javascript" src="https://cdn.datatables.net/v/bs/dt-1.10.20/datatables.min.js"></script>

<div id="breadcrumb">
<ul class="breadcrumb">
<li><i class="fa fa-home"></i><a href="/home"> EXTRANET</a></li>
<li class="active">Repository de backup</li>
</ul>
</div><!-- /breadcrumb-->


<div class="col-md-12">';




function GetSizeName($octet)
{
    // Array containing the different units
    $unite = array(' octets',' Ko',' Mo',' Go',' To');

    if ($octet < 1000) {
        return $octet.$unite[0];
    } else {
        if ($octet < 1000000) {
            $ko = round($octet/1024,2);
            return $ko.$unite[1];
        } else {
            if ($octet < 1000000000) {
                $mo = round($octet/(1024*1024),2);
                return $mo.$unite[2];
            } else {
                if ($octet < 1000000000000) {
                        $go = round($octet/(1024*1024*1024),2);
                        return $go.$unite[3];
                } else {
                       $to = round($octet/(1024*1024*1024*1024),2);
                       return $to.$unite[4];
                }
            }
        }
    }
}




if (empty($_REQUEST["repo"])) {
        $info = $db->query("SELECT count(id) as nombre, sum(size) as total, sum(csize) as ctotal, sum(dsize) as dtotal, (SELECT count(id)  from archives) as archives from repository")->fetchArray();
        $size = GetSizeName($info['total']);
        $csize = GetSizeName($info['ctotal']);
        $dsize = GetSizeName($info['dtotal']);
        $percentc = ceil(100-($info['ctotal']*100/$info['total']));
        $percentd = ceil(100-($info['dtotal']*100/$info['total']));

        echo "
        <h2>Liste des Repository</h2>
<div class='row'>
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-danger'>
                        <h2 class='m-top-none' id='userCount'>$info[nombre]</h2>
                        <h5>Repository</h5>
                        <span class='m-left-xs'>contenant $info[archives] archives</span>
                        <div class='stat-icon'>
                                <i class='fa fa-book fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-info'>
                        <h2 class='m-top-none'><span id='serverloadCount'>$size</span></h2>
                        <h5>Taille total</h5>
                        <span class='m-left-xs'></span>
                        <div class='stat-icon'>
                                <i class='fa fa-hdd-o fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-warning'>
                        <h2 class='m-top-none' id='orderCount'>$csize</h2>
                        <h5>Taille compressé</h5>
                        <span class='m-left-xs'>soit un gain de $percentc% d'espace</span>
                        <div class='stat-icon'>
                                <i class='fa fa-archive fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-success'>
                        <h2 class='m-top-none' id='visitorCount'>$dsize</h2>
                        <h5>Taille dédupliqué</h5>
                        </i><span class='m-left-xs'>soint un gain de $percentd% d'espace</span>
                        <div class='stat-icon'>
                                <i class='fa fa-filter fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
</div>
        <table class='table table-striped table-bordered responsive tabledata-striped' style='width: 100%;'>
        <thead>
        <tr>
                <th>Nom</th>
                <th>Points de resto</th>
                <th>Derniere archive</th>
                <th>Taille Reel</th>
                <th>Taille compressé</th>
                <th>Taille Dedup</th>
        </tr>
        </thead>
        <tbody>";
        $first=$db->query("SELECT * FROM `repository`")->fetchAll();
	if(!empty($first)) {
		foreach ($first as $row) {
                        $count = $db->query("SELECT count(id) as nb FROM archives WHERE repo = '$row[nom]'")->fetchArray();
                        $lastest= $db->query("SELECT DATE_FORMAT(archives.end,'%d/%m/%Y - %H:%i:%s') as lastest FROM archives WHERE repo = '$row[nom]' ORDER by end DESC LIMIT 1")->fetchArray();
                        $nom = explode('/',$row['nom']);
                        $size = GetSizeName($row['size']);
                        $csize = GetSizeName($row['csize']);
                        $dsize = GetSizeName($row['dsize']);
			if ($row['size']) {
				$percentc = ceil(100-($row['csize']*100/$row['size']));
				$percentd = ceil(100-($row['dsize']*100/$row['size']));
			} else {
				$percentc=$percentd = 'N/A';
			}

                        print " <tr>
                        <td><a href=\"/backup?repo=$row[nom]\">$nom[3]</a></td>
                        <td>$count[nb]</td>
                        <td>$lastest[lastest]</td>
                        <td>$size</td>
                        <td>$csize ($percentc%)</td>
                        <td>$dsize ($percentd%)</td>
                        </tr>";
                }
                echo "</tbody></table>";
        }
} else {
        $repoinfo = $db->query("SELECT * FROM `repository` WHERE nom='$_REQUEST[repo]'")->fetchArray();
        $info = $db->query("SELECT count(id) as nombre FROM `archives` WHERE repo='$_REQUEST[repo]'")->fetchArray();
        $size = GetSizeName($repoinfo['size']);
        $csize = GetSizeName($repoinfo['csize']);
        $dsize = GetSizeName($repoinfo['dsize']);
        $percentc = ceil(100-($repoinfo['csize']*100/$repoinfo['size']));
        $percentd = ceil(100-($repoinfo['dsize']*100/$repoinfo['size']));
        $nom = explode('/',$repoinfo['nom']);
        echo "
        <div class='row'>
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-danger'>
                        <h2 class='m-top-none' id='userCount'>$info[nombre]</h2>
                        <h5>Archives</h5>
                        <span class='m-left-xs'></span>
                        <div class='stat-icon'>
                                <i class='fa fa-book fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-info'>
                        <h2 class='m-top-none'><span id='serverloadCount'>$size</span></h2>
                        <h5>Taille des backups</h5>
                        <span class='m-left-xs'></span>
                        <div class='stat-icon'>
                                <i class='fa fa-hdd-o fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-warning'>
                        <h2 class='m-top-none' id='orderCount'>$csize</h2>
                        <h5>Taille compressé</h5>
                        <span class='m-left-xs'>soit un gain de $percentc% d'espace</span>
                        <div class='stat-icon'>
                                <i class='fa fa-archive fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
        <div class='col-sm-6 col-md-3'>
                <div class='panel-stat3 bg-success'>
                        <h2 class='m-top-none' id='visitorCount'>$dsize</h2>
                        <h5>Taille dédupliqué</h5>
                        </i><span class='m-left-xs'>soint un gain de $percentd% d'espace</span>
                        <div class='stat-icon'>
                                <i class='fa fa-filter fa-3x'></i>
                        </div>
                        <div class='refresh-button'>
                                <i class='fa fa-refresh'></i>
                        </div>
                        <div class='loading-overlay'>
                                <i class='loading-icon fa fa-refresh fa-spin fa-lg'></i>
                        </div>
                </div>
        </div><!-- /.col -->
</div>
        <h3> Listes des archives pour $nom[3] </h3>
        <table id='back' class='table table-striped table-bordered responsive tabledata' style='width: 100%;'>
        <thead>
        <tr>
                <th>Nom</th>
                <th>Durée</th>
                <th>Start</th>
                <th>End</th>
                <th>Taille Reel</th>
                <th>Taille compressé</th>
                <th>Taille Dedup</th>
                <th>Nombre de Fichiers</th>
        </tr>
        </thead>
        <tbody>";

        $repo=$_REQUEST["repo"];
        $SQL=$db->query("SELECT nfiles,nom, dur, DATE_FORMAT(archives.start,'%d/%m/%Y - %H:%i:%s') as datestart, DATE_FORMAT(archives.end,'%d/%m/%Y - %H:%i:%s') as dateend, osize,dsize,csize FROM `archives` WHERE repo = '$repo' ORDER BY start")->FetchAll();

        $first= $db->fsql("$SQL", $dbCon, "0");
	if(!empty($SQL)) {
		foreach ($SQL as $row) {
                        $size = GetSizeName($row['osize']);
                        $csize = GetSizeName($row['csize']);
                        $dsize = GetSizeName($row['dsize']);
                        $dur = $cli->secondsToTime($row['dur']);
                        $pcsize = round(100-($row['csize']* 100 / $row['osize']),2);
                        $pdsize = round(100-($row['dsize'] * 100 / $row['osize']),2);
                        print " <tr>
                        <td>$row[nom]</td>
                        <td>$dur</td>
                        <td>$row[datestart]</td>
                        <td>$row[dateend]</td>
                        <td>$size</td>
                        <td>$csize ($pcsize%)</td>
                        <td>$dsize ($pdsize%)</td>
                        <td>$row[nfiles]</td>
                        </tr>";
                }
                echo "</tbody></table><br>
                <a href='/backup' class='btn btn-warning'><i class='fa fa-chevron-left'></i> Back</a>
                <script>
                        $(document).ready(function () {
                $('#back').DataTable().order([2, 'desc']).draw();

                        });
                </script>";
        }
}

if ($_GET['report']) {
        echo "
        <h3> Rapport de backup</h3>
        <table id='back' class='table table-striped table-bordered responsive tabledata' style='width: 100%;'>
        <thead>
        <tr>
                <th>Status</th>
                <th>Nombre de backup effectués</th>
                <th>Debut</th>
                <th>fin</th>
                <th>Durée</th>
                <th>Nombre de fichiers</th>
                <th>Taille original</th>
                <th>Taille compressé</th>
                <th>Taille Dedup</th>
                <th>Serveur en cours</th>
                <th>Log</th>
        </tr>
        </thead>
        <tbody>";

        $repo=$_REQUEST["repo"];
        $SQL=$db->query("SELECT status,DATE_FORMAT(report.start,'%d/%m/%Y - %H:%i:%s') as datestart, DATE_FORMAT(report.end,'%d/%m/%Y - %H:%i:%s') as dateend,dur, osize,dsize,csize,nfiles,nb_archive,curpos FROM `report`  ORDER BY datestart")->fetchAll();

	if(!empty($SQL)) {
		foreach ($SQL as $row) {
                        $size = GetSizeName($row['osize']);
                        $csize = GetSizeName($row['csize']);
                        $dsize = GetSizeName($row['dsize']);
                        $dur = $cli->secondsToTime($row['dur']);
                        $pcsize=round(100-($row[csize]*100/$row['osize']),2);
                        $pdsize=round(100-($row[dsize]*100/$row['osize']),2);
                        if ($row['status'] == 0){
                        	$status="<i class='fa fa-check fa-lg' style='color:green;'></i>";
                        }
                        else {
                        	$status="<i class='fa fa-warning fa-lg' style='color:red;'></i>";
                        }
                        echo " <tr>
                        <td align=center>$status</td>
                        <td>$row[nb_archive]</td>
                        <td>$row[datestart]</td>
                        <td>$row[dateend]</td>
                        <td>$dur</td>
                        <td>$row[nfiles]</td>
                        <td>$size</td>
                        <td>$csize ($pcsize%)</td>
                        <td>$dsize ($pdsize%)</td>
                        <td>$row[curpos]</td>
                        <td><button type='button' class='btn btn-primary' data-toggle='modal' data-target='#exampleModalLong'>Log </button></td>
                        </tr>";
                }
                echo "</tbody></table><br>
                <a href='/backup' class='btn btn-warning'><i class='fa fa-chevron-left'></i> Back</a>
                <script>
                        $(document).ready(function () {
                $('#back').DataTable().order([2, 'desc']).draw();

                        });
                </script>";
        }

echo "
<div class='modal fade' id='exampleModalLong' tabindex='-1' role='dialog' aria-labelledby='exampleModalLongTitle' aria-hidden='true'>
  <div class='modal-dialog' role='document'>
    <div class='modal-content'>
      <div class='modal-header'>
        <h5 class='modal-title' id='exampleModalLongTitle'>Modal title</h5>
        <button type='button' class='close' data-dismiss='modal' aria-label='Close'>
          <span aria-hidden='true'>&times;</span>
        </button>
      </div>
      <div class='modal-body'>
       log
      </div>
      <div class='modal-footer'>
        <button type='button' class='btn btn-secondary' data-dismiss='modal'>Close</button>
        <button type='button' class='btn btn-primary'>Save changes</button>
      </div>
    </div>
  </div>
</div>
";
}


        ?>
</div>
