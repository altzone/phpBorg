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

            $settings = $this->settingRepository->findByCategory($category);

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

            // Get existing settings for this category
            $existingSettings = $this->settingRepository->findByCategory($category);
            $existingKeys = array_map(fn($s) => $s->key, $existingSettings);

            // Update each setting
            foreach ($data['settings'] as $key => $value) {
                // Only update settings that exist in this category
                if (!in_array($key, $existingKeys)) {
                    continue;
                }

                // Get setting to validate type
                $setting = $this->settingRepository->findByKey($key);
                if (!$setting || $setting->category !== $category) {
                    continue;
                }

                // Validate value based on type
                if (!$this->validateSettingValue($value, $setting->type)) {
                    $this->error("Invalid value for setting: $key", 400, 'INVALID_VALUE');
                    return;
                }

                $this->settingRepository->updateValue($key, $value);
            }

            // Get updated settings for this category
            $settings = $this->settingRepository->findByCategory($category);
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
