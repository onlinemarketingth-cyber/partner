/**
 * 2026-10-01 — the portal wears the SIGNED-IN person's company, whoever's
 * login page they came through.
 *
 * Human report: "ผมไป setup บริษัท samsung ซึ่งพอ frontend เข้าด้วยผู้ใช้ของ
 * SWS ต้องแสดง theme ของ sws แต่ดันแสดงผลของ samsung".
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. ANOTHER COMPANY'S BRAND ON SOMEBODY'S PORTAL. The login page wears the
 *    last company this browser saw (a ?company=<slug> link, or the slug cached
 *    from it). The portal asked "which company am I?" only on the first
 *    navigation of a page load, and signing in on the login screen is not
 *    one — so an SWS agent kept Samsung's colours, logo and font until F5.
 *    Nothing errors; it just looks like white-labelling does not work.
 *
 * 2. THE SAME BUG, INTERMITTENTLY, AFTER A REFRESH. At boot the cached-slug
 *    public load and the signed-in load run at the same time; without the
 *    "newest request wins" rule whichever lands LAST paints the screen.
 *
 * 3. A REQUEST ON EVERY CLICK. The check runs on every navigation, so it must
 *    load once per signed-in person, not once per page.
 *
 * Asserted by NAVIGATING the real router, the way a browser exercises it.
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
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
  getToken: vi.fn(() => null),
  setToken: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))

import router from '../index'
import { useAuthStore } from '@/stores/auth'
import { type Theme, useThemeStore } from '@/stores/theme'

function themeOf(slug: string, primary: string): Theme {
  return { company: { name: slug.toUpperCase(), slug }, primary_hex: primary } as unknown as Theme
}

const SAMSUNG = themeOf('samsung', '#1428a0')
const SWS = themeOf('sws', '#0f766e')

/** A signed-in user of `company`, or nobody. */
let me: { id: number; name: string; role: 'agent' } | null = null
let myTheme: Theme = SWS
const themeRequests = () => get.mock.calls.filter(([path]) => path === '/me/theme').length

async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) await Promise.resolve()
}

beforeEach(async () => {
  setActivePinia(createPinia())
  window.localStorage.clear()
  get.mockReset()
  me = null
  myTheme = SWS
  get.mockImplementation((path: string) => {
    if (path === '/me') return Promise.resolve(me ? { data: me } : '')
    if (path === '/me/theme') return Promise.resolve({ data: myTheme })
    if (path.startsWith('/public/theme/')) return Promise.resolve({ data: SAMSUNG })

    return Promise.resolve({ data: [] })
  })
  await router.push({ name: 'login', query: { company: 'samsung' } })
  await router.isReady()
})

describe('portal theme follows the signed-in company', () => {
  it('replaces the login page’s company with the signed-in person’s on sign-in', async () => {
    const theme = useThemeStore()
    await theme.loadPublic()
    expect(theme.theme?.company.slug).toBe('samsung')

    // What LoginView does: the store learns the user, then the app navigates.
    me = { id: 41, name: 'SWS agent', role: 'agent' }
    useAuthStore().user = me as never
    await router.push('/')
    await settle()

    expect(themeRequests()).toBe(1)
    expect(theme.theme?.company.slug).toBe('sws')
  })

  it('loads once per signed-in person, not on every navigation', async () => {
    me = { id: 41, name: 'SWS agent', role: 'agent' }
    useAuthStore().user = me as never
    await router.push('/')
    await router.push('/profile')
    await router.push('/')
    await settle()

    expect(themeRequests()).toBe(1)
  })

  it('reloads for the next person who signs in on the same browser', async () => {
    const theme = useThemeStore()
    const auth = useAuthStore()
    me = { id: 41, name: 'SWS agent', role: 'agent' }
    auth.user = me as never
    await router.push('/')
    await settle()

    // Sign out → the login page; then somebody from Samsung signs in.
    auth.user = null
    await router.push({ name: 'login' })
    me = { id: 77, name: 'Samsung agent', role: 'agent' }
    myTheme = SAMSUNG
    auth.user = me as never
    await router.push('/')
    await settle()

    expect(themeRequests()).toBe(2)
    expect(theme.theme?.company.slug).toBe('samsung')
  })

  it('a public load that lands AFTER the signed-in load does not repaint the screen', async () => {
    const theme = useThemeStore()
    let releasePublic: (v: unknown) => void = () => {}
    get.mockImplementation((path: string) => {
      if (path.startsWith('/public/theme/'))
        return new Promise((resolve) => {
          releasePublic = resolve
        })
      if (path === '/me/theme') return Promise.resolve({ data: SWS })

      return Promise.resolve({ data: [] })
    })

    const publicLoad = theme.loadPublic() // the boot-time cached slug: Samsung
    await theme.loadForMe(41) // the signed-in SWS agent, answered first
    releasePublic({ data: SAMSUNG }) // Samsung's answer arrives late
    await publicLoad

    expect(theme.theme?.company.slug).toBe('sws')
  })

  it('a customer link’s company hands back to the signed-in company on the next portal page', async () => {
    const theme = useThemeStore()
    me = { id: 41, name: 'SWS agent', role: 'agent' }
    useAuthStore().user = me as never
    await router.push('/')
    await settle()

    // e.g. the agent opens another company's /p/{token} share link.
    theme.applyResolved(SAMSUNG)
    await router.push('/profile')
    await settle()

    expect(themeRequests()).toBe(2)
    expect(theme.theme?.company.slug).toBe('sws')
  })

  it('does not keep the previous company’s font when the next company has none', () => {
    const theme = useThemeStore()
    theme.applyResolved({ ...SAMSUNG, font_family: 'Kanit' } as Theme)
    expect(document.documentElement.style.getPropertyValue('--app-font')).toContain('Kanit')

    theme.applyResolved({
      ...SWS,
      font_family: null,
      font_family_latin: null,
      font_family_thai: null,
    } as unknown as Theme)

    expect(document.documentElement.style.getPropertyValue('--app-font')).toBe('')
  })
  it('does not keep the previous company’s logos or tab icon when the next company has none', () => {
    const theme = useThemeStore()
    const icon = document.createElement('link')
    icon.rel = 'icon'
    icon.setAttribute('href', '/favicon.ico')
    document.head.appendChild(icon)
    const logos = {
      nav_url: 'https://cdn.test/samsung-nav.png',
      login_url: 'https://cdn.test/samsung-login.png',
      loading_url: 'https://cdn.test/samsung-loading.png',
      favicon_url: 'https://cdn.test/samsung.ico',
    }
    theme.applyResolved({ ...SAMSUNG, logos } as unknown as Theme)
    expect(theme.navLogo).toBe(logos.nav_url)
    expect(icon.getAttribute('href')).toBe(logos.favicon_url)

    theme.applyResolved({ ...SWS, logos: {} } as unknown as Theme)

    expect(theme.navLogo).toBeNull()
    expect(theme.loginLogo).toBeNull()
    expect(theme.loadingLogo).toBeNull()
    expect(icon.getAttribute('href')).toBe('/favicon.ico')
    icon.remove()
  })
})
