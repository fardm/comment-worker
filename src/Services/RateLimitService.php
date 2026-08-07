<?php

namespace Services;

use Repositories\CommentRepository;
use Repositories\VoteRepository;
use Repositories\LoginAttemptRepository;

/**
 * Rate Limit Service
 * Handles all rate-limiting logic
 */
class RateLimitService
{
    private CommentRepository $commentRepo;
    private VoteRepository $voteRepo;
    private LoginAttemptRepository $loginAttemptRepo;

    public function __construct(
        CommentRepository $commentRepo,
        VoteRepository $voteRepo,
        LoginAttemptRepository $loginAttemptRepo
    ) {
        $this->commentRepo = $commentRepo;
        $this->voteRepo = $voteRepo;
        $this->loginAttemptRepo = $loginAttemptRepo;
    }

    /**
     * Check comment rate limit by IP and email
     * Returns an array with 'limited' => bool and 'reason' => string
     */
    public function checkCommentRateLimit(string $ipAddress, string $email): array
    {
        // 5 comments per hour per IP
        $ipCount = $this->commentRepo->countByIpSince($ipAddress, '-1 hour');
        if ($ipCount >= 5) {
            return [
                'limited' => true,
                'reason' => 'Too many comments from your IP address. Please try again later.',
            ];
        }

        // 3 comments per 10 minutes per email
        $emailCount = $this->commentRepo->countByEmailSince($email, '-10 minutes');
        if ($emailCount >= 3) {
            return [
                'limited' => true,
                'reason' => 'Too many comments in a short time. Please wait a few minutes.',
            ];
        }

        return ['limited' => false];
    }

    /**
     * Check vote/reaction rate limit by IP
     * Returns true if rate limited
     */
    public function isVoteRateLimited(string $ipAddress): bool
    {
        $count = $this->voteRepo->countByIpSince($ipAddress, '-60 seconds');
        return $count >= 15;
    }

    /**
     * Check login rate limit by IP
     * Returns true if rate limited
     */
    public function isLoginRateLimited(string $ipAddress): bool
    {
        $count = $this->loginAttemptRepo->countByIpSince($ipAddress, '-1 hour');
        return $count >= 5;
    }
}
