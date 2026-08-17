const fs = require('fs');
const path = require('path');

const srcDir = path.join(__dirname, '../admin');
const destDir = path.join(__dirname, '../worker/public/admin');
const publicDir = path.join(__dirname, '../worker/public');

// Helper to remove directory recursively
function rmrf(dir) {
    if (fs.existsSync(dir)) {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

// Helper to copy directory recursively
function copyRecursiveSync(src, dest) {
    const exists = fs.existsSync(src);
    const stats = exists && fs.statSync(src);
    const isDirectory = exists && stats.isDirectory();
    if (isDirectory) {
        fs.mkdirSync(dest, { recursive: true });
        fs.readdirSync(src).forEach(function(childItemName) {
            copyRecursiveSync(path.join(src, childItemName), path.join(dest, childItemName));
        });
    } else {
        fs.copyFileSync(src, dest);
    }
}

rmrf(destDir);
if (!fs.existsSync(publicDir)) {
    fs.mkdirSync(publicDir, { recursive: true });
}
copyRecursiveSync(srcDir, destDir);
console.log('✅ Copied admin assets successfully.');
