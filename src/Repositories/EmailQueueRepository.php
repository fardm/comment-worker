<?php

namespace Repositories;

/**
 * Email Queue Repository
 * Handles all database operations for the email queue
 */
class EmailQueueRepository extends BaseRepository
{
    /**
     * Queue an email
     */
    public function queue(?int $commentId, string $recipientEmail, string $recipientName, string $emailType, string $subject, string $body): int
    {
        $stmt = $this->prepare("
            INSERT INTO email_queue (comment_id, recipient_email, recipient_name, email_type, subject, body, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([$commentId, $recipientEmail, $recipientName, $emailType, $subject, $body]);
        return (int)$this->lastInsertId();
    }

    /**
     * Get pending emails
     */
    public function getPending(int $limit = 10, int $maxAttempts = 3, int $retryDelay = 300): array
    {
        $stmt = $this->prepare("
            SELECT id, comment_id, recipient_email, recipient_name, email_type,
                   subject, body, attempts
            FROM email_queue
            WHERE status = 'pending'
              AND attempts < ?
              AND (last_error IS NULL OR created_at < datetime('now', '-' || ? || ' seconds'))
            ORDER BY created_at ASC
            LIMIT ?
        ");
        
        $stmt->execute([$maxAttempts, $retryDelay, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Mark email as sent
     */
    public function markSent(int $id): bool
    {
        $stmt = $this->prepare("
            UPDATE email_queue
            SET status = 'sent', sent_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark email as sent (alias for consistency)
     */
    public function markAsSent(int $id): bool
    {
        return $this->markSent($id);
    }

    /**
     * Mark email as failed
     */
    public function markFailed(int $id, string $error, int $maxAttempts = 3): bool
    {
        $stmt = $this->prepare("
            SELECT attempts FROM email_queue WHERE id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        $newAttempts = ((int)$result['attempts']) + 1;
        $status = $newAttempts >= $maxAttempts ? 'failed' : 'pending';
        
        $stmt = $this->prepare("
            UPDATE email_queue
            SET attempts = ?, status = ?, last_error = ?
            WHERE id = ?
        ");
        $stmt->execute([$newAttempts, $status, $error, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark email as failed with explicit status
     */
    public function markAsFailed(int $id, int $newAttempts, string $status, string $error): bool
    {
        $stmt = $this->prepare("
            UPDATE email_queue
            SET attempts = ?, status = ?, last_error = ?
            WHERE id = ?
        ");
        $stmt->execute([$newAttempts, $status, $error, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clean old sent and failed emails
     */
    public function cleanOld(int $sentDaysOld = 30, int $failedDaysOld = 7): int
    {
        $count = 0;
        
        // Delete old sent emails
        $stmt = $this->prepare("
            DELETE FROM email_queue
            WHERE status = 'sent' AND sent_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$sentDaysOld]);
        $count += $stmt->rowCount();
        
        // Delete old failed emails
        $stmt = $this->prepare("
            DELETE FROM email_queue
            WHERE status = 'failed' AND created_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$failedDaysOld]);
        $count += $stmt->rowCount();
        
        return $count;
    }

    /**
     * Delete old sent emails
     */
    public function deleteOldSent(int $days = 30): int
    {
        $stmt = $this->prepare("
            DELETE FROM email_queue
            WHERE status = 'sent' AND sent_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    /**
     * Delete old failed emails
     */
    public function deleteOldFailed(int $days = 7): int
    {
        $stmt = $this->prepare("
            DELETE FROM email_queue
            WHERE status = 'failed' AND created_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    /**
     * Count queued emails by status
     */
    public function countByStatus(string $status = 'pending'): int
    {
        $stmt = $this->prepare("SELECT COUNT(*) as count FROM email_queue WHERE status = ?");
        $stmt->execute([$status]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Check if table exists
     */
    public function exists(): bool
    {
        return $this->tableExists('email_queue');
    }
}
