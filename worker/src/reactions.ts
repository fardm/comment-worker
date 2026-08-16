export class ReactionService {
  private db: D1Database

  constructor(db: D1Database) {
    this.db = db
  }

  async addVote(commentId: number, ip: string, reactionType: string) {
    try {
      await this.db.prepare('INSERT INTO votes (comment_id, ip_address, reaction_type) VALUES (?, ?, ?)').bind(commentId, ip, reactionType).run()
      return { success: true }
    } catch (e) {
      // SQLite UNIQUE constraint violation
      return { error: 'already_voted' }
    }
  }

  async removeVote(commentId: number, ip: string, reactionType: string) {
    await this.db.prepare('DELETE FROM votes WHERE comment_id = ? AND ip_address = ? AND reaction_type = ?').bind(commentId, ip, reactionType).run()
    return { success: true }
  }

  async addPostReaction(url: string, ip: string, reactionType: string) {
    try {
      await this.db.prepare('INSERT INTO post_reactions (page_url, ip_address, reaction_type) VALUES (?, ?, ?)').bind(url, ip, reactionType).run()
      return { success: true }
    } catch (e) {
      return { error: 'already_reacted' }
    }
  }

  async removePostReaction(url: string, ip: string, reactionType: string) {
    await this.db.prepare('DELETE FROM post_reactions WHERE page_url = ? AND ip_address = ? AND reaction_type = ?').bind(url, ip, reactionType).run()
    return { success: true }
  }

  async getPostReactionsSummary(url: string, ip: string) {
    const { results } = await this.db.prepare('SELECT reaction_type, COUNT(*) as count FROM post_reactions WHERE page_url = ? GROUP BY reaction_type').bind(url).all()

    const userReacts = await this.db.prepare('SELECT reaction_type FROM post_reactions WHERE page_url = ? AND ip_address = ?').bind(url, ip).all()
    const userVoted = new Set(userReacts.results.map((r: any) => r.reaction_type))

    const summary: Record<string, {count: number, voted: boolean}> = {}
    for (const r of results) {
      const reactionType = r.reaction_type as string
      summary[reactionType] = {
        count: r.count as number,
        voted: userVoted.has(reactionType)
      }
    }

    return summary
  }
}
