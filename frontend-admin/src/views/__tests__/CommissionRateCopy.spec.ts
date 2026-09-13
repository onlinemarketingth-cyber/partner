/**
 * "ทำไมบริษัทเปิดใหม่ถึงไม่มีอัตราค่าคอมเลย ทำไมระบบไม่ตั้งให้เอง" (owner, 2026-09-13).
 *
 * The honest answer is that it never will. A rate the system invents and a
 * rate a human argued over are THE SAME ROW once either one resolves against a
 * closed deal, and a commission_ledger entry cannot be corrected afterwards
 * (BR-4) — so BR-7 forbids defaulting the one value on this screen that is
 * money. Step 3.1's red panel is that refusal, deliberately.
 *
 * What the complaint was actually about is the COST of the refusal: an admin
 * setting up the group's fourth company retyped every rate the third one
 * already had. So the cure is copying — which has a source company a human
 * picked, and is therefore a decision rather than a guess — made one click
 * plus a confirmation.
 *
 * THE CONFIRMATION IS THE FEATURE, and this file exists to keep it one. Every
 * test below defends a property that a plausible simplification would remove
 * while leaving the button working:
 *
 *   1. PICKING A SOURCE WRITES NOTHING. The preview is `dry_run: true` on the
 *      same endpoint as the copy, so what the admin approved and what the
 *      server does cannot drift apart — but only while the first call really
 *      is a dry run.
 *   2. THE SUMMARY NAMES THE ROWS, INCLUDING THE SKIPPED ONES. A count says
 *      something is missing; only the label and the server's Thai `reason` say
 *      whether the admin still has to go and set that rate by hand.
 *   3. NOTHING-TO-COPY EXPLAINS ITSELF. A disabled button with no sentence
 *      beside it is the dead end the whole 4-step redesign exists to remove.
 *   4. A FAILURE IS VISIBLE. A copy that silently does nothing leaves an admin
 *      believing rates exist that do not, which on this screen means believing
 *      people are being paid.
 *   5. A COMPANY ADMIN NEVER SEES THE BUTTON. The endpoint is Super Admin only
 *      (403), and the house rule is hide, never 403 (owner, 2026-09-11).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionPlansView from '../CommissionPlansView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

/** The company being configured — the one with nothing in step 3.1. */
const AIA = { id: 2, name: 'AIA', slug: 'aia' }
/** The sibling that has been live for a year — the only sane source. */
const BKI = { id: 3, name: 'กรุงเทพประกันภัย', slug: 'bki' }

function product(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    company_id: AIA.id,
    name: 'AIA Health Plus',
    category: null,
    price_satang: 890000,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    commission_rate_type: null,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...over,
  }
}

const READY = {
  state: 'ready',
  blocking_step: null,
  products_total: 1,
  products_covered: 1,
  issues: [],
  can_fix: true,
}

type CopyEntry = {
  scope: 'company' | 'category' | 'product'
  label: string
  rate_type: 'percentage' | 'fixed_satang'
  rate_value: number
  product_id: number | null
  product_category_id: number | null
  reason?: string
}

function entry(over: Partial<CopyEntry> = {}): CopyEntry {
  return {
    scope: 'company',
    label: 'ค่าเริ่มต้นทั้งบริษัท',
    rate_type: 'percentage',
    rate_value: 300,
    product_id: null,
    product_category_id: null,
    ...over,
  }
}

/** POST /commission-rules/copy's payload, shaped exactly as the endpoint sends it. */
function copyResult(over: {
  dry_run?: boolean
  agentCopied?: CopyEntry[]
  agentSkipped?: CopyEntry[]
  leaderCopied?: CopyEntry[]
  leaderSkipped?: CopyEntry[]
} = {}) {
  const { dry_run = true, agentCopied = [], agentSkipped = [], leaderCopied = [], leaderSkipped = [] } = over

  return {
    data: {
      dry_run,
      from_company: { id: BKI.id, name: BKI.name },
      to_company: { id: AIA.id, name: AIA.name },
      // The server counts only what it will create; skipped rows are not in it.
      total_to_copy: agentCopied.length + leaderCopied.length,
      agent_rates: { copied: agentCopied, skipped: agentSkipped },
      leader_rates: { copied: leaderCopied, skipped: leaderSkipped },
    },
  }
}

interface Fixture {
  products?: unknown[]
  rules?: unknown[]
}

async function mountView(fixture: Fixture = {}) {
  const { products = [product()], rules = [] } = fixture

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) return { data: { commission_plan_type: 'unilevel' } }
    // Two companies, because a copy with nothing to copy FROM is not the
    // feature under test — the source list is the whole entry point.
    if (path.startsWith('/companies')) return { data: [AIA, BKI] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/commission-rules')) return { data: rules }
    if (path.startsWith('/commission-override-rules')) return { data: [] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [AIA, BKI]
  active.selectedId = AIA.id

  const wrapper = mount(CommissionPlansView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function goToStep(wrapper: Wrapper, step: 1 | 2 | 3 | 4) {
  await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
  await flushPromises()
}

/** Open step 3.1's copy modal and pick กรุงเทพประกันภัย as the source. */
async function openAndPickSource(wrapper: Wrapper) {
  await wrapper.get('[data-test="copy-rates-open"]').trigger('click')
  await wrapper.get('[data-test="copy-rates-source"]').setValue(String(BKI.id))
  await flushPromises()
}

/** Every call to the copy endpoint, in order, as bodies. */
function copyCalls() {
  return post.mock.calls
    .filter((c) => c[0] === '/commission-rules/copy')
    .map((c) => c[1] as { from_company_id: number; to_company_id: number; dry_run: boolean })
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CommissionPlansView — copying rates is offered, never seeded', () => {
  it('offers the copy button beside the manual one, on the empty company default', async () => {
    /*
     * Both buttons, in the same panel, on purpose: copying is a shortcut to a
     * decision another company already made, and typing the number is still
     * what this screen is for. Losing either one turns the panel back into a
     * single dead end.
     */
    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)

    const panel = wrapper.get('[data-test="step3-company-default"]')
    expect(panel.find('[data-test="add-company-default"]').exists()).toBe(true)
    expect(panel.get('[data-test="copy-rates-open"]').text()).toBe('คัดลอกจากบริษัทอื่น')
  })

  it('hides the copy button from a Company Admin', async () => {
    /*
     * POST /commission-rules/copy is Super Admin only and 403s everybody else.
     * The owner's rule for exactly this case: "อันไหนสิทธิ์ company admin ทำไม่ได้
     * ต้องซ่อน ไม่ใช่ให้ error 403" — so the control is absent, not disabled.
     */
    useAuthStore().user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)

    expect(wrapper.find('[data-test="copy-rates-open"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="copy-rates-modal"]').exists()).toBe(false)
  })

  it('never offers the company itself as a source', async () => {
    // from === to is a 422, and an option that can only fail is not an option.
    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await wrapper.get('[data-test="copy-rates-open"]').trigger('click')

    const options = wrapper.get('[data-test="copy-rates-source"]').findAll('option[value]:not([disabled])')
    expect(options.map((o) => o.text())).toEqual([BKI.name])
  })
})

describe('CommissionPlansView — picking a source previews and writes nothing', () => {
  it('fires exactly one dry run and no write', async () => {
    post.mockResolvedValue(copyResult({ agentCopied: [entry()] }))

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    expect(copyCalls()).toEqual([{ from_company_id: BKI.id, to_company_id: AIA.id, dry_run: true }])
    // The assertion that matters: nothing on the screen has changed yet, so
    // the reload that repaints step 3 must not have run either.
    expect(wrapper.find('[data-test="company-default-pill"]').text()).toBe('ยังไม่มี')
  })

  it('lists every copied row with its label and formatted rate', async () => {
    post.mockResolvedValue(
      copyResult({
        agentCopied: [
          entry({ label: 'ค่าเริ่มต้นทั้งบริษัท', rate_value: 300 }),
          entry({ scope: 'category', label: 'หมวดหมู่: ประกันสุขภาพ', rate_type: 'fixed_satang', rate_value: 50000, product_category_id: 7 }),
        ],
        leaderCopied: [entry({ label: 'หัวหน้าทีม — ค่าเริ่มต้นทั้งบริษัท', rate_value: 150 })],
      }),
    )

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    const summary = wrapper.get('[data-test="copy-rates-summary"]')
    // BR-3 — the rate is read back in THB/%, never in the satang and basis
    // points the payload carries, so the admin approves the number they would
    // have typed.
    expect(summary.get('[data-test="copy-rates-agent-0"]').text()).toContain('3.00%')
    expect(summary.get('[data-test="copy-rates-agent-1"]').text()).toContain('หมวดหมู่: ประกันสุขภาพ')
    expect(summary.get('[data-test="copy-rates-agent-1"]').text()).toContain('500 บาท')
    expect(summary.get('[data-test="copy-rates-leader-0"]').text()).toContain('1.50%')
    expect(summary.text()).toContain('จะสร้างใหม่ 3 รายการ')
  })

  it('names each skipped row and the reason it was skipped', async () => {
    /*
     * The three server-side skips an admin can actually act on: a scope that
     * already has a live rate here (never overwritten), and a rate pointing at
     * the SOURCE company's own product or category (BR-6 — it does not exist
     * over here to point at).
     */
    post.mockResolvedValue(
      copyResult({
        agentCopied: [entry()],
        agentSkipped: [entry({ scope: 'product', label: 'BKI Cancer Care', product_id: 55, reason: 'สินค้านี้เป็นของบริษัทต้นทาง' })],
        leaderSkipped: [entry({ label: 'หัวหน้าทีม — ค่าเริ่มต้นทั้งบริษัท', reason: 'บริษัทปลายทางมีอัตรานี้อยู่แล้ว' })],
      }),
    )

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    const skipped = wrapper.get('[data-test="copy-rates-skipped"]')
    expect(skipped.text()).toContain('ไม่ได้คัดลอก 2 รายการ')
    expect(skipped.get('[data-test="copy-rates-skipped-0"]').text()).toContain('BKI Cancer Care')
    expect(skipped.get('[data-test="copy-rates-skipped-0"]').text()).toContain('สินค้านี้เป็นของบริษัทต้นทาง')
    expect(skipped.get('[data-test="copy-rates-skipped-1"]').text()).toContain('บริษัทปลายทางมีอัตรานี้อยู่แล้ว')
    // And the skips are NOT in the count — the button promises only what the
    // server will create.
    expect(wrapper.get('[data-test="copy-rates-confirm"]').text()).toBe('คัดลอก 1 รายการ')
  })
})

describe('CommissionPlansView — confirming is the only thing that writes', () => {
  it('posts dry_run: false with the same two companies, then repaints step 3', async () => {
    post.mockResolvedValue(copyResult({ agentCopied: [entry(), entry({ scope: 'category', label: 'หมวดหมู่: ประกันชีวิต', product_category_id: 8 })] }))

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    expect(wrapper.get('[data-test="copy-rates-confirm"]').text()).toBe('คัดลอก 2 รายการ')

    // The rows the reload is supposed to bring back. Swapped in before the
    // confirm so the repaint is observable rather than assumed.
    get.mockImplementation(async (path: string) => {
      if (path.startsWith('/commission-readiness')) return READY
      if (path.startsWith('/commission-settings')) return { data: { commission_plan_type: 'unilevel' } }
      if (path.startsWith('/companies')) return { data: [AIA, BKI] }
      if (path.startsWith('/products')) return { data: [product()] }
      if (path.startsWith('/commission-rules')) {
        return {
          data: [{
            id: 91,
            company_id: AIA.id,
            cert_tier: null,
            product: null,
            product_category: null,
            rate_type: 'percentage',
            rate_value: 300,
            effective_from: '2020-01-01',
            effective_to: null,
            renewal_rate_type: null,
            renewal_rate_value: null,
            renewal_recurs: false,
          }],
        }
      }
      if (path.startsWith('/commission-override-rules')) return { data: [] }

      return { data: [] }
    })
    post.mockResolvedValue(copyResult({ dry_run: false, agentCopied: [entry()] }))

    await wrapper.get('[data-test="copy-rates-confirm"]').trigger('click')
    await flushPromises()

    expect(copyCalls()).toEqual([
      { from_company_id: BKI.id, to_company_id: AIA.id, dry_run: true },
      { from_company_id: BKI.id, to_company_id: AIA.id, dry_run: false },
    ])
    // The modal closing is the admin's signal that the rows behind it are the
    // new ones, so both halves are asserted together.
    expect(wrapper.find('[data-test="copy-rates-modal"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="company-default-pill"]').text()).toBe('เรียบร้อย')
    expect(wrapper.find('[data-test="company-default-rule-91"]').exists()).toBe(true)
  })

  it('refuses to copy nothing, and says why instead of just greying out', async () => {
    /*
     * The realistic zero: the source's every rate is already matched here, so
     * there is nothing to create — and "ของเดิมที่นี่จะไม่ถูกทับ" is the half an
     * admin staring at a dead button would otherwise have to guess at.
     */
    post.mockResolvedValue(copyResult({ agentSkipped: [entry({ reason: 'บริษัทปลายทางมีอัตรานี้อยู่แล้ว' })] }))

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    const confirm = wrapper.get('[data-test="copy-rates-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(confirm.text()).toBe('คัดลอก 0 รายการ')
    expect(wrapper.get('[data-test="copy-rates-empty"]').text()).toContain('ไม่มีอะไรให้คัดลอก')

    await confirm.trigger('click')
    await flushPromises()

    expect(copyCalls().filter((c) => !c.dry_run)).toHaveLength(0)
  })
})

describe('CommissionPlansView — a failed copy is never silent', () => {
  it('shows the error line when the preview fails', async () => {
    post.mockRejectedValue(new Error('boom'))

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    expect(wrapper.get('[data-test="copy-rates-error"]').text()).toBe('ดูตัวอย่างไม่สำเร็จ')
    expect(wrapper.find('[data-test="copy-rates-summary"]').exists()).toBe(false)
  })

  it('keeps the modal open and shows the error line when the copy itself fails', async () => {
    /*
     * Closing on failure would leave the admin looking at the same red panel
     * with no idea whether the copy half-happened — and the endpoint is
     * transactional, so "nothing was written" is a fact worth being able to
     * read rather than infer.
     */
    post.mockResolvedValue(copyResult({ agentCopied: [entry()] }))

    const wrapper = await mountView({ rules: [] })
    await goToStep(wrapper, 3)
    await openAndPickSource(wrapper)

    post.mockRejectedValue(new Error('boom'))
    await wrapper.get('[data-test="copy-rates-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="copy-rates-modal"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="copy-rates-error"]').text()).toBe('คัดลอกไม่สำเร็จ')
    expect(wrapper.get('[data-test="company-default-pill"]').text()).toBe('ยังไม่มี')
  })
})
