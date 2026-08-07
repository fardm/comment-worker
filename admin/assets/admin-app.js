/**
 * admin-app.js
 * Single-page application shell for the admin panel.
 *
 * Responsibilities:
 *   - Auth orchestration (delegates to AdminAuth from admin-common.js)
 *   - Navigation rendering and active-link management
 *   - Hash-based routing: mount/unmount page views
 *   - View registry: each view defines { title, css, html(), init() }
 *   - Window-scope hoisting of onclick handlers per view
 *
 * Views are defined at the bottom of this file, one per page.
 * Each view's init() contains the page logic copied verbatim from the
 * original HTML files — no behavior changes.
 */

'use strict';

// ── Navigation definition ─────────────────────────────────────────────────────

const NAV_ITEMS = [
    { key: 'pending',        label: 'Pending'        },
    { key: 'all',            label: 'All Comments'   },
    { key: 'subscriptions',  label: 'Subscriptions'  },
    { key: 'post-reactions', label: 'Post Reactions' },
    { key: 'posts',          label: 'Posts'          },
    { key: 'analytics',      label: 'Analytics'      },
    { key: 'utilities',      label: 'Utilities'      },
];

// ── Router state ──────────────────────────────────────────────────────────────

let _currentViewKey = null;
let _currentStyleEl = null;       // <style> injected for the active view
let _currentCleanup = null;       // cleanup fn returned by the active view's init()
let _windowHandlers = [];         // { name } of properties hoisted to window

// ── Nav rendering ─────────────────────────────────────────────────────────────

function renderNav(activeKey) {
    const nav = document.getElementById('admin-nav');
    if (!nav) return;
    nav.innerHTML = NAV_ITEMS.map(({ key, label }) => {
        const cls = key === activeKey ? ' class="active"' : '';
        return `<a href="#${key}"${cls}>${label}</a>`;
    }).join('') +
        `<a href="#" onclick="AdminAuth.logout(); return false;" class="logout-btn">Logout</a>`;
}

// ── View mounting / unmounting ────────────────────────────────────────────────

/**
 * Unmount the current view:
 *   - Remove its injected <style>
 *   - Call its cleanup function (if any)
 *   - Remove window-hoisted handlers
 */
function unmountCurrent() {
    if (_currentCleanup) {
        try { _currentCleanup(); } catch (_) {}
        _currentCleanup = null;
    }

    if (_currentStyleEl) {
        _currentStyleEl.remove();
        _currentStyleEl = null;
    }

    for (const name of _windowHandlers) {
        try { delete window[name]; } catch (_) { window[name] = undefined; }
    }
    _windowHandlers = [];

    // Clear the mount point
    const app = document.getElementById('app');
    if (app) app.innerHTML = '';
}

/**
 * Hoist a map of { fnName: fn } to window so inline onclick= handlers work.
 * Tracks names for cleanup on unmount.
 */
function hoistToWindow(handlers) {
    for (const [name, fn] of Object.entries(handlers)) {
        window[name] = fn;
        _windowHandlers.push(name);
    }
}

/**
 * Mount a view by key.
 */
async function mountView(key) {
    const view = VIEWS[key];
    if (!view) {
        console.warn(`[admin-app] Unknown view key: "${key}"`);
        return;
    }

    if (_currentViewKey === key) return;   // already mounted

    unmountCurrent();
    _currentViewKey = key;

    // Update document title
    document.title = view.title
        ? `Comment System Admin — ${view.title}`
        : 'Comment System Admin';

    // Inject view-specific CSS into <head>
    if (view.css) {
        _currentStyleEl = document.createElement('style');
        _currentStyleEl.textContent = view.css;
        document.head.appendChild(_currentStyleEl);
    }

    // Inject view HTML into #app
    const app = document.getElementById('app');
    if (app) app.innerHTML = view.html();

    // Update nav active state
    renderNav(key);

    // Run the view's init — it may return a cleanup function
    if (view.init) {
        try {
            const cleanup = await view.init({ hoistToWindow });
            if (typeof cleanup === 'function') _currentCleanup = cleanup;
        } catch (err) {
            console.error(`[admin-app] View "${key}" init() threw:`, err);
        }
    }
}

// ── Hash routing ──────────────────────────────────────────────────────────────

function currentHash() {
    const h = window.location.hash.slice(1);       // strip leading '#'
    return VIEWS[h] ? h : 'pending';               // default to pending
}

function handleHashChange() {
    mountView(currentHash());
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

(function boot() {
    // Auth probe uses the lightest admin-only endpoint
    AdminAuth.init({
        authProbeUrl: `${API_URL}?action=pending&limit=1`,
        onSuccess() {
            document.getElementById('login-section').style.display  = 'none';
            document.getElementById('admin-shell').style.display    = 'block';

            // Initial route
            mountView(currentHash());

            // Listen for subsequent navigation
            window.addEventListener('hashchange', handleHashChange);
        },
    });
})();


// ═════════════════════════════════════════════════════════════════════════════
// VIEW REGISTRY
// Each entry: { title, css, html(), init({ hoistToWindow }) }
// html()  → returns the inner HTML string for #app (no <html>/<head>/<body>)
// init()  → runs after HTML is in the DOM; hoists onclick handlers to window;
//           optionally returns a cleanup() function called before unmounting
// CSS and JS are copied verbatim from the original HTML pages.
// ═════════════════════════════════════════════════════════════════════════════

const VIEWS = {};

// ─────────────────────────────────────────────────────────────────────────────
// PENDING COMMENTS
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['pending'] = {
    title: 'Pending Comments',
    css: ``,    /* no page-specific styles */
    html: () => `
        <div class="container">
            <div class="comments-section">
                <h2 style="margin-bottom: 1.5rem;">Pending Comments</h2>
                <div id="pending-comments">
                    <p class="no-comments">Loading...</p>
                </div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        async function loadDashboard() {
            await loadPendingComments();
            loadPostReactionsStat();
        }

        async function loadPostReactionsStat() {
            try {
                const response = await fetch(`${API_URL}?action=post_reactions_summary`, { credentials: 'include' });
                if (response.ok) {
                    const data = await response.json();
                    const el = document.getElementById('stat-post-reactions');
                    if (el) el.textContent = data.total || 0;
                }
            } catch (e) {}
        }

        async function loadPendingComments() {
            const container = document.getElementById('pending-comments');
            if (!container) return;
            try {
                const response = await fetch(`${API_URL}?action=pending&limit=10000&_=${Date.now()}`, {
                    credentials: 'include',
                    cache: 'no-store',
                });
                const data = await response.json();
                if (response.ok) {
                    displayPendingComments(data.comments);
                } else {
                    container.innerHTML = `<div class="message error">Error: ${data.error || 'Failed to load comments'}</div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="message error">Network error: ${error.message}</div>`;
            }
        }

        const reactionDefs = [
            { type: 'thumbsup',  emoji: '👍' }, { type: 'lightbulb', emoji: '👎' },
            { type: 'pray',      emoji: '🙏' }, { type: 'ok',        emoji: '👌' },
            { type: 'fire',      emoji: '🔥' }, { type: 'heart',     emoji: '❤️' },
            { type: 'frown',     emoji: '☹️' }, { type: 'rage',      emoji: '😡' },
            { type: 'funny',     emoji: '😄' }, { type: 'neutral',   emoji: '😐' },
        ];

        function displayPendingComments(comments) {
            const container = document.getElementById('pending-comments');
            if (!container) return;
            if (comments.length === 0) {
                container.innerHTML = '<p class="no-comments">No pending comments</p>';
                return;
            }
            container.innerHTML = comments.map(comment => {
                const votes = comment.votes_by_reaction_type || {
                    heart: comment.votes_heart || 0,
                    thumbsup: comment.votes_thumbsup || 0,
                    lightbulb: comment.votes_lightbulb || 0,
                    funny: comment.votes_funny || 0,
                };
                const reactionSummary = reactionDefs
                    .filter(r => (votes[r.type] || 0) > 0)
                    .map(r => `${r.emoji} ${votes[r.type]}`)
                    .join('&nbsp;&nbsp;');
                return `
                <div class="comment-item" id="comment-${comment.id}">
                    <div class="comment-meta">
                        <span class="comment-author">${escapeHtml(comment.author_name)}</span>
                        <span>${escapeHtml(comment.author_email)}</span>
                        ${comment.author_url ? `<a href="${escapeHtml(comment.author_url)}" target="_blank">Website</a>` : ''}
                        <span>${new Date(comment.created_at).toLocaleString()}</span>
                        <span class="badge badge-pending">Pending</span>
                    </div>
                    <div class="body-text"><strong>Page:</strong> <a href="${escapeHtml(comment.page_url_href || comment.page_url)}" target="_blank" style="color:#4a90e2;text-decoration:none;">${escapeHtml(comment.page_url_href || comment.page_url)}</a></div>
                    <div class="body-text"><strong>IP:</strong> ${escapeHtml(comment.ip_address || 'N/A')}</div>
                    <div class="comment-content" dir="auto" id="comment-content-${comment.id}">${escapeHtml(comment.content)}</div>
                    ${reactionSummary ? `<div class="body-text"><strong>Reactions:</strong> ${reactionSummary}</div>` : ''}
                    <div class="comment-actions">
                        <button class="btn btn-secondary" onclick="startCommentEdit(${comment.id})">Edit</button>
                        <button class="btn btn-success" onclick="moderateComment(${comment.id}, 'approved')">Approve</button>
                        <button class="btn btn-warning" onclick="moderateComment(${comment.id}, 'spam')">Mark as Spam</button>
                        <button class="btn btn-danger" onclick="deleteComment(${comment.id})">Delete</button>
                    </div>
                </div>`;
            }).join('');
        }

        async function moderateComment(id, status) {
            const commentEl = document.getElementById(`comment-${id}`);
            if (!commentEl) return;
            const originalHTML = commentEl.innerHTML;
            try {
                await AdminAuth.ensureCsrfToken();
                commentEl.style.opacity = '0.5';
                commentEl.innerHTML = `<p style="text-align:center;padding:2rem;">Processing...</p>`;
                const response = await fetch(`${API_URL}?action=moderate&id=${id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
                    credentials: 'include',
                    body: JSON.stringify({ status, csrf_token: AdminAuth.getCsrfToken() }),
                });
                const result = await response.json();
                if (response.ok) {
                    commentEl.innerHTML = `<p style="text-align:center;padding:2rem;color:green;">✓ ${status === 'approved' ? 'Approved' : 'Marked as spam'}!</p>`;
                    setTimeout(() => loadPendingComments(), 500);
                } else {
                    commentEl.style.opacity = '1';
                    commentEl.innerHTML = originalHTML + `<p class="error" style="margin-top:1rem;">Failed: ${result.error || 'Unknown error'}</p>`;
                }
            } catch (error) {
                commentEl.style.opacity = '1';
                commentEl.innerHTML = originalHTML + '<p class="error" style="margin-top:1rem;">Network error</p>';
            }
        }

        async function deleteComment(id) {
            if (!confirm('Are you sure you want to delete this comment?')) return;
            try {
                await AdminAuth.ensureCsrfToken();
                const response = await fetch(`${API_URL}?action=delete&id=${id}&csrf_token=${encodeURIComponent(AdminAuth.getCsrfToken())}`, {
                    method: 'DELETE', credentials: 'include',
                });
                if (response.ok) loadPendingComments();
            } catch (error) { console.error('Error deleting comment:', error); }
        }

        hoistToWindow({ moderateComment, deleteComment, startCommentEdit });
        loadDashboard();
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// ALL COMMENTS
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['all'] = {
    title: 'All Comments',
    css: `
        .filters {
            background: var(--on-background);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .filters select, .filters input[type="text"] {
            padding: 0.6rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.95rem;
        }
        .filters select { background: white; flex-shrink: 0; }
        @media (max-width: 768px) {
            .filters { flex-direction: column; align-items: stretch; }
        }`,

    html: () => `
        <div class="container">
            <div class="stats" id="stats">
                <div class="stat-card" onclick="window.location.hash='pending'">
                    <div class="stat-number" id="stat-pending">0</div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card" onclick="applyStatusFilter('approved')">
                    <div class="stat-number" id="stat-approved">0</div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card" onclick="applyStatusFilter('spam')">
                    <div class="stat-number" id="stat-spam">0</div>
                    <div class="stat-label">Spam</div>
                </div>
                <div class="stat-card" onclick="clearFilters()">
                    <div class="stat-number" id="stat-total">0</div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-card" onclick="window.location.hash='post-reactions'">
                    <div class="stat-number" id="stat-post-reactions">—</div>
                    <div class="stat-label">Post Reactions</div>
                </div>
            </div>
            <div class="filters">
                <select id="filter-status" onchange="applyFilters()">
                    <option value="all">All Statuses</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="spam">Spam</option>
                </select>
                <input type="text" id="filter-search" placeholder="Search by name, email, URL, or content…"
                       style="flex:1;min-width:200px;" onkeydown="if(event.key==='Enter') applyFilters();">
                <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                <button class="btn btn-warning" onclick="clearFilters()">Clear</button>
            </div>
            <div class="comments-section">
                <h2 style="margin-bottom:1.5rem;">All Comments</h2>
                <div id="all-comments"><p class="no-comments">Loading...</p></div>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        let currentPage  = 1;
        let currentTotal = 0;
        const commentsPerPage = 50;
        const reactionDefs = [
            { type: 'thumbsup',  emoji: '👍' }, { type: 'lightbulb', emoji: '👎' },
            { type: 'pray',      emoji: '🙏' }, { type: 'ok',        emoji: '👌' },
            { type: 'fire',      emoji: '🔥' }, { type: 'heart',     emoji: '❤️' },
            { type: 'frown',     emoji: '☹️' }, { type: 'rage',      emoji: '😡' },
            { type: 'funny',     emoji: '😄' }, { type: 'neutral',   emoji: '😐' },
        ];

        async function loadDashboard() {
            await loadPage(1);
            loadPostReactionsStat();
        }

        async function loadPostReactionsStat() {
            try {
                const r = await fetch(`${API_URL}?action=post_reactions_summary`, { credentials: 'include' });
                if (r.ok) {
                    document.getElementById('stat-post-reactions').textContent = (await r.json()).total || 0;
                }
            } catch (e) {}
        }

        async function loadPage(page) {
            currentPage = page;
            const container = document.getElementById('all-comments');
            if (!container) return;
            container.innerHTML = '<p class="no-comments">Loading…</p>';
            const status = document.getElementById('filter-status').value;
            const search = document.getElementById('filter-search').value.trim();
            const qs = new URLSearchParams({ action: 'all', limit: commentsPerPage, offset: (page - 1) * commentsPerPage });
            if (status !== 'all') qs.set('status', status);
            if (search) qs.set('search', search);
            try {
                const r = await fetch(`${API_URL}?${qs}`, { credentials: 'include', cache: 'no-store' });
                const data = await r.json();
                if (r.ok) {
                    currentTotal = data.pagination.total;
                    displayComments(data.comments);
                    renderPagination(data.pagination.total);
                    updateStats(data.aggregates);
                } else {
                    container.innerHTML = `<div class="message error">Error: ${data.error || 'Failed to load'}</div>`;
                }
            } catch (e) {
                container.innerHTML = `<div class="message error">Network error: ${e.message}</div>`;
            }
        }

        function applyFilters()  { loadPage(1); }
        function applyStatusFilter(status) {
            document.getElementById('filter-status').value = status;
            document.getElementById('filter-search').value = '';
            loadPage(1);
            document.querySelector('.filters')?.scrollIntoView({ behavior: 'smooth' });
        }
        function clearFilters() {
            document.getElementById('filter-status').value = 'all';
            document.getElementById('filter-search').value = '';
            loadPage(1);
        }

        function displayComments(comments) {
            const container = document.getElementById('all-comments');
            if (!container) return;
            if (comments.length === 0) {
                container.innerHTML = '<p class="no-comments">No comments found</p>';
                document.getElementById('pagination').innerHTML = '';
                return;
            }
            container.innerHTML = comments.map(comment => {
                const votes = comment.votes_by_reaction_type || {
                    heart: comment.votes_heart || 0, thumbsup: comment.votes_thumbsup || 0,
                    lightbulb: comment.votes_lightbulb || 0, funny: comment.votes_funny || 0,
                };
                const reactionSummary = reactionDefs
                    .filter(x => (votes[x.type] || 0) > 0)
                    .map(x => `${x.emoji} ${votes[x.type]}`).join('&nbsp;&nbsp;');
                return `
                <div class="comment-item" id="comment-${comment.id}">
                    <div class="comment-meta">
                        <span class="comment-author">${escapeHtml(comment.author_name)}</span>
                        <span>${escapeHtml(comment.author_email)}</span>
                        ${comment.author_url ? `<a href="${escapeHtml(comment.author_url)}" target="_blank">Website</a>` : ''}
                        <span>${new Date(comment.created_at).toLocaleString()}</span>
                        <span class="badge badge-${comment.status}">${comment.status}</span>
                    </div>
                    <div class="body-text"><strong>Page:</strong> <a href="${escapeHtml(comment.page_url_href || comment.page_url)}" target="_blank" style="color:#4a90e2;text-decoration:none;">${escapeHtml(comment.page_url_href || comment.page_url)}</a></div>
                    <div class="body-text"><strong>IP:</strong> ${escapeHtml(comment.ip_address || 'N/A')}</div>
                    ${comment.parent_id ? `<div class="body-text"><strong>Reply to:</strong> Comment #${comment.parent_id}</div>` : ''}
                    <div class="comment-content" dir="auto" id="comment-content-${comment.id}">${escapeHtml(comment.content)}</div>
                    ${reactionSummary ? `<div class="body-text"><strong>Reactions:</strong> ${reactionSummary}</div>` : ''}
                    <div class="comment-actions">
                        <button class="btn btn-secondary" onclick="startCommentEdit(${comment.id})">Edit</button>
                        ${comment.status !== 'approved' ? `<button class="btn btn-success" onclick="moderateComment(${comment.id}, 'approved')">Approve</button>` : ''}
                        ${comment.status !== 'spam' ? `<button class="btn btn-warning" onclick="moderateComment(${comment.id}, 'spam')">Mark as Spam</button>` : ''}
                        <button class="btn btn-danger" onclick="deleteComment(${comment.id})">Delete</button>
                    </div>
                </div>`;
            }).join('');
        }

        function updateStats(agg) {
            document.getElementById('stat-pending').textContent  = agg.pending  ?? 0;
            document.getElementById('stat-approved').textContent = agg.approved ?? 0;
            document.getElementById('stat-spam').textContent     = agg.spam     ?? 0;
            document.getElementById('stat-total').textContent    =
                (agg.pending ?? 0) + (agg.approved ?? 0) + (agg.spam ?? 0) + (agg.deleted ?? 0);
        }

        async function moderateComment(id, status) {
            const commentEl = document.getElementById(`comment-${id}`);
            if (!commentEl) return;
            const originalHTML = commentEl.innerHTML;
            try {
                await AdminAuth.ensureCsrfToken();
                commentEl.style.opacity = '0.5';
                commentEl.innerHTML = `<p style="text-align:center;padding:2rem;">Processing...</p>`;
                const r = await fetch(`${API_URL}?action=moderate&id=${id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
                    credentials: 'include',
                    body: JSON.stringify({ status, csrf_token: AdminAuth.getCsrfToken() }),
                });
                const result = await r.json();
                if (r.ok) {
                    commentEl.innerHTML = `<p style="text-align:center;padding:2rem;color:green;">✓ ${status === 'approved' ? 'Approved' : 'Marked as spam'}!</p>`;
                    setTimeout(() => loadPage(currentPage), 500);
                } else {
                    commentEl.style.opacity = '1';
                    commentEl.innerHTML = originalHTML + `<p class="error" style="margin-top:1rem;">Failed: ${result.error || 'Unknown error'}</p>`;
                }
            } catch (e) {
                commentEl.style.opacity = '1';
                commentEl.innerHTML = originalHTML + '<p class="error" style="margin-top:1rem;">Network error</p>';
            }
        }

        async function deleteComment(id) {
            if (!confirm('Are you sure you want to delete this comment?')) return;
            try {
                await AdminAuth.ensureCsrfToken();
                const r = await fetch(`${API_URL}?action=delete&id=${id}&csrf_token=${encodeURIComponent(AdminAuth.getCsrfToken())}`, {
                    method: 'DELETE', credentials: 'include',
                });
                if (r.ok) { loadPage(currentPage); }
                else { alert(`Failed to delete: ${(await r.json()).error || 'Unknown error'}`); }
            } catch (e) { alert('Network error while deleting comment'); }
        }

        function renderPagination(total) {
            const paginationEl = document.getElementById('pagination');
            if (!paginationEl) return;
            const totalPages = Math.ceil(total / commentsPerPage);
            if (totalPages <= 1) { paginationEl.innerHTML = ''; return; }
            let html = `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>Previous</button>`;
            const maxVisible = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let endPage   = Math.min(totalPages, startPage + maxVisible - 1);
            if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);
            if (startPage > 1) {
                html += `<button onclick="changePage(1)">1</button>`;
                if (startPage > 2) html += `<span class="page-info">...</span>`;
            }
            for (let i = startPage; i <= endPage; i++) {
                html += `<button onclick="changePage(${i})" ${i === currentPage ? 'class="active"' : ''}>${i}</button>`;
            }
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="page-info">...</span>`;
                html += `<button onclick="changePage(${totalPages})">${totalPages}</button>`;
            }
            html += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Next</button>`;
            const startIdx = (currentPage - 1) * commentsPerPage + 1;
            const endIdx   = Math.min(currentPage * commentsPerPage, total);
            html += `<span class="page-info">Showing ${startIdx}–${endIdx} of ${total.toLocaleString()}</span>`;
            paginationEl.innerHTML = html;
        }

        function changePage(page) {
            const totalPages = Math.ceil(currentTotal / commentsPerPage);
            if (page < 1 || page > totalPages) return;
            loadPage(page);
            document.querySelector('.comments-section')?.scrollIntoView({ behavior: 'smooth' });
        }

        hoistToWindow({ applyFilters, applyStatusFilter, clearFilters, moderateComment, deleteComment, changePage, startCommentEdit });
        loadDashboard();
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// POSTS SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['posts'] = {
    title: 'Posts',
    css: `
        .stat-card.clickable { cursor:pointer; transition:transform .1s,box-shadow .1s; }
        .stat-card.clickable:hover { transform:translateY(-2px); box-shadow:0 4px 8px rgba(0,0,0,.15); }
        .controls { background:var(--on-background); padding:1.25rem 1.5rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); margin-bottom:1.5rem; display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; }
        .control-group { display:flex; flex-direction:column; gap:.35rem; flex:1; min-width:180px; }
        .control-group label { font-size:.85rem; color:#555; margin-bottom:0; }
        .control-group input,.control-group select { padding:.6rem .75rem; font-size:.95rem; }
        .search-wrapper { position:relative; flex:2; min-width:250px; }
        .posts-section { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
        .posts-section-header { padding:1.25rem 1.5rem; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; }
        .posts-section-header h2 { margin:0; font-size:1.1rem; }
        .result-count { color:#666; font-size:.9rem; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        thead th { background:var(--light); padding:.75rem 1rem; text-align:left; font-weight:600; color:var(--body-text); border-bottom:2px solid #e9ecef; white-space:nowrap; }
        thead th.sortable { cursor:pointer; user-select:none; }
        thead th.sortable:hover { background:var(--lightgray); color:#4a90e2; }
        thead th.sort-active { color:#4a90e2; }
        thead th .sort-arrow { display:inline-block; margin-left:.3rem; opacity:.4; font-size:.75rem; }
        thead th.sort-active .sort-arrow { opacity:1; }
        tbody tr { border-bottom:1px solid #f0f0f0; transition:background .1s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:var(--light); }
        tbody tr.spam-magnet { background:#fff8f8; }
        tbody tr.spam-magnet:hover { background:#fff0f0; }
        td { color:var(--body-text); padding:.85rem 1rem; vertical-align:middle; }
        .url-cell { max-width:320px; }
        .url-text { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#333; font-size:.88rem; }
        .url-text a { color:#4a90e2; text-decoration:none; }
        .url-text a:hover { text-decoration:underline; }
        .count-breakdown { display:flex; gap:.3rem; flex-wrap:wrap; align-items:center; }
        .badge-deleted { background:#e2e3e5; color:#383d41; }
        .spam-pct { display:inline-flex; align-items:center; gap:.3rem; font-weight:600; font-size:.88rem; }
        .spam-pct.low { color:#28a745; } .spam-pct.medium { color:#e6a817; } .spam-pct.high { color:#dc3545; }
        .spam-bar-wrap { width:52px; height:5px; background:#eee; border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle; }
        .spam-bar { height:100%; border-radius:3px; }
        .spam-bar.low { background:#28a745; } .spam-bar.medium { background:#ffc107; } .spam-bar.high { background:#dc3545; }
        .pagination { padding:1.25rem; border-top:1px solid #eee; margin-top:0; }
        .pagination button { color:#555; }
        .pagination button:hover:not(:disabled) { border-color:#4a90e2; color:#4a90e2; background:white; }
        .pagination button.active { background:#4a90e2; border-color:#4a90e2; color:white; }
        .pagination button:disabled { opacity:.4; cursor:default; }
        @media (max-width:900px) {
            header { flex-direction:column; align-items:flex-start; gap:1rem; padding:1rem; }
            .nav { width:100%; flex-wrap:wrap; } .nav a { flex:1; min-width:90px; text-align:center; font-size:.9rem; }
            .stats { grid-template-columns:1fr 1fr; } .controls { flex-direction:column; }
            .control-group { width:100%; min-width:unset; } .search-wrapper { min-width:unset; }
            table { font-size:.82rem; } td,thead th { padding:.65rem .6rem; }
            .url-cell { max-width:180px; } .pagination button { padding:.4rem .75rem; font-size:.85rem; }
        }`,

    html: () => `
        <div class="container">
            <div class="stats" id="stats">
                <div class="stat-card"><div class="stat-number" id="stat-posts">—</div><div class="stat-label">Total Posts</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-comments">—</div><div class="stat-label">Total Comments</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-avg">—</div><div class="stat-label">Avg Comments / Post</div></div>
                <div class="stat-card clickable" onclick="sortBy('spam_pct')">
                    <div class="stat-number warning" id="stat-spam">—</div><div class="stat-label">Total Spam</div>
                </div>
                <div class="stat-card clickable" onclick="filterSpamMagnets()">
                    <div class="stat-number danger" id="stat-magnets">—</div><div class="stat-label">Spam Magnets (&gt;50%)</div>
                </div>
                <div class="stat-card clickable" onclick="sortBy('pending_count')">
                    <div class="stat-number warning" id="stat-pending">—</div><div class="stat-label">Total Pending</div>
                </div>
            </div>
            <div class="controls">
                <div class="search-wrapper control-group">
                    <label for="search-input">Search posts</label>
                    <input type="search" id="search-input" placeholder="Filter by URL…" oninput="onSearchInput()">
                </div>
                <div class="control-group" style="max-width:220px;">
                    <label for="sort-select">Sort by</label>
                    <select id="sort-select" onchange="applySortAndRender()">
                        <option value="last_comment_at_desc">Most Recent Comment</option>
                        <option value="total_comments_desc">Most Comments</option>
                        <option value="approved_count_desc">Most Approved</option>
                        <option value="pending_count_desc">Most Pending</option>
                        <option value="spam_count_desc">Most Spam (count)</option>
                        <option value="spam_pct_desc">Highest Spam %</option>
                        <option value="unique_authors_desc">Most Unique Authors</option>
                        <option value="unique_ips_desc">Most Unique IPs</option>
                        <option value="first_comment_at_asc">Oldest Post First</option>
                        <option value="last_comment_at_asc">Least Recent Comment</option>
                    </select>
                </div>
                <div class="control-group" style="max-width:180px;">
                    <label for="filter-spam">Spam filter</label>
                    <select id="filter-spam" onchange="applySortAndRender()">
                        <option value="all">All posts</option>
                        <option value="magnets">Spam magnets (&gt;50%)</option>
                        <option value="clean">Clean (&lt;10%)</option>
                        <option value="has_pending">Has pending</option>
                        <option value="has_spam">Has any spam</option>
                    </select>
                </div>
                <div class="control-group" style="max-width:80px;flex:0;">
                    <label>&nbsp;</label>
                    <button class="btn btn-warning" onclick="clearControls()" style="padding:.6rem 1rem;font-size:.9rem;white-space:nowrap;">Clear</button>
                </div>
            </div>
            <div class="posts-section">
                <div class="posts-section-header">
                    <h2>Posts with Comments</h2>
                    <span class="result-count" id="result-count"></span>
                </div>
                <div id="posts-table-wrap"><div class="loading">Loading…</div></div>
                <div class="pagination" id="pagination" style="display:none;"></div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        let allPosts      = [];
        let filteredPosts = [];
        let currentPage   = 1;
        const PAGE_SIZE   = 25;
        let searchTimer   = null;

        async function loadPosts() {
            document.getElementById('posts-table-wrap').innerHTML = '<div class="loading">Loading…</div>';
            try {
                const r = await fetch(`${API_URL}?action=posts_summary&_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
                const data = await r.json();
                if (!r.ok) throw new Error(data.error || 'Failed to load');
                allPosts = data.posts || [];
                updateSummaryStats(data.summary || {});
                applySortAndRender();
            } catch (err) {
                document.getElementById('posts-table-wrap').innerHTML =
                    `<div class="message error" style="margin:1rem;">Error: ${escapeHtml(err.message)}</div>`;
            }
        }

        function updateSummaryStats(summary) {
            const posts    = summary.total_posts    ?? allPosts.length;
            const comments = summary.total_comments ?? 0;
            document.getElementById('stat-posts').textContent    = fmt(posts);
            document.getElementById('stat-comments').textContent = fmt(comments);
            document.getElementById('stat-spam').textContent     = fmt(summary.total_spam    ?? 0);
            document.getElementById('stat-pending').textContent  = fmt(summary.total_pending ?? 0);
            document.getElementById('stat-avg').textContent      = posts > 0 ? (comments / posts).toFixed(1) : '0';
            document.getElementById('stat-magnets').textContent  = fmt(allPosts.filter(p => spamPct(p) > 50).length);
        }

        function onSearchInput() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applySortAndRender, 200);
        }

        function applySortAndRender() {
            const query      = document.getElementById('search-input').value.trim().toLowerCase();
            const sortKey    = document.getElementById('sort-select').value;
            const spamFilter = document.getElementById('filter-spam').value;
            filteredPosts = allPosts.filter(p => {
                if (query && !p.page_url.toLowerCase().includes(query)) return false;
                if (spamFilter === 'magnets'     && spamPct(p) <= 50) return false;
                if (spamFilter === 'clean'       && spamPct(p) >= 10) return false;
                if (spamFilter === 'has_pending' && p.pending_count === 0) return false;
                if (spamFilter === 'has_spam'    && p.spam_count === 0) return false;
                return true;
            });
            const [field, dir] = sortKey.split(/_(?=[^_]+$)/);
            filteredPosts.sort((a, b) => {
                if (field === 'spam_pct') return dir === 'asc' ? spamPct(a) - spamPct(b) : spamPct(b) - spamPct(a);
                if (field === 'last_comment_at' || field === 'first_comment_at') {
                    const av = a[field] || '', bv = b[field] || '';
                    return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
                }
                const av = a[field] ?? 0, bv = b[field] ?? 0;
                return dir === 'asc' ? av - bv : bv - av;
            });
            currentPage = 1;
            document.getElementById('result-count').textContent =
                `${fmt(filteredPosts.length)} post${filteredPosts.length !== 1 ? 's' : ''}`;
            renderTable();
            renderPagination();
        }

        function sortBy(key) { document.getElementById('sort-select').value = key + '_desc'; applySortAndRender(); }
        function filterSpamMagnets() { document.getElementById('filter-spam').value = 'magnets'; applySortAndRender(); }
        function clearControls() {
            document.getElementById('search-input').value = '';
            document.getElementById('sort-select').value  = 'last_comment_at_desc';
            document.getElementById('filter-spam').value  = 'all';
            applySortAndRender();
        }

        function renderTable() {
            const wrap = document.getElementById('posts-table-wrap');
            if (filteredPosts.length === 0) { wrap.innerHTML = '<div class="empty-state">No posts match your filters.</div>'; return; }
            const start = (currentPage - 1) * PAGE_SIZE;
            const page  = filteredPosts.slice(start, start + PAGE_SIZE);
            const rows = page.map(p => {
                const pct      = spamPct(p);
                const pctCls   = pct >= 50 ? 'high' : pct >= 20 ? 'medium' : 'low';
                const isMagnet = pct > 50;
                const pageHref = p.page_url_href || p.page_url;
                const urlShort = pageHref.replace(/^https?:\/\//, '');
                return `<tr${isMagnet ? ' class="spam-magnet"' : ''}>
                    <td class="url-cell"><span class="url-text" title="${escapeHtml(pageHref)}"><a href="${escapeHtml(pageHref)}" target="_blank" rel="noopener">${escapeHtml(urlShort)}</a></span></td>
                    <td><div class="count-breakdown">
                        <span class="badge badge-total">${p.total_comments}</span>
                        ${p.approved_count > 0 ? `<span class="badge badge-approved">${p.approved_count} ok</span>` : ''}
                        ${p.pending_count  > 0 ? `<span class="badge badge-pending">${p.pending_count} pend</span>` : ''}
                        ${p.spam_count     > 0 ? `<span class="badge badge-spam">${p.spam_count} spam</span>` : ''}
                        ${p.deleted_count  > 0 ? `<span class="badge badge-deleted">${p.deleted_count} del</span>` : ''}
                    </div></td>
                    <td><span class="spam-pct ${pctCls}"><span class="spam-bar-wrap"><span class="spam-bar ${pctCls}" style="width:${Math.min(pct,100)}%"></span></span>${pct}%</span></td>
                    <td class="num-cell">${p.unique_authors}</td>
                    <td class="num-cell">${p.unique_ips}</td>
                    <td class="num-cell">${p.avg_length > 0 ? p.avg_length : '—'}</td>
                    <td class="num-cell">${p.total_reactions > 0 ? p.total_reactions : '—'}</td>
                    <td class="date-cell">${formatDateRelative(p.last_comment_at)}</td>
                    <td class="date-cell">${formatDateRelative(p.first_comment_at)}</td>
                    <td class="actions-cell"><a href="#all" onclick="navigateToComments('${escapeHtml(p.page_url)}')" class="btn btn-primary btn-sm">Comments</a></td>
                </tr>`;
            }).join('');
            wrap.innerHTML = `<table><thead><tr>
                <th class="sortable" onclick="cycleSortCol('last_comment_at')">Post URL <span class="sort-arrow">↕</span></th>
                <th>Counts</th>
                <th class="sortable" onclick="cycleSortCol('spam_pct')">Spam % <span class="sort-arrow">↕</span></th>
                <th class="sortable num-cell" onclick="cycleSortCol('unique_authors')">Authors <span class="sort-arrow">↕</span></th>
                <th class="sortable num-cell" onclick="cycleSortCol('unique_ips')">IPs <span class="sort-arrow">↕</span></th>
                <th class="num-cell" title="Average comment length">Avg Len</th>
                <th class="num-cell" title="Post-level reactions">React.</th>
                <th class="sortable date-cell" onclick="cycleSortCol('last_comment_at')">Last Comment <span class="sort-arrow">↕</span></th>
                <th class="sortable date-cell" onclick="cycleSortCol('first_comment_at')">First Comment <span class="sort-arrow">↕</span></th>
                <th></th>
            </tr></thead><tbody>${rows}</tbody></table>`;
            const sortKey = document.getElementById('sort-select').value;
            const [activeField] = sortKey.split(/_(?=[^_]+$)/);
            wrap.querySelectorAll('thead th.sortable').forEach(th => {
                const col = th.getAttribute('onclick').match(/'([^']+)'/)?.[1];
                if (col === activeField) {
                    th.classList.add('sort-active');
                    const arrow = th.querySelector('.sort-arrow');
                    if (arrow) arrow.textContent = sortKey.endsWith('_asc') ? '↑' : '↓';
                }
            });
        }

        function navigateToComments(pageUrl) {
            // Set a flag read by the 'all' view on init to pre-fill the filter
            sessionStorage.setItem('all_filter_url', pageUrl);
        }

        function cycleSortCol(field) {
            const select = document.getElementById('sort-select');
            const [curField, curDir] = select.value.split(/_(?=[^_]+$)/);
            select.value = field + (curField === field && curDir === 'desc' ? '_asc' : '_desc');
            applySortAndRender();
        }

        function renderPagination() {
            const total = filteredPosts.length;
            const pages = Math.ceil(total / PAGE_SIZE);
            const pgEl  = document.getElementById('pagination');
            if (!pgEl) return;
            if (pages <= 1) { pgEl.style.display = 'none'; return; }
            pgEl.style.display = 'flex';
            const btns = [];
            btns.push(`<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>‹ Prev</button>`);
            const range = pageRange(currentPage, pages);
            let prev = null;
            for (const p of range) {
                if (prev !== null && p - prev > 1) btns.push('<span class="page-info">…</span>');
                btns.push(`<button onclick="changePage(${p})" class="${p === currentPage ? 'active' : ''}">${p}</button>`);
                prev = p;
            }
            btns.push(`<button onclick="changePage(${currentPage + 1})" ${currentPage === pages ? 'disabled' : ''}>Next ›</button>`);
            btns.push(`<span class="page-info">${(currentPage - 1) * PAGE_SIZE + 1}–${Math.min(currentPage * PAGE_SIZE, total)} of ${fmt(total)}</span>`);
            pgEl.innerHTML = btns.join('');
        }

        function changePage(n) {
            const pages = Math.ceil(filteredPosts.length / PAGE_SIZE);
            if (n < 1 || n > pages) return;
            currentPage = n; renderTable(); renderPagination();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function pageRange(cur, total) {
            const delta = 2, pages = [];
            for (let i = 1; i <= total; i++) {
                if (i === 1 || i === total || (i >= cur - delta && i <= cur + delta)) pages.push(i);
            }
            return pages;
        }

        function spamPct(p) { return p.total_comments ? Math.round((p.spam_count / p.total_comments) * 100) : 0; }
        function fmt(n) { return Number(n).toLocaleString(); }
        function formatDateRelative(dt) {
            if (!dt) return '—';
            const d = new Date(dt.replace(' ', 'T') + 'Z'), diff = (Date.now() - d) / 1000;
            if (diff < 60)     return 'just now';
            if (diff < 3600)   return Math.floor(diff / 60)    + 'm ago';
            if (diff < 86400)  return Math.floor(diff / 3600)  + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        }

        hoistToWindow({ applySortAndRender, sortBy, filterSpamMagnets, clearControls, cycleSortCol, changePage, onSearchInput, navigateToComments });
        loadPosts();
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// ANALYTICS
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['analytics'] = {
    title: 'Analytics',
    css: `
        .dashboard { display:flex; flex-direction:column; gap:1.5rem; margin-bottom:2rem; }
        .row-3-1 { display:grid; grid-template-columns:1fr 220px; gap:1.5rem; }
        .row-2col { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .chart-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); padding:1.25rem 1.5rem; }
        .chart-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.5rem; }
        .chart-title { font-size:.92rem; font-weight:600; color:#555; }
        .chart-subtitle { font-size:.75rem; font-weight:400; color:#aaa; margin-left:.4rem; }
        .toggle-group { display:flex; gap:.2rem; }
        .toggle-group button { padding:.22rem .7rem; border:1px solid #ddd; background:white; border-radius:3px; font-size:.78rem; cursor:pointer; color:#666; transition:all .15s; }
        .toggle-group button.active { background:#4a90e2; border-color:#4a90e2; color:white; }
        .toggle-group button:hover:not(.active) { border-color:#4a90e2; color:#4a90e2; }
        .chart-legend { display:flex; gap:1rem; flex-wrap:wrap; margin-top:.6rem; font-size:.8rem; }
        .legend-item { display:flex; align-items:center; gap:.3rem; color:#666; }
        .legend-swatch { width:10px; height:10px; border-radius:2px; flex-shrink:0; }
        .chart-empty { padding:2rem; text-align:center; color:#ccc; font-size:.9rem; }
        .chart-loading { padding:2rem; text-align:center; color:#bbb; font-size:.9rem; }
        .donut-wrap { display:flex; flex-direction:column; align-items:center; gap:1rem; }
        .donut-legend { width:100%; display:flex; flex-direction:column; gap:.35rem; font-size:.82rem; }
        .donut-legend-row { display:flex; align-items:center; gap:.4rem; }
        .donut-legend-row .dl-count { margin-left:auto; font-weight:600; color:#333; }
        .donut-legend-row .dl-pct { color:#aaa; font-size:.75rem; min-width:32px; text-align:right; }
        #chart-tooltip { position:fixed; background:rgba(25,25,25,.92); color:#fff; padding:.45rem .7rem; border-radius:5px; font-size:.8rem; pointer-events:none; z-index:9999; display:none; line-height:1.7; max-width:220px; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        @media (max-width:1000px) { .row-3-1,.row-2col { grid-template-columns:1fr; } }
        @media (max-width:768px)  { .nav a { min-width:80px; font-size:.85rem; } }`,

    html: () => `
        <div id="chart-tooltip"></div>
        <div class="container">
            <div class="stats">
                <div class="stat-card"><div class="stat-number" id="stat-total">—</div><div class="stat-label">Total Comments</div></div>
                <div class="stat-card"><div class="stat-number green" id="stat-approved">—</div><div class="stat-label">Approved</div></div>
                <div class="stat-card"><div class="stat-number yellow" id="stat-pending">—</div><div class="stat-label">Pending</div></div>
                <div class="stat-card"><div class="stat-number red" id="stat-spam">—</div><div class="stat-label">Spam</div></div>
                <div class="stat-card"><div class="stat-number gray" id="stat-commenters">—</div><div class="stat-label">Unique Commenters</div></div>
                <div class="stat-card"><div class="stat-number gray" id="stat-ips">—</div><div class="stat-label">Unique IPs</div></div>
            </div>
            <div class="dashboard" id="dashboard">
                <div class="chart-card">
                    <div class="chart-header">
                        <span class="chart-title">Comment Volume Over Time</span>
                        <div class="toggle-group">
                            <button id="toggle-daily" class="active" onclick="setGranularity('daily')">Daily</button>
                            <button id="toggle-weekly" onclick="setGranularity('weekly')">Weekly</button>
                            <button id="toggle-monthly" onclick="setGranularity('monthly')">Monthly</button>
                        </div>
                    </div>
                    <div id="timeline-chart"><div class="chart-loading">Loading…</div></div>
                    <div class="chart-legend">
                        <span class="legend-item"><span class="legend-swatch" style="background:#28a745"></span>Approved</span>
                        <span class="legend-item"><span class="legend-swatch" style="background:#ffc107"></span>Pending</span>
                        <span class="legend-item"><span class="legend-swatch" style="background:#dc3545"></span>Spam</span>
                    </div>
                </div>
                <div class="row-3-1">
                    <div class="chart-card">
                        <div class="chart-header"><span class="chart-title">Top Posts by Comment Volume</span></div>
                        <div id="top-posts-chart"><div class="chart-loading">Loading…</div></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header"><span class="chart-title">Status Breakdown</span></div>
                        <div id="donut-chart" class="donut-wrap"><div class="chart-loading">Loading…</div></div>
                    </div>
                </div>
                <div class="row-2col">
                    <div class="chart-card">
                        <div class="chart-header"><span class="chart-title">Activity by Hour<span class="chart-subtitle">(UTC, all time)</span></span></div>
                        <div id="hourly-chart"><div class="chart-loading">Loading…</div></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header"><span class="chart-title">Activity by Day of Week<span class="chart-subtitle">(all time)</span></span></div>
                        <div id="weekday-chart"><div class="chart-loading">Loading…</div></div>
                    </div>
                </div>
            </div>
        </div>`,

    async init({ hoistToWindow }) {
        let analyticsData      = null;
        let currentGranularity = 'daily';

        const r = await fetch(`${API_URL}?action=analytics&_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
        if (r.ok) loadAnalytics(await r.json());

        function loadAnalytics(data) {
            analyticsData = data;
            const st    = data.status_totals;
            const total = (st.approved || 0) + (st.pending || 0) + (st.spam || 0) + (st.deleted || 0);
            document.getElementById('stat-total').textContent      = fmt(total);
            document.getElementById('stat-approved').textContent   = fmt(st.approved || 0);
            document.getElementById('stat-pending').textContent    = fmt(st.pending  || 0);
            document.getElementById('stat-spam').textContent       = fmt(st.spam     || 0);
            document.getElementById('stat-commenters').textContent = fmt(data.unique_commenters || 0);
            document.getElementById('stat-ips').textContent        = fmt(data.unique_ips        || 0);
            renderTimeline();
            renderDonut(st);
            renderTopPosts(data.top_posts  || []);
            renderHourly(data.hourly       || []);
            renderWeekday(data.weekdays    || []);
        }

        function setGranularity(g) {
            currentGranularity = g;
            ['daily','weekly','monthly'].forEach(k =>
                document.getElementById('toggle-' + k)?.classList.toggle('active', k === g));
            renderTimeline();
        }

        function renderTimeline() {
            if (!analyticsData) return;
            const buckets = analyticsData.timeline[currentGranularity] || [];
            const el = document.getElementById('timeline-chart');
            if (!el) return;
            if (!buckets.length) { el.innerHTML = '<div class="chart-empty">No data for this period</div>'; return; }
            const W=900,H=210,PL=42,PR=12,PT=14,PB=34,cW=W-PL-PR,cH=H-PT-PB,n=buckets.length;
            const maxRaw=Math.max(...buckets.map(b=>b.total),1);
            const ticks=niceTicks(maxRaw,4),maxVal=ticks[ticks.length-1];
            let yLines='';
            for(const t of ticks){const y=(PT+cH-(t/maxVal)*cH).toFixed(1);yLines+=`<line x1="${PL}" x2="${W-PR}" y1="${y}" y2="${y}" stroke="#f0f0f0" stroke-width="1"/><text x="${PL-5}" y="${+y+4}" text-anchor="end" font-size="10" fill="#c0c0c0">${t>=1000?(t/1000).toFixed(t%1000===0?0:1)+'k':t}</text>`;}
            const slotW=cW/n,barW=Math.max(1.5,Math.min(slotW*.8,48)),barOff=(slotW-barW)/2;
            const labelEvery=Math.max(1,Math.round(n/9));
            let bars='',xLabels='';
            buckets.forEach((b,i)=>{
                const bx=(PL+i*slotW+barOff).toFixed(2);let y=PT+cH;
                const seg=(count,color)=>{const bh=count>0?Math.max(1.2,(count/maxVal)*cH):0;if(bh<.5)return'';y-=bh;return`<rect x="${bx}" y="${y.toFixed(2)}" width="${(+barW).toFixed(2)}" height="${bh.toFixed(2)}" fill="${color}"/>`;};
                const other=Math.max(0,b.total-b.approved-b.pending-b.spam);
                bars+=`<g>${seg(other,'#adb5bd')}${seg(b.spam,'#dc3545')}${seg(b.pending,'#ffc107')}${seg(b.approved,'#28a745')}</g>`;
                bars+=`<rect class="tt-bar" x="${(PL+i*slotW).toFixed(2)}" y="${PT}" width="${slotW.toFixed(2)}" height="${cH}" fill="rgba(0,0,0,0)" pointer-events="all" data-i="${i}"/>`;
                if(i%labelEvery===0||i===n-1){xLabels+=`<text x="${(PL+i*slotW+slotW/2).toFixed(1)}" y="${H-4}" text-anchor="middle" font-size="9.5" fill="#c0c0c0">${fmtPeriod(b.period,currentGranularity)}</text>`;}
            });
            const axes=`<line x1="${PL}" x2="${PL}" y1="${PT}" y2="${PT+cH}" stroke="#e8e8e8"/><line x1="${PL}" x2="${W-PR}" y1="${PT+cH}" y2="${PT+cH}" stroke="#e8e8e8"/>`;
            el.innerHTML=`<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;overflow:visible">${yLines}${axes}${bars}${xLabels}</svg>`;
            const ttEl=document.getElementById('chart-tooltip');
            el.querySelectorAll('.tt-bar').forEach(r=>{
                r.addEventListener('mouseenter',e=>{const b=buckets[+r.dataset.i];const pct=b.total>0?Math.round(b.spam/b.total*100):0;showTip(ttEl,e,`<strong>${b.period}</strong><br>Total: <strong>${b.total}</strong><br>✅ ${b.approved}&ensp;⏳ ${b.pending}&ensp;🚫 ${b.spam} (${pct}%)`);});
                r.addEventListener('mousemove',e=>moveTip(ttEl,e));r.addEventListener('mouseleave',()=>hideTip(ttEl));
            });
        }

        function renderDonut(st) {
            const el=document.getElementById('donut-chart');if(!el)return;
            const segs=[{key:'approved',label:'Approved',color:'#28a745'},{key:'pending',label:'Pending',color:'#ffc107'},{key:'spam',label:'Spam',color:'#dc3545'},{key:'deleted',label:'Deleted',color:'#adb5bd'}].filter(s=>(st[s.key]||0)>0);
            const total=segs.reduce((a,s)=>a+(st[s.key]||0),0);
            if(!total){el.innerHTML='<div class="chart-empty">No data</div>';return;}
            const cx=90,cy=90,R=68,ri=40;let paths='',start=-Math.PI/2;
            for(const s of segs){const frac=(st[s.key]||0)/total,sweep=frac*2*Math.PI;if(sweep<.001)continue;const end=start+sweep,cos1=Math.cos(start),sin1=Math.sin(start),cos2=Math.cos(end),sin2=Math.sin(end),large=sweep>Math.PI?1:0;const d=`M${(cx+R*cos1).toFixed(2)},${(cy+R*sin1).toFixed(2)} A${R},${R} 0 ${large},1 ${(cx+R*cos2).toFixed(2)},${(cy+R*sin2).toFixed(2)} L${(cx+ri*cos2).toFixed(2)},${(cy+ri*sin2).toFixed(2)} A${ri},${ri} 0 ${large},0 ${(cx+ri*cos1).toFixed(2)},${(cy+ri*sin1).toFixed(2)} Z`;paths+=`<path d="${d}" fill="${s.color}" class="donut-arc" data-label="${s.label}" data-count="${st[s.key]}" data-pct="${Math.round(frac*100)}" style="cursor:default"/>`;start=end;}
            const legend=segs.map(s=>{const count=st[s.key]||0,pct=Math.round(count/total*100);return`<div class="donut-legend-row"><span class="legend-swatch" style="background:${s.color}"></span><span style="color:#555">${s.label}</span><span class="dl-count">${fmt(count)}</span><span class="dl-pct">${pct}%</span></div>`;}).join('');
            el.innerHTML=`<svg viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:180px;display:block">${paths}<text x="${cx}" y="${cy-6}" text-anchor="middle" font-size="22" font-weight="700" fill="#333">${fmt(total)}</text><text x="${cx}" y="${cy+14}" text-anchor="middle" font-size="10" fill="#bbb">total</text></svg><div class="donut-legend">${legend}</div>`;
            const ttEl=document.getElementById('chart-tooltip');
            el.querySelectorAll('.donut-arc').forEach(p=>{p.addEventListener('mouseenter',e=>showTip(ttEl,e,`<strong>${p.dataset.label}</strong><br>${fmt(+p.dataset.count)} &nbsp;(${p.dataset.pct}%)`));p.addEventListener('mousemove',e=>moveTip(ttEl,e));p.addEventListener('mouseleave',()=>hideTip(ttEl));});
        }

        function renderTopPosts(posts) {
            const el=document.getElementById('top-posts-chart');if(!el)return;
            if(!posts.length){el.innerHTML='<div class="chart-empty">No posts yet</div>';return;}
            const W=700,ROW=30,URL_W=190,BAR_GAP=8,COUNT_W=32,BAR_W=W-URL_W-BAR_GAP-COUNT_W,H=posts.length*ROW+4;
            const maxVal=Math.max(...posts.map(p=>p.total),1);let rows='';
            posts.forEach((p,i)=>{const y=i*ROW,tw=(p.total/maxVal)*BAR_W,aw=p.total>0?(p.approved/p.total)*tw:0,pw=p.total>0?(p.pending/p.total)*tw:0,sw=p.total>0?(p.spam/p.total)*tw:0,ow=Math.max(0,tw-aw-pw-sw);const barH=14,by=y+(ROW-barH)/2,bx0=URL_W+BAR_GAP;let bx=bx0;const addSeg=(w,color)=>{if(w<.5)return;rows+=`<rect x="${bx.toFixed(1)}" y="${by.toFixed(1)}" width="${w.toFixed(1)}" height="${barH}" fill="${color}" rx="1.5"/>`;bx+=w;};addSeg(aw,'#28a745');addSeg(pw,'#ffc107');addSeg(sw,'#dc3545');addSeg(ow,'#adb5bd');rows+=`<text x="${URL_W-4}" y="${(y+ROW/2+4).toFixed(1)}" text-anchor="end" font-size="10.5" fill="#555">${escapeHtml(truncUrl(p.page_url,30))}</text>`;rows+=`<text x="${bx0+tw+5}" y="${(y+ROW/2+4).toFixed(1)}" font-size="10.5" fill="#888">${p.total}</text>`;if(i<posts.length-1)rows+=`<line x1="0" x2="${W}" y1="${y+ROW}" y2="${y+ROW}" stroke="#f5f5f5"/>`;rows+=`<rect x="0" y="${y}" width="${W}" height="${ROW}" fill="rgba(0,0,0,0)" pointer-events="all" class="post-ov" data-i="${i}"/>`;});
            el.innerHTML=`<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block">${rows}</svg>`;
            const ttEl=document.getElementById('chart-tooltip');
            el.querySelectorAll('.post-ov').forEach(r=>{r.addEventListener('mouseenter',e=>{const p=posts[+r.dataset.i];const pct=p.total>0?Math.round(p.spam/p.total*100):0;showTip(ttEl,e,`<strong>${escapeHtml(p.page_url)}</strong><br>Total: <strong>${p.total}</strong><br>✅ ${p.approved}&ensp;⏳ ${p.pending}&ensp;🚫 ${p.spam} (${pct}%)`);});r.addEventListener('mousemove',e=>moveTip(ttEl,e));r.addEventListener('mouseleave',()=>hideTip(ttEl));});
        }

        function renderHourly(values) { const labels=Array.from({length:24},(_,h)=>h===0?'12am':h===12?'12pm':h<12?h+'am':(h-12)+'pm'); renderSimpleBar('hourly-chart',values,labels,'#4a90e2',3); }
        function renderWeekday(values) { renderSimpleBar('weekday-chart',values,['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],'#7c3aed',1); }

        function renderSimpleBar(containerId,values,labels,color,labelEvery) {
            const el=document.getElementById(containerId);if(!el)return;
            const maxRaw=Math.max(...values,1),ticks=niceTicks(maxRaw,3),maxVal=ticks[ticks.length-1];
            const n=values.length,W=600,H=140,PL=35,PR=8,PT=10,PB=26,cW=W-PL-PR,cH=H-PT-PB;
            let yLines='';for(const t of ticks){const y=(PT+cH-(t/maxVal)*cH).toFixed(1);yLines+=`<line x1="${PL}" x2="${W-PR}" y1="${y}" y2="${y}" stroke="#f0f0f0"/>`;if(t>0)yLines+=`<text x="${PL-4}" y="${+y+4}" text-anchor="end" font-size="9.5" fill="#c0c0c0">${t>=1000?(t/1000).toFixed(1)+'k':t}</text>`;}
            const slotW=cW/n,barW=Math.max(2,Math.min(slotW*.72,40)),barOff=(slotW-barW)/2;
            let bars='',xLabels='';
            values.forEach((v,i)=>{const bx=(PL+i*slotW+barOff).toFixed(2),bh=v>0?Math.max(1.5,(v/maxVal)*cH):0,by=(PT+cH-bh).toFixed(2);bars+=`<rect x="${bx}" y="${by}" width="${barW.toFixed(2)}" height="${bh.toFixed(2)}" fill="${color}" rx="1" opacity="0.85"/>`;bars+=`<rect class="sb-ov" x="${(PL+i*slotW).toFixed(2)}" y="${PT}" width="${slotW.toFixed(2)}" height="${cH}" fill="rgba(0,0,0,0)" pointer-events="all" data-i="${i}"/>`;if(i%labelEvery===0){xLabels+=`<text x="${(PL+i*slotW+slotW/2).toFixed(1)}" y="${H-4}" text-anchor="middle" font-size="9.5" fill="#c0c0c0">${labels[i]}</text>`;}});
            const axes=`<line x1="${PL}" x2="${PL}" y1="${PT}" y2="${PT+cH}" stroke="#e8e8e8"/><line x1="${PL}" x2="${W-PR}" y1="${PT+cH}" y2="${PT+cH}" stroke="#e8e8e8"/>`;
            el.innerHTML=`<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block">${yLines}${axes}${bars}${xLabels}</svg>`;
            const ttEl=document.getElementById('chart-tooltip');
            el.querySelectorAll('.sb-ov').forEach(r=>{r.addEventListener('mouseenter',e=>{const i=+r.dataset.i;showTip(ttEl,e,`<strong>${labels[i]}</strong><br>${fmt(values[i])} comment${values[i]!==1?'s':''}`);});r.addEventListener('mousemove',e=>moveTip(ttEl,e));r.addEventListener('mouseleave',()=>hideTip(ttEl));});
        }

        function showTip(ttEl,e,html){if(!ttEl)return;ttEl.innerHTML=html;ttEl.style.display='block';moveTip(ttEl,e);}
        function moveTip(ttEl,e){if(!ttEl)return;const margin=14;let x=e.clientX+margin,y=e.clientY-margin;const tw=ttEl.offsetWidth,th=ttEl.offsetHeight;if(x+tw>window.innerWidth-8)x=e.clientX-tw-margin;if(y+th>window.innerHeight-8)y=e.clientY-th-margin;if(y<4)y=4;ttEl.style.left=x+'px';ttEl.style.top=y+'px';}
        function hideTip(ttEl){if(ttEl)ttEl.style.display='none';}
        function niceTicks(maxVal,count){if(!maxVal)return[0,1];const rough=maxVal/count,mag=Math.pow(10,Math.floor(Math.log10(rough)));const nice=[1,2,2.5,5,10].map(f=>f*mag).find(f=>f>=rough)||mag*10;const ticks=[];for(let v=0;v<=maxVal*1.05;v+=nice){ticks.push(Math.round(v));if(ticks.length>8)break;}if(!ticks.includes(0))ticks.unshift(0);return ticks;}
        function fmtPeriod(period,gran){const M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];if(gran==='daily'){const[y,m,d]=period.split('-');return M[+m-1]+' '+ +d;}if(gran==='weekly')return period.replace(/^\d{4}-W0?/,'W');if(gran==='monthly'){const[y,m]=period.split('-');return M[+m-1]+' \''+y.slice(2);}return period;}
        function truncUrl(url,max){const s=url.replace(/^https?:\/\//,'');return s.length>max?'…'+s.slice(-(max-1)):s;}
        function fmt(n){return Number(n).toLocaleString();}

        hoistToWindow({ setGranularity });
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// SUBSCRIPTIONS
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['subscriptions'] = {
    title: 'Subscriptions',
    css: `
        .section-card h2 { color:#4a90e2; }
        .subscription-item { border-bottom:1px solid #e0e0e0; padding:1rem 0; display:flex; justify-content:space-between; align-items:center; }
        .subscription-item:last-child { border-bottom:none; }
        .subscription-info { flex:1; }
        .subscription-email  { font-weight:600; color:var(--body-text); }
        .subscription-page   { color:var(--darkgray); font-size:.9rem; }
        .subscription-date   { color:var(--darkgray); font-size:.85rem; }
        .subscription-actions { display:flex; gap:.5rem; }
        @media (max-width:768px) {
            .subscription-item { flex-direction:column; align-items:flex-start; gap:1rem; }
            .subscription-actions { width:100%; flex-direction:column; }
            .subscription-actions button { width:100%; }
        }`,

    html: () => `
        <div class="container">
            <div class="stats" id="stats">
                <div class="stat-card"><div class="stat-number" id="stat-total-subs">0</div><div class="stat-label">Total Subscriptions</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-active-subs">0</div><div class="stat-label">Active</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-inactive-subs">0</div><div class="stat-label">Unsubscribed</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-pages">0</div><div class="stat-label">Pages with Subscribers</div></div>
            </div>
            <div class="section-card">
                <h2>All Subscriptions</h2>
                <div id="subscriptions-list"><p class="no-data">Loading...</p></div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        let allSubscriptions = [];

        async function loadSubscriptions() {
            const container = document.getElementById('subscriptions-list');
            if (!container) return;
            try {
                const r = await fetch(`${API_URL}?action=subscriptions&limit=10000&_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
                const data = await r.json();
                if (r.ok) {
                    allSubscriptions = data.subscriptions || [];
                    displaySubscriptions(allSubscriptions);
                    updateStats(allSubscriptions);
                } else {
                    container.innerHTML = `<div class="message error">Error: ${data.error}</div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="message error">Network error: ${error.message}</div>`;
            }
        }

        function displaySubscriptions(subscriptions) {
            const container = document.getElementById('subscriptions-list');
            if (!container) return;
            if (subscriptions.length === 0) { container.innerHTML = '<p class="no-data">No subscriptions yet</p>'; return; }
            container.innerHTML = subscriptions.map(sub => `
                <div class="subscription-item">
                    <div class="subscription-info">
                        <div class="subscription-email">${escapeHtml(sub.email)}</div>
                        <div class="subscription-page">Page: ${escapeHtml(sub.page_url)}</div>
                        <div class="subscription-date">
                            Subscribed: ${new Date(sub.subscribed_at).toLocaleString()}
                            <span class="badge badge-${sub.active ? 'active' : 'inactive'}">${sub.active ? 'Active' : 'Unsubscribed'}</span>
                        </div>
                    </div>
                    <div class="subscription-actions">
                        ${sub.active
                            ? `<button class="btn btn-warning btn-small" onclick="toggleSubscription('${sub.token}', 0)">Unsubscribe</button>`
                            : `<button class="btn btn-success btn-small" onclick="toggleSubscription('${sub.token}', 1)">Reactivate</button>`}
                        <button class="btn btn-danger btn-small" onclick="deleteSubscription('${sub.token}')">Delete</button>
                    </div>
                </div>`).join('');
        }

        function updateStats(subscriptions) {
            const active   = subscriptions.filter(s =>  s.active).length;
            const inactive = subscriptions.filter(s => !s.active).length;
            const pages    = new Set(subscriptions.map(s => s.page_url)).size;
            document.getElementById('stat-total-subs').textContent    = subscriptions.length;
            document.getElementById('stat-active-subs').textContent   = active;
            document.getElementById('stat-inactive-subs').textContent = inactive;
            document.getElementById('stat-pages').textContent         = pages;
        }

        async function toggleSubscription(token, active) {
            try {
                await AdminAuth.ensureCsrfToken();
                const r = await fetch(`${API_URL}?action=toggle_subscription`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
                    body: JSON.stringify({ token, active, csrf_token: AdminAuth.getCsrfToken() }),
                });
                if (r.ok) { loadSubscriptions(); }
                else { alert(`Failed to update subscription: ${(await r.json()).error || 'Unknown error'}`); }
            } catch (e) { alert('Network error'); }
        }

        async function deleteSubscription(token) {
            if (!confirm('Are you sure you want to permanently delete this subscription?')) return;
            try {
                await AdminAuth.ensureCsrfToken();
                const r = await fetch(`${API_URL}?action=delete_subscription&token=${token}&csrf_token=${encodeURIComponent(AdminAuth.getCsrfToken())}`,
                    { method: 'DELETE', credentials: 'include' });
                if (r.ok) { loadSubscriptions(); }
                else { alert(`Failed to delete: ${(await r.json()).error || 'Unknown error'}`); }
            } catch (e) { alert('Network error'); }
        }

        hoistToWindow({ toggleSubscription, deleteSubscription });
        loadSubscriptions();
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// POST REACTIONS
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['post-reactions'] = {
    title: 'Post Reactions',
    css: `
        .table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        table { width:100%; border-collapse:collapse; }
        #reactions-table table { min-width:800px; }
        th,td { color:var(--body-text); text-align:left; padding:.75rem 1rem; border-bottom:1px solid #e0e0e0; }
        th { font-weight:600; font-size:.9rem; background:var(--light); white-space:nowrap; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:var(--light); }
        .page-url a { color:#4a90e2; text-decoration:none; word-break:break-all; }
        .page-url a:hover { text-decoration:underline; }
        .reaction-cell { text-align:center; white-space:nowrap; }
        .total-cell { font-weight:600; color:#4a90e2; text-align:center; }
        .dropdown-group { display:flex; align-items:center; gap:.5rem; }
        .dropdown-group label { font-size:.9rem; font-weight:500; }
        .dropdown-group select { padding:.5rem .75rem; border:1px solid #ccc; border-radius:4px; background-color:var(--on-background); color:var(--body-text); font-size:.9rem; cursor:pointer; }
        .latest-reactions-table { font-size:.95rem; min-width:650px; }
        .reaction-emoji-cell { font-size:1.2rem; }
        .ip-cell { color:var(--body-text); font-size:.85rem; font-family:monospace; word-break:break-all; }
        @media (max-width:768px) { table { font-size:.85rem; } th,td { padding:.5rem; } }`,

    html: () => `
        <div class="container">
            <div class="stats" id="stats">
                <div class="stat-card"><div class="stat-number" id="stat-heart">0</div><div class="stat-label">❤️ Love it</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-thumbsup">0</div><div class="stat-label">👍 Good point</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-lightbulb">0</div><div class="stat-label">👎 Dislike</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-pray">0</div><div class="stat-label">🙏 Pray</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-ok">0</div><div class="stat-label">👌 Ok</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-fire">0</div><div class="stat-label">🔥 Fire</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-frown">0</div><div class="stat-label">☹️ Frown</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-rage">0</div><div class="stat-label">😡 Rage</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-funny">0</div><div class="stat-label">😄 Funny</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-neutral">0</div><div class="stat-label">😐 Neutral</div></div>
                <div class="stat-card"><div class="stat-number" id="stat-total-all">0</div><div class="stat-label">Total Reactions</div></div>
            </div>
            <div class="section-card">
                <h2>Reactions by Page</h2>
                <div id="reactions-message"></div>
                <div id="reactions-table" class="table-responsive"><p class="no-data">Loading...</p></div>
            </div>
            <div class="section-card">
                <div class="section-header">
                    <h2>Latest Reactions</h2>
                    <div class="dropdown-group">
                        <label for="latest-limit">Show:</label>
                        <select id="latest-limit" onchange="loadLatestReactions()">
                            <option value="10">Last 10</option>
                            <option value="25">Last 25</option>
                            <option value="50">Last 50</option>
                            <option value="100">Last 100</option>
                        </select>
                    </div>
                </div>
                <div id="latest-message"></div>
                <div id="latest-reactions-container" class="table-responsive"><p class="no-data">Loading...</p></div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        const EMOJI_BY_TYPE = { thumbsup:'👍', lightbulb:'👎', pray:'🙏', ok:'👌', fire:'🔥', heart:'❤️', frown:'☹️', rage:'😡', funny:'😄', neutral:'😐' };
        const REACTION_TYPES = ['thumbsup','lightbulb','pray','ok','fire','heart','frown','rage','funny','neutral'];

        async function loadReactions() {
            const container = document.getElementById('reactions-table');
            if (!container) return;
            try {
                const r = await fetch(`${API_URL}?action=post_reactions_summary&_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
                const data = await r.json();
                if (r.ok) { updateStats(data); displayReactions(data.pages || []); }
                else { container.innerHTML = `<div class="message error">${data.error || 'Failed to load'}</div>`; }
            } catch (e) { container.innerHTML = `<div class="message error">Network error: ${e.message}</div>`; }
        }

        function updateStats(data) {
            const pages  = data.pages || [];
            const totals = Object.fromEntries(REACTION_TYPES.map(t => [t, 0]));
            pages.forEach(page => {
                const reactions = page.reactions || {};
                REACTION_TYPES.forEach(t => { totals[t] += (parseInt(reactions[t]) || parseInt(page[t]) || 0); });
            });
            REACTION_TYPES.forEach(t => { const el = document.getElementById(`stat-${t}`); if (el) el.textContent = totals[t]; });
            document.getElementById('stat-total-all').textContent = Object.values(totals).reduce((s, v) => s + v, 0);
        }

        function displayReactions(pages) {
            const container = document.getElementById('reactions-table');
            if (!container) return;
            if (!pages.length) { container.innerHTML = '<p class="no-data">No post reactions yet.</p>'; return; }
            const allTypesSet = new Set();
            pages.forEach(p => { Object.keys(p.reactions || {}).forEach(t => allTypesSet.add(t)); ['heart','thumbsup','lightbulb','funny'].forEach(k => { if (p[k] !== undefined) allTypesSet.add(k); }); });
            const preferred = ['heart','thumbsup','lightbulb','funny'];
            const remaining = [...allTypesSet].filter(t => !preferred.includes(t)).sort();
            const columnOrder = [...preferred.filter(t => allTypesSet.has(t)), ...remaining];
            const thead = '<tr><th>Page</th>' + columnOrder.map(t => `<th class="reaction-cell">${EMOJI_BY_TYPE[t] || t}</th>`).join('') + '<th class="reaction-cell">Total</th><th class="actions-cell">Actions</th></tr>';
            const rows  = pages.map(p => {
                const reactions = p.reactions || {};
                const cells = columnOrder.map(t => { const count = (parseInt(reactions[t]) || 0) || (parseInt(p[t]) || 0); return `<td class="reaction-cell">${count}</td>`; }).join('');
                const displayUrl = p.page_url_href || p.page_url;
                const safeUrl = escapeHtml(displayUrl);
                const total   = p.total || Object.values(reactions).reduce((s, v) => s + (parseInt(v) || 0), 0) || 0;
                const pageUrlEscaped = (p.page_url || '').replace(/'/g, "\\'");
                return `<tr><td class="page-url"><a href="${safeUrl}" target="_blank">${safeUrl}</a></td>${cells}<td class="total-cell">${total}</td><td class="actions-cell"><button class="btn btn-danger btn-sm" onclick="clearReactions('${pageUrlEscaped}')">Clear</button></td></tr>`;
            }).join('');
            container.innerHTML = `<table><thead>${thead}</thead><tbody>${rows}</tbody></table>`;
        }

        async function clearReactions(pageUrl) {
            if (!confirm(`Clear all post reactions for:\n${pageUrl}`)) return;
            await AdminAuth.ensureCsrfToken();
            const msgEl = document.getElementById('reactions-message');
            try {
                const r = await fetch(`${API_URL}?action=delete_post_reactions&url=${encodeURIComponent(pageUrl)}&csrf_token=${encodeURIComponent(AdminAuth.getCsrfToken())}`, { method: 'DELETE', credentials: 'include' });
                const result = await r.json();
                if (r.ok) { msgEl.innerHTML = '<div class="message success">Reactions cleared.</div>'; setTimeout(() => { if (msgEl) msgEl.innerHTML = ''; }, 3000); loadReactions(); }
                else { msgEl.innerHTML = `<div class="message error">${result.error || 'Failed to clear'}</div>`; }
            } catch (e) { msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        async function clearReaction(reactionId, pageUrl, reactionType) {
            if (!confirm(`Delete this ${reactionType} reaction?`)) return;
            await AdminAuth.ensureCsrfToken();
            const msgEl = document.getElementById('latest-message');
            try {
                const r = await fetch(`${API_URL}?action=delete_single_reaction&id=${encodeURIComponent(reactionId)}&csrf_token=${encodeURIComponent(AdminAuth.getCsrfToken())}`, { method: 'DELETE', credentials: 'include' });
                const result = await r.json();
                if (r.ok) { msgEl.innerHTML = '<div class="message success">Reaction deleted.</div>'; setTimeout(() => { if (msgEl) msgEl.innerHTML = ''; }, 3000); loadLatestReactions(); loadReactions(); }
                else { msgEl.innerHTML = `<div class="message error">${result.error || 'Failed to delete'}</div>`; }
            } catch (e) { msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        async function loadLatestReactions() {
            const container = document.getElementById('latest-reactions-container');
            const limitEl = document.getElementById('latest-limit');
            const limit = limitEl ? limitEl.value : 10;
            if (!container) return;
            try {
                const r = await fetch(`${API_URL}?action=post_reactions_latest&limit=${limit}&_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
                const data = await r.json();
                if (r.ok) { displayLatestReactions(data.reactions || []); }
                else { container.innerHTML = `<div class="message error">${data.error || 'Failed to load'}</div>`; }
            } catch (e) { container.innerHTML = `<div class="message error">Network error: ${e.message}</div>`; }
        }

        function displayLatestReactions(reactions) {
            const container = document.getElementById('latest-reactions-container');
            if (!container) return;
            if (!reactions.length) { container.innerHTML = '<p class="no-data">No reactions yet.</p>'; return; }
            const thead = '<tr><th>Page</th><th>Reaction</th><th>IP Address</th><th>Date</th><th class="actions-cell">Actions</th></tr>';
            const rows  = reactions.map(r => {
                const safeUrl    = escapeHtml(r.page_url_href || r.page_url);
                const emoji      = EMOJI_BY_TYPE[r.reaction_type] || r.reaction_type;
                const date       = formatDate(r.created_at || r.date);
                const ip         = escapeHtml(r.ip_address || 'N/A');
                const reactionId = r.id || r.reaction_id;
                const pageUrlEsc = (r.page_url || '').replace(/'/g, "\\'");
                return `<tr><td class="page-url"><a href="${safeUrl}" target="_blank">${safeUrl}</a></td><td class="reaction-emoji-cell">${emoji}</td><td class="ip-cell">${ip}</td><td class="date-cell">${date}</td><td class="actions-cell"><button class="btn btn-danger btn-sm" onclick="clearReaction('${reactionId}','${pageUrlEsc}','${r.reaction_type}')">Delete</button></td></tr>`;
            }).join('');
            container.innerHTML = `<table class="latest-reactions-table"><thead>${thead}</thead><tbody>${rows}</tbody></table>`;
        }

        hoistToWindow({ clearReactions, clearReaction, loadLatestReactions });
        loadReactions();
        loadLatestReactions();
    },
};


// ─────────────────────────────────────────────────────────────────────────────
// UTILITIES
// ─────────────────────────────────────────────────────────────────────────────
VIEWS['utilities'] = {
    title: 'Utilities',
    css: `
        .utilities-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .util-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
        .util-card.full-width { grid-column:1/-1; }
        .util-card-header { padding:1rem 1.5rem; border-bottom:1px solid var(--gray,#e9ecef); display:flex; align-items:center; gap:.6rem; }
        .util-card-header h2 { font-size:1.1rem; color:var(--body-text,#333); }
        .util-card-header .icon { font-size:1.2rem; }
        .util-card-body { padding:1.5rem; }
        .util-card-body p { color:var(--body-text,#666); opacity:.8; font-size:.9rem; margin-bottom:1rem; }
        .setting-row { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; padding:.75rem 0; border-bottom:1px solid var(--gray,#f0f0f0); }
        .setting-row:last-of-type { border-bottom:none; }
        .setting-label { flex:1 1 200px; }
        .setting-label strong { color:var(--body-text); display:block; font-size:.95rem; }
        .setting-label span { font-size:.82rem; color:var(--body-text); opacity:.8; }
        .themed-control { background-color:transparent; color:var(--body-text); border:1px solid var(--gray,#ddd); border-radius:4px; padding:.5rem .75rem; font-size:.95rem; }
        select.themed-control option { background-color:var(--on-background,#fff); color:var(--body-text,#333); }
        .toggle-switch { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#ccc; border-radius:26px; transition:.3s; }
        .toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background-color:white; border-radius:50%; transition:.3s; }
        input:checked+.toggle-slider { background-color:#4a90e2; }
        input:checked+.toggle-slider:before { transform:translateX(20px); }
        .db-stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1.25rem; }
        .db-stat-item { background:var(--light); border:solid 1px var(--gray); border-radius:6px; padding:.75rem 1rem; text-align:center; }
        .db-stat-item .num { font-size:1.4rem; font-weight:700; color:#4a90e2; }
        .db-stat-item .lbl { font-size:.78rem; color:#888; text-transform:uppercase; letter-spacing:.03em; }
        .db-actions { display:flex; gap:.75rem; flex-wrap:wrap; }
        .file-drop { border:2px dashed var(--gray,#d0d7de); border-radius:6px; padding:1.5rem; text-align:center; cursor:pointer; transition:border-color .2s,background .2s; margin-bottom:1rem; position:relative; }
        .file-drop:hover,.file-drop.drag-over { border-color:#4a90e2; background:#f0f7ff; }
        .file-drop input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
        .file-drop .drop-icon { font-size:2rem; margin-bottom:.5rem; }
        .file-drop .drop-label { font-size:.9rem; color:var(--body-text); }
        .file-drop .file-selected { font-size:.88rem; color:#28a745; font-weight:600; margin-top:.4rem; }
        .preview-box { background:var(--on-background); border:1px solid var(--gray,#dee2e6); border-radius:6px; padding:1rem; margin:.75rem 0; font-size:.88rem; }
        .preview-box table { width:100%; border-collapse:collapse; }
        .preview-box td { padding:.3rem .5rem; }
        .preview-box td:first-child { color:var(--body-text); width:55%; }
        .preview-box td:last-child { font-weight:600; }
        .import-actions { display:flex; gap:.75rem; align-items:center; margin-top:.75rem; }
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding:1rem; z-index:9999; }
        .modal { width:100%; max-width:560px; background:var(--on-background,#fff); color:var(--body-text,#333); border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,.25); overflow:hidden; }
        .modal-header,.modal-footer { padding:.85rem 1rem; display:flex; align-items:center; justify-content:space-between; gap:.75rem; border-bottom:1px solid var(--gray,#eee); }
        .modal-footer { border-top:1px solid var(--gray,#eee); border-bottom:none; justify-content:flex-end; }
        .modal-body { padding:1rem; }
        .modal-close { border:none; background:transparent; font-size:1.35rem; line-height:1; cursor:pointer; color:var(--body-text,#666); opacity:.6; }
        .modal-close:hover { opacity:1; }
        .muted { opacity:.7; font-size:.9rem; }
        .export-row { display:flex; align-items:center; justify-content:space-between; padding:.75rem 0; border-bottom:1px solid var(--gray,#f0f0f0); }
        .export-row:last-child { border-bottom:none; }
        .export-row .export-info strong { display:block; color:var(--body-text); }
        .export-row .export-info span { font-size:.82rem; color:var(--body-text); opacity:.8; }
        .email-test-row { display:flex; flex-wrap:wrap; gap:.75rem; }
        .email-test-row input { flex:1 1 200px; }
        @media (max-width:900px) { .utilities-grid { grid-template-columns:1fr; } .util-card.full-width { grid-column:1; } }
        @media (max-width:768px) { .db-stats-grid { grid-template-columns:repeat(2,1fr); } }`,

    html: () => `
        <div class="container">
            <div class="utilities-grid">
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">⚙️</span><h2>Settings</h2></div>
                    <div class="util-card-body">
                        <div id="settings-message"></div>
                        <div class="setting-row">
                            <div class="setting-label"><strong>Require Moderation</strong><span>New comments must be approved before appearing</span></div>
                            <label class="toggle-switch"><input type="checkbox" id="setting-require-moderation"><span class="toggle-slider"></span></label>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><strong>Comment Sort Order</strong><span>Default order for top-level comments on the site</span></div>
                            <select id="setting-comment-sort-order" class="themed-control" style="min-width:180px;">
                                <option value="asc">Oldest first (ASC)</option>
                                <option value="desc">Newest first (DESC)</option>
                            </select>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><strong>Email Notifications</strong><span>Send email alerts for new comments</span></div>
                            <label class="toggle-switch"><input type="checkbox" id="setting-enable-notifications"><span class="toggle-slider"></span></label>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><strong>Admin Email</strong><span>Receives new comment notifications</span></div>
                            <div style="display:flex;gap:.5rem;flex:1 1 250px;">
                                <input type="email" id="setting-admin-email" class="themed-control" placeholder="admin@example.com" style="flex:1;">
                                <button class="btn btn-primary btn-sm" onclick="saveSettings()">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">🗄️</span><h2>Database</h2></div>
                    <div class="util-card-body">
                        <div id="db-stats-area"><p>Loading database stats...</p></div>
                        <div id="db-message"></div>
                        <div class="db-actions">
                            <button class="btn btn-secondary btn-sm" onclick="loadDbStats()">Refresh Stats</button>
                            <button class="btn btn-primary btn-sm" onclick="vacuumDb()">Optimize (VACUUM)</button>
                            <button class="btn btn-danger btn-sm" onclick="deleteSpam()" id="btn-delete-spam">Purge All Spam</button>
                            <button class="btn btn-danger btn-sm" onclick="openDeleteDataModal()">Delete Data</button>
                        </div>
                    </div>
                </div>
                <div id="delete-data-modal" class="modal-overlay" style="display:none;">
                    <div class="modal">
                        <div class="modal-header"><strong>Delete data from database</strong><button class="modal-close" onclick="closeDeleteDataModal()" aria-label="Close">×</button></div>
                        <div class="modal-body">
                            <div class="message warning" style="margin:0 0 .75rem 0;"><strong>Warning:</strong> This permanently deletes selected data records. The schema stays intact, but the data cannot be recovered unless you restore from an export/backup.</div>
                            <label class="checkbox-row" style="display:flex;align-items:center;gap:.5rem;margin:.25rem 0;"><input type="checkbox" id="dd-select-all" onchange="toggleDeleteDataSelectAll()"><span><strong>Select All</strong></span></label>
                            <div style="margin-top:.5rem;">
                                <label class="checkbox-row" style="display:flex;align-items:center;gap:.5rem;margin:.25rem 0;"><input type="checkbox" id="dd-comments" onchange="syncDeleteDataSelectAll()"><span>Comments <span class="muted" id="dd-count-comments">(…)</span></span></label>
                                <label class="checkbox-row" style="display:flex;align-items:center;gap:.5rem;margin:.25rem 0;"><input type="checkbox" id="dd-reactions" onchange="syncDeleteDataSelectAll()"><span>Reactions <span class="muted" id="dd-count-reactions">(…)</span></span></label>
                                <label class="checkbox-row" style="display:flex;align-items:center;gap:.5rem;margin:.25rem 0;"><input type="checkbox" id="dd-subscriptions" onchange="syncDeleteDataSelectAll()"><span>Subscriptions <span class="muted" id="dd-count-subscriptions">(…)</span></span></label>
                            </div>
                            <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--gray,#dee2e6);">
                                <label style="display:flex;align-items:flex-start;gap:.5rem;"><input type="checkbox" id="dd-confirm"><span>I understand this action is permanent and want to delete the selected data.</span></label>
                                <div id="dd-message" style="margin-top:.5rem;"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary btn-sm" onclick="closeDeleteDataModal()">Cancel</button>
                            <button class="btn btn-danger btn-sm" id="dd-delete-btn" onclick="runDeleteData()" disabled>Delete selected</button>
                        </div>
                    </div>
                </div>
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">📥</span><h2>Import Comments</h2></div>
                    <div class="util-card-body">
                        <p>Import from a Comments Export XML file, a legacy project export, Disqus XML, or WordPress WXR. Native exports restore comments (all statuses), reactions, subscriptions, IP addresses, and metadata. Duplicate comments are skipped automatically.</p>
                        <div class="file-drop" id="file-drop" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                            <input type="file" id="import-file" accept=".xml" onchange="handleFileSelect(event)">
                            <div class="drop-icon">📂</div>
                            <div class="drop-label">Drop XML file here or click to browse</div>
                            <div class="file-selected" id="file-selected-label" style="display:none;"></div>
                        </div>
                        <div id="import-preview" style="display:none;"></div>
                        <div id="import-message"></div>
                        <div class="import-actions">
                            <button class="btn btn-secondary btn-sm" id="btn-preview" onclick="previewImport()" disabled>Preview</button>
                            <button class="btn btn-success btn-sm" id="btn-import" onclick="runImport()" disabled>Import</button>
                            <span id="import-status" style="font-size:.85rem;color:var(--body-text,#888);opacity:.8;"></span>
                        </div>
                    </div>
                </div>
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">📤</span><h2>Export Comments</h2></div>
                    <div class="util-card-body">
                        <div class="export-row">
                            <div class="export-info"><strong>Comments Export XML</strong><span>Full backup: all comments (every status), reactions, subscriptions, IP addresses, and metadata</span></div>
                            <a href="../api.php?action=export_comments" class="btn btn-primary btn-sm">Download</a>
                        </div>
                        <div style="margin-top:1rem;"><div id="export-message"></div></div>
                    </div>
                </div>
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">🔗</span><h2>URL Tools</h2></div>
                    <div class="util-card-body">
                        <div class="export-row">
                            <div class="export-info"><strong>Normalize URLs</strong><span>Strip scheme &amp; host from full URLs (e.g. https://example.com/post/ → /post/)</span></div>
                            <button class="btn btn-warning btn-sm" onclick="normalizeUrls()">Run</button>
                        </div>
                        <div id="url-message"></div>
                    </div>
                </div>
                <div class="util-card">
                    <div class="util-card-header"><span class="icon">✉️</span><h2>Test Email</h2></div>
                    <div class="util-card-body">
                        <p>Send a test email to verify your server's mail configuration.</p>
                        <div class="form-group">
                            <label for="test-email-addr">Send test email to</label>
                            <div class="email-test-row">
                                <input type="email" id="test-email-addr" class="themed-control" placeholder="you@example.com">
                                <button class="btn btn-primary btn-sm" onclick="sendTestEmail()">Send</button>
                            </div>
                        </div>
                        <div id="email-message"></div>
                    </div>
                </div>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        let importFileContent = null;
        let importPreviewDone = false;

        // ── Settings ──────────────────────────────────────────────────────────
        async function loadSettings() {
            try {
                const r = await fetch(`${API_URL}?action=get_settings`, { credentials: 'include' });
                const d = await r.json();
                if (!r.ok) return;
                const s = d.settings;
                document.getElementById('setting-require-moderation').checked  = (s.require_moderation  === 'true');
                document.getElementById('setting-enable-notifications').checked = (s.enable_notifications === 'true');
                document.getElementById('setting-admin-email').value            = s.admin_email || '';
                document.getElementById('setting-comment-sort-order').value     = s.comment_sort_order === 'desc' ? 'desc' : 'asc';
            } catch (e) { console.error('Settings load failed', e); }
        }

        // Wire auto-save toggles after DOM is ready
        ['setting-require-moderation','setting-enable-notifications','setting-comment-sort-order'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', saveSettings);
        });

        async function saveSettings() {
            const msgEl = document.getElementById('settings-message');
            await AdminAuth.ensureCsrfToken();
            const payload = {
                csrf_token:           AdminAuth.getCsrfToken(),
                require_moderation:   document.getElementById('setting-require-moderation').checked   ? 'true' : 'false',
                enable_notifications: document.getElementById('setting-enable-notifications').checked ? 'true' : 'false',
                admin_email:          document.getElementById('setting-admin-email').value.trim(),
                comment_sort_order:   document.getElementById('setting-comment-sort-order').value,
            };
            try {
                const r = await fetch(`${API_URL}?action=save_settings`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    credentials: 'include', body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (r.ok) {
                    msgEl.innerHTML = '<div class="message success">Settings saved.</div>';
                    setTimeout(() => { if (msgEl) msgEl.innerHTML = ''; }, 2500);
                } else { msgEl.innerHTML = `<div class="message error">${d.error}</div>`; }
            } catch (e) { msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        // ── Database ──────────────────────────────────────────────────────────
        async function loadDbStats() {
            const area = document.getElementById('db-stats-area');
            if (!area) return;
            try {
                const r = await fetch(`${API_URL}?action=db_stats`, { credentials: 'include' });
                const d = await r.json();
                if (!r.ok) { area.innerHTML = `<div class="message error">${d.error}</div>`; return; }
                const t = d.tables, cs = d.comment_statuses || {};
                area.innerHTML = `<div class="db-stats-grid">
                    <div class="db-stat-item"><div class="num">${t.comments ?? 0}</div><div class="lbl">Comments</div></div>
                    <div class="db-stat-item"><div class="num">${cs.pending ?? 0}</div><div class="lbl">Pending</div></div>
                    <div class="db-stat-item"><div class="num">${cs.spam ?? 0}</div><div class="lbl">Spam</div></div>
                    <div class="db-stat-item"><div class="num">${t.votes ?? 0}</div><div class="lbl">Votes</div></div>
                    <div class="db-stat-item"><div class="num">${t.subscriptions ?? 0}</div><div class="lbl">Subscriptions</div></div>
                    <div class="db-stat-item"><div class="num">${formatBytes(d.db_size_bytes)}</div><div class="lbl">DB Size</div></div>
                </div>`;
                const spamCount = cs.spam ?? 0;
                const btn = document.getElementById('btn-delete-spam');
                if (btn) { btn.textContent = spamCount > 0 ? `Purge ${spamCount} Spam` : 'Purge All Spam'; btn.disabled = spamCount === 0; }
            } catch (e) { area.innerHTML = '<div class="message error">Failed to load stats</div>'; }
        }

        async function vacuumDb() {
            const msgEl = document.getElementById('db-message');
            await AdminAuth.ensureCsrfToken();
            msgEl.innerHTML = '<div class="message info">Running VACUUM…</div>';
            try {
                const r = await fetch(`${API_URL}?action=vacuum`, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken()}) });
                const d = await r.json();
                if (r.ok) { const saved=d.saved_bytes>0?` Freed ${formatBytes(d.saved_bytes)}.`:' No space reclaimed (already optimal).'; msgEl.innerHTML=`<div class="message success">Database optimized.${saved} New size: ${formatBytes(d.size_after)}.</div>`; loadDbStats(); }
                else { msgEl.innerHTML=`<div class="message error">${d.error}</div>`; }
            } catch (e) { msgEl.innerHTML='<div class="message error">Network error</div>'; }
        }

        async function deleteSpam() {
            if (!confirm('Delete ALL spam comments? This cannot be undone.')) return;
            const msgEl = document.getElementById('db-message');
            await AdminAuth.ensureCsrfToken();
            try {
                const r = await fetch(`${API_URL}?action=delete_spam`, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken()}) });
                const d = await r.json();
                if (r.ok) { msgEl.innerHTML=`<div class="message success">Deleted ${d.deleted} spam comment${d.deleted!==1?'s':''}.</div>`; loadDbStats(); }
                else { msgEl.innerHTML=`<div class="message error">${d.error}</div>`; }
            } catch (e) { msgEl.innerHTML='<div class="message error">Network error</div>'; }
        }

        // ── Delete Data modal ─────────────────────────────────────────────────
        function openDeleteDataModal() {
            ['dd-message','dd-confirm','dd-select-all','dd-comments','dd-reactions','dd-subscriptions'].forEach(id=>{const el=document.getElementById(id);if(!el)return;if(el.type==='checkbox')el.checked=false;else el.innerHTML='';});
            const btn=document.getElementById('dd-delete-btn');if(btn)btn.disabled=true;
            refreshDeleteDataCounts();
            document.getElementById('delete-data-modal').style.display='flex';
        }
        function closeDeleteDataModal() { const m=document.getElementById('delete-data-modal');if(m)m.style.display='none'; }
        function getDeleteDataSelection() { return['comments','reactions','subscriptions'].filter(c=>document.getElementById(`dd-${c}`)?.checked); }
        function toggleDeleteDataSelectAll() { const all=document.getElementById('dd-select-all').checked; ['comments','reactions','subscriptions'].forEach(c=>{const el=document.getElementById(`dd-${c}`);if(el)el.checked=all;}); updateDeleteDataButtonState(); }
        function syncDeleteDataSelectAll() { const allChecked=['comments','reactions','subscriptions'].every(c=>document.getElementById(`dd-${c}`)?.checked); const sa=document.getElementById('dd-select-all');if(sa)sa.checked=allChecked; updateDeleteDataButtonState(); }
        function updateDeleteDataButtonState() { const btn=document.getElementById('dd-delete-btn');if(btn)btn.disabled=!(document.getElementById('dd-confirm')?.checked&&getDeleteDataSelection().length>0); }

        // Wire confirm checkbox
        document.addEventListener('change', function onDdChange(e) {
            if (['dd-confirm','dd-comments','dd-reactions','dd-subscriptions'].includes(e.target?.id)) updateDeleteDataButtonState();
        });

        async function refreshDeleteDataCounts() {
            ['comments','reactions','subscriptions'].forEach(c=>{const el=document.getElementById(`dd-count-${c}`);if(el)el.textContent='(…)';});
            try {
                await AdminAuth.ensureCsrfToken();
                const r=await fetch(`${API_URL}?action=db_delete_data`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken(),preview:true})});
                const d=await r.json();if(!r.ok)return;
                ['comments','reactions','subscriptions'].forEach(c=>{const el=document.getElementById(`dd-count-${c}`);if(el)el.textContent=`(${d.counts?.[c]??0})`;});
            } catch(e){}
        }

        async function runDeleteData() {
            const msgEl=document.getElementById('dd-message');
            const categories=getDeleteDataSelection();
            if(!categories.length){msgEl.innerHTML='<div class="message error">Select at least one category.</div>';return;}
            if(!document.getElementById('dd-confirm')?.checked){msgEl.innerHTML='<div class="message error">You must explicitly confirm before deleting.</div>';return;}
            const btn=document.getElementById('dd-delete-btn');if(btn)btn.disabled=true;
            msgEl.innerHTML='<div class="message info">Deleting…</div>';
            try {
                await AdminAuth.ensureCsrfToken();
                const r=await fetch(`${API_URL}?action=db_delete_data`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken(),categories})});
                const d=await r.json();
                if(r.ok){const msg=`Deleted: comments=${d.deleted?.comments??0}, reactions=${d.deleted?.reactions??0}, subscriptions=${d.deleted?.subscriptions??0}.`;msgEl.innerHTML=`<div class="message success">${msg}</div>`;const dbMsg=document.getElementById('db-message');if(dbMsg)dbMsg.innerHTML=`<div class="message success">Delete completed. ${msg}</div>`;loadDbStats();refreshDeleteDataCounts();}
                else{msgEl.innerHTML=`<div class="message error">${d.error||'Failed to delete data'}</div>`;}
            }catch(e){msgEl.innerHTML='<div class="message error">Network error</div>';}
            finally{updateDeleteDataButtonState();}
        }

        // ── Import ────────────────────────────────────────────────────────────
        function handleDragOver(e){e.preventDefault();document.getElementById('file-drop')?.classList.add('drag-over');}
        function handleDragLeave(){document.getElementById('file-drop')?.classList.remove('drag-over');}
        function handleDrop(e){e.preventDefault();handleDragLeave();const f=e.dataTransfer.files[0];if(f)loadImportFile(f);}
        function handleFileSelect(e){const f=e.target.files[0];if(f)loadImportFile(f);}

        function loadImportFile(file) {
            const label=document.getElementById('file-selected-label');
            const reader=new FileReader();
            reader.onload=e=>{importFileContent=e.target.result;importPreviewDone=false;label.textContent=`✓ ${file.name} (${formatBytes(file.size)})`;label.style.display='block';const prev=document.getElementById('btn-preview');const imp=document.getElementById('btn-import');if(prev)prev.disabled=false;if(imp)imp.disabled=true;const iprev=document.getElementById('import-preview');if(iprev)iprev.style.display='none';const imsg=document.getElementById('import-message');if(imsg)imsg.innerHTML='';const ist=document.getElementById('import-status');if(ist)ist.textContent='';};
            reader.readAsText(file);
        }

        async function previewImport() {
            if(!importFileContent)return;
            const msgEl=document.getElementById('import-message');const previewEl=document.getElementById('import-preview');const statusEl=document.getElementById('import-status');
            await AdminAuth.ensureCsrfToken();
            if(statusEl)statusEl.textContent='Analysing…';const bprev=document.getElementById('btn-preview');if(bprev)bprev.disabled=true;
            try{
                const r=await fetch(`${API_URL}?action=import_comments`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken(),content:importFileContent,preview:true})});
                const d=await r.json();
                if(!r.ok){if(msgEl)msgEl.innerHTML=`<div class="message error">${d.error}</div>`;if(statusEl)statusEl.textContent='';if(bprev)bprev.disabled=false;return;}
                const dr=d.date_range;const dateRange=dr?`${dr.oldest.substring(0,10)} → ${dr.newest.substring(0,10)}`:'—';
                const topRows=(d.top_threads||[]).map(t=>`<tr><td>${escapeHtml(t.url)}</td><td>${t.count}</td></tr>`).join('');
                if(previewEl){previewEl.innerHTML=`<div class="preview-box"><table>
                    <tr><td>Detected format</td><td>${escapeHtml(d.format||'—')}${d.native_export?' (full restore)':''}</td></tr>
                    <tr><td>Threads / pages</td><td>${d.threads}</td></tr>
                    <tr><td>Total posts in file</td><td>${d.posts_total}</td></tr>
                    <tr><td>Will be imported</td><td><strong style="color:#28a745">${d.posts_import}</strong></td></tr>
                    <tr><td>Skipped (spam/deleted)</td><td>${d.posts_skip}</td></tr>
                    <tr><td>Duplicates (already exist)</td><td>${d.duplicates}</td></tr>
                    <tr><td>Orphaned (no thread match)</td><td>${d.orphaned}</td></tr>
                    <tr><td>Comment reactions in file</td><td>${d.reactions_in_file??0}</td></tr>
                    <tr><td>Comment reactions to import</td><td><strong style="color:#28a745">${d.reactions_import??0}</strong></td></tr>
                    <tr><td>Post reactions in file</td><td>${d.post_reactions_in_file??0}</td></tr>
                    <tr><td>Post reactions to import</td><td><strong style="color:#28a745">${d.post_reactions_import??0}</strong></td></tr>
                    <tr><td>Subscriptions in file</td><td>${d.subscriptions_in_file??0}</td></tr>
                    <tr><td>Subscriptions to import</td><td><strong style="color:#28a745">${d.subscriptions_import??0}</strong></td></tr>
                    <tr><td>Date range</td><td>${dateRange}</td></tr>
                </table>${topRows?`<hr style="margin:.75rem 0;border:none;border-top:1px solid var(--gray,#dee2e6);"><strong style="font-size:.82rem;">Top pages</strong><table style="margin-top:.4rem;">${topRows}</table>`:''}
                ${(d.warnings||[]).map(w=>`<div class="message warning" style="margin-top:.5rem;">${escapeHtml(w)}</div>`).join('')}
                </div>`;previewEl.style.display='block';}
                if(msgEl)msgEl.innerHTML='';
                const canImport=(d.posts_import>0)||((d.reactions_import??0)>0)||((d.post_reactions_import??0)>0)||((d.subscriptions_import??0)>0);
                const bimp=document.getElementById('btn-import');
                if(canImport){if(bimp)bimp.disabled=false;importPreviewDone=true;}else{if(msgEl)msgEl.innerHTML='<div class="message info">Nothing new to import.</div>';}
                if(statusEl)statusEl.textContent='';if(bprev)bprev.disabled=false;
            }catch(e){if(msgEl)msgEl.innerHTML='<div class="message error">Network error</div>';if(statusEl)statusEl.textContent='';if(bprev)bprev.disabled=false;}
        }

        async function runImport() {
            if(!importFileContent||!importPreviewDone)return;
            if(!confirm('Proceed with import? This will add comments, reactions, and/or subscriptions to the database.'))return;
            const msgEl=document.getElementById('import-message');const statusEl=document.getElementById('import-status');
            await AdminAuth.ensureCsrfToken();
            const bimp=document.getElementById('btn-import');if(bimp)bimp.disabled=true;if(statusEl)statusEl.textContent='Importing…';
            try{
                const r=await fetch(`${API_URL}?action=import_comments`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken(),content:importFileContent})});
                const d=await r.json();if(statusEl)statusEl.textContent='';
                if(r.ok){
                    const parts=[];
                    if(d.imported>0)parts.push(`${d.imported} comment${d.imported!==1?'s':''} across ${d.unique_pages} page${d.unique_pages!==1?'s':''}`);
                    if((d.reactions_imported??0)>0)parts.push(`${d.reactions_imported} comment reaction${d.reactions_imported!==1?'s':''}`);
                    if((d.post_reactions_imported??0)>0)parts.push(`${d.post_reactions_imported} post reaction${d.post_reactions_imported!==1?'s':''}`);
                    if((d.subscriptions_imported??0)>0)parts.push(`${d.subscriptions_imported} subscription${d.subscriptions_imported!==1?'s':''}`);
                    const dupNote=d.skipped_duplicates>0?` (${d.skipped_duplicates} duplicate comments skipped)`:'';
                    if(msgEl)msgEl.innerHTML=`<div class="message success">Imported ${parts.length?parts.join(', '):'no new items'}${dupNote}.</div>`;
                    const iprev=document.getElementById('import-preview');if(iprev)iprev.style.display='none';
                    importFileContent=null;importPreviewDone=false;
                    const bprev=document.getElementById('btn-preview');if(bprev)bprev.disabled=true;
                    const flabel=document.getElementById('file-selected-label');if(flabel)flabel.style.display='none';
                    loadDbStats();
                }else{if(msgEl)msgEl.innerHTML=`<div class="message error">${d.error}</div>`;if(bimp)bimp.disabled=false;}
            }catch(e){if(msgEl)msgEl.innerHTML='<div class="message error">Network error</div>';if(statusEl)statusEl.textContent='';if(bimp)bimp.disabled=false;}
        }

        // ── URL Tools ─────────────────────────────────────────────────────────
        async function normalizeUrls() {
            const msgEl=document.getElementById('url-message');
            if(!confirm('Strip scheme and host from all full URLs in the database?\n\nExample: https://example.com/post/ → /post/\n\nThis is safe to run multiple times.'))return;
            await AdminAuth.ensureCsrfToken();
            if(msgEl)msgEl.innerHTML='<div class="message info">Running…</div>';
            try{
                const r=await fetch(`${API_URL}?action=normalize_urls`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken()})});
                const d=await r.json();
                if(r.ok){if(msgEl)msgEl.innerHTML=`<div class="message success">${d.comments_updated>0?`Updated ${d.comments_updated} comment URL${d.comments_updated!==1?'s':''}.`:'No full URLs found — nothing to change.'}</div>`;}
                else{if(msgEl)msgEl.innerHTML=`<div class="message error">${d.error}</div>`;}
            }catch(e){if(msgEl)msgEl.innerHTML='<div class="message error">Network error</div>';}
        }

        // ── Email ─────────────────────────────────────────────────────────────
        async function sendTestEmail() {
            const addr=document.getElementById('test-email-addr')?.value.trim();
            const msgEl=document.getElementById('email-message');
            if(!addr){if(msgEl)msgEl.innerHTML='<div class="message error">Enter an email address.</div>';return;}
            await AdminAuth.ensureCsrfToken();
            if(msgEl)msgEl.innerHTML='<div class="message info">Sending…</div>';
            try{
                const r=await fetch(`${API_URL}?action=test_email`,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken(),email:addr,page_url:'/'})});
                const d=await r.json();
                if(r.ok){if(msgEl)msgEl.innerHTML=`<div class="message success">${d.message}</div>`;}
                else{if(msgEl)msgEl.innerHTML=`<div class="message error">${d.error}</div>`;}
            }catch(e){if(msgEl)msgEl.innerHTML='<div class="message error">Network error</div>';}
        }

        hoistToWindow({
            saveSettings, loadDbStats, vacuumDb, deleteSpam,
            openDeleteDataModal, closeDeleteDataModal, toggleDeleteDataSelectAll, syncDeleteDataSelectAll, runDeleteData,
            handleDragOver, handleDragLeave, handleDrop, handleFileSelect, previewImport, runImport,
            normalizeUrls, sendTestEmail,
        });

        loadSettings();
        loadDbStats();
    },
};
