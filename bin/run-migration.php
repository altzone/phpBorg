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

    // Remove SQL comments but preserve JSON content
    $cleanSql = preg_replace('/^\s*--.*$/m', '', $sql);
    
    // Use mysqli multi_query for better handling of complex statements
    $mysqli = new mysqli(
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASSWORD'] ?? '',
        $_ENV['DB_NAME'] ?? 'phpborg'
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    
    // Execute the migration as a whole to preserve statement integrity
    if ($mysqli->multi_query($cleanSql)) {
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
        
        if ($mysqli->error) {
            throw new Exception("SQL Error: " . $mysqli->error);
        }
    } else {
        throw new Exception("Query failed: " . $mysqli->error);
    }
    
    $mysqli->close();

    echo "✓ Migration completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
