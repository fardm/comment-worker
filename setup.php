<?php
/**
 * Setup Wizard
 * 
 * This script guides users through the initial installation of the comment system.
 * It collects configuration values and creates the admin password.
 * 
 * After successful completion:
 * - Configuration is saved to database settings
 * - config.php is created for backward compatibility
 */

// Start session for storing setup data (must be before loading database.php to avoid config.php interference)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database functions to use getDatabase() and initDatabase()
require_once __DIR__ . '/database.php';

// Prevent access if setup is already complete
function isSetupComplete() {
    $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/comments.db';
    if (!file_exists($dbPath)) {
        return false;
    }
    
    $db = getDatabase();
    if (!$db) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password_hash'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result && !empty($result['value']);
    } catch (PDOException $e) {
        return false;
    }
}

// Save configuration to database
function saveConfigToDatabase($db, $config) {
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        
        $stmt->execute(['app_url', $config['app_url']]);
        $stmt->execute(['allowed_origins', json_encode($config['allowed_origins'])]);
        $stmt->execute(['timezone', $config['timezone']]);
        $stmt->execute(['app_language', $config['app_language']]);
        
        return true;
    } catch (PDOException $e) {
        error_log('Failed to save config to database: ' . $e->getMessage());
        return false;
    }
}

// Save admin password to database
function saveAdminPassword($db, $password) {
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('admin_password_hash', ?)");
        $stmt->execute([$hash]);

        // Clear any existing admin token (same as set-password.php)
        $stmt = $db->prepare("DELETE FROM settings WHERE key = 'admin_token'");
        $stmt->execute();

        return true;
    } catch (PDOException $e) {
        error_log('Failed to save admin password: ' . $e->getMessage());
        return false;
    }
}

// Create config.php file
function createConfigFile($config) {
    $content = "<?php\n\n";
    $content .= "// Define the base URL where the comment system is installed. Do not include a trailing slash.\n";
    $content .= "define('APP_URL', '" . addslashes($config['app_url']) . "');\n\n";

    $content .= "// Add your domain\n";
    $content .= "define('ALLOWED_ORIGINS', " . var_export(array_values($config['allowed_origins']), true) . ");\n\n";

    $content .= "// Set timezone\n";
    $content .= "date_default_timezone_set('" . addslashes($config['timezone']) . "');\n\n";

    $content .= "// Frontend comment widget language: 'en' or 'fa'\n";
    $content .= "define('APP_LANGUAGE', '" . addslashes($config['app_language']) . "');\n";

    $result = file_put_contents(__DIR__ . '/config.php', $content);
    if ($result === false) {
        error_log('Failed to write config.php: ' . error_get_last()['message'] ?? 'Unknown error');
    }
    return $result !== false;
}

// Get list of available timezones
function getTimezones() {
    return [
        'UTC' => 'UTC',
        'America/New_York' => 'America/New_York (Eastern Time)',
        'America/Chicago' => 'America/Chicago (Central Time)',
        'America/Denver' => 'America/Denver (Mountain Time)',
        'America/Los_Angeles' => 'America/Los_Angeles (Pacific Time)',
        'Europe/London' => 'Europe/London (GMT)',
        'Europe/Paris' => 'Europe/Paris (Central European)',
        'Europe/Berlin' => 'Europe/Berlin (Central European)',
        'Asia/Tehran' => 'Asia/Tehran (Iran)',
        'Asia/Dubai' => 'Asia/Dubai (Gulf)',
        'Asia/Tokyo' => 'Asia/Tokyo (Japan)',
        'Asia/Shanghai' => 'Asia/Shanghai (China)',
        'Australia/Sydney' => 'Australia/Sydney (Australian Eastern)',
    ];
}

// Detect current URL for default value
function detectCurrentUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $dir;
}

// Main setup logic
$message = '';
$error = '';
$step = isset($_POST['step']) ? (int)$_POST['step'] : (isset($_GET['step']) ? (int)$_GET['step'] : 1);
$setupComplete = isSetupComplete();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure database directory exists
    $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/comments.db';
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }
    
    // Initialize database - ensure it exists first
    if (!file_exists($dbPath)) {
        // Try to create the database file by getting a connection
        $testDb = getDatabase();
        if (!$testDb) {
            $error = 'Failed to create database file. Please check permissions and ensure the db directory is writable.';
        } else {
            // Now run the schema initialization
            if (!initDatabase()) {
                $error = 'Failed to initialize database schema.';
            }
        }
    }
    
    // Get fresh database connection for operations
    $db = getDatabase();
    if (!$db) {
        $error = 'Failed to connect to database. Please check permissions and ensure the db directory is writable.';
    } else {
        // Verify settings table exists and is accessible
        try {
            $testStmt = $db->prepare("SELECT 1 FROM settings WHERE key = 'admin_password_hash'");
            $testStmt->execute();
        } catch (PDOException $e) {
            $error = 'Database settings table is not accessible. Please ensure database was initialized correctly.';
            $db = null;
        }
    }
    
    if ($db) {
        switch ($step) {
            case 1:
                // Validate app URL
                $appUrl = trim($_POST['app_url'] ?? '');
                if (empty($appUrl)) {
                    $error = 'Application URL is required.';
                } elseif (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
                    $error = 'Please enter a valid URL.';
                } else {
                    $_SESSION['setup_app_url'] = $appUrl;
                    $step = 2;
                }
                break;
                
            case 2:
                // Validate allowed origins
                $originsInput = trim($_POST['allowed_origins'] ?? '');
                $origins = array_filter(array_map('trim', explode(',', $originsInput)));
                if (empty($origins)) {
                    $error = 'At least one allowed origin is required.';
                } else {
                    foreach ($origins as $origin) {
                        if (!filter_var($origin, FILTER_VALIDATE_URL)) {
                            $error = "Invalid origin: $origin";
                            break 2;
                        }
                    }
                    $_SESSION['setup_allowed_origins'] = $origins;
                    $step = 3;
                }
                break;
                
            case 3:
                // Validate timezone
                $timezone = trim($_POST['timezone'] ?? 'UTC');
                $timezones = getTimezones();
                if (!isset($timezones[$timezone])) {
                    $error = 'Invalid timezone selected.';
                } else {
                    $_SESSION['setup_timezone'] = $timezone;
                    $step = 4;
                }
                break;
                
            case 4:
                // Validate language
                $language = trim($_POST['app_language'] ?? 'en');
                if (!in_array($language, ['en', 'fa'])) {
                    $error = 'Invalid language selected.';
                } else {
                    $_SESSION['setup_app_language'] = $language;
                    $step = 5;
                }
                break;
                
            case 5:
                // Validate and save admin password
                $password = trim($_POST['password'] ?? '');
                $confirm = trim($_POST['confirm_password'] ?? '');
                
                if (empty($password)) {
                    $error = 'Password cannot be empty.';
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    // Compile all configuration
                    $config = [
                        'app_url' => $_SESSION['setup_app_url'] ?? detectCurrentUrl(),
                        'allowed_origins' => $_SESSION['setup_allowed_origins'] ?? [detectCurrentUrl()],
                        'timezone' => $_SESSION['setup_timezone'] ?? 'UTC',
                        'app_language' => $_SESSION['setup_app_language'] ?? 'en',
                    ];
                    
                    // Save to database
                    if (!saveConfigToDatabase($db, $config)) {
                        $error = 'Failed to save configuration to database.';
                    } elseif (!saveAdminPassword($db, $password)) {
                        $error = 'Failed to save admin password.';
                    } elseif (!createConfigFile($config)) {
                        $error = 'Failed to create config.php file. Please check write permissions.';
                    } else {
                        // Clear session if it exists
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_unset();
                            session_destroy();
                        }
                        
                        // Show success message
                        $message = 'Setup completed successfully! You can now delete setup.php for security.';
                        $setupComplete = true;
                    }
                }
                break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - Comment System</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }
        .back-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            margin-bottom: 10px;
            transition: opacity 0.3s;
            opacity: 0.7;
        }
        .back-btn.hidden {
            visibility: hidden;
            cursor: default;
        }
        .back-btn:hover {
            opacity: 1;
        }
        .back-btn svg {
            width: 24px;
            height: 24px;
            stroke: #555;
            stroke-width: 2;
            fill: none;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
        }
        .progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .progress::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 0;
            transform: translateY(-50%);
        }
        .step-indicator {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: #666;
            position: relative;
            z-index: 1;
        }
        .step-indicator.active {
            background: #667eea;
            color: white;
        }
        .step-indicator.completed {
            background: #4caf50;
            color: white;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            font-weight: normal;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        input:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        .password-wrapper {
            position: relative;
        }
        .toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 12px;
            padding: 5px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #e0e0e0;
            color: #333;
            margin-top: 10px;
        }
        .btn-secondary:hover {
            background: #d0d0d0;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        .success {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #3c3;
        }
        .header {
            margin-bottom: 10px;
        }
        .header h1 {
            text-align: center;
        }
        .step-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <button type="button" class="back-btn<?php echo ($step == 1 || $setupComplete) ? ' hidden' : ''; ?>" onclick="goBack()">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
        <div class="header">
            <h1>🚀 Setup Wizard</h1>
        </div>
        <p class="subtitle">Configure your comment system in a few simple steps</p>
        
        <div class="progress">
            <div class="step-indicator <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">1</div>
            <div class="step-indicator <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">2</div>
            <div class="step-indicator <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">3</div>
            <div class="step-indicator <?php echo $step >= 4 ? ($step > 4 ? 'completed' : 'active') : ''; ?>">4</div>
            <div class="step-indicator <?php echo $step >= 5 ? ($step > 5 ? 'completed' : 'active') : ''; ?>">5</div>
        </div>
        
        <?php if ($setupComplete): ?>
            <div class="warning">
                <strong>⚠️ Setup Already Complete</strong><br>
                The comment system has already been configured. For security, you should delete this file from your server.
            </div>
            <?php if ($message): ?>
                <div class="success">✓ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <p><a href="admin/" class="btn btn-primary" style="display: block; text-decoration: none; text-align: center;">Go to Admin Panel</a></p>
        <?php else: ?>
            <form method="post">
            <input type="hidden" name="step" value="<?php echo $step; ?>">
            
            <?php if ($step == 1): ?>
                <div class="step-title">Application URL</div>
                <?php if ($error): ?>
                    <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <p class="help-text">The URL where this comment system is installed (no trailing slash)</p>
                <input type="text" name="app_url" id="app_url" value="<?php echo htmlspecialchars($_SESSION['setup_app_url'] ?? detectCurrentUrl()); ?>" required>
                
            <?php elseif ($step == 2): ?>
                <div class="step-title">Allowed Origins</div>
                <?php if ($error): ?>
                    <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <p class="help-text">Comma-separated list of domains allowed to embed comments (CORS)</p>
                <input type="text" name="allowed_origins" id="allowed_origins" placeholder="https://example.com" required>
                
            <?php elseif ($step == 3): ?>
                <div class="step-title">Timezone</div>
                <?php if ($error): ?>
                    <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <p class="help-text">Choose the timezone for comment timestamps</p>
                <select name="timezone" id="timezone" required>
                    <?php foreach (getTimezones() as $tz => $label): ?>
                        <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo (($_SESSION['setup_timezone'] ?? 'UTC') === $tz) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
            <?php elseif ($step == 4): ?>
                <div class="step-title">Language</div>
                <?php if ($error): ?>
                    <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <p class="help-text">Language for the comment widget interface</p>
                <select name="app_language" id="app_language" required>
                    <option value="en" <?php echo (($_SESSION['setup_app_language'] ?? 'en') === 'en') ? 'selected' : ''; ?>>English</option>
                    <option value="fa" <?php echo (($_SESSION['setup_app_language'] ?? 'en') === 'fa') ? 'selected' : ''; ?>>فارسی (Persian)</option>
                </select>
                
            <?php elseif ($step == 5): ?>
                <div class="step-title">Admin Password</div>
                <?php if ($error): ?>
                    <div class="error">✗ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <p class="help-text">Minimum 8 characters. This will be used to access the admin panel.</p>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword('password', this)">Show</button>
                </div>
                
                <p class="help-text">Confirm your password</p>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword('confirm_password', this)">Show</button>
                </div>
                
            <?php endif; ?>
            
            <button type="submit" class="btn btn-primary">
                <?php echo $step == 5 ? 'Complete Setup' : 'Continue'; ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
    
    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = 'Hide';
            } else {
                input.type = 'password';
                button.textContent = 'Show';
            }
        }
        
        function goBack() {
            const form = document.querySelector('form');
            const stepInput = form.querySelector('input[name="step"]');
            const currentStep = parseInt(stepInput.value);
            const newStep = currentStep - 1;
            window.location.href = window.location.pathname + '?step=' + newStep;
        }
    </script>
</body>
</html>
