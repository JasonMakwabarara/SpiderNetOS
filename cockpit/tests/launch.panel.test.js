// AtlasLaunchPanel — the right column of /atlas?mode=launch (plan D7 §5).
// Status pill, progress bar, "Now filling", the question card posting through
// the Atlas store in launch mode, the jurisdiction picker, generate buttons,
// deliverables, brain readiness rows and the finish CTA at 100%.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import AtlasLaunchPanel from '../src/components/AtlasLaunchPanel.vue'
import Atlas from '../src/views/Atlas.vue'
import { useAtlasStore } from '../src/stores/atlas.js'
import { useBrainStore } from '../src/stores/brain.js'
import launchFixture from './fixtures/launch.json'
import { makeRouter, httpError, settle } from './helpers/harness.js'

const LAUNCH = launchFixture.data
const READINESS = launchFixture.readiness

async function mountPanel({ launch = LAUNCH, readiness = READINESS, getFails = false } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  await router.push('/atlas?mode=launch')
  await router.isReady()

  if (getFails) {
    api.get.mockRejectedValue(httpError(503))
  } else {
    api.get.mockResolvedValue({ data: { data: launch } })
  }

  const brain = useBrainStore()
  if (readiness) brain.applyReadiness(readiness)

  const wrapper = mount(AtlasLaunchPanel, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return { wrapper, router, pinia, atlas: useAtlasStore(), brain }
}

const testId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('AtlasLaunchPanel', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  it('loads GET /api/launch and renders the status, progress and the file being filled', async () => {
    const { wrapper } = await mountPanel()

    expect(api.get).toHaveBeenCalledWith('/api/launch')
    expect(testId(wrapper, 'launch-status-pill').text()).toBe('Building the numbers')
    expect(testId(wrapper, 'launch-stage-title').text()).toBe('Finance')
    expect(testId(wrapper, 'launch-progress').attributes('data-pct')).toBe('62')
    expect(testId(wrapper, 'launch-progress-label').text()).toContain('5 of 9 stages')
    expect(testId(wrapper, 'launch-now-filling').text()).toBe('Now filling: finance/assumptions.yaml')
    expect(testId(wrapper, 'launch-disclaimer').text()).toBe('Not legal or financial advice.')
  })

  it('falls back to the fixture with an inline notice when the API is down', async () => {
    const { wrapper } = await mountPanel({ getFails: true })

    expect(testId(wrapper, 'launch-error').text()).toContain('your business launch')
    expect(testId(wrapper, 'launch-error').text()).toContain('sample data')
    expect(testId(wrapper, 'launch-status-pill').exists()).toBe(true)
  })

  it('shows the question Atlas is waiting on and answers it through the launch thread', async () => {
    const { wrapper, atlas } = await mountPanel()
    const send = vi.spyOn(atlas, 'sendMessage').mockResolvedValue({ success: true })

    expect(testId(wrapper, 'launch-question-prompt').text()).toContain('How much money can you put in')

    await testId(wrapper, 'launch-answer-input').setValue('About 10,000')
    await testId(wrapper, 'launch-answer-send').trigger('click')
    await flushPromises()

    expect(send).toHaveBeenCalledWith('About 10,000', { mode: 'launch' })
    expect(testId(wrapper, 'launch-answer-input').element.value).toBe('')
  })

  it('a choice question answers with one click', async () => {
    const launch = {
      ...LAUNCH,
      next_question: { ...LAUNCH.next_question, id: 'jurisdiction', type: 'choice', choices: ['uk', 'za', 'zw'], prompt: 'Which country?' },
    }
    const { wrapper, atlas } = await mountPanel({ launch })
    const send = vi.spyOn(atlas, 'sendMessage').mockResolvedValue({ success: true })

    await testId(wrapper, 'launch-choice-za').trigger('click')
    await flushPromises()

    expect(send).toHaveBeenCalledWith('za', { mode: 'launch' })
  })

  it('an optional question can be skipped through POST /api/launch/answer', async () => {
    const launch = { ...LAUNCH, next_question: { ...LAUNCH.next_question, required: false } }
    const { wrapper } = await mountPanel({ launch })
    api.post.mockResolvedValueOnce({ data: { data: { ...LAUNCH, next_question: null, progress_pct: 70 } } })

    expect(testId(wrapper, 'launch-answer-skip').exists()).toBe(true)
    await testId(wrapper, 'launch-answer-skip').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/launch/answer', {
      question_id: 'starting_cash',
      answer: '',
      skip: true,
    })
    expect(testId(wrapper, 'launch-interview-done').exists()).toBe(true)
  })

  it('a required question offers no skip', async () => {
    const { wrapper } = await mountPanel()
    expect(testId(wrapper, 'launch-answer-skip').exists()).toBe(false)
  })

  it('the jurisdiction picker marks the current country and posts a change', async () => {
    const { wrapper } = await mountPanel()
    api.post.mockResolvedValueOnce({ data: { data: { ...LAUNCH, jurisdiction: 'za' } } })

    expect(testId(wrapper, 'launch-jurisdiction-uk').attributes('aria-pressed')).toBe('true')
    expect(testId(wrapper, 'launch-jurisdiction-za').attributes('aria-pressed')).toBe('false')

    await testId(wrapper, 'launch-jurisdiction-za').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/launch/start', { jurisdiction: 'za' })
    expect(testId(wrapper, 'launch-jurisdiction-za').attributes('aria-pressed')).toBe('true')
  })

  it('generates the finance model and the plan through POST /api/launch/generate', async () => {
    const { wrapper } = await mountPanel()
    api.post.mockResolvedValueOnce({ data: launchFixture.generate })

    await testId(wrapper, 'launch-generate-finance').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/launch/generate', { targets: ['finance'] })
    expect(testId(wrapper, 'launch-progress').attributes('data-pct')).toBe('78')
    expect(testId(wrapper, 'launch-deliverable-finance-model-xlsx').exists()).toBe(true)
    expect(testId(wrapper, 'launch-deliverable-finance-model-xlsx').attributes('data-available')).toBe('true')

    api.post.mockResolvedValueOnce({ data: { data: { results: { plan: { ok: true } }, state: LAUNCH } } })
    await testId(wrapper, 'launch-generate-plan').trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenLastCalledWith('/api/launch/generate', { targets: ['plan'] })
  })

  it('a failed generate is reported inline, never thrown', async () => {
    const { wrapper } = await mountPanel()
    api.post.mockRejectedValueOnce(httpError(422, { message: 'Answer the finance questions first' }))

    await testId(wrapper, 'launch-generate-finance').trigger('click')
    await flushPromises()

    expect(testId(wrapper, 'launch-error').text()).toContain('Answer the finance questions first')
  })

  it('lists deliverables, flagging the ones a renderer could not produce', async () => {
    const launch = {
      ...LAUNCH,
      deliverables: [
        { path: 'plan/business-plan.docx', title: 'Business plan (Word)', available: true, format: 'docx' },
        { path: 'plan/business-plan.pdf', title: 'Business plan (PDF)', available: false, reason: 'weasyprint is not installed' },
      ],
    }
    const { wrapper } = await mountPanel({ launch })

    expect(testId(wrapper, 'launch-deliverable-plan-business-plan-docx').attributes('data-available')).toBe('true')
    expect(testId(wrapper, 'launch-deliverable-plan-business-plan-pdf').attributes('data-available')).toBe('false')
    expect(wrapper.findAll('[data-testid^="launch-deliverable-"]')).toHaveLength(2)
  })

  it('shows an empty deliverables note before anything is generated', async () => {
    const { wrapper } = await mountPanel({ launch: { ...LAUNCH, deliverables: [] } })
    expect(testId(wrapper, 'launch-deliverables-empty').exists()).toBe(true)
  })

  it('renders the brain readiness rows and asks Atlas about a thin file', async () => {
    const { wrapper, atlas } = await mountPanel()
    const send = vi.spyOn(atlas, 'sendMessage').mockResolvedValue({ success: true })

    expect(wrapper.find('[data-testid="brain-readiness"]').exists()).toBe(true)
    expect(testId(wrapper, 'brain-readiness-pill').text()).toBe('3/6 ready')

    await testId(wrapper, 'brain-file-ask-offer').trigger('click')
    await flushPromises()

    expect(send).toHaveBeenCalledWith(
      'What makes you different from the obvious alternative?',
      { mode: 'launch' },
    )
  })

  it('opens a brain file in the drawer', async () => {
    const { wrapper } = await mountPanel()
    api.get.mockResolvedValueOnce({
      data: { data: { path: 'offer/offer.md', title: 'Offer', status: 'partial', version: 1, content: '## Pricing\n\nGBP 100.' } },
    })

    await testId(wrapper, 'brain-file-open-offer').trigger('click')
    await settle(3)

    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    expect(document.body.querySelector('[data-testid="brain-file-drawer-path"]')?.textContent).toContain('offer/offer.md')
  })

  it('offers the finish CTA to the map only at 100%', async () => {
    const { wrapper } = await mountPanel()
    expect(testId(wrapper, 'launch-finish').exists()).toBe(false)

    const done = await mountPanel({ launch: { ...LAUNCH, progress_pct: 100, status: 'live' } })
    expect(testId(done.wrapper, 'launch-finish').exists()).toBe(true)
    expect(testId(done.wrapper, 'launch-status-pill').text()).toBe('Live')

    const push = vi.spyOn(done.router, 'push')
    await testId(done.wrapper, 'launch-finish-cta').trigger('click')
    expect(push).toHaveBeenCalledWith('/map')
  })

  it('a launch turn from the chat refreshes the panel without another GET', async () => {
    const { wrapper, atlas } = await mountPanel()
    api.get.mockClear()

    atlas.launch = { ...LAUNCH, progress_pct: 91, status: 'drafted', stage_title: 'Business plan', now_filling: null }
    await flushPromises()

    expect(testId(wrapper, 'launch-progress').attributes('data-pct')).toBe('91')
    expect(testId(wrapper, 'launch-status-pill').text()).toBe('Plan drafted')
    expect(api.get).not.toHaveBeenCalled()
  })
})

describe('Atlas view — ?mode=launch', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  async function mountAtlas(path) {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeRouter()
    await router.push(path)
    await router.isReady()

    api.get.mockResolvedValue({ data: { data: LAUNCH } })
    api.post.mockResolvedValue({ data: { session_id: 'sess-1', message: { id: 'm', metadata: {} } } })

    const wrapper = mount(Atlas, { global: { plugins: [pinia, router] } })
    await settle(3)

    return { wrapper, atlas: useAtlasStore() }
  }

  it('swaps the right column for the launch panel and opens the interview in launch mode', async () => {
    const { wrapper } = await mountAtlas('/atlas?mode=launch')

    expect(wrapper.find('[data-testid="atlas-launch-panel"]').exists()).toBe(true)
    const chat = api.post.mock.calls.find(([url]) => url === '/api/atlas/chat')
    expect(chat[1]).toMatchObject({ mode: 'launch', message: 'I want to start a business.' })
  })

  it('leaves the ordinary Atlas context panel alone without the mode', async () => {
    const { wrapper } = await mountAtlas('/atlas')

    expect(wrapper.find('[data-testid="atlas-launch-panel"]').exists()).toBe(false)
    const chat = api.post.mock.calls.find(([url]) => url === '/api/atlas/chat')
    expect(chat).toBeUndefined()
  })
})
