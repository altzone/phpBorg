<?php
/**
 * phpBorg Authentication Plugin for Adminer
 *
 * Validates session access via phpBorg API token
 * Auto-connects to database without showing login form
 */

class AdminerPhpBorgAuth
{
    private $phpborgApiUrl = 'http://host.docker.internal/api/instant-recovery/validate-admin';
    private $tokenValid = null;

    public function __construct()
    {
        // Start session to persist authentication
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Override login credentials
     * Pre-fill connection details from session or URL params
     */
    function credentials()
    {
        // If authenticated via session, use stored credentials
        if (!empty($_SESSION['phpborg_authenticated'])) {
            $server = $_SESSION['phpborg_server'] ?? 'host.docker.internal:5432';
            $username = $_SESSION['phpborg_username'] ?? 'postgres';
            return [$server, $username, ''];
        }

        // Otherwise check token
        $token = $_GET['phpborg_token'] ?? null;

        if (!$token || !$this->validateToken($token)) {
            return null;
        }

        // Extract connection info from query params
        $server = $_GET['phpborg_server'] ?? 'host.docker.internal:5432';
        $username = $_GET['phpborg_username'] ?? 'postgres';
        $password = '';

        // Replace 127.0.0.1 with host.docker.internal for Docker container access
        $server = str_replace('127.0.0.1', 'host.docker.internal', $server);

        // Return credentials array: [server, username, password]
        return [$server, $username, $password];
    }

    /**
     * Override login validation
     * Allow passwordless login if token is valid OR session authenticated
     */
    function login($login, $password)
    {
        // Check if already authenticated via session
        if (!empty($_SESSION['phpborg_authenticated'])) {
            return true;
        }

        // Otherwise validate token
        $token = $_GET['phpborg_token'] ?? null;

        if (!$token) {
            return false;
        }

        // Always allow login if token is valid
        return $this->validateToken($token);
    }

    /**
     * Override database selection
     * Auto-select database from session or URL param
     */
    function database()
    {
        // Use session if authenticated
        if (!empty($_SESSION['phpborg_authenticated'])) {
            return $_SESSION['phpborg_database'] ?? null;
        }

        return $_GET['phpborg_database'] ?? null;
    }

    /**
     * Hide login form and auto-connect
     */
    function loginForm()
    {
        // If already authenticated via session, show simple form that auto-submits
        if (!empty($_SESSION['phpborg_authenticated'])) {
            // Get driver from session
            $server = $_SESSION['phpborg_server'] ?? 'host.docker.internal:5432';
            $username = $_SESSION['phpborg_username'] ?? 'postgres';
            $database = $_SESSION['phpborg_database'] ?? '';
            $driver = $this->detectDriver($server);

            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Connecting...</title>
                <style>
                    body {
                        font-family: sans-serif;
                        text-align: center;
                        padding: 50px;
                        background: #f5f5f5;
                    }
                    .message {
                        font-size: 1.2em;
                        margin-bottom: 20px;
                        color: #333;
                    }
                    .button {
                        padding: 12px 24px;
                        font-size: 16px;
                        cursor: pointer;
                        background: #007bff;
                        color: white;
                        border: none;
                        border-radius: 4px;
                        margin-top: 20px;
                    }
                    .button:hover {
                        background: #0056b3;
                    }
                </style>
            </head>
            <body>
                <p class="message">🔐 <strong>Connecting to database...</strong></p>
                <p style="color: #666;">Click the button below to connect</p>

                <form method="post" action="">
                    <input type="hidden" name="auth[driver]" value="<?php echo htmlspecialchars($driver); ?>">
                    <input type="hidden" name="auth[server]" value="<?php echo htmlspecialchars($server); ?>">
                    <input type="hidden" name="auth[username]" value="<?php echo htmlspecialchars($username); ?>">
                    <input type="hidden" name="auth[password]" value="">
                    <input type="hidden" name="auth[db]" value="<?php echo htmlspecialchars($database); ?>">
                    <button type="submit" class="button">Connect to Database</button>
                </form>
            </body>
            </html>
            <?php
            exit;
        }

        $token = $_GET['phpborg_token'] ?? null;

        if (!$token) {
            echo '<p class="error">❌ Access Denied: Missing phpBorg Token</p>';
            echo '<p class="message">This Adminer instance is managed by <strong>phpBorg Instant Recovery</strong>.</p>';
            echo '<p class="message">Please access it through the phpBorg dashboard.</p>';
            return;
        }

        // Validate token
        if (!$this->validateToken($token)) {
            echo '<p class="error">❌ Invalid or Expired Token</p>';
            echo '<p class="message">Please start a new Instant Recovery session from phpBorg.</p>';
            return;
        }

        // Store authentication and credentials in session
        $_SESSION['phpborg_authenticated'] = true;
        $_SESSION['phpborg_auth_time'] = time();

        // Auto-submit login form with credentials from URL
        $server = $_GET['phpborg_server'] ?? 'host.docker.internal:5432';
        $username = $_GET['phpborg_username'] ?? 'postgres';
        $database = $_GET['phpborg_database'] ?? '';

        // Replace 127.0.0.1 with host.docker.internal for Docker container access
        $server = str_replace('127.0.0.1', 'host.docker.internal', $server);

        // Store credentials in session for post-redirect access
        $_SESSION['phpborg_server'] = $server;
        $_SESSION['phpborg_username'] = $username;
        $_SESSION['phpborg_database'] = $database;

        $driver = $this->detectDriver($server);

        // Auto-redirect to Adminer with credentials in URL
        // Don't include phpborg_token to avoid redirect loop
        $redirectUrl = '/?' . http_build_query([
            $driver => $server,
            'username' => $username,
            'db' => $database,
        ]);

        // Immediate PHP redirect (no HTML output)
        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Detect database driver from server string
     */
    private function detectDriver($server)
    {
        // Check port to detect driver
        if (strpos($server, ':5432') !== false || strpos($server, ':15432') !== false) {
            return 'pgsql';
        } elseif (strpos($server, ':3306') !== false || strpos($server, ':13306') !== false) {
            return 'server'; // MySQL
        } elseif (strpos($server, ':27017') !== false) {
            return 'mongo';
        }

        // Default to PostgreSQL
        return 'pgsql';
    }

    /**
     * Validate phpBorg token via API
     */
    private function validateToken($token)
    {
        // Cache validation result
        if ($this->tokenValid !== null) {
            return $this->tokenValid;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['token' => $token]),
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($this->phpborgApiUrl, false, $context);

        if ($response === false) {
            error_log('[phpBorg Adminer] Token validation failed: Unable to reach API at ' . $this->phpborgApiUrl);
            $this->tokenValid = false;
            return false;
        }

        $data = json_decode($response, true);

        $this->tokenValid = isset($data['success']) && $data['success'] === true;
        return $this->tokenValid;
    }

    /**
     * Hide permanent login checkbox (read-only session)
     */
    function permanentLogin($create = false)
    {
        return false; // Disable permanent login
    }
}

return new AdminerPhpBorgAuth;
