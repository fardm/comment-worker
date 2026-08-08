<?php

namespace Services;

/**
 * Config Service
 * Handles reading and writing config.php file
 */
class ConfigService
{
    private string $configPath;

    public function __construct()
    {
        $this->configPath = __DIR__ . '/../../config.php';
    }

    /**
     * Read config.php and return supported configuration values
     */
    public function readConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return [
                'error' => 'config.php does not exist',
                'exists' => false
            ];
        }

        // Load config.php to get current values
        require_once $this->configPath;

        return [
            'exists' => true,
            'writable' => is_writable($this->configPath),
            'app_url' => defined('APP_URL') ? APP_URL : '',
            'allowed_origins' => defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [],
            'timezone' => $this->getCurrentTimezone(),
            'app_language' => defined('APP_LANGUAGE') ? APP_LANGUAGE : 'en',
        ];
    }

    /**
     * Write configuration to config.php
     */
    public function writeConfig(array $data): array
    {
        if (!file_exists($this->configPath)) {
            return [
                'error' => 'config.php does not exist. Please run setup.php first.',
                'code' => 404
            ];
        }

        if (!is_writable($this->configPath)) {
            return [
                'error' => 'config.php is not writable. Please check file permissions.',
                'code' => 403
            ];
        }

        // Validate and sanitize input
        $appUrl = $this->validateAppUrl($data['app_url'] ?? '');
        if (isset($appUrl['error'])) {
            return $appUrl;
        }

        $allowedOrigins = $this->validateAllowedOrigins($data['allowed_origins'] ?? []);
        if (isset($allowedOrigins['error'])) {
            return $allowedOrigins;
        }

        $timezone = $this->validateTimezone($data['timezone'] ?? 'UTC');
        if (isset($timezone['error'])) {
            return $timezone;
        }

        $appLanguage = $this->validateAppLanguage($data['app_language'] ?? 'en');
        if (isset($appLanguage['error'])) {
            return $appLanguage;
        }

        // Generate config.php content
        $content = $this->generateConfigContent($appUrl, $allowedOrigins, $timezone, $appLanguage);

        // Write to file
        $result = file_put_contents($this->configPath, $content);
        if ($result === false) {
            return [
                'error' => 'Failed to write to config.php',
                'code' => 500
            ];
        }

        return ['success' => true];
    }

    /**
     * Validate APP_URL
     */
    private function validateAppUrl($value): array
    {
        $value = trim($value);
        if (empty($value)) {
            return ['error' => 'Application URL is required', 'code' => 400];
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return ['error' => 'Invalid Application URL format', 'code' => 400];
        }
        // Remove trailing slash
        $value = rtrim($value, '/');
        return $value;
    }

    /**
     * Validate ALLOWED_ORIGINS
     */
    private function validateAllowedOrigins($value): array
    {
        if (!is_array($value)) {
            return ['error' => 'Allowed origins must be an array', 'code' => 400];
        }
        if (empty($value)) {
            return ['error' => 'At least one allowed origin is required', 'code' => 400];
        }

        $origins = [];
        foreach ($value as $origin) {
            $origin = trim($origin);
            if (empty($origin)) {
                continue;
            }
            if ($origin === '*') {
                $origins[] = '*';
                continue;
            }
            if (!filter_var($origin, FILTER_VALIDATE_URL)) {
                return ['error' => "Invalid origin: $origin", 'code' => 400];
            }
            $origins[] = $origin;
        }

        if (empty($origins)) {
            return ['error' => 'At least one valid allowed origin is required', 'code' => 400];
        }

        return $origins;
    }

    /**
     * Validate timezone
     */
    private function validateTimezone($value): array
    {
        $value = trim($value);
        $validTimezones = \DateTimeZone::listIdentifiers();
        if (!in_array($value, $validTimezones)) {
            return ['error' => 'Invalid timezone', 'code' => 400];
        }
        return $value;
    }

    /**
     * Validate APP_LANGUAGE
     */
    private function validateAppLanguage($value): array
    {
        $value = trim($value);
        if (!in_array($value, ['en', 'fa'])) {
            return ['error' => 'Invalid language. Must be "en" or "fa"', 'code' => 400];
        }
        return $value;
    }

    /**
     * Generate config.php content
     */
    private function generateConfigContent(string $appUrl, array $allowedOrigins, string $timezone, string $appLanguage): string
    {
        $content = "<?php\n\n";
        $content .= "// Define the base URL where the comment system is installed. Do not include a trailing slash.\n";
        $content .= "define('APP_URL', '" . addslashes($appUrl) . "');\n\n";

        $content .= "// Add your domain\n";
        $content .= "define('ALLOWED_ORIGINS', " . var_export(array_values($allowedOrigins), true) . ");\n\n";

        $content .= "// Set timezone\n";
        $content .= "date_default_timezone_set('" . addslashes($timezone) . "');\n\n";

        $content .= "// Frontend comment widget language: 'en' or 'fa'\n";
        $content .= "define('APP_LANGUAGE', '" . addslashes($appLanguage) . "');\n";

        return $content;
    }

    /**
     * Get current timezone from date_default_timezone_get()
     */
    private function getCurrentTimezone(): string
    {
        return date_default_timezone_get() ?: 'UTC';
    }
}
