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
const post = vi.fn()
const download = vi.fn()

/**
 * The api client's own error type, mirrored — and HOISTED with the mock.
 *
 * The screen decides what to show by `instanceof ApiError`, so a rejection
 * thrown as a plain Error would take the generic branch and this file would
 * pass while the real refusal message was being dropped. `vi.mock` factories
 * run above every other statement in the module, so the class they hand back
 * has to be created inside `vi.hoisted` — a plain `class` above the mock is
 * still in its temporal dead zone when the factory runs.
 */
const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: vi.fn(),
    post: (...args: unknown[]) => post(...args),
    getBlob: vi.fn(),
    download: (...args: unknown[]) => download(...args),
  },
  ApiError: ApiErrorStub,
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

/**
 * One order as OrderResource sends it. The gateway fields are declared here
 * even though they are null in the happy case — a test that spreads
 * `{ ...ORDER, gateway_payment_received: true }` onto an object literal that
 * never mentions them is a type error, and widening it at the spread site
 * would only hide the next field that goes missing.
 */
const ORDER: {
  id: number
  order_number: string
  status: string
  status_label: string
  amount_satang: number
  client_id: number
  client_name: string
  product_name: string
  agent: { id: number; name: string }
  has_slip: boolean
  gateway_payment_received: boolean
  gateway_mode: string | null
  payment_provider: string | null
  payment_provider_label: string | null
  refund_reported_at: string | null
  refund_reported_satang: number | null
  last_payment_error: string | null
  last_payment_error_at: string | null
  paid_at: string | null
  verified_by: { id: number; name: string } | null
  created_at: string
  permissions: { confirm: boolean }
} = {
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
  gateway_payment_received: false,
  gateway_mode: null,
  payment_provider: null,
  payment_provider_label: null,
  refund_reported_at: null,
  refund_reported_satang: null,
  last_payment_error: null,
  last_payment_error_at: null,
  paid_at: null,
  verified_by: null,
  created_at: '2026-08-20T03:00:00Z',
  permissions: { confirm: false },
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
        OrderDetailModal: true,
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
    post.mockReset()
    post.mockResolvedValue({ data: {} })
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
    post.mockReset()
    post.mockResolvedValue({ data: {} })
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
    post.mockReset()
    post.mockResolvedValue({ data: {} })
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

  it('opens the slip in a modal instead of putting a file in Downloads', async () => {
    /*
     * 2026-09-10 — REVERSED, and the reversal is the fix. This used to
     * assert a download, which is what the button did while saying ดูสลิป:
     * the admin then had to find the file, open it elsewhere, compare it
     * against a row they could no longer see, and delete it afterwards.
     *
     * The download still exists — inside the modal, as a secondary action —
     * so nothing was taken away from whoever genuinely wants the file.
     */
    const wrapper = await mountView()

    await wrapper.find('[data-test="view-slip"]').trigger('click')
    await flushPromises()

    expect(download).not.toHaveBeenCalled()
    expect(wrapper.findComponent({ name: 'SlipViewerModal' }).props('orderId')).toBe(1)
  })

  it('offers approve only when the server says this user may, on this row', async () => {
    /*
     * 2026-09-10 (human: "ตอนนี้ผมหา UI สำหรับอนุมัติไม่เจอ"). The endpoint
     * and the Policy have existed since ADR-017; the button never did.
     *
     * `permissions.confirm` is the SERVER's answer to both halves — may I,
     * and is there anything to confirm — so this screen never re-derives
     * half a Policy and meets the other half as a 403.
     */
    get.mockReset()
    mockApi([
      { ...ORDER, id: 1, permissions: { confirm: true } },
      { ...ORDER, id: 2, order_number: 'ORD-0002', permissions: { confirm: false } },
    ])
    const wrapper = await mountView()

    expect(wrapper.findAll('[data-test="confirm-payment"]')).toHaveLength(1)
  })

  it('confirms, then refreshes the counts as well as the rows', async () => {
    // The tab would otherwise keep saying 4 over three rows — the exact
    // count/list disagreement the shared server-side scope prevents.
    get.mockReset()
    mockApi([{ ...ORDER, permissions: { confirm: true } }])
    const wrapper = await mountView()

    get.mockClear()
    await wrapper.find('[data-test="confirm-payment"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/orders/1/confirm')
    expect(requestedPaths().some((p) => p.startsWith('/orders/summary'))).toBe(true)
    expect(requestedPaths().some((p) => p.startsWith('/orders?'))).toBe(true)
  })

  it('shows a refusal in full, because it names the step that is missing', async () => {
    /*
     * confirmPayment() can refuse after the button is pressed: the journey
     * rule requires the referral to have reached the step before ชำระเงิน,
     * and its message says WHICH step. Flattening that to "ไม่สำเร็จ" throws
     * away the only sentence that says what to do next — which is how
     * ORD-MWJTV2QV sat unexplained.
     */
    get.mockReset()
    mockApi([{ ...ORDER, permissions: { confirm: true } }])
    const wrapper = await mountView()

    post.mockRejectedValueOnce(
      Object.assign(new ApiErrorStub('ต้องผ่านขั้น "พบแพทย์ครั้งแรก" ก่อน'), { status: 422 }),
    )

    await wrapper.find('[data-test="confirm-payment"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('พบแพทย์ครั้งแรก')
  })
})

/**
 * 2026-09-10 (human: "แก้ Ui ปรับจาก card เป็น table เรียงแบบนี้ วันที่ เวลา,
 * เลขที่ order, ชื่อสินค้า, ยอด, ชื่อผู้ซื้อ, ชื่อ Agent, สถานะ,
 * ช่องทางชำระเงิน, ปุ่มดูรายละเอียด").
 *
 * The column ORDER is the request, not an implementation detail: this screen
 * is read by someone scanning a day's takings, and the four leading columns
 * are the ones they scan by. A later tidy-up that reorders them — or drops
 * one into a tooltip — would quietly undo the reason the cards were replaced.
 */
describe('OrderPaymentsView — the table', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    post.mockResolvedValue({ data: {} })
    download.mockReset()
    mockApi()
  })

  it('lays the columns out in the order that was asked for', async () => {
    const wrapper = await mountView()

    expect(
      wrapper.find('[data-test="orders-table"]').findAll('th').map((th) => th.text()),
    ).toEqual([
      'วันที่ / เวลา',
      'เลขที่คำสั่งซื้อ',
      'สินค้า',
      'ยอด',
      'ผู้ซื้อ',
      'ตัวแทน',
      'สถานะ',
      'ช่องทางชำระเงิน',
      '',
    ])
  })

  it('puts every order on one row, with the four scanning columns in bold', async () => {
    const wrapper = await mountView()
    const cells = wrapper.find('[data-test="orders-table"] tbody tr').findAll('td')

    expect(cells[1]?.text()).toBe('ORD-0001')
    expect(cells[2]?.text()).toBe('Vital Blueprint')
    expect(cells[4]?.text()).toBe('ลูกค้า 2')
    expect(cells[5]?.text()).toBe('เกรียงยศ')

    // The human marked these four bold; the other columns identify the row,
    // these are the ones the eye runs down.
    for (const index of [0, 1, 2, 3]) {
      expect(cells[index]?.classes()).toContain('font-bold')
    }
  })

  it('opens the order sheet from the row', async () => {
    const wrapper = await mountView()

    const button = wrapper.find('[data-test="view-order"]')
    expect(button.exists()).toBe(true)

    await button.trigger('click')
    expect(wrapper.findComponent({ name: 'OrderDetailModal' }).props('orderId')).toBe(1)
  })

  it('gives "money in, sale not closed" its own row rather than a cell', async () => {
    /*
     * The single most important sentence on this screen — somebody has been
     * charged and the system could not finish. Squeezed into the status
     * cell it would be truncated; it gets a spanning row instead.
     */
    get.mockReset()
    mockApi([{ ...ORDER, status: 'pending', gateway_payment_received: true }])
    const wrapper = await mountView()

    const noticeRow = wrapper.findAll('[data-test="orders-table"] tbody tr')
      .find((tr) => tr.text().includes('ปิดการขายอัตโนมัติไม่สำเร็จ'))

    expect(noticeRow).toBeDefined()
    expect(noticeRow!.find('td').attributes('colspan')).toBe('9')
  })

  it('shows only the most urgent notice when a row has several', async () => {
    /*
     * A row carrying three warnings tells the reader nothing about which to
     * act on first. Money-in-sale-not-closed outranks a reported refund,
     * which outranks a declined card.
     */
    get.mockReset()
    mockApi([{
      ...ORDER,
      status: 'pending',
      gateway_payment_received: true,
      refund_reported_at: '2026-09-01T03:00:00Z',
      last_payment_error: 'บัตรถูกปฏิเสธ',
    }])
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('ปิดการขายอัตโนมัติไม่สำเร็จ')
    expect(text).not.toContain('บัตรถูกปฏิเสธ')
    expect(text).not.toContain('ผู้ให้บริการแจ้งว่ามีการคืนเงิน')
  })
})
