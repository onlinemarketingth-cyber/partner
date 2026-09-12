/**
 * TASK-245 — the commission screen, and the product it may not write.
 *
 * This screen had NO permission check of any kind. It has an `isSuperAdmin`
 * computed, but every use of it was about company SCOPE — which company's rows
 * to show — and none of it ever gated a control. Three buttons therefore led
 * straight to a 403 for a Company Admin:
 *
 *   • "+ ตั้งอัตราคอมมิชชั่น"  → POST /commission-rules, refused by
 *     StoreCommissionRuleRequest for a product linked to the shared catalogue.
 *   • "แก้ไขอัตราคอมมิชชั่น"    → the same, via UpdateCommissionRuleRequest.
 *   • the Wizard's first step   → PUT /products/{id} to write the plan type,
 *     refused by ProductPolicy::update for a shared OR linked product.
 *
 * ── 2026-09-12 — THE THIRD ONE NO LONGER EXISTS ──
 *
 * The Setup Wizard was deleted from CommissionPlansView, and with it the only
 * control on that screen that wrote `commission_plan_type`. The human's reason:
 * a company runs ONE plan type and varies the percentages, so the plan type is
 * a company decision (step 2), not a per-product one. The tests that pinned the
 * wizard's read-only plan-type field and its "sends no PUT /products" behaviour
 * went with it — there is no longer a code path on this screen for them to
 * describe. The Super-Admin-only per-product override lives on ProductEditView
 * and is covered by that view's own specs.
 *
 * The distinction that made this delicate was ADR-040's: commission stayed PER
 * COMPANY, so a Company Admin absolutely could set their own rate on a product
 * they could not otherwise touch. Hiding the rule button whenever the product
 * was not editable would have "fixed" the 403 by removing a right the human
 * agreed to — so the two questions were asked separately, and these tests
 * pinned the difference.
 *
 * ── 2026-09-11 — THE OWNER TOOK THAT RIGHT AWAY, ON PURPOSE ──
 *
 * Commission rate configuration is now Super Admin's alone. Five policies
 * (CommissionRulePolicy, CommissionOverrideRulePolicy, AgentRankPolicy,
 * CommissionGenerationRulePolicy, CommissionMatrixLevelRatePolicy) answer
 * create/update/delete with `$user->isSuperAdmin()` and nothing else, and the
 * six structural-settings *Update abilities were removed from the Company
 * Admin row of PermissionResolver::ROLE_ABILITIES. The owner's reason, stated
 * in those files: a commission rate is money, and one person owns those
 * numbers rather than every tenant admin holding them by virtue of a role.
 *
 * SO THESE TESTS CHANGED SIDES, and it is worth being blunt about which ones:
 * "a SHARED product still offers the rule button to a Company Admin" used to
 * be the assertion this file existed to protect, and it is now the assertion
 * it exists to forbid. The right was removed by a decision, not by a bug, and
 * the cost — a company can no longer finish its own commission setup
 * unassisted — is recorded in CommissionRulePolicy's docblock rather than
 * discovered from a support ticket.
 *
 * WHAT DID NOT CHANGE is the shape of the fix: the owner's house rule is still
 * "อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน ไม่ใช่ให้ error 403", so every
 * test below asks whether a control is ABSENT, never whether clicking it
 * produces a nice message.
 *
 * The per-row question (`permissions.set_commission_rule`) is still asked in
 * the view alongside the role one — see the last describe block for why a test
 * has to hold it in place now that the role check would hide the button first
 * either way.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

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
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionPlansView from '../CommissionPlansView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const AIA = { id: 2, name: 'AIA', slug: 'aia' }

/** Their own product: nothing about the PRODUCT stands in the way. */
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
 * A PLATFORM product. Its identity is the Super Admin's; its commission rows
 * are still written per company (ADR-040) — a Super Admin scoped to AIA sets
 * AIA's rate on it, which is why it must survive the company filter.
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
 * A CATALOG-LINKED product as a COMPANY ADMIN sees it. company_id is still
 * AIA, so nothing on screen distinguishes it — and since 2026-09-11 the
 * server answers `set_commission_rule: false` for a Company Admin on EVERY
 * product, this one included.
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

/**
 * The screen opens on ขั้นที่ 1 (เลือกบริษัท). Product rows live on ขั้นที่ 3,
 * which is where every rate control this file is about now sits — so every
 * test has to walk there first, exactly as an admin does.
 */
async function showProducts(wrapper: Wrapper) {
  await wrapper.get('[data-test="step-tab-3"]').trigger('click')
  await flushPromises()

  return wrapper
}

const buttonTexts = (w: Wrapper) => w.findAll('button').map((b) => b.text().trim())

function beSuperAdmin() {
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
}

beforeEach(() => {
  get.mockReset()

  const auth = useAuthStore()
  auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never
})

describe('CommissionPlansView — a Super Admin, on a product nothing else blocks', () => {
  it('offers the rate button', async () => {
    beSuperAdmin()
    const wrapper = await showProducts(await mountView([OWN]))

    expect(buttonTexts(wrapper)).toContain('+ ตั้งอัตราคอมมิชชั่น')
  })
})

describe('CommissionPlansView — a SHARED product', () => {
  it('is not dropped by the company filter', async () => {
    // company_id is null on a platform row; the old narrowing compared it to
    // the scoped id and threw it away, hiding the shared catalogue entirely
    // from the screen that sets its commission.
    beSuperAdmin()
    const wrapper = await showProducts(await mountView([SHARED]))

    expect(wrapper.text()).toContain('Vital Blueprint V5')
  })

  it('is still rate-settable by the Super Admin, because commission stays per company', async () => {
    /*
     * ADR-040's reason for keeping commission in commission_rules rather than
     * on the product row survives the 2026-09-11 narrowing: each company still
     * pays its own rate on the same shared product. What changed is only WHO
     * types the number — the rows are still per company.
     */
    beSuperAdmin()
    const wrapper = await showProducts(await mountView([SHARED]))

    expect(buttonTexts(wrapper)).toContain('+ ตั้งอัตราคอมมิชชั่น')
  })
})

describe('CommissionPlansView — a Company Admin sees everything and may change nothing', () => {
  /*
   * The 2026-09-11 decision, tested as the owner stated it: hidden, not 403.
   * Read access was deliberately left untouched, so "sees everything" is half
   * the assertion and not a throwaway — a Company Admin who cannot read the
   * rates their agents earn under cannot run the company.
   */
  it('still sees the product and the plan it is on', async () => {
    const wrapper = await showProducts(await mountView([OWN]))

    expect(wrapper.text()).toContain('AIA Own Package')
  })

  it('is offered no rate button, on any product', async () => {
    const wrapper = await showProducts(await mountView([OWN, SHARED]))

    expect(buttonTexts(wrapper)).not.toContain('+ ตั้งอัตราคอมมิชชั่น')
    expect(buttonTexts(wrapper)).not.toContain('แก้ไขอัตราคอมมิชชั่น')
  })

  it('says who can set it instead of leaving a blank space', async () => {
    const wrapper = await showProducts(await mountView([LINKED]))

    expect(wrapper.text()).toContain('Linked Package')
    expect(wrapper.text()).toContain('ตั้งค่าโดย Super Admin')
  })

  it('is told the rule up front, on ขั้นที่ 1, rather than finding it by clicking', async () => {
    /*
     * 2026-09-12 — the wording moved into the second person when the note
     * stopped being shown to everyone (the owner, editing as Super Admin, was
     * being told that only a Super Admin may edit). What the note has to do is
     * unchanged and is why it still exists: a screen with every button removed
     * and no sentence explaining it reads as broken, not as read-only.
     */
    const wrapper = await mountView([OWN])

    const note = wrapper.get('[data-test="commission-lock-note"]').text()
    expect(note).toContain('เปิดดูได้ทุกขั้นตอนแต่แก้ไขไม่ได้')
    expect(note).toContain('ติดต่อผู้ดูแลระบบ')
  })
})

describe('CommissionPlansView — the lock note speaks to the reader it is about', () => {
  it('is not shown to a Super Admin, who is the one doing the editing', async () => {
    /*
     * Owner, 2026-09-12: "ผม Login เป็น super admin อยู่ แต่ขึ้น ไม่ต้องขึ้น
     * คำเตือนนี้". A permission notice addressed in the second person to
     * somebody it does not describe is worse than no notice: on a screen they
     * are actively editing, it reads for a moment as though something is
     * blocked.
     */
    beSuperAdmin()
    const wrapper = await mountView([OWN])

    expect(wrapper.find('[data-test="commission-lock-note"]').exists()).toBe(false)
  })
})

describe("CommissionPlansView — the PRODUCT's plan type is shown, never edited, here", () => {
  /*
   * TWO DIFFERENT COLUMNS SHARE THIS NAME, and keeping them apart is what this
   * block is for.
   *
   *   products.commission_plan_type   a per-product OVERRIDE, nullable.
   *                                   Removed from this screen on 2026-09-12
   *                                   (a company runs one plan and varies the
   *                                   percentages); edited on ProductEditView,
   *                                   Super Admin only.
   *
   *   companies.commission_plan_type  the company's plan. Edited HERE, on step
   *                                   2, since later the same day — that is
   *                                   what step 2 is FOR, and the link-out it
   *                                   replaced was a dead end the owner
   *                                   reported as "ทำให้ UI สับสน".
   *
   * So a bare "this screen never writes commission_plan_type" assertion, which
   * is what stood here for a few hours, is now wrong in a way that would push
   * somebody to delete the step-2 button to make it pass. The tests below say
   * which column they mean.
   */
  const source = fs.readFileSync(
    path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'CommissionPlansView.vue'),
    'utf8',
  )

  it('still shows which plan the product falls under', async () => {
    beSuperAdmin()
    const wrapper = await showProducts(await mountView([SHARED]))

    expect(wrapper.get('[data-test="product-row-2"]').text()).toContain('แผน Unilevel')
  })

  it('never writes a plan type onto a PRODUCT from this screen', () => {
    // The product write is covered exactly below (one PUT, one field, and
    // that field is the PV); this is the same promise stated as the string a
    // re-introduction would have to contain.
    expect(source).not.toContain('/products/${p.id}`, { commission_plan_type')
    // `function` is load-bearing: the note explaining WHERE canEditPlanType()
    // used to live still mentions it by name, and a bare substring check
    // would fail on the comment that exists to stop it being re-added.
    expect(source).not.toContain('function canEditPlanType')
  })

  it("writes the COMPANY's plan type through commission's own endpoint", () => {
    /*
     * The positive half, and it belongs next to the negative one: without it,
     * a future edit that deleted step 2's plan switch to "tidy up the plan
     * type handling" would leave the test above passing and the screen unable
     * to do the thing it exists for.
     */
    expect(source).toContain("'/commission-settings'")
    expect(source).toContain('commission_plan_type: viewingPlanType.value')
  })

  it('writes exactly ONE product field from this screen, and it is the PV', () => {
    /*
     * 2026-09-12 — this assertion used to be `not.toContain('/products/${')`:
     * the screen wrote no product field at all once the wizard went. PV made
     * that false on purpose (step 2 edits every product's PV in one table,
     * because switching a company to PV makes all of them load-bearing at
     * once), so the promise is narrowed rather than dropped.
     *
     * What it still guarantees is the thing that mattered: exactly one
     * product write exists here, and it carries one field. A second PUT, or a
     * second field on this one, is how the per-product plan-type editor comes
     * back through a side door.
     */
    const writes = source.match(/commissionApi\.put\(`\/products\/\$\{[^`]*`, \{([^}]*)\}\)/g) ?? []

    expect(writes).toHaveLength(1)
    expect(writes[0]).toContain('pv_satang')
    expect(writes[0]).not.toContain('commission_plan_type')
  })
})

describe('CommissionPlansView — the per-row permission is still asked', () => {
  /*
   * WHY A SOURCE-TEXT TEST AND NOT A MOUNTED ONE.
   *
   * Since 2026-09-11 the role check hides the rate button for every Company
   * Admin before `permissions.set_commission_rule` gets a look in, and for a
   * Super Admin the server answers that flag `true` on every row (see
   * ProductResource). So there is no longer a (role, row) combination the
   * rendered screen can be put into where the row-level guard is the thing
   * that decides — which means no behavioural test can hold it in place, and
   * a guard nothing holds is a guard the next refactor deletes as dead.
   *
   * It must not be deleted. It is the ONLY thing standing between a
   * catalog-linked product and a 422/403 if the rate decision is ever revisited
   * and Company Admins get writes back — at which point the ADR-036 §5/§6 rule
   * becomes load-bearing again, on a screen nobody will re-audit.
   *
   * Same technique, and the same reason, as ScopedLookupOptions.spec.ts.
   */
  const source = fs.readFileSync(
    path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'CommissionPlansView.vue'),
    'utf8',
  )

  it('gates the rate controls on the row answer AND the role, not on either alone', () => {
    expect(source).toContain('canEditCommissionConfig && canSetCommission(p)')
  })

  /*
   * The companion assertion — "keeps catalog-linked products out of the wizard
   * by the same row answer", pinning `byCompany(products).filter(canSetCommission)`
   * in the wizard's product picker — was deleted with the wizard on 2026-09-12.
   * The row guard above is now the only place the flag is read, and it is the
   * one that matters: there is no second product list to fall out of.
   */
})
