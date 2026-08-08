#!/usr/bin/env php
<?php
/**
 * Email Queue Processor
 *
 * This script processes queued emails in the background to prevent blocking
 * comment submission requests. Run this script via cron or as a daemon.
 *
 * Usage:
 *   - Cron (every minute): * * * * * /usr/bin/php /path/to/comments/scripts/process-email-queue.php
 *   - Manual: php /path/to/comments/scripts/process-email-queue.php
 *   - Continuous daemon: php /path/to/comments/scripts/process-email-queue.php --daemon
 */

// Change to project root
chdir(__DIR__ . '/..');

require_once 'config.php';
require_once 'database.php';
require_once 'src/autoload.php';

use Services\EmailService;
use Repositories\EmailQueueRepository;
use Repositories\SubscriptionRepository;
use Repositories\SettingsRepository;
use Repositories\CommentRepository;

// Configuration
define('BATCH_SIZE', 10); // Process 10 emails per run
define('MAX_ATTEMPTS', 3); // Retry failed emails up to 3 times
define('RETRY_DELAY', 300); // Wait 5 minutes before retrying failed emails
define('DAEMON_SLEEP', 10); // Sleep 10 seconds between daemon cycles

// Check for daemon mode
$daemonMode = in_array('--daemon', $argv ?? []);

// Initialize services
$db = getDatabase();
if (!$db) {
    error_log('Email queue processor: Database connection failed');
    exit(1);
}

$emailQueueRepo = new EmailQueueRepository($db);
$subscriptionRepo = new SubscriptionRepository($db);
$settingsRepo = new SettingsRepository($db);
$commentRepo = new CommentRepository($db);

$emailService = new EmailService(
    $emailQueueRepo,
    $subscriptionRepo,
    $settingsRepo,
    $commentRepo
);

// Main execution
if ($daemonMode) {
    // Daemon mode: run continuously
    error_log('Email queue processor starting in daemon mode');

    $cleanupCounter = 0;
    while (true) {
        $emailService->processQueue(BATCH_SIZE, MAX_ATTEMPTS, RETRY_DELAY);

        // Run cleanup every hour (360 cycles of 10 seconds)
        if (++$cleanupCounter >= 360) {
            $emailService->cleanupOldEmails(30, 7);
            $cleanupCounter = 0;
        }

        sleep(DAEMON_SLEEP);
    }
} else {
    // Single run mode (for cron)
    $processed = $emailService->processQueue(BATCH_SIZE, MAX_ATTEMPTS, RETRY_DELAY);

    // Run cleanup randomly (1% chance) to avoid doing it every minute
    if (rand(1, 100) === 1) {
        $emailService->cleanupOldEmails(30, 7);
    }

    exit($processed > 0 ? 0 : 1);
}
