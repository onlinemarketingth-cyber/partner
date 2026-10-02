/**
 * ADR-052 — CatalogManagementView: "saved" is said once, by the dialog, and
 * only after the server answered and the list was re-read from it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
    postForm: vi.fn(),
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
}))

import CatalogManagementView from '../CatalogManagementView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const BRAND = { id: 1, name: 'Genesenn', is_active: true }
const CATEGORY = { id: 2, name: 'Anti Aging', is_active: true, sort_order: 0, icon: null }
const ITEM = {
  id: 10,
  catalog_brand_id: 1,
  catalog_category_id: 2,
  catalog_brand: BRAND,
  catalog_category: CATEGORY,
  name: 'Vital Blueprint V5',
  description: null,
  spec_description: null,
  default_price_satang: 890000,
  is_active: true,
  media: [],
  specs: [],
  linked_product_count: 0,
  created_at: '2026-09-05T03:00:00Z',
  updated_at: '2026-09-05T03:00:00Z',
}

let categories = [CATEGORY]
let brands = [BRAND]

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => (resolve = r))

  return { promise, resolve }
}

async function mountView() {
  const auth = useAuthStore()
  auth.user = { id: 1, role: 'super_admin' } as never

  const wrapper = mount(CatalogManagementView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        PlatformScopeBadge: true,
        RichTextEditor: true,
        RouterLink: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const button = (w: Wrapper, text: string) => w.findAll('button').find((b) => b.text().trim().includes(text))!

async function openTab(w: Wrapper, label: string) {
  await button(w, label).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  categories = [CATEGORY]
  brands = [BRAND]
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/catalog-brands')) return { data: brands }
    if (path.startsWith('/catalog-categories')) return { data: categories }

    return { data: [ITEM] }
  })
})

describe('CatalogManagementView — ADR-052 save feedback', () => {
  it('editing a category raises the dialog only after the server answered, and shows the SERVER name', async () => {
    const w = await mountView()
    await openTab(w, 'หมวดหมู่')
    await w.find('button[title="แก้ไข"]').trigger('click')
    await w.find('input:not([type="number"]):not([type="checkbox"])').setValue('Typed name')

    const pending = deferred<unknown>()
    put.mockReturnValueOnce(pending.promise)
    await button(w, 'บันทึก').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server normalised the name — that is what must be on screen.
    categories = [{ ...CATEGORY, name: 'Server name' }]
    pending.resolve({ data: { ...CATEGORY, name: 'Server name' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('Server name')
    expect(w.text()).toContain('Server name')
    expect(w.text()).not.toContain('Typed name')
  })

  it('a failing new-brand save shows the error and no dialog (submitBrand had no catch before)', async () => {
    const w = await mountView()
    await openTab(w, 'แบรนด์')
    await button(w, '+ เพิ่มแบรนด์').trigger('click')
    await w.find('form input').setValue('Dup')
    post.mockRejectedValueOnce(new (ApiError as unknown as new (s: number, m: string) => Error)(422, 'ชื่อซ้ำ'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.find('[data-test="brand-form-error"]').text()).toContain('ชื่อซ้ำ')
    // The form stays open with what was typed, so it can be fixed.
    expect(w.find('form').exists()).toBe(true)
  })

  it('a new brand reports the name the server stored', async () => {
    const w = await mountView()
    await openTab(w, 'แบรนด์')
    await button(w, '+ เพิ่มแบรนด์').trigger('click')
    await w.find('form input').setValue('  acme ')
    post.mockImplementationOnce(async () => {
      brands = [BRAND, { ...BRAND, id: 3, name: 'ACME' }]

      return { data: { ...BRAND, id: 3, name: 'ACME' } }
    })

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มแบรนด์ "ACME" แล้ว')
    expect(w.text()).toContain('ACME')
  })

  it('deleting a catalog item asks first, then raises the dialog once the server confirmed', async () => {
    del.mockResolvedValue(null)
    const w = await mountView()

    await w.find('button[title="ลบ"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(w.text()).toContain('ลบรายการ "Vital Blueprint V5" ออกจากแคตตาล็อกกลาง?')

    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/product-catalog-items/10')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ลบรายการ "Vital Blueprint V5"')
    expect(w.find('#catalog-item-10').exists()).toBe(false)
  })
})
