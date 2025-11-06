#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpBorg\Application;

if ($argc < 2) {
    echo "Usage: php bin/run-migration.php <migration-file>\n";
    exit(1);
}

$migrationFile = $argv[1];

if (!file_exists($migrationFile)) {
    echo "Error: Migration file not found: $migrationFile\n";
    exit(1);
}

echo "Running migration: $migrationFile\n";

try {
    $app = new Application();
    $connection = $app->getConnection();

    // Read SQL file
    $sql = file_get_contents($migrationFile);

    // Split by semicolon to handle multiple statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && !preg_match('/^\s*--/', $s)
    );

    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;

        echo "Executing: " . substr($statement, 0, 80) . "...\n";
        $connection->execute($statement);
    }

    echo "✓ Migration completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
