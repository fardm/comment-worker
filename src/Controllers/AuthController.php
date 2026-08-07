<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\AuthService;
use Services\RateLimitService;

/**
 * Auth Controller
 * Handles admin login, logout, and CSRF token endpoint.
 */
class AuthController
{
    private AuthService       $authService;
    private RateLimitService  $rateLimitService;

    public function __construct(AuthService $authService, RateLimitService $rateLimitService)
    {
        $this->authService      = $authService;
        $this->rateLimitService = $rateLimitService;
    }

    // GET ?action=csrf_token
    public function csrfToken(Request $request): Response
    {
        $token = $this->authService->getCsrfToken();
        return Response::json(['token' => $token]);
    }

    // POST ?action=login
    public function login(Request $request): Response
    {
        $ip = $request->getIp();

        if ($this->rateLimitService->isLoginRateLimited($ip)) {
            return Response::tooManyRequests('Too many login attempts. Please try again later.');
        }

        $password = $request->body('password', '');
        $result   = $this->authService->login($password, $ip, $request->getUserAgent() ?? '');

        if ($result === null) {
            return Response::json(['error' => 'Invalid password'], 401);
        }

        return Response::json([
            'success'    => true,
            'message'    => 'Logged in successfully',
            'csrf_token' => $result['csrf_token'],
        ]);
    }

    // POST ?action=logout
    public function logout(Request $request): Response
    {
        $this->authService->logout();
        return Response::success();
    }
}
