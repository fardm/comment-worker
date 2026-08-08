<?php

namespace Core;

use PDO;
use Repositories\CommentRepository;
use Repositories\SettingsRepository;
use Repositories\SessionRepository;
use Repositories\VoteRepository;
use Repositories\PostReactionRepository;
use Repositories\SubscriptionRepository;
use Repositories\EmailQueueRepository;
use Repositories\LoginAttemptRepository;
use Services\AuthService;
use Services\SpamService;
use Services\RateLimitService;
use Services\EmailService;
use Services\CommentService;
use Services\ReactionService;
use Services\SubscriptionService;
use Services\SettingsService;
use Services\ConfigService;
use Services\AnalyticsService;
use Services\ImportExportService;
use Services\DatabaseService;

/**
 * Container
 * Simple dependency-injection container.
 * Constructs every repository and service once and caches the instance.
 * All wiring is explicit — no magic, no reflection.
 */
class Container
{
    private PDO   $db;
    private array $instances = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Repositories ──────────────────────────────────────────────────────────

    public function commentRepository(): CommentRepository
    {
        return $this->instances[CommentRepository::class]
            ??= new CommentRepository($this->db);
    }

    public function settingsRepository(): SettingsRepository
    {
        return $this->instances[SettingsRepository::class]
            ??= new SettingsRepository($this->db);
    }

    public function sessionRepository(): SessionRepository
    {
        return $this->instances[SessionRepository::class]
            ??= new SessionRepository($this->db);
    }

    public function voteRepository(): VoteRepository
    {
        return $this->instances[VoteRepository::class]
            ??= new VoteRepository($this->db);
    }

    public function postReactionRepository(): PostReactionRepository
    {
        return $this->instances[PostReactionRepository::class]
            ??= new PostReactionRepository($this->db);
    }

    public function subscriptionRepository(): SubscriptionRepository
    {
        return $this->instances[SubscriptionRepository::class]
            ??= new SubscriptionRepository($this->db);
    }

    public function emailQueueRepository(): EmailQueueRepository
    {
        return $this->instances[EmailQueueRepository::class]
            ??= new EmailQueueRepository($this->db);
    }

    public function loginAttemptRepository(): LoginAttemptRepository
    {
        return $this->instances[LoginAttemptRepository::class]
            ??= new LoginAttemptRepository($this->db);
    }

    // ── Services ──────────────────────────────────────────────────────────────

    public function authService(): AuthService
    {
        return $this->instances[AuthService::class]
            ??= new AuthService(
                $this->sessionRepository(),
                $this->settingsRepository(),
                $this->loginAttemptRepository()
            );
    }

    public function spamService(): SpamService
    {
        return $this->instances[SpamService::class]
            ??= new SpamService();
    }

    public function rateLimitService(): RateLimitService
    {
        return $this->instances[RateLimitService::class]
            ??= new RateLimitService(
                $this->commentRepository(),
                $this->voteRepository(),
                $this->loginAttemptRepository()
            );
    }

    public function emailService(): EmailService
    {
        return $this->instances[EmailService::class]
            ??= new EmailService(
                $this->emailQueueRepository(),
                $this->subscriptionRepository(),
                $this->settingsRepository(),
                $this->commentRepository()
            );
    }

    public function commentService(): CommentService
    {
        return $this->instances[CommentService::class]
            ??= new CommentService(
                $this->commentRepository(),
                $this->subscriptionRepository(),
                $this->settingsRepository(),
                $this->voteRepository(),
                $this->spamService(),
                $this->rateLimitService(),
                $this->emailService(),
                $this->authService()
            );
    }

    public function reactionService(): ReactionService
    {
        return $this->instances[ReactionService::class]
            ??= new ReactionService(
                $this->voteRepository(),
                $this->postReactionRepository(),
                $this->commentRepository(),
                $this->rateLimitService(),
                $this->emailService()
            );
    }

    public function subscriptionService(): SubscriptionService
    {
        return $this->instances[SubscriptionService::class]
            ??= new SubscriptionService(
                $this->subscriptionRepository()
            );
    }

    public function settingsService(): SettingsService
    {
        return $this->instances[SettingsService::class]
            ??= new SettingsService(
                $this->settingsRepository()
            );
    }

    public function configService(): ConfigService
    {
        return $this->instances[ConfigService::class]
            ??= new ConfigService();
    }

    public function analyticsService(): AnalyticsService
    {
        return $this->instances[AnalyticsService::class]
            ??= new AnalyticsService(
                $this->commentRepository()
            );
    }

    public function importExportService(): ImportExportService
    {
        return $this->instances[ImportExportService::class]
            ??= new ImportExportService(
                $this->commentRepository(),
                $this->voteRepository(),
                $this->postReactionRepository(),
                $this->subscriptionRepository(),
                $this->db
            );
    }

    public function databaseService(): DatabaseService
    {
        return $this->instances[DatabaseService::class]
            ??= new DatabaseService(
                $this->commentRepository(),
                $this->voteRepository(),
                $this->sessionRepository(),
                $this->loginAttemptRepository(),
                $this->emailQueueRepository(),
                $this->db
            );
    }
}
