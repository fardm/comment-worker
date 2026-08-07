<?php

namespace Helpers;

/**
 * Security Helper
 * Provides security-related utility functions
 */
class SecurityHelper
{
    /**
     * Validate email address
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sanitize URL
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * Sanitize email content (prevent header injection)
     */
    public static function sanitizeEmailContent(string $input): string
    {
        return str_replace(["\r", "\n", "%0a", "%0d", "\x0A", "\x0D"], '', $input);
    }

    /**
     * Generate secure random token
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Hash password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify password
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        if (!isset($_COOKIE['csrf_token'])) {
            $token = self::generateToken(32);
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
            $appPath = defined('APP_PATH') ? APP_PATH : '/';
            $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600 * 24 * 30;
            setcookie('csrf_token', $token, time() + $lifetime, $appPath, '', $isSecure, false);
            return $token;
        }
        return $_COOKIE['csrf_token'];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool
    {
        return isset($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token);
    }

    /**
     * Get client IP address
     */
    public static function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get user agent
     */
    public static function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Ensure UTF-8 encoding
     */
    public static function ensureUtf8($data)
    {
        if (is_string($data)) {
            if (!mb_check_encoding($data, 'UTF-8')) {
                $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
                if (empty($data)) {
                    $data = iconv('ISO-8859-1', 'UTF-8//IGNORE', $data);
                }
            }
            return $data;
        } elseif (is_array($data)) {
            foreach ($data as &$value) {
                $value = self::ensureUtf8($value);
            }
            return $data;
        } elseif (is_object($data)) {
            foreach ($data as $key => &$value) {
                $data->$key = self::ensureUtf8($value);
            }
            return $data;
        }
        return $data;
    }
}
