<?php

namespace Services;

use Repositories\CommentRepository;
use Repositories\VoteRepository;
use Repositories\SessionRepository;
use Repositories\LoginAttemptRepository;
use Repositories\EmailQueueRepository;
use PDO;

/**
 * Database Service
 * Handles database maintenance and cleanup operations
 */
class DatabaseService
{
    private CommentRepository $commentRepo;
    private VoteRepository $voteRepo;
    private SessionRepository $sessionRepo;
    private LoginAttemptRepository $loginAttemptRepo;
    private EmailQueueRepository $emailQueueRepo;
    private PDO $db;

    public function __construct(
        CommentRepository $commentRepo,
        VoteRepository $voteRepo,
        SessionRepository $sessionRepo,
        LoginAttemptRepository $loginAttemptRepo,
        EmailQueueRepository $emailQueueRepo,
        PDO $db
    ) {
        $this->commentRepo = $commentRepo;
        $this->voteRepo = $voteRepo;
        $this->sessionRepo = $sessionRepo;
        $this->loginAttemptRepo = $loginAttemptRepo;
        $this->emailQueueRepo = $emailQueueRepo;
        $this->db = $db;
    }

    /**
     * Perform periodic cleanup (call probabilistically)
     */
    public function periodicCleanup(): void
    {
        // Clean vote logs older than 2 hours
        $this->voteRepo->cleanOldLogs('-2 hours');

        // Clean login attempts older than 2 hours
        $this->loginAttemptRepo->cleanOld('-2 hours');

        // Clean expired sessions
        $this->sessionRepo->deleteExpired();

        // Clean old email queue entries (if table exists)
        if ($this->emailQueueRepo->exists()) {
            $this->emailQueueRepo->cleanOld(30, 7);
        }
    }

    /**
     * Vacuum the database
     */
    public function vacuum(): array
    {
        $sizeBefore = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
        $this->periodicCleanup();
        $this->db->exec('VACUUM');
        $sizeAfter = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

        return [
            'success'     => true,
            'size_before' => $sizeBefore,
            'size_after'  => $sizeAfter,
            'saved_bytes' => max(0, $sizeBefore - $sizeAfter),
        ];
    }

    /**
     * Delete all spam comments
     */
    public function deleteSpam(): array
    {
        $count = $this->commentRepo->deleteSpam();
        return ['success' => true, 'deleted' => $count];
    }

    /**
     * Get database statistics
     */
    public function getStats(): array
    {
        $tables = ['comments', 'settings', 'subscriptions', 'email_queue', 'login_attempts', 'sessions', 'votes', 'vote_log', 'post_reactions'];
        $stats = [];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                $row = $this->db->query("SELECT COUNT(*) as count FROM {$table}")->fetch();
                $stats[$table] = (int)$row['count'];
            }
        }

        // Get comment status breakdown
        $statusCounts = [];
        $statusRows = $this->db->query("SELECT status, COUNT(*) as count FROM comments GROUP BY status")->fetchAll();
        foreach ($statusRows as $row) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }

        $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

        return [
            'tables'           => $stats,
            'comment_statuses' => $statusCounts,
            'db_size_bytes'    => $dbSize,
        ];
    }

    /**
     * Delete selected data categories
     */
    public function deleteData(array $categories, bool $preview = false): array
    {
        $allowed = ['comments', 'reactions', 'subscriptions'];
        foreach ($categories as $cat) {
            if (!in_array($cat, $allowed, true)) {
                return ['error' => 'Invalid category: ' . $cat, 'code' => 400];
            }
        }

        if (empty($categories)) {
            return ['error' => 'No categories selected', 'code' => 400];
        }

        $commentsCount = (int)($this->db->query("SELECT COUNT(*) AS c FROM comments")->fetch()['c'] ?? 0);
        $votesCount = (int)($this->db->query("SELECT COUNT(*) AS c FROM votes")->fetch()['c'] ?? 0);
        $postReactionsCount = (int)($this->db->query("SELECT COUNT(*) AS c FROM post_reactions")->fetch()['c'] ?? 0);
        $voteLogCount = (int)($this->db->query("SELECT COUNT(*) AS c FROM vote_log")->fetch()['c'] ?? 0);
        $subscriptionsCount = (int)($this->db->query("SELECT COUNT(*) AS c FROM subscriptions")->fetch()['c'] ?? 0);
        $reactionsCount = $votesCount + $postReactionsCount + $voteLogCount;

        if ($preview) {
            return [
                'success' => true,
                'counts'  => [
                    'comments'      => $commentsCount,
                    'reactions'     => $reactionsCount,
                    'subscriptions' => $subscriptionsCount,
                ],
                'details' => [
                    'votes'          => $votesCount,
                    'post_reactions' => $postReactionsCount,
                    'vote_log'       => $voteLogCount,
                ],
            ];
        }

        $toDeleteComments      = in_array('comments', $categories, true);
        $toDeleteReactions     = in_array('reactions', $categories, true);
        $toDeleteSubscriptions = in_array('subscriptions', $categories, true);

        $deleted = ['comments' => 0, 'reactions' => 0, 'subscriptions' => 0];

        $this->db->beginTransaction();
        try {
            if ($toDeleteReactions) {
                $this->db->exec("DELETE FROM votes");
                $this->db->exec("DELETE FROM post_reactions");
                $this->db->exec("DELETE FROM vote_log");
                $deleted['reactions'] = $reactionsCount;
            }

            if ($toDeleteSubscriptions) {
                $this->db->exec("DELETE FROM subscriptions");
                $deleted['subscriptions'] = $subscriptionsCount;
            }

            if ($toDeleteComments) {
                $this->db->exec("DELETE FROM comments");
                $deleted['comments'] = $commentsCount;
            }

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            return ['error' => 'Database error: ' . $e->getMessage(), 'code' => 500];
        }

        return [
            'success' => true,
            'deleted' => $deleted,
        ];
    }

    /**
     * Normalize all comment URLs
     */
    /**
     * Check if table exists
     */
    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$table]);
            return $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
