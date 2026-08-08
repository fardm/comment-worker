<?php

namespace Core;

use Controllers\CommentController;
use Controllers\ReactionController;
use Controllers\AuthController;
use Controllers\SubscriptionController;
use Controllers\SettingsController;
use Controllers\AdminController;

/**
 * Router
 * Maps (method, action) pairs to controller methods.
 * Returns a Response that the entry point sends to the client.
 */
class Router
{
    private CommentController      $comments;
    private ReactionController     $reactions;
    private AuthController         $auth;
    private SubscriptionController $subscriptions;
    private SettingsController     $settings;
    private AdminController        $admin;

    public function __construct(
        CommentController      $comments,
        ReactionController     $reactions,
        AuthController         $auth,
        SubscriptionController $subscriptions,
        SettingsController     $settings,
        AdminController        $admin
    ) {
        $this->comments      = $comments;
        $this->reactions     = $reactions;
        $this->auth          = $auth;
        $this->subscriptions = $subscriptions;
        $this->settings      = $settings;
        $this->admin         = $admin;
    }

    /**
     * Dispatch the request and return a Response.
     */
    public function dispatch(Request $request): Response
    {
        // Handle CORS preflight before anything else
        if ($request->isOptions()) {
            return Response::json([])->withHeader('Content-Type', 'application/json');
        }

        $method = $request->getMethod();
        $action = $request->getAction();

        return match (true) {

            // ── Public: Comments ─────────────────────────────────────────────
            $method === 'GET'  && $action === 'comments'
                => $this->comments->index($request),

            $method === 'GET'  && $action === 'recent'
                => $this->comments->recent($request),

            $method === 'POST' && $action === 'post'
                => $this->comments->store($request),

            // ── Admin: Comment moderation ────────────────────────────────────
            $method === 'PUT'    && $action === 'moderate'
                => $this->comments->moderate($request),

            $method === 'PUT'    && $action === 'edit_content'
                => $this->comments->editContent($request),

            $method === 'DELETE' && $action === 'delete'
                => $this->comments->destroy($request),

            $method === 'GET'    && $action === 'pending'
                => $this->comments->pending($request),

            $method === 'GET'    && $action === 'all'
                => $this->comments->all($request),

            // ── Public: Reactions ────────────────────────────────────────────
            $method === 'POST' && $action === 'vote'
                => $this->reactions->vote($request),

            $method === 'POST' && $action === 'post_reaction'
                => $this->reactions->postReaction($request),

            // ── Admin: Post reactions ────────────────────────────────────────
            $method === 'GET'    && $action === 'post_reactions_summary'
                => $this->reactions->summary($request),

            $method === 'GET'    && $action === 'post_reactions_latest'
                => $this->reactions->latest($request),

            $method === 'DELETE' && $action === 'delete_post_reactions'
                => $this->reactions->deleteByPage($request),

            $method === 'DELETE' && $action === 'delete_single_reaction'
                => $this->reactions->deleteSingle($request),

            // ── Auth ─────────────────────────────────────────────────────────
            $method === 'GET'  && $action === 'csrf_token'
                => $this->auth->csrfToken($request),

            $method === 'POST' && $action === 'login'
                => $this->auth->login($request),

            $method === 'POST' && $action === 'logout'
                => $this->auth->logout($request),

            // ── Admin: Subscriptions ─────────────────────────────────────────
            $method === 'GET'    && $action === 'subscriptions'
                => $this->subscriptions->index($request),

            $method === 'POST'   && $action === 'toggle_subscription'
                => $this->subscriptions->toggle($request),

            $method === 'DELETE' && $action === 'delete_subscription'
                => $this->subscriptions->destroy($request),

            // ── Settings ─────────────────────────────────────────────────────
            $method === 'GET'  && $action === 'widget_config'
                => $this->settings->widgetConfig($request),

            $method === 'GET'  && $action === 'get_settings'
                => $this->settings->getSettings($request),

            $method === 'POST' && $action === 'save_settings'
                => $this->settings->saveSettings($request),

            // ── Admin: Analytics & utilities ─────────────────────────────────
            $method === 'GET'  && $action === 'analytics'
                => $this->admin->analytics($request),

            $method === 'GET'  && $action === 'posts_summary'
                => $this->admin->postsSummary($request),

            $method === 'GET'  && $action === 'db_stats'
                => $this->admin->dbStats($request),

            $method === 'POST' && $action === 'vacuum'
                => $this->admin->vacuum($request),

            $method === 'POST' && $action === 'delete_spam'
                => $this->admin->deleteSpam($request),

            $method === 'POST' && $action === 'db_delete_data'
                => $this->admin->deleteData($request),

            $method === 'POST' && $action === 'normalize_urls'
                => $this->admin->normalizeUrls($request),

            $method === 'POST' && $action === 'test_email'
                => $this->admin->testEmail($request),

            // ── Admin: Import / Export (with legacy aliases) ─────────────────
            $method === 'GET'  && in_array($action, ['export_comments', 'export_disqus'], true)
                => $this->admin->exportComments($request),

            $method === 'GET'  && $action === 'export_comments_json'
                => $this->admin->exportCommentsJson($request),

            $method === 'POST' && in_array($action, ['import_comments', 'import_disqus'], true)
                => $this->admin->importComments($request),

            // ── 404 catch-all ────────────────────────────────────────────────
            default => Response::json([
                'error'   => 'Not Found',
                'message' => 'The requested API action does not exist',
                'action'  => $action,
                'method'  => $method,
            ], 404),
        };
    }
}
