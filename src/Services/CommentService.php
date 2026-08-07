<?php

namespace Services;

use Repositories\CommentRepository;
use Repositories\SubscriptionRepository;
use Repositories\SettingsRepository;
use Repositories\VoteRepository;
use Helpers\SecurityHelper;
use Helpers\UrlHelper;
use Helpers\ReactionHelper;

/**
 * Comment Service
 * Handles comment business logic: creation, threading, and moderation
 */
class CommentService
{
    private CommentRepository $commentRepo;
    private SubscriptionRepository $subscriptionRepo;
    private SettingsRepository $settingsRepo;
    private VoteRepository $voteRepo;
    private SpamService $spamService;
    private RateLimitService $rateLimitService;
    private EmailService $emailService;
    private AuthService $authService;

    public function __construct(
        CommentRepository $commentRepo,
        SubscriptionRepository $subscriptionRepo,
        SettingsRepository $settingsRepo,
        VoteRepository $voteRepo,
        SpamService $spamService,
        RateLimitService $rateLimitService,
        EmailService $emailService,
        AuthService $authService
    ) {
        $this->commentRepo = $commentRepo;
        $this->subscriptionRepo = $subscriptionRepo;
        $this->settingsRepo = $settingsRepo;
        $this->voteRepo = $voteRepo;
        $this->spamService = $spamService;
        $this->rateLimitService = $rateLimitService;
        $this->emailService = $emailService;
        $this->authService = $authService;
    }

    /**
     * Get comments for a page, returning a threaded structure with reactions
     */
    public function getCommentsForPage(string $pageUrl, int $limit = 500, int $offset = 0): array
    {
        $isAdmin = $this->authService->isAdmin();
        $statuses = $isAdmin ? ['pending', 'approved'] : ['approved'];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        // Get total count
        $total = $this->commentRepo->countByPageUrl($pageUrl, $statuses);

        // Get comments
        $comments = $this->commentRepo->getByPageUrl($pageUrl, $statuses, $limit, $offset);

        // Fetch per-reaction-type counts for all comments
        $commentIds = array_map(fn($c) => (int)$c['id'], $comments);
        $votesByCommentId = $this->commentRepo->getVotesByCommentIds($commentIds);

        // Build threaded structure
        $threaded = [];
        $lookup = [];

        foreach ($comments as $comment) {
            $comment['replies'] = [];
            $comment['votes_by_reaction_type'] = $votesByCommentId[(int)$comment['id']] ?? [];

            if (!empty($comment['author_email'])) {
                $comment['author_avatar'] = $this->getGravatarUrl($comment['author_email']);
            }

            // Don't expose email to non-admins
            if (!$isAdmin) {
                unset($comment['author_email']);
            }

            $lookup[$comment['id']] = $comment;
        }

        foreach ($lookup as $id => $comment) {
            if ($comment['parent_id'] === null) {
                $threaded[] = &$lookup[$id];
            } elseif (isset($lookup[$comment['parent_id']])) {
                $lookup[$comment['parent_id']]['replies'][] = &$lookup[$id];
            }
        }

        // Sort top-level comments
        $sortOrder = $this->getSortOrder();
        $this->sortTopLevelComments($threaded, $sortOrder);

        return [
            'comments' => $threaded,
            'sort_order' => $sortOrder,
            'total' => $total,
        ];
    }

    /**
     * Get recent approved comments site-wide
     */
    public function getRecentComments(int $limit = 10): array
    {
        $limit    = min(max(1, $limit), 100);
        $comments = $this->commentRepo->getRecent($limit);

        foreach ($comments as &$comment) {
            foreach ($comment as &$value) {
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $value = iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: $value;
                }
            }
            unset($value);

            $comment['excerpt'] = strlen($comment['content']) > 150
                ? substr($comment['content'], 0, 150) . '...'
                : $comment['content'];
        }
        unset($comment);

        return $comments;
    }

    /**
     * Post a new comment
     */
    public function postComment(array $input, string $ipAddress, ?string $userAgent): array
    {
        $pageUrl = $input['page_url'] ?? '';
        $parentId = $input['parent_id'] ?? null;
        $authorName = trim($input['author_name'] ?? '');
        $authorEmail = trim($input['author_email'] ?? '');
        $authorUrl = SecurityHelper::sanitizeUrl($input['author_url'] ?? '');
        $content = trim($input['content'] ?? '');
        $subscribe = $input['subscribe'] ?? false;
        $honeypot = $input['website'] ?? '';

        // Honeypot check
        if ($this->spamService->isHoneypotTriggered($honeypot)) {
            return ['error' => 'Invalid submission', 'code' => 400];
        }

        // Validation
        $errors = [];
        if (empty($pageUrl))                               $errors[] = 'URL is required';
        if (empty($authorName))                            $errors[] = 'Name is required';
        if (empty($authorEmail) || !SecurityHelper::validateEmail($authorEmail)) $errors[] = 'Valid email is required';
        if (empty($content))                               $errors[] = 'Comment content is required';
        if (strlen($content) > 5000)                      $errors[] = 'Comment is too long';

        if (!empty($errors)) {
            return ['error' => implode(', ', $errors), 'code' => 400];
        }

        // Rate limiting (skip for admins)
        if (!$this->authService->isAdmin()) {
            $rateLimit = $this->rateLimitService->checkCommentRateLimit($ipAddress, $authorEmail);
            if ($rateLimit['limited']) {
                return ['error' => $rateLimit['reason'], 'code' => 429];
            }
        }

        // Parent comment validation
        if ($parentId !== null) {
            if (!$this->commentRepo->exists((int)$parentId)) {
                return ['error' => 'Parent comment not found', 'code' => 404];
            }
        }

        // Determine status
        $isSpam = $this->spamService->detectSpam($content, $authorName, $authorEmail, $authorUrl);
        $isTrusted = $this->commentRepo->countApprovedByEmail($authorEmail) > 0;
        $requiresModeration = $this->settingsRepo->get('require_moderation') === 'true';

        if ($isSpam) {
            $status = 'spam';
        } elseif ($isTrusted) {
            $status = 'approved';
        } else {
            $status = $requiresModeration ? 'pending' : 'approved';
        }

        $now = date('Y-m-d H:i:s');
        $commentId = $this->commentRepo->create([
            'page_url'     => $pageUrl,
            'parent_id'    => $parentId ? (int)$parentId : null,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'author_url'   => $authorUrl,
            'content'      => $content,
            'status'       => $status,
            'ip_address'   => $ipAddress,
            'user_agent'   => $userAgent,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Handle subscription
        if ($subscribe && $status !== 'spam') {
            $token = SecurityHelper::generateToken(32);
            $this->subscriptionRepo->create($pageUrl, $authorEmail, $token);
        }

        // Send notifications
        if ($status !== 'spam') {
            $this->emailService->sendCommentNotification($commentId, $pageUrl, $parentId ? (int)$parentId : null, $authorName, $content, $authorEmail);
        }

        // Build response message key
        $messageKey = match(true) {
            $status === 'spam'   => 'spam',
            $status === 'pending' => 'pending',
            $isTrusted           => 'trusted',
            default              => 'approved',
        };

        return [
            'success'      => true,
            'id'           => $commentId,
            'status'       => $status,
            'message_key'  => $messageKey,
            'message'      => $this->getStatusMessage($messageKey),
            'trusted'      => $isTrusted,
            'code'         => 201,
        ];
    }

    /**
     * Moderate a comment (change status)
     */
    public function moderateComment(int $id, string $status): array
    {
        if (!in_array($status, ['approved', 'spam', 'deleted'])) {
            return ['error' => 'Invalid status', 'code' => 400];
        }

        $this->commentRepo->updateStatus($id, $status);
        return ['success' => true, 'message' => 'Comment updated'];
    }

    /**
     * Edit comment content
     */
    public function editContent(int $id, string $content): array
    {
        if ($id <= 0) {
            return ['error' => 'Invalid comment ID', 'code' => 400];
        }

        if (empty($content = trim($content))) {
            return ['error' => 'Comment content is required', 'code' => 400];
        }

        // Check max length from settings
        $maxLength = 5000;
        $maxSetting = $this->settingsRepo->get('max_comment_length');
        if ($maxSetting) {
            $maxLength = max(1, (int)$maxSetting);
        }

        if (strlen($content) > $maxLength) {
            return ['error' => 'Comment is too long', 'code' => 400];
        }

        if (!$this->commentRepo->exists($id)) {
            return ['error' => 'Comment not found', 'code' => 404];
        }

        $this->commentRepo->updateContent($id, $content);
        return ['success' => true, 'message' => 'Comment updated', 'content' => $content];
    }

    /**
     * Delete a comment
     */
    public function deleteComment(int $id): array
    {
        $this->commentRepo->delete($id);
        return ['success' => true, 'message' => 'Comment deleted'];
    }

    /**
     * Get pending comments
     */
    public function getPending(int $limit = 50, int $offset = 0): array
    {
        $total = $this->commentRepo->countPending();
        $comments = $this->commentRepo->getPending($limit, $offset);

        $commentIds = array_map(fn($c) => (int)$c['id'], $comments);
        $votesByCommentId = $this->commentRepo->getVotesByCommentIds($commentIds);

        foreach ($comments as &$c) {
            $c['votes_by_reaction_type'] = $votesByCommentId[(int)$c['id']] ?? [];
        }
        unset($c);

        UrlHelper::enrichPageUrlHref($comments);

        return ['comments' => $comments, 'total' => $total];
    }

    /**
     * Get all comments (admin)
     */
    public function getAll(int $limit = 50, int $offset = 0, ?string $status = null, ?string $search = null): array
    {
        $total = $this->commentRepo->countAll($status, $search);
        $aggregates = $this->commentRepo->getStatusAggregates();
        $comments = $this->commentRepo->getAll($limit, $offset, $status, $search);

        $commentIds = array_map(fn($c) => (int)$c['id'], $comments);
        $votesByCommentId = $this->commentRepo->getVotesByCommentIds($commentIds);

        foreach ($comments as &$c) {
            $c['votes_by_reaction_type'] = $votesByCommentId[(int)$c['id']] ?? [];
        }
        unset($c);

        UrlHelper::enrichPageUrlHref($comments);

        return ['comments' => $comments, 'aggregates' => $aggregates, 'total' => $total];
    }

    /**
     * Get sorted top-level sort order
     */
    public function getSortOrder(): string
    {
        static $order = null;
        if ($order !== null) {
            return $order;
        }

        $value = $this->settingsRepo->get('comment_sort_order');
        $order = ($value && in_array($value, ['asc', 'desc'], true)) ? $value : 'asc';
        return $order;
    }

    /**
     * Sort top-level comments
     */
    public function sortTopLevelComments(array &$comments, string $order = 'asc'): void
    {
        if ($order === 'desc') {
            usort($comments, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        }
    }

    /**
     * Get Gravatar URL for an email
     */
    public function getGravatarUrl(string $email, int $size = 80): string
    {
        $hash = md5(strtolower(trim($email)));
        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }

    /**
     * Get a human-readable status message
     */
    private function getStatusMessage(string $messageKey): string
    {
        return match($messageKey) {
            'spam'     => 'Comment marked as spam',
            'pending'  => 'Your comment has been submitted for review',
            'trusted'  => 'Your comment was published successfully (auto-approved)',
            default    => 'Your comment was published successfully',
        };
    }
}
