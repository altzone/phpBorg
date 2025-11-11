<?php

declare(strict_types=1);

namespace PhpBorg\Service\Server;

use PhpBorg\Entity\Server;
use PhpBorg\Logger\LoggerInterface;

final class ServerStatsCollector
{
    public function __construct(
        private readonly SshExecutor $sshExecutor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Collect all system metrics from a remote server
     */
    public function collectStats(Server $server): array
    {
        $this->logger->info("Collecting stats for server: {$server->name}", 'StatsCollector');

        $stats = [];

        // System information
        $stats = array_merge($stats, $this->collectSystemInfo($server));

        // CPU metrics
        $stats = array_merge($stats, $this->collectCPUMetrics($server));

        // Memory metrics
        $stats = array_merge($stats, $this->collectMemoryMetrics($server));

        // Disk metrics
        $stats = array_merge($stats, $this->collectDiskMetrics($server));

        // Uptime
        $stats = array_merge($stats, $this->collectUptimeMetrics($server));

        // Network
        $stats = array_merge($stats, $this->collectNetworkInfo($server));

        $this->logger->info("Stats collection completed for: {$server->name}", 'StatsCollector');

        return $stats;
    }

    /**
     * Collect system information
     */
    private function collectSystemInfo(Server $server): array
    {
        $stats = [];

        // OS distribution
        $result = $this->sshExecutor->execute($server, 'cat /etc/os-release 2>/dev/null || echo ""', 10);
        $osRelease = $result['stdout'];

        if (preg_match('/^NAME="?([^"\n]+)"?/m', $osRelease, $matches)) {
            $stats['os_distribution'] = trim($matches[1]);
        }
        if (preg_match('/^VERSION="?([^"\n]+)"?/m', $osRelease, $matches)) {
            $stats['os_version'] = trim($matches[1]);
        }

        // Kernel version
        $result = $this->sshExecutor->execute($server, 'uname -r 2>/dev/null || echo ""', 10);
        $stats['kernel_version'] = trim($result['stdout']);

        // Hostname
        $result = $this->sshExecutor->execute($server, 'hostname 2>/dev/null || echo ""', 10);
        $stats['hostname'] = trim($result['stdout']);

        // Architecture
        $result = $this->sshExecutor->execute($server, 'uname -m 2>/dev/null || echo ""', 10);
        $stats['architecture'] = trim($result['stdout']);

        return $stats;
    }

    /**
     * Collect CPU metrics
     */
    private function collectCPUMetrics(Server $server): array
    {
        $stats = [];

        // CPU cores
        $result = $this->sshExecutor->execute($server, 'nproc 2>/dev/null || echo "0"', 10);
        $stats['cpu_cores'] = (int)trim($result['stdout']) ?: null;

        // CPU model
        $result = $this->sshExecutor->execute($server, 'grep "model name" /proc/cpuinfo | head -1 | cut -d":" -f2 2>/dev/null || echo ""', 10);
        $stats['cpu_model'] = trim($result['stdout']) ?: null;

        // Load averages
        $result = $this->sshExecutor->execute($server, 'cat /proc/loadavg 2>/dev/null || echo "0 0 0"', 10);
        $loads = explode(' ', trim($result['stdout']));
        $stats['cpu_load_1'] = isset($loads[0]) ? (float)$loads[0] : null;
        $stats['cpu_load_5'] = isset($loads[1]) ? (float)$loads[1] : null;
        $stats['cpu_load_15'] = isset($loads[2]) ? (float)$loads[2] : null;

        // CPU usage percentage
        $result = $this->sshExecutor->execute($server, 'top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk \'{print 100 - $1}\' 2>/dev/null || echo "0"', 10);
        $stats['cpu_usage_percent'] = round((float)trim($result['stdout']), 2) ?: null;

        return $stats;
    }

    /**
     * Collect memory metrics
     */
    private function collectMemoryMetrics(Server $server): array
    {
        $stats = [];

        $result = $this->sshExecutor->execute($server, 'free -m 2>/dev/null || echo ""', 10);
        $lines = explode("\n", trim($result['stdout']));

        foreach ($lines as $line) {
            if (preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                $total = (int)$matches[1];
                $used = (int)$matches[2];
                $free = (int)$matches[3];
                $available = (int)$matches[4];

                $stats['memory_total_mb'] = $total;
                $stats['memory_used_mb'] = $used;
                $stats['memory_free_mb'] = $free;
                $stats['memory_available_mb'] = $available;
                $stats['memory_percent'] = $total > 0 ? round(($used / $total) * 100, 2) : null;
            }

            if (preg_match('/^Swap:\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches)) {
                $swapTotal = (int)$matches[1];
                $swapUsed = (int)$matches[2];

                $stats['swap_total_mb'] = $swapTotal;
                $stats['swap_used_mb'] = $swapUsed;
                $stats['swap_percent'] = $swapTotal > 0 ? round(($swapUsed / $swapTotal) * 100, 2) : 0;
            }
        }

        return $stats;
    }

    /**
     * Collect disk metrics
     */
    private function collectDiskMetrics(Server $server): array
    {
        $stats = [];

        // Get root filesystem stats
        $result = $this->sshExecutor->execute($server, 'df -BG / | tail -1 2>/dev/null || echo ""', 10);
        $df = $result['stdout'];

        if (preg_match('/\s+(\d+)G\s+(\d+)G\s+(\d+)G\s+(\d+)%/', $df, $matches)) {
            $total = (float)$matches[1];
            $used = (float)$matches[2];
            $free = (float)$matches[3];
            $percent = (float)$matches[4];

            $stats['disk_total_gb'] = $total;
            $stats['disk_used_gb'] = $used;
            $stats['disk_free_gb'] = $free;
            $stats['disk_percent'] = $percent;
            $stats['disk_mount_point'] = '/';
        }

        return $stats;
    }

    /**
     * Collect uptime metrics
     */
    private function collectUptimeMetrics(Server $server): array
    {
        $stats = [];

        // Uptime in seconds
        $result = $this->sshExecutor->execute($server, 'cat /proc/uptime 2>/dev/null | cut -d" " -f1 || echo "0"', 10);
        $uptimeSeconds = (int)trim(explode('.', $result['stdout'])[0]);
        $stats['uptime_seconds'] = $uptimeSeconds;

        // Human-readable uptime
        $stats['uptime_human'] = $this->formatUptime($uptimeSeconds);

        // Boot time (calculate from current time - uptime)
        if ($uptimeSeconds > 0) {
            $bootTimestamp = time() - $uptimeSeconds;
            $stats['boot_time'] = date('Y-m-d H:i:s', $bootTimestamp);
        }

        return $stats;
    }

    /**
     * Collect network information
     */
    private function collectNetworkInfo(Server $server): array
    {
        $stats = [];

        // Get primary IP address
        $result = $this->sshExecutor->execute($server, 'hostname -I 2>/dev/null | awk \'{print $1}\' || echo ""', 10);
        $stats['ip_address'] = trim($result['stdout']) ?: null;

        return $stats;
    }

    /**
     * Format uptime seconds into human-readable string
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $days = floor($hours / 24);

        if ($days > 0) {
            $hours = $hours % 24;
            return sprintf("%d days, %d hours", $days, $hours);
        }

        if ($hours > 0) {
            $minutes = $minutes % 60;
            return sprintf("%d hours, %d minutes", $hours, $minutes);
        }

        return sprintf("%d minutes", $minutes);
    }
}
