// Shared view-test harness: memory-history router + fresh pinia + mount.
// Views are mounted directly (not through <RouterView>) so `useRoute()`
// resolves to the pushed path and `route.params` / `route.query` work.
import { createRouter, createMemoryHistory } from 'vue-router'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

const Stub = defineComponent({ name: 'Stub', render: () => h('div') })

export function makeRouter(extra = []) {
  const routes = [
    { path: '/',                       component: Stub },
    { path: '/skills',                 name: 'Skills',         component: Stub },
    { path: '/skills/:slug',           name: 'SkillCard',      component: Stub },
    { path: '/brain',                  name: 'Brain',          component: Stub },
    { path: '/agents/runs',            name: 'AgentRuns',      component: Stub },
    { path: '/agents/runs/:id',        name: 'AgentRunDetail', component: Stub },
    { path: '/agents/:slug/workspace', name: 'AgentWorkspace', component: Stub },
    { path: '/approvals',              component: Stub },
    { path: '/atlas',                  component: Stub },
    { path: '/map',                    component: Stub },
    { path: '/feature-packs',          component: Stub },
    { path: '/:pathMatch(.*)*',        component: Stub },
    ...extra,
  ]
  return createRouter({ history: createMemoryHistory(), routes })
}

/**
 * Mount a view at `path` with a fresh pinia + memory router.
 * `setup(pinia)` runs after the pinia is active and before mount, so a test
 * can seed stores (e.g. auth capabilities).
 */
export async function mountView(Component, { path = '/', props = {}, attachTo, setup } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  await router.push(path)
  await router.isReady()
  if (setup) await setup(pinia, router)
  const wrapper = mount(Component, { props, global: { plugins: [pinia, router] }, attachTo })
  await flushPromises()
  return { wrapper, router, pinia }
}

export async function settle(times = 2) {
  for (let i = 0; i < times; i++) await flushPromises()
}

export const q = (sel) => document.body.querySelector(sel)
export const qa = (sel) => Array.from(document.body.querySelectorAll(sel))

/** Build a rejected axios-style error. */
export function httpError(status, data = {}, message = `Request failed with status code ${status}`) {
  const err = new Error(message)
  err.response = { status, data }
  return err
}
