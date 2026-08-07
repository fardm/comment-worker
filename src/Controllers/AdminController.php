<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\AnalyticsService;
use Services\DatabaseService;
use Services\ImportExportService;
use Services\AuthService;
use Helpers\SecurityHelper;

/**
 * Admin Controller
 * Handles analytics, database utilities, import/export,
 * and the test-email action.
 */
class AdminController
{
    private AnalyticsService    $analyticsService;
    private DatabaseService     $databaseService;
    private ImportExportService $importExportService;
    private AuthService         $authService;

    public function __construct(
        AnalyticsService    $analyticsService,
        DatabaseService     $databaseService,
        ImportExportService $importExportService,
        AuthService         $authService
    ) {
        $this->analyticsService    = $analyticsService;
        $this->databaseService     = $databaseService;
        $this->importExportService = $importExportService;
        $this->authService         = $authService;
    }

    // GET ?action=analytics  (admin)
    public function analytics(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        return Response::json($this->analyticsService->getAnalytics());
    }

    // GET ?action=posts_summary  (admin)
    public function postsSummary(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $search = trim($request->query('search', ''));
        return Response::json($this->analyticsService->getPostsSummary($search ?: null));
    }

    // GET ?action=db_stats  (admin)
    public function dbStats(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        return Response::json($this->databaseService->getStats());
    }

    // POST ?action=vacuum  (admin)
    public function vacuum(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        return Response::json($this->databaseService->vacuum());
    }

    // POST ?action=delete_spam  (admin)
    public function deleteSpam(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        return Response::json($this->databaseService->deleteSpam());
    }

    // POST ?action=db_delete_data  (admin)
    public function deleteData(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $preview    = !empty($request->body('preview'));
        $categories = $request->body('categories', []);

        if (!is_array($categories)) {
            return Response::error('categories must be an array');
        }

        $result = $this->databaseService->deleteData($categories, $preview);

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::json($result);
    }

    // POST ?action=normalize_urls  (admin)
    public function normalizeUrls(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        return Response::json($this->databaseService->normalizeUrls());
    }

    // GET ?action=export_comments  (admin, legacy alias: export_disqus)
    public function exportComments(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        // Export outputs directly and exits — no Response object returned
        $this->importExportService->exportComments();

        // Unreachable; satisfies return type
        return Response::json([]);
    }

    // POST ?action=import_comments  (admin, legacy alias: import_disqus)
    public function importComments(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $result = $this->importExportService->importComments($request->allBody());

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::json($result);
    }

    // POST ?action=test_email  (admin)
    public function testEmail(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $testEmail = $request->body('email', '');
        $pageUrl   = $request->body('page_url', '/');

        if (!SecurityHelper::validateEmail($testEmail)) {
            return Response::error('Invalid email address');
        }

        $safeEmail   = SecurityHelper::sanitizeEmailContent($testEmail);
        $safePageUrl = SecurityHelper::sanitizeEmailContent($pageUrl);
        $host        = $request->server('HTTP_HOST', 'localhost');

        $subject = 'Test Email from Comment System';
        $message = "This is a test email from your comment notification system.\n\n";
        $message .= "If you receive this, email notifications are working correctly!\n\n";
        $message .= "Test details:\n";
        $message .= "- Page URL: {$safePageUrl}\n";
        $message .= "- Sent at: " . date('Y-m-d H:i:s') . "\n";
        $message .= "- Server: {$host}\n\n";
        $message .= "---\nThis was a test email sent from the admin panel.\n";

        $headers  = "From: noreply@{$host}\r\n";
        $headers .= "Reply-To: noreply@{$host}\r\n";

        $sent = @mail($safeEmail, $subject, $message, $headers);

        if ($sent) {
            return Response::success(['message' => 'Test email sent successfully! Check your inbox (and spam folder).']);
        }

        return Response::error('Failed to send email. Check server mail configuration.', 500);
    }
}
