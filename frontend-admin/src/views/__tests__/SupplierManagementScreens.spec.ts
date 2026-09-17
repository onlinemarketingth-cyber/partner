/**
 * 2026-09-17 — จัดการคู่ค้า: the screen that had to exist, in the place the
 * owner asked for it.
 *
 * ── WHAT THESE TESTS ARE ACTUALLY DEFENDING ──
 *
 * 1. THE SCREEN EXISTS AT ALL. The supplier feature shipped with every column
 *    built and no way to create a supplier, so the chain broke at step one and
 *    56 backend tests passed anyway — each had built its own fixture. A
 *    fixture never walks the path a person walks.
 *
 * 2. IT IS NOT A PANEL ON จัดการบริษัท. The first fix put it there and was
 *    rejected: a tenant we pay commission TO and a counterparty we buy goods
 *    FROM are different relationships, and the screen that conflates them
 *    teaches the wrong model of the business. CompanyManagementView must stay
 *    free of supplier controls, asserted here rather than hoped for.
 *
 * 3. UNITS. `gp_value` is ONE field carrying TWO units decided by `gp_mode`:
 *    basis points for the percentage modes, satang for the fixed one. A person
 *    types 30 and the API must receive 3000; a person types 500 on the fixed
 *    mode and the API must receive 50000. Get that wrong and the number is
 *    plausible, stored, and a hundredfold out.
 *
 * 4. A HALF-FILLED DEAL SAVES. Deals are negotiated in stages. A form that
 *    refuses until every term is agreed does not produce a complete record; it
 *    produces a note in somebody's notebook.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422

    body: unknown = undefined
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

const routeParams = { id: '7' }
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: routeParams }),
  RouterLink: { template: '<a><slot /></a>' },
}))

import SupplierManagementView from '../SupplierManagementView.vue'
import SupplierDetailView from '../SupplierDetailView.vue'
import CompanyManagementView from '../CompanyManagementView.vue'

const STUBS = {
  // Both slots, not just the default one: the "เพิ่มคู่ค้า" button lives in
  // `#actions`, and a stub that drops it makes every create test fail with
  // "empty DOMWrapper" — which reads as a missing button rather than as a
  // missing stub slot.
  HeroHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  InfoPopover: true,
  RouterLink: { template: '<a><slot /></a>' },
}

function mountView(component: unknown) {
  return mount(component as never, { global: { stubs: STUBS } })
}

/*
 * Typed explicitly rather than inferred, like the ORDER fixture in
 * OrderPaymentsView.spec.ts: a test spreads `{ ...SUPPLIER, gp_mode: null }`
 * to exercise the half-configured deal, and an inferred literal type makes
 * that a type error at the spread site. Widening it there instead would only
 * hide the next field that needs it.
 */
const SUPPLIER: {
  id: number
  name: string
  legal_name: string | null
  tax_id: string | null
  contact_name: string | null
  contact_phone: string | null
  contact_email: string | null
  address: string | null
  is_active: boolean
  gp_mode: string | null
  gp_value: number | null
  release_trigger: string | null
  min_withdrawal_satang: number | null
  wht_rate: number | null
  payout_bank_name: string | null
  payout_bank_account_number: string | null
  payout_bank_account_name: string | null
  terms_complete: boolean
  missing_terms: string[]
  products_count: number
  payable_satang: number
  unreleased_satang: number
} = {
  id: 7,
  name: 'คลินิกความงาม ก.',
  legal_name: 'บริษัท คลินิกความงาม ก. จำกัด',
  tax_id: '0105561234567',
  contact_name: 'คุณสมชาย',
  contact_phone: '0812345678',
  contact_email: 'somchai@example.com',
  address: null,
  is_active: true,
  gp_mode: 'percent_of_sale',
  gp_value: 3000,
  release_trigger: 'on_payment',
  min_withdrawal_satang: null,
  wht_rate: 300,
  payout_bank_name: 'ธนาคารกสิกรไทย',
  payout_bank_account_number: '123-4-56789-0',
  payout_bank_account_name: 'บริษัท คลินิกความงาม ก. จำกัด',
  terms_complete: true,
  missing_terms: [],
  products_count: 3,
  payable_satang: 450000,
  unreleased_satang: 12000,
}

const DETAIL = {
  ...SUPPLIER,
  reserved_satang: 0,
  paid_satang: 900000,
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  routeParams.id = '7'
})

// ── The list ─────────────────────────────────────────────────────────────

describe('SupplierManagementView — จัดการคู่ค้า', () => {
  function mockList(rows = [SUPPLIER]) {
    get.mockResolvedValue({ data: rows })
  }

  it('lists suppliers with what we owe them on the row itself', async () => {
    // One click away per row means nobody looks, and "who are we behind with"
    // is the question this screen gets opened for.
    mockList()
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    const text = wrapper.find('[data-test="suppliers-table"]').text()
    expect(text).toContain('คลินิกความงาม ก.')
    expect(text).toContain('4,500.00')
    expect(text).toContain('30%')
  })

  it('names what is missing rather than leaving a blank cell', async () => {
    mockList([{
      ...SUPPLIER,
      gp_mode: null,
      gp_value: null,
      terms_complete: false,
      missing_terms: ['gp', 'release_trigger'],
    }])
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    expect(wrapper.find('[data-test="blocked-reason"]').text()).toContain('ยังไม่ได้ตั้ง GP')
  })

  it('asks for ALL suppliers by default, not just the active ones', async () => {
    /*
     * The tri-state filter matters: reading a missing parameter as `false`
     * would hide every working supplier behind an "inactive" filter nobody
     * chose. Easy to write, invisible once written.
     */
    mockList()
    mountView(SupplierManagementView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/suppliers')
  })

  it('sends a percentage GP as basis points', async () => {
    mockList([])
    post.mockResolvedValue({ data: SUPPLIER })
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    await wrapper.find('[data-test="add-supplier"]').trigger('click')
    await wrapper.find('[data-test="supplier-name"]').setValue('คู่ค้าใหม่')
    await wrapper.find('[data-test="gp-mode"]').setValue('percent_of_sale')
    await wrapper.find('[data-test="gp-value"]').setValue('30')
    await wrapper.find('[data-test="save-supplier"]').trigger('click')
    await flushPromises()

    // 30 typed, 3000 stored. The whole reason supplierTerms.ts exists.
    expect(post).toHaveBeenCalledWith('/suppliers', expect.objectContaining({
      name: 'คู่ค้าใหม่',
      gp_mode: 'percent_of_sale',
      gp_value: 3000,
    }))
  })

  it('sends a fixed GP as satang, from the same field', async () => {
    mockList([])
    post.mockResolvedValue({ data: SUPPLIER })
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    await wrapper.find('[data-test="add-supplier"]').trigger('click')
    await wrapper.find('[data-test="supplier-name"]').setValue('คู่ค้าใหม่')
    await wrapper.find('[data-test="gp-mode"]').setValue('fixed_per_unit')
    await wrapper.find('[data-test="gp-value"]').setValue('500')
    await wrapper.find('[data-test="save-supplier"]').trigger('click')
    await flushPromises()

    // ฿500.00 typed, 50000 satang stored — the SAME box that sent 3000 above.
    expect(post).toHaveBeenCalledWith('/suppliers', expect.objectContaining({
      gp_mode: 'fixed_per_unit',
      gp_value: 50000,
    }))
  })

  it('clears the GP value when the mode changes so the unit cannot be misread', async () => {
    /*
     * 30 meaning 30% becomes 30 meaning ฿0.30 the moment the mode flips. The
     * field would keep showing "30" while meaning something a hundred times
     * smaller, and nothing on the screen would say so.
     */
    mockList([])
    post.mockResolvedValue({ data: SUPPLIER })
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    await wrapper.find('[data-test="add-supplier"]').trigger('click')
    await wrapper.find('[data-test="supplier-name"]').setValue('คู่ค้าใหม่')
    await wrapper.find('[data-test="gp-mode"]').setValue('percent_of_sale')
    await wrapper.find('[data-test="gp-value"]').setValue('30')
    await wrapper.find('[data-test="gp-mode"]').setValue('fixed_per_unit')
    await flushPromises()

    expect((wrapper.find('[data-test="gp-value"]').element as HTMLInputElement).value).toBe('')
  })

  it('saves a supplier with a name and nothing else', async () => {
    // A deal negotiated in stages is the normal case.
    mockList([])
    post.mockResolvedValue({ data: SUPPLIER })
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    await wrapper.find('[data-test="add-supplier"]').trigger('click')
    await wrapper.find('[data-test="supplier-name"]').setValue('คู่ค้าใหม่')
    await wrapper.find('[data-test="save-supplier"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/suppliers', expect.objectContaining({
      name: 'คู่ค้าใหม่',
      gp_mode: null,
      gp_value: null,
      release_trigger: null,
    }))
  })

  it('puts a field error under its own field rather than in a banner', async () => {
    // "ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน" is useless at the top of a
    // four-panel form and exact underneath the box it is about.
    mockList([])
    const err = new ApiErrorStub('failed')
    err.body = { errors: { gp_value: ['ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน'] } }
    post.mockRejectedValue(err)

    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    await wrapper.find('[data-test="add-supplier"]').trigger('click')
    await wrapper.find('[data-test="supplier-name"]').setValue('คู่ค้าใหม่')
    await wrapper.find('[data-test="save-supplier"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="gp-value-error"]').text()).toContain('คู่กัน')
  })
})

// ── The detail page ──────────────────────────────────────────────────────

describe('SupplierDetailView', () => {
  function mockDetail(overrides: Record<string, unknown> = {}) {
    get.mockImplementation((url: string) => {
      if (url === '/suppliers/7') return Promise.resolve({ data: { ...DETAIL, ...overrides } })
      if (url === '/suppliers/7/products') return Promise.resolve({ data: [] })
      if (url === '/suppliers/7/users') return Promise.resolve({ data: [] })
      if (url === '/supplier-payouts/7/settlements') return Promise.resolve({ data: [] })

      return Promise.resolve({ data: [] })
    })
  }

  it('opens on the deal itself, editable in place', async () => {
    mockDetail()
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    // 3000 basis points arrives and 30 is rendered — the conversion running
    // the other way round.
    expect((wrapper.find('[data-test="gp-value"]').element as HTMLInputElement).value).toBe('30')
    expect((wrapper.find('[data-test="bank-account-number"]').element as HTMLInputElement).value)
      .toBe('123-4-56789-0')
  })

  it('warns at the top when the deal cannot be paid yet', async () => {
    mockDetail({ terms_complete: false, missing_terms: ['gp'] })
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    expect(wrapper.find('[data-test="incomplete-banner"]').exists()).toBe(true)
  })

  it('saves an edit without sending back the read-only balances', async () => {
    /*
     * The detail payload carries balances alongside the deal terms. Sending
     * them back would be rejected field by field — and quietly dropping them
     * inside the component is how a form ends up saving a shape nobody meant,
     * so the stripping is asserted rather than assumed.
     */
    mockDetail()
    put.mockResolvedValue({ data: DETAIL })
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    await wrapper.find('[data-test="save-supplier"]').trigger('click')
    await flushPromises()

    const payload = put.mock.calls[0]?.[1] as Record<string, unknown>
    expect(payload).not.toHaveProperty('payable_satang')
    expect(payload).not.toHaveProperty('paid_satang')
    expect(payload).not.toHaveProperty('terms_complete')
    expect(payload.gp_value).toBe(3000)
  })

  it('creates a partner login against the SUPPLIER, never a company', async () => {
    /*
     * The whole point of the rework. A partner carries supplier_id and no
     * company_id; sending a company here would recreate the conflation the
     * owner rejected — and the server would refuse it, which is the second
     * line of defence, not the first.
     */
    mockDetail()
    post.mockResolvedValue({ data: { id: 1 } })
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    await wrapper.find('[data-test="tab-users"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="new-user-first-name"]').setValue('คู่ค้า')
    await wrapper.find('[data-test="new-user-last-name"]').setValue('ทดสอบ')
    await wrapper.find('[data-test="new-user-email"]').setValue('partner@example.com')
    await wrapper.find('[data-test="new-user-password"]').setValue('Str0ng!Passw0rd#2569')
    await wrapper.find('[data-test="create-partner-user"]').trigger('click')
    await flushPromises()

    const payload = post.mock.calls[0]?.[1] as Record<string, unknown>
    expect(payload.supplier_id).toBe(7)
    expect(payload.role).toBe('company_partner')
    expect(payload).not.toHaveProperty('company_id')
  })

  it('never echoes the password back after creating the account', async () => {
    // Shown once, handed over out of band, gone. Same handling as every other
    // account creation in this console.
    mockDetail()
    post.mockResolvedValue({ data: { id: 1 } })
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    await wrapper.find('[data-test="tab-users"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="new-user-email"]').setValue('partner@example.com')
    await wrapper.find('[data-test="new-user-password"]').setValue('Str0ng!Passw0rd#2569')
    await wrapper.find('[data-test="create-partner-user"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="new-user-password"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('Str0ng!Passw0rd#2569')
  })

  it('will not offer a login for a supplier whose deal has ended', async () => {
    mockDetail({ is_active: false })
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    await wrapper.find('[data-test="tab-users"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="create-partner-user"]').attributes('disabled')).toBeDefined()
  })

  it('loads a tab only when it is opened', async () => {
    // Four tabs, three of them paginated lists. Fetching all of them on mount
    // makes the page slower for the one somebody actually wanted.
    mockDetail()
    const wrapper = mountView(SupplierDetailView)
    await flushPromises()

    expect(get).not.toHaveBeenCalledWith('/suppliers/7/products')

    await wrapper.find('[data-test="tab-products"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/suppliers/7/products')
  })
})

// ── The separation itself ────────────────────────────────────────────────

describe('CompanyManagementView stays free of supplier settings', () => {
  it('offers no supplier controls at all', async () => {
    /*
     * THE REGRESSION THIS FILE IS MOST WORTH WRITING FOR.
     *
     * A "ตั้งค่าคู่ค้า" panel lived on this screen for one day and the owner
     * rejected it in the strongest terms. Nothing errors if it comes back —
     * it would simply be wrong again, quietly, in the same way.
     */
    get.mockResolvedValue({ data: [], meta: { total: 0 } })
    const wrapper = mountView(CompanyManagementView)
    await flushPromises()

    expect(wrapper.find('[data-test="supplier-terms-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="supplier-terms-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="is-supplier"]').exists()).toBe(false)
    // `text()` rather than `html()`: the file still CARRIES the word in a
    // comment explaining why the panel is gone, and matching the comment
    // would make this test pass for the wrong reason.
    expect(wrapper.text()).not.toContain('ตั้งค่าคู่ค้า')
  })
})
