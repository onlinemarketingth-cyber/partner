/**
 * The payments screen — tabs, counts, and the request they send.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. THE TAB STOPS FILTERING. Drop `?status=` from the request and the screen
 *    still renders: rows appear, tabs highlight, counts show. It just shows
 *    every order under every tab. Nothing errors, and the admin working the
 *    "รอตรวจสลิป" queue is looking at paid orders.
 *
 * 2. THE COUNTS GET COMPUTED FROM THE ROWS. The tempting simplification —
 *    "we already have the list, why call summary?" — produces a page-sized
 *    count and a page-sized money total. Both look plausible. Both are wrong
 *    the moment there are more than fifteen orders, which is every real
 *    company.
 *
 * 3. CONFIRMING A PAYMENT REFRESHES ONE OF THE TWO. The tab keeps saying 4
 *    over three rows — the same count/list disagreement the shared
 *    server-side scope exists to prevent, reintroduced on the client.
 *
 * 4. "รอตรวจสลิป" STOPS BEING FIRST. It is the only state blocked on our
 *    side; a slip nobody checks blocks the deal AND the agent's commission.
 *    Sorting tabs by the status enum instead puts the queue nobody can act on
 *    in front of the one they must.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const download = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: vi.fn(),
    post: vi.fn(),
    download: (...args: unknown[]) => download(...args),
  },
  ApiError: class extends Error {},
}))

import OrderPaymentsView from '../OrderPaymentsView.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'

const SUMMARY = [
  { status: 'pending', status_label: 'รอชำระเงิน', count: 12, total_satang: 1200000 },
  { status: 'awaiting_verification', status_label: 'รอตรวจสอบสลิป', count: 4, total_satang: 890000 },
  { status: 'paid', status_label: 'ชำระเงินแล้ว', count: 30, total_satang: 9000000 },
  { status: 'cancelled', status_label: 'ยกเลิก', count: 0, total_satang: 0 },
  { status: 'refunded', status_label: 'คืนเงินแล้ว', count: 0, total_satang: 0 },
]

const ORDER = {
  id: 1,
  order_number: 'ORD-0001',
  status: 'awaiting_verification',
  status_label: 'รอตรวจสอบสลิป',
  amount_satang: 890000,
  client_id: 7,
  client_name: 'ลูกค้า 2',
  product_name: 'Vital Blueprint',
  agent: { id: 3, name: 'เกรียงยศ' },
  has_slip: true,
  paid_at: null,
  verified_by: null,
  created_at: '2026-08-20T03:00:00Z',
}

function mockApi(orders = [ORDER]) {
  get.mockImplementation(async (path: string) =>
    path === '/orders/summary' ? { data: SUMMARY } : { data: orders },
  )
}

/** Every path the component asked for, in order. */
function requestedPaths(): string[] {
  return get.mock.calls.map((c) => c[0] as string)
}

async function mountView() {
  const wrapper = mount(OrderPaymentsView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ClientDetailModal: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

/**
 * 2026-09-04 — human-reported: the header's company did not reach this
 * screen. Both endpoints have honoured company_id since TASK-209
 * (OrderController::scopedQuery), so a Super Admin was reading every
 * tenant's orders — and every tenant's money — under a header naming one.
 *
 * The summary is asserted alongside the list on purpose: the tab counts
 * come from one and the rows from the other, and a page showing one
 * company's totals above another's rows is worse than either mistake alone.
 */
describe('OrderPaymentsView — the company scope', () => {
  beforeEach(() => {
    get.mockReset()
    localStorage.clear()
    mockApi()

    const auth = useAuthStore()
    auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin' } as never
    const store = useActiveCompanyStore()
    store.companies = [
      { id: 4, name: 'ไทยประกันชีวิต', slug: 'thailife' },
      { id: 9, name: 'Genesenn', slug: 'genesenn' },
    ]
    store.setCompany(4)
  })

  it('asks both endpoints for the picked company', async () => {
    await mountView()

    expect(requestedPaths()).toContain('/orders/summary?company_id=4')
    expect(requestedPaths().some((p) => p.startsWith('/orders?') && p.includes('company_id=4'))).toBe(true)
  })

  it('reloads BOTH when the header switches company', async () => {
    await mountView()
    get.mockClear()

    useActiveCompanyStore().setCompany(9)
    await flushPromises()

    expect(requestedPaths()).toContain('/orders/summary?company_id=9')
    expect(requestedPaths().some((p) => p.startsWith('/orders?') && p.includes('company_id=9'))).toBe(true)
  })
})

describe('OrderPaymentsView — the tab actually filters', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    download.mockReset()
    mockApi()
  })

  it('opens on the queue that is blocked on us', async () => {
    // "รอตรวจสลิป" first, not the enum's order. A slip nobody has checked
    // blocks the deal and the agent's commission; "รอชำระเงิน" waits on the
    // customer and no amount of staring moves it.
    await mountView()

    expect(requestedPaths()).toContain('/orders?status=awaiting_verification')
  })

  it('sends the status on every tab change', async () => {
    const wrapper = await mountView()
    get.mockClear()

    const pendingTab = wrapper.findAll('button').find((b) => b.text().includes('รอชำระเงิน'))
    if (!pendingTab) throw new Error('The รอชำระเงิน tab is missing.')

    await pendingTab.trigger('click')
    await flushPromises()

    // Losing the query string leaves a screen that renders perfectly and
    // shows every order under every tab.
    expect(requestedPaths()).toContain('/orders?status=pending')
  })

  it('does not refetch when the active tab is clicked again', async () => {
    const wrapper = await mountView()
    get.mockClear()

    const activeTab = wrapper.findAll('button').find((b) => b.text().includes('รอตรวจสลิป'))
    await activeTab?.trigger('click')
    await flushPromises()

    expect(get).not.toHaveBeenCalled()
  })
})

describe('OrderPaymentsView — the counts come from the server', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    download.mockReset()
    mockApi()
  })

  it('asks the summary endpoint rather than counting the rows', async () => {
    // One row is loaded; the tab must still say 4. Counting the list would
    // say 1 — a plausible-looking number on a screen full of numbers.
    const wrapper = await mountView()

    expect(requestedPaths()).toContain('/orders/summary')
    expect(wrapper.text()).toContain('4')
    expect(wrapper.text()).toContain('12')
  })

  it('shows a whole-set money total, not a page total', async () => {
    // 890000 satang = ฿8,900.00, from the summary — the single loaded row
    // could never establish that.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('8,900.00')
  })

  it('shows a zero count rather than hiding the tab', async () => {
    // A tab that disappears when its queue empties reads as a broken screen,
    // not as "nothing to do".
    const wrapper = await mountView()

    const cancelled = wrapper.findAll('button').find((b) => b.text().includes('ยกเลิก'))
    expect(cancelled).toBeDefined()
    expect(cancelled?.text()).toContain('0')
  })

  it('survives a failed summary without losing the list', async () => {
    // A missing badge is not a broken page: an admin who can still see the
    // rows can still do the work.
    get.mockImplementation(async (path: string) => {
      if (path === '/orders/summary') throw new Error('boom')

      return { data: [ORDER] }
    })

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ORD-0001')
  })
})

describe('OrderPaymentsView — acting on a row', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    download.mockReset()
    mockApi()
  })

  it('offers the slip only when there is one to look at', async () => {
    const wrapper = await mountView()
    expect(wrapper.findAll('button').some((b) => b.text().includes('ดูสลิป'))).toBe(true)

    get.mockReset()
    mockApi([{ ...ORDER, has_slip: false }])
    const noSlip = await mountView()

    // A button that downloads nothing is worse than no button: it reads as
    // "the slip failed to open" rather than "no slip was attached".
    expect(noSlip.findAll('button').some((b) => b.text().includes('ดูสลิป'))).toBe(false)
  })

  it('downloads the slip named after its order', async () => {
    const wrapper = await mountView()

    const slipButton = wrapper.findAll('button').find((b) => b.text().includes('ดูสลิป'))
    await slipButton?.trigger('click')
    await flushPromises()

    expect(download).toHaveBeenCalledWith('/orders/1/slip', 'slip-ORD-0001.jpg')
  })
})
