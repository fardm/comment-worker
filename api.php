<?php
/**
 * API Entry Point
 *
 * Responsibilities of this file (and nothing else):
 *   1. Bootstrap: error handlers, output buffer, ini settings
 *   2. Load config + database (unchanged database.php / config.php)
 *   3. Send CORS + security headers (identical logic to original)
 *   4. Autoload the src/ class hierarchy
 *   5. Wire the Container, build the Router, dispatch, send response
 *
 * All business logic lives in src/.
 */

// ── 1. Bootstrap ─────────────────────────────────────────────────────────────

// Load config first so constants are available everywhere
if (file_exists(__DIR__ . '/config.php')) {
    require_once 'config.php';
}

// Define constants that config.php may have omitted
if (!defined('ADMIN_TOKEN_COOKIE')) {
    define('ADMIN_TOKEN_COOKIE', 'comment_admin_token');
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600 * 24 * 30); // 30 days
}
if (!defined('ALLOWED_ORIGINS')) {
    define('ALLOWED_ORIGINS', ['*']);
}
if (!defined('APP_LANGUAGE')) {
    define('APP_LANGUAGE', 'en');
}
if (!defined('APP_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    define('APP_URL', $scheme . '://' . $host . $dir);
}
if (!defined('APP_PATH')) {
    define('APP_PATH', parse_url(APP_URL, PHP_URL_PATH) ?: '/');
}

// Error reporting
if (!ini_get('error_log')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    ini_set('error_log', $logsDir . '/php-errors.log');
}

if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Capture any stray output (PHP notices, BOM chars, etc.) before JSON is sent
ob_start();

// Convert PHP errors to a clean JSON 500 rather than leaking HTML
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => 'An unexpected error occurred']);
    exit;
});

set_exception_handler(function (\Throwable $e): void {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => 'An unexpected error occurred']);
    exit;
});

// ── 2. Database (unchanged database.php handles init + migrations) ────────────

require_once __DIR__ . '/database.php';

$db = getDatabase();
if (!$db) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// ── 3. Headers ────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

// Cache control
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// CORS — identical logic to original api.php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', ALLOWED_ORIGINS, true) || in_array($origin, ALLOWED_ORIGINS, true)) {
    if (in_array('*', ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: *');
    } else {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Preflight — respond immediately before any DB or autoload work
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    exit;
}

// ── 4. Autoload ───────────────────────────────────────────────────────────────

require_once __DIR__ . '/src/autoload.php';

// ── 5. Wire, dispatch, respond ────────────────────────────────────────────────

use Core\Container;
use Core\Request;
use Core\Router;
use Controllers\CommentController;
use Controllers\ReactionController;
use Controllers\AuthController;
use Controllers\SubscriptionController;
use Controllers\SettingsController;
use Controllers\AdminController;

$container = new Container($db);

$router = new Router(
    new CommentController(
        $container->commentService(),
        $container->reactionService(),
        $container->authService()
    ),
    new ReactionController(
        $container->reactionService(),
        $container->authService()
    ),
    new AuthController(
        $container->authService(),
        $container->rateLimitService()
    ),
    new SubscriptionController(
        $container->subscriptionService(),
        $container->authService()
    ),
    new SettingsController(
        $container->settingsService(),
        $container->authService(),
        $container->configService()
    ),
    new AdminController(
        $container->analyticsService(),
        $container->databaseService(),
        $container->importExportService(),
        $container->authService()
    )
);

$request  = new Request();
$response = $router->dispatch($request);
$response->send();
