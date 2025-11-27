<?php

declare(strict_types=1);

namespace PhpBorg\Service\Agent;

use PhpBorg\Config\Configuration;
use PhpBorg\Logger\LoggerInterface;

/**
 * Agent Manager Service
 *
 * Manages phpborg-agent registration, SSH key deployment,
 * and authorized_keys configuration for the dedicated borg SSH server.
 */
final class AgentManager
{
    private const BORG_USER = 'phpborg-borg';
    private const BORG_SSH_DIR = '/var/lib/phpborg-borg/.ssh';
    private const AUTHORIZED_KEYS_PATH = '/var/lib/phpborg-borg/.ssh/authorized_keys';
    private const BACKUPS_BASE_PATH = '/opt/backups';

    public function __construct(
        private readonly Configuration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register a new agent and configure its SSH access
     *
     * @param string $agentUuid Unique agent identifier
     * @param string $agentName Human-readable agent name
     * @param string $publicKey Ed25519 public key from the agent
     * @param bool $appendOnly Enable append-only mode (ransomware protection)
     * @return array{backup_path: string, ssh_port: int, ssh_user: string}
     */
    public function registerAgent(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        bool $appendOnly = true
    ): array {
        $this->logger->info("Registering agent: {$agentName} ({$agentUuid})", 'AGENT');

        // Validate public key format (must be Ed25519)
        $this->validatePublicKey($publicKey);

        // Create agent-specific backup directory
        $backupPath = $this->createAgentBackupDirectory($agentUuid);

        // Add SSH key to authorized_keys with borg serve restriction
        $this->addAuthorizedKey($agentUuid, $agentName, $publicKey, $backupPath, $appendOnly);

        $this->logger->info("Agent registered successfully: {$agentName}", 'AGENT');

        return [
            'backup_path' => $backupPath,
            'ssh_port' => $this->config->get('borg_ssh_port', 2222),
            'ssh_user' => self::BORG_USER,
        ];
    }

    /**
     * Unregister an agent and remove its SSH access
     */
    public function unregisterAgent(string $agentUuid, string $agentName): void
    {
        $this->logger->info("Unregistering agent: {$agentName} ({$agentUuid})", 'AGENT');

        // Remove from authorized_keys
        $this->removeAuthorizedKey($agentUuid);

        $this->logger->info("Agent unregistered: {$agentName}", 'AGENT');
    }

    /**
     * Update agent's append-only setting
     */
    public function setAppendOnly(string $agentUuid, string $agentName, bool $appendOnly): void
    {
        $this->logger->info("Setting append-only={$appendOnly} for agent: {$agentName}", 'AGENT');

        // Read current authorized_keys
        $content = $this->readAuthorizedKeys();
        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            if (str_contains($line, "phpborg-agent-{$agentUuid}")) {
                // Found the agent's key, update it
                $found = true;

                // Extract public key and rebuild the line
                if (preg_match('/ssh-ed25519\s+(\S+)/', $line, $matches)) {
                    $keyData = $matches[0];
                    $backupPath = self::BACKUPS_BASE_PATH . '/' . $agentUuid;
                    $newLines[] = $this->buildAuthorizedKeyLine(
                        $agentUuid,
                        $agentName,
                        $keyData,
                        $backupPath,
                        $appendOnly
                    );
                }
            } else {
                $newLines[] = $line;
            }
        }

        if (!$found) {
            throw new \RuntimeException("Agent not found: {$agentUuid}");
        }

        $this->writeAuthorizedKeys(implode("\n", $newLines) . "\n");
    }

    /**
     * List all registered agents from authorized_keys
     *
     * @return array<array{uuid: string, name: string, append_only: bool}>
     */
    public function listAgents(): array
    {
        $content = $this->readAuthorizedKeys();
        $lines = explode("\n", $content);
        $agents = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Parse phpborg-agent entries
            if (preg_match('/phpborg-agent-([a-f0-9-]+)\s+(.+)$/', $line, $matches)) {
                $uuid = $matches[1];
                $name = $matches[2];
                $appendOnly = str_contains($line, '--append-only');

                $agents[] = [
                    'uuid' => $uuid,
                    'name' => $name,
                    'append_only' => $appendOnly,
                ];
            }
        }

        return $agents;
    }

    /**
     * Validate that the public key is Ed25519 format
     */
    private function validatePublicKey(string $publicKey): void
    {
        $publicKey = trim($publicKey);

        if (!str_starts_with($publicKey, 'ssh-ed25519 ')) {
            throw new \InvalidArgumentException(
                'Only Ed25519 keys are accepted. Key must start with "ssh-ed25519"'
            );
        }

        // Basic format validation
        $parts = explode(' ', $publicKey);
        if (count($parts) < 2) {
            throw new \InvalidArgumentException('Invalid public key format');
        }

        // Validate base64 encoding of key data
        $keyData = $parts[1];
        if (base64_decode($keyData, true) === false) {
            throw new \InvalidArgumentException('Invalid public key: malformed base64 data');
        }
    }

    /**
     * Create agent-specific backup directory
     */
    private function createAgentBackupDirectory(string $agentUuid): string
    {
        $backupPath = self::BACKUPS_BASE_PATH . '/' . $agentUuid;

        if (!is_dir($backupPath)) {
            if (!mkdir($backupPath, 0750, true)) {
                throw new \RuntimeException("Failed to create backup directory: {$backupPath}");
            }

            // Set ownership to borg user
            chown($backupPath, self::BORG_USER);
            chgrp($backupPath, self::BORG_USER);
        }

        return $backupPath;
    }

    /**
     * Add SSH key to authorized_keys with borg serve restriction
     */
    private function addAuthorizedKey(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        string $backupPath,
        bool $appendOnly
    ): void {
        // Check if key already exists
        $content = $this->readAuthorizedKeys();
        if (str_contains($content, "phpborg-agent-{$agentUuid}")) {
            $this->logger->info("Agent key already exists, updating: {$agentUuid}", 'AGENT');
            $this->removeAuthorizedKey($agentUuid);
            $content = $this->readAuthorizedKeys();
        }

        // Build the authorized key entry
        $keyLine = $this->buildAuthorizedKeyLine(
            $agentUuid,
            $agentName,
            trim($publicKey),
            $backupPath,
            $appendOnly
        );

        // Append to authorized_keys
        $newContent = rtrim($content) . "\n" . $keyLine . "\n";
        $this->writeAuthorizedKeys($newContent);
    }

    /**
     * Build a restricted authorized_keys entry
     */
    private function buildAuthorizedKeyLine(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        string $backupPath,
        bool $appendOnly
    ): string {
        // Build borg serve command with path restriction
        $borgCommand = "borg serve --restrict-to-path {$backupPath}";
        if ($appendOnly) {
            $borgCommand .= " --append-only";
        }

        // Build restrictions
        $restrictions = [
            "command=\"{$borgCommand}\"",
            'restrict',  // Shorthand for all no-* options
        ];

        // Clean public key (remove any existing comment)
        $keyParts = explode(' ', $publicKey);
        $keyType = $keyParts[0];
        $keyData = $keyParts[1];

        // Build the full line with our identifier comment
        return implode(',', $restrictions) . " {$keyType} {$keyData} phpborg-agent-{$agentUuid} {$agentName}";
    }

    /**
     * Remove agent from authorized_keys
     */
    private function removeAuthorizedKey(string $agentUuid): void
    {
        $content = $this->readAuthorizedKeys();
        $lines = explode("\n", $content);
        $newLines = [];

        foreach ($lines as $line) {
            if (!str_contains($line, "phpborg-agent-{$agentUuid}")) {
                if (!empty(trim($line))) {
                    $newLines[] = $line;
                }
            }
        }

        $this->writeAuthorizedKeys(implode("\n", $newLines) . "\n");
    }

    /**
     * Read authorized_keys file
     */
    private function readAuthorizedKeys(): string
    {
        $path = self::AUTHORIZED_KEYS_PATH;

        if (!file_exists($path)) {
            // Create the file if it doesn't exist
            $this->ensureAuthorizedKeysExists();
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read authorized_keys: {$path}");
        }

        return $content;
    }

    /**
     * Write authorized_keys file with proper permissions
     */
    private function writeAuthorizedKeys(string $content): void
    {
        $path = self::AUTHORIZED_KEYS_PATH;

        $this->ensureAuthorizedKeysExists();

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write authorized_keys: {$path}");
        }

        // Ensure proper permissions
        chmod($path, 0600);
        chown($path, self::BORG_USER);
        chgrp($path, self::BORG_USER);
    }

    /**
     * Ensure authorized_keys file and directory exist
     */
    private function ensureAuthorizedKeysExists(): void
    {
        $dir = self::BORG_SSH_DIR;
        $path = self::AUTHORIZED_KEYS_PATH;

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \RuntimeException("Failed to create SSH directory: {$dir}");
            }
            chown($dir, self::BORG_USER);
            chgrp($dir, self::BORG_USER);
        }

        if (!file_exists($path)) {
            if (touch($path) === false) {
                throw new \RuntimeException("Failed to create authorized_keys: {$path}");
            }
            chmod($path, 0600);
            chown($path, self::BORG_USER);
            chgrp($path, self::BORG_USER);
        }
    }

    /**
     * Get backup path for an agent
     */
    public function getAgentBackupPath(string $agentUuid): string
    {
        return self::BACKUPS_BASE_PATH . '/' . $agentUuid;
    }

    /**
     * Deploy an agent's SSH key (alias for registerAgent with minimal params)
     *
     * @param string $agentUuid Unique agent identifier
     * @param string $publicKey Ed25519 public key from the agent
     * @return array{backup_path: string, ssh_port: int, ssh_user: string}
     */
    public function deployAgentKey(string $agentUuid, string $publicKey): array
    {
        return $this->registerAgent(
            agentUuid: $agentUuid,
            agentName: "agent-{$agentUuid}",
            publicKey: $publicKey,
            appendOnly: true
        );
    }

    /**
     * Check if agent is registered
     */
    public function isAgentRegistered(string $agentUuid): bool
    {
        $content = $this->readAuthorizedKeys();
        return str_contains($content, "phpborg-agent-{$agentUuid}");
    }
}
