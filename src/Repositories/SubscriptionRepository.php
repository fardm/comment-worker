<?php

namespace Repositories;

/**
 * Subscription Repository
 * Handles all database operations for email subscriptions
 */
class SubscriptionRepository extends BaseRepository
{
    /**
     * Create a new subscription
     */
    public function create(string $pageUrl, string $email, string $token): int
    {
        $stmt = $this->prepare("
            INSERT OR REPLACE INTO subscriptions (page_url, email, token, subscribed_at)
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$pageUrl, $email, $token]);
        return (int)$this->lastInsertId();
    }

    /**
     * Find subscription by token
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, email, token, subscribed_at, active
            FROM subscriptions
            WHERE token = ?
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find subscription by page URL and email
     */
    public function findByPageAndEmail(string $pageUrl, string $email): ?array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, email, token, subscribed_at, active
            FROM subscriptions
            WHERE page_url = ? AND email = ?
        ");
        $stmt->execute([$pageUrl, $email]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get active subscriptions for a page
     */
    public function getActiveByPage(string $pageUrl, ?string $excludeEmail = null): array
    {
        if ($excludeEmail) {
            $stmt = $this->prepare("
                SELECT email, token FROM subscriptions
                WHERE page_url = ? AND active = 1 AND email != ?
            ");
            $stmt->execute([$pageUrl, $excludeEmail]);
        } else {
            $stmt = $this->prepare("
                SELECT email, token FROM subscriptions
                WHERE page_url = ? AND active = 1
            ");
            $stmt->execute([$pageUrl]);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Update subscription active status
     */
    public function updateActive(string $token, int $active): bool
    {
        $stmt = $this->prepare("UPDATE subscriptions SET active = ? WHERE token = ?");
        $stmt->execute([$active, $token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete subscription by token
     */
    public function deleteByToken(string $token): bool
    {
        $stmt = $this->prepare("DELETE FROM subscriptions WHERE token = ?");
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all subscriptions with pagination
     */
    public function getAll(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, email, token, subscribed_at, active
            FROM subscriptions
            ORDER BY subscribed_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Count all subscriptions
     */
    public function countAll(): int
    {
        $stmt = $this->query("SELECT COUNT(*) as total FROM subscriptions");
        $result = $stmt->fetch();
        return (int)$result['total'];
    }

    /**
     * Get all subscriptions for export
     */
    public function getAllForExport(): array
    {
        $stmt = $this->query("
            SELECT page_url, email, token, subscribed_at, active
            FROM subscriptions
            ORDER BY subscribed_at ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Batch insert subscriptions (for import)
     */
    public function batchInsert(array $subscriptions): int
    {
        $stmt = $this->prepare("
            INSERT OR REPLACE INTO subscriptions (page_url, email, token, subscribed_at, active)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $count = 0;
        foreach ($subscriptions as $sub) {
            $stmt->execute([
                $sub['page_url'],
                $sub['email'],
                $sub['token'],
                $sub['subscribed_at'],
                $sub['active']
            ]);
            if ($stmt->rowCount() > 0) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Check if subscription exists (for import duplicate detection)
     */
    public function exists(string $pageUrl, string $email): bool
    {
        $stmt = $this->prepare("
            SELECT 1 FROM subscriptions WHERE page_url = ? AND email = ?
        ");
        $stmt->execute([$pageUrl, $email]);
        return $stmt->fetch() !== false;
    }
}
