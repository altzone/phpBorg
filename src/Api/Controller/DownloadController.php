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
    private const RELEASES_DIR = PHPBORG_ROOT . '/releases';

    public function __construct(Application $app)
    {
        // No dependencies needed
    }

    /**
     * GET /downloads/phpborg-agent
     * Download the phpborg-agent binary
     */
    public function agentBinary(): void
    {
        $binaryPath = self::RELEASES_DIR . '/agent/phpborg-agent';

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
        $checksumPath = self::RELEASES_DIR . '/agent/phpborg-agent.sha256';

        if (!file_exists($checksumPath)) {
            // Generate on the fly if binary exists
            $binaryPath = self::RELEASES_DIR . '/agent/phpborg-agent';
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
        $binaryPath = self::RELEASES_DIR . '/agent/phpborg-agent';

        if (!file_exists($binaryPath)) {
            $this->success([
                'available' => false,
                'message' => 'Agent binary not built yet',
            ]);
            return;
        }

        $this->success([
            'available' => true,
            'size' => filesize($binaryPath),
            'size_human' => $this->formatBytes(filesize($binaryPath)),
            'checksum' => hash_file('sha256', $binaryPath),
            'modified_at' => date('Y-m-d H:i:s', filemtime($binaryPath)),
            'download_url' => '/downloads/phpborg-agent',
        ]);
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
