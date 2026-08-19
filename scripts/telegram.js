const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const WRANGLER_TOML = path.join(__dirname, '../worker/wrangler.toml');
const DEV_VARS = path.join(__dirname, '../worker/.dev.vars');
const CWD = path.join(__dirname, '../worker');

// Reusable readline instance — creating/closing one per prompt can cause
// stdin to stay paused and silently swallow the next input.
let rl = null;
function getRl() {
  if (!rl) rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return rl;
}
function closeRl() { if (rl) { rl.close(); rl = null; } }

/**
 * Run a command silently. Returns stdout on success, null on failure.
 * Uses shell:true so that npx/npx.cmd resolves correctly on every OS.
 */
function run(command) {
  try {
    return execSync(command, {
      encoding: 'utf-8',
      stdio: ['pipe', 'pipe', 'pipe'],
      cwd: CWD,
      shell: true,
    });
  } catch (error) {
    return null;
  }
}

/**
 * Run a command with inherited stdio (user sees output). Returns true on success.
 */
function runVerbose(command) {
  try {
    execSync(command, { encoding: 'utf-8', stdio: 'inherit', cwd: CWD, shell: true });
    return true;
  } catch (error) {
    return false;
  }
}

function prompt(question) {
  return new Promise(resolve => {
    getRl().question(question, answer => resolve(answer.trim()));
  });
}

function updateDevVars(token) {
  let content = '';
  if (fs.existsSync(DEV_VARS)) content = fs.readFileSync(DEV_VARS, 'utf-8');
  if (content.includes('TELEGRAM_BOT_TOKEN=')) {
    content = content.replace(/TELEGRAM_BOT_TOKEN=.*/, `TELEGRAM_BOT_TOKEN="${token}"`);
  } else {
    content = content.trimEnd() + `\nTELEGRAM_BOT_TOKEN="${token}"\n`;
  }
  fs.writeFileSync(DEV_VARS, content);
}

function getDbName() {
  const toml = fs.readFileSync(WRANGLER_TOML, 'utf-8');
  const match = toml.match(/database_name\s*=\s*"([^"]+)"/);
  return match ? match[1] : 'comments-db';
}

/**
 * Read the production APP_URL from [vars] in wrangler.toml.
 * This is always the canonical public admin URL — never localhost.
 */
function getProductionUrl() {
  const toml = fs.readFileSync(WRANGLER_TOML, 'utf-8');
  const match = toml.match(/APP_URL\s*=\s*"([^"]+)"/);
  return match ? match[1] : '';
}

/** Build the admin panel URL for Telegram inline buttons. */
function getAdminUrl() {
  const base = getProductionUrl();
  return base ? `${base}/admin/index.html` : '';
}

function getSetting(dbName, key) {
  const result = run(`npx wrangler d1 execute "${dbName}" --command="SELECT value FROM settings WHERE key='${key}'" --json`);
  if (result) {
    try {
      const data = JSON.parse(result);
      // Wrangler v4 wraps the output in an array: [{results: [...]}]
      const first = Array.isArray(data) ? data[0] : data;
      if (first && first.results && first.results.length > 0 && first.results[0].value !== undefined) {
        return String(first.results[0].value);
      }
    } catch (e) {}
  }
  return null;
}

function updateSetting(dbName, key, value) {
  return run(`npx wrangler d1 execute "${dbName}" --command="INSERT OR REPLACE INTO settings (key, value) VALUES ('${key}', '${value}')"`);
}

/**
 * Push the bot token to the Cloudflare Worker secret using execSync with input piping.
 * Works cross-platform because execSync with shell:true resolves npx → npx.cmd on Windows.
 */
function pushSecret(token) {
  try {
    execSync('npx wrangler secret put TELEGRAM_BOT_TOKEN', {
      input: token,
      cwd: CWD,
      stdio: ['pipe', 'pipe', 'pipe'],
      shell: true,
    });
    return true;
  } catch (e) {
    return false;
  }
}

async function setupTelegram() {
  console.log('✈️  Telegram Notification Setup\n');

  const token = await prompt('Enter your Telegram Bot Token: ');
  if (!token || !token.includes(':')) {
    console.error('❌ Invalid bot token format.');
    process.exit(1);
  }

  const dbName = getDbName();

  if (pushSecret(token)) {
    console.log('✅ Bot token set as Worker secret.');
  } else {
    console.log('⚠️  Could not set remote secret. Set it later with: cd worker && npx wrangler secret put TELEGRAM_BOT_TOKEN');
  }
  updateDevVars(token);

  const chatId = await prompt('Enter your Telegram Chat ID (numeric): ');
  if (!chatId) {
    console.error('❌ Chat ID is required.');
    process.exit(1);
  }

  updateSetting(dbName, 'telegram_chat_id', chatId);
  console.log('✅ Chat ID saved.');

  // Send test notification
  const test = await prompt('Send a test notification? (y/n): ');
  if (test.toLowerCase() === 'y' || test.toLowerCase() === 'yes') {
    try {
      const response = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: chatId,
          text: '✅ <b>Telegram integration test</b>\n\nNotifications are working!',
          parse_mode: 'HTML',
        }),
      });
      console.log(response.ok ? '✅ Test notification sent!' : `❌ Failed: ${await response.text()}`);
    } catch (e) {
      console.error('❌ Failed:', e.message);
    }
  }

  const enable = await prompt('Enable notifications? (y/n): ');
  const enabled = enable.toLowerCase() === 'y' || enable.toLowerCase() === 'yes';
  updateSetting(dbName, 'telegram_enabled', enabled ? 'true' : 'false');
  console.log(enabled ? '✅ Telegram notifications enabled.' : 'ℹ️  Telegram notifications disabled.');
  console.log();
}

async function changeToken() {
  console.log('🔑 Change Telegram Bot Token\n');
  const token = await prompt('Enter new Bot Token: ');
  if (!token || !token.includes(':')) {
    console.error('❌ Invalid bot token format.');
    process.exit(1);
  }

  console.log('\n🔑 Updating secret...');
  if (pushSecret(token)) {
    console.log('✅ Secret updated for production.');
  } else {
    console.log('⚠️  Could not update remote secret.');
    console.log('   Run manually: cd worker && echo "NEW_TOKEN" | npx wrangler secret put TELEGRAM_BOT_TOKEN');
  }

  updateDevVars(token);
  console.log('✅ Updated .dev.vars.');
}

async function changeChatId() {
  console.log('💬 Change Telegram Chat ID\n');
  const chatId = await prompt('Enter new Chat ID (numeric): ');
  if (!chatId) {
    console.error('❌ Chat ID is required.');
    process.exit(1);
  }

  const dbName = getDbName();
  updateSetting(dbName, 'telegram_chat_id', chatId);
  console.log('✅ Chat ID updated.');
}

async function enableNotifications() {
  const dbName = getDbName();
  updateSetting(dbName, 'telegram_enabled', 'true');
  console.log('✅ Telegram notifications enabled.');
}

async function disableNotifications() {
  const dbName = getDbName();
  updateSetting(dbName, 'telegram_enabled', 'false');
  console.log('✅ Telegram notifications disabled.');
}

async function sendTest() {
  console.log('📨 Sending test notification...\n');

  // Read token from env var, .dev.vars, or prompt
  let token = process.env.TELEGRAM_BOT_TOKEN;
  if (!token && fs.existsSync(DEV_VARS)) {
    const content = fs.readFileSync(DEV_VARS, 'utf-8');
    const match = content.match(/TELEGRAM_BOT_TOKEN="?([^"\n]+)"?/);
    if (match) token = match[1];
  }

  if (!token) {
    console.log('❌ No bot token found.');
    console.log('   Set TELEGRAM_BOT_TOKEN environment variable,');
    console.log('   or run: npm run telegram (first-time setup)');
    return;
  }

  const dbName = getDbName();
  const chatId = getSetting(dbName, 'telegram_chat_id');
  if (!chatId) {
    console.log('❌ No Chat ID configured.');
    console.log('   Run: npm run telegram (first-time setup)');
    return;
  }

  try {
    const response = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: chatId,
        text: '✅ <b>Telegram integration test</b>\n\nYour Telegram notifications are working correctly!\n\nYou will receive notifications for new comments here.',
        parse_mode: 'HTML',
        ...(getAdminUrl() ? { reply_markup: JSON.stringify({ inline_keyboard: [[{ text: '⚙️ Open Admin', url: getAdminUrl() }]] }) } : {}),
      }),
    });
    if (response.ok) {
      console.log('✅ Test notification sent successfully!');
    } else {
      const err = await response.text();
      console.error('❌ Failed to send test notification.');
      console.error('   Make sure your Bot Token and Chat ID are correct.');
      console.error('   Response:', err);
    }
  } catch (e) {
    console.error('❌ Failed to send test notification:', e.message);
  }
}

async function showMenu() {
  try {
  console.log('✈️  Telegram Notification Configuration\n');
  console.log('Select an option:\n');
  console.log('  1. Setup / Reconfigure');
  console.log('  2. Change Bot Token');
  console.log('  3. Change Chat ID');
  console.log('  4. Enable notifications');
  console.log('  5. Disable notifications');
  console.log('  6. Send test notification');
  console.log('');

  const choice = await prompt('Enter option (1-6): ');
  console.log('');

  switch (choice) {
    case '1': return setupTelegram();
    case '2': return changeToken();
    case '3': return changeChatId();
    case '4': return enableNotifications();
    case '5': return disableNotifications();
    case '6': return sendTest();
    default:
      console.error('❌ Invalid option.');
      process.exit(1);
  }
  } finally {
    closeRl();
  }
}

// Handle CLI arguments
const args = process.argv.slice(2);
const handled = (
  (args.includes('--enable') && enableNotifications()) ||
  (args.includes('--disable') && disableNotifications()) ||
  (args.includes('--test') && sendTest()) ||
  (args.includes('--setup') && setupTelegram()) ||
  (args.includes('--token') && changeToken()) ||
  (args.includes('--chat-id') && changeChatId())
);
if (handled) {
  Promise.resolve(handled).then(() => closeRl()).catch(e => { closeRl(); console.error(e); process.exit(1); });
} else {
  showMenu().catch(err => { closeRl(); console.error(err); process.exit(1); });
}
