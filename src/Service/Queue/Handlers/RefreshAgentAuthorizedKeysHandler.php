<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Repository\AgentRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler pour rafraîchir les authorized_keys d'un agent
 *
 * Ce handler tourne dans le worker agent (phpborg-borg) qui a les permissions
 * nécessaires pour écrire dans /var/lib/phpborg-borg/.ssh/authorized_keys
 */
final class RefreshAgentAuthorizedKeysHandler implements JobHandlerInterface
{
    private const BORG_USER = 'phpborg-borg';
    private const BORG_SSH_DIR = '/var/lib/phpborg-borg/.ssh';
    private const AUTHORIZED_KEYS_PATH = '/var/lib/phpborg-borg/.ssh/authorized_keys';

    public function __construct(
        private readonly AgentRepository $agentRepo,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $agentUuid = $payload['agent_uuid'] ?? null;
        $agentName = $payload['agent_name'] ?? null;
        $publicKey = $payload['public_key'] ?? null;
        $allowedPaths = $payload['allowed_paths'] ?? [];
        $appendOnly = $payload['append_only'] ?? true;
        $repoPath = $payload['repo_path'] ?? null;
        $passphrase = $payload['passphrase'] ?? null;
        $encryption = $payload['encryption'] ?? 'repokey-blake2';

        if (!$agentUuid || !$agentName || !$publicKey) {
            throw new \InvalidArgumentException('Paramètres manquants: agent_uuid, agent_name, public_key requis');
        }

        $queue->updateProgress($job->id, 10, "Préparation pour {$agentName}...");

        // Préparer le répertoire du repo et initialiser borg si spécifié
        if ($repoPath) {
            $queue->updateProgress($job->id, 30, "Préparation du répertoire de backup...");
            $this->ensureRepoDirectory($repoPath);

            // Initialiser le repo borg en tant que phpborg-borg
            if ($passphrase) {
                $queue->updateProgress($job->id, 40, "Initialisation du dépôt Borg...");
                $this->initBorgRepository($repoPath, $passphrase, $encryption);
            }
        }

        $queue->updateProgress($job->id, 50, "Rafraîchissement des authorized_keys...");

        $this->logger->info("Refreshing authorized_keys for agent: {$agentName} with " . count($allowedPaths) . " paths", 'AGENT');

        // Supprimer l'ancienne entrée
        $this->removeAuthorizedKey($agentUuid);

        // Ajouter la nouvelle entrée avec tous les paths
        $content = $this->readAuthorizedKeys();
        $keyLine = $this->buildAuthorizedKeyLine($agentUuid, $agentName, trim($publicKey), $allowedPaths, $appendOnly);
        $newContent = rtrim($content) . "\n" . $keyLine . "\n";
        $this->writeAuthorizedKeys($newContent);

        $queue->updateProgress($job->id, 100, "Terminé avec succès");

        $this->logger->info("Authorized_keys refreshed for agent: {$agentName}", 'AGENT');

        return json_encode([
            'success' => true,
            'agent_uuid' => $agentUuid,
            'paths_count' => count($allowedPaths),
            'repo_prepared' => $repoPath !== null
        ]);
    }

    /**
     * Préparer le répertoire du repo avec les bonnes permissions
     * Utilise un script helper qui valide que le path est dans un storage pool valide
     */
    private function ensureRepoDirectory(string $repoPath): void
    {
        $this->logger->info("Preparing repository directory: {$repoPath}", 'AGENT');

        $script = '/opt/newphpborg/phpBorg/bin/prepare-backup-directory.sh';
        $cmd = sprintf('sudo %s %s 2>&1', escapeshellarg($script), escapeshellarg($repoPath));

        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Échec de la préparation du répertoire: " . implode("\n", $output));
        }

        $this->logger->info("Repository directory prepared: {$repoPath}", 'AGENT');
    }

    /**
     * Initialiser le dépôt Borg en tant que phpborg-borg
     */
    private function initBorgRepository(string $repoPath, string $passphrase, string $encryption): void
    {
        $this->logger->info("Initializing Borg repository: {$repoPath}", 'AGENT');

        // Vérifier si le repo existe déjà
        $configFile = $repoPath . '/config';
        if (file_exists($configFile)) {
            $this->logger->info("Repository already initialized: {$repoPath}", 'AGENT');
            return;
        }

        // Exécuter borg init en tant que phpborg-borg
        $cmd = sprintf(
            'sudo -u %s BORG_PASSPHRASE=%s borg init --encryption=%s %s 2>&1',
            self::BORG_USER,
            escapeshellarg($passphrase),
            escapeshellarg($encryption),
            escapeshellarg($repoPath)
        );

        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $outputStr = implode("\n", $output);
            // Ignorer si déjà initialisé
            if (str_contains($outputStr, 'already exists') || str_contains($outputStr, 'already initialized')) {
                $this->logger->info("Repository already initialized (idempotent): {$repoPath}", 'AGENT');
                return;
            }
            throw new \RuntimeException("Échec de l'initialisation Borg: " . $outputStr);
        }

        $this->logger->info("Borg repository initialized: {$repoPath}", 'AGENT');
    }

    /**
     * Construire une entrée authorized_keys avec restrictions multiples
     */
    private function buildAuthorizedKeyLine(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        array $allowedPaths,
        bool $appendOnly
    ): string {
        // Construire la commande borg serve avec restrictions de chemin multiples
        $borgCommand = "borg serve";
        foreach ($allowedPaths as $path) {
            $borgCommand .= " --restrict-to-path " . escapeshellarg($path);
        }
        if ($appendOnly) {
            $borgCommand .= " --append-only";
        }

        // Construire les restrictions
        $restrictions = [
            "command=\"{$borgCommand}\"",
            'restrict',
        ];

        // Nettoyer la clé publique
        $keyParts = explode(' ', $publicKey);
        $keyType = $keyParts[0];
        $keyData = $keyParts[1];

        return implode(',', $restrictions) . " {$keyType} {$keyData} phpborg-agent-{$agentUuid} {$agentName}";
    }

    /**
     * Supprimer un agent des authorized_keys
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
     * S'assurer que le fichier authorized_keys existe via sudo
     */
    private function ensureAuthorizedKeysExists(): void
    {
        $dir = self::BORG_SSH_DIR;
        $path = self::AUTHORIZED_KEYS_PATH;

        // Créer le répertoire .ssh si nécessaire
        if (!is_dir($dir)) {
            $commands = [
                sprintf('sudo mkdir -p %s', escapeshellarg($dir)),
                sprintf('sudo chown %s:%s %s', self::BORG_USER, self::BORG_USER, escapeshellarg($dir)),
                sprintf('sudo chmod 700 %s', escapeshellarg($dir)),
            ];

            foreach ($commands as $cmd) {
                exec($cmd . ' 2>&1', $output, $returnCode);
                if ($returnCode !== 0) {
                    throw new \RuntimeException("Échec de la création du répertoire SSH");
                }
            }
        }

        // Créer le fichier authorized_keys si nécessaire
        if (!file_exists($path)) {
            $commands = [
                sprintf('sudo touch %s', escapeshellarg($path)),
                sprintf('sudo chown %s:%s %s', self::BORG_USER, self::BORG_USER, escapeshellarg($path)),
                sprintf('sudo chmod 600 %s', escapeshellarg($path)),
            ];

            foreach ($commands as $cmd) {
                exec($cmd . ' 2>&1', $output, $returnCode);
                if ($returnCode !== 0) {
                    throw new \RuntimeException("Échec de la création du fichier authorized_keys");
                }
            }
        }
    }

    /**
     * Lire le fichier authorized_keys via sudo
     */
    private function readAuthorizedKeys(): string
    {
        $path = self::AUTHORIZED_KEYS_PATH;

        if (!file_exists($path)) {
            $this->ensureAuthorizedKeysExists();
            return '';
        }

        $output = [];
        exec(sprintf('sudo cat %s 2>&1', escapeshellarg($path)), $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Échec de la lecture du fichier authorized_keys");
        }

        return implode("\n", $output);
    }

    /**
     * Écrire le fichier authorized_keys via sudo
     */
    private function writeAuthorizedKeys(string $content): void
    {
        $path = self::AUTHORIZED_KEYS_PATH;
        $tempFile = '/tmp/phpborg_authorized_keys_' . uniqid();

        // Écrire dans un fichier temporaire
        if (file_put_contents($tempFile, $content) === false) {
            throw new \RuntimeException("Échec de l'écriture du fichier temporaire");
        }

        // Déplacer avec sudo et définir les permissions
        $commands = [
            sprintf('sudo cp %s %s', escapeshellarg($tempFile), escapeshellarg($path)),
            sprintf('sudo chown %s:%s %s', self::BORG_USER, self::BORG_USER, escapeshellarg($path)),
            sprintf('sudo chmod 600 %s', escapeshellarg($path)),
        ];

        foreach ($commands as $cmd) {
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                @unlink($tempFile);
                throw new \RuntimeException("Échec de l'écriture du fichier authorized_keys");
            }
        }

        @unlink($tempFile);
    }
}
