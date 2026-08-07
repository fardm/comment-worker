<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\ReactionService;
use Services\AuthService;

/**
 * Reaction Controller
 * Handles comment reactions (votes) and post-level reactions.
 */
class ReactionController
{
    private ReactionService $reactionService;
    private AuthService     $authService;

    public function __construct(ReactionService $reactionService, AuthService $authService)
    {
        $this->reactionService = $reactionService;
        $this->authService     = $authService;
    }

    // POST ?action=vote
    public function vote(Request $request): Response
    {
        $commentId    = $request->bodyInt('comment_id', 0);
        $reactionType = $request->body('reaction_type', 'heart');
        $result       = $this->reactionService->toggleCommentReaction($commentId, $reactionType, $request->getIp());

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::json($result);
    }

    // POST ?action=post_reaction
    public function postReaction(Request $request): Response
    {
        $pageUrl      = $request->body('page_url', '');
        $reactionType = $request->body('reaction_type', 'heart');
        $result       = $this->reactionService->togglePostReaction($pageUrl, $reactionType, $request->getIp());

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::json($result);
    }

    // GET ?action=post_reactions_summary  (admin)
    public function summary(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        return Response::json($this->reactionService->getPostReactionsSummary());
    }

    // GET ?action=post_reactions_latest&limit=N  (admin)
    public function latest(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $limit     = $request->queryInt('limit', 10);
        $reactions = $this->reactionService->getLatestPostReactions($limit);
        return Response::json(['reactions' => $reactions]);
    }

    // DELETE ?action=delete_post_reactions&url=...  (admin)
    public function deleteByPage(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->query('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $pageUrl = $request->query('url', '');
        $result  = $this->reactionService->deletePageReactions($pageUrl);

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::success(['message' => $result['message']]);
    }

    // DELETE ?action=delete_single_reaction&id=N  (admin)
    public function deleteSingle(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->query('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $id     = $request->queryInt('id', 0);
        $result = $this->reactionService->deleteSingleReaction($id);

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::success(['message' => $result['message']]);
    }
}
