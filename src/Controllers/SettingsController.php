<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\SettingsService;
use Services\AuthService;
use Services\ConfigService;

/**
 * Settings Controller
 * Handles reading and writing admin settings,
 * plus the public widget_config endpoint.
 */
class SettingsController
{
    private SettingsService $settingsService;
    private AuthService     $authService;
    private ConfigService   $configService;

    public function __construct(SettingsService $settingsService, AuthService $authService, ConfigService $configService)
    {
        $this->settingsService = $settingsService;
        $this->authService     = $authService;
        $this->configService   = $configService;
    }

    // GET ?action=widget_config  (public)
    public function widgetConfig(Request $request): Response
    {
        return Response::json(['language' => $this->settingsService->getAppLanguage()]);
    }

    // GET ?action=get_settings  (admin)
    public function getSettings(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        return Response::json(['settings' => $this->settingsService->getSettings()]);
    }

    // POST ?action=save_settings  (admin)
    public function saveSettings(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $result = $this->settingsService->saveSettings($request->allBody());

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::success();
    }

    // GET ?action=get_config  (admin)
    public function getConfig(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $config = $this->configService->readConfig();

        if (isset($config['error'])) {
            return Response::error($config['error'], $config['code'] ?? 500);
        }

        return Response::json($config);
    }

    // POST ?action=save_config  (admin)
    public function saveConfig(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $result = $this->configService->writeConfig($request->allBody());

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 500);
        }

        return Response::success();
    }
}
