<?php

namespace Services;

use Repositories\CommentRepository;
use Repositories\VoteRepository;
use Repositories\PostReactionRepository;
use Repositories\SubscriptionRepository;
use Helpers\ReactionHelper;
use Helpers\UrlHelper;
use Helpers\SecurityHelper;
use PDO;

/**
 * Import/Export Service
 * Handles Disqus/WordPress XML import and native export
 */
class ImportExportService
{
    // Export namespace constants
    private const EXPORT_NS = 'https://example.com/ns/comments-export/1.0';
    private const EXPORT_VERSION = '1.0';

    private CommentRepository $commentRepo;
    private VoteRepository $voteRepo;
    private PostReactionRepository $postReactionRepo;
    private SubscriptionRepository $subscriptionRepo;
    private \PDO $db;

    public function __construct(
        CommentRepository $commentRepo,
        VoteRepository $voteRepo,
        PostReactionRepository $postReactionRepo,
        SubscriptionRepository $subscriptionRepo,
        \PDO $db
    ) {
        $this->commentRepo = $commentRepo;
        $this->voteRepo = $voteRepo;
        $this->postReactionRepo = $postReactionRepo;
        $this->subscriptionRepo = $subscriptionRepo;
        $this->db = $db;
    }

    /**
     * Handle JSON export of all data
     * Outputs JSON directly and exits
     */
    public function exportCommentsJson(): void
    {
        $comments = $this->commentRepo->getAllForExport();
        $votesByCommentId = $this->voteRepo->getAllGroupedByComment();
        $postReactions = $this->postReactionRepo->getAll();
        $subscriptions = $this->subscriptionRepo->getAllForExport();

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="comments_export_' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-cache');

        $baseUrl = UrlHelper::getSiteOrigin();
        $siteUrl = defined('APP_URL') ? APP_URL : $baseUrl;

        $export = [
            'version' => '1.0',
            'format' => 'standalone-comments-export',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => [
                'url' => $siteUrl,
                'name' => parse_url($siteUrl, PHP_URL_HOST) ?: 'Standalone Comments'
            ],
            'comments' => [],
            'post_reactions' => [],
            'subscriptions' => []
        ];

        // Build comments array with embedded reactions
        foreach ($comments as $comment) {
            $commentReactions = [];
            if (isset($votesByCommentId[$comment['id']])) {
                foreach ($votesByCommentId[$comment['id']] as $vote) {
                    $commentReactions[] = [
                        'reaction_type' => $vote['reaction_type'],
                        'ip_address' => $vote['ip_address'],
                        'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($vote['created_at']))
                    ];
                }
            }

            $export['comments'][] = [
                'id' => (int)$comment['id'],
                'page_url' => $comment['page_url'],
                'parent_id' => $comment['parent_id'] ? (int)$comment['parent_id'] : null,
                'author' => [
                    'name' => $comment['author_name'],
                    'email' => $comment['author_email'] ?: null,
                    'url' => $comment['author_url'] ?: null
                ],
                'content' => $comment['content'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($comment['created_at'])),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($comment['updated_at'] ?? $comment['created_at'])),
                'status' => $comment['status'],
                'ip_address' => $comment['ip_address'] ?: null,
                'user_agent' => $comment['user_agent'] ?: null,
                'reactions' => $commentReactions
            ];
        }

        // Build post reactions array
        foreach ($postReactions as $pr) {
            $export['post_reactions'][] = [
                'page_url' => $pr['page_url'],
                'reaction_type' => $pr['reaction_type'],
                'ip_address' => $pr['ip_address'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($pr['created_at']))
            ];
        }

        // Build subscriptions array
        foreach ($subscriptions as $sub) {
            $export['subscriptions'][] = [
                'page_url' => $sub['page_url'],
                'email' => $sub['email'],
                'token' => $sub['token'],
                'subscribed_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($sub['subscribed_at'])),
                'active' => (int)$sub['active'] === 1
            ];
        }

        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Handle XML export of all data
     * Outputs XML directly and exits
     */
    public function exportComments(): void
    {
        $comments = $this->commentRepo->getAllForExport();
        $votesByCommentId = $this->voteRepo->getAllGroupedByComment();
        $postReactions = $this->postReactionRepo->getAll();
        $subscriptions = $this->subscriptionRepo->getAllForExport();

        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="comments_export_' . date('Y-m-d') . '.xml"');
        header('Cache-Control: no-cache');

        $baseUrl = UrlHelper::getSiteOrigin();
        $forumHost = parse_url($baseUrl, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $forum = preg_replace('/^www\./', '', $forumHost);

        // Build thread map
        $threadMap = [];
        $threadId = 1;
        foreach ($comments as $comment) {
            if (!isset($threadMap[$comment['page_url']])) {
                $threadMap[$comment['page_url']] = $threadId++;
            }
        }

        $e = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
        $fullUrl = fn($pageUrl) => (strpos($pageUrl, 'http') === 0) ? $pageUrl : $baseUrl . $pageUrl;
        $isoDate = fn($ts) => gmdate('Y-m-d\TH:i:s\Z', strtotime($ts));
        $allowedTypes = ReactionHelper::getAllowedReactionTypes();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<commentsExport version="' . self::EXPORT_VERSION . '"' . "\n";
        echo '  xmlns="http://disqus.com"' . "\n";
        echo '  xmlns:dsq="http://disqus.com/disqus-internals"' . "\n";
        echo '  xmlns:custom="' . self::EXPORT_NS . '"' . "\n";
        echo '  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
        echo '  xsi:schemaLocation="http://disqus.com http://disqus.com/api/schemas/1.0/disqus.xsd">' . "\n\n";

        echo '  <category dsq:id="1">' . "\n";
        echo '    <forum>' . $e($forum) . '</forum>' . "\n";
        echo '    <title>General</title>' . "\n";
        echo '    <isDefault>true</isDefault>' . "\n";
        echo '  </category>' . "\n\n";

        foreach ($threadMap as $pageUrl => $tid) {
            $url = $fullUrl($pageUrl);
            echo '  <thread dsq:id="' . $tid . '">' . "\n";
            echo '    <id>' . $e($url) . '</id>' . "\n";
            echo '    <forum>' . $e($forum) . '</forum>' . "\n";
            echo '    <category dsq:id="1"/>' . "\n";
            echo '    <link>' . $e($url) . '</link>' . "\n";
            echo '    <title>' . $e($url) . '</title>' . "\n";
            echo '    <createdAt>' . gmdate('Y-m-d\TH:i:s\Z') . '</createdAt>' . "\n";
            echo '    <isClosed>false</isClosed>' . "\n";
            echo '    <isDeleted>false</isDeleted>' . "\n";
            echo '  </thread>' . "\n\n";
        }

        foreach ($comments as $comment) {
            $tid = $threadMap[$comment['page_url']];
            $status = $comment['status'];
            $isSpam = $status === 'spam' ? 'true' : 'false';
            $isDeleted = $status === 'deleted' ? 'true' : 'false';
            $approved = $status === 'approved' ? 'true' : 'false';

            echo '  <post dsq:id="' . $comment['id'] . '">' . "\n";
            echo '    <thread dsq:id="' . $tid . '"/>' . "\n";
            if ($comment['parent_id']) {
                echo '    <parent dsq:id="' . $comment['parent_id'] . '"/>' . "\n";
            }
            echo '    <author>' . "\n";
            echo '      <name>' . $e($comment['author_name']) . '</name>' . "\n";
            if ($comment['author_email']) {
                echo '      <email>' . $e($comment['author_email']) . '</email>' . "\n";
            }
            if ($comment['author_url']) {
                echo '      <link>' . $e($comment['author_url']) . '</link>' . "\n";
            }
            echo '      <isAnonymous>false</isAnonymous>' . "\n";
            echo '    </author>' . "\n";
            echo '    <message><![CDATA[' . $comment['content'] . ']]></message>' . "\n";

            $commentVotes = $votesByCommentId[(int)$comment['id']] ?? [];
            if (!empty($commentVotes)) {
                echo '    <custom:reactions>' . "\n";
                foreach ($commentVotes as $vote) {
                    if (!in_array($vote['reaction_type'], $allowedTypes, true)) continue;
                    echo '      <custom:reaction type="' . $e($vote['reaction_type']) . '"';
                    echo ' ip="' . $e($vote['ip_address']) . '"';
                    echo ' createdAt="' . $isoDate($vote['created_at']) . '"/>' . "\n";
                }
                echo '    </custom:reactions>' . "\n";
            }

            echo '    <custom:status>' . $e($status) . '</custom:status>' . "\n";
            if ($comment['ip_address']) {
                echo '    <ipAddress>' . $e($comment['ip_address']) . '</ipAddress>' . "\n";
            }
            if ($comment['user_agent']) {
                echo '    <custom:userAgent>' . $e($comment['user_agent']) . '</custom:userAgent>' . "\n";
            }
            echo '    <custom:updatedAt>' . $isoDate($comment['updated_at'] ?? $comment['created_at']) . '</custom:updatedAt>' . "\n";
            echo '    <createdAt>' . $isoDate($comment['created_at']) . '</createdAt>' . "\n";
            echo '    <isDeleted>' . $isDeleted . '</isDeleted>' . "\n";
            echo '    <isApproved>' . $approved . '</isApproved>' . "\n";
            echo '    <isFlagged>false</isFlagged>' . "\n";
            echo '    <isSpam>' . $isSpam . '</isSpam>' . "\n";
            echo '  </post>' . "\n\n";
        }

        if (!empty($postReactions)) {
            echo '  <custom:postReactions>' . "\n";
            foreach ($postReactions as $pr) {
                if (!in_array($pr['reaction_type'], $allowedTypes, true)) continue;
                echo '    <custom:reaction pageUrl="' . $e($pr['page_url']) . '"';
                echo ' type="' . $e($pr['reaction_type']) . '"';
                echo ' ip="' . $e($pr['ip_address']) . '"';
                echo ' createdAt="' . $isoDate($pr['created_at']) . '"/>' . "\n";
            }
            echo '  </custom:postReactions>' . "\n\n";
        }

        if (!empty($subscriptions)) {
            echo '  <custom:subscriptions>' . "\n";
            foreach ($subscriptions as $sub) {
                echo '    <custom:subscription pageUrl="' . $e($sub['page_url']) . '"';
                echo ' email="' . $e($sub['email']) . '"';
                echo ' token="' . $e($sub['token']) . '"';
                echo ' subscribedAt="' . $isoDate($sub['subscribed_at']) . '"';
                echo ' active="' . ((int)$sub['active'] === 1 ? '1' : '0') . '"/>' . "\n";
            }
            echo '  </custom:subscriptions>' . "\n\n";
        }

        echo '</commentsExport>' . "\n";
        exit;
    }

    /**
     * Handle import: parse, plan, and optionally execute
     * Supports both XML (Disqus/WordPress/Native) and JSON formats
     */
    public function importComments(array $input): array
    {
        $content = $input['content'] ?? '';
        if (empty($content)) {
            return ['error' => 'No file content received', 'code' => 400];
        }

        $format = $this->detectImportFormat($content);

        if ($format === 'json') {
            $parsed = $this->parseExportJson($content);
            $formatObj = $parsed;
        } else {
            // XML parsing
            if (PHP_VERSION_ID < 80000) {
                libxml_disable_entity_loader(true);
            }
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);

            if ($xml === false) {
                $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
                return ['error' => 'Invalid XML: ' . implode('; ', $errs), 'code' => 400];
            }

            $parsed = $this->parseExportXml($xml);
            $formatObj = $xml;
        }

        if (isset($parsed['error'])) {
            return ['error' => $parsed['error'], 'code' => 400];
        }

        $plan = $this->buildImportPlan($parsed);

        if (!empty($input['preview'])) {
            return $this->buildPreviewResponse($parsed, $plan, $formatObj, $format);
        }

        $result = $this->executeImport($parsed, $plan);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'code' => 500];
        }

        return [
            'success'                  => true,
            'imported'                 => $result['imported'],
            'unique_pages'             => $result['unique_pages'],
            'skipped_duplicates'       => $result['skipped_duplicates'],
            'reactions_imported'       => $result['reactions_imported'],
            'post_reactions_imported'  => $result['post_reactions_imported'],
            'subscriptions_imported'   => $result['subscriptions_imported'],
        ];
    }

    /**
     * Detect import format (JSON or XML)
     */
    private function detectImportFormat(string $content): string
    {
        $trimmed = trim($content);
        
        // Check for JSON
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($decoded['format']) && $decoded['format'] === 'standalone-comments-export') {
                    return 'json';
                }
            }
        }
        
        // Default to XML
        return 'xml';
    }

    /**
     * Parse JSON export format
     */
    private function parseExportJson(string $content): array
    {
        $json = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        // Validate structure
        if (!isset($json['format']) || $json['format'] !== 'standalone-comments-export') {
            return ['error' => 'Invalid JSON format: missing or incorrect format field'];
        }

        if (!isset($json['version'])) {
            return ['error' => 'Invalid JSON format: missing version field'];
        }

        if (!isset($json['comments']) || !is_array($json['comments'])) {
            return ['error' => 'Invalid JSON format: missing or invalid comments array'];
        }

        $allowedTypes = ReactionHelper::getAllowedReactionTypes();
        $rawPosts = [];
        $rawPostReactions = [];
        $rawSubscriptions = [];
        $threads = [];

        // Parse comments
        foreach ($json['comments'] as $comment) {
            if (!isset($comment['id'], $comment['page_url'], $comment['author'], $comment['content'])) {
                continue; // Skip invalid comments
            }

            $author = $comment['author'];
            if (!isset($author['name'])) {
                continue;
            }

            // Build thread map
            $pageUrl = $comment['page_url'];
            if (!isset($threads[$pageUrl])) {
                $threads[$pageUrl] = $pageUrl;
            }

            // Parse reactions
            $reactions = [];
            if (isset($comment['reactions']) && is_array($comment['reactions'])) {
                foreach ($comment['reactions'] as $reaction) {
                    if (!isset($reaction['reaction_type'], $reaction['ip_address'])) {
                        continue;
                    }
                    if (!in_array($reaction['reaction_type'], $allowedTypes, true)) {
                        continue;
                    }
                    $reactions[] = [
                        'reaction_type' => $reaction['reaction_type'],
                        'ip_address' => $reaction['ip_address'],
                        'created_at' => $this->parseJsonTimestamp($reaction['created_at'] ?? null),
                    ];
                }
            }

            $rawPosts[] = [
                'export_id' => (string)$comment['id'],
                'parent_export_id' => isset($comment['parent_id']) && $comment['parent_id'] ? (string)$comment['parent_id'] : null,
                'page_url' => $pageUrl,
                'author_name' => $author['name'],
                'author_email' => $author['email'] ?? '',
                'author_url' => $author['url'] ?? null,
                'content' => $comment['content'],
                'created_at' => $this->parseJsonTimestamp($comment['created_at']),
                'updated_at' => $this->parseJsonTimestamp($comment['updated_at'] ?? $comment['created_at']),
                'status' => $comment['status'] ?? 'approved',
                'ip_address' => $comment['ip_address'] ?? null,
                'user_agent' => $comment['user_agent'] ?? null,
                'reactions' => $reactions,
            ];
        }

        // Parse post reactions
        if (isset($json['post_reactions']) && is_array($json['post_reactions'])) {
            foreach ($json['post_reactions'] as $pr) {
                if (!isset($pr['page_url'], $pr['reaction_type'], $pr['ip_address'])) {
                    continue;
                }
                if (!in_array($pr['reaction_type'], $allowedTypes, true)) {
                    continue;
                }
                $rawPostReactions[] = [
                    'page_url' => $pr['page_url'],
                    'reaction_type' => $pr['reaction_type'],
                    'ip_address' => $pr['ip_address'],
                    'created_at' => $this->parseJsonTimestamp($pr['created_at']),
                ];
            }
        }

        // Parse subscriptions
        if (isset($json['subscriptions']) && is_array($json['subscriptions'])) {
            foreach ($json['subscriptions'] as $sub) {
                if (!isset($sub['page_url'], $sub['email'], $sub['token'])) {
                    continue;
                }
                $rawSubscriptions[] = [
                    'page_url' => $sub['page_url'],
                    'email' => $sub['email'],
                    'token' => $sub['token'],
                    'subscribed_at' => $this->parseJsonTimestamp($sub['subscribed_at']),
                    'active' => isset($sub['active']) && $sub['active'] ? 1 : 0,
                ];
            }
        }

        return [
            'threads' => $threads,
            'raw_posts' => $rawPosts,
            'raw_post_reactions' => $rawPostReactions,
            'raw_subscriptions' => $rawSubscriptions,
            'raw_total' => count($rawPosts),
            'skipped' => 0,
            'orphaned' => 0,
            'import_all_statuses' => true,
        ];
    }

    /**
     * Parse JSON timestamp to MySQL datetime format
     */
    private function parseJsonTimestamp($timestamp): string
    {
        if ($timestamp === null) {
            return date('Y-m-d H:i:s');
        }
        
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        
        return date('Y-m-d H:i:s', $ts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Parsing helpers (ported verbatim from api.php, kept private)
    // ─────────────────────────────────────────────────────────────────────────

    private function normalizeUrl(string $link): string
    {
        return UrlHelper::normalizeExportPageUrl($link);
    }

    private function isProjectNativeExport(\SimpleXMLElement $xml): bool
    {
        $root = $xml->getName();
        if ($root === 'commentsExport') return true;
        if ($root === 'disqus') {
            $custom = $xml->children(self::EXPORT_NS);
            return isset($custom->postReactions) || isset($custom->subscriptions);
        }
        return false;
    }

    private function parseExportCommentStatus(\SimpleXMLElement $post): string
    {
        $custom = $post->children(self::EXPORT_NS);
        if (isset($custom->status)) {
            $status = (string)$custom->status;
            if (in_array($status, ['pending', 'approved', 'spam', 'deleted'], true)) {
                return $status;
            }
        }
        if ((string)$post->isDeleted === 'true') return 'deleted';
        if ((string)$post->isSpam    === 'true') return 'spam';
        if ((string)$post->isApproved === 'true') return 'approved';
        return 'pending';
    }

    private function parseCommentReactionsFromPost(\SimpleXMLElement $post): array
    {
        $reactions = [];
        $custom = $post->children(self::EXPORT_NS);
        if (!isset($custom->reactions)) return $reactions;

        foreach ($custom->reactions->children(self::EXPORT_NS) as $node) {
            $parsed = $this->parseVoteReactionNode($node);
            if ($parsed) $reactions[] = $parsed;
        }
        return $reactions;
    }

    private function parseVoteReactionNode(\SimpleXMLElement $node): ?array
    {
        if ((string)$node->getName() !== 'reaction') return null;
        $attrs = $node->attributes();
        $type = (string)($attrs['type'] ?? '');
        if ($type === '' || !in_array($type, ReactionHelper::getAllowedReactionTypes(), true)) return null;
        $ip = (string)($attrs['ip'] ?? '');
        if ($ip === '') return null;
        $createdAt = (string)($attrs['createdAt'] ?? '');
        $ts = $createdAt !== '' ? strtotime($createdAt) : false;
        return [
            'reaction_type' => $type,
            'ip_address'    => $ip,
            'created_at'    => date('Y-m-d H:i:s', $ts !== false ? $ts : time()),
        ];
    }

    private function parsePostReactionsFromXml(\SimpleXMLElement $xml): array
    {
        $reactions = [];
        $custom = $xml->children(self::EXPORT_NS);
        if (!isset($custom->postReactions)) return $reactions;

        foreach ($custom->postReactions->children(self::EXPORT_NS) as $node) {
            if ((string)$node->getName() !== 'reaction') continue;
            $attrs = $node->attributes();
            $pageUrl = (string)($attrs['pageUrl'] ?? '');
            if ($pageUrl === '') continue;
            if (strpos($pageUrl, 'http') === 0) $pageUrl = $this->normalizeUrl($pageUrl);
            $parsed = $this->parseVoteReactionNode($node);
            if ($parsed) {
                $parsed['page_url'] = $pageUrl;
                $reactions[] = $parsed;
            }
        }
        return $reactions;
    }

    private function parseSubscriptionsFromXml(\SimpleXMLElement $xml): array
    {
        $subscriptions = [];
        $custom = $xml->children(self::EXPORT_NS);
        if (!isset($custom->subscriptions)) return $subscriptions;

        foreach ($custom->subscriptions->children(self::EXPORT_NS) as $node) {
            if ((string)$node->getName() !== 'subscription') continue;
            $attrs = $node->attributes();
            $pageUrl = (string)($attrs['pageUrl'] ?? '');
            $email   = (string)($attrs['email']   ?? '');
            $token   = (string)($attrs['token']   ?? '');
            if ($pageUrl === '' || $email === '' || $token === '') continue;
            if (strpos($pageUrl, 'http') === 0) $pageUrl = $this->normalizeUrl($pageUrl);
            $subscribedAt = (string)($attrs['subscribedAt'] ?? '');
            $ts = $subscribedAt !== '' ? strtotime($subscribedAt) : false;
            $subscriptions[] = [
                'page_url'      => $pageUrl,
                'email'         => $email,
                'token'         => $token,
                'subscribed_at' => date('Y-m-d H:i:s', $ts !== false ? $ts : time()),
                'active'        => (int)(((string)($attrs['active'] ?? '1')) !== '0'),
            ];
        }
        return $subscriptions;
    }

    private function parseExportPostNode(\SimpleXMLElement $post, array $threads, string $threadNs, bool $importAll): ?array
    {
        $isDeleted = ((string)$post->isDeleted) === 'true';
        $isSpam    = ((string)$post->isSpam)    === 'true';

        if (!$importAll && ($isDeleted || $isSpam)) return null;

        $exportId = (string)$post->attributes($threadNs)->id;
        $threadId = (string)$post->thread->attributes($threadNs)->id;
        $pageUrl  = $threads[$threadId] ?? null;
        if (!$pageUrl) return ['orphaned' => true];

        $parentExportId = null;
        if (isset($post->parent)) {
            $pid = (string)$post->parent->attributes($threadNs)->id;
            if ($pid !== '' && $pid !== '0') $parentExportId = $pid;
        }

        $custom = $post->children(self::EXPORT_NS);
        $updatedAt = isset($custom->updatedAt)
            ? date('Y-m-d H:i:s', strtotime((string)$custom->updatedAt))
            : date('Y-m-d H:i:s', strtotime((string)$post->createdAt));

        return [
            'export_id'        => $exportId,
            'parent_export_id' => $parentExportId,
            'page_url'         => $pageUrl,
            'author_name'      => (string)$post->author->name ?: 'Anonymous',
            'author_email'     => (string)$post->author->email ?: '',
            'author_url'       => (string)$post->author->link ?: null,
            'content'          => html_entity_decode(strip_tags((string)$post->message), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'created_at'       => date('Y-m-d H:i:s', strtotime((string)$post->createdAt)),
            'updated_at'       => $updatedAt,
            'status'           => $this->parseExportCommentStatus($post),
            'ip_address'       => ((string)($post->ipAddress ?? '')) ?: null,
            'user_agent'       => isset($custom->userAgent) ? ((string)$custom->userAgent ?: null) : null,
            'reactions'        => $this->parseCommentReactionsFromPost($post),
        ];
    }

    private function parseExportXml(\SimpleXMLElement $xml): array
    {
        $importAll  = $this->isProjectNativeExport($xml);
        $threads    = [];
        $rawPosts   = [];
        $skipped    = 0;
        $orphaned   = 0;
        $rawTotal   = 0;

        if ($xml->getName() === 'rss') {
            $wpNs = 'http://wordpress.org/export/1.0/';
            foreach ($xml->channel->item as $item) {
                $link = (string)$item->link;
                if (empty($link)) continue;
                $pageUrl = $this->normalizeUrl($link);
                $threads[$pageUrl] = $pageUrl;
                $wpChildren = $item->children($wpNs);
                if (!isset($wpChildren->comment)) continue;
                foreach ($wpChildren->comment as $comment) {
                    $wp = $comment->children($wpNs);
                    $rawTotal++;
                    if ((string)$wp->comment_approved !== '1') { $skipped++; continue; }
                    $wpId      = (string)$wp->comment_id;
                    $parentWpId = (string)$wp->comment_parent;
                    $message    = html_entity_decode(strip_tags((string)$wp->comment_content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $createdAt  = date('Y-m-d H:i:s', strtotime((string)$wp->comment_date_gmt));
                    $rawPosts[] = [
                        'export_id'        => $wpId,
                        'parent_export_id' => ($parentWpId && $parentWpId !== '0') ? $parentWpId : null,
                        'page_url'         => $pageUrl,
                        'author_name'      => (string)$wp->comment_author ?: 'Anonymous',
                        'author_email'     => (string)$wp->comment_author_email ?: '',
                        'author_url'       => (string)$wp->comment_author_url ?: null,
                        'content'          => $message,
                        'created_at'       => $createdAt,
                        'updated_at'       => $createdAt,
                        'status'           => 'approved',
                        'ip_address'       => ((string)($wp->comment_author_IP ?? '')) ?: null,
                        'user_agent'       => null,
                        'reactions'        => [],
                    ];
                }
            }
        } else {
            $rootName = $xml->getName();
            if (!in_array($rootName, ['commentsExport', 'disqus'])) {
                return ['error' => 'Unsupported export format'];
            }
            $namespaces = $xml->getNamespaces(true);
            $threadNs   = $namespaces['dsq'] ?? 'http://disqus.com/disqus-internals';

            foreach ($xml->thread as $thread) {
                $threadId = (string)$thread->attributes($threadNs)->id;
                $link     = (string)$thread->link;
                if ($threadId && $link) $threads[$threadId] = $this->normalizeUrl($link);
            }

            foreach ($xml->post as $post) {
                $rawTotal++;
                $parsed = $this->parseExportPostNode($post, $threads, $threadNs, $importAll);
                if ($parsed === null)             { $skipped++; continue; }
                if (!empty($parsed['orphaned']))  { $orphaned++; continue; }
                $rawPosts[] = $parsed;
            }
        }

        return [
            'threads'              => $threads,
            'raw_posts'            => $rawPosts,
            'raw_post_reactions'   => isset($threadNs) ? $this->parsePostReactionsFromXml($xml) : [],
            'raw_subscriptions'    => isset($threadNs) ? $this->parseSubscriptionsFromXml($xml) : [],
            'raw_total'            => $rawTotal,
            'skipped'              => $skipped,
            'orphaned'             => $orphaned,
            'import_all_statuses'  => $importAll,
        ];
    }

    private function buildImportPlan(array $parsed): array
    {
        $rawPosts          = $parsed['raw_posts'];
        $rawPostReactions  = $parsed['raw_post_reactions'];
        $rawSubscriptions  = $parsed['raw_subscriptions'];

        // Detect duplicates and map existing comment IDs
        $existingKeys = [];
        $existingCommentIds = [];
        $rows = $this->db->query("SELECT id, created_at, page_url, author_name FROM comments")->fetchAll();
        foreach ($rows as $row) {
            $key = $row['created_at'] . '|' . $row['page_url'] . '|' . $row['author_name'];
            $existingKeys[$key] = (int)$row['id'];
        }

        $dupCount = 0;
        $newPosts = [];
        $existingPosts = []; // Store posts that already exist but may need reactions
        foreach ($rawPosts as $post) {
            $key = $post['created_at'] . '|' . $post['page_url'] . '|' . $post['author_name'];
            if (isset($existingKeys[$key])) {
                $dupCount++;
                // Store existing post with its database ID for reaction import
                $post['existing_db_id'] = $existingKeys[$key];
                $existingPosts[] = $post;
            } else {
                $newPosts[] = $post;
            }
        }

        // Reaction counts - count reactions from both new and existing posts
        $reactionsInFile  = array_sum(array_map(fn($p) => count($p['reactions'] ?? []), $rawPosts));
        
        // Count reactions that will actually be imported (excluding duplicates in votes table)
        $existingVoteKeys = [];
        foreach ($this->db->query("SELECT comment_id, ip_address, reaction_type FROM votes")->fetchAll() as $r) {
            $existingVoteKeys[$r['comment_id'] . '|' . $r['ip_address'] . '|' . $r['reaction_type']] = true;
        }

        $reactionsToImport = 0;
        // Count reactions for new posts (will get new comment IDs)
        foreach ($newPosts as $post) {
            foreach ($post['reactions'] ?? [] as $reaction) {
                $reactionsToImport++;
            }
        }
        // Count reactions for existing posts (use existing comment IDs)
        foreach ($existingPosts as $post) {
            $commentId = $post['existing_db_id'];
            foreach ($post['reactions'] ?? [] as $reaction) {
                $voteKey = $commentId . '|' . $reaction['ip_address'] . '|' . $reaction['reaction_type'];
                if (!isset($existingVoteKeys[$voteKey])) {
                    $reactionsToImport++;
                }
            }
        }

        // Post reaction duplicates
        $existingPrKeys = [];
        foreach ($this->db->query("SELECT page_url, ip_address, reaction_type FROM post_reactions")->fetchAll() as $r) {
            $existingPrKeys[$r['page_url'] . '|' . $r['ip_address'] . '|' . $r['reaction_type']] = true;
        }
        $postReactionsToImport = count(array_filter($rawPostReactions, fn($pr) =>
            !isset($existingPrKeys[$pr['page_url'] . '|' . $pr['ip_address'] . '|' . $pr['reaction_type']])
        ));

        // Subscription duplicates
        $existingSubKeys = [];
        foreach ($this->db->query("SELECT page_url, email FROM subscriptions")->fetchAll() as $r) {
            $existingSubKeys[$r['page_url'] . '|' . $r['email']] = true;
        }
        $subscriptionsToImport = count(array_filter($rawSubscriptions, fn($sub) =>
            !isset($existingSubKeys[$sub['page_url'] . '|' . $sub['email']])
        ));

        return [
            'new_posts'               => $newPosts,
            'existing_posts'          => $existingPosts,
            'dup_count'               => $dupCount,
            'reactions_in_file'       => $reactionsInFile,
            'reactions_to_import'     => $reactionsToImport,
            'post_reactions_in_file'  => count($rawPostReactions),
            'post_reactions_to_import'=> $postReactionsToImport,
            'subscriptions_in_file'   => count($rawSubscriptions),
            'subscriptions_to_import' => $subscriptionsToImport,
            'raw_post_reactions'      => $rawPostReactions,
            'raw_subscriptions'       => $rawSubscriptions,
        ];
    }

    private function buildPreviewResponse(array $parsed, array $plan, $formatObj, string $format): array
    {
        $pageCounts = array_count_values(array_column($plan['new_posts'], 'page_url'));
        arsort($pageCounts);
        $topThreads = [];
        foreach (array_slice($pageCounts, 0, 5, true) as $url => $count) {
            $topThreads[] = ['url' => $url, 'count' => $count];
        }

        $dates    = array_column($parsed['raw_posts'], 'created_at');
        $dateRange = $dates ? ['oldest' => min($dates), 'newest' => max($dates)] : null;

        $warnings = [];
        if (count($parsed['threads']) === 0)    $warnings[] = 'No threads found in file.';
        if ($parsed['raw_total'] === 0)         $warnings[] = 'No posts found in file.';
        if ($parsed['orphaned'] > 0)            $warnings[] = $parsed['orphaned'] . ' post(s) reference unknown threads and will be skipped.';
        if ($plan['dup_count'] > 0)             $warnings[] = $plan['dup_count'] . ' duplicate(s) already in database — will be skipped.';

        // Determine format name
        $formatName = $format === 'json' ? 'json' : $formatObj->getName();

        return [
            'preview'              => true,
            'format'               => $formatName,
            'native_export'        => $parsed['import_all_statuses'],
            'threads'              => count($parsed['threads']),
            'posts_total'          => $parsed['raw_total'],
            'posts_import'         => count($plan['new_posts']),
            'posts_skip'           => $parsed['skipped'],
            'duplicates'           => $plan['dup_count'],
            'orphaned'             => $parsed['orphaned'],
            'reactions_in_file'    => $plan['reactions_in_file'],
            'reactions_import'     => $plan['reactions_to_import'],
            'post_reactions_in_file'   => $plan['post_reactions_in_file'],
            'post_reactions_import'    => $plan['post_reactions_to_import'],
            'subscriptions_in_file'    => $plan['subscriptions_in_file'],
            'subscriptions_import'     => $plan['subscriptions_to_import'],
            'date_range'           => $dateRange,
            'top_threads'          => $topThreads,
            'warnings'             => $warnings,
        ];
    }

    private function executeImport(array $parsed, array $plan): array
    {
        $newPosts = $plan['new_posts'];
        $existingPosts = $plan['existing_posts'] ?? [];
        usort($newPosts, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));

        $exportIdMap = [];
        $imported = $reactionsImported = $postReactionsImported = $subscriptionsImported = 0;

        $this->db->beginTransaction();
        try {
            $commentStmt = $this->db->prepare("
                INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url,
                                      content, created_at, updated_at, status, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $voteStmt = $this->db->prepare("
                INSERT OR IGNORE INTO votes (comment_id, ip_address, reaction_type, created_at)
                VALUES (?, ?, ?, ?)
            ");
            $prStmt = $this->db->prepare("
                INSERT OR IGNORE INTO post_reactions (page_url, ip_address, reaction_type, created_at)
                VALUES (?, ?, ?, ?)
            ");
            $subStmt = $this->db->prepare("
                INSERT OR REPLACE INTO subscriptions (page_url, email, token, subscribed_at, active)
                VALUES (?, ?, ?, ?, ?)
            ");

            // Import new comments
            foreach ($newPosts as $post) {
                $parentId = $post['parent_export_id'] ? ($exportIdMap[$post['parent_export_id']] ?? null) : null;

                $commentStmt->execute([
                    $post['page_url'],    $parentId,
                    $post['author_name'], $post['author_email'], $post['author_url'],
                    $post['content'],     $post['created_at'],
                    $post['updated_at'] ?? $post['created_at'],
                    $post['status'] ?? 'approved',
                    $post['ip_address'],  $post['user_agent'],
                ]);

                $newId = (int)$this->db->lastInsertId();
                $exportIdMap[$post['export_id']] = $newId;
                $imported++;

                foreach ($post['reactions'] ?? [] as $reaction) {
                    $voteStmt->execute([$newId, $reaction['ip_address'], $reaction['reaction_type'], $reaction['created_at']]);
                    if ($voteStmt->rowCount() > 0) $reactionsImported++;
                }
            }

            // Import reactions for existing comments (comments that were already in database)
            foreach ($existingPosts as $post) {
                $commentId = $post['existing_db_id'];
                foreach ($post['reactions'] ?? [] as $reaction) {
                    $voteStmt->execute([$commentId, $reaction['ip_address'], $reaction['reaction_type'], $reaction['created_at']]);
                    if ($voteStmt->rowCount() > 0) $reactionsImported++;
                }
            }

            foreach ($plan['raw_post_reactions'] as $pr) {
                $prStmt->execute([$pr['page_url'], $pr['ip_address'], $pr['reaction_type'], $pr['created_at']]);
                if ($prStmt->rowCount() > 0) $postReactionsImported++;
            }

            foreach ($plan['raw_subscriptions'] as $sub) {
                $subStmt->execute([$sub['page_url'], $sub['email'], $sub['token'], $sub['subscribed_at'], $sub['active']]);
                if ($subStmt->rowCount() > 0) $subscriptionsImported++;
            }

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            return ['error' => 'Database error: ' . $e->getMessage()];
        }

        return [
            'imported'                => $imported,
            'unique_pages'            => count(array_unique(array_column($newPosts, 'page_url'))),
            'skipped_duplicates'      => $plan['dup_count'],
            'reactions_imported'      => $reactionsImported,
            'post_reactions_imported' => $postReactionsImported,
            'subscriptions_imported'  => $subscriptionsImported,
        ];
    }
}
