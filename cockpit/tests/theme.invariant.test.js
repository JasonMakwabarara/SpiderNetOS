import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import router from '../src/router/index.js'

const root = resolve(import.meta.dirname, '..')
const appearance = readFileSync(resolve(root, 'src/appearance.js'), 'utf8')
const routerSource = readFileSync(resolve(root, 'src/router/index.js'), 'utf8')

describe('theme does not change the product', () => {
  it('appearance state cannot see routes or permissions', () => {
    expect(appearance).not.toMatch(/vue-router|router\/index|requiresAuth|capability/)
    expect(appearance).not.toMatch(/\/billing|\/usage|\/approvals/)
  })

  it('routes do not branch on a theme name', () => {
    expect(routerSource).not.toMatch(/midnight-harbour|copper-ledger|solarpunk|data-theme/)
    const paths = router.getRoutes().map((route) => route.path)
    expect(paths).toContain('/')
    expect(paths).toContain('/billing')
    expect(paths).toContain('/usage')
    expect(paths).toContain('/approvals')
    expect(new Set(paths).size).toBe(paths.length)
  })
})
