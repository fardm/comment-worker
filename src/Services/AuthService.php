<?php

namespace Services;

use Repositories\SessionRepository;
use Repositories\SettingsRepository;
use Repositories\LoginAttemptRepository;
use Helpers\SecurityHelper;

/**
 * Auth Service
 * Handles admin authentication and session management
 */
class AuthService
{
    private SessionRepository $sessionRepo;
    private SettingsRepository $settingsRepo;
    private LoginAttemptRepository $loginAttemptRepo;

    public function __construct(
        SessionRepository $sessionRepo,
        SettingsRepository $settingsRepo,
        LoginAttemptRepository $loginAttemptRepo
    ) {
        $this->sessionRepo = $sessionRepo;
        $this->settingsRepo = $settingsRepo;
        $this->loginAttemptRepo = $loginAttemptRepo;
    }

    /**
     * Check if the current request is authenticated as admin
     */
    public function isAdmin(): bool
    {
        $cookieName = defined('ADMIN_TOKEN_COOKIE') ? ADMIN_TOKEN_COOKIE : 'comment_admin_token';
        
        if (!isset($_COOKIE[$cookieName])) {
            return false;
        }
        
        $token = $_COOKIE[$cookieName];
        
        // Check session table
        $session = $this->sessionRepo->findByToken($token);
        if ($session) {
            $this->sessionRepo->updateActivity($session['id']);
            return true;
        }
        
        // Fallback to legacy admin_token for backward compatibility
        $storedToken = $this->settingsRepo->get('admin_token');
        if ($storedToken && hash_equals($storedToken, $token)) {
            return true;
        }
        
        return false;
    }

    /**
     * Attempt login with password
     */
    public function login(string $password, string $ipAddress, string $userAgent): ?array
    {
        $hash = $this->settingsRepo->get('admin_password_hash');
        
        if (!$hash || !SecurityHelper::verifyPassword($password, $hash)) {
            $this->loginAttemptRepo->record($ipAddress, false);
            return null;
        }
        
        $this->loginAttemptRepo->record($ipAddress, true);
        
        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600 * 24 * 30;
        $token = SecurityHelper::generateToken(32);
        $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
        
        $this->sessionRepo->create($token, $expiresAt, $ipAddress, $userAgent);
        
        // Also store in legacy settings for backward compatibility
        $this->settingsRepo->set('admin_token', $token);
        
        // Set secure cookie
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $appPath = defined('APP_PATH') ? APP_PATH : '/';
        $cookieName = defined('ADMIN_TOKEN_COOKIE') ? ADMIN_TOKEN_COOKIE : 'comment_admin_token';
        
        setcookie($cookieName, $token, time() + $sessionLifetime, $appPath, '', $isSecure, true);
        
        // Generate CSRF token
        $csrfToken = SecurityHelper::generateCsrfToken();
        
        return [
            'token' => $token,
            'csrf_token' => $csrfToken,
        ];
    }

    /**
     * Log out the current admin session
     */
    public function logout(): void
    {
        $cookieName = defined('ADMIN_TOKEN_COOKIE') ? ADMIN_TOKEN_COOKIE : 'comment_admin_token';
        
        if (isset($_COOKIE[$cookieName])) {
            $token = $_COOKIE[$cookieName];
            
            // Invalidate session in DB
            $this->sessionRepo->deleteByToken($token);
            
            // Remove legacy admin_token
            $this->settingsRepo->delete('admin_token');
        }
        
        // Expire cookies
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $appPath = defined('APP_PATH') ? APP_PATH : '/';
        
        setcookie($cookieName, '', time() - 3600, $appPath, '', $isSecure, true);
        setcookie('csrf_token', '', time() - 3600, $appPath, '', $isSecure, false);
    }

    /**
     * Check if login rate limit is exceeded for an IP
     */
    public function isLoginRateLimited(string $ipAddress): bool
    {
        $count = $this->loginAttemptRepo->countByIpSince($ipAddress, '-1 hour');
        return $count >= 5;
    }

    /**
     * Get or generate CSRF token
     */
    public function getCsrfToken(): string
    {
        return SecurityHelper::generateCsrfToken();
    }

    /**
     * Validate CSRF token
     */
    public function validateCsrfToken(string $token): bool
    {
        return SecurityHelper::validateCsrfToken($token);
    }
}
