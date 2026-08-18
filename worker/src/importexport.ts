export class ImportExportService {
  private db: D1Database

  constructor(db: D1Database) {
    this.db = db
  }

  async exportCommentsJson() {
    const { results } = await this.db.prepare('SELECT * FROM comments').all()
    return results
  }

  async exportCommentsXml() {
    const { results } = await this.db.prepare('SELECT * FROM comments').all()
    let xml = '<?xml version="1.0" encoding="UTF-8"?>\n<comments>\n'
    for (const r of results) {
       xml += `  <comment id="${r.id}">\n`
       xml += `    <content><![CDATA[${r.content}]]></content>\n`
       xml += `  </comment>\n`
    }
    xml += '</comments>'
    return xml
  }

  async previewImport(content: string) {
    try {
      const data = JSON.parse(content)
      if (Array.isArray(data)) {
        return {
          format: 'native_json',
          comments: data.length
        }
      }
      return { error: 'Invalid format. Expected JSON array of comments.' }
    } catch (e: any) {
      return { error: 'Failed to parse JSON: ' + e.message }
    }
  }

  async runImport(content: string) {
    try {
      const data = JSON.parse(content)
      if (!Array.isArray(data)) {
        return { error: 'Invalid format. Expected JSON array of comments.' }
      }

      let imported = 0
      let skipped_duplicates = 0
      const uniquePages = new Set()

      for (const comment of data) {
        try {
          // Fallbacks for optional fields
          const parentId = comment.parent_id || null
          const authorUrl = comment.author_url || null
          const status = comment.status || 'approved'
          const ip = comment.ip_address || null
          const userAgent = comment.user_agent || null
          const createdAt = comment.created_at || new Date().toISOString()
          const updatedAt = comment.updated_at || createdAt

          await this.db.prepare(`
            INSERT INTO comments (id, page_url, parent_id, author_name, author_email, author_url, content, created_at, updated_at, status, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          `).bind(
            comment.id,
            comment.page_url,
            parentId,
            comment.author_name,
            comment.author_email,
            authorUrl,
            comment.content,
            createdAt,
            updatedAt,
            status,
            ip,
            userAgent
          ).run()

          imported++
          if (comment.page_url) {
            uniquePages.add(comment.page_url)
          }
        } catch (e: any) {
          // If duplicate ID or other error, just skip
          skipped_duplicates++
        }
      }

      return {
        imported,
        skipped_duplicates,
        unique_pages: uniquePages.size
      }

    } catch (e: any) {
      return { error: 'Failed to parse JSON: ' + e.message }
    }
  }
}
