<?php
/**
 * Setup Script - Run this once to initialize the comment system
 *
 * Usage: php setup.php
 * Or visit: https://yourdomain.com/comments/setup.php
 */

require_once 'config.php';
require_once 'database.php';

// Check if running from CLI or web
$isCLI = php_sapi_name() === 'cli';

function output($message, $isCLI) {
    if ($isCLI) {
        echo $message . "\n";
    } else {
        echo htmlspecialchars($message) . "<br>\n";
    }
}

if (!$isCLI) {
    echo '<!DOCTYPE html><html><head><title>Setup Comment System</title></head><body>';
    echo '<h1>Comment System Setup</h1>';
    echo '<pre>';
}

output("=== Comment System Setup ===", $isCLI);
output("", $isCLI);

// Step 1: Initialize database
output("Step 1: Initializing database...", $isCLI);
if (file_exists(DB_PATH)) {
    output("  Database already exists at: " . DB_PATH, $isCLI);
} else {
    if (initDatabase()) {
        output("  ✓ Database created successfully", $isCLI);
    } else {
        output("  ✗ Failed to create database", $isCLI);
        exit(1);
    }
}

// Step 2: Check database connection
output("", $isCLI);
output("Step 2: Testing database connection...", $isCLI);
$db = getDatabase();
if ($db) {
    output("  ✓ Database connection successful", $isCLI);
} else {
    output("  ✗ Database connection failed", $isCLI);
    exit(1);
}

// Step 3: Set admin password
output("", $isCLI);
output("Step 3: Setting admin password...", $isCLI);

$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password_hash'");
$stmt->execute();
$result = $stmt->fetch();

if ($result && !empty($result['value'])) {
    output("  Admin password already set", $isCLI);
    output("  To change it, run: php set-password.php", $isCLI);
} else {
    // Set default password
    $defaultPassword = 'admin';
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('admin_password_hash', ?)");
    $stmt->execute([$hash]);

    output("  ✓ Default admin password set", $isCLI);
    output("  Default password: admin", $isCLI);
    output("  ⚠ IMPORTANT: Change this password immediately!", $isCLI);
}

// Step 4: Check file permissions
output("", $isCLI);
output("Step 4: Checking file permissions...", $isCLI);

if (is_writable(dirname(DB_PATH))) {
    output("  ✓ Database directory is writable", $isCLI);
} else {
    output("  ✗ Database directory is not writable", $isCLI);
    output("  Run: chmod 755 " . dirname(DB_PATH), $isCLI);
}

if (file_exists(DB_PATH)) {
    if (is_writable(DB_PATH)) {
        output("  ✓ Database file is writable", $isCLI);
    } else {
        output("  ✗ Database file is not writable", $isCLI);
        output("  Run: chmod 666 " . DB_PATH, $isCLI);
    }
}

// Step 5: Check PHP extensions
output("", $isCLI);
output("Step 5: Checking PHP extensions...", $isCLI);

$required = ['pdo_sqlite', 'json'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        output("  ✓ $ext extension loaded", $isCLI);
    } else {
        output("  ✗ $ext extension not loaded", $isCLI);
    }
}

// Step 6: Display configuration
output("", $isCLI);
output("Step 6: Configuration summary...", $isCLI);
output("  Database path: " . DB_PATH, $isCLI);
output("  Allowed origins: " . implode(', ', ALLOWED_ORIGINS), $isCLI);

// Step 7: Test query
output("", $isCLI);
output("Step 7: Testing database queries...", $isCLI);
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM comments");
    $count = $stmt->fetch();
    output("  ✓ Database queries working", $isCLI);
    output("  Current comment count: " . $count['count'], $isCLI);
} catch (PDOException $e) {
    output("  ✗ Database query failed: " . $e->getMessage(), $isCLI);
}

// Final instructions
output("", $isCLI);
output("=== Setup Complete ===", $isCLI);
output("", $isCLI);
output("Next steps:", $isCLI);
output("1. Change admin password at: admin/index.html", $isCLI);
output("2. Update ALLOWED_ORIGINS in config.php", $isCLI);
output("3. Add the Hugo shortcode to your site", $isCLI);
output("4. Test by visiting a page with comments enabled", $isCLI);
output("", $isCLI);
output("Admin panel: admin/index.html", $isCLI);
output("API endpoint: api.php", $isCLI);

if (!$isCLI) {
    echo '</pre>';
    echo '<p><a href="admin/index.html">Go to Admin Panel</a></p>';
    echo '</body></html>';
}
