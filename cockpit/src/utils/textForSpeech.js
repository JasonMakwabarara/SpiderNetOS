/**
 * textForSpeech — turn an Atlas reply into something a TTS engine (or the
 * browser's speechSynthesis fallback) can read aloud without narrating
 * markdown or the "Value: / Outcome:" answer scaffolding.
 *
 *   textForSpeech(text, { maxLength })  → cleaned string
 *   speechSentences(text, { maxLength }) → cleaned string split into sentences
 *   splitSentences(text)                → sentence array (no cleaning)
 */

const DEFAULT_MAX_LENGTH = 1500

// Answer scaffolding Atlas prefixes onto structured replies. The label is
// dropped, the sentence after it is kept ("Value: we saved 3h" → "we saved 3h").
// Handles "Value:", "**Value:**", "**Value**:" and "- Value:" at a line start,
// plus a label that follows a sentence end on the same line ("Done. Outcome: …").
const LABEL = '(?:value|outcome|why|next steps?|result|summary|tl;dr)'
const SCAFFOLD_AT_LINE_START = new RegExp(`^[ \\t]*(?:[-*+][ \\t]+)?(?:\\*\\*)?${LABEL}(?:\\*\\*)?[ \\t]*:[ \\t]*(?:\\*\\*)?[ \\t]*`, 'gim')
const SCAFFOLD_AFTER_SENTENCE = new RegExp(`(?<=[.!?…]\\**[ \\t]+)(?:\\*\\*)?${LABEL}(?:\\*\\*)?[ \\t]*:[ \\t]*(?:\\*\\*)?[ \\t]*`, 'gi')

export function stripScaffolding(text) {
  return String(text ?? '')
    .replace(SCAFFOLD_AT_LINE_START, '')
    .replace(SCAFFOLD_AFTER_SENTENCE, '')
}

export function stripMarkdown(text) {
  return String(text ?? '')
    .replace(/```[\s\S]*?```/g, ' ')                   // fenced code: never read code aloud
    .replace(/<[^>\n]+>/g, ' ')                        // stray html tags
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')             // images
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')           // [text](url) → text
    .replace(/^\s{0,3}#{1,6}\s+/gm, '')                // headings
    .replace(/^\s*>\s?/gm, '')                         // blockquotes
    .replace(/^\s*(?:[-*+]|\d+[.)])\s+/gm, '')         // list markers
    .replace(/^\s*[-*_]{3,}\s*$/gm, ' ')               // horizontal rules
    .replace(/(\*\*|__)(.*?)\1/g, '$2')                // bold
    .replace(/(\*|_)(?=\S)(.*?)(?<=\S)\1/g, '$2')      // italic
    .replace(/`([^`]*)`/g, '$1')                       // inline code
    .replace(/\|/g, ' ')                               // table pipes
}

export function collapseWhitespace(text) {
  return String(text ?? '').replace(/\s+/g, ' ').trim()
}

export function splitSentences(text) {
  const clean = collapseWhitespace(text)
  if (!clean) return []
  const parts = clean.match(/[^.!?…]+(?:[.!?…]+["')\]]*|$)/g) || [clean]
  return parts.map((s) => s.trim()).filter(Boolean)
}

export function capLength(text, maxLength = DEFAULT_MAX_LENGTH) {
  const clean = String(text ?? '')
  if (!Number.isFinite(maxLength) || maxLength <= 0 || clean.length <= maxLength) return clean
  const head = clean.slice(0, maxLength)
  // Prefer ending on a sentence, then on a word, before hard-cutting.
  const lastStop = Math.max(head.lastIndexOf('. '), head.lastIndexOf('! '), head.lastIndexOf('? '))
  if (lastStop > maxLength * 0.5) return head.slice(0, lastStop + 1).trim()
  const lastSpace = head.lastIndexOf(' ')
  return (lastSpace > 0 ? head.slice(0, lastSpace) : head).trim()
}

export function textForSpeech(text, { maxLength = DEFAULT_MAX_LENGTH } = {}) {
  const stripped = collapseWhitespace(stripMarkdown(stripScaffolding(text)))
  return capLength(stripped, maxLength)
}

export function speechSentences(text, options = {}) {
  return splitSentences(textForSpeech(text, options))
}

export default textForSpeech
