/**
 * 2026-09-09 (human: "ตอนนี้ Super admin เพิ่มสินค้าแล้วแต่ไม่มี Ui ตรงไหนแจ้งไว้
 * ว่าจะบันทึกลงเฉพาะ company หรือ ใช้เป็น product กลาง ทำแบบเดียวกันกับทางแบรนด์
 * และหมวดหมู่").
 *
 * ADR-040 gave `company_id NULL` a meaning — one row the platform owns and
 * every company sells — and every READ learned it. The WRITES never did: all
 * three create forms could only ever make a row for one company, and none of
 * them said so on screen. The only thing on the whole system that could create
 * a shared row was `catalog:promote-products`, a command run over SSH.
 *
 * So this is one question — "whose row is this?" — asked in three places, and
 * these tests pin the answer in all three, plus the three things that change
 * about a PRODUCT once the answer is "the platform's":
 *
 *   IT CANNOT WEAR ONE COMPANY'S BRAND. Every other company would see that
 *   name on a product they sell.
 *
 *   IT MUST NAME ITS COMMISSION PLAN. There is no company to inherit from, and
 *   Product::effectivePlanType() throws rather than guess — a guess lands in a
 *   ledger that cannot be corrected (BR-2/BR-4).
 *
 *   IT HAS NO PIPELINE OF ITS OWN. A template belongs to one company
 *   (ADR-026 §3.3); the journey resolves per selling company instead.
 *
 * A form that offers what its own save refuses is the bug this screen shipped
 * one day earlier ("The selected brand id is invalid."), which is why the
 * narrowing is asserted here and not left to the 422.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/*
 * ProductEditView imports MediaPreviewModal, which imports pdfjs-dist at module
 * scope — and pdfjs reaches for DOMMatrix the moment it is loaded, which jsdom
 * does not have. A component `stub` cannot help: the import runs before any
 * stub is consulted. Nothing in these tests goes near a PDF.
 */
vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

const get = vi.fn()
const post = vi.fn()
const postForm = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: (...args: unknown[]) => postForm(...args),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

const routerPush = vi.fn()
vi.mock('vue-router', () => ({
  // No :id param at all — 'product-create'. Both views read the same mock.
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: routerPush, replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductEditView from '../ProductEditView.vue'
import ProductCatalogView from '../ProductCatalogView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const AIA = { id: 2, name: 'AIA', slug: 'aia' }
const THAI_LIFE = { id: 1, name: 'Thai Life', slug: 'thai-life' }

/** The pair that legitimately survives `catalog:tidy-taxonomy`. */
const PLATFORM_BRAND = { id: 11, company_id: null, name: 'Genesenn', is_active: true }
const OWN_BRAND = { id: 12, company_id: AIA.id, name: 'AIA Only', is_active: true }
const PLATFORM_CATEGORY = { id: 21, company_id: null, name: 'Anti Aging', is_active: true, sort_order: 0, icon: null }
const OWN_CATEGORY = { id: 22, company_id: AIA.id, name: 'AIA Cat', is_active: true, sort_order: 0, icon: null }

function asSuperAdmin() {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin', company: null } as never
}

function asCompanyAdmin() {
  const auth = useAuthStore()
  auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never
}

function scopeToAia() {
  const active = useActiveCompanyStore()
  active.companies = [THAI_LIFE, AIA]
  active.selectedId = AIA.id
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  postForm.mockReset()
  routerPush.mockReset()
  post.mockResolvedValue({ data: { id: 99 } })
  postForm.mockResolvedValue({ data: { id: 99 } })
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/companies')) return { data: [THAI_LIFE, AIA] }
    if (path.startsWith('/brands')) return { data: [PLATFORM_BRAND, OWN_BRAND] }
    if (path.startsWith('/product-categories')) return { data: [PLATFORM_CATEGORY, OWN_CATEGORY] }
    if (path.startsWith('/pipeline-templates')) return { data: [] }

    return { data: [] }
  })
})

// ── The product form ─────────────────────────────────────────────────

async function mountProductForm() {
  scopeToAia()

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
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProductForm>>

/**
 * Fill in the fields the create form actually requires: name, brand, category,
 * price.
 *
 * Deliberately NOT findAll('input')[0] — the ownership radios now come first
 * in the DOM, and setValue() on a radio would quietly flip the choice back to
 * "this company's" and make every payload assertion below pass for the wrong
 * reason. (It did exactly that on the first run of this file.)
 */
async function fillBasics(w: Wrapper, brandId: number, categoryId: number) {
  const typed = w.findAll('input').filter((i) => !['radio', 'checkbox', 'file'].includes(i.attributes('type') ?? ''))
  await typed[0].setValue('แพ็กเกจทดสอบ')
  await typed[1].setValue('8900')

  const selects = w.findAll('select')
  await selects[0].setValue(String(brandId))
  await selects[1].setValue(String(categoryId))
}

const submitProduct = async (w: Wrapper) => {
  const button = w.findAll('button').find((b) => b.text().includes('บันทึกและดำเนินการต่อ'))
  if (!button) throw new Error('ไม่พบปุ่มบันทึกของฟอร์มสร้างสินค้า')
  await button.trigger('submit')
  await flushPromises()
}

describe('ProductEditView — whose product is this?', () => {
  it('offers a Super Admin the two answers, spelled out', async () => {
    asSuperAdmin()
    const w = await mountProductForm()

    expect(w.find('[data-test="owner-company"]').exists()).toBe(true)
    expect(w.find('[data-test="owner-platform"]').exists()).toBe(true)
    // The consequence, in the human's words rather than the schema's: the
    // point of ADR-040 is that nothing is copied per company.
    expect(w.text()).toContain('สินค้ากลาง (ทุกบริษัทใช้ร่วมกัน)')
    expect(w.text()).toContain('ไม่ได้ copy แยกไปแต่ละบริษัท')
  })

  it('does not ask a Company Admin a question they cannot answer', async () => {
    // They own exactly one company and were never eligible; a control that
    // 403s or silently does nothing is worse than no control.
    asCompanyAdmin()
    const w = await mountProductForm()

    expect(w.find('[data-test="owner-company"]').exists()).toBe(false)
    expect(w.find('[data-test="owner-platform"]').exists()).toBe(false)
  })

  it('sends company_id and no is_platform by default', async () => {
    // The regression guard: every product created before today took this
    // branch and nothing about it may change.
    asSuperAdmin()
    const w = await mountProductForm()
    await fillBasics(w, OWN_BRAND.id, OWN_CATEGORY.id)
    await submitProduct(w)

    expect(post).toHaveBeenCalledTimes(1)
    const body = post.mock.calls[0][1] as Record<string, unknown>
    expect(body.company_id).toBe(AIA.id)
    expect(body).not.toHaveProperty('is_platform')
  })

  it('sends is_platform INSTEAD of company_id, never alongside it', async () => {
    asSuperAdmin()
    const w = await mountProductForm()
    await w.find('[data-test="owner-platform"]').setValue()
    await flushPromises()

    await fillBasics(w, PLATFORM_BRAND.id, PLATFORM_CATEGORY.id)
    // A platform product has nothing to inherit a plan type from.
    const planSelect = w.findAll('select')[2]
    await planSelect.setValue('unilevel')
    await submitProduct(w)

    expect(post).toHaveBeenCalledTimes(1)
    const body = post.mock.calls[0][1] as Record<string, unknown>
    expect(body.is_platform).toBe(true)
    expect(body).not.toHaveProperty('company_id')
  })

  it('narrows the brand and category pickers to ของกลาง rows', async () => {
    /*
     * ValidatesProductTaxonomy refuses a company brand on a platform product
     * server-side. Offering one here would reproduce exactly the bug reported
     * yesterday: the screen names the answer, then rejects it.
     */
    asSuperAdmin()
    const w = await mountProductForm()
    await w.find('[data-test="owner-platform"]').setValue()
    await flushPromises()

    const brandValues = w.findAll('select')[0].findAll('option').map((o) => o.attributes('value'))
    expect(brandValues).toContain(String(PLATFORM_BRAND.id))
    expect(brandValues).not.toContain(String(OWN_BRAND.id))

    const categoryValues = w.findAll('select')[1].findAll('option').map((o) => o.attributes('value'))
    expect(categoryValues).toContain(String(PLATFORM_CATEGORY.id))
    expect(categoryValues).not.toContain(String(OWN_CATEGORY.id))
  })

  it('refuses to post a platform product with no commission plan', async () => {
    // Product::effectivePlanType() throws for exactly this row. Stopping it
    // here means the admin reads a sentence instead of a 422.
    asSuperAdmin()
    const w = await mountProductForm()
    await w.find('[data-test="owner-platform"]').setValue()
    await flushPromises()

    await fillBasics(w, PLATFORM_BRAND.id, PLATFORM_CATEGORY.id)
    await submitProduct(w)

    expect(post).not.toHaveBeenCalled()
    expect(w.text()).toContain('สินค้ากลางต้องเลือกรูปแบบค่าคอมมิชชั่นก่อนบันทึก')
  })

  it('drops the "inherit from the company" option that has nothing to inherit from', async () => {
    asSuperAdmin()
    const w = await mountProductForm()
    expect(w.text()).toContain('สืบทอดจากบริษัท (ค่าเริ่มต้น)')

    await w.find('[data-test="owner-platform"]').setValue()
    await flushPromises()

    expect(w.text()).not.toContain('สืบทอดจากบริษัท (ค่าเริ่มต้น)')
  })

  it('replaces the pipeline picker with the reason there is none', async () => {
    // ADR-026 §3.3 — a template belongs to one company. Leaving an empty
    // select on screen would read as "misconfigured", not "not applicable".
    asSuperAdmin()
    const w = await mountProductForm()
    await w.find('[data-test="owner-platform"]').setValue()
    await flushPromises()

    expect(w.text()).not.toContain('เส้นทางการขายของสินค้านี้ (Pipeline)')
    expect(w.text()).toContain('สินค้ากลางใช้เส้นทางการขายของบริษัทที่ขายสินค้านั้น')
  })
})

// ── The brand and category forms ─────────────────────────────────────

async function mountRefDrawer(tab: 'brands' | 'categories') {
  scopeToAia()

  const wrapper = mount(ProductCatalogView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        // Left as a real-ish stub so its presence/absence is assertable.
        CompanyMultiSelect: { name: 'CompanyMultiSelect', template: '<div data-test="company-ticks" />' },
        ConfirmDialog: true,
        PlatformScopeBadge: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  const open = wrapper.findAll('button').find((b) => b.text().includes('จัดการแบรนด์'))
  if (!open) throw new Error('ไม่พบปุ่มเปิดลิ้นชักจัดการแบรนด์/หมวดหมู่')
  await open.trigger('click')
  await flushPromises()

  if (tab === 'categories') {
    const tabButton = wrapper.findAll('button').find((b) => b.text().includes('หมวดหมู่') && b.text().length < 30)
    if (!tabButton) throw new Error('ไม่พบแท็บหมวดหมู่')
    await tabButton.trigger('click')
    await flushPromises()
  }

  const create = wrapper.findAll('button').find((b) => b.text().includes(tab === 'brands' ? '+ เพิ่มแบรนด์' : '+ เพิ่มหมวดหมู่'))
  if (!create) throw new Error('ไม่พบปุ่มเพิ่ม')
  await create.trigger('click')
  await flushPromises()

  return wrapper
}

async function submitRefForm(w: Awaited<ReturnType<typeof mountRefDrawer>>, name: string) {
  const form = w.find('form')
  await form.find('input').setValue(name)
  await form.trigger('submit')
  await flushPromises()
}

describe('ProductCatalogView — whose brand is this?', () => {
  it('asks before the company tick boxes, because ของกลาง is not one more company', async () => {
    asSuperAdmin()
    const w = await mountRefDrawer('brands')

    expect(w.find('[data-test="brand-owner-company"]').exists()).toBe(true)
    expect(w.find('[data-test="brand-owner-platform"]').exists()).toBe(true)
    expect(w.find('[data-test="company-ticks"]').exists()).toBe(true)
  })

  it('hides the tick boxes once the brand belongs to nobody in particular', async () => {
    /*
     * Ticking companies AND ของกลาง would mean "shared with everyone and owned
     * by these two", which is not a state the schema has.
     */
    asSuperAdmin()
    const w = await mountRefDrawer('brands')
    await w.find('[data-test="brand-owner-platform"]').setValue()
    await flushPromises()

    expect(w.find('[data-test="company-ticks"]').exists()).toBe(false)
  })

  it('posts ONE brand with is_platform and no company_id', async () => {
    asSuperAdmin()
    const w = await mountRefDrawer('brands')
    await w.find('[data-test="brand-owner-platform"]').setValue()
    await flushPromises()
    await submitRefForm(w, 'De La Lita')

    expect(postForm).toHaveBeenCalledTimes(1)
    const fd = postForm.mock.calls[0][1] as FormData
    expect(fd.get('is_platform')).toBe('1')
    expect(fd.get('company_id')).toBeNull()
  })

  it('still fans out one row per ticked company by default', async () => {
    // TASK-203's behaviour, unchanged: the scoped company is pre-ticked, and
    // one submit is still one POST per company.
    asSuperAdmin()
    const w = await mountRefDrawer('brands')
    await submitRefForm(w, 'AIA Only')

    expect(postForm).toHaveBeenCalledTimes(1)
    const fd = postForm.mock.calls[0][1] as FormData
    expect(fd.get('company_id')).toBe(String(AIA.id))
    expect(fd.get('is_platform')).toBeNull()
  })

  it('never shows the choice to a Company Admin', async () => {
    asCompanyAdmin()
    const w = await mountRefDrawer('brands')

    expect(w.find('[data-test="brand-owner-company"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-owner-platform"]').exists()).toBe(false)
  })
})

describe('ProductCatalogView — whose category is this?', () => {
  it('asks the same question the same way as the brands tab', async () => {
    // Two adjacent tabs behaving differently would be its own bug.
    asSuperAdmin()
    const w = await mountRefDrawer('categories')

    expect(w.find('[data-test="category-owner-company"]').exists()).toBe(true)
    expect(w.find('[data-test="category-owner-platform"]').exists()).toBe(true)
  })

  it('posts ONE category with is_platform and no company_id', async () => {
    asSuperAdmin()
    const w = await mountRefDrawer('categories')
    await w.find('[data-test="category-owner-platform"]').setValue()
    await flushPromises()
    await submitRefForm(w, 'Life Style')

    expect(post).toHaveBeenCalledTimes(1)
    const body = post.mock.calls[0][1] as Record<string, unknown>
    expect(body.is_platform).toBe(true)
    expect(body).not.toHaveProperty('company_id')
  })

  it('still creates a company category by default', async () => {
    asSuperAdmin()
    const w = await mountRefDrawer('categories')
    await submitRefForm(w, 'AIA Cat')

    expect(post).toHaveBeenCalledTimes(1)
    const body = post.mock.calls[0][1] as Record<string, unknown>
    expect(body.company_id).toBe(AIA.id)
    expect(body).not.toHaveProperty('is_platform')
  })
})
