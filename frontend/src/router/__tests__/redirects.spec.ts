/**
 * TASK-169 §5.2 — `/referrals` and `/pipeline` REDIRECT, they do not 404.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. A BOOKMARK BECOMES A DEAD END. Phase 4b deleted the two views. Agents
 *     bookmark URLs and share them with each other; ag-lead's ruling is that
 *     a 404 is a worse outcome than a redirect. A missing route in this SPA
 *     does not throw — it renders nothing, which looks like the app broke.
 *
 *  2. `/pipeline` LANDS ON THE WRONG THING. HomeView still links to
 *     `/pipeline` — the human kept that quick link explicitly ("ไม่ลบ") — and
 *     an agent who asked for the BOARD must get the board, not the roster of
 *     people. This is the entire reason Phase 3 put the view mode in the
 *     query string instead of component state (§5.3), so a redirect to bare
 *     `/clients` would quietly undo that decision while still "working".
 *
 * Asserted by NAVIGATING the real router (not by reading its config back),
 * so the redirect is exercised the way a browser exercises it. `/me` is
 * mocked to a logged-in agent because the session guard runs first and would
 * otherwise bounce every navigation to /login.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import router from '../index'

describe('router — TASK-169 Phase 4b redirects', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    get.mockImplementation((path: string) => {
      if (path === '/me') return Promise.resolve({ data: { id: 9, name: 'ตัวแทน ทดสอบ' } })
      // Everything a landed view fires on mount; the assertions are about the
      // URL, not about what the destination rendered.
      return Promise.resolve({ data: [] })
    })
    await router.push('/')
    await router.isReady()
  })

  it('/referrals redirects to the merged client screen instead of 404ing', async () => {
    await router.push('/referrals')

    expect(router.currentRoute.value.path).toBe('/clients')
    expect(router.currentRoute.value.name).toBe('clients')
    // It landed on the LIST — /referrals was the submission log of people's
    // deals, and those now live inside each person.
    expect(router.currentRoute.value.query.view).toBeUndefined()
    // And it really was a redirect, not a coincidence.
    expect(router.currentRoute.value.redirectedFrom?.path).toBe('/referrals')
  })

  it('/pipeline redirects to the merged screen IN PIPELINE MODE', async () => {
    await router.push('/pipeline')

    expect(router.currentRoute.value.path).toBe('/clients')
    // The whole point: HomeView's kept quick link must reach the BOARD.
    expect(router.currentRoute.value.query.view).toBe('pipeline')
    expect(router.currentRoute.value.fullPath).toBe('/clients?view=pipeline')
    expect(router.currentRoute.value.redirectedFrom?.path).toBe('/pipeline')
  })

  it('no route still points at the two deleted views', () => {
    const names = router.getRoutes().map((r) => r.name)
    expect(names).not.toContain('referrals')
    expect(names).not.toContain('pipeline')
    // The paths survive — as redirects, which is the point.
    const paths = router.getRoutes().map((r) => r.path)
    expect(paths).toContain('/referrals')
    expect(paths).toContain('/pipeline')
  })
})
