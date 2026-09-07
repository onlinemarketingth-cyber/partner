/**
 * TASK-245 — the commission screen, and the product it may not write.
 *
 * This screen had NO permission check of any kind. It has an `isSuperAdmin`
 * computed, but every use of it is about company SCOPE — which company's rows
 * to show — and none of it ever gated a control. Three buttons therefore led
 * straight to a 403 for a Company Admin:
 *
 *   • "+ ตั้งอัตราคอมมิชชั่น"  → POST /commission-rules, refused by
 *     StoreCommissionRuleRequest for a product linked to the shared catalogue.
 *   • "แก้ไขอัตราคอมมิชชั่น"    → the same, via UpdateCommissionRuleRequest.
 *   • the Wizard's first step   → PUT /products/{id} to write the plan type,
 *     refused by ProductPolicy::update for a shared OR linked product.
 *
 * The distinction that makes this delicate is ADR-040's: commission stays PER
 * COMPANY, so a Company Admin absolutely may set their own rate on a product
 * they cannot otherwise touch. Hiding the rule button whenever the product is
 * not editable would have "fixed" the 403 by removing a right the human
 * agreed to — so the two questions are asked separately, and these tests pin
 * the difference.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
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

const AIA = { id: 2, name: 'AIA', slug: 'aia' }

/** Their own product: everything is theirs to set. */
const OWN = {
  id: 1,
  company_id: AIA.id,
  name: 'AIA Own Package',
  commission_plan_type: null,
  effective_plan_type: 'unilevel',
  commission_rate_type: null,
  permissions: { update: true, delete: true, set_commission_rule: true },
}

/**
 * A PLATFORM product. Its identity is the Super Admin's; its commission is
 * still this company's — that is ADR-040, and the payload says both.
 */
const SHARED = {
  id: 2,
  company_id: null,
  name: 'Vital Blueprint V5',
  commission_plan_type: 'unilevel',
  effective_plan_type: 'unilevel',
  commission_rate_type: null,
  permissions: { update: false, delete: false, set_commission_rule: true },
}

/**
 * A CATALOG-LINKED product. company_id is still AIA, so nothing on screen
 * distinguished it — and every write to it is Super Admin's (ADR-036 §5/§6).
 */
const LINKED = {
  id: 3,
  company_id: AIA.id,
  name: 'Linked Package',
  commission_plan_type: null,
  effective_plan_type: 'unilevel',
  commission_rate_type: null,
  permissions: { update: false, delete: false, set_commission_rule: false },
}

function mockApi(products: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/companies')) return { data: [AIA] }
    if (path.startsWith('/products')) return { data: products }

    return { data: [] }
  })
}

async function mountView(products: unknown[]) {
  mockApi(products)

  const active = useActiveCompanyStore()
  active.companies = [AIA]
  active.selectedId = AIA.id

  const wrapper = mount(CommissionPlansView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ConfirmDialog: true,
        BuddhistDateInput: true,
        PlatformScopeBadge: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const buttonTexts = (w: Wrapper) => w.findAll('button').map((b) => b.text().trim())

/**
 * The wizard button ON A PRODUCT CARD, which preselects that product — not
 * the header's "เริ่ม Wizard…", which opens it empty. The distinction matters:
 * an empty wizard cannot reach step 1's plan-type field at all, so a test that
 * clicked the wrong one would pass without exercising anything.
 */
async function openWizardFor(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((b) => b.text().trim() === 'Wizard')
  if (!button) throw new Error('ไม่พบปุ่ม Wizard บนการ์ดสินค้า')
  await button.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })

  const auth = useAuthStore()
  auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never
})

describe('CommissionPlansView — a product the company may write', () => {
  it('offers the rule button and the wizard', async () => {
    const wrapper = await mountView([OWN])

    expect(buttonTexts(wrapper)).toContain('+ ตั้งอัตราคอมมิชชั่น')
    expect(buttonTexts(wrapper)).toContain('Wizard')
  })
})

describe('CommissionPlansView — a SHARED product', () => {
  it('still offers the rule button, because commission stays per company', async () => {
    /*
     * The one that must NOT be hidden. ADR-040's whole reason for keeping
     * commission in commission_rules rather than on the product row is that
     * each company pays its own rate on the same shared product.
     */
    const wrapper = await mountView([SHARED])

    expect(wrapper.text()).toContain('Vital Blueprint V5')
    expect(buttonTexts(wrapper)).toContain('+ ตั้งอัตราคอมมิชชั่น')
  })

  it('is not dropped by the company filter', async () => {
    // company_id is null on a platform row; the old narrowing compared it to
    // the scoped id and threw it away, hiding the shared catalogue entirely
    // from the screen that sets its commission.
    const auth = useAuthStore()
    auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never

    const wrapper = await mountView([SHARED])

    expect(wrapper.text()).toContain('Vital Blueprint V5')
  })
})

describe('CommissionPlansView — a CATALOG-LINKED product', () => {
  it('hides both buttons even though the product is the company\'s own', async () => {
    /*
     * company_id === AIA, so `is_shared` is false and nothing visible on the
     * card said these would 403. The server's answer is the only thing that
     * knows.
     */
    const wrapper = await mountView([LINKED])

    expect(wrapper.text()).toContain('Linked Package')
    expect(buttonTexts(wrapper)).not.toContain('+ ตั้งอัตราคอมมิชชั่น')
    expect(buttonTexts(wrapper)).not.toContain('Wizard')
  })

  it('says who can set it instead of leaving a blank space', async () => {
    const wrapper = await mountView([LINKED])

    expect(wrapper.text()).toContain('ตั้งค่าโดย Super Admin')
  })

  it('keeps it out of the wizard\'s own product list', async () => {
    const wrapper = await mountView([OWN, LINKED])

    const openWizard = wrapper.findAll('button').find((b) => b.text().trim() === 'เปิดตัวช่วยตั้งค่า')
      ?? wrapper.findAll('button').find((b) => b.text().includes('Wizard'))
    expect(openWizard).toBeTruthy()
    await openWizard!.trigger('click')
    await flushPromises()

    const options = wrapper.findAll('option').map((o) => o.text())
    expect(options).toContain('AIA Own Package')
    expect(options).not.toContain('Linked Package')
  })
})

describe('CommissionPlansView — the wizard never writes a plan type it may not', () => {
  it('shows the plan type read-only for a shared product', async () => {
    const wrapper = await mountView([SHARED])

    await openWizardFor(wrapper)

    expect(wrapper.text()).toContain('รูปแบบแผนของสินค้ากลางตั้งโดย Super Admin')
  })

  it('and sends no PUT /products when moving on', async () => {
    // The 403 itself. Step 1 used to write the product row unconditionally
    // whenever the dropdown differed from the stored value.
    const wrapper = await mountView([SHARED])

    await openWizardFor(wrapper)
    await wrapper.findAll('button').find((b) => b.text().trim() === 'ถัดไป')!.trigger('click')
    await flushPromises()

    expect(put.mock.calls.filter((c) => String(c[0]).startsWith('/products/'))).toHaveLength(0)
  })
})
