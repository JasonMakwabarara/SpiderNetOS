import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, post } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('../src/services/api.js', () => ({
  default: { get, post },
}))

import ResearchBriefs from '../src/views/research/ResearchBriefs.vue'

const brief = {
  id: '11111111-1111-1111-1111-111111111111',
  title: 'Hearty Meal distributor brief',
  status: 'draft',
  meta: {
    objective: 'Find three channels',
    audience: 'Mine canteens',
    supplied_facts: ['Shelf-stable'],
    draft: 'A convenient meal during a demanding working day.',
    sources: [{
      title: 'Public shift notice',
      url: 'https://example.com/shifts',
      excerpt: 'Day shifts run twelve hours.',
      origin: 'operator_supplied',
      evidence_status: 'supplied_unverified',
    }],
    provenance: 'Pasted from a workspace.',
    review_state: 'unreviewed',
  },
}

describe('Research briefs review surface', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    get.mockImplementation((url) => {
      if (url === '/api/research-briefs') return Promise.resolve({ data: { data: [brief] } })
      if (String(url).startsWith('/api/research-briefs/')) return Promise.resolve({ data: { data: brief } })
      return Promise.reject(new Error(url))
    })
  })

  it('opens a draft, shows the unverified source register, and submits the existing artifact path', async () => {
    const wrapper = mount(ResearchBriefs)
    await flushPromises()

    expect(wrapper.get('[data-testid="research-briefs-page"]').text()).toContain('Hearty Meal distributor brief')

    await wrapper.get(`[data-testid="research-brief-${brief.id}"]`).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="research-brief-objective"]').text()).toContain('Find three channels')
    expect(wrapper.get('[data-testid="research-brief-source-0"]').text()).toBe('Public shift notice')
    expect(wrapper.text()).toContain('supplied_unverified')
    expect(wrapper.text()).toContain('Suggestions only')

    post.mockResolvedValueOnce({ data: { data: { status: 'submitted', approval_id: 'ap-1' } } })
    await wrapper.get('[data-testid="research-brief-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith(`/api/artifacts/${brief.id}/submit`, {
      reason: 'Review research brief: Hearty Meal distributor brief',
    })
  })

  it('imports a JSON envelope as an unreviewed draft', async () => {
    post.mockResolvedValueOnce({ data: { data: brief } })
    const wrapper = mount(ResearchBriefs)
    await flushPromises()

    await wrapper.get('[data-testid="research-briefs-envelope"]').setValue(JSON.stringify({
      title: brief.title,
      objective: brief.meta.objective,
      sources: brief.meta.sources,
    }))
    await wrapper.get('[data-testid="research-briefs-import-submit"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/api/research-briefs', expect.objectContaining({
      title: brief.title,
      objective: 'Find three channels',
    }))
    expect(wrapper.get('[data-testid="research-briefs-notice"]').text()).toContain('unreviewed')
  })
})
