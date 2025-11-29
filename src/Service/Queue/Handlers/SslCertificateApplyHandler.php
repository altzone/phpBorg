<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler for applying SSL certificates
 * Runs in worker context with root privileges
 */
final class SslCertificateApplyHandler implements JobHandlerInterface
{
    private const CERT_DIR = '/etc/phpborg/ssl';
    private const NGINX_CONF = '/etc/nginx/sites-available/phpborg';
    private const NGINX_ENABLED = '/etc/nginx/sites-enabled/phpborg';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;
        $action = $payload['action'] ?? 'apply';

        $this->logger->info("SSL certificate operation: {$action}", 'SSL');

        return match ($action) {
            'validate' => $this->validateCertificate($job, $queue, $payload),
            'apply' => $this->applyCertificate($job, $queue, $payload),
            'self_signed' => $this->generateSelfSigned($job, $queue, $payload),
            'letsencrypt_http' => $this->letsEncryptHttp($job, $queue, $payload),
            'letsencrypt_dns' => $this->letsEncryptDns($job, $queue, $payload),
            'renew' => $this->renewCertificate($job, $queue),
            'disable' => $this->disableSsl($job, $queue),
            default => throw new \Exception("Unknown SSL action: {$action}"),
        };
    }

    private function validateCertificate(Job $job, JobQueue $queue, array $payload): string
    {
        $queue->updateProgress($job->id, 10, "Validation du certificat...");

        $cert = $payload['cert'] ?? null;
        $key = $payload['key'] ?? null;
        $chain = $payload['chain'] ?? null;

        if (!$cert || !$key) {
            throw new \Exception("Certificate and key are required");
        }

        // Write temp files
        $tempCert = '/tmp/ssl-validate-cert-' . uniqid() . '.pem';
        $tempKey = '/tmp/ssl-validate-key-' . uniqid() . '.pem';

        file_put_contents($tempCert, $cert);
        file_put_contents($tempKey, $key);

        try {
            $queue->updateProgress($job->id, 30, "Vérification de la paire clé/certificat...");

            // Check if cert and key match
            $certModulus = shell_exec("openssl x509 -noout -modulus -in {$tempCert} 2>&1 | openssl md5");
            $keyModulus = shell_exec("openssl rsa -noout -modulus -in {$tempKey} 2>&1 | openssl md5");

            if (trim($certModulus) !== trim($keyModulus)) {
                throw new \Exception("Certificate and private key do not match");
            }

            $queue->updateProgress($job->id, 50, "Extraction des informations du certificat...");

            // Extract certificate info
            $certInfo = openssl_x509_parse($cert);
            if (!$certInfo) {
                throw new \Exception("Invalid certificate format");
            }

            // Note: We don't test nginx config here - that's done during apply
            // Validation only checks cert/key match and cert validity

            $queue->updateProgress($job->id, 100, "Certificat valide");

            $result = [
                'valid' => true,
                'subject' => $certInfo['subject']['CN'] ?? 'Unknown',
                'issuer' => $certInfo['issuer']['CN'] ?? $certInfo['issuer']['O'] ?? 'Unknown',
                'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
                'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
                'days_remaining' => (int) (($certInfo['validTo_time_t'] - time()) / 86400),
            ];

            return json_encode($result);

        } finally {
            @unlink($tempCert);
            @unlink($tempKey);
        }
    }

    private function applyCertificate(Job $job, JobQueue $queue, array $payload): string
    {
        $queue->updateProgress($job->id, 10, "Préparation de l'application du certificat...");

        $cert = $payload['cert'] ?? null;
        $key = $payload['key'] ?? null;
        $chain = $payload['chain'] ?? null;
        $domain = $payload['domain'] ?? null;

        if (!$cert || !$key) {
            throw new \Exception("Certificate and key are required");
        }

        // Ensure cert directory exists
        $this->ensureCertDir();

        $queue->updateProgress($job->id, 20, "Sauvegarde des certificats actuels...");

        // Backup current certs
        $this->backupCurrentCerts();

        $queue->updateProgress($job->id, 40, "Écriture des nouveaux certificats...");

        // Write new certificates using sudo
        $this->sudoWrite(self::CERT_DIR . '/cert.pem', $cert, '0644');
        $this->sudoWrite(self::CERT_DIR . '/privkey.pem', $key, '0600');

        // Create fullchain
        $fullchain = $cert;
        if ($chain) {
            $fullchain .= "\n" . $chain;
            $this->sudoWrite(self::CERT_DIR . '/chain.pem', $chain, '0644');
        }
        $this->sudoWrite(self::CERT_DIR . '/fullchain.pem', $fullchain, '0644');

        $queue->updateProgress($job->id, 60, "Mise à jour de la configuration nginx...");

        // Update nginx config
        $phpborgRoot = dirname(__DIR__, 4);
        $config = $this->generateNginxSslConfig($domain ?: $this->detectDomain(), $phpborgRoot);

        // Backup nginx config
        if (file_exists(self::NGINX_CONF)) {
            exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_CONF . '.bak')));
        }

        $this->sudoWrite(self::NGINX_CONF, $config, '0644');

        // Ensure symlink exists
        if (!file_exists(self::NGINX_ENABLED)) {
            exec(sprintf('sudo ln -s %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_ENABLED)));
        }

        $queue->updateProgress($job->id, 80, "Test de la configuration nginx...");

        // Test nginx config
        exec('sudo nginx -t 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            // Restore backup
            if (file_exists(self::NGINX_CONF . '.bak')) {
                copy(self::NGINX_CONF . '.bak', self::NGINX_CONF);
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 90, "Rechargement de nginx...");

        // Reload nginx
        exec('sudo systemctl reload nginx 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("Failed to reload nginx", 'SSL', ['output' => implode("\n", $output)]);
        }

        // Save settings
        $this->saveSetting('ssl.enabled', 'true');
        $this->saveSetting('ssl.type', 'custom');
        if ($domain) {
            $this->saveSetting('ssl.domain', $domain);
        }

        $queue->updateProgress($job->id, 100, "Certificat appliqué avec succès");

        $this->logger->info("Certificate applied successfully", 'SSL');

        return "Certificate applied successfully";
    }

    private function generateSelfSigned(Job $job, JobQueue $queue, array $payload): string
    {
        $domain = $payload['domain'] ?? null;
        $days = (int) ($payload['days'] ?? 365);

        if (!$domain) {
            throw new \Exception("Domain is required");
        }

        $queue->updateProgress($job->id, 10, "Génération du certificat auto-signé...");

        $this->ensureCertDir();

        // Generate self-signed certificate in temp directory first
        $tempDir = '/tmp/phpborg-ssl-' . uniqid();
        mkdir($tempDir, 0700, true);
        $tempKeyFile = $tempDir . '/privkey.pem';
        $tempCertFile = $tempDir . '/cert.pem';
        $tempConfigFile = $tempDir . '/openssl.cnf';

        // Build Subject Alternative Names (SANs)
        $sans = ["DNS:$domain"];

        // Add wildcard if not already a wildcard domain
        if (strpos($domain, '*.') !== 0) {
            $sans[] = "DNS:*.$domain";
        }

        // Add server IPs from database settings
        $db = new \PhpBorg\Database\Database();
        $settingsRepo = new \PhpBorg\Repository\SettingsRepository($db);
        $externalIp = $settingsRepo->get('network.external_ip');
        $internalIp = $settingsRepo->get('network.internal_ip');

        if ($externalIp) {
            $sans[] = "IP:$externalIp";
        }
        if ($internalIp && $internalIp !== $externalIp) {
            $sans[] = "IP:$internalIp";
        }

        // Always add localhost
        $sans[] = "IP:127.0.0.1";

        // Create OpenSSL config with SANs
        $opensslConfig = "[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
x509_extensions = v3_ext

[dn]
CN = $domain

[v3_ext]
subjectAltName = " . implode(',', $sans) . "
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
";

        file_put_contents($tempConfigFile, $opensslConfig);

        $cmd = sprintf(
            'openssl req -x509 -nodes -days %d -newkey rsa:2048 -keyout %s -out %s -config %s 2>&1',
            $days,
            escapeshellarg($tempKeyFile),
            escapeshellarg($tempCertFile),
            escapeshellarg($tempConfigFile)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            @unlink($tempKeyFile);
            @unlink($tempCertFile);
            @unlink($tempConfigFile);
            @rmdir($tempDir);
            throw new \Exception("Failed to generate certificate: " . implode("\n", $output));
        }

        // Copy to final location using sudo
        $keyFile = self::CERT_DIR . '/privkey.pem';
        $certFile = self::CERT_DIR . '/cert.pem';
        $fullchainFile = self::CERT_DIR . '/fullchain.pem';

        exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($tempKeyFile), escapeshellarg($keyFile)));
        exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($tempCertFile), escapeshellarg($certFile)));
        exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($tempCertFile), escapeshellarg($fullchainFile)));
        exec(sprintf('sudo chmod 0600 %s 2>&1', escapeshellarg($keyFile)));

        // Cleanup temp files
        @unlink($tempKeyFile);
        @unlink($tempCertFile);
        @unlink($tempConfigFile);
        @rmdir($tempDir);

        $queue->updateProgress($job->id, 50, "Mise à jour de la configuration nginx...");

        // Update nginx config
        $phpborgRoot = dirname(__DIR__, 4);
        $config = $this->generateNginxSslConfig($domain, $phpborgRoot);

        if (file_exists(self::NGINX_CONF)) {
            exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_CONF . '.bak')));
        }

        $this->sudoWrite(self::NGINX_CONF, $config, '0644');

        if (!file_exists(self::NGINX_ENABLED)) {
            exec(sprintf('sudo ln -s %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_ENABLED)));
        }

        $queue->updateProgress($job->id, 70, "Test de la configuration nginx...");

        exec('sudo nginx -t 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            if (file_exists(self::NGINX_CONF . '.bak')) {
                copy(self::NGINX_CONF . '.bak', self::NGINX_CONF);
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 90, "Rechargement de nginx...");

        exec('sudo systemctl reload nginx 2>&1');

        // Save settings
        $this->saveSetting('ssl.enabled', 'true');
        $this->saveSetting('ssl.type', 'self-signed');
        $this->saveSetting('ssl.domain', $domain);

        $queue->updateProgress($job->id, 100, "Certificat auto-signé généré");

        $this->logger->info("Self-signed certificate generated", 'SSL', ['domain' => $domain]);

        return "Self-signed certificate generated for {$domain}";
    }

    private function letsEncryptHttp(Job $job, JobQueue $queue, array $payload): string
    {
        $domain = $payload['domain'] ?? null;
        $email = $payload['email'] ?? null;

        if (!$domain || !$email) {
            throw new \Exception("Domain and email are required");
        }

        $queue->updateProgress($job->id, 10, "Vérification de certbot...");

        if (!$this->commandExists('certbot')) {
            throw new \Exception("certbot is not installed");
        }

        $this->ensureCertDir();

        $webroot = '/var/www/html';
        if (!is_dir($webroot)) {
            mkdir($webroot, 0755, true);
        }

        $queue->updateProgress($job->id, 20, "Demande du certificat Let's Encrypt...");

        $cmd = sprintf(
            'sudo certbot certonly --webroot -w %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
            escapeshellarg($webroot),
            escapeshellarg($domain),
            escapeshellarg($email)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Let's Encrypt failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 50, "Copie des certificats...");

        $this->copyLetsEncryptCerts();

        $queue->updateProgress($job->id, 70, "Mise à jour de nginx...");

        $phpborgRoot = dirname(__DIR__, 4);
        $config = $this->generateNginxSslConfig($domain, $phpborgRoot);

        if (file_exists(self::NGINX_CONF)) {
            exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_CONF . '.bak')));
        }

        $this->sudoWrite(self::NGINX_CONF, $config, '0644');

        if (!file_exists(self::NGINX_ENABLED)) {
            exec(sprintf('sudo ln -s %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_ENABLED)));
        }

        exec('sudo nginx -t 2>&1', $testOutput, $exitCode);

        if ($exitCode !== 0) {
            if (file_exists(self::NGINX_CONF . '.bak')) {
                exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF . '.bak'), escapeshellarg(self::NGINX_CONF)));
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $testOutput));
        }

        $queue->updateProgress($job->id, 90, "Rechargement de nginx...");

        exec('sudo systemctl reload nginx 2>&1');

        // Save settings
        $this->saveSetting('ssl.enabled', 'true');
        $this->saveSetting('ssl.type', 'letsencrypt');
        $this->saveSetting('ssl.domain', $domain);
        $this->saveSetting('ssl.email', $email);

        // Setup auto-renewal
        $this->setupAutoRenewal();

        $queue->updateProgress($job->id, 100, "Certificat Let's Encrypt obtenu");

        $this->logger->info("Let's Encrypt certificate obtained", 'SSL', ['domain' => $domain]);

        return "Let's Encrypt certificate obtained for {$domain}";
    }

    private function letsEncryptDns(Job $job, JobQueue $queue, array $payload): string
    {
        $domain = $payload['domain'] ?? null;
        $email = $payload['email'] ?? null;
        $cfToken = $payload['cloudflare_token'] ?? null;

        if (!$domain || !$email || !$cfToken) {
            throw new \Exception("Domain, email and Cloudflare token are required");
        }

        $queue->updateProgress($job->id, 10, "Vérification de certbot...");

        if (!$this->commandExists('certbot')) {
            throw new \Exception("certbot is not installed");
        }

        // Check for Cloudflare plugin
        if (!file_exists('/usr/lib/python3/dist-packages/certbot_dns_cloudflare')) {
            exec('sudo apt-get install -y python3-certbot-dns-cloudflare 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \Exception("python3-certbot-dns-cloudflare is not installed");
            }
        }

        $this->ensureCertDir();

        $queue->updateProgress($job->id, 20, "Configuration Cloudflare...");

        // Create Cloudflare credentials file
        $cfCredsFile = self::CERT_DIR . '/cloudflare.ini';
        file_put_contents($cfCredsFile, "dns_cloudflare_api_token = {$cfToken}\n");
        chmod($cfCredsFile, 0600);

        $queue->updateProgress($job->id, 30, "Demande du certificat Let's Encrypt via DNS...");

        $cmd = sprintf(
            'sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials %s -d %s --email %s --agree-tos --non-interactive --cert-name phpborg 2>&1',
            escapeshellarg($cfCredsFile),
            escapeshellarg($domain),
            escapeshellarg($email)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Let's Encrypt failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 60, "Copie des certificats...");

        $this->copyLetsEncryptCerts();

        $queue->updateProgress($job->id, 80, "Mise à jour de nginx...");

        $phpborgRoot = dirname(__DIR__, 4);
        $config = $this->generateNginxSslConfig($domain, $phpborgRoot);

        if (file_exists(self::NGINX_CONF)) {
            exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_CONF . '.bak')));
        }

        $this->sudoWrite(self::NGINX_CONF, $config, '0644');

        if (!file_exists(self::NGINX_ENABLED)) {
            exec(sprintf('sudo ln -s %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_ENABLED)));
        }

        exec('sudo nginx -t 2>&1', $testOutput, $exitCode);

        if ($exitCode !== 0) {
            if (file_exists(self::NGINX_CONF . '.bak')) {
                exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF . '.bak'), escapeshellarg(self::NGINX_CONF)));
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $testOutput));
        }

        $queue->updateProgress($job->id, 95, "Rechargement de nginx...");

        exec('sudo systemctl reload nginx 2>&1');

        // Save settings
        $this->saveSetting('ssl.enabled', 'true');
        $this->saveSetting('ssl.type', 'letsencrypt');
        $this->saveSetting('ssl.domain', $domain);
        $this->saveSetting('ssl.email', $email);
        $this->saveSetting('ssl.cloudflare_configured', 'true');

        $this->setupAutoRenewal();

        $queue->updateProgress($job->id, 100, "Certificat Let's Encrypt obtenu via DNS");

        $this->logger->info("Let's Encrypt certificate obtained via DNS", 'SSL', ['domain' => $domain]);

        return "Let's Encrypt certificate obtained for {$domain} via DNS-01";
    }

    private function renewCertificate(Job $job, JobQueue $queue): string
    {
        $queue->updateProgress($job->id, 10, "Vérification du type de certificat...");

        $type = $this->getSetting('ssl.type');

        if ($type !== 'letsencrypt') {
            throw new \Exception("Auto-renewal is only available for Let's Encrypt certificates");
        }

        $queue->updateProgress($job->id, 30, "Renouvellement du certificat...");

        exec('sudo certbot renew --cert-name phpborg 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Renewal failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 70, "Copie des certificats renouvelés...");

        $this->copyLetsEncryptCerts();

        $queue->updateProgress($job->id, 90, "Rechargement de nginx...");

        exec('sudo systemctl reload nginx 2>&1');

        $queue->updateProgress($job->id, 100, "Certificat renouvelé");

        $this->logger->info("Certificate renewed successfully", 'SSL');

        return "Certificate renewed successfully";
    }

    private function disableSsl(Job $job, JobQueue $queue): string
    {
        $queue->updateProgress($job->id, 20, "Génération de la configuration HTTP...");

        $phpborgRoot = dirname(__DIR__, 4);
        $config = $this->generateNginxHttpConfig($phpborgRoot);

        if (file_exists(self::NGINX_CONF)) {
            exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF), escapeshellarg(self::NGINX_CONF . '.ssl.bak')));
        }

        $this->sudoWrite(self::NGINX_CONF, $config, '0644');

        $queue->updateProgress($job->id, 50, "Test de la configuration nginx...");

        exec('sudo nginx -t 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            if (file_exists(self::NGINX_CONF . '.ssl.bak')) {
                exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg(self::NGINX_CONF . '.ssl.bak'), escapeshellarg(self::NGINX_CONF)));
            }
            throw new \Exception("Nginx config test failed: " . implode("\n", $output));
        }

        $queue->updateProgress($job->id, 80, "Rechargement de nginx...");

        exec('sudo systemctl reload nginx 2>&1');

        $this->saveSetting('ssl.enabled', 'false');
        $this->saveSetting('ssl.type', 'none');

        $queue->updateProgress($job->id, 100, "SSL désactivé");

        $this->logger->info("SSL disabled", 'SSL');

        return "SSL disabled successfully";
    }

    // Helper methods

    /**
     * Write file using sudo tee (for files owned by root)
     */
    private function sudoWrite(string $path, string $content, string $mode = '0644'): void
    {
        $tempFile = '/tmp/phpborg-ssl-' . uniqid() . '.tmp';
        if (file_put_contents($tempFile, $content) === false) {
            throw new \Exception("Failed to write temp file");
        }

        // Use sudo cp to copy file to destination
        exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($tempFile), escapeshellarg($path)), $output, $exitCode);
        @unlink($tempFile);

        if ($exitCode !== 0) {
            throw new \Exception("Failed to write {$path}: " . implode("\n", $output));
        }

        // Set permissions
        exec(sprintf('sudo chmod %s %s 2>&1', $mode, escapeshellarg($path)), $output, $exitCode);
        if ($exitCode !== 0) {
            $this->logger->warning("Failed to set permissions on {$path}", 'SSL');
        }
    }

    /**
     * Create directory using sudo
     */
    private function sudoMkdir(string $path, string $mode = '0755'): void
    {
        if (is_dir($path)) {
            return;
        }

        exec(sprintf('sudo mkdir -p %s 2>&1', escapeshellarg($path)), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception("Failed to create directory {$path}: " . implode("\n", $output));
        }

        exec(sprintf('sudo chmod %s %s 2>&1', $mode, escapeshellarg($path)));
    }

    private function ensureCertDir(): void
    {
        $this->sudoMkdir(self::CERT_DIR, '0700');
    }

    private function backupCurrentCerts(): void
    {
        $backupDir = self::CERT_DIR . '/backup_' . date('Y-m-d_His');

        if (file_exists(self::CERT_DIR . '/cert.pem')) {
            $this->sudoMkdir($backupDir, '0700');
            foreach (['cert.pem', 'privkey.pem', 'fullchain.pem', 'chain.pem'] as $file) {
                $src = self::CERT_DIR . '/' . $file;
                if (file_exists($src)) {
                    exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($src), escapeshellarg($backupDir . '/' . $file)));
                }
            }
            $this->logger->info("Certificates backed up", 'SSL', ['backup_dir' => $backupDir]);
        }
    }

    private function copyLetsEncryptCerts(): void
    {
        $leDir = '/etc/letsencrypt/live/phpborg';

        if (!is_dir($leDir)) {
            throw new \Exception("Let's Encrypt certificates not found");
        }

        $this->ensureCertDir();

        // Use sudo to copy Let's Encrypt certs (they're readable but we write to /etc/phpborg/ssl)
        foreach (['cert.pem', 'privkey.pem', 'fullchain.pem', 'chain.pem'] as $file) {
            $src = $leDir . '/' . $file;
            $dst = self::CERT_DIR . '/' . $file;
            if (file_exists($src)) {
                exec(sprintf('sudo cp %s %s 2>&1', escapeshellarg($src), escapeshellarg($dst)), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new \Exception("Failed to copy {$file}: " . implode("\n", $output));
                }
            }
        }

        exec(sprintf('sudo chmod 0600 %s 2>&1', escapeshellarg(self::CERT_DIR . '/privkey.pem')));
    }

    private function setupAutoRenewal(): void
    {
        $cronContent = "# phpBorg SSL auto-renewal\n";
        $cronContent .= "0 3 * * * root certbot renew --cert-name phpborg --quiet --deploy-hook \"systemctl reload nginx\"\n";

        $this->sudoWrite('/etc/cron.d/phpborg-ssl-renew', $cronContent, '0644');

        $this->logger->info("Auto-renewal cron configured", 'SSL');
    }

    private function testNginxConfig(string $certFile, string $keyFile, ?string $chain): void
    {
        $phpborgRoot = dirname(__DIR__, 4);

        // Generate test config
        $templateFile = $phpborgRoot . '/install/templates/nginx-ssl.conf.template';

        if (!file_exists($templateFile)) {
            return; // Skip test if no template
        }

        $template = file_get_contents($templateFile);
        $domain = $this->detectDomain() ?: 'localhost';
        $phpFpmSock = $this->detectPhpFpmSocket();

        $testConfig = str_replace(
            ['__DOMAIN__', '__PHPBORG_ROOT__', '__CERT_DIR__', '__PHP_FPM_SOCK__'],
            [$domain, $phpborgRoot, dirname($certFile), $phpFpmSock],
            $template
        );

        // Test with sudo
        exec("sudo nginx -t 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Nginx configuration test failed: " . implode("\n", $output));
        }
    }

    private function generateNginxSslConfig(string $domain, string $phpborgRoot): string
    {
        $templateFile = $phpborgRoot . '/install/templates/nginx-ssl.conf.template';

        if (!file_exists($templateFile)) {
            throw new \Exception("SSL nginx template not found: {$templateFile}");
        }

        $template = file_get_contents($templateFile);
        $phpFpmSock = $this->detectPhpFpmSocket();

        return str_replace(
            ['__DOMAIN__', '__PHPBORG_ROOT__', '__CERT_DIR__', '__PHP_FPM_SOCK__'],
            [$domain, $phpborgRoot, self::CERT_DIR, $phpFpmSock],
            $template
        );
    }

    private function generateNginxHttpConfig(string $phpborgRoot): string
    {
        $templateFile = $phpborgRoot . '/install/templates/nginx.conf.template';

        if (!file_exists($templateFile)) {
            throw new \Exception("HTTP nginx template not found");
        }

        $template = file_get_contents($templateFile);
        $phpFpmSock = $this->detectPhpFpmSocket();

        return str_replace(
            ['__PHPBORG_ROOT__', '__PHP_FPM_SOCK__'],
            [$phpborgRoot, $phpFpmSock],
            $template
        );
    }

    private function detectPhpFpmSocket(): string
    {
        $possibleSockets = [
            '/run/php/php8.3-fpm.sock',
            '/run/php/php8.2-fpm.sock',
            '/run/php/php8.1-fpm.sock',
            '/run/php/php8.0-fpm.sock',
            '/run/php/php-fpm.sock',
            '/var/run/php/php-fpm.sock',
        ];

        foreach ($possibleSockets as $socket) {
            if (file_exists($socket)) {
                return $socket;
            }
        }

        return '/run/php/php-fpm.sock';
    }

    private function detectDomain(): string
    {
        // Try to get from settings
        $domain = $this->getSetting('ssl.domain');
        if ($domain) {
            return $domain;
        }

        // Try to detect from current nginx config
        if (file_exists(self::NGINX_CONF)) {
            $config = file_get_contents(self::NGINX_CONF);
            if (preg_match('/server_name\s+([^\s;]+)/', $config, $matches)) {
                if ($matches[1] !== '_' && $matches[1] !== 'localhost') {
                    return $matches[1];
                }
            }
        }

        return $_SERVER['SERVER_NAME'] ?? 'localhost';
    }

    private function commandExists(string $cmd): bool
    {
        exec("which {$cmd} 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }

    private function getSetting(string $key): ?string
    {
        $setting = $this->settings->findByKey($key);
        return $setting?->value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $existing = $this->settings->findByKey($key);
        if ($existing) {
            $this->settings->updateValue($key, $value);
        } else {
            $this->settings->create($key, $value, 'ssl', 'string', "SSL setting: {$key}");
        }
    }
}
