#!/bin/bash
# Test .htaccess security protections
# Run this on the server after deployment

echo "=== .htaccess Security Test ==="
echo ""

# Base URL - adjust as needed
BASE_URL="${1:-https://darcynorman.net/comments}"

echo "Testing protections for: $BASE_URL"
echo ""

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

test_url() {
    local url="$1"
    local should_block="$2"
    local description="$3"

    echo -n "Testing: $description... "

    status=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)

    if [ "$should_block" = "true" ]; then
        # Should be blocked (403, 404, or 500)
        if [ "$status" = "403" ] || [ "$status" = "404" ] || [ "$status" = "500" ]; then
            echo -e "${GREEN}✓ BLOCKED${NC} (HTTP $status)"
        else
            echo -e "${RED}✗ EXPOSED!${NC} (HTTP $status) - SECURITY RISK!"
        fi
    else
        # Should be accessible (200)
        if [ "$status" = "200" ]; then
            echo -e "${GREEN}✓ ACCESSIBLE${NC} (HTTP $status)"
        else
            echo -e "${RED}✗ NOT ACCESSIBLE${NC} (HTTP $status)"
        fi
    fi
}

echo "--- Database Directory Protection ---"
test_url "$BASE_URL/db/" true "db directory"
test_url "$BASE_URL/db/comments.db" true "Database file in db/"
test_url "$BASE_URL/db/comments-default.db" true "Template database in db/"
test_url "$BASE_URL/db/comments.db-shm" true "SQLite shared memory"
test_url "$BASE_URL/db/comments.db-wal" true "SQLite WAL file"

echo ""
echo "--- Directory Protection ---"
test_url "$BASE_URL/scripts/" true "scripts directory"
test_url "$BASE_URL/scripts/enable-notifications.php" true "enable-notifications.php in scripts"
test_url "$BASE_URL/scripts/fix-urls.php" true "fix-urls script"
test_url "$BASE_URL/scripts/backup-db.sh" true "Backup script"
test_url "$BASE_URL/backups/" true "backups directory"
test_url "$BASE_URL/backups/comments-backup.db" true "backup database"

echo ""
echo "--- Sensitive Files Protection ---"
test_url "$BASE_URL/docs/README.md" true "Documentation file"
test_url "$BASE_URL/docs/SECURITY-AUDIT.md" true "Security documentation"

echo ""
echo "--- Script Files Protection ---"
test_url "$BASE_URL/scripts/enable-notifications.php" true "Notification config"

echo ""
echo "--- Required Files (Should Work) ---"
test_url "$BASE_URL/admin.html" false "Admin panel HTML"
test_url "$BASE_URL/comments.js" false "Comments JavaScript"
test_url "$BASE_URL/comments.css" false "Comments CSS"
test_url "$BASE_URL/index.html" false "Index page"

echo ""
echo "--- API Endpoints (Should Work) ---"
# These should return JSON, not 403
echo -n "Testing: API endpoint... "
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api.php?action=comments&url=/test" 2>/dev/null)
if [ "$status" = "200" ]; then
    echo -e "${GREEN}✓ WORKING${NC} (HTTP $status)"
else
    echo -e "${YELLOW}⚠ ISSUE${NC} (HTTP $status)"
fi

echo ""
echo "=== Test Complete ==="
echo ""
echo "What to do if files are exposed:"
echo "1. Check .htaccess is uploaded and in correct location"
echo "2. Verify Apache has AllowOverride enabled"
echo "3. Check Apache error logs: tail -f /var/log/apache2/error.log"
echo "4. Restart Apache: sudo systemctl restart apache2"
echo ""
