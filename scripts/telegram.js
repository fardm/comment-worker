const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const WRANGLER_TOML = path.join(__dirname, '../worker/wrangler.toml');
const DEV_VARS = path.join(__dirname, '../worker/.dev.vars');
const CWD = path.join(__dirname, '../worker');

function run(command) {
  try {
    return execSync(command, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'], cwd: CWD });
  } catch (error) {
    return null;
  }
}

function runVerbose(command) {
  try {
    execSync(command, { encoding: 'utf-8', stdio: ['inherit'], cwd: CWD });
    return true;
  } catch (error) {
    console.error(`Error: ${error.message}`);
    return false;
  }
}

function prompt(question) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise(resolve => {
    rl.question(question, answer => { rl.close(); resolve(answer.trim()); });
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

function runD1(command) {
  try {
    return execSync(command, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'], cwd: CWD });
  } catch (error) {
    return null;
  }
}

function getSetting(dbName, key) {
  const result = runD1(`npx wrangler d1 execute "${dbName}" --command="SELECT value FROM settings WHERE key='${key}'" --json`);
  if (result) {
    try {
      const data = JSON.parse(result);
      if (data.results && data.results.length > 0 && data.results[0].value !== undefined) {
        return String(data.results[0].value);
      }
    } catch (e) {}
  }
  return null;
}

function updateSetting(dbName, key, value) {
  return runD1(`npx wrangler d1 execute "${dbName}" --command="INSERT OR REPLACE INTO settings (key, value) VALUES ('${key}', '${value}')"`);
}

async function setupTelegram() {
  console.log('✈️  Telegram Notification Setup\n');
  console.log('Configure your Telegram bot to receive admin notifications.\n');
  console.log('📖 Steps:');
  console.log('   1. Open Telegram and search for @BotFather');
  console.log('   2. Send /newbot and follow the instructions');
  console.log('   3. Copy the Bot Token (format: 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11)');
  console.log('   4. Add your bot to a group or create a channel');
  console.log('   5. Get the Chat ID (see below)\n');
  console.log('💡 To find your Chat ID:');
  console.log('   • For a group: add @userinfobot to the group, then forward');
  console.log('     a message from your bot to @userinfobot');
  console.log('   • For a channel: post a message, then visit:');
  console.log('     https://api.telegram.org/bot<TOKEN>/getUpdates\n');

  const token = await prompt('Enter your Telegram Bot Token: ');
  if (!token || !token.includes(':')) {
    console.error('❌ Invalid bot token format.');
    process.exit(1);
  }

  const dbName = getDbName();

  console.log('\n🔑 Setting bot token as Cloudflare Worker secret...');
  if (run(`npx wrangler secret list`)) {
    // Try to set via secret
    const child = require('child_process').spawn('npx', [
      'wrangler', 'secret', 'put', 'TELEGRAM_BOT_TOKEN'
    ], { cwd: CWD, stdio: ['pipe', 'pipe', 'pipe'] });
    child.stdin.write(token);
    child.stdin.end();
    try {
      execSync(`npx wrangler secret put TELEGRAM_BOT_TOKEN`, {
        input: token,
        cwd: CWD,
        stdio: ['pipe', 'pipe', 'pipe'],
      });
      console.log('✅ Secret set for production.');
    } catch (e) {
      if (e.stdout && e.stdout.includes('success')) {
        console.log('✅ Secret set for production.');
      } else {
        console.log('⚠️  Could not set remote secret. Setting it when you deploy.\n');
        console.log('   Run manually: cd worker && echo "YOUR_TOKEN" | npx wrangler secret put TELEGRAM_BOT_TOKEN');
      }
    }
  }

  console.log('🔧 Updating local dev secret (.dev.vars)...');
  updateDevVars(token);
  console.log('✅ Updated .dev.vars\n');

  const chatId = await prompt('Enter your Telegram Chat ID (numeric): ');
  if (!chatId) {
    console.error('❌ Chat ID is required.');
    process.exit(1);
  }

  console.log('\n💾 Saving Chat ID to database...');
  updateSetting(dbName, 'telegram_chat_id', chatId);
  console.log(`✅ Chat ID saved to ${dbName}.\n`);

  // Send test notification
  const test = await prompt('Send a test notification now? (y/n): ');
  if (test.toLowerCase() === 'y' || test.toLowerCase() === 'yes') {
    console.log('\n📨 Sending test notification...');
    try {
      const response = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: chatId,
          text: '✅ <b>Telegram integration test</b>\n\nYour Telegram notifications are working correctly!\n\nYou will receive notifications for new comments here.',
          parse_mode: 'HTML',
        }),
      });
      if (response.ok) {
        console.log('✅ Test notification sent successfully!');
      } else {
        const err = await response.text();
        console.error('❌ Failed to send test notification.');
        console.error('   Response:', err);
      }
    } catch (e) {
      console.error('❌ Failed to send test notification:', e.message);
    }
  }

  // Enable Telegram notifications
  const enable = await prompt('Enable Telegram notifications? (y/n): ');
  if (enable.toLowerCase() === 'y' || enable.toLowerCase() === 'yes') {
    updateSetting(dbName, 'telegram_enabled', 'true');
    console.log('✅ Telegram notifications enabled.');
  } else {
    updateSetting(dbName, 'telegram_enabled', 'false');
    console.log('ℹ️  Telegram notifications saved as disabled. Enable them from the Admin Panel.');
  }

  console.log('\n✨ Setup complete!\n');
}

async function changeToken() {
  console.log('🔑 Change Telegram Bot Token\n');
  const token = await prompt('Enter new Bot Token: ');
  if (!token || !token.includes(':')) {
    console.error('❌ Invalid bot token format.');
    process.exit(1);
  }

  console.log('\n🔑 Updating secret...');
  try {
    execSync(`npx wrangler secret put TELEGRAM_BOT_TOKEN`, {
      input: token,
      cwd: CWD,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    console.log('✅ Secret updated for production.');
  } catch (e) {
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
        reply_markup: JSON.stringify({
          inline_keyboard: [[{ text: '⚙️ Open Admin', url: 'http://localhost:8787/admin/index.html' }]],
        }),
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
}

// Handle CLI arguments
const args = process.argv.slice(2);
if (args.includes('--enable')) return enableNotifications();
if (args.includes('--disable')) return disableNotifications();
if (args.includes('--test')) return sendTest();
if (args.includes('--setup')) return setupTelegram();
if (args.includes('--token')) return changeToken();
if (args.includes('--chat-id')) return changeChatId();

showMenu();
