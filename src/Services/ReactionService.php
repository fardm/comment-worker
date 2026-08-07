<?php

namespace Services;

use Repositories\VoteRepository;
use Repositories\PostReactionRepository;
use Repositories\CommentRepository;
use Helpers\ReactionHelper;
use Helpers\UrlHelper;

/**
 * Reaction Service
 * Handles comment reactions and post reactions
 */
class ReactionService
{
    private VoteRepository $voteRepo;
    private PostReactionRepository $postReactionRepo;
    private CommentRepository $commentRepo;
    private RateLimitService $rateLimitService;
    private EmailService $emailService;

    public function __construct(
        VoteRepository $voteRepo,
        PostReactionRepository $postReactionRepo,
        CommentRepository $commentRepo,
        RateLimitService $rateLimitService,
        EmailService $emailService
    ) {
        $this->voteRepo = $voteRepo;
        $this->postReactionRepo = $postReactionRepo;
        $this->commentRepo = $commentRepo;
        $this->rateLimitService = $rateLimitService;
        $this->emailService = $emailService;
    }

    /**
     * Toggle a reaction on a comment
     */
    public function toggleCommentReaction(int $commentId, string $reactionType, string $ipAddress): array
    {
        if ($commentId <= 0) {
            return ['error' => 'Invalid comment ID', 'code' => 400];
        }

        $allowedTypes = ReactionHelper::getAllowedReactionTypes();
        if (!in_array($reactionType, $allowedTypes, true)) {
            return ['error' => 'Invalid reaction type', 'code' => 400];
        }

        // Verify comment exists and is approved
        $comment = $this->commentRepo->getById($commentId);
        if (!$comment || $comment['status'] !== 'approved') {
            return ['error' => 'Comment not found', 'code' => 404];
        }

        // Rate limit
        if ($this->rateLimitService->isVoteRateLimited($ipAddress)) {
            return ['error' => 'Too many reactions. Please slow down.', 'code' => 429];
        }

        // Toggle vote
        if ($this->voteRepo->exists($commentId, $ipAddress, $reactionType)) {
            $this->voteRepo->remove($commentId, $ipAddress, $reactionType);
            $voted = false;
        } else {
            $this->voteRepo->add($commentId, $ipAddress, $reactionType);
            $voted = true;
        }

        // Log action for rate limiting
        $this->voteRepo->logAction($ipAddress);

        // Notify comment author
        if ($voted) {
            $this->emailService->sendReactionNotification(
                $commentId,
                $comment['page_url'],
                $comment['author_name'],
                $comment['author_email'] ?? '',
                $reactionType
            );
        }

        $counts = $this->voteRepo->getCountsByComment($commentId, $allowedTypes);

        return [
            'voted'         => $voted,
            'reaction_type' => $reactionType,
            'counts'        => $counts,
        ];
    }

    /**
     * Toggle a reaction on a post (page-level)
     */
    public function togglePostReaction(string $pageUrl, string $reactionType, string $ipAddress): array
    {
        if (empty($pageUrl)) {
            return ['error' => 'page_url is required', 'code' => 400];
        }

        $allowedTypes = ReactionHelper::getAllowedReactionTypes();
        if (!in_array($reactionType, $allowedTypes, true)) {
            return ['error' => 'Invalid reaction type', 'code' => 400];
        }

        // Rate limit
        if ($this->rateLimitService->isVoteRateLimited($ipAddress)) {
            return ['error' => 'Too many reactions. Please slow down.', 'code' => 429];
        }

        // Toggle post reaction
        if ($this->postReactionRepo->exists($pageUrl, $ipAddress, $reactionType)) {
            $this->postReactionRepo->remove($pageUrl, $ipAddress, $reactionType);
            $voted = false;
        } else {
            $this->postReactionRepo->add($pageUrl, $ipAddress, $reactionType);
            $voted = true;
        }

        // Log action for rate limiting
        $this->voteRepo->logAction($ipAddress);

        // Notify admin
        if ($voted) {
            $this->emailService->sendPostReactionNotification($pageUrl, $reactionType);
        }

        $counts = $this->postReactionRepo->getCountsByPage($pageUrl, $allowedTypes);

        return [
            'voted'         => $voted,
            'reaction_type' => $reactionType,
            'counts'        => $counts,
        ];
    }

    /**
     * Get post reactions for a page
     */
    public function getPostReactionsForPage(string $pageUrl): array
    {
        $allowedTypes = ReactionHelper::getAllowedReactionTypes();
        return $this->postReactionRepo->getCountsForPage($pageUrl, $allowedTypes);
    }

    /**
     * Get post reactions summary (admin)
     */
    public function getPostReactionsSummary(): array
    {
        $pages = $this->postReactionRepo->getSummary();
        usort($pages, fn($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
        UrlHelper::enrichPageUrlHref($pages);
        
        $total = array_sum(array_map(fn($p) => (int)($p['total'] ?? 0), $pages));
        
        return ['pages' => $pages, 'total' => $total];
    }

    /**
     * Get latest post reactions (admin)
     */
    public function getLatestPostReactions(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $reactions = $this->postReactionRepo->getLatest($limit);
        UrlHelper::enrichPageUrlHref($reactions);
        return $reactions;
    }

    /**
     * Delete all reactions for a page (admin)
     */
    public function deletePageReactions(string $pageUrl): array
    {
        if (empty($pageUrl)) {
            return ['error' => 'url is required', 'code' => 400];
        }

        $this->postReactionRepo->deleteByPage($pageUrl);
        return ['success' => true, 'message' => 'Post reactions cleared'];
    }

    /**
     * Delete single post reaction by ID (admin)
     */
    public function deleteSingleReaction(int $id): array
    {
        if (empty($id)) {
            return ['error' => 'id is required', 'code' => 400];
        }

        if ($this->postReactionRepo->deleteById($id)) {
            return ['success' => true, 'message' => 'Reaction deleted'];
        }

        return ['error' => 'Reaction not found', 'code' => 404];
    }
}
