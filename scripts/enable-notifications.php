<?php
// Enable email notifications and set admin email

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$db = getDatabase();
if (!$db) {
    die("Database connection failed\n");
}

echo "=== Email Notification Configuration ===\n\n";

// Get admin email
echo "Enter admin email address (or press Enter to skip): ";
$adminEmail = trim(fgets(STDIN));

if (!empty($adminEmail)) {
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        die("Invalid email address\n");
    }

    $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = 'admin_email'");
    $stmt->execute([$adminEmail]);
    echo "✓ Admin email set to: $adminEmail\n";
} else {
    echo "- Admin email not changed\n";
}

// Enable notifications
$stmt = $db->prepare("UPDATE settings SET value = 'true' WHERE key = 'enable_notifications'");
$stmt->execute();
echo "✓ Email notifications ENABLED\n\n";

// Show current settings
echo "Current settings:\n";
$stmt = $db->query("SELECT key, value FROM settings WHERE key IN ('enable_notifications', 'admin_email')");
while ($row = $stmt->fetch()) {
    echo "  {$row['key']}: {$row['value']}\n";
}

echo "\n=== Important Notes ===\n";
echo "1. Notifications only sent for NEW comments (not your first one)\n";
echo "2. You won't receive notifications for your own comments\n";
echo "3. PHP's mail() function must be configured on your server\n";
echo "4. Test by posting a SECOND comment on the same page\n";
echo "\nDone!\n";
