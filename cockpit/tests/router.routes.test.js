// Route table smoke test for the slice-0 surfaces.
// Boots the real router (hash history) and checks every new path
// resolves to a named, auth-gated record — so a missing placeholder view
// or a typo in the table fails here rather than as a 404 in the shell.
import { describe, it, expect } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

setActivePinia(createPinia())
const { default: router } = await import('../src/router/index.js')

const CASES = [
  ['/skills',                'Skills'],
  ['/skills/cold-outreach',  'SkillCard'],
  ['/map',                   'BusinessMap'],
  ['/operate/systems',       'SystemsMap'],
  ['/settings/voice',        'VoiceSettings'],
  ['/brain',                 'Brain'],
  ['/agents/runs',           'AgentRuns'],
  ['/agents/runs/run_1',     'AgentRunDetail'],
  ['/agents/richard/workspace', 'AgentWorkspace'],
  ['/gods-eye',              'GodsEye'],
  ['/board',                 'BoardRoom'],
  ['/social',                'SocialPosts'],
]

describe('router table (slice 0)', () => {
  it.each(CASES)('%s resolves to %s and requires auth', (path, name) => {
    const resolved = router.resolve(path)
    expect(resolved.name).toBe(name)
    expect(resolved.meta.requiresAuth).toBe(true)
  })

  it('keeps the literal /agents/runs ahead of /agents/:slug/workspace', () => {
    expect(router.resolve('/agents/runs/workspace').name).toBe('AgentRunDetail')
    expect(router.resolve('/agents/new').name).toBe('CreateAgent')
  })

  it('passes params through', () => {
    expect(router.resolve('/skills/cold-outreach').params.slug).toBe('cold-outreach')
    expect(router.resolve('/agents/runs/run_1').params.id).toBe('run_1')
  })
})
