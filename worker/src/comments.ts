import { SpamService } from './spam'

export class CommentService {
  private db: D1Database
  private spamService: SpamService

  constructor(db: D1Database) {
    this.db = db
    this.spamService = new SpamService(db)
  }

  async getComments(url: string, limit: number, offset: number) {
    const { results: comments } = await this.db.prepare(`
      SELECT * FROM comments
      WHERE page_url = ? AND status = 'approved'
      ORDER BY created_at ASC
      LIMIT ? OFFSET ?
    `).bind(url, limit, offset).all()

    // Group into threads
    const topLevel: any[] = []
    const repliesMap = new Map<number, any[]>()

    // Fetch all reactions for these comments to map to counts
    const commentIds = comments.map(c => c.id).join(',');
    const votesMap = new Map<number, Record<string, number>>();
    if (commentIds) {
      const { results: votes } = await this.db.prepare(`SELECT comment_id, reaction_type, COUNT(*) as count FROM votes WHERE comment_id IN (${commentIds}) GROUP BY comment_id, reaction_type`).all();
      for (const v of votes) {
        const cId = v.comment_id as number;
        if (!votesMap.has(cId)) votesMap.set(cId, {});
        votesMap.get(cId)![v.reaction_type as string] = v.count as number;
      }
    }

    for (const comment of comments) {
      comment.reactions = votesMap.get(comment.id as number) || {};
      const parentId = comment.parent_id as number | null;
      if (parentId) {
        if (!repliesMap.has(parentId)) {
          repliesMap.set(parentId, [])
        }
        repliesMap.get(parentId)!.push(comment)
      } else {
        topLevel.push(comment)
      }
    }

    // Assign replies
    for (const comment of topLevel) {
      comment.replies = repliesMap.get(comment.id as number) || []
    }

    const { count } = await this.db.prepare(`SELECT COUNT(*) as count FROM comments WHERE page_url = ? AND status = 'approved'`).bind(url).first<{count: number}>() || { count: 0 }

    return { comments: topLevel, total: count }
  }

  async createComment(data: any, ip: string, userAgent: string) {
    // Honeypot check
    if (data.website) {
      return { error: 'spam_detected' }
    }

    const isSpam = await this.spamService.checkSpam(data.content, data.author_name, data.author_email, data.author_url || '', ip, userAgent)
    const status = isSpam ? 'spam' : 'pending' // Should read from settings 'require_moderation'

    const result = await this.db.prepare(`
      INSERT INTO comments (page_url, parent_id, author_name, author_email, author_url, content, ip_address, user_agent, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).bind(
      data.page_url,
      data.parent_id || null,
      data.author_name,
      data.author_email,
      data.author_url || null,
      data.content,
      ip,
      userAgent,
      status
    ).run()

    if (result.success) {
      return {
        success: true,
        message: status === 'pending' ? 'Your comment is awaiting moderation.' : 'Comment posted successfully.',
        status
      }
    }
    return { error: 'Database error' }
  }

  async moderateComment(id: number, status: string) {
    await this.db.prepare('UPDATE comments SET status = ? WHERE id = ?').bind(status, id).run()
    return { success: true }
  }

  async editComment(id: number, content: string) {
    await this.db.prepare('UPDATE comments SET content = ?, updated_at = datetime("now") WHERE id = ?').bind(content, id).run()
    return { success: true }
  }

  async deleteComment(id: number) {
    await this.db.prepare('DELETE FROM comments WHERE id = ?').bind(id).run()
    return { success: true }
  }

  async getRecentComments(limit: number) {
    const { results } = await this.db.prepare(`
      SELECT * FROM comments
      WHERE status = 'approved'
      ORDER BY created_at DESC
      LIMIT ?
    `).bind(limit).all()
    return results
  }
}
