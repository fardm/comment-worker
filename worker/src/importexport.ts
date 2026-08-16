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
}
