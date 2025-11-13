<?php
header('Content-Type: text/plain');

echo "=== PHP Mount Test ===\n\n";

echo "Current user: " . posix_getpwuid(posix_geteuid())['name'] . "\n";
echo "Current UID: " . posix_geteuid() . "\n";
echo "Current GID: " . posix_getegid() . "\n\n";

$paths = [
    '/tmp',
    '/tmp/phpborg_mounts',
    '/tmp/phpborg_mounts/5',
];

foreach ($paths as $path) {
    echo "Path: $path\n";
    echo "  Exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
    echo "  Is dir: " . (is_dir($path) ? 'YES' : 'NO') . "\n";
    echo "  Readable: " . (is_readable($path) ? 'YES' : 'NO') . "\n";
    echo "  Writable: " . (is_writable($path) ? 'YES' : 'NO') . "\n";

    if (is_dir($path)) {
        $perms = fileperms($path);
        echo "  Permissions: " . substr(sprintf('%o', $perms), -4) . "\n";
        $stat = stat($path);
        echo "  Owner UID: " . $stat['uid'] . "\n";
        echo "  Owner GID: " . $stat['gid'] . "\n";
    }
    echo "\n";
}

echo "\n=== Trying to scandir /tmp/phpborg_mounts/5 ===\n";
try {
    if (is_dir('/tmp/phpborg_mounts/5')) {
        $files = scandir('/tmp/phpborg_mounts/5');
        if ($files === false) {
            echo "ERROR: scandir returned false\n";
            echo "Last error: " . error_get_last()['message'] . "\n";
        } else {
            echo "Success! Found " . count($files) . " entries:\n";
            foreach (array_slice($files, 0, 20) as $file) {
                echo "  - $file\n";
            }
        }
    } else {
        echo "Path is not a directory!\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
