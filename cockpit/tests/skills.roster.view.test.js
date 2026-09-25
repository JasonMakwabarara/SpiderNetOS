// Skills roster view — Vitest + @vue/test-utils with a memory router.
// Pillar order from meta, tiles, Enable through the store, filters that
// live in the route query, empty state, requires-pack Install link and
// the fixture fallback with an inline error.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import Skills from '../src/views/skills/Skills.vue'
import skillsFixture from './fixtures/skills.json'
import { mountView, settle, httpError } from './helpers/harness.js'

const PILLAR_ORDER = skillsFixture.meta.pillars.map((p) => p.key)

let wrapper
afterEach(() => { wrapper?.unmount(); wrapper = null })
beforeEach(() => {
  vi.resetAllMocks()
  api.get.mockResolvedValue({ data: skillsFixture })
})

describe('Skills roster view', () => {
  it('renders pillar sections in meta.pillars.order with a mini card per skill', async () => {
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/skills', { params: {} })
    const keys = wrapper.findAll('[data-testid^="skills-pillar-"]').map((s) => s.attributes('data-testid').replace('skills-pillar-', ''))
    expect(keys).toEqual(PILLAR_ORDER)
    expect(wrapper.findAll('[data-testid^="skill-mini-"]')).toHaveLength(skillsFixture.data.length)
    // Sections are labelled regions
    const first = wrapper.find('[data-testid="skills-pillar-sales"]')
    expect(first.element.tagName).toBe('SECTION')
    expect(wrapper.find(`#${first.attributes('aria-labelledby')}`).text()).toBe('Sales')
  })

  it('shows the four metric tiles', async () => {
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    const all = skillsFixture.data
    expect(wrapper.findAll('[data-testid^="skills-tile-"]')).toHaveLength(4)
    expect(wrapper.find('[data-testid="skills-tile-total"]').text()).toContain(String(all.length))
    expect(wrapper.find('[data-testid="skills-tile-enabled"]').text()).toContain(String(all.filter((s) => s.enabled).length))
    expect(wrapper.find('[data-testid="skills-tile-brain"]').text()).toContain(String(all.filter((s) => s.brain.ready).length))
    expect(wrapper.find('[data-testid="skills-tile-autonomous"]').text()).toContain(String(all.filter((s) => s.pipeline.stage === 'autonomous').length))
  })

  it('mini cards carry the stage dot, brain pill and Open link', async () => {
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    expect(wrapper.find('[data-testid="skill-stage-dot-prospect-research-analysis"]').attributes('data-stage')).toBe('autonomous')
    expect(wrapper.find('[data-testid="skill-brain-pill-cold-email-drafting"]').text()).toContain('1 brain file missing')
    expect(wrapper.find('[data-testid="skill-brain-pill-cold-email-drafting"]').classes()).toContain('sn-pill-warn')
    expect(wrapper.find('[data-testid="skill-brain-pill-icp-definition"]').classes()).toContain('sn-pill-success')
    expect(wrapper.find('[data-testid="skill-open-cold-email-drafting"]').attributes('href')).toContain('/skills/cold-email-drafting')
    expect(wrapper.find('[data-testid="skill-title-cold-email-drafting"]').element.tagName).toBe('A')
  })

  it('Enable calls the store which posts /enable and flips the pill', async () => {
    api.post.mockResolvedValueOnce({ data: { data: {} } })
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    const enable = wrapper.find('[data-testid="skill-enable-follow-up-drafting"]')
    expect(enable.exists()).toBe(true)
    await enable.trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/skills/follow-up-drafting/enable')
    expect(wrapper.find('[data-testid="skill-enabled-follow-up-drafting"]').text()).toBe('Enabled')
    expect(wrapper.find('[data-testid="skill-enable-follow-up-drafting"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="skill-notice-follow-up-drafting"]').text()).toContain('Enabled')
  })

  it('shows the enable error inline on a 402', async () => {
    api.post.mockRejectedValueOnce(httpError(402, { checkout_hint: 'Buy the Customer pack', pack_id: 'customer' }))
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    await wrapper.find('[data-testid="skill-enable-churn-radar"]').trigger('click')
    await settle()
    expect(wrapper.find('[data-testid="skill-notice-churn-radar"]').text()).toContain('Buy the Customer pack')
    expect(wrapper.find('[data-testid="skill-enabled-churn-radar"]').text()).not.toBe('Enabled')
  })

  it('filters update the route query and refetch with params', async () => {
    let router
    ;({ wrapper, router } = await mountView(Skills, { path: '/skills' }))
    await settle()
    await wrapper.find('[data-testid="skills-filter-pillar"]').setValue('deals')
    await settle()
    expect(router.currentRoute.value.query.pillar).toBe('deals')
    expect(api.get).toHaveBeenLastCalledWith('/api/skills', { params: { pillar: 'deals' } })
    expect(wrapper.findAll('[data-testid^="skills-pillar-"]').map((s) => s.attributes('data-testid'))).toEqual(['skills-pillar-deals'])

    await wrapper.find('[data-testid="skills-filter-enabled"]').setValue('0')
    await settle()
    expect(router.currentRoute.value.query).toEqual({ pillar: 'deals', enabled: '0' })
    expect(wrapper.find('[data-testid="skills-empty"]').exists()).toBe(true)

    await wrapper.find('[data-testid="skills-filter-clear"]').trigger('click')
    await settle()
    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.findAll('[data-testid^="skills-pillar-"]')).toHaveLength(PILLAR_ORDER.length)
  })

  it('reads filters from the query on load and searches by q', async () => {
    let router
    ;({ wrapper, router } = await mountView(Skills, { path: '/skills?q=newsletter' }))
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/skills', { params: { q: 'newsletter' } })
    expect(wrapper.find('[data-testid="skills-filter-q"]').element.value).toBe('newsletter')
    expect(wrapper.findAll('[data-testid^="skill-mini-"]').map((s) => s.attributes('data-testid'))).toEqual(['skill-mini-customer-newsletter'])

    const input = wrapper.find('[data-testid="skills-filter-q"]')
    await input.setValue('zzz-no-match')
    await input.trigger('change')
    await settle()
    expect(router.currentRoute.value.query.q).toBe('zzz-no-match')
    expect(wrapper.find('[data-testid="skills-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="skills-empty"]').text()).toContain('No skills match')
  })

  it('shows an Install pack link for requires_pack skills', async () => {
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    const install = wrapper.find('[data-testid="skill-install-customer-newsletter"]')
    expect(install.exists()).toBe(true)
    expect(install.element.tagName).toBe('A')
    expect(install.attributes('href')).toContain('/feature-packs')
    expect(install.attributes('href')).toContain('pack-marketing-studio')
    expect(wrapper.find('[data-testid="skill-enable-customer-newsletter"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="skill-enabled-customer-newsletter"]').text()).toBe('Needs pack')
  })

  it('renders from the fixture with an inline error when the API fails', async () => {
    api.get.mockRejectedValue(httpError(404))
    ;({ wrapper } = await mountView(Skills, { path: '/skills' }))
    await settle()
    expect(wrapper.find('[data-testid="skills-error"]').text()).toContain('HTTP 404')
    expect(wrapper.findAll('[data-testid^="skills-pillar-"]').length).toBeGreaterThan(0)
    expect(wrapper.find('[data-testid="skills-empty"]').exists()).toBe(false)
  })
})
