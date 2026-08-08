<?php
// Database helper functions

// Load config.php if it exists, otherwise use defaults
if (file_exists(__DIR__ . '/config.php')) {
    require_once 'config.php';
}

// Function to load config from database settings
function loadConfigFromDatabase() {
    $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/comments.db';
    if (!file_exists($dbPath)) {
        return false;
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Load app_url
        if (!defined('APP_URL')) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'app_url'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['value'])) {
                define('APP_URL', $result['value']);
            }
        }
        
        // Load allowed_origins
        if (!defined('ALLOWED_ORIGINS')) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'allowed_origins'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['value'])) {
                $origins = json_decode($result['value'], true);
                if (is_array($origins)) {
                    define('ALLOWED_ORIGINS', $origins);
                }
            }
        }
        
        // Load timezone
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'timezone'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['value'])) {
            date_default_timezone_set($result['value']);
        }
        
        // Load app_language
        if (!defined('APP_LANGUAGE')) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'app_language'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['value'])) {
                define('APP_LANGUAGE', $result['value']);
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Failed to load config from database: ' . $e->getMessage());
        return false;
    }
}

// Try to load config from database if not already defined
loadConfigFromDatabase();

// Define DB_PATH if not already defined
if (!defined('DB_PATH')) {
    // Auto-detect environment
    $isLocalhost = false;
    if (getenv('COMMENT_ENV') === 'development') {
        $isLocalhost = true;
    } elseif (isset($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        $isLocalhost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            strpos($host, '.local') !== false ||
            strpos($host, ':1313') !== false
        );
    } elseif (php_sapi_name() === 'cli-server') {
        $isLocalhost = true;
    }

    define('DB_PATH', __DIR__ . ($isLocalhost ? '/db/comments-dev.db' : '/db/comments.db'));
}

function getDatabase() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Ensure UTF-8 encoding for SQLite
        $db->exec('PRAGMA encoding = "UTF-8"');
        // Enable foreign key constraints in SQLite
        $db->exec('PRAGMA foreign_keys = ON');
        // Set busy timeout to 30 seconds to handle database locks
        $db->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $db->exec('PRAGMA busy_timeout = 30000');
        // Enable WAL mode for better concurrency
        $db->exec('PRAGMA journal_mode = WAL');
        // Performance tuning
        $db->exec('PRAGMA cache_size = -8000');    // 8MB page cache
        $db->exec('PRAGMA temp_store = MEMORY');   // temp tables in RAM
        $db->exec('PRAGMA mmap_size = 134217728'); // 128MB memory-mapped I/O
        return $db;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

function tableExists($db, $tableName) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$tableName]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function initDatabase() {
    $db = getDatabase();
    if (!$db) return false;

    // Check if schema.sql exists, otherwise use inline schema
    $schemaFile = __DIR__ . '/src/Database/schema.sql';
    if (file_exists($schemaFile)) {
        $schema = file_get_contents($schemaFile);
    } else {
        // Inline schema for deployment
        $schema = "
            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                parent_id INTEGER DEFAULT NULL,
                author_name TEXT NOT NULL,
                author_email TEXT NOT NULL,
                author_url TEXT DEFAULT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'spam', 'deleted')),
                ip_address TEXT,
                user_agent TEXT,
                FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_page_url ON comments(page_url);
            CREATE INDEX IF NOT EXISTS idx_parent_id ON comments(parent_id);
            CREATE INDEX IF NOT EXISTS idx_status ON comments(status);
            CREATE INDEX IF NOT EXISTS idx_created_at ON comments(created_at);

            CREATE INDEX IF NOT EXISTS idx_ip_address ON comments(ip_address);
            CREATE INDEX IF NOT EXISTS idx_author_email ON comments(author_email);
            CREATE INDEX IF NOT EXISTS idx_rate_limit_ip ON comments(ip_address, created_at);
            CREATE INDEX IF NOT EXISTS idx_rate_limit_email ON comments(author_email, created_at);
            CREATE INDEX IF NOT EXISTS idx_page_url_status ON comments(page_url, status);
            CREATE INDEX IF NOT EXISTS idx_author_email_status ON comments(author_email, status);

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            INSERT OR IGNORE INTO settings (key, value) VALUES
                ('require_moderation', 'true'),
                ('allow_guest_comments', 'true'),
                ('max_comment_length', '5000'),
                ('enable_notifications', 'false'),
                ('admin_email', ''),
                ('schema_version', '0');

            CREATE TABLE IF NOT EXISTS subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                email TEXT NOT NULL,
                token TEXT UNIQUE NOT NULL,
                subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                UNIQUE(page_url, email)
            );

            CREATE INDEX IF NOT EXISTS idx_sub_page_url ON subscriptions(page_url);
            CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email);
            CREATE INDEX IF NOT EXISTS idx_sub_token ON subscriptions(token);

            CREATE TABLE IF NOT EXISTS email_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                comment_id INTEGER,
                recipient_email TEXT NOT NULL,
                recipient_name TEXT,
                email_type TEXT NOT NULL,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'failed')),
                attempts INTEGER DEFAULT 0,
                last_error TEXT,
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_email_queue_status ON email_queue(status, created_at);
            CREATE INDEX IF NOT EXISTS idx_email_queue_comment ON email_queue(comment_id);

            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                success INTEGER DEFAULT 0
            );

            CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address, attempted_at);

            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                ip_address TEXT,
                user_agent TEXT
            );

            CREATE INDEX IF NOT EXISTS idx_session_token ON sessions(token);
            CREATE INDEX IF NOT EXISTS idx_session_expires ON sessions(expires_at);

            CREATE TABLE IF NOT EXISTS votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                comment_id INTEGER NOT NULL,
                ip_address TEXT NOT NULL,
                reaction_type TEXT NOT NULL DEFAULT 'heart',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
                UNIQUE(comment_id, ip_address, reaction_type)
            );

            CREATE INDEX IF NOT EXISTS idx_votes_comment ON votes(comment_id);

            CREATE TABLE IF NOT EXISTS vote_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_vote_log_ip ON vote_log(ip_address, created_at);

            CREATE TABLE IF NOT EXISTS post_reactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                reaction_type TEXT NOT NULL DEFAULT 'heart',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(page_url, ip_address, reaction_type)
            );

            CREATE INDEX IF NOT EXISTS idx_post_reactions_page ON post_reactions(page_url);
        ";
    }

    try {
        $db->exec($schema);
        return true;
    } catch (PDOException $e) {
        error_log('Database initialization failed: ' . $e->getMessage());
        return false;
    }
}

// Bump this when adding new migrations so existing installs skip the full migration block
define('CURRENT_SCHEMA_VERSION', '2');

function migrateDatabase() {
    $db = getDatabase();
    if (!$db) return false;

    try {
        // Fast path: if schema is already current, skip all migration checks
        $versionStmt = $db->query("SELECT value FROM settings WHERE key = 'schema_version'");
        $storedVersion = $versionStmt ? $versionStmt->fetchColumn() : '0';
        if ($storedVersion === CURRENT_SCHEMA_VERSION) {
            // Still run probabilistic cleanup even on fast path
            if (rand(1, 20) === 1) {
                $db->exec("DELETE FROM vote_log WHERE created_at < datetime('now', '-1 hour')");
                $db->exec("DELETE FROM sessions WHERE expires_at < datetime('now')");
                $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-7 days')");
            }
            return true;
        }

        // Check if subscriptions table exists, if not create it
        if (!tableExists($db, 'subscriptions')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    page_url TEXT NOT NULL,
                    email TEXT NOT NULL,
                    token TEXT UNIQUE NOT NULL,
                    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    active INTEGER DEFAULT 1,
                    UNIQUE(page_url, email)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_page_url ON subscriptions(page_url)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_token ON subscriptions(token)");
            error_log('Database migration: subscriptions table created');
        }

        // Add performance indexes (safe to run multiple times due to IF NOT EXISTS)
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_address ON comments(ip_address)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_author_email ON comments(author_email)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_ip ON comments(ip_address, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_email ON comments(author_email, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_page_url_status ON comments(page_url, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_author_email_status ON comments(author_email, status)");

        // Create email queue table if it doesn't exist
        if (!tableExists($db, 'email_queue')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS email_queue (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    comment_id INTEGER,
                    recipient_email TEXT NOT NULL,
                    recipient_name TEXT,
                    email_type TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME,
                    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'failed')),
                    attempts INTEGER DEFAULT 0,
                    last_error TEXT,
                    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_email_queue_status ON email_queue(status, created_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_email_queue_comment ON email_queue(comment_id)");
            error_log('Database migration: email_queue table created');
        }

        // Create login attempts table if it doesn't exist
        if (!tableExists($db, 'login_attempts')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    success INTEGER DEFAULT 0
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address, attempted_at)");
            error_log('Database migration: login_attempts table created');
        }

        // Create sessions table if it doesn't exist
        if (!tableExists($db, 'sessions')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token TEXT UNIQUE NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                    ip_address TEXT,
                    user_agent TEXT
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_session_token ON sessions(token)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_session_expires ON sessions(expires_at)");
            error_log('Database migration: sessions table created');
        }

        // Create votes table if it doesn't exist
        if (!tableExists($db, 'votes')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS votes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    comment_id INTEGER NOT NULL,
                    ip_address TEXT NOT NULL,
                    reaction_type TEXT NOT NULL DEFAULT 'heart',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
                    UNIQUE(comment_id, ip_address, reaction_type)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_votes_comment ON votes(comment_id)");
            error_log('Database migration: votes table created');
        } else {
            // Migrate existing votes table to add reaction_type if missing
            $pragma = $db->query("PRAGMA table_info(votes)")->fetchAll();
            $hasReactionType = false;
            foreach ($pragma as $col) {
                if ($col['name'] === 'reaction_type') { $hasReactionType = true; break; }
            }
            if (!$hasReactionType) {
                $db->exec("
                    CREATE TABLE votes_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        comment_id INTEGER NOT NULL,
                        ip_address TEXT NOT NULL,
                        reaction_type TEXT NOT NULL DEFAULT 'heart',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
                        UNIQUE(comment_id, ip_address, reaction_type)
                    )
                ");
                $db->exec("INSERT INTO votes_new (id, comment_id, ip_address, reaction_type, created_at)
                    SELECT id, comment_id, ip_address, 'heart', created_at FROM votes");
                $db->exec("DROP TABLE votes");
                $db->exec("ALTER TABLE votes_new RENAME TO votes");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_votes_comment ON votes(comment_id)");
                error_log('Database migration: votes table updated with reaction_type');
            }
        }

        // Create vote_log table if it doesn't exist
        if (!tableExists($db, 'vote_log')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS vote_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_vote_log_ip ON vote_log(ip_address, created_at)");
            error_log('Database migration: vote_log table created');
        }

        // Create post_reactions table if it doesn't exist
        if (!tableExists($db, 'post_reactions')) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS post_reactions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    page_url TEXT NOT NULL,
                    ip_address TEXT NOT NULL,
                    reaction_type TEXT NOT NULL DEFAULT 'heart',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(page_url, ip_address, reaction_type)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_post_reactions_page ON post_reactions(page_url)");
            error_log('Database migration: post_reactions table created');
        }

        // Clean up old vote_log entries (5% chance)
        if (rand(1, 20) === 1) {
            $db->exec("DELETE FROM vote_log WHERE created_at < datetime('now', '-1 hour')");
        }

        // Clean up expired sessions (5% chance to avoid overhead on every request)
        if (rand(1, 20) === 1) {
            $db->exec("DELETE FROM sessions WHERE expires_at < datetime('now')");
        }

        // Clean up old login attempts (5% chance)
        if (rand(1, 20) === 1) {
            $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-7 days')");
        }

        // Update query planner statistics and mark schema as current
        $db->exec("ANALYZE");
        $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '" . CURRENT_SCHEMA_VERSION . "')");

        return true;
    } catch (PDOException $e) {
        error_log('Database migration failed: ' . $e->getMessage());
        return false;
    }
}

// Initialize database if it doesn't exist
if (!file_exists(DB_PATH)) {
    // Ensure db directory exists
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    // Check if a default template database exists
    $defaultDbPath = __DIR__ . '/db/comments-default.db';
    if (file_exists($defaultDbPath)) {
        // Copy the default database instead of creating from scratch
        copy($defaultDbPath, DB_PATH);
        error_log('Database initialized from db/comments-default.db template');
    } else {
        // Create new database from schema
        initDatabase();
        error_log('Database initialized from schema');
    }
} else {
    // Run migrations on existing database
    migrateDatabase();
}
