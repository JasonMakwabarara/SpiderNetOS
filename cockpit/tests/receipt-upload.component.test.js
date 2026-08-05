// ReceiptUpload component — Vitest + @vue/test-utils.
// Client-side validation: mime whitelist + 10MB cap; emits 'upload'
// with the File for valid picks.
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ReceiptUpload from '../src/components/financial/ReceiptUpload.vue'

function makeFile(name, type, sizeBytes) {
  const file = new File(['x'], name, { type })
  Object.defineProperty(file, 'size', { value: sizeBytes })
  return file
}

async function drop(wrapper, file) {
  const zone = wrapper.find('[data-testid="receipt-dropzone"]')
  await zone.trigger('drop', { dataTransfer: { files: [file] } })
}

describe('ReceiptUpload component', () => {
  it('emits upload for a valid image', async () => {
    const wrapper = mount(ReceiptUpload)
    const file = makeFile('receipt.jpg', 'image/jpeg', 1024)
    await drop(wrapper, file)
    expect(wrapper.emitted('upload')).toHaveLength(1)
    expect(wrapper.emitted('upload')[0][0]).toBe(file)
    expect(wrapper.find('[data-testid="receipt-error"]').exists()).toBe(false)
  })

  it('emits upload for a valid pdf', async () => {
    const wrapper = mount(ReceiptUpload)
    await drop(wrapper, makeFile('invoice.pdf', 'application/pdf', 2048))
    expect(wrapper.emitted('upload')).toHaveLength(1)
  })

  it('rejects an oversize file (>10MB)', async () => {
    const wrapper = mount(ReceiptUpload)
    await drop(wrapper, makeFile('huge.png', 'image/png', 10 * 1024 * 1024 + 1))
    expect(wrapper.emitted('upload')).toBeUndefined()
    expect(wrapper.find('[data-testid="receipt-error"]').text()).toContain('10 MB')
  })

  it('rejects a disallowed mime type', async () => {
    const wrapper = mount(ReceiptUpload)
    await drop(wrapper, makeFile('malware.exe', 'application/x-msdownload', 1024))
    expect(wrapper.emitted('upload')).toBeUndefined()
    expect(wrapper.find('[data-testid="receipt-error"]').text()).toContain('Unsupported file type')
  })

  it('shows the progress bar mid-upload', async () => {
    const wrapper = mount(ReceiptUpload, { props: { progress: 42 } })
    expect(wrapper.find('[data-testid="receipt-progress"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="receipt-progress"]').text()).toContain('42%')
  })

  it('hides the progress bar at 0 and 100', () => {
    expect(mount(ReceiptUpload, { props: { progress: 0 } })
      .find('[data-testid="receipt-progress"]').exists()).toBe(false)
    expect(mount(ReceiptUpload, { props: { progress: 100 } })
      .find('[data-testid="receipt-progress"]').exists()).toBe(false)
  })
})
