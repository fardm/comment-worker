<?php

namespace Services;

use Repositories\CommentRepository;
use Helpers\UrlHelper;

/**
 * Analytics Service
 * Provides aggregated analytics data and post summaries
 */
class AnalyticsService
{
    private CommentRepository $commentRepo;

    public function __construct(CommentRepository $commentRepo)
    {
        $this->commentRepo = $commentRepo;
    }

    /**
     * Get all analytics data for the dashboard
     */
    public function getAnalytics(): array
    {
        $data = $this->commentRepo->getAnalytics();

        // Cast all timeline rows to int
        foreach (['timeline_daily', 'timeline_weekly', 'timeline_monthly'] as $key) {
            foreach ($data[$key] as &$row) {
                $row['total']    = (int)$row['total'];
                $row['approved'] = (int)$row['approved'];
                $row['pending']  = (int)$row['pending'];
                $row['spam']     = (int)$row['spam'];
            }
            unset($row);
        }

        // Cast top posts to int
        foreach ($data['top_posts'] as &$p) {
            $p['total']    = (int)$p['total'];
            $p['approved'] = (int)$p['approved'];
            $p['pending']  = (int)$p['pending'];
            $p['spam']     = (int)$p['spam'];
        }
        unset($p);

        return [
            'status_totals'     => $data['status_totals'],
            'timeline'          => [
                'daily'   => $data['timeline_daily'],
                'weekly'  => $data['timeline_weekly'],
                'monthly' => $data['timeline_monthly'],
            ],
            'hourly'            => $data['hourly'],
            'weekdays'          => $data['weekdays'],
            'top_posts'         => $data['top_posts'],
            'unique_commenters' => $data['unique_commenters'],
            'unique_ips'        => $data['unique_ips'],
        ];
    }

    /**
     * Get posts summary for the admin posts page
     */
    public function getPostsSummary(?string $search = null): array
    {
        $posts = $this->commentRepo->getPostsSummary($search);

        foreach ($posts as &$post) {
            $post['total_comments']  = (int)$post['total_comments'];
            $post['approved_count']  = (int)$post['approved_count'];
            $post['pending_count']   = (int)$post['pending_count'];
            $post['spam_count']      = (int)$post['spam_count'];
            $post['deleted_count']   = (int)$post['deleted_count'];
            $post['unique_authors']  = (int)$post['unique_authors'];
            $post['unique_ips']      = (int)$post['unique_ips'];
            $post['avg_length']      = (int)$post['avg_length'];
            $post['total_reactions'] = (int)$post['total_reactions'];
        }
        unset($post);

        UrlHelper::enrichPageUrlHref($posts);

        $totalPosts    = count($posts);
        $totalComments = array_sum(array_column($posts, 'total_comments'));
        $totalSpam     = array_sum(array_column($posts, 'spam_count'));
        $totalPending  = array_sum(array_column($posts, 'pending_count'));

        return [
            'posts'   => $posts,
            'summary' => [
                'total_posts'    => $totalPosts,
                'total_comments' => $totalComments,
                'total_spam'     => $totalSpam,
                'total_pending'  => $totalPending,
            ],
        ];
    }
}
