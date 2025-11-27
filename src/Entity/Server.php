<?php

declare(strict_types=1);

namespace PhpBorg\Entity;

/**
 * Server entity representing a backup server configuration
 */
final readonly class Server
{
    public function __construct(
        public int $id,
        public string $name,
        public string $host,
        public int $port,
        public string $backupType,
        public string $sshPublicKey,
        public bool $active,
        public bool $capabilitiesDetected = false,
        public ?string $capabilitiesData = null,
        public ?\DateTime $capabilitiesDetectedAt = null,
        // Agent fields
        public ?string $agentUuid = null,
        public string $agentStatus = 'none',
        public ?\DateTime $agentLastHeartbeat = null,
        public ?string $agentVersion = null,
        public string $connectionMode = 'ssh',
    ) {
    }

    /**
     * Create Server from database row
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabase(array $row): self
    {
        $capabilitiesDetectedAt = null;
        if (!empty($row['capabilities_detected_at'])) {
            $capabilitiesDetectedAt = new \DateTime($row['capabilities_detected_at']);
        }

        $agentLastHeartbeat = null;
        if (!empty($row['agent_last_heartbeat'])) {
            $agentLastHeartbeat = new \DateTime($row['agent_last_heartbeat']);
        }

        return new self(
            id: (int)$row['id'],
            name: (string)$row['name'],
            host: (string)$row['host'],
            port: (int)$row['port'],
            backupType: (string)($row['backuptype'] ?? 'internal'),
            sshPublicKey: (string)$row['ssh_pub_key'],
            active: (bool)$row['active'],
            capabilitiesDetected: (bool)($row['capabilities_detected'] ?? false),
            capabilitiesData: $row['capabilities_data'] ?? null,
            capabilitiesDetectedAt: $capabilitiesDetectedAt,
            // Agent fields
            agentUuid: $row['agent_uuid'] ?? null,
            agentStatus: $row['agent_status'] ?? 'none',
            agentLastHeartbeat: $agentLastHeartbeat,
            agentVersion: $row['agent_version'] ?? null,
            connectionMode: $row['connection_mode'] ?? 'ssh',
        );
    }

    /**
     * Convert to array for database storage
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'backuptype' => $this->backupType,
            'ssh_pub_key' => $this->sshPublicKey,
            'active' => $this->active ? 1 : 0,
            'capabilities_detected' => $this->capabilitiesDetected ? 1 : 0,
            'capabilities_data' => $this->capabilitiesData,
            'capabilities_detected_at' => $this->capabilitiesDetectedAt?->format('Y-m-d H:i:s'),
            // Agent fields
            'agent_uuid' => $this->agentUuid,
            'agent_status' => $this->agentStatus,
            'agent_last_heartbeat' => $this->agentLastHeartbeat?->format('Y-m-d H:i:s'),
            'agent_version' => $this->agentVersion,
            'connection_mode' => $this->connectionMode,
        ];
    }

    /**
     * Check if server uses agent connection
     */
    public function usesAgent(): bool
    {
        return $this->connectionMode === 'agent';
    }

    /**
     * Check if agent is active (heartbeat within last 5 minutes)
     */
    public function isAgentActive(): bool
    {
        if (!$this->usesAgent() || $this->agentLastHeartbeat === null) {
            return false;
        }

        $threshold = new \DateTime('-5 minutes');
        return $this->agentLastHeartbeat > $threshold;
    }

    /**
     * Get human-readable agent status
     */
    public function getAgentStatusLabel(): string
    {
        return match ($this->agentStatus) {
            'none' => 'No Agent',
            'pending' => 'Pending Installation',
            'installing' => 'Installing...',
            'active' => $this->isAgentActive() ? 'Online' : 'Offline',
            'inactive' => 'Offline',
            'error' => 'Error',
            default => 'Unknown',
        };
    }
}
