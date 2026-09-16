// Brain view — Vitest + @vue/test-utils with a memory router.
// Tree + readiness + gaps, file selection through ?file=, markdown
// preview, edit → save with base_version, 409 conflict line, versions →
// revert with confirm, sync, search, and the fixture fallback.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.mock('../src/services/api.js', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import api from '../src/services/api.js'
import Brain from '../src/views/brain/Brain.vue'
import treeFixture from './fixtures/brain_tree.json'
import fileFixture from './fixtures/brain_file.json'
import { mountView, settle, q, httpError } from './helpers/harness.js'

function mockReads() {
  api.get.mockImplementation((url) => {
    if (url === '/api/brain/tree') return Promise.resolve({ data: { data: treeFixture.data } })
    if (url === '/api/brain/readiness') return Promise.resolve({ data: treeFixture.readiness })
    if (url === '/api/brain/gaps') return Promise.resolve({ data: treeFixture.gaps })
    if (url === '/api/brain/files/offer/offer.md') return Promise.resolve({ data: fileFixture })
    if (url === '/api/brain/files/offer/offer.md/versions') return Promise.resolve({ data: fileFixture.versions })
    if (url.endsWith('/versions')) return Promise.resolve({ data: { data: [] } })
    return Promise.reject(httpError(404))
  })
}

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})
beforeEach(() => {
  vi.resetAllMocks()
  mockReads()
})

describe('Brain view', () => {
  it('renders the folder tree with status dots, the readiness bar and the gaps banner', async () => {
    ;({ wrapper } = await mountView(Brain, { path: '/brain' }))
    await settle()
    expect(wrapper.findAll('[data-testid^="brain-folder-"]')).toHaveLength(treeFixture.data.folders.length)
    expect(wrapper.find('[data-testid="brain-tree-file-offer-offer-md"]').attributes('data-status')).toBe('partial')
    expect(wrapper.find('[data-testid="brain-tree-file-people-user-md"]').attributes('data-status')).toBe('missing')
    expect(wrapper.find('[data-testid="brain-readiness-pct"]').text()).toBe('55%')
    expect(wrapper.find('[data-testid="brain-readiness-counts"]').text()).toContain('4 filled')
    expect(wrapper.find('[data-testid="brain-gaps"]').text()).toContain('3 gaps blocking skills')
    expect(wrapper.find('[data-testid="brain-file-none"]').exists()).toBe(true)
  })

  it('selects a file through ?file=, previews the markdown and lists versions', async () => {
    let router
    ;({ wrapper, router } = await mountView(Brain, { path: '/brain' }))
    await settle()
    await wrapper.find('[data-testid="brain-tree-file-offer-offer-md"]').trigger('click')
    await settle()
    expect(router.currentRoute.value.query.file).toBe('offer/offer.md')
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    expect(wrapper.find('[data-testid="brain-file-title"]').text()).toBe('Offer')
    expect(wrapper.find('[data-testid="brain-file-status"]').text()).toBe('partial')
    expect(wrapper.find('[data-testid="brain-file-path"]').text()).toContain('v2')
    const preview = wrapper.find('[data-testid="brain-file-preview"]')
    expect(preview.find('h1').text()).toBe('Offer')
    expect(preview.find('strong').text()).toBe('Apex Cockpit')
    expect(wrapper.findAll('[data-testid^="brain-version-"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="brain-revert-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="brain-revert-2"]').exists()).toBe(false) // current
    expect(wrapper.find('[data-testid="brain-tree-file-offer-offer-md"]').attributes('aria-current')).toBe('true')
  })

  it('edits in the drawer and saves with base_version', async () => {
    api.put.mockResolvedValueOnce({ data: { data: { path: 'offer/offer.md', version: 3, status: 'filled', updated_at: '2026-09-16T10:00:00Z' } } })
    ;({ wrapper } = await mountView(Brain, { path: '/brain?file=offer%2Foffer.md', attachTo: document.body }))
    await settle()
    await wrapper.find('[data-testid="brain-file-edit"]').trigger('click')
    await settle()
    const editor = q('[data-testid="brain-file-editor"]')
    expect(editor).not.toBeNull()
    expect(editor.value).toContain('# Offer')
    editor.value = '# Offer\n\nRewritten.'
    editor.dispatchEvent(new Event('input', { bubbles: true }))
    await settle()
    q('[data-testid="brain-file-save"]').click()
    await settle()
    expect(api.put).toHaveBeenCalledWith('/api/brain/files/offer/offer.md', { content: '# Offer\n\nRewritten.', base_version: 2 })
    expect(q('[data-testid="brain-file-editor"]')).toBeNull()
    expect(wrapper.find('[data-testid="brain-file-notice"]').text()).toContain('Saved v3')
    expect(wrapper.find('[data-testid="brain-file-status"]').text()).toBe('filled')
    expect(wrapper.find('[data-testid="brain-tree-file-offer-offer-md"]').attributes('data-status')).toBe('filled')
  })

  it('shows the conflict line on a 409 and reloads on request', async () => {
    api.put.mockRejectedValueOnce(httpError(409, { current_version: 4 }))
    ;({ wrapper } = await mountView(Brain, { path: '/brain?file=offer%2Foffer.md', attachTo: document.body }))
    await settle()
    await wrapper.find('[data-testid="brain-file-edit"]').trigger('click')
    await settle()
    q('[data-testid="brain-file-save"]').click()
    await settle()
    const conflict = wrapper.find('[data-testid="brain-conflict"]')
    expect(conflict.exists()).toBe(true)
    expect(conflict.attributes('role')).toBe('alert')
    expect(conflict.text()).toContain('v4')
    // Drawer stays open so nothing typed is lost
    expect(q('[data-testid="brain-file-editor"]')).not.toBeNull()
    api.get.mockClear()
    await wrapper.find('[data-testid="brain-conflict-reload"]').trigger('click')
    await settle()
    expect(api.get).toHaveBeenCalledWith('/api/brain/files/offer/offer.md')
    expect(wrapper.find('[data-testid="brain-conflict"]').exists()).toBe(false)
  })

  it('reverts a version after confirming', async () => {
    api.post.mockResolvedValue({ data: { data: { path: 'offer/offer.md', version: 3, status: 'partial', content: '# Offer v1' } } })
    ;({ wrapper } = await mountView(Brain, { path: '/brain?file=offer%2Foffer.md', attachTo: document.body }))
    await settle()
    await wrapper.find('[data-testid="brain-revert-1"]').trigger('click')
    await settle()
    const dialog = q('[role="dialog"]')
    expect(dialog).not.toBeNull()
    expect(dialog.textContent).toContain('Revert this file?')
    expect(api.post).not.toHaveBeenCalled()
    Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent.trim() === 'Revert').click()
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/brain/files/offer/offer.md/revert', { version: 1 })
    expect(wrapper.find('[data-testid="brain-file-notice"]').text()).toContain('Reverted to v1')
    expect(wrapper.find('[data-testid="brain-file-preview"]').text()).toContain('Offer v1')
  })

  it('syncs and filters the tree by search', async () => {
    api.post.mockResolvedValueOnce({ data: { data: {} } })
    ;({ wrapper } = await mountView(Brain, { path: '/brain' }))
    await settle()
    await wrapper.find('[data-testid="brain-sync"]').trigger('click')
    await settle()
    expect(api.post).toHaveBeenCalledWith('/api/brain/sync')
    expect(wrapper.find('[data-testid="brain-sync-notice"]').text()).toBe('Synced.')

    await wrapper.find('[data-testid="brain-search"]').setValue('voice')
    await settle()
    expect(wrapper.findAll('[data-testid^="brain-tree-file-"]').map((b) => b.attributes('data-testid'))).toEqual(['brain-tree-file-brand-voice-md'])
    await wrapper.find('[data-testid="brain-search"]').setValue('nothing-here')
    await settle()
    expect(wrapper.find('[data-testid="brain-tree-empty"]').exists()).toBe(true)
  })

  it('renders from the fixture with an inline error when the API is down', async () => {
    api.get.mockRejectedValue(httpError(500))
    ;({ wrapper } = await mountView(Brain, { path: '/brain?file=offer%2Foffer.md' }))
    await settle()
    expect(wrapper.find('[data-testid="brain-error"]').text()).toContain('HTTP 500')
    expect(wrapper.findAll('[data-testid^="brain-folder-"]').length).toBeGreaterThan(0)
    expect(wrapper.find('[data-testid="brain-file-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="brain-file-preview"]').find('h1').text()).toBe('Offer')
  })
})
