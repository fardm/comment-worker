<?php

namespace Repositories;

/**
 * Vote Repository
 * Handles all database operations for comment reactions (votes)
 */
class VoteRepository extends BaseRepository
{
    /**
     * Check if a vote exists
     */
    public function exists(int $commentId, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            SELECT id FROM votes 
            WHERE comment_id = ? AND ip_address = ? AND reaction_type = ?
        ");
        $stmt->execute([$commentId, $ipAddress, $reactionType]);
        return $stmt->fetch() !== false;
    }

    /**
     * Add a vote
     */
    public function add(int $commentId, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            INSERT INTO votes (comment_id, ip_address, reaction_type) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$commentId, $ipAddress, $reactionType]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a vote
     */
    public function remove(int $commentId, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            DELETE FROM votes 
            WHERE comment_id = ? AND ip_address = ? AND reaction_type = ?
        ");
        $stmt->execute([$commentId, $ipAddress, $reactionType]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get vote counts for a comment, keyed by reaction type (zero-filled for all allowed types)
     */
    public function getCountsByComment(int $commentId, array $reactionTypes): array
    {
        $counts = array_fill_keys($reactionTypes, 0);

        $stmt = $this->prepare("
            SELECT reaction_type, COUNT(*) as count
            FROM votes
            WHERE comment_id = ?
            GROUP BY reaction_type
        ");
        $stmt->execute([$commentId]);

        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['reaction_type']])) {
                $counts[$row['reaction_type']] = (int)$row['count'];
            }
        }

        return $counts;
    }

    /**
     * Count votes by IP in time window (for rate limiting)
     */
    public function countByIpSince(string $ipAddress, string $modifier): int
    {
        $stmt = $this->prepare("
            SELECT COUNT(*) as count FROM vote_log
            WHERE ip_address = ? AND created_at > datetime('now', ?)
        ");
        $stmt->execute([$ipAddress, $modifier]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Log a vote action for rate limiting
     */
    public function logAction(string $ipAddress): bool
    {
        $stmt = $this->prepare("INSERT INTO vote_log (ip_address) VALUES (?)");
        $stmt->execute([$ipAddress]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all votes for a comment (for export)
     */
    public function getByCommentId(int $commentId): array
    {
        $stmt = $this->prepare("
            SELECT comment_id, reaction_type, ip_address, created_at
            FROM votes
            WHERE comment_id = ?
            ORDER BY created_at
        ");
        $stmt->execute([$commentId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all votes grouped by comment (for export)
     */
    public function getAllGroupedByComment(): array
    {
        $stmt = $this->query("
            SELECT v.comment_id, v.reaction_type, v.ip_address, v.created_at
            FROM votes v
            INNER JOIN comments c ON c.id = v.comment_id
            ORDER BY v.comment_id, v.created_at
        ");
        
        $votesByCommentId = [];
        foreach ($stmt->fetchAll() as $row) {
            $votesByCommentId[(int)$row['comment_id']][] = $row;
        }
        
        return $votesByCommentId;
    }

    /**
     * Clean old vote logs (older than specified time)
     */
    public function cleanOldLogs(string $olderThan = '-2 hours'): int
    {
        $stmt = $this->prepare("DELETE FROM vote_log WHERE created_at < datetime('now', ?)");
        $stmt->execute([$olderThan]);
        return $stmt->rowCount();
    }
}
