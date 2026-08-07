<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\SubscriptionService;
use Services\AuthService;

/**
 * Subscription Controller
 * Handles email subscription management.
 */
class SubscriptionController
{
    private SubscriptionService $subscriptionService;
    private AuthService         $authService;

    public function __construct(SubscriptionService $subscriptionService, AuthService $authService)
    {
        $this->subscriptionService = $subscriptionService;
        $this->authService         = $authService;
    }

    // GET ?action=subscriptions  (admin)
    public function index(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }

        $limit  = min(max(1, $request->queryInt('limit', 50)), 10000);
        $offset = max(0, $request->queryInt('offset', 0));
        $result = $this->subscriptionService->getAll($limit, $offset);

        return Response::json([
            'subscriptions' => $result['subscriptions'],
            'pagination'    => [
                'total'   => $result['total'],
                'limit'   => $limit,
                'offset'  => $offset,
                'hasMore' => ($offset + $limit) < $result['total'],
            ],
        ]);
    }

    // POST ?action=toggle_subscription  (admin)
    public function toggle(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->body('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $token  = $request->body('token', '');
        $active = (int)$request->body('active', 1);
        $result = $this->subscriptionService->toggleSubscription($token, $active);

        return Response::success(['message' => $result['message']]);
    }

    // DELETE ?action=delete_subscription&token=...  (admin)
    public function destroy(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::unauthorized();
        }
        if (!$this->authService->validateCsrfToken($request->query('csrf_token', ''))) {
            return Response::forbidden('Invalid CSRF token');
        }

        $token  = $request->query('token', '');
        $result = $this->subscriptionService->deleteSubscription($token);
        return Response::success(['message' => $result['message']]);
    }
}
