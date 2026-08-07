<?php

namespace Repositories;

/**
 * Comment Repository
 * Handles all database operations for comments
 */
class CommentRepository extends BaseRepository
{
    /**
     * Get comments for a specific page URL with optional status filter
     */
    public function getByPageUrl(string $pageUrl, array $statuses = ['approved'], int $limit = 500, int $offset = 0): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        
        $stmt = $this->prepare("
            SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
                   c.content, c.created_at, c.status,
                   COALESCE(v.votes_heart, 0) AS votes_heart,
                   COALESCE(v.votes_thumbsup, 0) AS votes_thumbsup,
                   COALESCE(v.votes_lightbulb, 0) AS votes_lightbulb,
                   COALESCE(v.votes_funny, 0) AS votes_funny
            FROM comments c
            LEFT JOIN (
                SELECT comment_id,
                       SUM(reaction_type = 'heart') AS votes_heart,
                       SUM(reaction_type = 'thumbsup') AS votes_thumbsup,
                       SUM(reaction_type = 'lightbulb') AS votes_lightbulb,
                       SUM(reaction_type = 'funny') AS votes_funny
                FROM votes
                GROUP BY comment_id
            ) v ON v.comment_id = c.id
            WHERE c.page_url = ? AND c.status IN ($placeholders)
            ORDER BY c.created_at ASC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute(array_merge([$pageUrl], $statuses, [$limit, $offset]));
        return $stmt->fetchAll();
    }

    /**
     * Count comments for a specific page URL with optional status filter
     */
    public function countByPageUrl(string $pageUrl, array $statuses = ['approved']): int
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->prepare("
            SELECT COUNT(*) as total FROM comments
            WHERE page_url = ? AND status IN ($placeholders)
        ");
        $stmt->execute(array_merge([$pageUrl], $statuses));
        $result = $stmt->fetch();
        return (int)$result['total'];
    }

    /**
     * Get votes by reaction type for multiple comments
     */
    public function getVotesByCommentIds(array $commentIds): array
    {
        if (empty($commentIds)) {
            return [];
        }

        $votesByCommentId = [];
        $chunkSize = 500;
        
        for ($i = 0; $i < count($commentIds); $i += $chunkSize) {
            $chunk = array_slice($commentIds, $i, $chunkSize);
            if (empty($chunk)) continue;
            
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->prepare("
                SELECT comment_id, reaction_type, COUNT(*) as count
                FROM votes
                WHERE comment_id IN ($placeholders)
                GROUP BY comment_id, reaction_type
            ");
            $stmt->execute($chunk);
            
            foreach ($stmt->fetchAll() as $row) {
                $cid = (int)$row['comment_id'];
                $type = $row['reaction_type'];
                $votesByCommentId[$cid][$type] = (int)$row['count'];
            }
        }
        
        return $votesByCommentId;
    }

    /**
     * Create a new comment
     */
    public function create(array $data): int
    {
        $stmt = $this->prepare("
            INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url,
                                 content, status, ip_address, user_agent, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['page_url'],
            $data['parent_id'] ?? null,
            $data['author_name'],
            $data['author_email'],
            $data['author_url'] ?? null,
            $data['content'],
            $data['status'] ?? 'pending',
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            $data['created_at'] ?? date('Y-m-d H:i:s'),
            $data['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        return (int)$this->lastInsertId();
    }

    /**
     * Update comment status
     */
    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->prepare("UPDATE comments SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update comment content
     */
    public function updateContent(int $id, string $content): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->prepare("UPDATE comments SET content = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([$content, $now, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a comment
     */
    public function delete(int $id): bool
    {
        $stmt = $this->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get comment by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, parent_id, author_name, author_email, author_url,
                   content, created_at, updated_at, status, ip_address, user_agent
            FROM comments WHERE id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Check if parent comment exists
     */
    public function exists(int $id): bool
    {
        $stmt = $this->prepare("SELECT id FROM comments WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }

    /**
     * Count comments by IP address since a given datetime string (e.g. '-1 hour')
     * Pass a SQLite modifier string such as '-1 hour' or '-10 minutes'.
     */
    public function countByIpSince(string $ipAddress, string $modifier): int
    {
        $stmt = $this->prepare("
            SELECT COUNT(*) as count FROM comments
            WHERE ip_address = ? AND created_at > datetime('now', ?)
        ");
        $stmt->execute([$ipAddress, $modifier]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Count comments by email since a given datetime modifier (e.g. '-10 minutes')
     */
    public function countByEmailSince(string $email, string $modifier): int
    {
        $stmt = $this->prepare("
            SELECT COUNT(*) as count FROM comments
            WHERE author_email = ? AND created_at > datetime('now', ?)
        ");
        $stmt->execute([$email, $modifier]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Count approved comments by email
     */
    public function countApprovedByEmail(string $email): int
    {
        $stmt = $this->prepare("
            SELECT COUNT(*) as count FROM comments
            WHERE author_email = ? AND status = 'approved'
        ");
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Get pending comments
     */
    public function getPending(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->prepare("
            SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
                   c.content, c.created_at, c.status, c.ip_address,
                   COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'heart'), 0) AS votes_heart,
                   COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'thumbsup'), 0) AS votes_thumbsup,
                   COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'lightbulb'), 0) AS votes_lightbulb,
                   COALESCE((SELECT COUNT(*) FROM votes WHERE comment_id = c.id AND reaction_type = 'funny'), 0) AS votes_funny
            FROM comments c
            WHERE c.status = 'pending'
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Count pending comments
     */
    public function countPending(): int
    {
        $stmt = $this->query("SELECT COUNT(*) as total FROM comments WHERE status = 'pending'");
        $result = $stmt->fetch();
        return (int)$result['total'];
    }

    /**
     * Get all comments with optional filters
     */
    public function getAll(int $limit = 50, int $offset = 0, ?string $status = null, ?string $search = null): array
    {
        $where = [];
        $params = [];
        
        if ($status && in_array($status, ['pending', 'approved', 'spam', 'deleted'])) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        
        if ($search) {
            $where[] = '(c.author_name LIKE ? OR c.author_email LIKE ? OR c.page_url LIKE ? OR c.content LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        
        $stmt = $this->prepare("
            SELECT c.id, c.page_url, c.parent_id, c.author_name, c.author_email, c.author_url,
                   c.content, c.created_at, c.status, c.ip_address,
                   COALESCE(v.votes_heart, 0)     AS votes_heart,
                   COALESCE(v.votes_thumbsup, 0)  AS votes_thumbsup,
                   COALESCE(v.votes_lightbulb, 0) AS votes_lightbulb,
                   COALESCE(v.votes_funny, 0)     AS votes_funny
            FROM comments c
            LEFT JOIN (
                SELECT comment_id,
                       SUM(reaction_type = 'heart')     AS votes_heart,
                       SUM(reaction_type = 'thumbsup')  AS votes_thumbsup,
                       SUM(reaction_type = 'lightbulb') AS votes_lightbulb,
                       SUM(reaction_type = 'funny')     AS votes_funny
                FROM votes GROUP BY comment_id
            ) v ON v.comment_id = c.id
            $whereSQL
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll();
    }

    /**
     * Count all comments with optional filters
     */
    public function countAll(?string $status = null, ?string $search = null): int
    {
        $where = [];
        $params = [];
        
        if ($status && in_array($status, ['pending', 'approved', 'spam', 'deleted'])) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        
        if ($search) {
            $where[] = '(author_name LIKE ? OR author_email LIKE ? OR page_url LIKE ? OR content LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        
        $stmt = $this->prepare("SELECT COUNT(*) as total FROM comments $whereSQL");
        $stmt->execute($params);
        $result = $stmt->fetch();
        return (int)$result['total'];
    }

    /**
     * Get comment status aggregates
     */
    public function getStatusAggregates(): array
    {
        $stmt = $this->query("SELECT status, COUNT(*) as count FROM comments GROUP BY status");
        $aggregates = ['pending' => 0, 'approved' => 0, 'spam' => 0, 'deleted' => 0];
        
        foreach ($stmt->fetchAll() as $row) {
            if (isset($aggregates[$row['status']])) {
                $aggregates[$row['status']] = (int)$row['count'];
            }
        }
        
        return $aggregates;
    }

    /**
     * Delete spam comments
     */
    public function deleteSpam(): int
    {
        $stmt = $this->prepare("SELECT COUNT(*) as count FROM comments WHERE status = 'spam'");
        $stmt->execute();
        $count = (int)$stmt->fetch()['count'];
        
        $this->exec("DELETE FROM comments WHERE status = 'spam'");
        
        return $count;
    }

    /**
     * Get recent approved comments (for the recent widget)
     */
    public function getRecent(int $limit = 10): array
    {
        $stmt = $this->prepare("
            SELECT id, page_url, author_name, author_url, content, created_at
            FROM comments
            WHERE status = 'approved'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get all comments for export
     */
    public function getAllForExport(): array
    {
        $stmt = $this->query("
            SELECT id, page_url, parent_id, author_name, author_email, author_url,
                   content, created_at, updated_at, status, ip_address, user_agent
            FROM comments
            ORDER BY created_at ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get posts summary (aggregated by page_url)
     */
    public function getPostsSummary(?string $search = null): array
    {
        $where = $search ? 'WHERE c.page_url LIKE ?' : '';
        $params = $search ? ['%' . $search . '%'] : [];
        
        $stmt = $this->prepare("
            SELECT
                c.page_url,
                COUNT(*)                                                        AS total_comments,
                SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END)         AS approved_count,
                SUM(CASE WHEN c.status = 'pending'  THEN 1 ELSE 0 END)         AS pending_count,
                SUM(CASE WHEN c.status = 'spam'     THEN 1 ELSE 0 END)         AS spam_count,
                SUM(CASE WHEN c.status = 'deleted'  THEN 1 ELSE 0 END)         AS deleted_count,
                MIN(c.created_at)                                               AS first_comment_at,
                MAX(c.created_at)                                               AS last_comment_at,
                COUNT(DISTINCT c.author_email)                                  AS unique_authors,
                COUNT(DISTINCT c.ip_address)                                    AS unique_ips,
                ROUND(AVG(LENGTH(c.content)))                                   AS avg_length,
                COALESCE(pr.total_reactions, 0)                                 AS total_reactions
            FROM comments c
            LEFT JOIN (
                SELECT page_url, COUNT(*) AS total_reactions
                FROM post_reactions
                GROUP BY page_url
            ) pr ON c.page_url = pr.page_url
            $where
            GROUP BY c.page_url
            ORDER BY last_comment_at DESC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get analytics data
     */
    public function getAnalytics(): array
    {
        $data = [];
        
        // Status totals
        $data['status_totals'] = $this->getStatusAggregates();
        
        // Daily timeline (last 90 days)
        $stmt = $this->query("
            SELECT strftime('%Y-%m-%d', created_at) AS period,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
            FROM comments
            WHERE created_at >= datetime('now', '-90 days')
            GROUP BY period ORDER BY period ASC
        ");
        $data['timeline_daily'] = $stmt->fetchAll();
        
        // Weekly timeline (last 52 weeks)
        $stmt = $this->query("
            SELECT strftime('%Y-W%W', created_at) AS period,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
            FROM comments
            WHERE created_at >= datetime('now', '-364 days')
            GROUP BY period ORDER BY period ASC
        ");
        $data['timeline_weekly'] = $stmt->fetchAll();
        
        // Monthly timeline (all time)
        $stmt = $this->query("
            SELECT strftime('%Y-%m', created_at) AS period,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
            FROM comments
            GROUP BY period ORDER BY period ASC
        ");
        $data['timeline_monthly'] = $stmt->fetchAll();
        
        // Hour of day distribution
        $stmt = $this->query("
            SELECT CAST(strftime('%H', created_at) AS INTEGER) AS hour, COUNT(*) AS count
            FROM comments GROUP BY hour ORDER BY hour
        ");
        $hourly = array_fill(0, 24, 0);
        foreach ($stmt->fetchAll() as $r) {
            $hourly[(int)$r['hour']] = (int)$r['count'];
        }
        $data['hourly'] = $hourly;
        
        // Day of week distribution
        $stmt = $this->query("
            SELECT CAST(strftime('%w', created_at) AS INTEGER) AS dow, COUNT(*) AS count
            FROM comments GROUP BY dow ORDER BY dow
        ");
        $weekdays = array_fill(0, 7, 0);
        foreach ($stmt->fetchAll() as $r) {
            $weekdays[(int)$r['dow']] = (int)$r['count'];
        }
        $data['weekdays'] = $weekdays;
        
        // Top 10 posts
        $stmt = $this->query("
            SELECT page_url,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN status='spam'     THEN 1 ELSE 0 END) AS spam
            FROM comments
            GROUP BY page_url ORDER BY total DESC LIMIT 10
        ");
        $data['top_posts'] = $stmt->fetchAll();
        
        // Unique commenters
        $stmt = $this->query("
            SELECT COUNT(DISTINCT author_email) AS emails, COUNT(DISTINCT ip_address) AS ips
            FROM comments
        ");
        $unique = $stmt->fetch();
        $data['unique_commenters'] = (int)$unique['emails'];
        $data['unique_ips'] = (int)$unique['ips'];
        
        return $data;
    }

    /**
     * Normalize page URLs (strip scheme+host)
     */
    public function normalizeUrls(): int
    {
        $stmt = $this->query("SELECT DISTINCT page_url FROM comments WHERE page_url LIKE 'http%'");
        $fullUrls = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $fixed = 0;
        $update = $this->prepare("UPDATE comments SET page_url = ? WHERE page_url = ?");
        
        foreach ($fullUrls as $url) {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? $url;
            if (isset($parsed['query'])) $path .= '?' . $parsed['query'];
            if (isset($parsed['fragment'])) $path .= '#' . $parsed['fragment'];
            
            if ($path !== $url) {
                $update->execute([$path, $url]);
                $fixed += $update->rowCount();
            }
        }
        
        return $fixed;
    }
}
