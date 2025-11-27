<?php

declare(strict_types=1);

namespace PhpBorg\Service\Agent;

use PhpBorg\Config\Configuration;
use PhpBorg\Logger\LoggerInterface;

/**
 * Certificate Manager for Agent mTLS Authentication
 *
 * Manages the internal Certificate Authority (CA) and agent certificates
 * for mutual TLS authentication between agents and the phpBorg API.
 */
final class CertificateManager
{
    private const CA_DIR = '/etc/phpborg/certs';
    private const CA_KEY = '/etc/phpborg/certs/ca.key';
    private const CA_CERT = '/etc/phpborg/certs/ca.crt';
    private const SERVER_KEY = '/etc/phpborg/certs/server.key';
    private const SERVER_CERT = '/etc/phpborg/certs/server.crt';
    private const AGENTS_DIR = '/etc/phpborg/certs/agents';

    private const CA_VALIDITY_DAYS = 3650;  // 10 years
    private const CERT_VALIDITY_DAYS = 365; // 1 year

    public function __construct(
        private readonly Configuration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Initialize the Certificate Authority if not exists
     */
    public function initializeCA(): void
    {
        $this->logger->info('Initializing Certificate Authority', 'CERT');

        $this->ensureDirectoriesExist();

        if (file_exists(self::CA_KEY) && file_exists(self::CA_CERT)) {
            $this->logger->info('CA already initialized', 'CERT');
            return;
        }

        // Generate CA private key (ECDSA P-256)
        $this->logger->info('Generating CA private key', 'CERT');
        $this->runOpenssl([
            'ecparam',
            '-genkey',
            '-name', 'prime256v1',
            '-out', self::CA_KEY,
        ]);
        chmod(self::CA_KEY, 0600);

        // Generate self-signed CA certificate
        $this->logger->info('Generating CA certificate', 'CERT');
        $subject = '/CN=phpBorg Internal CA/O=phpBorg/C=US';
        $this->runOpenssl([
            'req',
            '-new',
            '-x509',
            '-key', self::CA_KEY,
            '-out', self::CA_CERT,
            '-days', (string) self::CA_VALIDITY_DAYS,
            '-subj', $subject,
            '-sha256',
        ]);
        chmod(self::CA_CERT, 0644);

        $this->logger->info('CA initialized successfully', 'CERT');
    }

    /**
     * Generate server certificate for the API
     */
    public function generateServerCertificate(string $hostname): void
    {
        $this->logger->info("Generating server certificate for: {$hostname}", 'CERT');

        $this->ensureCAExists();

        // Generate server private key
        $this->runOpenssl([
            'ecparam',
            '-genkey',
            '-name', 'prime256v1',
            '-out', self::SERVER_KEY,
        ]);
        chmod(self::SERVER_KEY, 0600);

        // Create CSR config with SAN
        $csrConfig = $this->createServerCSRConfig($hostname);
        $configFile = self::CA_DIR . '/server_csr.cnf';
        file_put_contents($configFile, $csrConfig);

        // Generate CSR
        $csrFile = self::CA_DIR . '/server.csr';
        $this->runOpenssl([
            'req',
            '-new',
            '-key', self::SERVER_KEY,
            '-out', $csrFile,
            '-config', $configFile,
        ]);

        // Sign with CA
        $this->runOpenssl([
            'x509',
            '-req',
            '-in', $csrFile,
            '-CA', self::CA_CERT,
            '-CAkey', self::CA_KEY,
            '-CAcreateserial',
            '-out', self::SERVER_CERT,
            '-days', (string) self::CERT_VALIDITY_DAYS,
            '-sha256',
            '-extfile', $configFile,
            '-extensions', 'v3_req',
        ]);
        chmod(self::SERVER_CERT, 0644);

        // Cleanup
        unlink($csrFile);
        unlink($configFile);

        $this->logger->info('Server certificate generated', 'CERT');
    }

    /**
     * Generate agent certificate
     *
     * @return array{cert: string, key: string, ca: string}
     */
    public function generateAgentCertificate(string $agentUuid, string $agentName): array
    {
        $this->logger->info("Generating certificate for agent: {$agentName} ({$agentUuid})", 'CERT');

        $this->ensureCAExists();
        $this->ensureDirectoriesExist();

        $keyFile = self::AGENTS_DIR . "/{$agentUuid}.key";
        $certFile = self::AGENTS_DIR . "/{$agentUuid}.crt";

        // Generate agent private key
        $this->runOpenssl([
            'ecparam',
            '-genkey',
            '-name', 'prime256v1',
            '-out', $keyFile,
        ]);
        chmod($keyFile, 0600);

        // Create CSR config
        $csrConfig = $this->createAgentCSRConfig($agentUuid, $agentName);
        $configFile = self::AGENTS_DIR . "/{$agentUuid}_csr.cnf";
        file_put_contents($configFile, $csrConfig);

        // Generate CSR
        $csrFile = self::AGENTS_DIR . "/{$agentUuid}.csr";
        $this->runOpenssl([
            'req',
            '-new',
            '-key', $keyFile,
            '-out', $csrFile,
            '-config', $configFile,
        ]);

        // Sign with CA
        $this->runOpenssl([
            'x509',
            '-req',
            '-in', $csrFile,
            '-CA', self::CA_CERT,
            '-CAkey', self::CA_KEY,
            '-CAcreateserial',
            '-out', $certFile,
            '-days', (string) self::CERT_VALIDITY_DAYS,
            '-sha256',
            '-extfile', $configFile,
            '-extensions', 'v3_req',
        ]);
        chmod($certFile, 0644);

        // Read certificate and key
        $cert = file_get_contents($certFile);
        $key = file_get_contents($keyFile);
        $ca = file_get_contents(self::CA_CERT);

        // Cleanup CSR and config
        unlink($csrFile);
        unlink($configFile);

        $this->logger->info("Agent certificate generated: {$agentUuid}", 'CERT');

        return [
            'cert' => $cert,
            'key' => $key,
            'ca' => $ca,
        ];
    }

    /**
     * Revoke an agent certificate
     */
    public function revokeAgentCertificate(string $agentUuid): void
    {
        $this->logger->info("Revoking certificate for agent: {$agentUuid}", 'CERT');

        $keyFile = self::AGENTS_DIR . "/{$agentUuid}.key";
        $certFile = self::AGENTS_DIR . "/{$agentUuid}.crt";

        // Simply delete the certificate files
        // For a production system, you'd want to maintain a CRL
        if (file_exists($keyFile)) {
            unlink($keyFile);
        }
        if (file_exists($certFile)) {
            unlink($certFile);
        }

        $this->logger->info("Agent certificate revoked: {$agentUuid}", 'CERT');
    }

    /**
     * Check if an agent certificate exists and is valid
     */
    public function isAgentCertificateValid(string $agentUuid): bool
    {
        $certFile = self::AGENTS_DIR . "/{$agentUuid}.crt";

        if (!file_exists($certFile)) {
            return false;
        }

        // Check expiration
        $certData = file_get_contents($certFile);
        $cert = openssl_x509_parse($certData);

        if ($cert === false) {
            return false;
        }

        // Check if expired
        $validTo = $cert['validTo_time_t'] ?? 0;
        return $validTo > time();
    }

    /**
     * Get certificate expiration date
     */
    public function getAgentCertificateExpiry(string $agentUuid): ?\DateTimeImmutable
    {
        $certFile = self::AGENTS_DIR . "/{$agentUuid}.crt";

        if (!file_exists($certFile)) {
            return null;
        }

        $certData = file_get_contents($certFile);
        $cert = openssl_x509_parse($certData);

        if ($cert === false || !isset($cert['validTo_time_t'])) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($cert['validTo_time_t']);
    }

    /**
     * Get CA certificate content (for distribution to agents)
     */
    public function getCACertificate(): string
    {
        $this->ensureCAExists();
        return file_get_contents(self::CA_CERT);
    }

    /**
     * Get paths to server certificate files
     *
     * @return array{cert: string, key: string, ca: string}
     */
    public function getServerCertificatePaths(): array
    {
        return [
            'cert' => self::SERVER_CERT,
            'key' => self::SERVER_KEY,
            'ca' => self::CA_CERT,
        ];
    }

    /**
     * Renew an agent certificate
     *
     * @return array{cert: string, key: string, ca: string}
     */
    public function renewAgentCertificate(string $agentUuid, string $agentName): array
    {
        $this->logger->info("Renewing certificate for agent: {$agentUuid}", 'CERT');

        // Revoke old certificate
        $this->revokeAgentCertificate($agentUuid);

        // Generate new certificate
        return $this->generateAgentCertificate($agentUuid, $agentName);
    }

    /**
     * List all agent certificates
     *
     * @return array<array{uuid: string, expires: string, valid: bool}>
     */
    public function listAgentCertificates(): array
    {
        $certificates = [];

        if (!is_dir(self::AGENTS_DIR)) {
            return $certificates;
        }

        $files = glob(self::AGENTS_DIR . '/*.crt');
        foreach ($files as $file) {
            $uuid = basename($file, '.crt');
            $expiry = $this->getAgentCertificateExpiry($uuid);
            $valid = $this->isAgentCertificateValid($uuid);

            $certificates[] = [
                'uuid' => $uuid,
                'expires' => $expiry?->format('Y-m-d H:i:s') ?? 'unknown',
                'valid' => $valid,
            ];
        }

        return $certificates;
    }

    /**
     * Ensure CA exists
     */
    private function ensureCAExists(): void
    {
        if (!file_exists(self::CA_KEY) || !file_exists(self::CA_CERT)) {
            throw new \RuntimeException(
                'CA not initialized. Run initializeCA() first.'
            );
        }
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectoriesExist(): void
    {
        $dirs = [self::CA_DIR, self::AGENTS_DIR];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0700, true)) {
                    throw new \RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
    }

    /**
     * Create CSR config for server certificate
     */
    private function createServerCSRConfig(string $hostname): string
    {
        return <<<CONFIG
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = {$hostname}
O = phpBorg
C = US

[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$hostname}
DNS.2 = localhost
IP.1 = 127.0.0.1
CONFIG;
    }

    /**
     * Create CSR config for agent certificate
     */
    private function createAgentCSRConfig(string $agentUuid, string $agentName): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $agentName);

        return <<<CONFIG
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = agent-{$agentUuid}
O = phpBorg Agent
OU = {$safeName}
C = US

[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = clientAuth
CONFIG;
    }

    /**
     * Run openssl command
     */
    private function runOpenssl(array $args): void
    {
        $command = 'openssl ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $outputStr = implode("\n", $output);
            throw new \RuntimeException("OpenSSL command failed: {$outputStr}");
        }
    }
}
