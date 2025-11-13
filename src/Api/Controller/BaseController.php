<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

/**
 * Base Controller for API endpoints
 */
abstract class BaseController
{
    /**
     * Send JSON response
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Send success response
     */
    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Send error response
     */
    protected function error(string $message, int $statusCode = 400, ?string $code = null, ?array $data = null): void
    {
        $errorResponse = [
            'message' => $message,
            'code' => $code,
        ];

        if ($data !== null) {
            $errorResponse['data'] = $data;
        }

        $this->json([
            'success' => false,
            'error' => $errorResponse,
        ], $statusCode);
    }

    /**
     * Get JSON body from request
     */
    protected function getJsonBody(): array
    {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return [];
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON body', 400);
            exit;
        }

        return $data ?? [];
    }

    /**
     * Get authorization header token
     */
    protected function getBearerToken(): ?string
    {
        $headers = $this->getAuthorizationHeader();

        if ($headers !== null && preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get authorization header
     */
    private function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }

        return null;
    }

    /**
     * Validate required fields in request
     */
    protected function validateRequired(array $data, array $requiredFields): void
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $this->error(
                'Missing required fields: ' . implode(', ', $missing),
                400,
                'MISSING_FIELDS'
            );
            exit;
        }
    }
}
