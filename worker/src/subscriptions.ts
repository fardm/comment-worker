export class SubscriptionService {
  private db: D1Database

  constructor(db: D1Database) {
    this.db = db
  }

  async addSubscription(url: string, email: string) {
    const token = crypto.randomUUID().replace(/-/g, '')
    try {
      await this.db.prepare('INSERT INTO subscriptions (page_url, email, token) VALUES (?, ?, ?)').bind(url, email, token).run()
      return { success: true }
    } catch (e) {
      return { error: 'already_subscribed' }
    }
  }

  async getSubscriptions() {
    const { results } = await this.db.prepare('SELECT * FROM subscriptions ORDER BY subscribed_at DESC').all()
    return results
  }

  async deleteSubscription(id: number) {
    await this.db.prepare('DELETE FROM subscriptions WHERE id = ?').bind(id).run()
    return { success: true }
  }

  async unsubscribe(token: string) {
    const result = await this.db.prepare('DELETE FROM subscriptions WHERE token = ?').bind(token).run()
    return result.success
  }
}
