<?php

declare(strict_types=1);

/**
 * phpBorg 2.0 - Web Dashboard
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpBorg\Application;
use PhpBorg\Repository\ServerRepository;
use PhpBorg\Repository\BorgRepositoryRepository;
use PhpBorg\Repository\ArchiveRepository;

try {
    $app = new Application();

    $serverRepo = $app->getServerRepository();
    $repoRepo = $app->getBorgRepositoryRepository();
    $archiveRepo = $app->getArchiveRepository();

    // Get statistics
    $servers = $serverRepo->findAll();
    $repositories = $repoRepo->findAll();
    $totalServers = count($servers);

    // Calculate totals
    $totalSize = 0;
    $totalCompressed = 0;
    $totalDeduplicated = 0;
    $totalArchives = 0;

    foreach ($repositories as $repo) {
        $totalSize += $repo->size;
        $totalCompressed += $repo->compressedSize;
        $totalDeduplicated += $repo->deduplicatedSize;
        $totalArchives += $archiveRepo->countByRepositoryId($repo->repoId);
    }

    // Calculate compression ratios
    $compressionRatio = $totalSize > 0 ? round((1 - $totalCompressed / $totalSize) * 100, 1) : 0;
    $deduplicationRatio = $totalSize > 0 ? round((1 - $totalDeduplicated / $totalSize) * 100, 1) : 0;

} catch (Exception $e) {
    $error = $e->getMessage();
    $totalServers = 0;
    $totalArchives = 0;
    $totalSize = 0;
    $totalCompressed = 0;
    $totalDeduplicated = 0;
    $compressionRatio = 0;
    $deduplicationRatio = 0;
    $servers = [];
    $repositories = [];
}

/**
 * Format bytes to human readable size
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>phpBorg 2.0 - Backup Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .dashboard-card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 3rem;
            opacity: 0.3;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        .server-table {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }
        .header-title {
            color: white;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="header-title">
                    <i class="fas fa-database"></i> phpBorg 2.0
                    <small class="fs-5 ms-2">Backup Dashboard</small>
                </h1>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <!-- Error Alert -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                    <hr>
                    <p class="mb-0">Please run: <code>php bin/phpborg setup --fix</code></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <!-- Servers -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card bg-primary text-white position-relative">
                    <div class="card-body">
                        <i class="fas fa-server stat-icon"></i>
                        <h5 class="card-title">Servers</h5>
                        <h2 class="mb-0"><?= $totalServers ?></h2>
                        <small>Configured servers</small>
                    </div>
                </div>
            </div>

            <!-- Archives -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card bg-success text-white position-relative">
                    <div class="card-body">
                        <i class="fas fa-archive stat-icon"></i>
                        <h5 class="card-title">Archives</h5>
                        <h2 class="mb-0"><?= $totalArchives ?></h2>
                        <small>Total backups</small>
                    </div>
                </div>
            </div>

            <!-- Original Size -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card bg-info text-white position-relative">
                    <div class="card-body">
                        <i class="fas fa-hdd stat-icon"></i>
                        <h5 class="card-title">Original Size</h5>
                        <h2 class="mb-0"><?= formatBytes($totalSize) ?></h2>
                        <small>Total data backed up</small>
                    </div>
                </div>
            </div>

            <!-- Storage Used -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card bg-warning text-white position-relative">
                    <div class="card-body">
                        <i class="fas fa-compress stat-icon"></i>
                        <h5 class="card-title">Storage Used</h5>
                        <h2 class="mb-0"><?= formatBytes($totalDeduplicated) ?></h2>
                        <small><?= $deduplicationRatio ?>% space saved</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Efficiency Stats -->
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-chart-line text-primary"></i> Compression Efficiency
                        </h5>
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar bg-success" role="progressbar"
                                 style="width: <?= $compressionRatio ?>%;"
                                 aria-valuenow="<?= $compressionRatio ?>"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                                <?= $compressionRatio ?>%
                            </div>
                        </div>
                        <small class="text-muted">
                            Saved <?= formatBytes($totalSize - $totalCompressed) ?> through compression
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-layer-group text-info"></i> Deduplication Efficiency
                        </h5>
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar bg-info" role="progressbar"
                                 style="width: <?= $deduplicationRatio ?>%;"
                                 aria-valuenow="<?= $deduplicationRatio ?>"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                                <?= $deduplicationRatio ?>%
                            </div>
                        </div>
                        <small class="text-muted">
                            Saved <?= formatBytes($totalSize - $totalDeduplicated) ?> through deduplication
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Servers Table -->
        <?php if (!empty($servers)): ?>
        <div class="row">
            <div class="col-12">
                <div class="server-table">
                    <h5 class="mb-3">
                        <i class="fas fa-list"></i> Configured Servers
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Server</th>
                                    <th>Host</th>
                                    <th>Port</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($servers as $server): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($server->name) ?></strong>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($server->host) ?></code>
                                    </td>
                                    <td><?= $server->port ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars($server->backupType) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($server->active): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-times-circle"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="server-details.php?name=<?= urlencode($server->name) ?>"
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="row mt-4">
            <div class="col-12 text-center text-white">
                <p class="mb-0">
                    <i class="fas fa-shield-alt"></i> phpBorg 2.0 - Professional PHP 8.3+ Frontend for BorgBackup
                    <br>
                    <small>
                        <i class="fas fa-code"></i> CLI Commands:
                        <code class="text-white">./bin/phpborg list</code> |
                        <code class="text-white">./bin/phpborg backup &lt;server&gt;</code> |
                        <code class="text-white">./bin/phpborg mount</code>
                    </small>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
