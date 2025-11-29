<?php

declare(strict_types=1);

namespace PhpBorg\Service;

use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\SettingRepository;

/**
 * SSL/TLS Certificate Management Service
 *
 * Supports:
 * - Self-signed certificates (development/internal use)
 * - Let's Encrypt (HTTP-01 and DNS-01 validation)
 * - Custom certificates (user-provided)
 * - Cloudflare DNS API for automatic DNS-01 validation
 */
final class SslService
{
    private const CERT_DIR = '/etc/phpborg/ssl';
    private const NGINX_CONF = '/etc/nginx/sites-available/phpborg';
    private const NGINX_ENABLED = '/etc/nginx/sites-enabled/phpborg';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get current SSL status and certificate info
     */
    public function getStatus(): array
    {
        $status = [
            'enabled' => false,
            'type' => 'none', // none, self-signed, letsencrypt, custom
            'domain' => null,
            'issuer' => null,
            'valid_from' => null,
            'valid_to' => null,
            'days_remaining' => null,
            'auto_renew' => false,
            'cloudflare_configured' => false,
        ];

        $certFile = self::CERT_DIR . '/cert.pem';

        if (!file_exists($certFile)) {
            return $status;
        }

        $certData = openssl_x509_parse(file_get_contents($certFile));

        if (!$certData) {
            return $status;
        }

        $status['enabled'] = true;
        $status['domain'] = $certData['subject']['CN'] ?? null;
        $status['issuer'] = $certData['issuer']['CN'] ?? $certData['issuer']['O'] ?? 'Unknown';
        $status['valid_from'] = date('Y-m-d H:i:s', $certData['validFrom_time_t']);
        $status['valid_to'] = date('Y-m-d H:i:s', $certData['validTo_time_t']);

        $daysRemaining = ($certData['validTo_time_t'] - time()) / 86400;
        $status['days_remaining'] = (int) $daysRemaining;

        // Expiry warning
        $status['expiry_warning'] = false;
        $status['expiry_critical'] = false;
        if ($daysRemaining <= 30) {
            $status['expiry_warning'] = true;
        }
        if ($daysRemaining <= 7) {
            $status['expiry_critical'] = true;
        }
        if ($daysRemaining <= 0) {
            $status['expired'] = true;
        }

        // Determine certificate type
        if (str_contains($status['issuer'], "Let's Encrypt") || str_contains($status['issuer'], 'R3') || str_contains($status['issuer'], 'R10')) {
            $status['type'] = 'letsencrypt';
            $status['auto_renew'] = file_exists('/etc/cron.d/phpborg-ssl-renew');
        } elseif ($status['issuer'] === $status['domain'] || str_contains($status['issuer'], 'phpBorg')) {
            $status['type'] = 'self-signed';
        } else {
            $status['type'] = 'custom';
        }

        // Check Cloudflare config
        $cfToken = $this->settings->get('ssl.cloudflare_api_token');
        $status['cloudflare_configured'] = !empty($cfToken);

        return $status;
    }

    /**
     * Generate a self-signed certificate
     */
    public function generateSelfSigned(string $domain, int $days = 365): array
    {
        $this->logger->info("Generating self-signed certificate for {$domain}", 'SSL');

        $this->ensureCertDir();

        $keyFile = self::CERT_DIR . '/privkey.pem';
        $certFile = self::CERT_DIR . '/cert.pem';

        // Generate private key
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (!$privateKey) {
            throw new \Exception('Failed to generate private key: ' . openssl_error_string());
        }

        // Generate CSR
        $dn = [
            'commonName' => $domain,
            'organizationName' => 'phpBorg',
            'organizationalUnitName' => 'Self-Signed Certificate',
        ];

        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);

        if (!$csr) {
            throw new \Exception('Failed to generate CSR: ' . openssl_error_string());
        }

        // Sign certificate
        $cert = openssl_csr_sign($csr, null, $privateKey, $days, ['digest_alg' => 'sha256']);

        if (!$cert) {
            throw new \Exception('Failed to sign certificate: ' . openssl_error_string());
        }

        // Export key and certificate
        openssl_pkey_export_to_file($privateKey, $keyFile);
        openssl_x509_export_to_file($cert, $certFile);

        // Set permissions
        chmod($keyFile, 0600);
        chmod($certFile, 0644);

        // Save settings
        $this->settings->set('ssl.type', 'self-signed');
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('ssl.enabled', '1');

        // Update nginx config
        $this->updateNginxConfig($domain);

        $this->logger->info("Self-signed certificate generated successfully", 'SSL');

        return [
            'success' => true,
            'type' => 'self-signed',
            'domain' => $domain,
            'valid_days' => $days,
        ];
    }

    /**
     * Request Let's Encrypt certificate using HTTP-01 challenge
     */
    public function requestLetsEncryptHttp(string $domain, string $email): array
    {
        $this->logger->info("Requesting Let's Encrypt certificate for {$domain} via HTTP-01", 'SSL');

        // Check if certbot is installed
        if (!$this->commandExists('certbot')) {
            throw new \Exception("certbot is not installed. Install it with: apt install certbot python3-certbot-nginx");
        }

        $this->ensureCertDir();

        // Stop nginx temporarily or use webroot
        $webroot = '/var/www/html';
        if (!is_dir($webroot)) {
            mkdir($webroot, 0755, true);
        }

        // Run certbot with webroot plugin
        $cmd = sprintf(
            'certbot certonly --webroot -w %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
            escapeshellarg($webroot),
            escapeshellarg($domain),
            escapeshellarg($email)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error("Let's Encrypt HTTP-01 failed", 'SSL', ['output' => implode("\n", $output)]);
            throw new \Exception("Let's Encrypt failed: " . implode("\n", $output));
        }

        // Copy certificates to our directory
        $this->copyLetsEncryptCerts();

        // Save settings
        $this->settings->set('ssl.type', 'letsencrypt');
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('ssl.email', $email);
        $this->settings->set('ssl.validation', 'http-01');
        $this->settings->set('ssl.enabled', '1');

        // Setup auto-renewal cron
        $this->setupAutoRenewal();

        // Update nginx config
        $this->updateNginxConfig($domain);

        $this->logger->info("Let's Encrypt certificate obtained successfully", 'SSL');

        return [
            'success' => true,
            'type' => 'letsencrypt',
            'validation' => 'http-01',
            'domain' => $domain,
        ];
    }

    /**
     * Request Let's Encrypt certificate using DNS-01 challenge with Cloudflare
     */
    public function requestLetsEncryptDnsCloudflare(string $domain, string $email, string $cfApiToken): array
    {
        $this->logger->info("Requesting Let's Encrypt certificate for {$domain} via DNS-01 (Cloudflare)", 'SSL');

        // Check if certbot is installed
        if (!$this->commandExists('certbot')) {
            throw new \Exception("certbot is not installed");
        }

        // Check for Cloudflare plugin
        if (!$this->commandExists('certbot') || !file_exists('/usr/lib/python3/dist-packages/certbot_dns_cloudflare')) {
            // Try to install it
            exec('apt-get install -y python3-certbot-dns-cloudflare 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("python3-certbot-dns-cloudflare is not installed");
            }
        }

        $this->ensureCertDir();

        // Create Cloudflare credentials file
        $cfCredsFile = self::CERT_DIR . '/cloudflare.ini';
        file_put_contents($cfCredsFile, "dns_cloudflare_api_token = {$cfApiToken}\n");
        chmod($cfCredsFile, 0600);

        // Run certbot with Cloudflare DNS plugin
        $cmd = sprintf(
            'certbot certonly --dns-cloudflare --dns-cloudflare-credentials %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
            escapeshellarg($cfCredsFile),
            escapeshellarg($domain),
            escapeshellarg($email)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error("Let's Encrypt DNS-01 (Cloudflare) failed", 'SSL', ['output' => implode("\n", $output)]);
            throw new \Exception("Let's Encrypt failed: " . implode("\n", $output));
        }

        // Copy certificates to our directory
        $this->copyLetsEncryptCerts();

        // Save settings
        $this->settings->set('ssl.type', 'letsencrypt');
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('ssl.email', $email);
        $this->settings->set('ssl.validation', 'dns-cloudflare');
        $this->settings->set('ssl.cloudflare_api_token', $cfApiToken);
        $this->settings->set('ssl.enabled', '1');

        // Setup auto-renewal cron
        $this->setupAutoRenewal();

        // Update nginx config
        $this->updateNginxConfig($domain);

        $this->logger->info("Let's Encrypt certificate obtained successfully via Cloudflare DNS", 'SSL');

        return [
            'success' => true,
            'type' => 'letsencrypt',
            'validation' => 'dns-cloudflare',
            'domain' => $domain,
        ];
    }

    /**
     * Get DNS-01 challenge for manual DNS validation
     */
    public function getDnsChallenge(string $domain, string $email): array
    {
        $this->logger->info("Getting DNS-01 challenge for {$domain}", 'SSL');

        if (!$this->commandExists('certbot')) {
            throw new \Exception("certbot is not installed");
        }

        // Run certbot in manual mode to get the challenge
        $cmd = sprintf(
            'certbot certonly --manual --preferred-challenges dns -d %s --email %s --agree-tos --manual-public-ip-logging-ok --dry-run 2>&1',
            escapeshellarg($domain),
            escapeshellarg($email)
        );

        // This is tricky - certbot needs interaction for manual mode
        // We'll provide instructions instead

        return [
            'success' => true,
            'type' => 'dns-manual',
            'domain' => $domain,
            'instructions' => [
                '1. Create a TXT record for _acme-challenge.' . $domain,
                '2. Run the following command on the server:',
                "   certbot certonly --manual --preferred-challenges dns -d {$domain} --email {$email} --agree-tos",
                '3. Follow the interactive prompts',
                '4. After certificate is issued, click "Verify" in phpBorg',
            ],
            'record_name' => '_acme-challenge.' . $domain,
            'record_type' => 'TXT',
        ];
    }

    /**
     * Upload custom certificate
     */
    public function uploadCertificate(string $cert, string $key, ?string $chain = null): array
    {
        $this->logger->info("Uploading custom certificate", 'SSL');

        // Validate certificate
        $certData = openssl_x509_parse($cert);
        if (!$certData) {
            throw new \Exception("Invalid certificate format");
        }

        // Validate private key
        $keyResource = openssl_pkey_get_private($key);
        if (!$keyResource) {
            throw new \Exception("Invalid private key format");
        }

        // Verify key matches certificate
        if (!openssl_x509_check_private_key($cert, $keyResource)) {
            throw new \Exception("Private key does not match certificate");
        }

        $this->ensureCertDir();

        // Save files
        $certFile = self::CERT_DIR . '/cert.pem';
        $keyFile = self::CERT_DIR . '/privkey.pem';
        $chainFile = self::CERT_DIR . '/chain.pem';
        $fullchainFile = self::CERT_DIR . '/fullchain.pem';

        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);

        if ($chain) {
            file_put_contents($chainFile, $chain);
            file_put_contents($fullchainFile, $cert . "\n" . $chain);
            file_put_contents($certFile, $cert);
        } else {
            file_put_contents($certFile, $cert);
            file_put_contents($fullchainFile, $cert);
        }

        chmod($certFile, 0644);
        chmod($fullchainFile, 0644);

        $domain = $certData['subject']['CN'] ?? 'unknown';

        // Save settings
        $this->settings->set('ssl.type', 'custom');
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('ssl.enabled', '1');

        // Update nginx config
        $this->updateNginxConfig($domain);

        $this->logger->info("Custom certificate uploaded successfully", 'SSL');

        return [
            'success' => true,
            'type' => 'custom',
            'domain' => $domain,
            'issuer' => $certData['issuer']['CN'] ?? $certData['issuer']['O'] ?? 'Unknown',
            'valid_to' => date('Y-m-d H:i:s', $certData['validTo_time_t']),
        ];
    }

    /**
     * Disable SSL and revert to HTTP
     */
    public function disable(): array
    {
        $this->logger->info("Disabling SSL", 'SSL');

        $this->settings->set('ssl.enabled', '0');

        // Update nginx to HTTP only
        $this->updateNginxConfig(null, false);

        // Remove auto-renewal cron
        @unlink('/etc/cron.d/phpborg-ssl-renew');

        return ['success' => true];
    }

    /**
     * Renew Let's Encrypt certificate
     */
    public function renewCertificate(): array
    {
        $this->logger->info("Renewing Let's Encrypt certificate", 'SSL');

        $type = $this->settings->get('ssl.type');

        if ($type !== 'letsencrypt') {
            throw new \Exception("Auto-renewal is only available for Let's Encrypt certificates");
        }

        exec('certbot renew --cert-name phpborg 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error("Certificate renewal failed", 'SSL', ['output' => implode("\n", $output)]);
            throw new \Exception("Renewal failed: " . implode("\n", $output));
        }

        // Copy renewed certs
        $this->copyLetsEncryptCerts();

        // Reload nginx
        exec('systemctl reload nginx 2>&1');

        $this->logger->info("Certificate renewed successfully", 'SSL');

        return ['success' => true];
    }

    /**
     * Test renewal with dry-run (doesn't actually renew)
     */
    public function testRenewal(): array
    {
        $this->logger->info("Testing certificate renewal (dry-run)", 'SSL');

        $type = $this->settings->get('ssl.type');

        if ($type !== 'letsencrypt') {
            throw new \Exception("Renewal test is only available for Let's Encrypt certificates");
        }

        exec('certbot renew --cert-name phpborg --dry-run 2>&1', $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            $this->logger->warning("Renewal dry-run failed", 'SSL', ['output' => $outputStr]);
            return [
                'success' => false,
                'error' => 'Dry-run failed',
                'output' => $outputStr,
            ];
        }

        // Check if test was successful
        $testPassed = str_contains($outputStr, 'Congratulations') ||
                      str_contains($outputStr, 'successfully') ||
                      str_contains($outputStr, 'simulating renewal');

        return [
            'success' => $testPassed,
            'output' => $outputStr,
        ];
    }

    /**
     * Check auto-renewal service status
     */
    public function checkAutoRenewalStatus(): array
    {
        $status = [
            'cron_exists' => false,
            'certbot_timer_active' => false,
            'last_renewal_check' => null,
            'next_renewal' => null,
        ];

        // Check our cron file
        $status['cron_exists'] = file_exists('/etc/cron.d/phpborg-ssl-renew');

        // Check certbot systemd timer (if exists)
        exec('systemctl is-active certbot.timer 2>&1', $output, $exitCode);
        $status['certbot_timer_active'] = ($exitCode === 0);

        // Get next renewal date from certbot
        exec('certbot certificates --cert-name phpborg 2>&1', $output, $exitCode);
        $outputStr = implode("\n", $output);

        if (preg_match('/Expiry Date:\s*(\d{4}-\d{2}-\d{2})/', $outputStr, $matches)) {
            $expiryDate = new \DateTime($matches[1]);
            $renewDate = clone $expiryDate;
            $renewDate->modify('-30 days'); // Let's Encrypt renews 30 days before expiry
            $status['next_renewal'] = $renewDate->format('Y-m-d');
        }

        // Check last cron run (from syslog if available)
        exec('grep "certbot" /var/log/syslog 2>/dev/null | tail -1', $syslogOutput);
        if (!empty($syslogOutput)) {
            $status['last_renewal_check'] = trim($syslogOutput[0] ?? '');
        }

        return $status;
    }

    /**
     * Force setup auto-renewal (useful if cron was deleted)
     */
    public function ensureAutoRenewal(): array
    {
        $type = $this->settings->get('ssl.type');

        if ($type !== 'letsencrypt') {
            return ['success' => false, 'error' => 'Not a Let\'s Encrypt certificate'];
        }

        $this->setupAutoRenewal();

        return [
            'success' => true,
            'message' => 'Auto-renewal cron configured',
        ];
    }

    /**
     * Test Cloudflare API token
     */
    public function testCloudflareToken(string $token): array
    {
        $ch = curl_init('https://api.cloudflare.com/client/v4/user/tokens/verify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Invalid API token'];
        }

        $data = json_decode($response, true);

        if (!($data['success'] ?? false)) {
            return ['success' => false, 'error' => $data['errors'][0]['message'] ?? 'Unknown error'];
        }

        return [
            'success' => true,
            'status' => $data['result']['status'] ?? 'unknown',
        ];
    }

    /**
     * Get list of Cloudflare zones (domains)
     */
    public function getCloudflareZones(string $token): array
    {
        $ch = curl_init('https://api.cloudflare.com/client/v4/zones?per_page=50');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!($data['success'] ?? false)) {
            throw new \Exception($data['errors'][0]['message'] ?? 'Failed to fetch zones');
        }

        $zones = [];
        foreach ($data['result'] as $zone) {
            $zones[] = [
                'id' => $zone['id'],
                'name' => $zone['name'],
                'status' => $zone['status'],
            ];
        }

        return $zones;
    }

    // ==========================================
    // Private helper methods
    // ==========================================

    private function ensureCertDir(): void
    {
        if (!is_dir(self::CERT_DIR)) {
            mkdir(self::CERT_DIR, 0755, true);
        }
    }

    private function commandExists(string $cmd): bool
    {
        exec("which {$cmd} 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }

    private function copyLetsEncryptCerts(): void
    {
        $letsencryptDir = '/etc/letsencrypt/live/phpborg';

        if (!is_dir($letsencryptDir)) {
            throw new \Exception("Let's Encrypt certificates not found");
        }

        $this->ensureCertDir();

        // Copy certificates (follow symlinks)
        copy($letsencryptDir . '/privkey.pem', self::CERT_DIR . '/privkey.pem');
        copy($letsencryptDir . '/cert.pem', self::CERT_DIR . '/cert.pem');
        copy($letsencryptDir . '/chain.pem', self::CERT_DIR . '/chain.pem');
        copy($letsencryptDir . '/fullchain.pem', self::CERT_DIR . '/fullchain.pem');

        chmod(self::CERT_DIR . '/privkey.pem', 0600);
        chmod(self::CERT_DIR . '/cert.pem', 0644);
        chmod(self::CERT_DIR . '/chain.pem', 0644);
        chmod(self::CERT_DIR . '/fullchain.pem', 0644);
    }

    private function setupAutoRenewal(): void
    {
        $cronContent = <<<'CRON'
# phpBorg SSL certificate auto-renewal
0 3 * * * root certbot renew --cert-name phpborg --quiet --deploy-hook "systemctl reload nginx"
CRON;

        file_put_contents('/etc/cron.d/phpborg-ssl-renew', $cronContent . "\n");
        chmod('/etc/cron.d/phpborg-ssl-renew', 0644);
    }

    private function updateNginxConfig(string $domain = null, bool $enableSsl = true): void
    {
        $phpborgRoot = dirname(__DIR__, 2);

        if ($enableSsl && $domain) {
            $template = $this->getNginxSslTemplate($domain, $phpborgRoot);
        } else {
            $template = $this->getNginxHttpTemplate($phpborgRoot);
        }

        // Backup current config
        if (file_exists(self::NGINX_CONF)) {
            copy(self::NGINX_CONF, self::NGINX_CONF . '.bak');
        }

        file_put_contents(self::NGINX_CONF, $template);

        // Ensure symlink exists
        if (!file_exists(self::NGINX_ENABLED)) {
            symlink(self::NGINX_CONF, self::NGINX_ENABLED);
        }

        // Test nginx config
        exec('nginx -t 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            // Restore backup
            if (file_exists(self::NGINX_CONF . '.bak')) {
                copy(self::NGINX_CONF . '.bak', self::NGINX_CONF);
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $output));
        }

        // Reload nginx
        exec('systemctl reload nginx 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("Failed to reload nginx", 'SSL', ['output' => implode("\n", $output)]);
        }
    }

    private function getNginxSslTemplate(string $domain, string $phpborgRoot): string
    {
        $certDir = self::CERT_DIR;

        return <<<NGINX
# phpBorg Nginx Configuration (SSL)
# Generated by phpBorg SSL Service

# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};

    # Allow Let's Encrypt HTTP-01 challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

# HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$domain};

    # SSL certificates
    ssl_certificate {$certDir}/fullchain.pem;
    ssl_certificate_key {$certDir}/privkey.pem;

    # SSL settings
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;

    # HSTS (uncomment for production)
    # add_header Strict-Transport-Security "max-age=63072000" always;

    root {$phpborgRoot}/public;
    index index.php index.html;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    # API routes
    location /api {
        try_files \$uri /api/index.php\$is_args\$args;
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Frontend (Vue.js SPA)
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
}
NGINX;
    }

    private function getNginxHttpTemplate(string $phpborgRoot): string
    {
        return <<<NGINX
# phpBorg Nginx Configuration (HTTP)
# Generated by phpBorg SSL Service

server {
    listen 80;
    listen [::]:80;
    server_name _;

    root {$phpborgRoot}/public;
    index index.php index.html;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    # API routes
    location /api {
        try_files \$uri /api/index.php\$is_args\$args;
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Frontend (Vue.js SPA)
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # Let's Encrypt challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
}
NGINX;
    }
}
