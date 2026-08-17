import { Hono } from 'hono'
import { cors } from 'hono/cors'
import { adminMiddleware, AuthService, ADMIN_TOKEN_COOKIE } from './auth'
import { CommentService } from './comments'
import { ReactionService } from './reactions'
import { AdminService } from './admin'
import { RateLimitService } from "./ratelimit"
import { EmailService } from "./email"
import { ImportExportService } from "./importexport"
import { SubscriptionService } from './subscriptions'
import { SettingsService } from './settings'
import { getCookie } from 'hono/cookie'

type Bindings = {
  DB: D1Database
  ALLOWED_ORIGINS: string
  APP_URL: string
  ADMIN_PASSWORD_HASH?: string
}

const app = new Hono<{ Bindings: Bindings }>()

app.use('*', async (c, next) => {
  const allowedOrigins = c.env.ALLOWED_ORIGINS || '*'

  const corsMiddleware = cors({
    origin: allowedOrigins === '*' ? '*' : allowedOrigins.split(',').map(o => o.trim()),
    allowMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowHeaders: ['Content-Type', 'Authorization', 'X-Admin-Token'],
    credentials: true,
  })

  return corsMiddleware(c, next)
})

app.get('/', (c) => c.text('Cloudflare Comments API is running.'))

// The single endpoint to match the old api.php routing logic
app.all('/api.php', async (c) => {
  const method = c.req.method
  const action = c.req.query('action')

  const db = c.env.DB

  const auth = new AuthService(db)
  const comments = new CommentService(db)
  const reactions = new ReactionService(db)
  const admin = new AdminService(db)
  const subscriptions = new SubscriptionService(db)
  const settings = new SettingsService(db)
  const ratelimit = new RateLimitService(db)
  const emailService = new EmailService(db)
  const importExport = new ImportExportService(db)

  const ip = c.req.header('CF-Connecting-IP') || '127.0.0.1'
  const userAgent = c.req.header('User-Agent') || ''

  try {
    // ── Public Routes ─────────────────────────────────────────────

    if (method === 'GET' && action === 'widget_config') {
      const config = await settings.getAllSettings()
      return c.json({
        require_moderation: config.require_moderation === 'true',
        allow_guest_comments: config.allow_guest_comments === 'true',
        max_comment_length: parseInt(config.max_comment_length || '5000')
      })
    }

    if (method === 'GET' && action === 'comments') {
      const url = c.req.query('url')
      if (!url) return c.json({ error: 'URL is required' }, 400)
      const limit = parseInt(c.req.query('limit') || '500')
      const offset = parseInt(c.req.query('offset') || '0')
      const result = await comments.getComments(url, limit, offset)
      return c.json(result)
    }

    if (method === 'POST' && action === 'post') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await comments.createComment(body, ip, userAgent)
      return c.json(result)
    }

    if (method === 'POST' && action === 'post_reaction') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const isDelete = c.req.query('delete') === '1'
      if (!isDelete && await ratelimit.isVoteRateLimited(ip)) return c.json({ error: "Too many votes. Please try again later." }, 429)
      if (isDelete) {
        const result = await reactions.removePostReaction(body.url, ip, body.reaction_type)
        return c.json(result)
      } else {
        const result = await reactions.addPostReaction(body.url, ip, body.reaction_type)
        return c.json(result)
      }
    }

    if (method === 'POST' && action === 'vote') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const isDelete = c.req.query('delete') === '1'
      if (!isDelete && await ratelimit.isVoteRateLimited(ip)) return c.json({ error: "Too many votes. Please try again later." }, 429)
      if (isDelete) {
        const result = await reactions.removeVote(body.comment_id, ip, body.reaction_type)
        return c.json(result)
      } else {
        const result = await reactions.addVote(body.comment_id, ip, body.reaction_type)
        return c.json(result)
      }
    }

    if (method === 'GET' && action === 'post_reactions_summary') {
      const url = c.req.query('url')
      if (!url) {
        // Return global summary if no url is provided
        const result = await reactions.getGlobalPostReactionsSummary()
        return c.json(result)
      }
      const result = await reactions.getPostReactionsSummary(url, ip)
      return c.json(result)
    }


    // ── Auth Routes ─────────────────────────────────────────────

    if (method === 'POST' && action === 'login') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await auth.login(c, body.password, ip, userAgent)
      if (result.error) return c.json(result, 401)
      return c.json({ success: true, message: 'Logged in successfully', csrf_token: 'dummy_csrf' })
    }

    if (method === 'GET' && action === 'csrf_token') {
      return c.json({ token: 'dummy_csrf' })
    }

    if (method === 'POST' && action === 'logout') {
      await auth.logout(c)
      return c.json({ success: true })
    }

    // ── Admin Routes ─────────────────────────────────────────────

    // Check admin
    if (!(await auth.isAdmin(c))) {
      return c.json({ error: 'Unauthorized' }, 401)
    }

    if (method === 'GET' && action === 'post_reactions_latest') {
      const limit = parseInt(c.req.query('limit') || '10')
      const result = await reactions.getLatestPostReactions(limit)
      return c.json(result)
    }


    if (method === 'GET' && action === 'pending') {
      const result = await db.prepare("SELECT * FROM comments WHERE status = 'pending' ORDER BY created_at DESC").all()
      return c.json({ comments: result.results, total: result.results.length })
    }

    if (method === 'GET' && action === 'all') {
      const result = await db.prepare("SELECT * FROM comments ORDER BY created_at DESC").all()
      return c.json({ comments: result.results, total: result.results.length })
    }

    if (method === 'PUT' && action === 'moderate') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await comments.moderateComment(body.id, body.status)
      return c.json(result)
    }

    if (method === 'PUT' && action === 'edit_content') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await comments.editComment(body.id, body.content)
      return c.json(result)
    }

    if (method === 'DELETE' && action === 'delete') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await comments.deleteComment(body.id)
      return c.json(result)
    }

    if (method === 'GET' && action === 'analytics') {
      const result = await admin.getAnalytics()
      return c.json(result)
    }

    if (method === 'GET' && action === 'db_stats') {
      const result = await admin.getDbStats()
      return c.json(result)
    }


    if (method === 'GET' && action === 'get_config') {
      const config = await settings.getAllSettings()
      return c.json({
        ...config,
        app_url: c.env.APP_URL || '',
        allowed_origins: c.env.ALLOWED_ORIGINS ? c.env.ALLOWED_ORIGINS.split(',').map(o => o.trim()) : ['*']
      })
    }


    if (method === 'GET' && action === 'get_settings') {
      const result = await settings.getAllSettings()
      return c.json(result)
    }

    if (method === 'POST' && action === 'save_settings') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await settings.saveSettings(body)
      return c.json(result)
    }

    if (method === 'GET' && action === 'export_comments_json') {
      const result = await importExport.exportCommentsJson()
      return c.json(result)
    }

    if (method === 'GET' && action === 'export_comments') {
      const result = await importExport.exportCommentsXml()
      return new Response(result, {
        headers: {
          "Content-Type": "application/xml",
          "Content-Disposition": "attachment; filename=\"comments.xml\""
        }
      })
    }

    if (method === 'GET' && action === 'subscriptions') {
      const result = await subscriptions.getSubscriptions()
      return c.json({ subscriptions: result, total: result.length })
    }

    if (method === 'DELETE' && action === 'delete_subscription') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await subscriptions.deleteSubscription(body.id)
      return c.json(result)
    }

    return c.json({ error: 'Unknown action or method' }, 404)

  } catch (e: any) {
    return c.json({ error: 'Internal Server Error', message: e.message }, 500)
  }
})

export default app
