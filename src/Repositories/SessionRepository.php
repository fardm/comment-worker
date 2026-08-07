<?php

namespace Repositories;

/**
 * Session Repository
 * Handles all database operations for admin sessions
 */
class SessionRepository extends BaseRepository
{
    /**
     * Create a new session
     */
    public function create(string $token, string $expiresAt, ?string $ipAddress = null, ?string $userAgent = null): int
    {
        $stmt = $this->prepare("
            INSERT INTO sessions (token, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$token, $expiresAt, $ipAddress, $userAgent]);
        return (int)$this->lastInsertId();
    }

    /**
     * Find a session by token
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->prepare("
            SELECT id, token, created_at, expires_at, last_activity, ip_address, user_agent
            FROM sessions
            WHERE token = ? AND expires_at > datetime('now')
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Update session last activity
     */
    public function updateActivity(int $sessionId): bool
    {
        $stmt = $this->prepare("UPDATE sessions SET last_activity = datetime('now') WHERE id = ?");
        $stmt->execute([$sessionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a session by token
     */
    public function deleteByToken(string $token): bool
    {
        $stmt = $this->prepare("DELETE FROM sessions WHERE token = ?");
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete expired sessions
     */
    public function deleteExpired(): int
    {
        $stmt = $this->prepare("DELETE FROM sessions WHERE expires_at < datetime('now')");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Delete all sessions (logout all)
     */
    public function deleteAll(): int
    {
        $stmt = $this->query("DELETE FROM sessions");
        return $stmt->rowCount();
    }
}
