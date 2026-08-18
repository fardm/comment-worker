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

  async deleteSubscription(token: string) {
    await this.db.prepare('DELETE FROM subscriptions WHERE token = ?').bind(token).run()
    return { success: true }
  }

  async unsubscribe(token: string) {
    const result = await this.db.prepare('DELETE FROM subscriptions WHERE token = ?').bind(token).run()
    return result.success
  }

  async toggleSubscription(token: string, active: number) {
    await this.db.prepare('UPDATE subscriptions SET active = ? WHERE token = ?').bind(active, token).run()
    return { success: true }
  }
}
