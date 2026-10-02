/**
 * 2026-10-02 — a signup link is checked once, from the first instant.
 *
 * Owner report: opening a shared signup link "โหลดช้ามาก ... เหมือนมันค้าง".
 * Measured on production the same day: the check answers in ~0.3 s. The wait
 * was the ORDER — a 3-second splash, then the page started checking from
 * zero behind a second loading line.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE SECOND WAIT COMES BACK. If RegisterView stops taking the request
 *    main.ts started, it asks again after mount: the page still works, it is
 *    just slow again, and nobody files a bug for "slow".
 * 2. THE CHECK IS SENT TWICE. Both endpoints are throttle:10,1 — two calls
 *    per visit halves how many recruits can open links at once.
 * 3. A STALE ANSWER IS REUSED. The prefetched answer belongs to ONE link and
 *    is handed over once; a code typed by hand later must reach the server.
 * 4. THE AGENT PORTAL LOSES ITS SPLASH. Only customer/recruit link pages skip
 *    the 3-second floor (owner decision); the portal keeps it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const post = vi.fn()
const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

const currentRoute = { name: '', params: {} as Record<string, unknown>, query: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({
  useRoute: () => currentRoute,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

import RegisterView from '../RegisterView.vue'
import {
  isPublicLinkPage,
  resetLinkPrefetch,
  signupLinkTarget,
  startLinkPrefetch,
  takePrefetchedTeamLink,
} from '@/utils/bootPrefetch'

function setRoute(name: string, params: Record<string, unknown> = {}, query: Record<string, unknown> = {}) {
  currentRoute.name = name
  currentRoute.params = params
  currentRoute.query = query
}

async function mountRegister() {
  const wrapper = mount(RegisterView, { global: { stubs: { Icon: true, RouterLink: true, Teleport: true } } })
  await flushPromises()

  return wrapper
}

const calls = (path: string) => post.mock.calls.filter(([p]) => p === path).length

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  get.mockResolvedValue({ data: {} })
  resetLinkPrefetch()
  setRoute('register')
})

describe('signup link prefetch', () => {
  it('a /j/ link is checked once, at boot, and the page uses that answer', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    startLinkPrefetch({ pathname: '/j/K7M3QP2X9A', search: '' })
    setRoute('team-signup-link', { code: 'K7M3QP2X9A' })

    const wrapper = await mountRegister()

    expect(calls('/register/resolve-ref-token')).toBe(1)
    expect(wrapper.text()).toContain('สมชาย')
    expect(wrapper.text()).not.toContain('กำลังตรวจสอบลิงก์ชวนเข้าทีม')
  })

  it('a /c/ link is checked once, at boot, and the page uses that answer', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต' })
    startLinkPrefetch({ pathname: '/c/thailife', search: '' })
    setRoute('company-signup-link', { code: 'thailife' })

    const wrapper = await mountRegister()

    expect(calls('/register/resolve-invite-code')).toBe(1)
    expect(wrapper.text()).toContain('ไทยประกันชีวิต')
    expect(wrapper.find('#invite_code').exists()).toBe(false)
  })

  it('a dead link checked at boot still lands on the code form with a reason', async () => {
    post.mockRejectedValue(new Error('404'))
    startLinkPrefetch({ pathname: '/j/DEADLINK00', search: '' })
    setRoute('team-signup-link', { code: 'DEADLINK00' })

    const wrapper = await mountRegister()

    expect(calls('/register/resolve-ref-token')).toBe(1)
    expect(wrapper.find('#invite_code').exists()).toBe(true)
  })

  it('an answer for one link is never reused for another', async () => {
    post.mockResolvedValue({ company_name: 'X', inviter_name: 'Y' })
    startLinkPrefetch({ pathname: '/j/AAAAAAAAAA', search: '' })

    expect(takePrefetchedTeamLink('BBBBBBBBBB')).toBeNull()
    expect(takePrefetchedTeamLink('AAAAAAAAAA')).not.toBeNull()
    // Handed over once only.
    expect(takePrefetchedTeamLink('AAAAAAAAAA')).toBeNull()
  })

  it('with no prefetch the page still checks the link itself', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    setRoute('team-signup-link', { code: 'K7M3QP2X9A' })

    await mountRegister()

    expect(calls('/register/resolve-ref-token')).toBe(1)
  })

  it('recognises every signup link form and nothing else', () => {
    expect(signupLinkTarget('/j/K7M3QP2X9A', '')).toEqual({ kind: 'team', token: 'K7M3QP2X9A' })
    expect(signupLinkTarget('/register', '?ref=abc')).toEqual({ kind: 'team', token: 'abc' })
    expect(signupLinkTarget('/c/thailife', '')).toEqual({ kind: 'company', code: 'thailife' })
    expect(signupLinkTarget('/register', '')).toBeNull()
    expect(signupLinkTarget('/', '')).toBeNull()
    expect(signupLinkTarget('/clients', '?ref=abc')).toBeNull()
  })

  it('only customer/recruit link pages skip the splash floor; the portal keeps it', () => {
    for (const path of ['/j/K7M3QP2X9A', '/c/thailife', '/register', '/p/tok', '/pay/tok', '/l/tok']) {
      expect(isPublicLinkPage(path)).toBe(true)
    }
    for (const path of ['/', '/login', '/clients', '/in/abc', '/profile']) {
      expect(isPublicLinkPage(path)).toBe(false)
    }
  })
})
