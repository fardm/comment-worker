# Quartz Standalone Comments

This project adds a commenting system to [Quartz](https://quartz.jzhao.xyz/). The original repository can be found here: https://github.com/dlnorman/standalone-comments. This project has been heavily modified to be fully serverless, running on Cloudflare Workers and Cloudflare D1.

> ✅ Compatible with Quartz v5

## Features

- 🌍 Multilingual support (English & Persian, extendable via i18n)
- 😀 Reactions with 10 emoji options for both posts and comments
- 👤 Gravatar integration with automatic fallback avatars
- 💬 Latest comments widget
- 🛠️ Admin panel for viewing and managing comments
- 📦 Data import and export support
- 🛡️ Spam protection and moderation system
- 📧 Email notifications for new comments

---

## 1. Requirements and installation

- A Cloudflare account.
- Node.js installed locally.
- Git repository clone of this project.

Run `npm install` inside the project to install dependencies.

## 2. Admin password/secrets setup

The system secures the admin interface using an environment variable.

First, generate a secure password hash or simply use a strong plain-text password for the `ADMIN_PASSWORD_HASH` variable (if deploying plainly, although hashing is recommended for real deployments, the current config compares directly).

You must add this secret to your Cloudflare Worker:
```bash
cd worker
npx wrangler secret put ADMIN_PASSWORD_HASH
```
When prompted, enter your secure password.

## 3. Local configuration

In `worker/wrangler.toml`, there are several variables you can configure:

- `ALLOWED_ORIGINS`: A comma-separated list of domains allowed to access the API. (e.g. `https://your-quartz-site.com, https://admin.yourdomain.com`). Set it to localhost origins for testing.
- `APP_URL`: The URL of your API Worker.
- `ADMIN_PASSWORD_HASH`: For local testing, you can set it directly in `wrangler.toml` under `[vars]`, but it should remain empty in source control and set via `wrangler secret put` in production.

## 4. Database setup

The project uses Cloudflare D1. To initialize the database:

**Local Development:**
```bash
cd worker
npx wrangler d1 execute comments-db --local --file=./schema.sql
```

**Production:**
First, create the database:
```bash
npx wrangler d1 create comments-db
```
Update your `worker/wrangler.toml` with the generated `database_id`. Then initialize it:
```bash
npx wrangler d1 execute comments-db --file=./schema.sql
```

## 5. Running Worker and Admin locally

To run the Worker API locally:
```bash
cd worker
npx wrangler dev
```
The API will be available at `http://localhost:8787`.

To run the Admin panel locally, you can use any static file server in the project root:
```bash
npx serve .
```
Navigate to `http://localhost:3000/admin/index.html`. You can configure the `window.COMMENTS_CONFIG.apiUrl` inside `admin/index.html` to point to `http://localhost:8787/api.php` if needed.

## 6. Worker deployment

To deploy the API Worker to Cloudflare:
```bash
cd worker
npx wrangler deploy
```

## 7. Admin Pages deployment

You can host the Admin panel and static assets on Cloudflare Pages. From the root directory:
```bash
npx wrangler pages deploy . --project-name comments-admin
```
Follow the interactive prompts to create the project. The admin panel will be accessible at `https://<your-pages-domain>.pages.dev/admin/index.html`.

## 8. Frontend comments integration

Run the following command inside your Quartz project:

```bash
npx quartz plugin add github:fardm/quartz-standalone-comments
```

Once installed, open the `quartz.config.yaml` file and configure the plugin. Set `backendUrl` to the URL of the server where you uploaded the comment system. You can define `apiUrl` and `assetUrl` separately if they are hosted on different domains.

## 9. Production configuration

Make sure `ALLOWED_ORIGINS` in your `wrangler.toml` (or via Cloudflare Dashboard) includes the exact origin of your Quartz site and your Admin panel site (e.g., `https://my-blog.com, https://comments-admin.pages.dev`).

In `admin/index.html`, uncomment the `window.COMMENTS_CONFIG` block and set `apiUrl` to your Worker URL (e.g., `https://comments-worker.<your-username>.workers.dev/api.php`).

## 10. Troubleshooting

- **CORS Credentials Errors:** The API does not allow `*` when credentials are included. Ensure your exact frontend domains are listed in `ALLOWED_ORIGINS`.
- **404 Assets (lang/en.js):** The script automatically resolves asset URLs based on where `comments.js` is loaded from, or from `window.COMMENTS_CONFIG.assetUrl`.
- **Login fails / CSRF errors:** Ensure `ADMIN_PASSWORD_HASH` is set via Wrangler secrets, and that your API URL is correctly configured in the Admin panel.
