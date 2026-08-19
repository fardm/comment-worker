# Quartz Standalone Comments

This project adds a commenting system to [Quartz](https://quartz.jzhao.xyz/). The original repository can be found here: https://github.com/dlnorman/standalone-comments.

I made several modifications to improve compatibility and usability for Quartz websites, now simplified with automated setup and configuration using Cloudflare Workers and D1!

> ✅ Compatible with Quartz v5

## Features

- 🌍 Multilingual support (English & Persian, extendable via i18n)
- 😀 Reactions with 10 emoji options for both posts and comments
- 👤 Gravatar integration with automatic fallback avatars
- 💬 Latest comments widget
- 🛠️ Admin panel for viewing and managing comments
- 📦 Data import and export support
- 🛡️ Spam protection and moderation system
- 📧 Email notifications for new comments (requires configuration)

<br>

## Setup & Deployment Guide

This setup guide is for complete beginners! We will use **Cloudflare Workers** (to run the code) and **Cloudflare D1** (to store the comments).

### Prerequisites

1. **Node.js**: Make sure you have Node.js installed. You can download it from [nodejs.org](https://nodejs.org/).
2. **Cloudflare Account**: Sign up for a free account at [Cloudflare](https://dash.cloudflare.com/sign-up).
3. Download or clone this repository to your computer and open a terminal inside the project folder.

### Step 1: Login to Cloudflare

Run this command in your terminal to connect to your Cloudflare account:

`npx wrangler login`

A browser window will open asking you to authorize Wrangler.

### Step 2: Install and Setup

Install the required packages:

`npm install`

Now, run the automatic setup script. This script will automatically create a database, configure it, and ask you to choose an admin password.

`npm run setup`

*(Note: If you've already created the database before, or if you run this script again, it will gracefully detect the existing database, retrieve its configuration, and let you update your password safely without data loss.)*

### Step 3: Local Development (Optional)

If you want to test the server locally on your machine before deploying, run:

`npm run dev`

Your server will be available at `http://127.0.0.1:8787`. You can also visit `http://127.0.0.1:8787/admin/index.html` to see the admin panel locally.

### Step 4: Deploy to Production

Once you are ready, deploy the comments server to Cloudflare:

`npm run deploy`

The terminal will give you a public URL (e.g., `https://standalone-comments-server.<your-username>.workers.dev`). This is your **Backend URL**.

<br>

## Install the Quartz Plugin

Run the following command inside your Quartz project:

`npx quartz plugin add github:fardm/quartz-standalone-comments`

Once installed, open your `quartz.config.yaml` file and configure the plugin. Set `backendUrl` to the URL you got after running `npm run deploy`:

```yaml
- source: github:fardm/quartz-standalone-comments
  enabled: true
  options:
    backendUrl: https://standalone-comments-server.<your-username>.workers.dev
    type: full
  layout:
    position: afterBody
    priority: 100
```

Start your local Quartz server (`npx quartz build --serve`) to see the comments in action!

<br>

## Accessing the Admin Panel

To manage comments and change settings, go to your deployed URL and add `/admin/index.html`.

For example:
`https://standalone-comments-server.<your-username>.workers.dev/admin/index.html`

Log in using the password you chose during the `npm run setup` step.

## Testing Guide

After deployment or when making changes, test the key features to ensure they function properly:

- **Frontend Language**: In the Admin panel under "Settings -> Configuration", change the Frontend Language. Reload your comments widget on your site to see the UI text updated.
- **Comment Reactions**: Click reaction emojis on both the comment widget itself and check the "All Comments" or "Post Reactions" section in the Admin panel to ensure counts increase properly without refreshing.
- **Recent Comments**: Post a new comment and check the "Recent Comments" widget (if embedded on the sidebar). It should show a brief excerpt of your comment.
- **Vacuum DB**: Go to "Settings -> Database" in the Admin panel and click "Optimize (VACUUM)". You should see a success message that the database size was optimized.
- **Export JSON**: Go to "Settings -> Import & Export" in the Admin panel and click "Download JSON". Ensure a `.json` file downloads automatically to your computer rather than displaying in the browser tab.
- **Unsubscribe & Delete Subscription**: Navigate to "Subscriptions" in the Admin panel. Click "Unsubscribe" to toggle the active status of an email, and click "Delete" to ensure the subscription is removed from the database completely.
- **Import Comments**: In "Settings -> Import & Export", drop a `.json` or `.xml` export file. Click "Preview" to see the data, then "Import" to populate the database with the comments.

## Telegram Notifications

You can receive instant Telegram notifications whenever a new comment is posted. This uses the official Telegram Bot API — no server-side polling required.

### 1. Create a Bot

1. Open Telegram and search for **@BotFather**.
2. Send `/newbot` and follow the prompts to choose a name and username for your bot.
3. BotFather will give you a **Bot Token** (looks like `123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`). Copy it.

### 2. Get Your Chat ID

1. Open a chat with your new bot in Telegram and send any message (e.g. `/start`).
2. Open this URL in your browser (replace `<TOKEN>` with your Bot Token):

   ```
   https://api.telegram.org/bot<TOKEN>/getUpdates
   ```

3. Look for `"chat":{"id":` in the JSON response — the number that follows is your **Chat ID**.

   > 💡 For group chats, add the bot to the group first, then send a message there before calling `getUpdates`. For channels, post a message as the bot.

### 3. Configure via CLI

Run the interactive setup:

`npm run telegram`

Choose **option 1 (Setup / Reconfigure)** and follow the prompts:

- Enter your **Bot Token** — it is pushed as a Cloudflare Worker Secret (`TELEGRAM_BOT_TOKEN`) and saved locally in `worker/.dev.vars` for development.
- Enter your **Chat ID** — it is stored in your D1 database alongside other settings.
- Choose whether to enable notifications and send a test message.

You can return to this menu anytime to change the token, chat ID, enable/disable notifications, or send another test:

| Option | Description |
|--------|-------------|
| `1` | Full setup / reconfigure |
| `2` | Change Bot Token |
| `3` | Change Chat ID |
| `4` | Enable notifications |
| `5` | Disable notifications |
| `6` | Send test notification |

### Where Things Are Stored

| What | Where | Why |
|------|-------|-----|
| **Bot Token** | Cloudflare Worker Secret (`TELEGRAM_BOT_TOKEN`) + local `worker/.dev.vars` | Secrets are never stored in the database — only in encrypted Cloudflare storage and your local dev file (which is git-ignored). |
| **Chat ID** | D1 `settings` table | Kept in the database so it can be managed from both the CLI and the Admin Panel. |
| **Enabled/Disabled** | D1 `settings` table | Toggle from the CLI (`npm run telegram`) or the Admin Panel. |

## Testing Your Deployed Worker Without Installation

If you want to quickly verify that your worker is working, copy the following HTML snippet and save it as an `index.html` file, or paste it directly into an existing test page. Make sure to replace `<YOUR_WORKER_URL>` with your actual deployed Worker URL!

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Comments Test</title>
    <!-- 1. Load the CSS -->
    <link rel="stylesheet" href="<YOUR_WORKER_URL>/comments.css">
</head>
<body>
    <h1>Comments Test Page</h1>

    <!-- 2. Create a container for the comments -->
    <div id="comments-container"></div>

    <!-- 3. Configure the worker URL -->
    <script>
        window.COMMENTS_CONFIG = {
            apiUrl: "<YOUR_WORKER_URL>/api",
            pageUrl: window.location.pathname,
            title: document.title
        };
    </script>

    <!-- 4. Load the JS file to initialize the comments -->
    <script src="<YOUR_WORKER_URL>/comments.js"></script>
</body>
</html>
```

Open your HTML file in a browser, and you should see the comment section load and connect correctly to your backend!
