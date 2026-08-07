<?php

namespace Services;

use Repositories\EmailQueueRepository;
use Repositories\SubscriptionRepository;
use Repositories\SettingsRepository;
use Repositories\CommentRepository;
use Helpers\SecurityHelper;
use Helpers\UrlHelper;
use Helpers\ReactionHelper;

/**
 * Email Service
 * Handles email queueing and notification logic
 */
class EmailService
{
    private EmailQueueRepository $emailQueueRepo;
    private SubscriptionRepository $subscriptionRepo;
    private SettingsRepository $settingsRepo;
    private CommentRepository $commentRepo;

    public function __construct(
        EmailQueueRepository $emailQueueRepo,
        SubscriptionRepository $subscriptionRepo,
        SettingsRepository $settingsRepo,
        CommentRepository $commentRepo
    ) {
        $this->emailQueueRepo = $emailQueueRepo;
        $this->subscriptionRepo = $subscriptionRepo;
        $this->settingsRepo = $settingsRepo;
        $this->commentRepo = $commentRepo;
    }

    /**
     * Queue a notification email for a new comment
     */
    public function sendCommentNotification(int $commentId, string $pageUrl, ?int $parentId, string $authorName, string $content, string $authorEmail): void
    {
        if (!$this->isNotificationsEnabled()) {
            return;
        }

        $safeAuthorName = SecurityHelper::sanitizeEmailContent($authorName);
        $safeContent = SecurityHelper::sanitizeEmailContent($content);
        $resolvedPageUrl = UrlHelper::resolvePageUrl($pageUrl);
        $safePageUrl = SecurityHelper::sanitizeEmailContent($resolvedPageUrl);
        $notifiedEmails = [];

        // Notify parent comment author if this is a reply
        if ($parentId !== null) {
            $parent = $this->commentRepo->getById($parentId);
            
            if ($parent && !empty($parent['author_email']) && $parent['author_email'] !== $authorEmail) {
                $safeParentName = SecurityHelper::sanitizeEmailContent($parent['author_name']);
                $unsubscribeUrl = $this->getUnsubscribeUrl($pageUrl, $parent['author_email']);

                $subject = 'New reply to your comment';
                $message = "Hello {$safeParentName},\n\n";
                $message .= "{$safeAuthorName} replied to your comment on {$safePageUrl}:\n\n";
                $message .= "{$safeContent}\n\n";
                $message .= "View and reply: {$safePageUrl}#comment-{$commentId}\n\n";
                if ($unsubscribeUrl) {
                    $message .= "---\n";
                    $message .= "To unsubscribe from notifications: {$unsubscribeUrl}\n";
                }

                $this->emailQueueRepo->queue($commentId, $parent['author_email'], $safeParentName, 'parent_reply', $subject, $message);
                $notifiedEmails[] = $parent['author_email'];
            }
        }

        // Notify all page subscribers
        $subscribers = $this->subscriptionRepo->getActiveByPage($pageUrl, $authorEmail);
        
        foreach ($subscribers as $subscriber) {
            if (in_array($subscriber['email'], $notifiedEmails)) {
                continue;
            }

            $unsubscribeUrl = defined('APP_URL') ? APP_URL . '/unsubscribe.php?token=' . $subscriber['token'] : '';

            $subject = 'New comment on ' . parse_url($pageUrl, PHP_URL_PATH);
            $message = "Hello,\n\n";
            $message .= "{$safeAuthorName} posted a new comment on {$safePageUrl}:\n\n";
            $message .= "{$safeContent}\n\n";
            $message .= "View and reply: {$safePageUrl}#comment-{$commentId}\n\n";
            $message .= "---\n";
            $message .= "To unsubscribe from notifications for this page: {$unsubscribeUrl}\n";

            $this->emailQueueRepo->queue($commentId, $subscriber['email'], '', 'subscriber', $subject, $message);
        }

        // Notify admin
        $adminEmail = $this->settingsRepo->get('admin_email');
        if (!empty($adminEmail)) {
            $adminPanelUrl = defined('APP_URL') ? APP_URL . '/admin.html' : '/admin.html';
            $subject = 'New comment on your site';
            $message = "New comment from {$safeAuthorName} on {$safePageUrl}:\n\n";
            $message .= "{$safeContent}\n\n";
            $message .= "Manage comments: {$adminPanelUrl}\n";

            $this->emailQueueRepo->queue($commentId, $adminEmail, 'Admin', 'admin', $subject, $message);
        }
    }

    /**
     * Queue a notification email for a comment reaction
     */
    public function sendReactionNotification(int $commentId, string $pageUrl, string $authorName, string $authorEmail, string $reactionType): void
    {
        if (empty($authorEmail) || !$this->isNotificationsEnabled()) {
            return;
        }

        $reactionLabel = ReactionHelper::getReactionEmailLabel($reactionType);
        $safeAuthorName = SecurityHelper::sanitizeEmailContent($authorName);
        $safePageUrl = SecurityHelper::sanitizeEmailContent(UrlHelper::resolvePageUrl($pageUrl));
        $unsubscribeUrl = $this->getUnsubscribeUrl($pageUrl, $authorEmail);

        $subject = 'Someone reacted to your comment';
        $message = "Hello {$safeAuthorName},\n\n";
        $message .= "Someone left a {$reactionLabel} reaction on your comment at {$safePageUrl}.\n\n";
        $message .= "View your comment: {$safePageUrl}#comment-{$commentId}\n\n";
        
        if ($unsubscribeUrl) {
            $message .= "---\n";
            $message .= "To unsubscribe from notifications: {$unsubscribeUrl}\n";
        }

        $this->emailQueueRepo->queue($commentId, $authorEmail, $safeAuthorName, 'reaction', $subject, $message);
    }

    /**
     * Queue a notification email for a post reaction
     */
    public function sendPostReactionNotification(string $pageUrl, string $reactionType): void
    {
        if (!$this->isNotificationsEnabled()) {
            return;
        }

        $adminEmail = $this->settingsRepo->get('admin_email');
        if (empty($adminEmail)) {
            return;
        }

        $reactionLabel = ReactionHelper::getReactionEmailLabel($reactionType);
        $safePageUrl = SecurityHelper::sanitizeEmailContent(UrlHelper::resolvePageUrl($pageUrl));
        $reactionsUrl = defined('APP_URL') ? APP_URL . '/admin-post-reactions.html' : '/admin-post-reactions.html';

        $subject = 'New post reaction on your site';
        $message = "Someone left a {$reactionLabel} reaction on {$safePageUrl}.\n\n";
        $message .= "View post reactions: {$reactionsUrl}\n";

        $this->emailQueueRepo->queue(null, $adminEmail, 'Admin', 'post_reaction', $subject, $message);
    }

    /**
     * Check if notifications are enabled
     */
    public function isNotificationsEnabled(): bool
    {
        $value = $this->settingsRepo->get('enable_notifications');
        return $value === 'true';
    }

    /**
     * Get unsubscribe URL for a given page+email pair
     */
    private function getUnsubscribeUrl(string $pageUrl, string $email): string
    {
        $sub = $this->subscriptionRepo->findByPageAndEmail($pageUrl, $email);
        
        if (!$sub) {
            return '';
        }
        
        return defined('APP_URL') ? APP_URL . '/unsubscribe.php?token=' . $sub['token'] : '';
    }
}
