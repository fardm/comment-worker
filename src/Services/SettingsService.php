<?php

namespace Services;

use Repositories\SettingsRepository;
use Helpers\SecurityHelper;

/**
 * Settings Service
 * Handles reading and writing admin settings
 */
class SettingsService
{
    private const EDITABLE_KEYS = [
        'require_moderation',
        'enable_notifications',
        'admin_email',
        'comment_sort_order',
    ];

    private const BOOLEAN_KEYS = [
        'require_moderation',
        'enable_notifications',
    ];

    private SettingsRepository $settingsRepo;

    public function __construct(SettingsRepository $settingsRepo)
    {
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Get all editable settings
     */
    public function getSettings(): array
    {
        $settings = [];
        foreach (self::EDITABLE_KEYS as $key) {
            $settings[$key] = $this->settingsRepo->get($key);
        }
        return $settings;
    }

    /**
     * Save settings from input array
     */
    public function saveSettings(array $input): array
    {
        foreach (self::EDITABLE_KEYS as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $value = ($value === 'true' || $value === true || $value === '1' || $value === 1) ? 'true' : 'false';
            }

            if ($key === 'comment_sort_order') {
                $value = strtolower((string)$value);
                if (!in_array($value, ['asc', 'desc'], true)) {
                    return ['error' => 'Invalid comment sort order', 'code' => 400];
                }
            }

            if ($key === 'admin_email' && !empty($value) && !SecurityHelper::validateEmail((string)$value)) {
                return ['error' => 'Invalid email address', 'code' => 400];
            }

            $this->settingsRepo->set($key, (string)$value);
        }

        return ['success' => true];
    }

    /**
     * Get the app language
     */
    public function getAppLanguage(): string
    {
        $lang = defined('APP_LANGUAGE') ? APP_LANGUAGE : 'en';
        return preg_match('/^[a-z]{2}$/i', $lang) ? strtolower($lang) : 'en';
    }
}
