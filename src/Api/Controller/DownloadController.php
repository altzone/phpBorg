<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;

/**
 * Download Controller
 *
 * Serves downloadable files like the phpborg-agent binary
 */
final class DownloadController extends BaseController
{
    private readonly string $releasesDir;

    public function __construct(Application $app)
    {
        // Get phpBorg root from the application directory
        $this->releasesDir = dirname(__DIR__, 3) . '/releases';
    }

    /**
     * Resolve the agent binary path (Bug 14). The build publishes arch-suffixed
     * binaries (phpborg-agent-linux-amd64/arm64); older code served a plain
     * `phpborg-agent` that was never produced -> 404. Resolve the arch from ?arch=
     * or the User-Agent (default amd64), then fall back to the legacy plain name.
     */
    private function resolveAgentBinaryPath(): ?string
    {
        $arch = $_GET['arch'] ?? null;
        if ($arch === null) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $arch = (stripos($ua, 'aarch64') !== false || stripos($ua, 'arm64') !== false) ? 'arm64' : 'amd64';
        }
        $arch = $arch === 'arm64' ? 'arm64' : 'amd64';

        $candidates = [
            $this->releasesDir . "/agent/phpborg-agent-linux-{$arch}",
            $this->releasesDir . '/agent/phpborg-agent',              // legacy default (if published)
            $this->releasesDir . '/agent/phpborg-agent-linux-amd64',  // last-resort
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * GET /downloads/phpborg-agent
     * Download the phpborg-agent binary
     */
    public function agentBinary(): void
    {
        $binaryPath = $this->resolveAgentBinaryPath();

        if ($binaryPath === null) {
            $this->error('Agent binary not found. Please run the build process.', 404, 'BINARY_NOT_FOUND');
            return;
        }

        // Get file info
        $fileSize = filesize($binaryPath);
        $checksum = hash_file('sha256', $binaryPath);

        // Send headers
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="phpborg-agent"');
        header('Content-Length: ' . $fileSize);
        header('Content-Transfer-Encoding: binary');
        header('X-Checksum-SHA256: ' . $checksum);
        header('Cache-Control: no-cache, must-revalidate');

        // Stream the file
        readfile($binaryPath);
        exit;
    }

    /**
     * GET /downloads/phpborg-agent.sha256
     * Download the checksum file
     */
    public function agentChecksum(): void
    {
        $binaryPath = $this->resolveAgentBinaryPath();
        $checksumPath = $binaryPath ? $binaryPath . '.sha256' : null;

        if ($checksumPath === null || !file_exists($checksumPath)) {
            // Generate on the fly if the binary exists
            if ($binaryPath !== null && file_exists($binaryPath)) {
                $checksum = hash_file('sha256', $binaryPath);
                header('Content-Type: text/plain');
                echo $checksum . '  ' . basename($binaryPath) . "\n";
                exit;
            }

            $this->error('Checksum file not found', 404, 'CHECKSUM_NOT_FOUND');
            return;
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: inline; filename="phpborg-agent.sha256"');
        readfile($checksumPath);
        exit;
    }

    /**
     * GET /downloads/agent-info
     * Get information about the available agent binary
     */
    public function agentInfo(): void
    {
        $binaryPath = $this->resolveAgentBinaryPath();

        // Get version from source code (always available)
        $version = self::getLatestAgentVersion();

        if ($binaryPath === null || !file_exists($binaryPath)) {
            $this->success([
                'available' => false,
                'version' => $version,
                'message' => 'Agent binary not built yet',
            ]);
            return;
        }

        $this->success([
            'available' => true,
            'version' => $version,
            'size' => filesize($binaryPath),
            'size_human' => $this->formatBytes(filesize($binaryPath)),
            'checksum' => hash_file('sha256', $binaryPath),
            'modified_at' => date('Y-m-d H:i:s', filemtime($binaryPath)),
            'download_url' => '/downloads/phpborg-agent',
        ]);
    }

    /**
     * Get the latest agent version from source code
     * Used internally by other controllers
     */
    public static function getLatestAgentVersion(): ?string
    {
        // Read version from Go source (single source of truth)
        $mainGoPath = dirname(__DIR__, 3) . '/agent/cmd/phpborg-agent/main.go';
        if (file_exists($mainGoPath)) {
            $content = file_get_contents($mainGoPath);
            // Parse: const Version = "1.0.0"
            if (preg_match('/const\s+Version\s*=\s*"([^"]+)"/', $content, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
