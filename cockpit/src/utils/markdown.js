/**
 * Tiny, dependency-free Markdown → HTML renderer for brain files and
 * skill copy. Deliberately small: headings (#, ##, ###), **bold**,
 * *italic*, `code`, https links, "- " / "* " bullet lists, "1. " numbered
 * lists and paragraphs. Everything is HTML-escaped BEFORE inline
 * formatting is applied, so raw markup in a file can never reach the DOM.
 */

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c])
}

// Inline formatting runs on already-escaped text; every replacement only
// ever introduces our own tags around escaped content.
function inline(escaped) {
  return escaped
    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
    .replace(
      /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    )
}

export function renderMarkdown(markdown) {
  const lines = String(markdown ?? '').replace(/\r\n?/g, '\n').split('\n')
  const out = []
  let paragraph = []
  let list = null // { type: 'ul' | 'ol', items: [] }

  const flushParagraph = () => {
    if (!paragraph.length) return
    out.push(`<p>${inline(escapeHtml(paragraph.join('\n')))}</p>`)
    paragraph = []
  }
  const flushList = () => {
    if (!list) return
    const items = list.items.map((i) => `<li>${inline(escapeHtml(i))}</li>`).join('')
    out.push(`<${list.type}>${items}</${list.type}>`)
    list = null
  }

  for (const raw of lines) {
    const line = raw.replace(/\s+$/, '')
    if (!line.trim()) { flushParagraph(); flushList(); continue }

    const heading = /^(#{1,3})\s+(.+)$/.exec(line)
    if (heading) {
      flushParagraph(); flushList()
      const level = heading[1].length
      out.push(`<h${level}>${inline(escapeHtml(heading[2].trim()))}</h${level}>`)
      continue
    }

    const bullet = /^\s*[-*]\s+(.+)$/.exec(line)
    if (bullet) {
      flushParagraph()
      if (!list || list.type !== 'ul') { flushList(); list = { type: 'ul', items: [] } }
      list.items.push(bullet[1])
      continue
    }

    const numbered = /^\s*\d+[.)]\s+(.+)$/.exec(line)
    if (numbered) {
      flushParagraph()
      if (!list || list.type !== 'ol') { flushList(); list = { type: 'ol', items: [] } }
      list.items.push(numbered[1])
      continue
    }

    flushList()
    paragraph.push(line.trim())
  }

  flushParagraph()
  flushList()
  return out.join('\n')
}

export default renderMarkdown
