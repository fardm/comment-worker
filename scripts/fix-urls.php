<?php
/**
 * Fix TalkYard URL Mapping
 *
 * This script fixes the mangled URLs from TalkYard import
 * Usage: php fix-urls.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

$db = getDatabase();
if (!$db) {
    echo "Error: Could not connect to database\n";
    exit(1);
}

echo "Fixing TalkYard URLs...\n\n";

// Get all unique URLs that need fixing
$stmt = $db->query("SELECT DISTINCT page_url FROM comments ORDER BY page_url");
$urls = $stmt->fetchAll(PDO::FETCH_COLUMN);

$fixed = 0;
$skipped = 0;

foreach ($urls as $url) {
    $newUrl = $url;

    // Fix URLs that start with httpdarcynormannet (missing ://)
    if (preg_match('/^httpdarcynormannet(.+)/', $url, $matches)) {
        $path = $matches[1];

        // Extract date pattern: YYYYMMDD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(.+)/', $path, $dateMatch)) {
            $year = $dateMatch[1];
            $month = $dateMatch[2];
            $day = $dateMatch[3];
            $slug = $dateMatch[4];

            // Convert remaining slashes back to dashes for slug
            $slug = str_replace('/', '-', trim($slug, '/'));

            // Build proper URL: /YYYY/MM/DD/slug/
            $newUrl = "/{$year}/{$month}/{$day}/{$slug}/";

            echo "Fix: {$url}\n";
            echo " ->  {$newUrl}\n\n";

            // Update all comments with this URL
            $updateStmt = $db->prepare("UPDATE comments SET page_url = ? WHERE page_url = ?");
            $updateStmt->execute([$newUrl, $url]);
            $fixed += $updateStmt->rowCount();
        }
    }
    // Fix TalkYard internal URLs like /-5/imported-from-disqus
    else if (preg_match('/^\/-\d+\/imported-from-disqus$/', $url)) {
        echo "Skip (TalkYard internal): {$url}\n";
        $skipped++;
    }
    else {
        echo "OK: {$url}\n";
    }
}

echo "\n=== Summary ===\n";
echo "URLs fixed: {$fixed} comments updated\n";
echo "Skipped: {$skipped} TalkYard internal pages\n";

// Show updated URL distribution
echo "\n=== Updated URL Distribution ===\n";
$stmt = $db->query("
    SELECT page_url, COUNT(*) as count
    FROM comments
    WHERE page_url NOT LIKE '/-%%'
    GROUP BY page_url
    ORDER BY count DESC
    LIMIT 20
");

while ($row = $stmt->fetch()) {
    echo "  {$row['page_url']}: {$row['count']} comments\n";
}

echo "\n✓ URL fix complete!\n";
