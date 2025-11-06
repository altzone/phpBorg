<?php

declare(strict_types=1);

namespace PhpBorg\Api\Middleware;

/**
 * CORS Middleware for API
 * Allows requests from frontend (Vue.js)
 */
final class CorsMiddleware
{
    /**
     * Handle CORS preflight and set CORS headers
     */
    public static function handle(): void
    {
        // Allow from any origin (adjust for production)
        // In production, set specific origins like: http://localhost:5173, https://your-domain.com
        $allowedOrigins = [
            'http://localhost:5173', // Vite dev server
            'http://localhost:3000', // Alternative dev port
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
        ];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

        // Handle OPTIONS request (preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}
