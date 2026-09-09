/**
 * 2026-09-09 — reported from production: the buy button had vanished from a
 * live share link ("ระบบจ่ายเงินชำระเงินทำไมมันหายไปจากหน้านี้").
 *
 * The cause was two screens agreeing with each other and both being wrong.
 * Promoting a product to สินค้ากลาง cleared its journey, and THIS page then
 * replaced the journey selector with a grey note saying a shared product had
 * none to choose — so the journey fell back to the selling company's Medical
 * Package fail-safe (whose first step is an appointment), the share page
 * correctly refused to offer checkout, and no screen in the console would let
 * anyone put it back.
 *
 * Journeys can now be platform-owned, so what is pinned here is:
 *
 *   · a สินค้ากลาง gets a real selector, offering เส้นทางกลาง only;
 *   · the page SAYS whether the chosen journey leaves the share link with a
 *     buy button — the one consequence of this field that was invisible from
 *     the page it is chosen on.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

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

/*
 * The route, made switchable.
 *
 * ProductEditView decides create-vs-edit from `route.params.id`, and this
 * file needs both. `vi.mock` factories are hoisted above everything else in
 * the module, so the object they close over has to be hoisted with them —
 * a plain `const` above the mock is still in its temporal dead zone when the
 * factory runs. Each mount helper sets this before mounting.
 */
const routeState = vi.hoisted(() => ({ params: {} as Record<string, string> }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: routeState.params, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductEditView from '../ProductEditView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const stage = (key: string, position: number) => ({ key, label: key, position })

/** ลงทะเบียน → ชำระเงิน. Payment is second, so the link can be bought from. */
const DIRECT_SALE = {
  id: 1,
  company_id: null,
  key: 'direct_sale_default',
  name: 'Direct Sale (default)',
  is_system: true,
  stages: [stage('complete_registered', 0), stage('complete_payment', 1)],
}

/** ลงทะเบียน → นัดหมาย → พบแพทย์ → ชำระเงิน → นัดถัดไป. No buy button. */
const MEDICAL = {
  id: 2,
  company_id: null,
  key: 'medical_package_default',
  name: 'Medical Package (default)',
  is_system: true,
  stages: [
    stage('complete_registered', 0),
    stage('waiting_appointment', 1),
    stage('finish_1st_doctor_meeting', 2),
    stage('complete_payment', 3),
    stage('ongoing_next_meeting', 4),
  ],
}

/** One company's own journey — a สินค้ากลาง must never be offered it. */
const COMPANY_OWN = { ...DIRECT_SALE, id: 3, company_id: 2, key: 'aia_fast_track', name: 'AIA Fast Track' }

/**
 * The company's OWN copy of ขายตรง. Every company is provisioned one, and a
 * new product in that company should start on it rather than on the
 * platform's — same journey either way, but the company's own row is the one
 * their admins can rename and reason about.
 */
const COMPANY_DIRECT_SALE = { ...DIRECT_SALE, id: 4, company_id: 2 }

function productPayload(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    company_id: null,
    name: 'GENESENN Health Tracker V5 Vital Blueprint',
    price_satang: 890000,
    is_active: true,
    is_shared: true,
    effective_plan_type: 'unilevel',
    pipeline_template_id: DIRECT_SALE.id,
    effective_pipeline_template: DIRECT_SALE,
    permissions: { update: true, delete: true, restore: true, set_commission_rule: true },
    ...overrides,
  }
}

async function mountProduct(overrides: Record<string, unknown> = {}) {
  routeState.params = { id: '11' }

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin', company: null } as never

  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'AIA', slug: 'aia' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/pipeline-templates')) return { data: [DIRECT_SALE, MEDICAL, COMPANY_OWN, COMPANY_DIRECT_SALE] }
    if (path.startsWith('/products/11')) return { data: productPayload(overrides) }
    if (path.startsWith('/companies')) return { data: [{ id: 2, name: 'AIA', slug: 'aia' }] }

    return { data: [] }
  })

  const wrapper = mount(ProductEditView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        Icon: true,
        EmptyState: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
        AuthenticatedMedia: true,
        MediaUploadModal: true,
        MediaPreviewModal: true,
        PdfThumbnail: true,
        GroupCombobox: true,
        InfoPopover: true,
        ConfirmDialog: true,
        RichTextEditor: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProduct>>

/** The journey ids the selector actually offers (excluding the "inherit" row). */
function offeredJourneyIds(w: Wrapper): number[] {
  return w
    .find('[data-test="journey-select"]')
    .findAll('option')
    .map((o) => o.attributes('value'))
    .filter((v): v is string => typeof v === 'string' && v !== '')
    .map(Number)
}

/** The create form, where there is no product to load yet. */
async function mountCreateForm() {
  routeState.params = {}

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin', company: null } as never

  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'AIA', slug: 'aia' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/pipeline-templates')) return { data: [DIRECT_SALE, MEDICAL, COMPANY_OWN, COMPANY_DIRECT_SALE] }
    if (path.startsWith('/companies')) return { data: [{ id: 2, name: 'AIA', slug: 'aia' }] }

    return { data: [] }
  })

  const wrapper = mount(ProductEditView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        Icon: true,
        EmptyState: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
        AuthenticatedMedia: true,
        MediaUploadModal: true,
        MediaPreviewModal: true,
        PdfThumbnail: true,
        GroupCombobox: true,
        InfoPopover: true,
        ConfirmDialog: true,
        RichTextEditor: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('a new product starts on ขายตรง, and can be changed', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('preselects the company ขายตรง journey rather than "inherit"', async () => {
    /*
     * "ใช้ค่าจากหมวดสินค้า / บริษัท" is not a neutral default: the end of
     * that chain is the Medical Package journey, so a product created
     * without touching this field used to come out un-buyable from its own
     * share link. Every existing product was just repaired to ขายตรง;
     * creating the next one straight back into the old state would undo
     * that repair one product at a time.
     */
    const w = await mountCreateForm()
    const select = w.find('[data-test="journey-select"]').element as HTMLSelectElement

    expect(select.value).toBe(String(COMPANY_DIRECT_SALE.id))
    expect(w.find('[data-test="journey-checkout-ok"]').exists()).toBe(true)
  })

  it('lets the admin change it, including back to inherit', async () => {
    // A default, not a rule. The whole point of "ต้องแก้ไขได้".
    const w = await mountCreateForm()
    const select = w.find('[data-test="journey-select"]')

    await select.setValue(String(MEDICAL.id))
    expect(w.find('[data-test="journey-checkout-warning"]').exists()).toBe(true)

    await select.setValue('')
    expect((select.element as HTMLSelectElement).value).toBe('')
  })
})

describe('a สินค้ากลาง can be given a journey', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('offers a selector at all, and only platform journeys in it', async () => {
    /*
     * The whole regression. This selector did not exist for a shared
     * product — a grey note stood in its place — so a promoted product's
     * journey could not be set from any screen in the console.
     */
    const w = await mountProduct()

    expect(w.find('[data-test="journey-select"]').exists()).toBe(true)
    expect(offeredJourneyIds(w)).toEqual([DIRECT_SALE.id, MEDICAL.id])
    expect(offeredJourneyIds(w)).not.toContain(COMPANY_OWN.id)
  })

  it('says the link can be bought from when payment is the second step', async () => {
    const w = await mountProduct()

    expect(w.find('[data-test="journey-checkout-ok"]').exists()).toBe(true)
    expect(w.find('[data-test="journey-checkout-warning"]').exists()).toBe(false)
  })

  it('warns when the chosen journey leaves the share link with no buy button', async () => {
    /*
     * Not a validation — a journey with a doctor's visit in it is a perfectly
     * good journey. It is the consequence that was invisible from this page,
     * and was therefore discovered from a customer's screen instead.
     */
    const w = await mountProduct({
      pipeline_template_id: MEDICAL.id,
      effective_pipeline_template: MEDICAL,
    })

    const warning = w.find('[data-test="journey-checkout-warning"]')

    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('ไม่มีปุ่มชำระเงิน')
    expect(w.find('[data-test="journey-checkout-ok"]').exists()).toBe(false)
  })

  it('still offers a company product its own journeys as well as the platform ones', async () => {
    // Nothing taken away from the case that already worked.
    const w = await mountProduct({ company_id: 2, is_shared: false })

    expect(offeredJourneyIds(w)).toEqual([DIRECT_SALE.id, MEDICAL.id, COMPANY_OWN.id, COMPANY_DIRECT_SALE.id])
  })
})
