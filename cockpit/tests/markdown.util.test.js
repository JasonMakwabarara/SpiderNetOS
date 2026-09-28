// utils/markdown — Vitest unit tests.
// The renderer is the only thing standing between a brain file and
// v-html, so escaping is the headline assertion.
import { describe, it, expect } from 'vitest'
import { renderMarkdown, escapeHtml } from '../src/utils/markdown.js'

describe('markdown util', () => {
  it('escapes raw HTML so <script> never reaches the DOM', () => {
    const html = renderMarkdown('<script>alert(1)</script>')
    expect(html).toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
    expect(html).not.toContain('<script')
  })

  it('escapes attributes-in-waiting inside bold and links', () => {
    expect(renderMarkdown('**<img src=x onerror=alert(1)>**')).toBe(
      '<p><strong>&lt;img src=x onerror=alert(1)&gt;</strong></p>',
    )
    // Only http(s) links are linkified; javascript: stays plain text.
    expect(renderMarkdown('[x](javascript:alert(1))')).toBe('<p>[x](javascript:alert(1))</p>')
    expect(renderMarkdown('[Site](https://example.com/a?b=1&c=2)')).toBe(
      '<p><a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">Site</a></p>',
    )
  })

  it('renders # / ## / ### headings', () => {
    expect(renderMarkdown('# One\n## Two\n### Three')).toBe('<h1>One</h1>\n<h2>Two</h2>\n<h3>Three</h3>')
    // Four hashes is not a supported heading → paragraph
    expect(renderMarkdown('#### Four')).toBe('<p>#### Four</p>')
  })

  it('renders bold, italic and inline code', () => {
    expect(renderMarkdown('We sell **clarity** and *speed* via `atlas`.')).toBe(
      '<p>We sell <strong>clarity</strong> and <em>speed</em> via <code>atlas</code>.</p>',
    )
  })

  it('renders bullet and numbered lists', () => {
    expect(renderMarkdown('- a\n- b\n* c')).toBe('<ul><li>a</li><li>b</li><li>c</li></ul>')
    expect(renderMarkdown('1. first\n2. second')).toBe('<ol><li>first</li><li>second</li></ol>')
  })

  it('splits paragraphs on blank lines and keeps soft breaks inside one', () => {
    expect(renderMarkdown('line one\nline two\n\nnext para')).toBe('<p>line one\nline two</p>\n<p>next para</p>')
  })

  it('handles empty and nullish input', () => {
    expect(renderMarkdown('')).toBe('')
    expect(renderMarkdown(null)).toBe('')
    expect(renderMarkdown(undefined)).toBe('')
  })

  it('normalises CRLF input', () => {
    expect(renderMarkdown('# Hi\r\n\r\n- a\r\n- b')).toBe('<h1>Hi</h1>\n<ul><li>a</li><li>b</li></ul>')
  })

  it('escapeHtml covers the five specials', () => {
    expect(escapeHtml(`<a href="x" title='y'>&</a>`)).toBe('&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;')
  })
})
