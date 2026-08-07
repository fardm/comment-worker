<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\CommentService;
use Services\ReactionService;
use Services\AuthService;

/**
 * Comment Controller
 * Handles all comment-related HTTP actions.
 */
class CommentController
{
    private CommentService  $commentService;
    private ReactionService $reactionService;
    private AuthService     $authService;

    public function __construct(
        CommentService  $commentService,
        ReactionService $reactionService,
        AuthService     $authService
    ) {
        $this->commentService  = $commentService;
        $this->reactionService = $reactionService;
        $this->authService     = $authService;
    }

    // GET ?action=comments&url=...
    public function index(Request $request): Response
    {
        $pageUrl = $request->query('url', '');
        if (empty($pageUrl)) {
            return Response::error('URL is required');
        }

        $limit  = min(max(1, $request->queryInt('limit', 500)), 1000);
        $offset = max(0, $request->queryInt('offset', 0));

        $result        = $this->commentService->getCommentsForPage($pageUrl, $limit, $offset);
        $postReactions = $this->reactionService->getPostReactionsForPage($pageUrl);

        return Response::json([
            'comments'           => $result['comments'],
            'post_reactions'     => $postReactions,
            'comment_sort_order' => $result['sort_order'],
            'pagination'         => [
                'total'   => $result['total'],
                'limit'   => $limit,
                'offset'  => $offset,
                'hasMore' => ($offset + $limit) < $result['total'],
            ],
        ]);
    }

    // GET ?action=recent&limit=N
    public function recent(Request $request): Response
    {
        $limit    = max(1, $request->queryInt('limit', 10));
        $comments = $this->commentService->getRecentComments($limit);
        return Response::json(['comments' => $comments]);
    }

    // POST ?action=post
    public function store(Request $request): Response
    {
        $result = $this->commentService->postComment(
            $request->allBody(),
            $request->getIp(),
            $request->getUserAgent()
        );

        $code = $result['code'] ?? 200;
        unset($result['code']);

        if (isset($result['error'])) {
            return Response::error($result['error'], $code);
        }

        return Response::created($result);
    }

    // PUT ?action=moderate&id=N
    public function moderate(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $id     = (int)$request->query('id', 0);
        $status = $request->body('status', '');
        $result = $this->commentService->moderateComment($id, $status);

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::success(['message' => $result['message']]);
    }

    // PUT ?action=edit_content&id=N
    public function editContent(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $id      = (int)$request->query('id', 0);
        $content = $request->body('content', '');
        $result  = $this->commentService->editContent($id, $content);

        if (isset($result['error'])) {
            return Response::error($result['error'], $result['code'] ?? 400);
        }

        return Response::success(['message' => $result['message'], 'content' => $result['content']]);
    }

    // DELETE ?action=delete&id=N
    public function destroy(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->query('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $id     = (int)$request->query('id', 0);
        $result = $this->commentService->deleteComment($id);
        return Response::success(['message' => $result['message']]);
    }

    // GET ?action=pending
    public function pending(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $limit  = min(max(1, $request->queryInt('limit', 50)), 10000);
        $offset = max(0, $request->queryInt('offset', 0));
        $result = $this->commentService->getPending($limit, $offset);

        return Response::json([
            'comments'   => $result['comments'],
            'pagination' => [
                'total'   => $result['total'],
                'limit'   => $limit,
                'offset'  => $offset,
                'hasMore' => ($offset + $limit) < $result['total'],
            ],
        ]);
    }

    // GET ?action=all
    public function all(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $limit        = min(max(1, $request->queryInt('limit', 50)), 100);
        $offset       = max(0, $request->queryInt('offset', 0));
        $statusFilter = trim($request->query('status', 'all'));
        $search       = trim($request->query('search', ''));

        $status = ($statusFilter !== 'all') ? $statusFilter : null;
        $result = $this->commentService->getAll($limit, $offset, $status, $search ?: null);

        return Response::json([
            'comments'   => $result['comments'],
            'aggregates' => $result['aggregates'],
            'pagination' => [
                'total'   => $result['total'],
                'limit'   => $limit,
                'offset'  => $offset,
                'hasMore' => ($offset + $limit) < $result['total'],
            ],
        ]);
    }
}
