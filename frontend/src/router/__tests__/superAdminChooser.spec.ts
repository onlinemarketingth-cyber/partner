/**
 * The Super Admin chooser — the guard must ASK, then get out of the way.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE CHOICE STOPS STICKING. The guard runs before EVERY navigation, so
 *    "let me into the Agent Portal" that is not remembered is not a choice
 *    at all — the very next click throws them back to the chooser. That
 *    failure looks like a broken button, not like a missing feature, and
 *    the person reporting it will describe it as "the page keeps jumping".
 *
 * 2. THE GUARD STOPS GUARDING. Remove the condition entirely and a Super
 *    Admin lands on an agent dashboard rendered from their own identity —
 *    zero XP, no team, no orders — which is what TASK-218 was raised over.
 *    Both halves matter, so both are pinned here.
 *
 * 3. THE CHOICE OUTLIVES THE PERSON. It is cleared on logout on purpose:
 *    the next person to sign in on the same machine must be asked in their
 *    own right, and so must the same person tomorrow.
 *
 * Asserted by NAVIGATING the real router rather than reading its config
 * back, so the guard is exercised the way a browser exercises it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import router from '../index'
import { useAuthStore } from '@/stores/auth'
import { hasChosenToStayInAgentPortal, rememberStayInAgentPortal } from '@/utils/portalChoice'

function signedInAs(role: 'agent' | 'company_admin' | 'super_admin') {
  get.mockImplementation((path: string) => {
    if (path === '/me') return Promise.resolve({ data: { id: 9, name: 'ผู้ใช้ ทดสอบ', role } })

    return Promise.resolve({ data: [] })
  })
}

describe('router — the Super Admin chooser', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    window.sessionStorage.clear()
    post.mockReset()
    post.mockResolvedValue({})
    signedInAs('agent')
    await router.push('/')
    await router.isReady()
  })

  it('sends a Super Admin to the chooser instead of an empty agent dashboard', async () => {
    setActivePinia(createPinia())
    signedInAs('super_admin')
    await useAuthStore().fetchUser()

    await router.push('/clients')

    expect(router.currentRoute.value.name).toBe('super-admin-notice')
  })

  it('lets the Super Admin through once they have chosen to stay', async () => {
    setActivePinia(createPinia())
    signedInAs('super_admin')
    await useAuthStore().fetchUser()

    // What the "หน้าตัวแทน" button does.
    rememberStayInAgentPortal()

    await router.push('/clients')

    expect(router.currentRoute.value.name).toBe('clients')
  })

  it('keeps letting them through on EVERY later navigation, not just the first', async () => {
    // The guard runs before every navigation. A choice that survives one
    // hop and not the next reads as a broken button.
    setActivePinia(createPinia())
    signedInAs('super_admin')
    await useAuthStore().fetchUser()
    rememberStayInAgentPortal()

    await router.push('/clients')
    await router.push('/products')
    await router.push('/profile')

    expect(router.currentRoute.value.name).toBe('profile')
  })

  it('never bounces a Company Admin — they belong in this app', async () => {
    setActivePinia(createPinia())
    signedInAs('company_admin')
    await useAuthStore().fetchUser()

    await router.push('/clients')

    expect(router.currentRoute.value.name).toBe('clients')
  })

  it('never bounces an ordinary agent', async () => {
    setActivePinia(createPinia())
    signedInAs('agent')
    await useAuthStore().fetchUser()

    await router.push('/clients')

    expect(router.currentRoute.value.name).toBe('clients')
  })

  it('forgets the choice on logout so the next sign-in is asked again', async () => {
    setActivePinia(createPinia())
    signedInAs('super_admin')
    const auth = useAuthStore()
    await auth.fetchUser()
    rememberStayInAgentPortal()
    expect(hasChosenToStayInAgentPortal()).toBe(true)

    await auth.logout()

    expect(hasChosenToStayInAgentPortal()).toBe(false)
  })

  it('reports "not chosen" rather than throwing when sessionStorage is unusable', async () => {
    // Read from a route guard, so a throw here white-screens the whole app
    // rather than losing a preference. Same failure modes safeStorage.js
    // documents: Safari private mode, a sandboxed iframe, site data off.
    const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage')
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      get() {
        throw new Error('SecurityError')
      },
    })

    expect(() => hasChosenToStayInAgentPortal()).not.toThrow()
    expect(hasChosenToStayInAgentPortal()).toBe(false)
    expect(() => rememberStayInAgentPortal()).not.toThrow()

    if (original) Object.defineProperty(window, 'sessionStorage', original)
  })
})
