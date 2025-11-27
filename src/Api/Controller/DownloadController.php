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
     * GET /downloads/phpborg-agent
     * Download the phpborg-agent binary
     */
    public function agentBinary(): void
    {
        $binaryPath = $this->releasesDir . '/agent/phpborg-agent';

        if (!file_exists($binaryPath)) {
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
        $checksumPath = $this->releasesDir . '/agent/phpborg-agent.sha256';

        if (!file_exists($checksumPath)) {
            // Generate on the fly if binary exists
            $binaryPath = $this->releasesDir . '/agent/phpborg-agent';
            if (file_exists($binaryPath)) {
                $checksum = hash_file('sha256', $binaryPath);
                header('Content-Type: text/plain');
                echo $checksum . "  phpborg-agent\n";
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
        $binaryPath = $this->releasesDir . '/agent/phpborg-agent';
        $versionPath = $this->releasesDir . '/agent/VERSION';

        if (!file_exists($binaryPath)) {
            $this->success([
                'available' => false,
                'message' => 'Agent binary not built yet',
            ]);
            return;
        }

        // Read version from VERSION file
        $version = null;
        if (file_exists($versionPath)) {
            $version = trim(file_get_contents($versionPath));
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
     * Get the latest agent version
     * Used internally by other controllers
     */
    public static function getLatestAgentVersion(): ?string
    {
        $versionPath = dirname(__DIR__, 3) . '/releases/agent/VERSION';
        if (file_exists($versionPath)) {
            return trim(file_get_contents($versionPath));
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
