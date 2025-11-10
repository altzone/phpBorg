#!/usr/bin/env php
<?php

// Quick script to create a test JWT token

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

// Load configuration
$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$secret = $_ENV['APP_SECRET'] ?? throw new Exception('APP_SECRET not found in .env');

$now = new DateTimeImmutable();
$exp = $now->getTimestamp() + 3600; // 1 hour

$payload = [
    'iss' => 'phpborg',
    'aud' => 'phpborg-api',
    'iat' => $now->getTimestamp(),
    'exp' => $exp,
    'sub' => 1,
    'username' => 'admin',
    'roles' => ['ROLE_ADMIN', 'ROLE_USER']
];

$token = JWT::encode($payload, $secret, 'HS256');

echo "Test JWT Token:\n";
echo $token . "\n\n";
echo "Use it with: curl -H \"Authorization: Bearer $token\" ...\n";

// Save to file for easy reuse
file_put_contents('/tmp/test-token.txt', $token);
echo "Token saved to /tmp/test-token.txt\n";