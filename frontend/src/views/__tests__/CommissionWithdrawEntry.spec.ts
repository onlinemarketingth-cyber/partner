/**
 * ค่าแนะนำ — the way an agent asks to be paid.
 *
 * Owner, 2026-09-15: "หน้า frontend ผมไม่เห็นปุ่ม UI ที่กดเพื่อทำการเบิกมาที่ระบบ
 * admin เลย".
 *
 * They were right, and the cause was worse than a missing button. The
 * withdrawal page has existed since August; the only link to it lived in
 * TopNavigation's item row, which is `hidden lg:flex`, and BottomNav — the
 * whole of navigation on a phone — never listed it. On the device most agents
 * use, the feature could not be reached at all.
 *
 * So this file pins the entry point on the screen where the agent is already
 * looking at their money, and — more importantly — pins the three states
 * where offering the button would be a lie:
 *
 *   · the balance could not be read (never print a zero nobody measured)
 *   · the agent has no complete payout details (the server refuses)
 *   · the balance is under the company's floor (the server refuses)
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, name: 'commission' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionView from '../CommissionView.vue'

/** What GET /commission-withdrawals/available answers, per test. */
type Payout = { available_satang: number; min_withdrawal_satang: number | null; payout_details_complete: boolean } | 'fail'

async function mountView(payout: Payout) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawals/available')) {
      if (payout === 'fail') throw new Error('500')

      return payout
    }

    return { data: [] }
  })

  const wrapper = mount(CommissionView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        TabFilterBar: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        AppCard: { template: '<div><slot /></div>' },
        AppList: true,
        AppListGroupHeader: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

const READY = { available_satang: 149500, min_withdrawal_satang: null, payout_details_complete: true }

beforeEach(() => {
  // This view reads the per-company theme store for its icon and title.
  setActivePinia(createPinia())
  get.mockReset()
})

describe('the button that was missing', () => {
  it('offers the request right on the screen that shows the money', async () => {
    const wrapper = await mountView(READY)

    expect(wrapper.find('[data-test="withdraw-cta-button"]').exists()).toBe(true)
  })

  it('prints the balance the SERVER will honour, not a sum of the rows below', async () => {
    /*
     * The real figure also subtracts requests already open and absorbs
     * reversals. A total counted from the visible ledger would be a larger
     * number than the server accepts — printed directly above the button
     * that asks for it.
     */
    const wrapper = await mountView(READY)

    expect(wrapper.get('[data-test="withdraw-cta"]').text()).toContain('1,495')
    expect(get).toHaveBeenCalledWith('/commission-withdrawals/available')
  })

  it('shows the company floor when there is one', async () => {
    const wrapper = await mountView({ ...READY, min_withdrawal_satang: 100000 })

    expect(wrapper.get('[data-test="withdraw-cta"]').text()).toContain('1,000')
  })
})

describe('the states where offering it would be a lie', () => {
  it('renders nothing at all when the balance could not be read', async () => {
    /*
     * THE ONE THAT MATTERS. "ยอดที่เบิกได้ 0 บาท" says the agent has earned
     * nothing withdrawable — the most discouraging sentence this screen can
     * print, and after a failed read it is a number nobody measured. The
     * ledger underneath still loads.
     */
    const wrapper = await mountView('fail')

    expect(wrapper.find('[data-test="withdraw-cta"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('0 บาท')
  })

  it('points at the profile instead when payout details are incomplete', async () => {
    // The server refuses this request outright, so a button here would open
    // a form that cannot succeed.
    const wrapper = await mountView({ ...READY, payout_details_complete: false })

    expect(wrapper.find('[data-test="withdraw-cta-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="withdraw-cta-blocked"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="withdraw-cta"]').text()).toContain('บัญชีธนาคาร')
  })

  it('says so when the balance is under the company floor', async () => {
    const wrapper = await mountView({ available_satang: 50000, min_withdrawal_satang: 100000, payout_details_complete: true })

    expect(wrapper.find('[data-test="withdraw-cta-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="withdraw-cta-below-min"]').exists()).toBe(true)
  })

  it('says so when there is nothing to withdraw', async () => {
    // Zero from the SERVER is a real answer and reads differently from a
    // zero after a failed read — this one is allowed to say it.
    const wrapper = await mountView({ ...READY, available_satang: 0 })

    expect(wrapper.find('[data-test="withdraw-cta-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="withdraw-cta-none"]').exists()).toBe(true)
  })
})
