<?php

declare(strict_types=1);

namespace PhpBorg\Service\Queue\Handlers;

use PhpBorg\Entity\Job;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Agent\CertificateManager;
use PhpBorg\Service\Queue\JobQueue;

/**
 * Handler pour le déploiement asynchrone des clés SSH des agents
 *
 * Ce handler est exécuté par le worker qui peut utiliser sudo pour créer
 * les répertoires et fichiers appartenant à phpborg-borg.
 */
final class DeployAgentKeyHandler implements JobHandlerInterface
{
    private const BORG_USER = 'phpborg-borg';
    private const BORG_SSH_DIR = '/var/lib/phpborg-borg/.ssh';
    private const AUTHORIZED_KEYS_PATH = '/var/lib/phpborg-borg/.ssh/authorized_keys';
    private const BACKUPS_BASE_PATH = '/opt/backups';

    public function __construct(
        private readonly CertificateManager $certManager,
        private readonly LoggerInterface $logger,
        private readonly int $borgSshPort = 2222
    ) {
    }

    public function handle(Job $job, JobQueue $queue): string
    {
        $payload = $job->payload;

        $agentUuid = $payload['agent_uuid'] ?? null;
        $agentName = $payload['agent_name'] ?? null;
        $publicKey = $payload['public_key'] ?? null;
        $appendOnly = $payload['append_only'] ?? true;

        if (!$agentUuid || !$agentName || !$publicKey) {
            throw new \InvalidArgumentException('Paramètres manquants: agent_uuid, agent_name, public_key requis');
        }

        $queue->updateProgress($job->id, 0, "Démarrage du déploiement de la clé pour l'agent {$agentName}");

        // Étape 1: Valider la clé publique
        $queue->updateProgress($job->id, 10, "Validation de la clé publique Ed25519...");
        $this->validatePublicKey($publicKey);

        // Étape 2: Créer le répertoire de backup pour l'agent (avec sudo)
        $queue->updateProgress($job->id, 30, "Création du répertoire de backup...");
        $backupPath = $this->createAgentBackupDirectory($agentUuid);

        // Étape 3: Ajouter la clé SSH aux authorized_keys (avec sudo)
        $queue->updateProgress($job->id, 50, "Configuration de l'accès SSH...");
        $this->addAuthorizedKey($agentUuid, $agentName, $publicKey, $backupPath, $appendOnly);

        // Étape 4: Générer les certificats mTLS
        $queue->updateProgress($job->id, 70, "Génération des certificats mTLS...");
        $certificates = $this->certManager->generateAgentCertificate($agentUuid, $agentName);

        // Étape 5: Préparer le résultat
        $queue->updateProgress($job->id, 90, "Finalisation...");

        $result = [
            'success' => true,
            'backup_path' => $backupPath,
            'ssh_port' => $this->borgSshPort,
            'ssh_user' => self::BORG_USER,
            'mtls_enabled' => true,
            'certificates' => [
                'cert' => base64_encode($certificates['cert']),
                'key' => base64_encode($certificates['key']),
                'ca' => base64_encode($certificates['ca']),
            ],
        ];

        // Stocker le résultat dans Redis pour récupération par l'agent
        $queue->setProgressInfo($job->id, $result, 3600); // TTL 1 heure

        $queue->updateProgress($job->id, 100, "Déploiement terminé avec succès");

        $this->logger->info("Agent {$agentName} ({$agentUuid}) déployé avec succès", 'AGENT');

        return json_encode($result);
    }

    /**
     * Valider que la clé publique est au format Ed25519
     */
    private function validatePublicKey(string $publicKey): void
    {
        $publicKey = trim($publicKey);

        if (!str_starts_with($publicKey, 'ssh-ed25519 ')) {
            throw new \InvalidArgumentException(
                'Seules les clés Ed25519 sont acceptées. La clé doit commencer par "ssh-ed25519"'
            );
        }

        $parts = explode(' ', $publicKey);
        if (count($parts) < 2) {
            throw new \InvalidArgumentException('Format de clé publique invalide');
        }

        $keyData = $parts[1];
        if (base64_decode($keyData, true) === false) {
            throw new \InvalidArgumentException('Clé publique invalide: données base64 malformées');
        }
    }

    /**
     * Créer le répertoire de backup pour l'agent via sudo
     */
    private function createAgentBackupDirectory(string $agentUuid): string
    {
        $backupPath = self::BACKUPS_BASE_PATH . '/' . $agentUuid;

        if (!is_dir($backupPath)) {
            // Utiliser sudo pour créer le répertoire avec les bonnes permissions
            $commands = [
                sprintf('sudo mkdir -p %s', escapeshellarg($backupPath)),
                sprintf('sudo chown %s:%s %s', self::BORG_USER, self::BORG_USER, escapeshellarg($backupPath)),
                sprintf('sudo chmod 750 %s', escapeshellarg($backupPath)),
            ];

            foreach ($commands as $cmd) {
                $output = [];
                $returnCode = 0;
                exec($cmd . ' 2>&1', $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new \RuntimeException(
                        "Échec de la création du répertoire de backup: " . implode("\n", $output)
                    );
                }
            }

            $this->logger->info("Répertoire de backup créé: {$backupPath}", 'AGENT');
        }

        return $backupPath;
    }

    /**
     * Ajouter la clé SSH aux authorized_keys avec restriction borg serve
     */
    private function addAuthorizedKey(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        string $backupPath,
        bool $appendOnly
    ): void {
        // S'assurer que le fichier authorized_keys existe
        $this->ensureAuthorizedKeysExists();

        // Lire le contenu actuel
        $content = $this->readAuthorizedKeys();

        // Vérifier si la clé existe déjà
        if (str_contains($content, "phpborg-agent-{$agentUuid}")) {
            $this->logger->info("Clé agent existante, mise à jour: {$agentUuid}", 'AGENT');
            $this->removeAuthorizedKey($agentUuid);
            $content = $this->readAuthorizedKeys();
        }

        // Construire l'entrée authorized_keys
        $keyLine = $this->buildAuthorizedKeyLine($agentUuid, $agentName, trim($publicKey), $backupPath, $appendOnly);

        // Ajouter au fichier
        $newContent = rtrim($content) . "\n" . $keyLine . "\n";
        $this->writeAuthorizedKeys($newContent);

        $this->logger->info("Clé SSH ajoutée pour l'agent: {$agentUuid}", 'AGENT');
    }

    /**
     * Construire une entrée authorized_keys restreinte
     */
    private function buildAuthorizedKeyLine(
        string $agentUuid,
        string $agentName,
        string $publicKey,
        string $backupPath,
        bool $appendOnly
    ): string {
        // Construire la commande borg serve avec restriction de chemin
        $borgCommand = "borg serve --restrict-to-path {$backupPath}";
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
     * S'assurer que le fichier authorized_keys existe
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
