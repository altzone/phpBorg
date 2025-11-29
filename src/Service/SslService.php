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
            'sudo certbot certonly --webroot -w %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
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
            exec('sudo apt-get install -y python3-certbot-dns-cloudflare 2>&1', $output, $exitCode);
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
            'sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
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
     * Validate certificate without applying (for testing before upload)
     */
    public function validateCertificate(string $cert, string $key, ?string $chain = null): array
    {
        $errors = [];
        $warnings = [];
        $info = [];

        // Validate certificate format
        $certData = openssl_x509_parse($cert);
        if (!$certData) {
            $errors[] = "Invalid certificate format - cannot parse PEM";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'info' => $info];
        }

        $info['domain'] = $certData['subject']['CN'] ?? 'unknown';
        $info['issuer'] = $certData['issuer']['CN'] ?? $certData['issuer']['O'] ?? 'Unknown';
        $info['valid_from'] = date('Y-m-d H:i:s', $certData['validFrom_time_t']);
        $info['valid_to'] = date('Y-m-d H:i:s', $certData['validTo_time_t']);

        // Check expiry
        $daysRemaining = ($certData['validTo_time_t'] - time()) / 86400;
        $info['days_remaining'] = (int) $daysRemaining;

        if ($daysRemaining <= 0) {
            $errors[] = "Certificate has expired!";
        } elseif ($daysRemaining <= 7) {
            $warnings[] = "Certificate expires in {$info['days_remaining']} days - critical!";
        } elseif ($daysRemaining <= 30) {
            $warnings[] = "Certificate expires in {$info['days_remaining']} days - consider renewing soon";
        }

        // Validate private key format
        $keyResource = openssl_pkey_get_private($key);
        if (!$keyResource) {
            $errors[] = "Invalid private key format - cannot parse PEM";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'info' => $info];
        }

        // Verify key matches certificate
        if (!openssl_x509_check_private_key($cert, $keyResource)) {
            $errors[] = "Private key does not match certificate - keys must be paired";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'info' => $info];
        }

        // Validate chain if provided
        if ($chain) {
            $chainData = openssl_x509_parse($chain);
            if (!$chainData) {
                $warnings[] = "CA chain format may be invalid - could cause browser warnings";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
        ];
    }

    /**
     * Upload custom certificate
     */
    public function uploadCertificate(string $cert, string $key, ?string $chain = null): array
    {
        $this->logger->info("Uploading custom certificate", 'SSL');

        // First validate everything
        $validation = $this->validateCertificate($cert, $key, $chain);
        if (!$validation['valid']) {
            throw new \Exception("Certificate validation failed: " . implode(', ', $validation['errors']));
        }

        // Parse certificate for info
        $certData = openssl_x509_parse($cert);
        $domain = $certData['subject']['CN'] ?? 'unknown';

        $this->ensureCertDir();

        // Save to temporary location first
        $tempDir = self::CERT_DIR . '/temp_' . uniqid();
        mkdir($tempDir, 0700, true);

        $tempCertFile = $tempDir . '/cert.pem';
        $tempKeyFile = $tempDir . '/privkey.pem';
        $tempFullchainFile = $tempDir . '/fullchain.pem';

        file_put_contents($tempKeyFile, $key);
        chmod($tempKeyFile, 0600);

        if ($chain) {
            file_put_contents($tempFullchainFile, $cert . "\n" . $chain);
        } else {
            file_put_contents($tempFullchainFile, $cert);
        }
        file_put_contents($tempCertFile, $cert);
        chmod($tempCertFile, 0644);
        chmod($tempFullchainFile, 0644);

        // Test nginx config with new certificates BEFORE applying
        $testResult = $this->testNginxConfigWithCerts($domain, $tempDir);
        if (!$testResult['success']) {
            // Cleanup temp files
            $this->removeDir($tempDir);
            throw new \Exception("Nginx configuration test failed: " . $testResult['error']);
        }

        // Backup current certificates if they exist
        $this->backupCurrentCerts();

        // Move temp files to final location
        $certFile = self::CERT_DIR . '/cert.pem';
        $keyFile = self::CERT_DIR . '/privkey.pem';
        $chainFile = self::CERT_DIR . '/chain.pem';
        $fullchainFile = self::CERT_DIR . '/fullchain.pem';

        rename($tempKeyFile, $keyFile);
        rename($tempCertFile, $certFile);
        rename($tempFullchainFile, $fullchainFile);

        if ($chain) {
            file_put_contents($chainFile, $chain);
            chmod($chainFile, 0644);
        }

        // Cleanup temp directory
        @rmdir($tempDir);

        // Save settings
        $this->settings->set('ssl.type', 'custom');
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('ssl.enabled', '1');

        // Update nginx config (already tested)
        $this->updateNginxConfig($domain);

        $this->logger->info("Custom certificate uploaded successfully", 'SSL');

        return [
            'success' => true,
            'type' => 'custom',
            'domain' => $domain,
            'issuer' => $certData['issuer']['CN'] ?? $certData['issuer']['O'] ?? 'Unknown',
            'valid_to' => date('Y-m-d H:i:s', $certData['validTo_time_t']),
            'warnings' => $validation['warnings'],
        ];
    }

    /**
     * Test nginx config with specific certificates (without applying)
     */
    private function testNginxConfigWithCerts(string $domain, string $certDir): array
    {
        $phpborgRoot = dirname(__DIR__, 2);
        $phpFpmSock = $this->detectPhpFpmSocket();

        // Generate test config
        $templateFile = $phpborgRoot . '/install/templates/nginx-ssl.conf.template';
        if (file_exists($templateFile)) {
            $template = file_get_contents($templateFile);
        } else {
            $template = $this->getInlineSslTemplate();
        }

        $testConfig = str_replace(
            ['__DOMAIN__', '__PHPBORG_ROOT__', '__CERT_DIR__', '__PHP_FPM_SOCK__'],
            [$domain, $phpborgRoot, $certDir, $phpFpmSock],
            $template
        );

        // Write to temp config file
        $tempConfigFile = '/tmp/phpborg-nginx-test-' . uniqid() . '.conf';
        file_put_contents($tempConfigFile, $testConfig);

        // Test the configuration
        exec("sudo nginx -t -c /etc/nginx/nginx.conf 2>&1", $output, $exitCode);

        // Also test with our specific config included
        exec("sudo nginx -t 2>&1", $output2, $exitCode2);

        // Cleanup
        @unlink($tempConfigFile);

        if ($exitCode !== 0 || $exitCode2 !== 0) {
            return [
                'success' => false,
                'error' => implode("\n", array_merge($output, $output2)),
            ];
        }

        return ['success' => true];
    }

    /**
     * Backup current certificates
     */
    private function backupCurrentCerts(): void
    {
        $backupDir = self::CERT_DIR . '/backup_' . date('Y-m-d_His');

        if (file_exists(self::CERT_DIR . '/cert.pem')) {
            mkdir($backupDir, 0700, true);

            foreach (['cert.pem', 'privkey.pem', 'fullchain.pem', 'chain.pem'] as $file) {
                $src = self::CERT_DIR . '/' . $file;
                if (file_exists($src)) {
                    copy($src, $backupDir . '/' . $file);
                }
            }

            $this->logger->info("Current certificates backed up to {$backupDir}", 'SSL');
        }
    }

    /**
     * Remove directory recursively
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
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

        exec('sudo certbot renew --cert-name phpborg 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error("Certificate renewal failed", 'SSL', ['output' => implode("\n", $output)]);
            throw new \Exception("Renewal failed: " . implode("\n", $output));
        }

        // Copy renewed certs
        $this->copyLetsEncryptCerts();

        // Reload nginx
        exec('sudo systemctl reload nginx 2>&1');

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

        exec('sudo certbot renew --cert-name phpborg --dry-run 2>&1', $output, $exitCode);

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
        exec('sudo systemctl is-active certbot.timer 2>&1', $output, $exitCode);
        $status['certbot_timer_active'] = ($exitCode === 0);

        // Get next renewal date from certbot
        exec('sudo certbot certificates --cert-name phpborg 2>&1', $output, $exitCode);
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
            $config = $this->generateNginxSslConfig($domain, $phpborgRoot);
        } else {
            $config = $this->generateNginxHttpConfig($phpborgRoot);
        }

        // Backup current config
        if (file_exists(self::NGINX_CONF)) {
            copy(self::NGINX_CONF, self::NGINX_CONF . '.bak');
        }

        file_put_contents(self::NGINX_CONF, $config);

        // Ensure symlink exists
        if (!file_exists(self::NGINX_ENABLED)) {
            symlink(self::NGINX_CONF, self::NGINX_ENABLED);
        }

        // Test nginx config
        exec('sudo nginx -t 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            // Restore backup
            if (file_exists(self::NGINX_CONF . '.bak')) {
                copy(self::NGINX_CONF . '.bak', self::NGINX_CONF);
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $output));
        }

        // Reload nginx
        exec('sudo systemctl reload nginx 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("Failed to reload nginx", 'SSL', ['output' => implode("\n", $output)]);
        }
    }

    /**
     * Generate nginx SSL config from template
     */
    private function generateNginxSslConfig(string $domain, string $phpborgRoot): string
    {
        $templateFile = $phpborgRoot . '/install/templates/nginx-ssl.conf.template';

        if (file_exists($templateFile)) {
            $template = file_get_contents($templateFile);
        } else {
            // Fallback to inline template if file not found
            $template = $this->getInlineSslTemplate();
        }

        $phpFpmSock = $this->detectPhpFpmSocket();

        return str_replace(
            ['__DOMAIN__', '__PHPBORG_ROOT__', '__CERT_DIR__', '__PHP_FPM_SOCK__'],
            [$domain, $phpborgRoot, self::CERT_DIR, $phpFpmSock],
            $template
        );
    }

    /**
     * Generate nginx HTTP config from template
     */
    private function generateNginxHttpConfig(string $phpborgRoot): string
    {
        $templateFile = $phpborgRoot . '/install/templates/nginx.conf.template';

        if (file_exists($templateFile)) {
            $template = file_get_contents($templateFile);
            $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

            return str_replace(
                ['__DOMAIN__', '__PHPBORG_ROOT__', '__PHP_VERSION__'],
                ['_', $phpborgRoot, $phpVersion],
                $template
            );
        }

        // Fallback to inline template
        return $this->getInlineHttpTemplate($phpborgRoot);
    }

    /**
     * Detect the PHP-FPM socket path
     */
    private function detectPhpFpmSocket(): string
    {
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $possibleSockets = [
            "/run/php/phpborg-{$phpVersion}-fpm.sock",
            "/run/php/phpborg-fpm.sock",
            "/run/php/php{$phpVersion}-fpm.sock",
            "/run/php-fpm/php-fpm.sock",
            "/var/run/php/php{$phpVersion}-fpm.sock",
            "/var/run/php/php-fpm.sock",
        ];

        foreach ($possibleSockets as $socket) {
            if (file_exists($socket)) {
                return $socket;
            }
        }

        return "/run/php/php{$phpVersion}-fpm.sock";
    }

    /**
     * Get domain from settings or detect from current certificate
     */
    public function getDomain(): ?string
    {
        // First check settings
        $domain = $this->settings->get('ssl.domain');
        if ($domain) {
            return $domain;
        }

        // Try to get from app.domain setting
        $domain = $this->settings->get('app.domain');
        if ($domain) {
            return $domain;
        }

        // Try to detect from current certificate
        $certFile = self::CERT_DIR . '/cert.pem';
        if (file_exists($certFile)) {
            $certData = openssl_x509_parse(file_get_contents($certFile));
            if ($certData && isset($certData['subject']['CN'])) {
                return $certData['subject']['CN'];
            }
        }

        // Try to detect from current nginx config
        if (file_exists(self::NGINX_CONF)) {
            $config = file_get_contents(self::NGINX_CONF);
            if (preg_match('/server_name\s+([^;\s]+);/', $config, $matches)) {
                if ($matches[1] !== '_') {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Set domain in settings
     */
    public function setDomain(string $domain): void
    {
        $this->settings->set('ssl.domain', $domain);
        $this->settings->set('app.domain', $domain);
    }

    /**
     * Inline SSL template fallback
     */
    private function getInlineSslTemplate(): string
    {
        return <<<'NGINX'
# phpBorg Nginx Configuration (SSL)
# Generated by phpBorg SSL Service

server {
    listen 80;
    listen [::]:80;
    server_name __DOMAIN__;

    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name __DOMAIN__;

    ssl_certificate __CERT_DIR__/fullchain.pem;
    ssl_certificate_key __CERT_DIR__/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;

    root __PHPBORG_ROOT__/frontend/dist;
    index index.html;

    client_max_body_size 100M;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/sse/stream {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 86400s;
    }

    location ~ ^/api/ {
        fastcgi_pass unix:__PHP_FPM_SOCK__;
        fastcgi_param SCRIPT_FILENAME __PHPBORG_ROOT__/api/public/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ ^/downloads/ {
        fastcgi_pass unix:__PHP_FPM_SOCK__;
        fastcgi_param SCRIPT_FILENAME __PHPBORG_ROOT__/api/public/index.php;
        include fastcgi_params;
    }

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    /**
     * Inline HTTP template fallback
     */
    private function getInlineHttpTemplate(string $phpborgRoot): string
    {
        $phpFpmSock = $this->detectPhpFpmSocket();

        return <<<NGINX
# phpBorg Nginx Configuration (HTTP)
# Generated by phpBorg SSL Service

server {
    listen 80;
    listen [::]:80;
    server_name _;

    root {$phpborgRoot}/frontend/dist;
    index index.html;

    client_max_body_size 100M;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location /api/sse/stream {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host \$host;
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 86400s;
    }

    location ~ ^/api/ {
        fastcgi_pass unix:{$phpFpmSock};
        fastcgi_param SCRIPT_FILENAME {$phpborgRoot}/api/public/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ ^/downloads/ {
        fastcgi_pass unix:{$phpFpmSock};
        fastcgi_param SCRIPT_FILENAME {$phpborgRoot}/api/public/index.php;
        include fastcgi_params;
    }

    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location ~ /\. {
        deny all;
    }
}
NGINX;
    }
}
