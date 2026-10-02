/**
 * ADR-052 — ProductCatalogView: every write ends in ONE "saved" dialog, raised
 * only after the server answered 2xx and the rows on screen were re-read.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()
const postForm = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
    postForm: (...args: unknown[]) => postForm(...args),
    download: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      message: string,
    ) {
      super(message)
    }
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductCatalogView from '../ProductCatalogView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const THAI_LIFE = { id: 1, name: 'Thai Life', slug: 'thai-life' }
const AIA = { id: 2, name: 'AIA', slug: 'aia' }
const BRAND = { id: 11, company_id: null, name: 'Genesenn', is_active: true }
const CATEGORY = { id: 21, company_id: null, name: 'Anti Aging', is_active: true, sort_order: 0, icon: null }

function sharedProduct(overrides: Record<string, unknown> = {}) {
  return {
    id: 100,
    is_shared: true,
    company_id: null,
    name: 'Vital Blueprint V5',
    price_satang: 890000,
    effective_price_satang: 890000,
    own_price_satang: null,
    is_sellable_here: true,
    is_active: true,
    brand: BRAND,
    category: CATEGORY,
    commission_rate_type: null,
    permissions: { update: true, delete: true, set_commission_rule: true },
    ...overrides,
  }
}

function ownProduct(overrides: Record<string, unknown> = {}) {
  return {
    ...sharedProduct(),
    id: 200,
    is_shared: false,
    company_id: AIA.id,
    name: 'AIA Only',
    ...overrides,
  }
}

let products: unknown[] = []
let pins: unknown[] = []
let brands: unknown[] = [BRAND]

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => (resolve = r))

  return { promise, resolve }
}

async function mountView(rows: unknown[]) {
  products = rows
  get.mockImplementation(async (path: string) => {
    if (path.includes('/deletion-impact')) return { data: { is_shared: false, blockers: {}, selling_companies: [] } }
    if (path.startsWith('/companies')) return { data: [THAI_LIFE, AIA] }
    if (path.startsWith('/brands')) return { data: brands }
    if (path.startsWith('/product-categories')) return { data: [CATEGORY] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/product-recommendation-pins')) return { data: pins }
    if (path.startsWith('/catalog-trash')) return { data: { products: [], brands: [], categories: [] } }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [THAI_LIFE, AIA]
  active.selectedId = AIA.id

  const wrapper = mount(ProductCatalogView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        PlatformScopeBadge: true,
        AuthenticatedMedia: true,
        CompanyMultiSelect: { name: 'CompanyMultiSelect', props: ['modelValue', 'options', 'label', 'placeholder'], template: '<div />' },
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function clickByText(wrapper: Wrapper, text: string) {
  const button = wrapper.findAll('button').find((b) => b.text().trim() === text)
  if (!button) throw new Error(`no button "${text}"`)

  return button.trigger('click')
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  postForm.mockReset()
  pins = []
  brands = [BRAND]
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('ProductCatalogView — ADR-052 save feedback', () => {
  it('a company price save raises the dialog only after the server answered, quoting the price it STORED', async () => {
    const w = await mountView([sharedProduct()])
    await clickByText(w, 'ตั้งราคา')
    await w.find('input[type="checkbox"]').setValue(false)
    await w.find('input[type="number"]').setValue('7900')

    const pending = deferred<unknown>()
    put.mockReturnValueOnce(pending.promise)
    await clickByText(w, 'บันทึก')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server answers with a different effective price than was typed.
    products = [sharedProduct({ own_price_satang: 750000, effective_price_satang: 750000 })]
    pending.resolve({ data: {} })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain((7500).toLocaleString('th-TH'))
    expect(saveFeedbackState.body).not.toContain((7900).toLocaleString('th-TH'))
    expect(w.text()).toContain((7500).toLocaleString('th-TH'))
  })

  it('a failing sell-here toggle raises no dialog, shows the error, and the switch stays at the stored state', async () => {
    const w = await mountView([sharedProduct({ is_sellable_here: false })])
    put.mockRejectedValueOnce(new (ApiError as unknown as new (s: number, m: string) => Error)(500, 'boom'))

    await w.find('[data-test="sell-here-switch"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('บันทึกไม่สำเร็จ (500)')
    expect(w.find('[data-test="product-row-closed"]').exists()).toBe(true)
  })

  it('a successful sell-here toggle reports the state re-read from the server', async () => {
    const w = await mountView([sharedProduct({ is_sellable_here: false })])
    put.mockImplementationOnce(async () => {
      products = [sharedProduct({ is_sellable_here: true })]

      return { data: {} }
    })

    await w.find('[data-test="sell-here-switch"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เปิดขาย "Vital Blueprint V5" ใน AIA แล้ว')
    expect(w.find('[data-test="product-row-open"]').exists()).toBe(true)
  })

  it('deleting a company package asks first, then raises the dialog once the server confirmed', async () => {
    del.mockResolvedValue(null)
    const w = await mountView([ownProduct()])

    await w.find('[data-test="delete-company-product"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(w.text()).toContain('ซ่อนแพ็กเกจ "AIA Only"')

    await clickByText(w, 'ยืนยัน')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/products/200')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ซ่อนแพ็กเกจ "AIA Only" แล้ว')
    expect(w.text()).not.toContain('AIA Only')
  })

  it('a failing delete raises no dialog and shows the server refusal', async () => {
    del.mockRejectedValue(new (ApiError as unknown as new (s: number, m: string) => Error)(422, 'ลบไม่ได้ เพราะมีการขายผูกอยู่'))
    const w = await mountView([ownProduct()])

    await w.find('[data-test="delete-company-product"]').trigger('click')
    await flushPromises()
    await clickByText(w, 'ยืนยัน')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ลบไม่ได้ เพราะมีการขายผูกอยู่')
    expect(w.text()).toContain('AIA Only')
  })

  it('pinning a package raises the dialog after the pins were re-read', async () => {
    const w = await mountView([ownProduct()])
    post.mockImplementationOnce(async () => {
      const row = { id: 5, product_id: 200, company_id: AIA.id, sort_order: 0, is_active: true }
      pins = [row]

      return { data: row }
    })

    await w.find('[data-test="toggle-pin"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ปักหมุดแนะนำ "AIA Only" แล้ว')
  })
})

describe('ProductCatalogView — unticking a company on edit is a delete, and asks first', () => {
  const PERMS = { update: true, delete: true, restore: true }
  const THAI_ROW = { id: 31, company_id: THAI_LIFE.id, name: 'Genesenn', is_active: true, logo_url: null, products_count: 0, permissions: PERMS }
  const AIA_ROW = { id: 32, company_id: AIA.id, name: 'Genesenn', is_active: true, logo_url: null, products_count: 0, permissions: PERMS }

  async function openBrandEdit() {
    brands = [THAI_ROW, AIA_ROW]
    const w = await mountView([])
    await w.findAll('button').find((b) => b.text().includes('จัดการแบรนด์'))!.trigger('click')
    await flushPromises()
    await w.find('[title="แก้ไข"]').trigger('click')
    await flushPromises()
    // Untick AIA, keep Thai Life.
    const picker = w.findAllComponents({ name: 'CompanyMultiSelect' }).find((c) => (c.props('modelValue') as number[])?.length === 2)!
    picker.vm.$emit('update:modelValue', [THAI_LIFE.id])
    await flushPromises()

    return w
  }

  it('names the companies it will remove from, and cancel sends nothing', async () => {
    const w = await openBrandEdit()
    await clickByText(w, 'บันทึก')
    await flushPromises()

    expect(w.text()).toContain('บันทึกครั้งนี้จะเอาแบรนด์ "Genesenn" ออกจาก 1 บริษัท')
    expect(w.text()).toContain('• AIA')
    expect(postForm).not.toHaveBeenCalled()
    expect(del).not.toHaveBeenCalled()

    await clickByText(w, 'ยกเลิก')
    await flushPromises()
    expect(postForm).not.toHaveBeenCalled()
    expect(del).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
  })

  it('confirming sends the save, deletes only the unticked company, then raises the dialog', async () => {
    postForm.mockResolvedValue({ data: { ...THAI_ROW } })
    del.mockResolvedValue(null)
    const w = await openBrandEdit()
    await clickByText(w, 'บันทึก')
    await flushPromises()
    brands = [THAI_ROW]

    await clickByText(w, 'บันทึกและเอาออก')
    await flushPromises()

    expect(postForm).toHaveBeenCalledWith('/brands/31', expect.any(FormData))
    expect(del).toHaveBeenCalledTimes(1)
    expect(del).toHaveBeenCalledWith('/brands/32')
    expect(saveFeedbackState.body).toBe('บันทึกแบรนด์ "Genesenn" แล้ว')
  })
})
