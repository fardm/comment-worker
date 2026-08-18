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

// Allow /api as the new canonical endpoint, while keeping /api.php for backward compatibility
const handler = async (c: any) => {
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
        max_comment_length: parseInt(config.max_comment_length || '5000'),
        language: config.language || 'en'
      })
    }

    if (method === 'GET' && action === 'comments') {
      const url = c.req.query('url')
      if (!url) return c.json({ error: 'URL is required' }, 400)
      const limit = parseInt(c.req.query('limit') || '500')
      const offset = parseInt(c.req.query('offset') || '0')
      const result = await comments.getComments(url, limit, offset, ip)

      // Also fetch and attach post_reactions to the result
      const postReactionsSummary = await reactions.getPostReactionsSummary(url, ip)
      return c.json({ ...result, post_reactions: postReactionsSummary })
    }

    if (method === 'GET' && action === 'recent') {
      const limit = parseInt(c.req.query('limit') || '8')
      const result = await comments.getRecentComments(limit)
      return c.json(result)
    }

    if (method === 'POST' && action === 'post') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      const result = await comments.createComment(body, ip, userAgent)

      if (result.success && body.subscribe && body.author_email) {
        await subscriptions.addSubscription(body.page_url || body.url, body.author_email)
      }

      return c.json(result)
    }

    if (method === 'POST' && action === 'post_reaction') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      if (await ratelimit.isVoteRateLimited(ip)) return c.json({ error: "Too many votes. Please try again later." }, 429)

      const result = await reactions.togglePostReaction(body.page_url || body.url, ip, body.reaction_type)
      return c.json(result)
    }

    if (method === 'POST' && action === 'vote') {
      const body = await c.req.json()
      if (await ratelimit.isCommentRateLimited(ip)) return c.json({ error: "Too many comments. Please try again later." }, 429)
      if (await ratelimit.isVoteRateLimited(ip)) return c.json({ error: "Too many votes. Please try again later." }, 429)

      const result = await reactions.toggleVote(body.comment_id, ip, body.reaction_type)
      return c.json(result)
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
      const limit = parseInt(c.req.query('limit') || '50')
      const offset = parseInt(c.req.query('offset') || '0')
      const status = c.req.query('status')
      const search = c.req.query('search')

      let query = "SELECT * FROM comments"
      let countQuery = "SELECT COUNT(*) as count FROM comments"
      let conditions = []
      let params = []

      if (status && status !== 'all') {
        conditions.push("status = ?")
        params.push(status)
      }

      if (search) {
        conditions.push("(author_name LIKE ? OR content LIKE ? OR author_email LIKE ?)")
        const searchTerm = `%${search}%`
        params.push(searchTerm, searchTerm, searchTerm)
      }

      if (conditions.length > 0) {
        query += " WHERE " + conditions.join(" AND ")
        countQuery += " WHERE " + conditions.join(" AND ")
      }

      query += " ORDER BY created_at DESC LIMIT ? OFFSET ?"

      const countStmt = db.prepare(countQuery)
      const stmt = db.prepare(query)

      const countResult = await countStmt.bind(...params).first()
      const totalCount = countResult ? countResult.count : 0

      const result = await stmt.bind(...params, limit, offset).all()

      // Calculate aggregates
      const aggregatesResult = await db.prepare("SELECT status, COUNT(*) as count FROM comments GROUP BY status").all()
      const aggregates: Record<string, number> = { pending: 0, approved: 0, spam: 0, all: 0 }
      for (const row of aggregatesResult.results) {
        aggregates[row.status as string] = row.count as number
        aggregates.all += row.count as number
      }

      return c.json({
        comments: result.results,
        pagination: { total: totalCount },
        aggregates
      })
    }

    if (method === 'PUT' && action === 'moderate') {
      const body = await c.req.json().catch(() => ({}))
      const id = parseInt(c.req.query('id') || '0') || body.id
      const result = await comments.moderateComment(id, body.status)
      return c.json(result)
    }

    if (method === 'PUT' && action === 'edit_content') {
      const body = await c.req.json().catch(() => ({}))
      const id = parseInt(c.req.query('id') || '0') || body.id
      const result = await comments.editComment(id, body.content)
      return c.json(result)
    }

    if (method === 'DELETE' && action === 'delete') {
      const body = await c.req.json().catch(() => ({}))
      const id = parseInt(c.req.query('id') || '0') || body.id
      const result = await comments.deleteComment(id)
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
      let allowed_origins = c.env.ALLOWED_ORIGINS ? c.env.ALLOWED_ORIGINS.split(',').map((o: string) => o.trim()) : ['*']
      if (config.allowed_origins) {
        try {
          allowed_origins = JSON.parse(config.allowed_origins)
        } catch {
          allowed_origins = config.allowed_origins.split(',').map((o: string) => o.trim())
        }
      }
      return c.json({
        ...config,
        app_url: config.app_url || c.env.APP_URL || '',
        allowed_origins
      })
    }


    if (method === 'GET' && action === 'get_settings') {
      const result = await settings.getAllSettings()
      return c.json({ settings: result })
    }

    if (method === 'POST' && action === 'save_settings') {
      const body = await c.req.json()
      const result = await settings.saveSettings(body)
      return c.json(result)
    }

    if (method === 'POST' && action === 'save_config') {
      const body = await c.req.json()
      // Save config as settings. The system currently uses `settings` to store both `settings` and `config`.
      const result = await settings.saveSettings(body)
      return c.json(result)
    }

    if (method === 'POST' && action === 'db_delete_data') {
      const body = await c.req.json()
      let result: Record<string, number> = {}

      if (body.delete_comments) {
        const { meta } = await db.prepare('DELETE FROM comments').run()
        result.comments = meta.changes || 0
      }
      if (body.delete_reactions) {
        const { meta: postMeta } = await db.prepare('DELETE FROM post_reactions').run()
        const { meta: voteMeta } = await db.prepare('DELETE FROM votes').run()
        result.reactions = (postMeta.changes || 0) + (voteMeta.changes || 0)
      }
      if (body.delete_subscriptions) {
        const { meta } = await db.prepare('DELETE FROM subscriptions').run()
        result.subscriptions = meta.changes || 0
      }

      return c.json({ deleted: result })
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

    if (method === 'DELETE' && action === 'delete_single_reaction') {
      const id = parseInt(c.req.query('id') || '0')
      const result = await reactions.deleteReaction(id)
      return c.json(result)
    }

    if (method === 'DELETE' && action === 'delete_subscription') {
      const body = await c.req.json()
      const result = await subscriptions.deleteSubscription(body.id)
      return c.json(result)
    }

    return c.json({ error: 'Unknown action or method' }, 404)

  } catch (e: any) {
    return c.json({ error: 'Internal Server Error', message: e.message }, 500)
  }
}

app.all('/api', handler)
app.all('/api.php', handler)

export default app
