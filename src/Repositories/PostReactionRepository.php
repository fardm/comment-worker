<?php

namespace Repositories;

/**
 * Post Reaction Repository
 * Handles all database operations for page-level reactions
 */
class PostReactionRepository extends BaseRepository
{
    /**
     * Check if a post reaction exists
     */
    public function exists(string $pageUrl, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            SELECT id FROM post_reactions 
            WHERE page_url = ? AND ip_address = ? AND reaction_type = ?
        ");
        $stmt->execute([$pageUrl, $ipAddress, $reactionType]);
        return $stmt->fetch() !== false;
    }

    /**
     * Add a post reaction
     */
    public function add(string $pageUrl, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            INSERT INTO post_reactions (page_url, ip_address, reaction_type) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$pageUrl, $ipAddress, $reactionType]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a post reaction
     */
    public function remove(string $pageUrl, string $ipAddress, string $reactionType): bool
    {
        $stmt = $this->prepare("
            DELETE FROM post_reactions 
            WHERE page_url = ? AND ip_address = ? AND reaction_type = ?
        ");
        $stmt->execute([$pageUrl, $ipAddress, $reactionType]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get reaction counts for a page, keyed by reaction type (zero-filled)
     */
    public function getCountsForPage(string $pageUrl, array $reactionTypes): array
    {
        $counts = array_fill_keys($reactionTypes, 0);

        $stmt = $this->prepare("
            SELECT reaction_type, COUNT(*) as count
            FROM post_reactions
            WHERE page_url = ?
            GROUP BY reaction_type
        ");
        $stmt->execute([$pageUrl]);

        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['reaction_type']])) {
                $counts[$row['reaction_type']] = (int)$row['count'];
            }
        }

        return $counts;
    }

    /**
     * Get reaction counts for a page
     */
    public function getCountsByPage(string $pageUrl, array $reactionTypes): array
    {
        return $this->getCountsForPage($pageUrl, $reactionTypes);
    }

    /**
     * Get reaction summary for all pages
     */
    public function getSummary(): array
    {
        $stmt = $this->query("
            SELECT page_url, reaction_type, COUNT(*) as count
            FROM post_reactions
            GROUP BY page_url, reaction_type
        ");
        
        $byPage = [];
        foreach ($stmt->fetchAll() as $row) {
            $pageUrl = $row['page_url'];
            $type = $row['reaction_type'];
            $cnt = (int)$row['count'];
            
            if (!isset($byPage[$pageUrl])) {
                $byPage[$pageUrl] = [
                    'page_url' => $pageUrl,
                    'total' => 0,
                    'heart' => 0,
                    'thumbsup' => 0,
                    'lightbulb' => 0,
                    'funny' => 0,
                    'reactions' => [],
                ];
            }
            
            $byPage[$pageUrl]['total'] += $cnt;
            $byPage[$pageUrl]['reactions'][$type] = $cnt;
            
            // Legacy keys
            if (isset($byPage[$pageUrl][$type])) {
                $byPage[$pageUrl][$type] = $cnt;
            }
        }
        
        return array_values($byPage);
    }

    /**
     * Get latest post reactions
     */
    public function getLatest(int $limit = 10): array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, reaction_type, created_at, ip_address
            FROM post_reactions
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Delete all reactions for a page
     */
    public function deleteByPage(string $pageUrl): int
    {
        $stmt = $this->prepare("DELETE FROM post_reactions WHERE page_url = ?");
        $stmt->execute([$pageUrl]);
        return $stmt->rowCount();
    }

    /**
     * Delete a single reaction by ID
     */
    public function deleteById(int $id): bool
    {
        $stmt = $this->prepare("DELETE FROM post_reactions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all post reactions (for export)
     */
    public function getAll(): array
    {
        $stmt = $this->query("
            SELECT page_url, reaction_type, ip_address, created_at
            FROM post_reactions
            ORDER BY page_url, created_at
        ");
        return $stmt->fetchAll();
    }

    /**
     * Count total reactions across all pages
     */
    public function countTotal(): int
    {
        $stmt = $this->query("SELECT COUNT(*) as count FROM post_reactions");
        $result = $stmt->fetch();
        return (int)$result['count'];
    }
}
