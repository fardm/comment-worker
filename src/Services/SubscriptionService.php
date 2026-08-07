<?php

namespace Services;

use Repositories\SubscriptionRepository;

/**
 * Subscription Service
 * Handles email subscription management
 */
class SubscriptionService
{
    private SubscriptionRepository $subscriptionRepo;

    public function __construct(SubscriptionRepository $subscriptionRepo)
    {
        $this->subscriptionRepo = $subscriptionRepo;
    }

    /**
     * Get all subscriptions (admin)
     */
    public function getAll(int $limit = 50, int $offset = 0): array
    {
        $total = $this->subscriptionRepo->countAll();
        $subscriptions = $this->subscriptionRepo->getAll($limit, $offset);

        return ['subscriptions' => $subscriptions, 'total' => $total];
    }

    /**
     * Toggle subscription active state (admin)
     */
    public function toggleSubscription(string $token, int $active): array
    {
        $this->subscriptionRepo->updateActive($token, $active);
        return ['success' => true, 'message' => 'Subscription updated'];
    }

    /**
     * Delete a subscription (admin)
     */
    public function deleteSubscription(string $token): array
    {
        $this->subscriptionRepo->deleteByToken($token);
        return ['success' => true, 'message' => 'Subscription deleted'];
    }

    /**
     * Handle unsubscribe by token (public)
     */
    public function unsubscribe(string $token): array
    {
        if (empty($token)) {
            return ['success' => false, 'error' => 'Invalid token'];
        }

        $updated = $this->subscriptionRepo->updateActive($token, 0);
        
        if ($updated) {
            return ['success' => true, 'message' => 'You have been successfully unsubscribed from comment notifications.'];
        }

        return ['success' => false, 'error' => 'Subscription not found or already unsubscribed.'];
    }

    /**
     * Get subscription info by token (for unsubscribe page)
     */
    public function getByToken(string $token): ?array
    {
        return $this->subscriptionRepo->findByToken($token);
    }
}
