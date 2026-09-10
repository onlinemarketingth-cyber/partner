/**
 * 2026-09-09 (human: "อันไหนสิทธิ์ company admin ทำไม่ได้ต้องซ่อน ไม่ใช่ให้
 * error 403 หรืออื่นๆ").
 *
 * A Company Admin opening a สินค้ากลาง used to get a fully editable form.
 * They could type a new name, press บันทึก, and be answered 403 — because
 * every company shares that one row, so ProductPolicy makes it
 * Super-Admin-only. The screen and the server disagreed, and the person
 * found out only after doing the work.
 *
 * ── WHAT THIS PINS ──
 *
 * That the page asks the SERVER whether this user may edit this row
 * (`permissions.update`, TASK-245) rather than re-deriving the rule, and
 * that "may not" means the controls are gone — not greyed, not present and
 * refused later.
 *
 * ── AND THE TWO THINGS THEY KEEP ──
 *
 * Read-only is not "you have lost this product". Commission rates and the
 * recommendation pin are that company's OWN decisions about a shared
 * product (ADR-040), and both must survive the lock. Those two carve-outs
 * are the part most likely to be broken by a later tidy-up, so they get
 * their own tests.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

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
  useRoute: () => ({ params: { id: '11' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductEditView from '../ProductEditView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

/**
 * What the server sends a COMPANY ADMIN for a สินค้ากลาง: the product is
 * readable, the row is not editable, and their own commission rates are.
 */
const COMPANY_ADMIN_ON_SHARED = {
  update: false,
  delete: false,
  restore: false,
  set_commission_rule: true,
}

const SUPER_ADMIN_ON_SHARED = {
  update: true,
  delete: true,
  restore: true,
  set_commission_rule: true,
}

async function mountAs(
  role: 'company_admin' | 'super_admin',
  permissions: Record<string, boolean>,
) {
  const auth = useAuthStore()
  auth.user = {
    id: 1,
    name: 'ผู้ดูแล',
    role,
    company: role === 'company_admin' ? { id: 2, name: 'Thai Life' } : null,
    company_id: role === 'company_admin' ? 2 : null,
  } as never

  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'Thai Life', slug: 'thai-life' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/products/11')) {
      return {
        data: {
          id: 11,
          company_id: null,
          name: 'GENESENN Health Tracker V8 Vital Blueprint',
          price_satang: 990000,
          is_active: true,
          is_shared: true,
          effective_plan_type: 'unilevel',
          catalog_item_id: null,
          pipeline_template_id: null,
          effective_pipeline_template: null,
          permissions,
        },
      }
    }
    if (path.startsWith('/companies')) return { data: [{ id: 2, name: 'Thai Life', slug: 'thai-life' }] }

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

type Wrapper = Awaited<ReturnType<typeof mountAs>>

/** Is the journey selector inert because an ancestor fieldset is disabled? */
function insideDisabledFieldset(w: Wrapper, selector: string): boolean {
  let node: HTMLElement | null = w.find(selector).element as HTMLElement

  while (node) {
    if (node.tagName === 'FIELDSET' && node.hasAttribute('disabled')) return true
    node = node.parentElement
  }

  return false
}

describe('a Company Admin on a สินค้ากลาง', () => {
  beforeEach(() => {
    get.mockReset()
    put.mockReset()
    put.mockResolvedValue({ data: {} })
  })

  it('is told why, at the top of the page', async () => {
    /*
     * Before the fields, not after a failed save. The notice also names
     * what IS still theirs — "read-only" on its own reads as "you have lost
     * this product", which is not what happened.
     */
    const w = await mountAs('company_admin', COMPANY_ADMIN_ON_SHARED)
    const notice = w.find('[data-test="shared-read-only-notice"]')

    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('แก้ไขได้เฉพาะ Super Admin')
    expect(notice.text()).toContain('ราคาของบริษัท')
    expect(notice.text()).toContain('อัตราคอมมิชชั่น')
  })

  it('cannot type into the product fields', async () => {
    const w = await mountAs('company_admin', COMPANY_ADMIN_ON_SHARED)

    expect(insideDisabledFieldset(w, '[data-test="journey-select"]')).toBe(true)
  })

  it('keeps the recommendation pin, because that is their own storefront', async () => {
    /*
     * The carve-out most likely to be lost in a later tidy-up: the pin sits
     * in the same tab as the locked fields, but it is stored per company and
     * the server allows it. Losing it here would take away something a
     * Company Admin has always had.
     */
    const w = await mountAs('company_admin', COMPANY_ADMIN_ON_SHARED)
    const pinToggle = w.findAll('button').find((b) => b.element.className.includes('w-14'))

    expect(pinToggle).toBeDefined()
    expect(insideDisabledFieldset(w, '[data-test="save-basics"]')).toBe(false)
    expect(w.find('[data-test="save-basics"]').exists()).toBe(true)
  })

  it('never sends the product row to the server', async () => {
    /*
     * The 403 itself, prevented at the source. The one บันทึก button saves
     * the pin only — a PUT /products/11 here would come back 403 and put an
     * error on screen for a change the person never made.
     */
    const w = await mountAs('company_admin', COMPANY_ADMIN_ON_SHARED)

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(put.mock.calls.every(([path]) => path !== '/products/11')).toBe(true)
  })
})

describe('a Super Admin on the same product', () => {
  beforeEach(() => {
    get.mockReset()
    put.mockReset()
    put.mockResolvedValue({ data: {} })
  })

  it('edits it normally', async () => {
    // The lock is about WHO, not about the product being shared — nothing
    // here may narrow what a Super Admin can do.
    const w = await mountAs('super_admin', SUPER_ADMIN_ON_SHARED)

    expect(w.find('[data-test="shared-read-only-notice"]').exists()).toBe(false)
    expect(insideDisabledFieldset(w, '[data-test="journey-select"]')).toBe(false)
  })
})
