<?php
/**
 * phpBorg Authentication Plugin for Adminer
 *
 * Validates session access via phpBorg API token
 * Auto-connects to database without showing login form
 */

class AdminerPhpBorgAuth
{
    private $phpborgApiUrl = 'http://host.docker.internal:8080/api/instant-recovery/validate-admin';
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
        // If already authenticated via session, auto-submit the form
        if (!empty($_SESSION['phpborg_authenticated'])) {
            // Get Adminer's CSP nonce if available
            $nonce = '';
            if (function_exists('get_nonce')) {
                $nonce = ' nonce="' . get_nonce() . '"';
            }
            ?>
            <script<?php echo $nonce; ?>>
            // Auto-submit login form when page loads
            (function() {
                var submitted = false;
                function trySubmit() {
                    if (submitted) return;
                    var form = document.querySelector('form[action]');
                    if (form) {
                        submitted = true;
                        form.submit();
                    } else {
                        // Try again after a short delay
                        setTimeout(trySubmit, 100);
                    }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', trySubmit);
                } else {
                    trySubmit();
                }
            })();
            </script>
            <p class="message">🔐 <strong>Connecting to database...</strong></p>
            <?php
            return;
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
