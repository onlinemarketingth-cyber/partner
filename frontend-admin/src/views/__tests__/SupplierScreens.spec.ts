/**
 * 2026-09-16 — the three supplier screens, and the rules they must not lose.
 *
 * ── WHAT BREAKS SILENTLY HERE ──
 *
 * 1. THE PAYOUT BUTTON APPEARS WHEN IT SHOULDN'T. A supplier's balance is the
 *    NET of sales and shortfalls, so it can be zero or negative with plenty of
 *    rows behind it. Offering ตั้งจ่าย there produces a 422 and no explanation.
 *
 * 2. ONE FIGURE INSTEAD OF THREE. Accounting transfers `net`, reconciles
 *    against `gross`, and issues a certificate for the difference. A screen
 *    showing only the amount cannot be tied to a bank statement — and this is
 *    the first place in the whole product that withholds tax at all, so there
 *    is no precedent elsewhere to copy the habit from.
 *
 * 3. THE ADDRESS RENDERS FOR A SERVICE. The server omits the keys for a
 *    product that does not ship. A template that reads them anyway prints an
 *    empty "ที่อยู่จัดส่ง" block on an appointment — and invites somebody to
 *    "fix" it by sending nulls, which is the disclosure the omission prevents.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import SupplierPayoutsView from '../SupplierPayoutsView.vue'
import SupplierOrdersView from '../SupplierOrdersView.vue'
import SupplierSettlementsView from '../SupplierSettlementsView.vue'

const STUBS = {
  HeroHeader: { template: '<div><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  /*
   * 2026-09-17 — the supplier name on the payout screen became a link into
   * จัดการคู่ค้า, and a real RouterLink needs an injected router. Stubbed as a
   * plain element so the text it wraps still appears in `wrapper.text()` —
   * `true` would render <router-link-stub> and swallow the name these tests
   * assert on.
   */
  RouterLink: { template: '<a><slot /></a>' },
}

function mountView(component: unknown) {
  return mount(component as never, { global: { stubs: STUBS } })
}

const SUPPLIER: {
  supplier_id: number
  supplier_name: string
  is_active: boolean
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  terms_complete: boolean
  missing_terms: string[]
  payable_satang: number
  reserved_satang: number
  unreleased_satang: number
} = {
  supplier_id: 7,
  supplier_name: 'บริษัท ซัพพลายเออร์ จำกัด',
  is_active: true,
  bank_name: 'ธนาคารกสิกรไทย',
  bank_account_number: '123-4-56789-0',
  bank_account_holder_name: 'บริษัท ซัพพลายเออร์ จำกัด',
  terms_complete: true,
  // Empty, not absent: the screen reads this array to decide whether to offer
  // ตั้งจ่าย, and an undefined here would throw rather than fail an assertion.
  missing_terms: [],
  payable_satang: 100000,
  reserved_satang: 0,
  unreleased_satang: 25000,
}

/**
 * Typed explicitly, like the ORDER fixture in OrderPaymentsView.spec.ts and
 * for the same reason: a test spreads `{ ...REQUEST, wht_rate_at_time: null }`
 * to exercise the several-rates case, and an inferred `number` makes that a
 * type error at the spread site. Widening it there instead would only hide
 * the next field that needs it.
 */
const REQUEST: {
  id: number
  supplier_id: number
  supplier_name: string | null
  status: string
  source: string
  gross_satang: number
  wht_rate_at_time: number | null
  wht_satang: number
  net_satang: number
  wht_certificate_no: string | null
  bank_name: string | null
  bank_account_number: string | null
  bank_account_holder_name: string | null
  transferred_at: string | null
  transfer_reference: string | null
  created_at: string | null
} = {
  id: 1,
  supplier_id: 7,
  supplier_name: 'บริษัท ซัพพลายเออร์ จำกัด',
  status: 'approved',
  source: 'company_payout',
  gross_satang: 100000,
  wht_rate_at_time: 300,
  wht_satang: 3000,
  net_satang: 97000,
  wht_certificate_no: null,
  bank_name: 'ธนาคารกสิกรไทย',
  bank_account_number: '123-4-56789-0',
  bank_account_holder_name: 'บริษัท ซัพพลายเออร์ จำกัด',
  transferred_at: null,
  transfer_reference: null,
  created_at: '2026-09-16T03:00:00Z',
}

function mockPayouts(suppliers = [SUPPLIER], requests = [REQUEST]) {
  get.mockImplementation(async (path: string) =>
    path === '/supplier-payouts' ? { data: suppliers } : { data: requests },
  )
}

describe('SupplierPayoutsView — จ่ายคืนคู่ค้า', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    post.mockResolvedValue({ data: {} })
  })

  it('offers ตั้งจ่าย when there is a positive released balance', async () => {
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="raise-payout"]').exists()).toBe(true)
  })

  it('refuses to offer it on a negative balance and says why', async () => {
    // The owner ruled a shortfall is carried by the supplier and nets off
    // against their future sales. We do not invoice them for it, and we do not
    // offer a button that would 422.
    mockPayouts([{ ...SUPPLIER, payable_satang: -5000 }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="raise-payout"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('หักกลบกับยอดขายรอบถัดไป')
  })

  it('names the missing configuration rather than showing a dead button', async () => {
    // "GP ยังไม่ได้ตั้ง" is something somebody can go and fix. A greyed button
    // with no explanation is a support ticket.
    mockPayouts([{ ...SUPPLIER, terms_complete: false, missing_terms: ['gp'] }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="raise-payout"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ยังไม่ได้ตั้งเงื่อนไข GP')
  })

  it('refuses to pay a supplier whose bank account is blank, and says so', async () => {
    /*
     * 2026-09-17 — the terms can be COMPLETE and the payment still impossible.
     *
     * `terms_complete` covers GP and the release trigger, deliberately: a
     * balance is correct and worth showing without a bank account. But nobody
     * can transfer to an account that is not there, and discovering that after
     * raising the payout means unpicking a reservation.
     */
    mockPayouts([{
      ...SUPPLIER,
      terms_complete: true,
      missing_terms: ['bank'],
      bank_account_number: null,
    }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="raise-payout"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ยังไม่ได้กรอกบัญชีรับเงิน')
  })

  it('keeps listing a supplier whose deal has ended but whose balance has not', async () => {
    /*
     * Ending a deal does not end a debt. The first cut REFUSED to deactivate a
     * supplier we still owed, which sounds careful and is not: the flag then
     * never gets set and the supplier stays on the current list forever. The
     * rule moved here instead — deactivate freely, and the payout screen keeps
     * showing them, marked.
     */
    mockPayouts([{ ...SUPPLIER, is_active: false }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.text()).toContain('ปิดใช้งาน')
    expect(wrapper.find('[data-test="raise-payout"]').exists()).toBe(true)
  })

  it('shows gross, withholding and net — all three, not just the amount', async () => {
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    const text = wrapper.find('[data-test="awaiting-table"]').text()
    expect(text).toContain('1,000.00') // gross
    expect(text).toContain('30.00')    // withheld
    expect(text).toContain('970.00')   // net — what accounting actually transfers
  })

  it('says "หลายอัตรา" when a payout spans goods and services', async () => {
    // A null rate means SEVERAL rates applied, never "no tax" — which is 0.
    // Printing nothing there would read as untaxed.
    mockPayouts([SUPPLIER], [{ ...REQUEST, wht_rate_at_time: null, wht_satang: 3000 }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="awaiting-table"]').text()).toContain('หลายอัตรา')
  })

  it('asks before recording a transfer, with the NET figure in front of the person', async () => {
    // It settles the ledger rows behind it and cannot be undone. The number on
    // the confirmation is the number they are about to type into the bank.
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    await wrapper.find('[data-test="mark-transferred"]').trigger('click')

    const panel = wrapper.find('[data-test="transfer-panel"]')
    expect(panel.exists()).toBe(true)
    expect(panel.text()).toContain('970.00')
    expect(post).not.toHaveBeenCalled()
  })

  it('sends the reference and the certificate number with the transfer', async () => {
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    await wrapper.find('[data-test="mark-transferred"]').trigger('click')
    await wrapper.find('[data-test="transfer-reference"]').setValue('REF-77')
    await wrapper.find('[data-test="wht-certificate"]').setValue('WHT-2569-001')
    await wrapper.find('[data-test="confirm-transfer"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/supplier-payouts/1/mark-transferred', {
      transfer_reference: 'REF-77',
      wht_certificate_no: 'WHT-2569-001',
    })
  })

  it('shows the server refusal in full rather than a generic failure', async () => {
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    post.mockRejectedValueOnce(new ApiErrorStub('ยอดคงเหลือของคู่ค้ารายนี้เป็นศูนย์หรือติดลบ'))
    await wrapper.find('[data-test="raise-payout"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="error"]').text()).toContain('เป็นศูนย์หรือติดลบ')
  })
})

/* ───────────────────────────────────────────────────────────────────────── */

const SHIPPABLE_ORDER = {
  id: 11,
  order_number: 'ORD-0001',
  status: 'paid',
  status_label: 'ชำระแล้ว',
  paid_at: '2026-09-16T03:00:00Z',
  created_at: '2026-09-16T02:00:00Z',
  product_name: 'กล่องอาหารเสริม',
  sale_price_satang: 100000,
  customer_name: 'ลูกค้า ทดสอบ',
  requires_shipping: true,
  shipping_status: 'pending',
  shipping_status_label: 'รอจัดส่ง',
  tracking_number: null,
  shipped_at: null,
  shipping_recipient_name: 'ลูกค้า ทดสอบ',
  shipping_phone: '0812345678',
  shipping_address: '99/1 ถนนสีลม กรุงเทพฯ',
}

/** A service product: the server omits the contact keys entirely. */
const SERVICE_ORDER = {
  id: 12,
  order_number: 'ORD-0002',
  status: 'paid',
  status_label: 'ชำระแล้ว',
  paid_at: '2026-09-16T03:00:00Z',
  created_at: '2026-09-16T02:00:00Z',
  product_name: 'ตรวจสุขภาพ',
  sale_price_satang: 200000,
  customer_name: 'ลูกค้า สอง',
  requires_shipping: false,
  shipping_status: 'pending',
  shipping_status_label: 'รอจัดส่ง',
  tracking_number: null,
  shipped_at: null,
}

describe('SupplierOrdersView — คำสั่งซื้อสินค้าของฉัน', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    post.mockResolvedValue({ data: {} })
  })

  it('opens on รอจัดส่ง, because that is what a supplier came to find out', async () => {
    get.mockResolvedValue({ data: [SHIPPABLE_ORDER] })
    mountView(SupplierOrdersView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/supplier/orders?shipping_status=pending')
  })

  it('shows the delivery address for a product that ships', async () => {
    get.mockResolvedValue({ data: [SHIPPABLE_ORDER] })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()

    const block = wrapper.find('[data-test="shipping-block"]')
    expect(block.exists()).toBe(true)
    expect(block.text()).toContain('99/1 ถนนสีลม กรุงเทพฯ')
    expect(block.text()).toContain('0812345678')
  })

  it('renders no address block at all for a service', async () => {
    // Not an empty one. The keys are absent from the payload and the template
    // must not invite anybody to "fix" that by sending nulls.
    get.mockResolvedValue({ data: [SERVICE_ORDER] })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()

    expect(wrapper.find('[data-test="shipping-block"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ลูกค้า สอง')
  })

  it('will not submit a shipment without a tracking number', async () => {
    // It is the only part of "I sent it" anybody else can check — and on an
    // OnDelivered deal, saying it releases the supplier's own money.
    get.mockResolvedValue({ data: [SHIPPABLE_ORDER] })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()

    await wrapper.find('[data-test="open-ship"]').trigger('click')
    const confirm = wrapper.find('[data-test="confirm-ship"]')

    expect((confirm.element as HTMLButtonElement).disabled).toBe(true)
  })

  it('posts the tracking number against the right order', async () => {
    get.mockResolvedValue({ data: [SHIPPABLE_ORDER] })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()

    await wrapper.find('[data-test="open-ship"]').trigger('click')
    await wrapper.find('[data-test="tracking-input"]').setValue('TH123456789')
    await wrapper.find('[data-test="confirm-ship"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/supplier/orders/11/ship', { tracking_number: 'TH123456789' })
  })

  it('offers no shipment button on an order already sent', async () => {
    get.mockResolvedValue({
      data: [{ ...SHIPPABLE_ORDER, shipping_status: 'shipped', shipping_status_label: 'จัดส่งแล้ว', tracking_number: 'TH1' }],
    })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()

    expect(wrapper.find('[data-test="open-ship"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('TH1')
  })
})

/* ───────────────────────────────────────────────────────────────────────── */

describe('SupplierSettlementsView — ยอดค้างรับ', () => {
  const BALANCE = { payable_satang: 100000, reserved_satang: 50000, unreleased_satang: 25000 }

  const ROW = {
    id: 1,
    order_number: 'ORD-0001',
    product_name: 'กล่องอาหารเสริม',
    sale_price_satang: 200000,
    amount_satang: 100000,
    released_at: '2026-09-16T03:00:00Z',
    payment_status: 'pending',
    created_at: '2026-09-16T02:00:00Z',
  }

  function mockSettlements(balance = BALANCE, rows = [ROW], payouts: unknown[] = []) {
    get.mockImplementation(async (path: string) => {
      if (path === '/supplier/balance') return { data: balance }
      if (path === '/supplier/settlements') return { data: rows }
      return { data: payouts }
    })
  }

  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('explains what "ยังไม่ถึงกำหนด" is, because that figure is what starts the phone call', async () => {
    mockSettlements()
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    const note = wrapper.find('[data-test="unreleased-note"]')
    expect(note.exists()).toBe(true)
    expect(note.text()).toContain('ยังไม่ถึงกำหนดจ่ายตามเงื่อนไขในสัญญา')
  })

  it('hides that note when everything is already payable', async () => {
    mockSettlements({ ...BALANCE, unreleased_satang: 0 })
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    expect(wrapper.find('[data-test="unreleased-note"]').exists()).toBe(false)
  })

  it('shows a shortfall row rather than hiding it', async () => {
    // A number that reduces a statement has to be visible on that statement.
    mockSettlements(BALANCE, [{ ...ROW, amount_satang: -30000 }])
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    expect(wrapper.find('[data-test="settlements-table"]').text()).toContain('-300.00')
  })

  it('never shows the supplier our commission or our GP', async () => {
    /*
     * Asserted on rendered TEXT, not on html().
     *
     * The first draft of this test used html() and failed on the template's
     * own explanatory comment, which mentions both words — i.e. it was
     * checking the source rather than the screen. The real guarantee is two
     * layers deep anyway: the server does not send these fields to a supplier
     * at all (SupplierPortalController::settlements), so this asserts the
     * second layer, that nothing on the page invents a column for them.
     */
    mockSettlements()
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    const text = wrapper.text()
    expect(text).not.toContain('ค่าแนะนำ')
    expect(text).not.toContain('GP')
  })

  it('shows gross, withholding and net on the payment history', async () => {
    mockSettlements(BALANCE, [ROW], [{
      id: 5,
      status: 'transferred',
      gross_satang: 100000,
      wht_satang: 3000,
      net_satang: 97000,
      wht_certificate_no: 'WHT-1',
      transferred_at: '2026-09-16T04:00:00Z',
      transfer_reference: 'REF-1',
      rejection_reason: null,
      created_at: '2026-09-16T03:00:00Z',
    }])
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    const text = wrapper.find('[data-test="payouts-table"]').text()
    expect(text).toContain('1,000.00')
    expect(text).toContain('30.00')
    expect(text).toContain('970.00')
    expect(text).toContain('WHT-1')
  })
})
