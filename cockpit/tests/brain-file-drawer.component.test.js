// BrainFileDrawer component — Vitest + @vue/test-utils (happy-dom).
// Open/editor state, save payload, escaped markdown preview, Atlas
// hand-off (router push with hannah=1 + prefill), Esc close + focus return.
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'

const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import BrainFileDrawer from '../src/components/brain/BrainFileDrawer.vue'

const q = (sel) => document.body.querySelector(sel)

const file = {
  key: 'offer',
  path: 'brain/offer.md',
  title: 'Offer',
  status: 'partial',
  body_md: '# Offer\n\nWe sell **clarity**.',
  ask_prompt: 'Help me write my offer',
}

let wrapper
beforeEach(() => { push.mockReset() })
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

function mountDrawer(props = {}) {
  return mount(BrainFileDrawer, {
    props: { modelValue: true, file, ...props },
    attachTo: document.body,
  })
}

async function type(el, value) {
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

describe('BrainFileDrawer component', () => {
  it('opens with the file title, status pill, path and prefilled editor', () => {
    wrapper = mountDrawer()
    expect(q('[data-testid="side-drawer"]')).not.toBeNull()
    expect(q('[data-testid="brain-file-drawer-title"]').textContent).toBe('Offer')
    expect(q('[data-testid="brain-file-drawer-status"]').textContent.trim()).toBe('Partial')
    expect(q('[data-testid="brain-file-drawer-status"]').className).toContain('sn-pill-warn')
    expect(q('[data-testid="brain-file-drawer-path"]').textContent).toBe('brain/offer.md')
    expect(q('[data-testid="brain-file-editor"]').value).toBe(file.body_md)
    expect(q('[data-testid="brain-file-dirty"]')).toBeNull()
  })

  it('tracks edits and emits save({ path, body_md }) without calling any API', async () => {
    wrapper = mountDrawer()
    await type(q('[data-testid="brain-file-editor"]'), '# Offer\n\nNew body')
    expect(q('[data-testid="brain-file-dirty"]')).not.toBeNull()
    q('[data-testid="brain-file-save"]').click()
    expect(wrapper.emitted('save')).toEqual([[{ path: 'brain/offer.md', body_md: '# Offer\n\nNew body' }]])
  })

  it('disables Save while saving', () => {
    wrapper = mountDrawer({ saving: true })
    expect(q('[data-testid="brain-file-save"]').disabled).toBe(true)
    expect(q('[data-testid="brain-file-save"]').textContent).toContain('Saving')
  })

  it('renders the preview as escaped HTML (no live <script>)', async () => {
    wrapper = mountDrawer({ file: { ...file, body_md: '# Offer\n<script>alert(1)</script>\n**bold** and - not a list' } })
    q('[data-testid="brain-file-preview-toggle"]').click()
    await nextTick()
    expect(q('[data-testid="brain-file-editor"]')).toBeNull()
    const preview = q('[data-testid="brain-file-preview"]')
    expect(preview).not.toBeNull()
    expect(preview.querySelector('h1').textContent).toBe('Offer')
    expect(preview.querySelector('strong').textContent).toBe('bold')
    // The <script> must land as TEXT inside the paragraph, never as an element.
    // (Asserted on the DOM rather than innerHTML: happy-dom's serializer does
    // not re-escape text nodes, so innerHTML would be misleading either way.)
    expect(preview.querySelector('script')).toBeNull()
    expect(preview.querySelector('p').textContent).toContain('<script>alert(1)</script>')
    expect(preview.textContent).not.toContain('&lt;')

    // Toggling back restores the editor with the draft intact
    q('[data-testid="brain-file-preview-toggle"]').click()
    await nextTick()
    expect(q('[data-testid="brain-file-editor"]').value).toContain('<script>alert(1)</script>')
  })

  it('hands off to Atlas with hannah=1 + the file prompt and closes', () => {
    wrapper = mountDrawer()
    q('[data-testid="brain-file-ask-atlas"]').click()
    expect(push).toHaveBeenCalledWith({ path: '/atlas', query: { hannah: '1', prefill: 'Help me write my offer' } })
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
    expect(wrapper.emitted('ask')[0][0].prefill).toBe('Help me write my offer')
  })

  it('falls back to a generated prefill when the file has no ask_prompt', () => {
    wrapper = mountDrawer({ file: { ...file, ask_prompt: undefined } })
    q('[data-testid="brain-file-ask-atlas"]').click()
    const arg = push.mock.calls[0][0]
    expect(arg.path).toBe('/atlas')
    expect(arg.query.hannah).toBe('1')
    expect(arg.query.prefill).toContain('Offer')
    expect(arg.query.prefill).toContain('brain/offer.md')
  })

  it('closes on Escape and returns focus to the opener', async () => {
    const Host = defineComponent({
      setup() {
        const open = ref(false)
        return { open }
      },
      render() {
        return h('div', [
          h('button', { 'data-testid': 'opener', onClick: () => { this.open = true } }, 'open'),
          h(BrainFileDrawer, { modelValue: this.open, 'onUpdate:modelValue': (v) => { this.open = v }, file }),
        ])
      },
    })
    wrapper = mount(Host, { attachTo: document.body })
    const opener = wrapper.find('[data-testid="opener"]').element
    opener.focus()
    await wrapper.find('[data-testid="opener"]').trigger('click')
    await nextTick()
    await nextTick()

    const drawer = q('[data-testid="side-drawer"]')
    expect(drawer).not.toBeNull()
    // autofocus lands on the editor
    expect(document.activeElement).toBe(q('[data-testid="brain-file-editor"]'))

    drawer.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(q('[data-testid="side-drawer"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
  })
})
