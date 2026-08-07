<?php

namespace Repositories;

/**
 * Login Attempt Repository
 * Handles all database operations for login attempt tracking
 */
class LoginAttemptRepository extends BaseRepository
{
    /**
     * Record a login attempt
     */
    public function record(string $ipAddress, bool $success): bool
    {
        $stmt = $this->prepare("
            INSERT INTO login_attempts (ip_address, success, attempted_at)
            VALUES (?, ?, datetime('now'))
        ");
        $stmt->execute([$ipAddress, $success ? 1 : 0]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count login attempts by IP in time window
     */
    public function countByIpSince(string $ipAddress, string $since = '-1 hour'): int
    {
        $stmt = $this->prepare("
            SELECT COUNT(*) as count FROM login_attempts
            WHERE ip_address = ? AND attempted_at > datetime('now', ?)
        ");
        $stmt->execute([$ipAddress, $since]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Clean old login attempts
     */
    public function cleanOld(string $olderThan = '-7 days'): int
    {
        $stmt = $this->prepare("
            DELETE FROM login_attempts WHERE attempted_at < datetime('now', ?)
        ");
        $stmt->execute([$olderThan]);
        return $stmt->rowCount();
    }
}
