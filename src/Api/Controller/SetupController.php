<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Setup wizard API controller
 * Handles first-run configuration
 */
class SetupController extends BaseController
{
    private readonly SettingRepository $settingRepository;

    public function __construct(Application $app)
    {
        $this->settingRepository = new SettingRepository($app->getConnection());
    }

    /**
     * GET /api/setup/status
     * Check if initial setup is completed
     */
    public function status(): void
    {
        try {
            // Check if app.name is set (indicates setup completed)
            $appName = $this->settingRepository->findByKey('app.name');
            $setupCompleted = $this->settingRepository->findByKey('setup.completed');

            $isSetupRequired = !$setupCompleted || $setupCompleted->value !== 'true';

            // Get defaults for the wizard
            $defaults = [];
            if ($isSetupRequired) {
                $defaults = $this->getDefaults();
            }

            $this->success([
                'setup_required' => $isSetupRequired,
                'defaults' => $defaults,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETUP_STATUS_ERROR');
        }
    }

    /**
     * GET /api/setup/detect-network
     * Detect available network interfaces and IPs
     */
    public function detectNetwork(): void
    {
        try {
            $privateIps = $this->detectPrivateIps();
            $publicIp = $this->detectPublicIp();

            $this->success([
                'private_ips' => $privateIps,
                'public_ip' => $publicIp,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'NETWORK_DETECT_ERROR');
        }
    }

    /**
     * POST /api/setup/complete
     * Complete initial setup with provided settings
     */
    public function complete(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $requiredFields = ['app_name', 'timezone', 'language', 'internal_ip', 'external_ip'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    $this->error("Field '$field' is required", 400, 'MISSING_FIELD');
                    return;
                }
            }

            // Save settings
            $this->saveOrCreateSetting('app.name', $data['app_name'], 'general', 'string', 'Application name');
            $this->saveOrCreateSetting('app.timezone', $data['timezone'], 'general', 'string', 'Default timezone');
            $this->saveOrCreateSetting('app.language', $data['language'], 'general', 'string', 'Default language');
            $this->saveOrCreateSetting('network.internal_ip', $data['internal_ip'], 'network', 'string', 'Internal IP address');
            $this->saveOrCreateSetting('network.external_ip', $data['external_ip'], 'network', 'string', 'External IP address');

            // Optional email settings - use smtp.* keys to match SettingsView and EmailService
            if (!empty($data['email_enabled'])) {
                $this->saveOrCreateSetting('smtp.enabled', $data['email_enabled'] ? 'true' : 'false', 'smtp', 'boolean', 'Enable email notifications');

                if (!empty($data['smtp_host'])) {
                    $this->saveOrCreateSetting('smtp.host', $data['smtp_host'], 'smtp', 'string', 'SMTP server host');
                }
                if (!empty($data['smtp_port'])) {
                    $this->saveOrCreateSetting('smtp.port', (string)$data['smtp_port'], 'smtp', 'integer', 'SMTP server port');
                }
                if (!empty($data['smtp_username'])) {
                    $this->saveOrCreateSetting('smtp.username', $data['smtp_username'], 'smtp', 'string', 'SMTP username');
                }
                if (!empty($data['smtp_password'])) {
                    $this->saveOrCreateSetting('smtp.password', $data['smtp_password'], 'smtp', 'string', 'SMTP password');
                }
                if (!empty($data['smtp_encryption'])) {
                    $this->saveOrCreateSetting('smtp.encryption', $data['smtp_encryption'], 'smtp', 'string', 'SMTP encryption (tls/ssl)');
                }
                if (!empty($data['email_from'])) {
                    $this->saveOrCreateSetting('smtp.from_email', $data['email_from'], 'smtp', 'string', 'From email address');
                }
                if (!empty($data['email_from_name'])) {
                    $this->saveOrCreateSetting('smtp.from_name', $data['email_from_name'], 'smtp', 'string', 'From name');
                }
            }

            // Optional retention policy settings (use same keys as SettingsView)
            if (isset($data['keep_daily'])) {
                $this->saveOrCreateSetting('backup.retention.daily', (string)$data['keep_daily'], 'backup', 'integer', 'Keep N daily backups');
            }
            if (isset($data['keep_weekly'])) {
                $this->saveOrCreateSetting('backup.retention.weekly', (string)$data['keep_weekly'], 'backup', 'integer', 'Keep N weekly backups');
            }
            if (isset($data['keep_monthly'])) {
                $this->saveOrCreateSetting('backup.retention.monthly', (string)$data['keep_monthly'], 'backup', 'integer', 'Keep N monthly backups');
            }
            if (isset($data['keep_yearly'])) {
                $this->saveOrCreateSetting('backup.retention.yearly', (string)$data['keep_yearly'], 'backup', 'integer', 'Keep N yearly backups');
            }

            // Mark setup as completed
            $this->saveOrCreateSetting('setup.completed', 'true', 'general', 'boolean', 'Initial setup completed');

            $this->success(
                ['setup_completed' => true],
                'Setup completed successfully'
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETUP_COMPLETE_ERROR');
        }
    }

    /**
     * Get default values for the wizard
     */
    private function getDefaults(): array
    {
        // Detect timezone from system
        $timezone = date_default_timezone_get();
        if ($timezone === 'UTC') {
            // Try to get from /etc/timezone
            if (file_exists('/etc/timezone')) {
                $timezone = trim(file_get_contents('/etc/timezone')) ?: 'Europe/Paris';
            }
        }

        return [
            'app_name' => 'phpBorg',
            'timezone' => $timezone,
            'language' => 'fr',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            // Retention policy defaults
            'keep_daily' => 7,
            'keep_weekly' => 4,
            'keep_monthly' => 6,
            'keep_yearly' => 1,
        ];
    }

    /**
     * Detect private IPs (excluding docker, lo, veth)
     */
    private function detectPrivateIps(): array
    {
        $ips = [];

        // Get all IPs using ip command
        $output = shell_exec("ip -4 addr show | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'");
        if ($output) {
            $allIps = array_filter(array_map('trim', explode("\n", $output)));

            // Get interfaces to filter
            foreach ($allIps as $ip) {
                // Skip loopback
                if ($ip === '127.0.0.1') {
                    continue;
                }

                // Check if IP belongs to docker/veth interface
                $interface = shell_exec("ip -4 addr show | grep -B2 '$ip' | head -1 | awk '{print \$2}' | tr -d ':'");
                $interface = trim($interface ?? '');

                // Skip docker, veth, br- interfaces
                if (preg_match('/^(docker|veth|br-|virbr|lxc)/', $interface)) {
                    continue;
                }

                // Check if it's a private IP (RFC1918)
                if ($this->isPrivateIp($ip)) {
                    $ips[] = [
                        'ip' => $ip,
                        'interface' => $interface,
                    ];
                }
            }
        }

        return $ips;
    }

    /**
     * Detect public IP using external service
     */
    private function detectPublicIp(): ?string
    {
        // Try multiple services
        $services = [
            'https://api.ipify.org',
            'https://ifconfig.me/ip',
            'https://icanhazip.com',
        ];

        foreach ($services as $service) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET',
                ],
            ]);

            $ip = @file_get_contents($service, false, $context);
            if ($ip !== false) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Check if IP is private (RFC1918)
     */
    private function isPrivateIp(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }

        $first = (int)$parts[0];
        $second = (int)$parts[1];

        // 10.0.0.0/8
        if ($first === 10) {
            return true;
        }

        // 172.16.0.0/12
        if ($first === 172 && $second >= 16 && $second <= 31) {
            return true;
        }

        // 192.168.0.0/16
        if ($first === 192 && $second === 168) {
            return true;
        }

        return false;
    }

    /**
     * Save or create setting
     */
    private function saveOrCreateSetting(string $key, string $value, string $category, string $type, string $description): void
    {
        $existing = $this->settingRepository->findByKey($key);

        if ($existing) {
            $this->settingRepository->updateValue($key, $value);
        } else {
            $this->settingRepository->create($key, $value, $category, $type, $description);
        }
    }
}
