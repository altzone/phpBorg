<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * Settings management API controller
 */
class SettingsController extends BaseController
{
    private readonly SettingRepository $settingRepository;

    public function __construct(Application $app)
    {
        $this->settingRepository = new SettingRepository($app->getConnection());
    }

    /**
     * GET /api/settings
     * Get all settings grouped by category
     */
    public function list(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            // Get all settings grouped by category
            $settings = $this->settingRepository->getAllGroupedByCategory();

            $this->success(['settings' => $settings]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETTINGS_LIST_ERROR');
        }
    }

    /**
     * GET /api/settings/:category
     * Get settings by category
     */
    public function getByCategory(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            $category = $_SERVER['ROUTE_PARAMS']['category'] ?? '';

            if (empty($category)) {
                $this->error('Category is required', 400, 'INVALID_CATEGORY');
                return;
            }

            // Map email -> smtp for storage
            $fetchCategory = ($category === 'email') ? 'smtp' : $category;
            $settings = $this->settingRepository->findByCategory($fetchCategory);

            // Convert to key-value array
            $result = [];
            foreach ($settings as $setting) {
                $result[$setting->key] = $setting->getTypedValue();
            }

            $this->success([
                'category' => $category,
                'settings' => $result,
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETTINGS_GET_ERROR');
        }
    }

    /**
     * PUT /api/settings
     * Update multiple settings
     */
    public function update(): void
    {
        try {
            // Only ROLE_ADMIN can update settings
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            if (!isset($data['settings']) || !is_array($data['settings'])) {
                $this->error('Settings array is required', 400, 'INVALID_SETTINGS');
                return;
            }

            // Update each setting
            foreach ($data['settings'] as $key => $value) {
                // Verify setting exists
                $setting = $this->settingRepository->findByKey($key);
                if (!$setting) {
                    continue; // Skip non-existent settings
                }

                // Validate value based on type
                if (!$this->validateSettingValue($value, $setting->type)) {
                    $this->error("Invalid value for setting: $key", 400, 'INVALID_VALUE');
                    return;
                }

                $this->settingRepository->updateValue($key, $value);
            }

            // Get updated settings
            $settings = $this->settingRepository->getAllGroupedByCategory();

            $this->success(
                ['settings' => $settings],
                'Settings updated successfully'
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETTINGS_UPDATE_ERROR');
        }
    }

    /**
     * PUT /api/settings/:category
     * Update settings for a specific category
     */
    public function updateCategory(): void
    {
        try {
            // Only ROLE_ADMIN can update settings
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $category = $_SERVER['ROUTE_PARAMS']['category'] ?? '';

            if (empty($category)) {
                $this->error('Category is required', 400, 'INVALID_CATEGORY');
                return;
            }

            $data = $this->getJsonBody();

            if (!isset($data['settings']) || !is_array($data['settings'])) {
                $this->error('Settings object is required', 400, 'INVALID_SETTINGS');
                return;
            }

            // Define allowed settings per category (with types)
            $allowedSettings = $this->getAllowedSettingsForCategory($category);

            // Update or create each setting
            foreach ($data['settings'] as $key => $value) {
                // Check if this key is allowed for this category
                if (!isset($allowedSettings[$key])) {
                    continue;
                }

                $settingDef = $allowedSettings[$key];
                $type = $settingDef['type'];

                // Validate value based on type
                if (!$this->validateSettingValue($value, $type)) {
                    $this->error("Invalid value for setting: $key", 400, 'INVALID_VALUE');
                    return;
                }

                // Determine the actual storage category (email -> smtp)
                $storageCategory = ($category === 'email') ? 'smtp' : $category;

                // Check if setting exists
                $setting = $this->settingRepository->findByKey($key);
                if ($setting) {
                    // Update existing
                    $this->settingRepository->updateValue($key, $value);
                } else {
                    // Create new setting (create() handles value formatting)
                    $this->settingRepository->create(
                        $key,
                        $value,
                        $storageCategory,
                        $type,
                        $settingDef['description'] ?? ''
                    );
                }
            }

            // Get updated settings for this category (email -> smtp)
            $fetchCategory = ($category === 'email') ? 'smtp' : $category;
            $settings = $this->settingRepository->findByCategory($fetchCategory);
            $result = [];
            foreach ($settings as $setting) {
                $result[$setting->key] = $setting->getTypedValue();
            }

            $this->success(
                [
                    'category' => $category,
                    'settings' => $result,
                ],
                'Settings updated successfully'
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'SETTINGS_UPDATE_ERROR');
        }
    }

    /**
     * Get allowed settings definition for a category
     */
    private function getAllowedSettingsForCategory(string $category): array
    {
        return match ($category) {
            'smtp', 'email' => [
                'smtp.enabled' => ['type' => 'boolean', 'description' => 'Enable SMTP notifications'],
                'smtp.host' => ['type' => 'string', 'description' => 'SMTP server host'],
                'smtp.port' => ['type' => 'integer', 'description' => 'SMTP server port'],
                'smtp.encryption' => ['type' => 'string', 'description' => 'SMTP encryption (none, ssl, tls)'],
                'smtp.username' => ['type' => 'string', 'description' => 'SMTP username'],
                'smtp.password' => ['type' => 'string', 'description' => 'SMTP password'],
                'smtp.from_email' => ['type' => 'string', 'description' => 'From email address'],
                'smtp.from_name' => ['type' => 'string', 'description' => 'From name'],
            ],
            'general' => [
                'app.name' => ['type' => 'string', 'description' => 'Application name'],
                'app.timezone' => ['type' => 'string', 'description' => 'Application timezone'],
                'app.language' => ['type' => 'string', 'description' => 'Application language'],
                'notification.email' => ['type' => 'string', 'description' => 'Notification email'],
            ],
            'backup' => [
                'backup.retention_days' => ['type' => 'integer', 'description' => 'Backup retention in days'],
                'backup.compression' => ['type' => 'string', 'description' => 'Compression algorithm'],
            ],
            default => [],
        };
    }

    /**
     * Validate setting value based on type
     */
    private function validateSettingValue(mixed $value, string $type): bool
    {
        return match ($type) {
            'boolean' => is_bool($value) || $value === 'true' || $value === 'false',
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'json' => is_array($value) || is_object($value),
            'string' => is_string($value) || is_numeric($value),
            default => false,
        };
    }
}
