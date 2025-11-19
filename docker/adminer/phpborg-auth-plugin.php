<?php
/**
 * phpBorg Authentication Plugin for Adminer
 *
 * Validates session access via phpBorg API token
 * Allows passwordless login when valid token is provided
 */

class AdminerPhpBorgAuth
{
    private $phpborgApiUrl = 'http://127.0.0.1/api/instant-recovery/validate-admin';

    /**
     * Override login credentials
     * Called before authentication attempt
     */
    function credentials()
    {
        // Get token from query parameter
        $token = $_GET['phpborg_token'] ?? null;

        if (!$token) {
            return null;
        }

        // Validate token via phpBorg API
        if (!$this->validateToken($token)) {
            return null;
        }

        // Extract connection info from query params
        $server = $_GET['phpborg_server'] ?? '127.0.0.1';
        $username = $_GET['phpborg_username'] ?? 'postgres';
        $password = $_GET['phpborg_password'] ?? '';
        $database = $_GET['phpborg_database'] ?? '';

        // Return credentials (Adminer will use these)
        return [$server, $username, $password];
    }

    /**
     * Override login validation
     * Allow login without password check if token is valid
     */
    function login($login, $password)
    {
        $token = $_GET['phpborg_token'] ?? null;

        if (!$token) {
            return false;
        }

        // Token validation already done in credentials()
        // Just verify it's still present
        return $this->validateToken($token);
    }

    /**
     * Validate phpBorg token via API
     */
    private function validateToken($token)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['token' => $token]),
                'timeout' => 5
            ]
        ]);

        $response = @file_get_contents($this->phpborgApiUrl, false, $context);

        if ($response === false) {
            error_log('phpBorg token validation failed: Unable to reach API');
            return false;
        }

        $data = json_decode($response, true);

        return isset($data['success']) && $data['success'] === true;
    }

    /**
     * Custom login form message
     */
    function loginForm()
    {
        $token = $_GET['phpborg_token'] ?? null;

        if (!$token) {
            echo '<p class="error">Access denied: Missing phpBorg authentication token</p>';
            echo '<p class="message">This Adminer instance is managed by phpBorg Instant Recovery.</p>';
            echo '<p class="message">Please access it through phpBorg dashboard.</p>';
        }
    }
}

return new AdminerPhpBorgAuth;
