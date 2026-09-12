/**
 * The commission-readiness banner — "หากยังไม่ได้มีการ setup ค่าคอม ให้แจ้ง
 * เตือนในทุกหน้า หลังจากมีการตั้งค่าแล้วไม่แสดง หากมีการ setup ค่าคอมไม่ครบถ้วน
 * ที่ไม่สมบูรณ์ให้เตือนผู้ใช้" (owner, 2026-09-11).
 *
 * ── THE BUG THIS FILE EXISTS TO PREVENT ──
 *
 * Not a rendering bug. The bug is a company discovering at payout time that
 * its commission was never configured. CommissionService::recordForReferral()
 * is silent by design when no rate resolves — it logs a warning and returns
 * null rather than blocking the sale — so deals close, orders become
 * immutable, and NOTHING on any screen says the agents will not be paid. This
 * strip is the only thing standing between that silence and a month of it.
 *
 * Which means the failures worth testing are the ones that make it silent
 * again, and every one of them is a single line of markup or one condition:
 *
 *   · it renders nothing when everything is configured (owner: "ไม่แสดง") —
 *     and the same code path must not render nothing when it is NOT;
 *   · a Company Admin sees the MESSAGE but no button, because commission
 *     config went Super-Admin-only to write on the same day and a button
 *     they cannot use is worse than none (house rule: "อันไหนสิทธิ์ company
 *     admin ทำไม่ได้ต้องซ่อน ไม่ใช่ให้ error 403");
 *   · dismissing it works, or admins learn to read past it;
 *   · dismissing it does NOT work tomorrow, or one click buys a month of the
 *     exact silence this banner exists to break.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    status: number
    constructor(status: number, message = 'error') {
      super(message)
      this.status = status
    }
  },
}))

const push = vi.fn()
/** Mutable so a test can put the banner on the login screen or on /commission-plans. */
const route = { path: '/dashboard', meta: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ push }),
}))

import CommissionReadinessBanner from '../CommissionReadinessBanner.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useCommissionReadinessStore } from '@/stores/commissionReadiness'

const AIA = { id: 2, name: 'AIA' }

interface Readiness {
  state: 'missing' | 'incomplete' | 'ready'
  blocking_step: 1 | 2 | 3 | 4 | null
  products_total: number
  products_covered: number
  issues: { code: string; label: string; count: number }[]
  can_fix: boolean
}

/** The red one: no rate resolves for any product, so a closed deal pays nobody. */
const MISSING: Readiness = {
  state: 'missing',
  blocking_step: 3,
  products_total: 7,
  products_covered: 0,
  issues: [{ code: 'products_without_rate', label: 'สินค้า 7 จาก 7 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้', count: 7 }],
  can_fix: true,
}

/** The amber one: some products pay, some do not. */
const INCOMPLETE: Readiness = {
  state: 'incomplete',
  blocking_step: 3,
  products_total: 7,
  products_covered: 3,
  issues: [{ code: 'products_without_rate', label: 'สินค้า 4 จาก 7 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้', count: 4 }],
  can_fix: true,
}

const READY: Readiness = {
  state: 'ready',
  blocking_step: null,
  products_total: 7,
  products_covered: 7,
  issues: [],
  can_fix: true,
}

function mountBanner() {
  return mount(CommissionReadinessBanner, { global: { stubs: { Icon: true } } })
}

async function mountWith(readiness: Readiness, role: 'super_admin' | 'company_admin' = 'super_admin') {
  get.mockResolvedValue(readiness)
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ใช้', role, company: AIA } as never
  auth.status = 'ready'
  useActiveCompanyStore().selectedId = AIA.id

  const wrapper = mountBanner()
  await flushPromises()

  return wrapper
}

const banner = (w: ReturnType<typeof mountBanner>) => w.find('[data-test="commission-readiness-banner"]')

beforeEach(() => {
  get.mockReset()
  push.mockReset()
  route.path = '/dashboard'
  route.meta = {}
  window.localStorage.clear()
})

describe('CommissionReadinessBanner — which state shows what', () => {
  it('renders NOTHING when commission is fully configured', async () => {
    // The owner's own words: "หลังจากมีการตั้งค่าแล้วไม่แสดง". Not a green
    // confirmation strip, not a collapsed bar — nothing. A permanent band at
    // the top of every page is furniture, and furniture is what people stop
    // seeing.
    const wrapper = await mountWith(READY)

    expect(banner(wrapper).exists()).toBe(false)
  })

  it('says the money consequence in red when no rate resolves for anything', async () => {
    const wrapper = await mountWith(MISSING)

    expect(banner(wrapper).classes()).toContain('bg-rose-50')
    expect(wrapper.get('[data-test="commission-readiness-headline"]').text())
      .toBe('ยังไม่ได้ตั้งค่าคอมมิชชั่น — ดีลที่ปิดได้จะไม่มีใครได้เงิน')
  })

  it('names what is incomplete and how many, in amber', async () => {
    /*
     * Amber and not red because somebody IS being paid — painting a partial
     * gap the same colour as "nobody is paid" is how the red one stops being
     * believed. And the words are the server's, counts and all: the rule that
     * decides which products are covered is the one money uses, so paraphrasing
     * its answer here would be a second sentence about money in a second file.
     */
    const wrapper = await mountWith(INCOMPLETE)

    expect(banner(wrapper).classes()).toContain('bg-amber-50')
    expect(wrapper.get('[data-test="commission-readiness-headline"]').text())
      .toBe('สินค้า 4 จาก 7 รายการยังไม่มีอัตราค่าคอมที่ใช้ได้')
  })

  it('names the blocking step in the settings screen\'s own vocabulary', async () => {
    // "ติดอยู่ที่ขั้นที่ 3" is a place to go — it matches the numbered step the
    // button lands on. "Incomplete" is a feeling.
    const wrapper = await mountWith(MISSING)

    expect(wrapper.get('[data-test="commission-readiness-detail"]').text()).toContain('ติดอยู่ที่ขั้นที่ 3')
  })
})

describe('CommissionReadinessBanner — who is offered the fix', () => {
  it('gives a Super Admin a button to the commission settings screen', async () => {
    const wrapper = await mountWith(MISSING)

    await wrapper.get('[data-test="commission-readiness-action"]').trigger('click')

    expect(push).toHaveBeenCalledWith('/commission-plans')
  })

  it('gives a Company Admin the message and no button, but tells them who can', async () => {
    /*
     * 2026-09-11: five policies now answer isSuperAdmin() and six
     * Settings…Update abilities left the Company Admin row, so a Company Admin
     * can no longer change any commission number. They must still be TOLD —
     * they run the company and their agents ask them first — but a "go fix it"
     * button would walk them into a 403 they cannot do anything about.
     *
     * The role is NOT read here: `can_fix` is the server's answer, so the day
     * that rule gains an exception this component needs no change at all.
     */
    const wrapper = await mountWith({ ...MISSING, can_fix: false }, 'company_admin')

    expect(banner(wrapper).exists()).toBe(true)
    expect(wrapper.get('[data-test="commission-readiness-headline"]').text())
      .toBe('ยังไม่ได้ตั้งค่าคอมมิชชั่น — ดีลที่ปิดได้จะไม่มีใครได้เงิน')
    expect(wrapper.find('[data-test="commission-readiness-action"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="commission-readiness-contact-admin"]').text()).toBe('กรุณาติดต่อผู้ดูแลระบบ')
  })
})

describe('CommissionReadinessBanner — dismissal lasts exactly one day', () => {
  it('hides when dismissed', async () => {
    const wrapper = await mountWith(MISSING)

    await wrapper.get('[data-test="commission-readiness-dismiss"]').trigger('click')
    await flushPromises()

    expect(banner(wrapper).exists()).toBe(false)
  })

  it('comes back the next day, because yesterday\'s dismissal is not today\'s', async () => {
    /*
     * THE HALF THAT MATTERS. A dismissal that persisted forever would let one
     * click on the day the banner first appeared buy silence for the entire
     * month it takes an unpaid commission to surface — which is the exact
     * outcome this feature exists to prevent, arrived at through the feature
     * itself.
     *
     * Written by planting yesterday's stamp directly rather than by faking
     * the clock: the stored value IS the contract with tomorrow's session, so
     * a test that mocked Date would prove the comparison and not the storage.
     */
    const yesterday = new Date()
    yesterday.setDate(yesterday.getDate() - 1)
    const stamp = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`
    window.localStorage.setItem(`commissionReadiness.dismissed:${AIA.id}:missing`, stamp)

    const wrapper = await mountWith(MISSING)

    expect(banner(wrapper).exists()).toBe(true)
  })

  it('does not let a dismissal of the amber banner silence the red one', async () => {
    // Going from "ไม่ครบ" to "ไม่มีใครได้เงิน" is new information — a rate
    // expiring overnight is enough to cause it — and a dismissal keyed only by
    // company would swallow exactly that transition.
    const today = new Date()
    const stamp = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
    window.localStorage.setItem(`commissionReadiness.dismissed:${AIA.id}:incomplete`, stamp)

    const wrapper = await mountWith(MISSING)

    expect(banner(wrapper).exists()).toBe(true)
  })
})

describe('CommissionReadinessBanner — where it must never appear', () => {
  it('renders nothing before the session is known', async () => {
    // On a hard refresh the user is null until /me answers. A banner that
    // flashed in and out on every reload would read as a glitch, and a glitch
    // is something people click away without reading.
    get.mockResolvedValue(MISSING)
    const auth = useAuthStore()
    auth.user = null
    auth.status = 'checking'

    const wrapper = mountBanner()
    await flushPromises()

    expect(banner(wrapper).exists()).toBe(false)
    expect(get).not.toHaveBeenCalled()
  })

  it('renders nothing on a public route such as the login screen', async () => {
    route.meta = { public: true }

    const wrapper = await mountWith(MISSING)

    expect(banner(wrapper).exists()).toBe(false)
  })

  it('stands down on the commission settings screen, which has its own banner', async () => {
    // "ทุกหน้า" is still honoured: that screen warns more loudly, with the
    // step flow and a jump button, reading this same store. Two stacked
    // banners saying one sentence is how both stop being read.
    route.path = '/commission-plans'

    const wrapper = await mountWith(MISSING)

    expect(banner(wrapper).exists()).toBe(false)
  })

  it('renders nothing, and never retries, for a role the endpoint refuses', async () => {
    /*
     * An agent has no business seeing this — they cannot fix a rate, so the
     * only thing it could tell them is that the company will not pay them, on
     * every page, with no way to act (owner decision; the agent portal does
     * not render it at all). The 403 is the real wall. This asserts the
     * client does not walk into it once per navigation.
     */
    get.mockRejectedValue(new ApiError(403, 'forbidden'))
    const auth = useAuthStore()
    auth.user = { id: 9, name: 'ตัวแทน', role: 'agent', company: AIA } as never
    auth.status = 'ready'

    const wrapper = mountBanner()
    await flushPromises()

    expect(banner(wrapper).exists()).toBe(false)

    await useCommissionReadinessStore().ensureLoaded()
    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('CommissionReadinessBanner — the cost of being on every page', () => {
  it('asks the server once per company, not once per page', async () => {
    /*
     * The component is mounted ONCE in the app shell and never unmounts, so
     * "every page" costs one request per session rather than one per
     * navigation — and the store dedupes anything that asks again. A banner
     * that put a request on every route change would be the most expensive
     * thing in the console and the first thing somebody deleted.
     */
    await mountWith(MISSING)

    const readiness = useCommissionReadinessStore()
    await readiness.ensureLoaded()
    await readiness.ensureLoaded()

    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith(`/commission-readiness?company_id=${AIA.id}`)
  })

  it('re-asks after a commission setting is saved, so a fixed gap clears', async () => {
    // The settings screen calls refresh() through its own write wrapper. An
    // admin who has just fixed the last missing rate must not be left looking
    // at a banner insisting nobody is paid.
    await mountWith(MISSING)

    get.mockResolvedValue(READY)
    await useCommissionReadinessStore().refresh()
    await flushPromises()

    expect(get).toHaveBeenCalledTimes(2)
    expect(useCommissionReadinessStore().state).toBe('ready')
  })
})
