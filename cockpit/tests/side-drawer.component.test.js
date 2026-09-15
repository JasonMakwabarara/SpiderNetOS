// SideDrawer component — Vitest + @vue/test-utils (happy-dom).
// The drawer teleports to <body>, so assertions go through the document
// rather than the wrapper. Covers: render/width, Esc + backdrop close,
// header slot, focus-in / focus-return and the Tab trap.
import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import SideDrawer from '../src/components/data/SideDrawer.vue'

const q = (sel) => document.body.querySelector(sel)

function key(el, k, init = {}) {
  el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }))
}

let wrapper
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

function mountDrawer(props = {}, slots = {}) {
  return mount(SideDrawer, {
    props: { modelValue: true, title: 'Offer', ...props },
    slots,
    attachTo: document.body,
  })
}

describe('SideDrawer component', () => {
  it('renders nothing while closed', () => {
    wrapper = mountDrawer({ modelValue: false })
    expect(q('[data-testid="side-drawer"]')).toBeNull()
  })

  it('teleports an aria-modal dialog to body with the requested width', () => {
    wrapper = mountDrawer({ width: '640px' }, { default: () => h('p', { 'data-testid': 'drawer-child' }, 'hello') })
    const el = q('[data-testid="side-drawer"]')
    expect(el).not.toBeNull()
    expect(el.getAttribute('role')).toBe('dialog')
    expect(el.getAttribute('aria-modal')).toBe('true')
    expect(el.style.width).toBe('640px')
    expect(q('[data-testid="side-drawer-title"]').textContent).toBe('Offer')
    expect(q('[data-testid="drawer-child"]')).not.toBeNull()
  })

  it('renders a custom header slot instead of the default title', () => {
    wrapper = mountDrawer({}, { header: () => h('div', { 'data-testid': 'custom-header' }, 'Custom') })
    expect(q('[data-testid="custom-header"]').textContent).toBe('Custom')
    expect(q('[data-testid="side-drawer-title"]')).toBeNull()
  })

  it('closes on Escape', () => {
    wrapper = mountDrawer()
    key(q('[data-testid="side-drawer"]'), 'Escape')
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('closes on backdrop click but not on a click inside the panel', () => {
    wrapper = mountDrawer()
    q('[data-testid="side-drawer"]').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    q('[data-testid="side-drawer-backdrop"]').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
  })

  it('ignores backdrop clicks when closeOnBackdrop is false', () => {
    wrapper = mountDrawer({ closeOnBackdrop: false })
    q('[data-testid="side-drawer-backdrop"]').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('closes from the header close button', () => {
    wrapper = mountDrawer()
    q('[data-testid="side-drawer-close"]').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
  })

  it('moves focus into the drawer on open and returns it to the opener on close', async () => {
    const Host = defineComponent({
      setup() {
        const open = ref(false)
        return { open }
      },
      render() {
        return h('div', [
          h('button', { 'data-testid': 'opener', onClick: () => { this.open = true } }, 'open'),
          h(
            SideDrawer,
            { modelValue: this.open, 'onUpdate:modelValue': (v) => { this.open = v }, title: 'T' },
            { default: () => h('button', { 'data-testid': 'inner' }, 'inner') },
          ),
        ])
      },
    })
    wrapper = mount(Host, { attachTo: document.body })
    const opener = wrapper.find('[data-testid="opener"]').element
    opener.focus()
    expect(document.activeElement).toBe(opener)

    await wrapper.find('[data-testid="opener"]').trigger('click')
    await nextTick()
    await nextTick()
    const drawer = q('[data-testid="side-drawer"]')
    expect(drawer).not.toBeNull()
    expect(drawer.contains(document.activeElement)).toBe(true)

    key(drawer, 'Escape')
    await nextTick()
    expect(q('[data-testid="side-drawer"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
  })

  it('traps Tab inside the panel (wraps last → first and first → last)', async () => {
    wrapper = mountDrawer({}, {
      default: () => [
        h('button', { 'data-testid': 'b1' }, 'one'),
        h('button', { 'data-testid': 'b2' }, 'two'),
      ],
    })
    await nextTick()
    const first = q('[data-testid="side-drawer-close"]')
    const last = q('[data-testid="b2"]')

    last.focus()
    key(last, 'Tab')
    expect(document.activeElement).toBe(first)

    first.focus()
    key(first, 'Tab', { shiftKey: true })
    expect(document.activeElement).toBe(last)
  })
})
