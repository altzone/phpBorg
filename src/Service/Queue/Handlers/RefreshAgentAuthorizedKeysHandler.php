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

        if (!$agentUuid || !$agentName || !$publicKey) {
            throw new \InvalidArgumentException('Paramètres manquants: agent_uuid, agent_name, public_key requis');
        }

        $queue->updateProgress($job->id, 10, "Rafraîchissement des authorized_keys pour {$agentName}...");

        $this->logger->info("Refreshing authorized_keys for agent: {$agentName} with " . count($allowedPaths) . " paths", 'AGENT');

        // Supprimer l'ancienne entrée
        $this->removeAuthorizedKey($agentUuid);

        // Ajouter la nouvelle entrée avec tous les paths
        $content = $this->readAuthorizedKeys();
        $keyLine = $this->buildAuthorizedKeyLine($agentUuid, $agentName, trim($publicKey), $allowedPaths, $appendOnly);
        $newContent = rtrim($content) . "\n" . $keyLine . "\n";
        $this->writeAuthorizedKeys($newContent);

        $queue->updateProgress($job->id, 100, "Authorized_keys rafraîchi avec succès");

        $this->logger->info("Authorized_keys refreshed for agent: {$agentName}", 'AGENT');

        return json_encode([
            'success' => true,
            'agent_uuid' => $agentUuid,
            'paths_count' => count($allowedPaths)
        ]);
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
     * S'assurer que le fichier authorized_keys existe
     */
    private function ensureAuthorizedKeysExists(): void
    {
        $dir = self::BORG_SSH_DIR;
        $path = self::AUTHORIZED_KEYS_PATH;

        // Créer le répertoire .ssh si nécessaire (on tourne en phpborg-borg, pas besoin de sudo)
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \RuntimeException("Échec de la création du répertoire SSH: {$dir}");
            }
        }

        // Créer le fichier authorized_keys si nécessaire
        if (!file_exists($path)) {
            if (touch($path) === false) {
                throw new \RuntimeException("Échec de la création du fichier authorized_keys");
            }
            chmod($path, 0600);
        }
    }

    /**
     * Lire le fichier authorized_keys
     */
    private function readAuthorizedKeys(): string
    {
        $path = self::AUTHORIZED_KEYS_PATH;

        if (!file_exists($path)) {
            $this->ensureAuthorizedKeysExists();
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Échec de la lecture du fichier authorized_keys");
        }

        return $content;
    }

    /**
     * Écrire le fichier authorized_keys
     */
    private function writeAuthorizedKeys(string $content): void
    {
        $path = self::AUTHORIZED_KEYS_PATH;

        $this->ensureAuthorizedKeysExists();

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Échec de l'écriture du fichier authorized_keys");
        }

        // S'assurer des bonnes permissions
        chmod($path, 0600);
    }
}
