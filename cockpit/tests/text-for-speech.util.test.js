// utils/textForSpeech — Vitest unit tests.
import { describe, it, expect } from 'vitest'
import {
  textForSpeech,
  speechSentences,
  splitSentences,
  stripScaffolding,
  stripMarkdown,
  capLength,
} from '../src/utils/textForSpeech.js'

describe('textForSpeech util', () => {
  it('strips Value: / Outcome: scaffolding but keeps the sentences', () => {
    const out = textForSpeech('Value: We saved 3 hours this week.\nOutcome: The invoice went out on time.')
    expect(out).toBe('We saved 3 hours this week. The invoice went out on time.')
  })

  it('strips bolded labels and bullet-wrapped labels too', () => {
    expect(stripScaffolding('- **Value:** cheaper\n**Outcome**: faster')).toBe('cheaper\nfaster')
  })

  it('drops markdown syntax without dropping the words', () => {
    const out = textForSpeech('# Weekly review\n\n- **Two** deals closed\n- See [the trace](https://x.y/z)\n\n`code` and *emphasis*')
    expect(out).toBe('Weekly review Two deals closed See the trace code and emphasis')
  })

  it('never reads fenced code aloud', () => {
    expect(stripMarkdown('Run this:\n```js\nconsole.log(1)\n```\nthen relax').replace(/\s+/g, ' ').trim()).toBe('Run this: then relax')
  })

  it('collapses whitespace', () => {
    expect(textForSpeech('  many\n\n\n   spaces \t here ')).toBe('many spaces here')
  })

  it('caps length on a sentence boundary', () => {
    const long = 'First sentence is here. Second sentence is here. Third sentence is here.'
    const out = textForSpeech(long, { maxLength: 55 })
    expect(out).toBe('First sentence is here. Second sentence is here.')
    expect(out.length).toBeLessThanOrEqual(55)
  })

  it('falls back to a word boundary when no sentence end fits', () => {
    expect(capLength('one two three four five', 12)).toBe('one two')
  })

  it('splits into sentences', () => {
    expect(splitSentences('Hello there. How are you? Fine! Ok…')).toEqual(['Hello there.', 'How are you?', 'Fine!', 'Ok…'])
    expect(splitSentences('no terminator')).toEqual(['no terminator'])
    expect(splitSentences('')).toEqual([])
  })

  it('speechSentences cleans then splits', () => {
    expect(speechSentences('Value: **Done.** Outcome: Shipped!')).toEqual(['Done.', 'Shipped!'])
  })

  it('handles nullish input', () => {
    expect(textForSpeech(null)).toBe('')
    expect(textForSpeech(undefined)).toBe('')
    expect(speechSentences(undefined)).toEqual([])
  })
})
