import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, post } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('../src/services/api.js', () => ({
  default: { get, post },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

import People from '../src/views/operations/People.vue'

const draft = { id: 'run-1', status: 'draft' }

async function mountWithDraft() {
  const wrapper = mount(People, { global: { stubs: { 'router-link': true } } })
  await flushPromises()
  await wrapper.find('[data-testid="payroll-start"]').setValue('2026-12-01')
  await wrapper.find('[data-testid="payroll-end"]').setValue('2026-12-31')
  await wrapper.find('[data-testid="payroll-draft"]').trigger('submit')
  await flushPromises()
  return wrapper
}

describe('People payroll panel', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    get.mockImplementation((url) => {
      if (url === '/api/enterprise/employees') return Promise.resolve({ data: { data: [] } })
      if (url === '/api/enterprise/departments') return Promise.resolve({ data: { data: [] } })
      if (url === '/api/enterprise/hr/settings') return Promise.resolve({ data: { data: {} } })
      return Promise.reject(new Error(url))
    })
    post.mockImplementation((url) => {
      if (url === '/api/enterprise/payroll-runs') return Promise.resolve({ data: { data: draft } })
      return Promise.reject(new Error(url))
    })
  })

  it('shows posted, not an error, when the post response is lost but the run posted', async () => {
    const wrapper = await mountWithDraft()
    post.mockImplementation(() => Promise.reject(new Error('Network Error')))
    get.mockImplementation((url) => (url === '/api/enterprise/payroll-runs/run-1'
      ? Promise.resolve({ data: { data: { id: 'run-1', status: 'posted' } } })
      : Promise.resolve({ data: { data: [] } })))

    await wrapper.find('[data-testid="payroll-post"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-status"]').text()).toBe('posted')
    expect(wrapper.find('[data-testid="payroll-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-post"]').attributes('disabled')).toBeDefined()
  })

  it('keeps the server refusal when the run is still a draft', async () => {
    const wrapper = await mountWithDraft()
    post.mockImplementation(() => Promise.reject({ response: { data: { message: 'A payroll run needs an amount to post.' } } }))
    get.mockImplementation((url) => (url === '/api/enterprise/payroll-runs/run-1'
      ? Promise.resolve({ data: { data: draft } })
      : Promise.resolve({ data: { data: [] } })))

    await wrapper.find('[data-testid="payroll-post"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="payroll-error"]').text()).toBe('A payroll run needs an amount to post.')
    expect(wrapper.find('[data-testid="payroll-status"]').text()).toBe('draft')
  })

  it('sends one post request for a double click', async () => {
    const wrapper = await mountWithDraft()
    let resolve
    post.mockImplementation(() => new Promise((r) => { resolve = r }))

    const button = wrapper.find('[data-testid="payroll-post"]')
    await button.trigger('click')
    await button.trigger('click')
    resolve({ data: { data: { id: 'run-1', status: 'posted' } } })
    await flushPromises()

    expect(post.mock.calls.filter(([url]) => url === '/api/enterprise/payroll-runs/run-1/post')).toHaveLength(1)
    expect(wrapper.find('[data-testid="payroll-status"]').text()).toBe('posted')
  })
})
