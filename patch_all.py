import re
import os

# 1. admin/assets/admin-app.js (NAV_ITEMS and views)
with open('admin/assets/admin-app.js', 'r') as f:
    app_js = f.read()

app_js = app_js.replace("""    { key: 'utilities',      label: 'Utilities',      icon: 'tool' },
    { key: 'settings',       label: 'Settings',       icon: 'settings' },""", """    { key: 'settings',       label: 'Settings',       icon: 'settings', isParent: true, children: [
        { key: 'settings-general',       label: 'General' },
        { key: 'settings-configuration', label: 'Configuration' },
        { key: 'settings-database',      label: 'Database' },
        { key: 'settings-notifications', label: 'Notifications' },
        { key: 'settings-import-export', label: 'Import & Export' }
    ]}""")

app_js = app_js.replace("""    nav.innerHTML = NAV_ITEMS.map(({ key, label, icon }) => {
        const cls = key === activeKey ? ' class="active"' : '';
        return `<a href="#${key}"${cls} title="${label}"><i data-lucide="${icon}"></i><span class="nav-label">${label}</span></a>`;
    }).join('') +""", """    let isSettingsActive = false;
    if (activeKey && activeKey.startsWith('settings-')) {
        isSettingsActive = true;
    }

    nav.innerHTML = NAV_ITEMS.map(({ key, label, icon, isParent, children }) => {
        if (isParent) {
            const isOpen = isSettingsActive ? 'open' : '';
            const childrenHtml = children.map(child => {
                const cls = child.key === activeKey ? ' class="active"' : '';
                return `<a href="#${child.key}"${cls} title="${child.label}"><span class="nav-label">${child.label}</span></a>`;
            }).join('');
            return `<details class="nav-parent" ${isOpen}><summary title="${label}"><i data-lucide="${icon}"></i><span class="nav-label">${label}</span><i data-lucide="chevron-down" class="nav-chevron"></i></summary><div class="nav-children">${childrenHtml}</div></details>`;
        }
        const cls = key === activeKey ? ' class="active"' : '';
        return `<a href="#${key}"${cls} title="${label}"><i data-lucide="${icon}"></i><span class="nav-label">${label}</span></a>`;
    }).join('') +""")

utilities_start = app_js.find("// ─────────────────────────────────────────────────────────────────────────────\n// UTILITIES")
sidebar_toggle_start = app_js.find("// Sidebar toggle logic")

views_content = """// ─────────────────────────────────────────────────────────────────────────────
// SETTINGS
// ─────────────────────────────────────────────────────────────────────────────

VIEWS['settings-general'] = {
    title: 'General Settings',
    css: `
        .util-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
        .util-card-header { padding:1rem 1.5rem; border-bottom:1px solid var(--gray,#e9ecef); display:flex; align-items:center; gap:.6rem; }
        .util-card-header h2 { font-size:1.1rem; color:var(--body-text,#333); }
        .util-card-header .icon { font-size:1.2rem; }
        .util-card-body { padding:1.5rem; }
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
    `,
    html: () => `
        <div class="container">
            <h2 style="margin-bottom: 1.5rem;">General Settings</h2>
            <div class="util-card">
                <div class="util-card-header"><span class="icon">⚙️</span><h2>General</h2></div>
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
                </div>
            </div>
        </div>
    `,
    init({ hoistToWindow }) {
        async function loadSettings() {
            try {
                const r = await fetch(`${API_URL}?action=get_settings`, { credentials: 'include' });
                const d = await r.json();
                if (!r.ok) return;
                const s = d.settings;
                document.getElementById('setting-require-moderation').checked  = (s.require_moderation  === 'true');
                document.getElementById('setting-comment-sort-order').value     = s.comment_sort_order === 'desc' ? 'desc' : 'asc';
            } catch (e) { console.error('Settings load failed', e); }
        }

        ['setting-require-moderation','setting-comment-sort-order'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', saveSettings);
        });

        async function saveSettings() {
            const msgEl = document.getElementById('settings-message');
            await AdminAuth.ensureCsrfToken();
            try {
                const g = await fetch(`${API_URL}?action=get_settings`, { credentials: 'include' });
                const current = (await g.json()).settings || {};

                const payload = {
                    csrf_token:           AdminAuth.getCsrfToken(),
                    require_moderation:   document.getElementById('setting-require-moderation').checked   ? 'true' : 'false',
                    enable_notifications: current.enable_notifications || 'false',
                    admin_email:          current.admin_email || '',
                    comment_sort_order:   document.getElementById('setting-comment-sort-order').value,
                };

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

        hoistToWindow({ saveSettings });
        loadSettings();
    }
};

VIEWS['settings-configuration'] = {
    title: 'Configuration',
    css: ``,
    html: () => `
        <div class="container">
            <h2 style="margin-bottom: 1.5rem;">Configuration</h2>
            <div id="settings-message"></div>
            <div class="settings-form">
                <div class="form-group">
                    <label for="config-app-url">Application URL</label>
                    <p class="help-text">The URL where this comment system is installed (no trailing slash)</p>
                    <input type="text" id="config-app-url" class="themed-control">
                </div>
                <div class="form-group">
                    <label for="config-allowed-origins">Allowed Origins</label>
                    <p class="help-text">Comma-separated list of domains allowed to embed comments (CORS)</p>
                    <input type="text" id="config-allowed-origins" class="themed-control" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label for="config-timezone">Timezone</label>
                    <p class="help-text">Choose the timezone for comment timestamps</p>
                    <select id="config-timezone" class="themed-control">
                        <option value="UTC">UTC</option>
                        <option value="America/New_York">America/New_York (Eastern Time)</option>
                        <option value="America/Chicago">America/Chicago (Central Time)</option>
                        <option value="America/Denver">America/Denver (Mountain Time)</option>
                        <option value="America/Los_Angeles">America/Los_Angeles (Pacific Time)</option>
                        <option value="Europe/London">Europe/London (GMT)</option>
                        <option value="Europe/Paris">Europe/Paris (Central European)</option>
                        <option value="Europe/Berlin">Europe/Berlin (Central European)</option>
                        <option value="Asia/Tehran">Asia/Tehran (Iran)</option>
                        <option value="Asia/Dubai">Asia/Dubai (Gulf)</option>
                        <option value="Asia/Tokyo">Asia/Tokyo (Japan)</option>
                        <option value="Asia/Shanghai">Asia/Shanghai (China)</option>
                        <option value="Australia/Sydney">Australia/Sydney (Australian Eastern)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="config-language">Frontend Language</label>
                    <p class="help-text">Language for the comment widget interface</p>
                    <select id="config-language" class="themed-control">
                        <option value="en">English</option>
                        <option value="fa">فارسی (Persian)</option>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="saveConfig()">Save Configuration</button>
            </div>
        </div>`,

    init({ hoistToWindow }) {
        async function loadConfig() {
            const msgEl = document.getElementById('settings-message');
            try {
                const r = await fetch(`${API_URL}?action=get_config`, { credentials: 'include' });
                const d = await r.json();
                if (r.ok) {
                    document.getElementById('config-app-url').value = d.app_url || '';
                    document.getElementById('config-allowed-origins').value = Array.isArray(d.allowed_origins) ? d.allowed_origins.join(', ') : '';
                    document.getElementById('config-timezone').value = d.timezone || 'UTC';
                    document.getElementById('config-language').value = d.app_language || 'en';
                } else {
                    if (msgEl) msgEl.innerHTML = `<div class="message error">${d.error || 'Failed to load configuration'}</div>`;
                }
            } catch (e) {
                if (msgEl) msgEl.innerHTML = '<div class="message error">Network error loading configuration</div>';
            }
        }

        async function saveConfig() {
            const msgEl = document.getElementById('settings-message');
            const appUrl = document.getElementById('config-app-url').value.trim();
            const allowedOrigins = document.getElementById('config-allowed-origins').value.split(',').map(s => s.trim()).filter(s => s);
            const timezone = document.getElementById('config-timezone').value;
            const language = document.getElementById('config-language').value;

            if (!appUrl) {
                if (msgEl) msgEl.innerHTML = '<div class="message error">Application URL is required</div>';
                return;
            }
            if (!allowedOrigins.length) {
                if (msgEl) msgEl.innerHTML = '<div class="message error">At least one allowed origin is required</div>';
                return;
            }

            try {
                await AdminAuth.ensureCsrfToken();
                const r = await fetch(`${API_URL}?action=save_config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        csrf_token: AdminAuth.getCsrfToken(),
                        app_url: appUrl,
                        allowed_origins: allowedOrigins,
                        timezone: timezone,
                        app_language: language
                    })
                });
                const d = await r.json();
                if (r.ok) {
                    if (msgEl) msgEl.innerHTML = '<div class="message success">Configuration saved successfully</div>';
                } else {
                    if (msgEl) msgEl.innerHTML = `<div class="message error">${d.error || 'Failed to save configuration'}</div>`;
                }
            } catch (e) {
                if (msgEl) msgEl.innerHTML = '<div class="message error">Network error saving configuration</div>';
            }
        }

        hoistToWindow({ saveConfig });
        loadConfig();
    },
};

VIEWS['settings-database'] = {
    title: 'Database Settings',
    css: `
        .util-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
        .util-card-header { padding:1rem 1.5rem; border-bottom:1px solid var(--gray,#e9ecef); display:flex; align-items:center; gap:.6rem; }
        .util-card-header h2 { font-size:1.1rem; color:var(--body-text,#333); }
        .util-card-header .icon { font-size:1.2rem; }
        .util-card-body { padding:1.5rem; }
        .db-stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1.25rem; }
        .db-stat-item { background:var(--light); border:solid 1px var(--gray); border-radius:6px; padding:.75rem 1rem; text-align:center; }
        .db-stat-item .num { font-size:1.4rem; font-weight:700; color:#4a90e2; }
        .db-stat-item .lbl { font-size:.78rem; color:#888; text-transform:uppercase; letter-spacing:.03em; }
        .db-actions { display:flex; gap:.75rem; flex-wrap:wrap; }
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding:1rem; z-index:9999; }
        .modal { width:100%; max-width:560px; background:var(--on-background,#fff); color:var(--body-text,#333); border-radius:10px; box-shadow:0 10px 40px rgba(0,0,0,.25); overflow:hidden; }
        .modal-header,.modal-footer { padding:.85rem 1rem; display:flex; align-items:center; justify-content:space-between; gap:.75rem; border-bottom:1px solid var(--gray,#eee); }
        .modal-footer { border-top:1px solid var(--gray,#eee); border-bottom:none; justify-content:flex-end; }
        .modal-body { padding:1rem; }
        .modal-close { border:none; background:transparent; font-size:1.35rem; line-height:1; cursor:pointer; color:var(--body-text,#666); opacity:.6; }
        .modal-close:hover { opacity:1; }
        .muted { opacity:.7; font-size:.9rem; }
        @media (max-width:768px) { .db-stats-grid { grid-template-columns:repeat(2,1fr); } }
    `,
    html: () => `
        <div class="container">
            <h2 style="margin-bottom: 1.5rem;">Database Settings</h2>
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
        </div>
    `,
    init({ hoistToWindow }) {
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
                else { msgEl.innerHTML = `<div class="message error">${d.error}</div>`; }
            } catch(e) { msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        async function deleteSpam() {
            const msgEl = document.getElementById('db-message');
            if(!confirm('Delete ALL comments marked as spam? This cannot be undone.')) return;
            await AdminAuth.ensureCsrfToken();
            msgEl.innerHTML = '<div class="message info">Purging spam…</div>';
            try {
                const r = await fetch(`${API_URL}?action=delete_spam`, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken()}) });
                const d = await r.json();
                if (r.ok) { msgEl.innerHTML = `<div class="message success">Deleted ${d.deleted_count} spam comment(s).</div>`; loadDbStats(); }
                else { msgEl.innerHTML = `<div class="message error">${d.error}</div>`; }
            } catch(e) { msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        function openDeleteDataModal() {
            const m = document.getElementById('delete-data-modal');
            if (!m) return;
            m.style.display = 'flex';
            document.getElementById('dd-select-all').checked = false;
            ['comments','reactions','subscriptions','confirm'].forEach(k => { const el = document.getElementById('dd-'+k); if (el) el.checked = false; });
            document.getElementById('dd-message').innerHTML = '';
            document.getElementById('dd-delete-btn').disabled = true;
            syncDeleteDataSelectAll();

            fetch(`${API_URL}?action=db_stats`, { credentials: 'include' })
                .then(r => r.json())
                .then(d => {
                    if (d.tables) {
                        const c = document.getElementById('dd-count-comments'); if (c) c.textContent = `(${d.tables.comments ?? 0})`;
                        const r = document.getElementById('dd-count-reactions'); if (r) r.textContent = `(${(d.tables.reactions ?? 0) + (d.tables.post_reactions ?? 0)})`;
                        const s = document.getElementById('dd-count-subscriptions'); if (s) s.textContent = `(${d.tables.subscriptions ?? 0})`;
                    }
                }).catch(()=>{});
        }

        function closeDeleteDataModal() {
            const m = document.getElementById('delete-data-modal');
            if (m) m.style.display = 'none';
        }

        function toggleDeleteDataSelectAll() {
            const allChecked = document.getElementById('dd-select-all').checked;
            ['comments','reactions','subscriptions'].forEach(k => {
                const el = document.getElementById('dd-'+k);
                if (el) el.checked = allChecked;
            });
            updateDeleteDataBtn();
        }

        function syncDeleteDataSelectAll() {
            const all = document.getElementById('dd-select-all');
            const c = document.getElementById('dd-comments').checked;
            const r = document.getElementById('dd-reactions').checked;
            const s = document.getElementById('dd-subscriptions').checked;
            if (all) all.checked = (c && r && s);
            updateDeleteDataBtn();
        }

        function updateDeleteDataBtn() {
            const btn = document.getElementById('dd-delete-btn');
            const conf = document.getElementById('dd-confirm');
            if (!btn || !conf) return;
            const anyChecked = ['comments','reactions','subscriptions'].some(k => document.getElementById('dd-'+k)?.checked);
            btn.disabled = !(anyChecked && conf.checked);
            if (conf) {
                conf.onchange = () => {
                    const anyCheckedNow = ['comments','reactions','subscriptions'].some(k => document.getElementById('dd-'+k)?.checked);
                    btn.disabled = !(anyCheckedNow && conf.checked);
                };
            }
        }

        async function runDeleteData() {
            const msgEl = document.getElementById('dd-message');
            const btn = document.getElementById('dd-delete-btn');
            if (!msgEl || !btn) return;

            const req = {
                csrf_token: AdminAuth.getCsrfToken(),
                delete_comments: document.getElementById('dd-comments').checked,
                delete_reactions: document.getElementById('dd-reactions').checked,
                delete_subscriptions: document.getElementById('dd-subscriptions').checked
            };

            btn.disabled = true;
            msgEl.innerHTML = '<div class="message info">Deleting data...</div>';

            try {
                await AdminAuth.ensureCsrfToken();
                req.csrf_token = AdminAuth.getCsrfToken();
                const r = await fetch(`${API_URL}?action=db_delete_data`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify(req) });
                const d = await r.json();

                if (r.ok) {
                    const parts = [];
                    if (d.deleted?.comments !== undefined) parts.push(`${d.deleted.comments} comment(s)`);
                    if (d.deleted?.reactions !== undefined) parts.push(`${d.deleted.reactions} reaction(s)`);
                    if (d.deleted?.subscriptions !== undefined) parts.push(`${d.deleted.subscriptions} subscription(s)`);

                    const resStr = parts.length > 0 ? parts.join(', ') : 'no data';
                    msgEl.innerHTML = `<div class="message success">Successfully deleted ${resStr}. Vacuuming database...</div>`;

                    await fetch(`${API_URL}?action=vacuum`, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body:JSON.stringify({csrf_token:AdminAuth.getCsrfToken()}) });

                    setTimeout(() => {
                        closeDeleteDataModal();
                        loadDbStats();
                        const pm = document.getElementById('db-message');
                        if (pm) { pm.innerHTML = `<div class="message success">Data deletion complete (${resStr}).</div>`; setTimeout(()=>pm.innerHTML='', 5000); }
                    }, 1500);
                } else {
                    msgEl.innerHTML = `<div class="message error">${d.error || 'Deletion failed'}</div>`;
                    btn.disabled = false;
                }
            } catch (e) {
                msgEl.innerHTML = '<div class="message error">Network error</div>';
                btn.disabled = false;
            }
        }

        hoistToWindow({
            loadDbStats, vacuumDb, deleteSpam,
            openDeleteDataModal, closeDeleteDataModal, toggleDeleteDataSelectAll, syncDeleteDataSelectAll, runDeleteData
        });

        loadDbStats();
        // Setup confirm checkbox listener
        const confCheckbox = document.getElementById('dd-confirm');
        if (confCheckbox) {
            confCheckbox.addEventListener('change', updateDeleteDataBtn);
        }
    }
};

VIEWS['settings-notifications'] = {
    title: 'Notification Settings',
    css: `
        .util-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
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
        .toggle-switch { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#ccc; border-radius:26px; transition:.3s; }
        .toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background-color:white; border-radius:50%; transition:.3s; }
        input:checked+.toggle-slider { background-color:#4a90e2; }
        input:checked+.toggle-slider:before { transform:translateX(20px); }
        .email-test-row { display:flex; flex-wrap:wrap; gap:.75rem; }
        .email-test-row input { flex:1 1 200px; }
    `,
    html: () => `
        <div class="container">
            <h2 style="margin-bottom: 1.5rem;">Notification Settings</h2>
            <div class="util-card">
                <div class="util-card-header"><span class="icon">🔔</span><h2>Notifications</h2></div>
                <div class="util-card-body">
                    <div id="settings-message"></div>
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

            <div class="util-card" style="margin-top: 1.5rem;">
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
    `,
    init({ hoistToWindow }) {
        async function loadSettings() {
            try {
                const r = await fetch(`${API_URL}?action=get_settings`, { credentials: 'include' });
                const d = await r.json();
                if (!r.ok) return;
                const s = d.settings;
                document.getElementById('setting-enable-notifications').checked = (s.enable_notifications === 'true');
                document.getElementById('setting-admin-email').value            = s.admin_email || '';
            } catch (e) { console.error('Settings load failed', e); }
        }

        document.getElementById('setting-enable-notifications')?.addEventListener('change', saveSettings);

        async function saveSettings() {
            const msgEl = document.getElementById('settings-message');
            await AdminAuth.ensureCsrfToken();
            try {
                const g = await fetch(`${API_URL}?action=get_settings`, { credentials: 'include' });
                const current = (await g.json()).settings || {};

                const payload = {
                    csrf_token:           AdminAuth.getCsrfToken(),
                    require_moderation:   current.require_moderation || 'false',
                    enable_notifications: document.getElementById('setting-enable-notifications').checked ? 'true' : 'false',
                    admin_email:          document.getElementById('setting-admin-email').value.trim(),
                    comment_sort_order:   current.comment_sort_order || 'desc',
                };

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

        async function sendTestEmail() {
            const addr = document.getElementById('test-email-addr')?.value.trim();
            const msgEl = document.getElementById('email-message');
            if(!addr) { if(msgEl) msgEl.innerHTML = '<div class="message error">Enter an email address.</div>'; return; }
            await AdminAuth.ensureCsrfToken();
            if(msgEl) msgEl.innerHTML = '<div class="message info">Sending…</div>';
            try {
                const r = await fetch(`${API_URL}?action=test_email`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ csrf_token: AdminAuth.getCsrfToken(), email: addr, page_url: '/' })
                });
                const d = await r.json();
                if(r.ok) { if(msgEl) msgEl.innerHTML = `<div class="message success">${d.message}</div>`; }
                else { if(msgEl) msgEl.innerHTML = `<div class="message error">${d.error}</div>`; }
            } catch(e) { if(msgEl) msgEl.innerHTML = '<div class="message error">Network error</div>'; }
        }

        hoistToWindow({ saveSettings, sendTestEmail });
        loadSettings();
    }
};

VIEWS['settings-import-export'] = {
    title: 'Import & Export Settings',
    css: `
        .util-card { background:var(--on-background); border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow:hidden; }
        .util-card-header { padding:1rem 1.5rem; border-bottom:1px solid var(--gray,#e9ecef); display:flex; align-items:center; gap:.6rem; }
        .util-card-header h2 { font-size:1.1rem; color:var(--body-text,#333); }
        .util-card-header .icon { font-size:1.2rem; }
        .util-card-body { padding:1.5rem; }
        .util-card-body p { color:var(--body-text,#666); opacity:.8; font-size:.9rem; margin-bottom:1rem; }
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
        .export-row { display:flex; align-items:center; justify-content:space-between; padding:.75rem 0; border-bottom:1px solid var(--gray,#f0f0f0); }
        .export-row:last-child { border-bottom:none; }
        .export-row .export-info strong { display:block; color:var(--body-text); font-size:.95rem; }
        .export-row .export-info span { font-size:.82rem; color:var(--body-text); opacity:.8; }
    `,
    html: () => `
        <div class="container">
            <h2 style="margin-bottom: 1.5rem;">Import & Export</h2>
            <div class="util-card">
                <div class="util-card-header"><span class="icon">📤</span><h2>Export Comments</h2></div>
                <div class="util-card-body">
                    <div class="export-row">
                        <div class="export-info"><strong>Comments Export XML</strong><span>Disqus-compatible format: all comments, reactions, subscriptions, IP addresses, and metadata</span></div>
                        <a href="../api.php?action=export_comments" class="btn btn-primary btn-sm">Download XML</a>
                    </div>
                    <div class="export-row" style="margin-top:1rem;">
                        <div class="export-info"><strong>Comments Export JSON</strong><span>Native format: all comments, reactions, subscriptions, IP addresses, and metadata</span></div>
                        <a href="../api.php?action=export_comments_json" class="btn btn-success btn-sm">Download JSON</a>
                    </div>
                    <div style="margin-top:1rem;"><div id="export-message"></div></div>
                </div>
            </div>

            <div class="util-card" style="margin-top: 1.5rem;">
                <div class="util-card-header"><span class="icon">📥</span><h2>Import Comments</h2></div>
                <div class="util-card-body">
                    <p>Import from a Comments Export file (XML or JSON), legacy project export, Disqus XML, or WordPress WXR. Native exports restore comments (all statuses), reactions, subscriptions, IP addresses, and metadata. Duplicate comments are skipped automatically.</p>
                    <div class="file-drop" id="file-drop" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                        <input type="file" id="import-file" accept=".xml,.json" onchange="handleFileSelect(event)">
                        <div class="drop-icon">📂</div>
                        <div class="drop-label">Drop XML or JSON file here or click to browse</div>
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
        </div>
    `,
    init({ hoistToWindow }) {
        let importFileContent = null;
        let importPreviewDone = false;

        function handleDragOver(e) { e.preventDefault(); e.stopPropagation(); document.getElementById('file-drop')?.classList.add('drag-over'); }
        function handleDragLeave(e) { e.preventDefault(); e.stopPropagation(); document.getElementById('file-drop')?.classList.remove('drag-over'); }
        function handleDrop(e) {
            e.preventDefault(); e.stopPropagation();
            const fd = document.getElementById('file-drop'); if (fd) fd.classList.remove('drag-over');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                const f = e.dataTransfer.files[0];
                document.getElementById('import-file').files = e.dataTransfer.files;
                processFile(f);
            }
        }
        function handleFileSelect(e) { if (e.target.files && e.target.files.length > 0) processFile(e.target.files[0]); }
        function processFile(file) {
            importFileContent = null; importPreviewDone = false;
            const bprev = document.getElementById('btn-preview'), bimp = document.getElementById('btn-import');
            if(bprev) bprev.disabled = true; if(bimp) bimp.disabled = true;
            document.getElementById('import-preview').style.display = 'none';
            document.getElementById('import-message').innerHTML = '';

            const flabel = document.getElementById('file-selected-label');
            if (!file.name.endsWith('.xml') && !file.name.endsWith('.json')) {
                if(flabel) { flabel.style.display = 'block'; flabel.style.color = '#dc3545'; flabel.textContent = 'Unsupported file type. Please select .xml or .json'; }
                return;
            }
            if(flabel) { flabel.style.display = 'block'; flabel.style.color = '#28a745'; flabel.textContent = `Selected: ${file.name} (${formatBytes(file.size)})`; }

            const r = new FileReader();
            r.onload = (e) => { importFileContent = e.target.result; if(bprev) bprev.disabled = false; if(bimp) bimp.disabled = false; };
            r.readAsText(file);
        }

        async function previewImport() {
            if (!importFileContent) return;
            const msgEl = document.getElementById('import-message');
            const prevEl = document.getElementById('import-preview');
            await AdminAuth.ensureCsrfToken();
            if (msgEl) msgEl.innerHTML = '<div class="message info">Analyzing file…</div>';
            if (prevEl) prevEl.style.display = 'none';
            try {
                const r = await fetch(`${API_URL}?action=import_comments&preview=1`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ csrf_token: AdminAuth.getCsrfToken(), content: importFileContent }) });
                const d = await r.json();
                if (r.ok) {
                    if (msgEl) msgEl.innerHTML = '';
                    if (prevEl) {
                        prevEl.style.display = 'block';
                        const formatName = (d.format === 'wxr') ? 'WordPress WXR' : (d.format === 'disqus') ? 'Disqus XML' : (d.format === 'native_json') ? 'Native JSON' : (d.format === 'legacy_json') ? 'Legacy JSON' : 'Comments Export XML';
                        prevEl.innerHTML = `
                            <strong>Preview (${formatName})</strong>
                            <table>
                                <tbody>
                                    <tr><td>Comments to import</td><td>${d.comments}</td></tr>
                                    <tr><td>Reactions to import</td><td>${d.reactions ?? 0}</td></tr>
                                    <tr><td>Post reactions to import</td><td>${d.post_reactions ?? 0}</td></tr>
                                    <tr><td>Subscriptions to import</td><td>${d.subscriptions ?? 0}</td></tr>
                                </tbody>
                            </table>
                            <div style="margin-top:.75rem;font-size:.85rem;color:#666;">Note: Duplicate comments will be automatically skipped during import.</div>
                        `;
                    }
                    importPreviewDone = true;
                } else { if (msgEl) msgEl.innerHTML = `<div class="message error">${d.error}</div>`; }
            } catch (e) { if (msgEl) msgEl.innerHTML = '<div class="message error">Network error analyzing file</div>'; }
        }

        async function runImport() {
            if (!importFileContent) return;
            if (!importPreviewDone) {
                if (!confirm('You are importing without previewing. Proceed?')) return;
            }
            const msgEl = document.getElementById('import-message');
            const statusEl = document.getElementById('import-status');
            const bimp = document.getElementById('btn-import');
            await AdminAuth.ensureCsrfToken();
            if(bimp) bimp.disabled = true;
            if(msgEl) msgEl.innerHTML = '';
            if(statusEl) statusEl.textContent = 'Importing... this may take a moment for large files.';
            try {
                const r = await fetch(`${API_URL}?action=import_comments`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ csrf_token: AdminAuth.getCsrfToken(), content: importFileContent }) });
                const d = await r.json();
                if(statusEl) statusEl.textContent = '';
                if(r.ok) {
                    const parts = [];
                    if(d.imported > 0) parts.push(`${d.imported} comment${d.imported !== 1 ? 's' : ''} across ${d.unique_pages} page${d.unique_pages !== 1 ? 's' : ''}`);
                    if((d.reactions_imported ?? 0) > 0) parts.push(`${d.reactions_imported} comment reaction${d.reactions_imported !== 1 ? 's' : ''}`);
                    if((d.post_reactions_imported ?? 0) > 0) parts.push(`${d.post_reactions_imported} post reaction${d.post_reactions_imported !== 1 ? 's' : ''}`);
                    if((d.subscriptions_imported ?? 0) > 0) parts.push(`${d.subscriptions_imported} subscription${d.subscriptions_imported !== 1 ? 's' : ''}`);
                    const dupNote = d.skipped_duplicates > 0 ? ` (${d.skipped_duplicates} duplicate comments skipped)` : '';
                    if(msgEl) msgEl.innerHTML = `<div class="message success">Imported ${parts.length ? parts.join(', ') : 'no new items'}${dupNote}.</div>`;
                    const iprev = document.getElementById('import-preview'); if(iprev) iprev.style.display = 'none';
                    importFileContent = null; importPreviewDone = false;
                    const bprev = document.getElementById('btn-preview'); if(bprev) bprev.disabled = true;
                    const flabel = document.getElementById('file-selected-label'); if(flabel) flabel.style.display = 'none';
                } else {
                    if(msgEl) msgEl.innerHTML = `<div class="message error">${d.error}</div>`;
                    if(bimp) bimp.disabled = false;
                }
            } catch(e) {
                if(msgEl) msgEl.innerHTML = '<div class="message error">Network error</div>';
                if(statusEl) statusEl.textContent = '';
                if(bimp) bimp.disabled = false;
            }
        }

        hoistToWindow({ handleDragOver, handleDragLeave, handleDrop, handleFileSelect, previewImport, runImport });
    }
};

// Sidebar toggle logic
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const mobileToggleBtn = document.getElementById('mobile-sidebar-toggle');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }

    if (mobileToggleBtn && sidebar) {
        mobileToggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-active');
        });
    }
});

// Update the existing mobile close logic
document.getElementById('admin-nav').addEventListener('click', function(e) {
    if (e.target.closest('a') && window.innerWidth <= 768) {
        document.getElementById('admin-sidebar').classList.remove('mobile-active');
    }
});
