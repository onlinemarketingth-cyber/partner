/**
 * ค่าแนะนำ — where the pillar sits, what it holds, and what still resolves.
 *
 * Owner, 2026-09-15, four requests in one message:
 *   1. ย้ายเมนูหลัก Commission ต่อจัดการตัวแทน
 *   2. เปลี่ยนเมนู [ชื่อ]
 *   3. ย้าย sub menu ตั้งค่า ไปอยู่ที่ ตั้งค่าระบบ ต่อจาก จัดการบริษัท
 *   4. ย้าย ตั้งจ่าย รอบจ่าย รายรายการ ไปเป็น sub menu
 *
 * Navigation is the kind of change that looks finished the moment it looks
 * right, and breaks two things nobody re-checks: the links already written
 * down, and who is allowed in. Both are pinned below.
 *
 * ── WHY THE ROUTE TABLE IS ASSERTED AND NOT JUST THE MENU ──
 *
 * /commission stopped being a page and became a redirect. Every link written
 * as `{ name: 'commission-management' }` — in this app, in bookmarks, and in
 * the in-app notification links the payout release added — goes through it,
 * and the two old query shapes (`?view=` and `?tab=`) have to keep landing on
 * the page that now owns them. A redirect that silently drops `?tab=paid`
 * sends a reader from a card counting money already paid to a screen showing
 * money still owed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { RouteRecordRaw } from 'vue-router'

const activeRoute = { value: { name: 'commission-payouts', path: '/commission/payouts', query: {} } }

vi.mock('vue-router', () => ({
  useRoute: () => activeRoute.value,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  createRouter: vi.fn(() => ({ beforeEach: vi.fn(), afterEach: vi.fn(), onError: vi.fn() })),
  createWebHistory: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(async () => ({ data: [] })), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), postForm: vi.fn(), download: vi.fn() },
  ApiError: class extends Error {},
}))

import AdminNavigation from '@/design-system/components/AdminNavigation.vue'
import { routes } from '@/router'
import { useAuthStore } from '@/stores/auth'

function mountNav() {
  return mount(AdminNavigation, {
    global: {
      stubs: {
        AppLogo: true,
        Icon: true,
        NotificationBell: true,
        CompanySwitcher: true,
        GlobalSearch: true,
      },
    },
  })
}

/** The pillar labels, in the order the icon bar renders them. */
function pillarLabels(wrapper: ReturnType<typeof mountNav>): string[] {
  return wrapper.findAll('[data-test^="nav-pillar-"]').map((el) => el.attributes('title') ?? el.text())
}

function routeNamed(name: string): RouteRecordRaw | undefined {
  const flat: RouteRecordRaw[] = []
  const walk = (list: readonly RouteRecordRaw[]) => list.forEach((r) => {
    flat.push(r)
    if (r.children) walk(r.children)
  })
  walk(routes)

  return flat.find((r) => r.name === name)
}

beforeEach(() => {
  // The sub-row only renders for the ACTIVE pillar, so which pillar's items a
  // test can read is decided by the route it pretends to be on.
  activeRoute.value = { name: 'commission-payouts', path: '/commission/payouts', query: {} }
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('each page has a real route', () => {
  it.each([
    ['commission-payouts', '/commission/payouts'],
    ['commission-runs', '/commission/runs'],
  ])('%s is at %s', (name, path) => {
    // Real paths rather than ?view=, so each is bookmarkable and the menu
    // highlight needs nothing cleverer than a route name.
    expect(routeNamed(name)?.path).toBe(path)
  })

  it('keeps /commission/entries resolving after รายรายการ was folded in', () => {
    /*
     * 2026-09-16 — the third page is gone (owner: "ผมว่ามันทับซ้อน"), but its
     * PATH is in bookmarks and in the dashboard's own card. Kept as a
     * redirect rather than deleted: a 404 on a link somebody saved is how a
     * consolidation gets remembered as a regression.
     */
    const entries = routeNamed('commission-entries')

    expect(entries?.path).toBe('/commission/entries')
    expect(entries?.redirect).toBeDefined()
  })

  it('carries the query across that redirect', () => {
    // ?tab=paid means "show me what has been paid". Dropping it would land
    // the reader on the queue of money still owed instead.
    const to = (routeNamed('commission-entries')?.redirect as (t: unknown) => { name: string; query: Record<string, unknown> })({
      query: { tab: 'paid' },
    })

    expect(to.name).toBe('commission-payouts')
    expect(to.query).toEqual({ tab: 'paid' })
  })
})

describe('/commission keeps every link that was already written down', () => {
  const redirect = () => routeNamed('commission-management')?.redirect

  it('still exists under its old name', () => {
    // Deleting the name would break `{ name: 'commission-management' }` call
    // sites at RUNTIME only — the router throws on push, and nothing on this
    // screen or in a test would have caught it first.
    expect(routeNamed('commission-management')).toBeDefined()
  })

  it('sends a bare visit to ตั้งจ่าย', () => {
    const to = (redirect() as (t: unknown) => { name: string })({ query: {} })

    expect(to.name).toBe('commission-payouts')
  })

  it('sends the old queue link to รอบจ่าย', () => {
    // /commission-withdrawals redirected here during the one release when the
    // queue was a tab; the notification links added then use it too.
    const to = (redirect() as (t: unknown) => { name: string })({ query: { view: 'queue' } })

    expect(to.name).toBe('commission-runs')
  })

  it('sends ?tab= to ตั้งจ่าย AND carries the tab with it', () => {
    /*
     * THE ONE THAT MATTERS. The dashboard card counts commission already
     * PAID and opens the screen on that tab. Dropping the query would land
     * the reader on รอจ่าย — a different number from the one they clicked.
     *
     * 2026-09-16 — the destination changed from the ledger page to ตั้งจ่าย
     * when the two merged; the QUERY surviving the hop is what this pins, and
     * that has not changed.
     */
    const to = (redirect() as (t: unknown) => { name: string; query: Record<string, unknown> })({
      query: { tab: 'paid' },
    })

    expect(to.name).toBe('commission-payouts')
    expect(to.query).toEqual({ tab: 'paid' })
  })

  it('still resolves the retired withdrawals path', () => {
    expect(routeNamed('commission-withdrawals')).toBeUndefined()
    const withdrawals = routes.find((r) => r.path === '/commission-withdrawals')
    expect(withdrawals?.redirect).toEqual({ name: 'commission-runs' })
  })
})

describe('the pillar', () => {
  it('sits directly after จัดการตัวแทน', async () => {
    const labels = pillarLabels(mountNav())
    const agents = labels.findIndex((l) => l.includes('จัดการตัวแทน'))
    const referral = labels.findIndex((l) => l.includes('ค่าแนะนำ'))

    expect(agents).toBeGreaterThanOrEqual(0)
    expect(referral).toBe(agents + 1)
  })

  it('holds the two payout pages and nothing else', () => {
    const wrapper = mountNav()
    const subs = wrapper.findAll('[data-test^="nav-sub-"]').map((el) => el.text())

    /*
     * 2026-09-16 — two, not three. รายรายการ repeated these two screens'
     * status tabs and their company totals (computed a second time, in the
     * browser, over one page of a paginated endpoint), and the one thing it
     * alone showed moved into ตั้งจ่าย's drill-down.
     */
    expect(subs).toEqual(['ตั้งจ่าย', 'รอบจ่าย'])
  })
})

describe('the settings page moved to ตั้งค่าระบบ', () => {
  it('is no longer in the ค่าแนะนำ pillar', () => {
    const subs = mountNav().findAll('[data-test^="nav-sub-"]').map((el) => el.text())

    expect(subs).not.toContain('ตั้งค่าค่าแนะนำ')
  })

  it('lands directly after จัดการบริษัท', () => {
    // Standing on a ตั้งค่าระบบ page so that pillar's sub-row is the one
    // rendered; the order is the whole of what the owner asked for here.
    activeRoute.value = { name: 'theme-settings', path: '/theme-settings', query: {} }

    const subs = mountNav().findAll('[data-test^="nav-sub-"]').map((el) => el.text())

    expect(subs[0]).toBe('จัดการบริษัท')
    expect(subs[1]).toBe('ตั้งค่าค่าแนะนำ')
  })

  it('stays visible to a Company Admin', () => {
    /*
     * THE REGRESSION THIS FILE IS WORTH WRITING FOR. ตั้งค่าระบบ holds several
     * Super-Admin-only items (จัดการบริษัท, SMTP, payment gateways), and a
     * moved page that picked up that flag would silently take away something
     * a Company Admin has always been able to do: set their own company's
     * rates. Nothing errors — the menu item simply is not there.
     *
     * Asserted on the NAV item, not on route meta: `superAdminOnly` is a
     * navigation concern here, so a test reading the route would pass while
     * the menu hid it.
     */
    useAuthStore().user = { id: 2, name: 'ผู้ดูแลบริษัท', role: 'company_admin' } as never
    activeRoute.value = { name: 'theme-settings', path: '/theme-settings', query: {} }

    const subs = mountNav().findAll('[data-test^="nav-sub-"]').map((el) => el.text())

    // จัดการบริษัท is Super-Admin-only and correctly absent; the moved page
    // must NOT have come along with that flag.
    expect(subs).not.toContain('จัดการบริษัท')
    expect(subs).toContain('ตั้งค่าค่าแนะนำ')
  })
})
