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
