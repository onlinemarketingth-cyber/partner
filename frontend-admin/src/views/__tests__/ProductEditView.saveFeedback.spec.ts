/**
 * ADR-052 — ProductEditView: one "saved" dialog per action, raised only after
 * the server answered and the section on screen was re-read from it; never on
 * a failure; deletes ask first; chained buttons stop at the first failure.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const patch = vi.fn()
const del = vi.fn()
const postFile = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: (...args: unknown[]) => patch(...args),
    delete: (...args: unknown[]) => del(...args),
    postForm: vi.fn(),
    postFileWithProgress: (...args: unknown[]) => postFile(...args),
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

// Mutable, so a test can mount the create page and then the page it routes to.
const routeState = vi.hoisted(() => ({ params: { id: '11' } as Record<string, string> }))
const push = vi.hoisted(() => vi.fn())

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: routeState.params, query: {} }),
  useRouter: () => ({ push, replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductEditView from '../ProductEditView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

const Err = ApiError as unknown as new (status: number, message: string) => Error

const BRAND = { id: 1, company_id: 2, name: 'Genesenn' }
const CATEGORY = { id: 2, company_id: 2, name: 'Anti Aging' }

function productRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    company_id: 2,
    name: 'GENESENN Vital Blueprint',
    price_satang: 100000,
    cost_satang: null,
    pv_satang: null,
    is_active: true,
    is_shared: false,
    catalog_item_id: null,
    description: null,
    spec_description: null,
    brand: BRAND,
    category: CATEGORY,
    commission_plan_type: null,
    effective_plan_type: 'unilevel',
    affiliate_override_mode: null,
    effective_affiliate_override_mode: 'additive',
    commission_rate_type: null,
    pipeline_template_id: null,
    voucher_usage_quota: null,
    voucher_validity_days: null,
    requires_shipping: false,
    permissions: { update: true, delete: true, restore: true, set_commission_rule: true },
    ...overrides,
  }
}

let product = productRow()
let specs: unknown[] = []
let materials: unknown[] = []
let pins: unknown[] = []
let rules: unknown[] = []
/** Paths whose next GET should fail — a re-read after a write that does not come back. */
let failingGets: string[] = []
/** Paths whose GET is held until the test resolves it. */
let heldGets: Record<string, Promise<unknown>> = {}

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => (resolve = r))

  return { promise, resolve }
}

async function mountProduct() {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin', company: null } as never
  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'AIA', slug: 'aia' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (failingGets.includes(path)) throw new Err(500, 'อ่านข้อมูลไม่สำเร็จ')
    if (heldGets[path]) return heldGets[path]
    const id = product.id
    if (path.startsWith(`/products/${id}/specs`)) return { data: specs }
    if (path.startsWith(`/products/${id}/sales-materials`)) return { data: materials }
    if (path.startsWith(`/products/${id}/`)) return { data: [] }
    if (path.startsWith(`/products/${id}`)) return { data: product }
    if (path.startsWith('/brands')) return { data: [BRAND] }
    if (path.startsWith('/product-categories')) return { data: [CATEGORY] }
    if (path.startsWith('/product-recommendation-pins')) return { data: pins }
    if (path.startsWith('/commission-rules')) return { data: rules }
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
        GroupCombobox: { name: 'GroupCombobox', props: ['modelValue', 'options', 'placeholder'], template: '<div class="group-combobox" />' },
        InfoPopover: true,
        RichTextEditor: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProduct>>

async function openTab(w: Wrapper, label: string) {
  const tab = w.findAll('button').find((b) => b.text().trim() === label)
  if (!tab) throw new Error(`no tab ${label}`)
  await tab.trigger('click')
  await flushPromises()
}

const button = (w: Wrapper, text: string) => {
  const b = w.findAll('button').find((x) => x.text().trim() === text)
  if (!b) throw new Error(`no button "${text}"`)

  return b
}

beforeEach(() => {
  product = productRow()
  specs = []
  materials = []
  pins = []
  rules = []
  failingGets = []
  heldGets = {}
  routeState.params = { id: '11' }
  for (const fn of [get, post, put, patch, del, postFile, push]) fn.mockReset()
})

describe('ProductEditView — basics (ข้อมูลสินค้า)', () => {
  it('raises the dialog only after the server answered, and the form shows the SERVER name', async () => {
    const w = await mountProduct()
    await w.find('input[required]').setValue('  typed name ')

    const pending = deferred<unknown>()
    put.mockReturnValueOnce(pending.promise)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    pending.resolve({ data: productRow({ name: 'Typed Name' }) })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('บันทึกข้อมูลสินค้า "Typed Name" แล้ว')
    expect((w.find('input[required]').element as HTMLInputElement).value).toBe('Typed Name')
  })

  it('a failing basics save shows the error, raises no dialog, and does NOT go on to save the pin', async () => {
    const w = await mountProduct()
    put.mockRejectedValueOnce(new Err(422, 'ชื่อสินค้าซ้ำ'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ชื่อสินค้าซ้ำ')
    // The chain stopped: one PUT (the product), no pin write.
    expect(put).toHaveBeenCalledTimes(1)
    expect(post).not.toHaveBeenCalled()
  })

  it('basics + pin: ONE dialog for the button, and the pin form re-reads what the server stored', async () => {
    const w = await mountProduct()
    put.mockResolvedValueOnce({ data: productRow() })
    // Turn the pin on and ask for position 5; the server places it at 3.
    const pinSwitch = w.findAll('button[type="button"]').find((b) => b.element.closest('div')?.textContent?.includes('ปักหมุดแนะนำ'))!
    await pinSwitch.trigger('click')
    await w.find('input[type="number"][min="0"].w-20').setValue('5')
    post.mockResolvedValueOnce({ data: { id: 9, product_id: 11, sort_order: 3, is_active: true } })

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('บันทึกข้อมูลสินค้า "GENESENN Vital Blueprint" และการปักหมุดแนะนำแล้ว')
    expect((w.find('input[type="number"][min="0"].w-20').element as HTMLInputElement).value).toBe('3')
  })

  it('the header status reads the SAVED product, and an unsaved flip is labelled as such', async () => {
    const w = await mountProduct()
    expect(w.find('[data-test="saved-active-status"]').text()).toBe('เปิดใช้งาน')

    // The switch right after the status label.
    const sw = w.find('[data-test="saved-active-status"]').element.parentElement!.querySelector('button')!
    sw.click()
    await flushPromises()

    expect(w.find('[data-test="saved-active-status"]').text()).toBe('เปิดใช้งาน')
    expect(w.find('[data-test="active-unsaved"]').text()).toContain('ยังไม่บันทึก')
  })
})

describe('ProductEditView — specs', () => {
  it('deleting a spec asks first, then raises the dialog once the server confirmed', async () => {
    specs = [{ id: 5, spec_group: null, spec_key: 'น้ำหนัก', spec_value: '500 กรัม', sort_order: 0 }]
    del.mockResolvedValue(null)
    const w = await mountProduct()
    await openTab(w, 'สเปคสินค้า')

    await w.find('button[title="ลบ"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(w.text()).toContain('ลบสเปค "น้ำหนัก: 500 กรัม" ออกจากสินค้านี้')

    await button(w, 'ลบสเปค').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/product-specs/5')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบสเปค "น้ำหนัก" แล้ว')
    expect(w.text()).not.toContain('500 กรัม')
  })

  it('a failing spec delete shows the error and raises no dialog', async () => {
    specs = [{ id: 5, spec_group: null, spec_key: 'น้ำหนัก', spec_value: '500 กรัม', sort_order: 0 }]
    del.mockRejectedValue(new Err(500, ''))
    const w = await mountProduct()
    await openTab(w, 'สเปคสินค้า')

    await w.find('button[title="ลบ"]').trigger('click')
    await button(w, 'ลบสเปค').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ลบสเปคไม่สำเร็จ (500)')
    expect(w.text()).toContain('500 กรัม')
  })
})

describe('ProductEditView — sales material group (was LOCAL)', () => {
  const MATERIAL = {
    id: 21,
    material_group: null,
    original_filename: 'brochure.pdf',
    mime_type: 'application/pdf',
    source_type: 'upload',
    stream_url: '/m/21',
    embed_url: null,
    thumbnail_url: null,
  }

  it('shows the group the SERVER stored, not the one that was sent', async () => {
    materials = [MATERIAL]
    const w = await mountProduct()
    await openTab(w, 'สื่อการขาย')
    const pickersBefore = w.findAllComponents({ name: 'GroupCombobox' }).length
    await w.find('button[title="ย้ายกลุ่ม"]').trigger('click')
    // The move picker opened (the page has another combobox for new groups).
    expect(w.findAllComponents({ name: 'GroupCombobox' })).toHaveLength(pickersBefore + 1)
    patch.mockResolvedValueOnce({ data: { ...MATERIAL, material_group: 'บทเรียนที่ 1' } })

    w.findComponent({ name: 'GroupCombobox' }).vm.$emit('update:modelValue', 'บทเรียนที่ 1 ')
    await flushPromises()

    expect(patch).toHaveBeenCalledWith('/sales-materials/21', { material_group: 'บทเรียนที่ 1 ' })
    expect(saveFeedbackState.body).toBe('ย้ายไปกลุ่ม "บทเรียนที่ 1" แล้ว')
    expect(w.text()).toContain('บทเรียนที่ 1')
    expect(w.findAllComponents({ name: 'GroupCombobox' })).toHaveLength(pickersBefore)
  })

  it('keeps the picker open with the error when the move fails', async () => {
    materials = [MATERIAL]
    const w = await mountProduct()
    await openTab(w, 'สื่อการขาย')
    const pickersBefore = w.findAllComponents({ name: 'GroupCombobox' }).length
    await w.find('button[title="ย้ายกลุ่ม"]').trigger('click')
    patch.mockRejectedValueOnce(new Err(422, 'ชื่อกลุ่มยาวเกินไป'))

    w.findComponent({ name: 'GroupCombobox' }).vm.$emit('update:modelValue', 'x')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ชื่อกลุ่มยาวเกินไป')
    // Still open, so the move can be retried.
    expect(w.findAllComponents({ name: 'GroupCombobox' })).toHaveLength(pickersBefore + 1)
  })
})

describe('ProductEditView — commission tab', () => {
  it('a failing basics save stops the chain: no rule is posted and no dialog', async () => {
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    await w.find('input[step="0.01"]').setValue('10')
    put.mockRejectedValueOnce(new Err(500, ''))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
  })

  it("the first rule's format is RE-READ from the server, not patched in from what was sent", async () => {
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    await w.find('input[step="0.01"]').setValue('10')
    put.mockResolvedValueOnce({ data: productRow() })
    post.mockImplementationOnce(async () => {
      // The server locked the product's format as a side effect (TASK-197 §2.2).
      product = productRow({ commission_rate_type: 'fixed_satang' })
      rules = [{ id: 7, product: { id: 11 }, rate_type: 'percentage', rate_value: 1000, effective_from: '2026-10-01', effective_to: null, renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false }]

      return { data: rules[0] }
    })

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/products/11')
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าค่าแนะนำและเพิ่มอัตรา 10.00% แล้ว')
    // "ค่าที่ใช้จริงตอนนี้" reads the stored product.
    expect(w.text()).toContain('ค่าที่ใช้จริงตอนนี้')
    expect((w.find('select').element as HTMLSelectElement).value).toBe('fixed_satang')
  })

  it('editing ONE rule row sends the stored format, not an unsaved pick in the settings dropdown', async () => {
    product = productRow({ commission_rate_type: 'percentage' })
    rules = [{ id: 7, product: { id: 11 }, rate_type: 'percentage', rate_value: 1000, effective_from: '2026-10-01', effective_to: null, renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false }]
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    // Unsaved: the dropdown says fixed amount, the product still says percent.
    await w.find('select').setValue('fixed_satang')
    await w.find('button[title="แก้ไข"]').trigger('click')
    expect(w.text()).toContain('จะบันทึกเป็น')
    put.mockImplementationOnce(async () => {
      rules = [{ ...(rules[0] as object), rate_value: 1250 }]

      return { data: rules[0] }
    })

    // The row's own บันทึก (the tab's form has another one above it).
    const saves = w.findAll('button').filter((x) => x.text().trim() === 'บันทึก')
    const rowSave = saves[saves.length - 1]!
    await rowSave.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-rules/7', expect.objectContaining({ rate_type: 'percentage' }))
    // The dialog quotes the rate as re-read from the server.
    expect(saveFeedbackState.body).toBe('บันทึกอัตราค่าแนะนำ 12.50% แล้ว')
  })

  it('deleting a rule asks first, then raises the dialog', async () => {
    rules = [{ id: 7, product: { id: 11 }, rate_type: 'percentage', rate_value: 1000, effective_from: '2026-10-01', effective_to: null, renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false }]
    del.mockResolvedValue(null)
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')

    await w.find('button[title="ลบ"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    await button(w, 'ลบอัตรา').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/commission-rules/7')
    expect(saveFeedbackState.body).toBe('ลบอัตราค่าแนะนำ 10.00% แล้ว')
  })
})

describe('ProductEditView — cover photos (a batch is ONE action)', () => {
  function pick(w: Wrapper, names: string[]) {
    const input = w.find('input[type="file"][multiple][accept="image/*"]')
    const files = names.map((n) => new File(['x'], n, { type: 'image/png' }))
    Object.defineProperty(input.element, 'files', { value: files, configurable: true })

    return input.trigger('change')
  }

  it('raises ONE dialog for the whole pick, after the gallery re-read', async () => {
    postFile.mockImplementation(() => ({ promise: Promise.resolve({ data: {} }), abort: () => {} }))
    const w = await mountProduct()
    await openTab(w, 'รูปภาพและสื่อ')

    await pick(w, ['a.png', 'b.png'])
    await flushPromises()

    expect(postFile).toHaveBeenCalledTimes(2)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('อัปโหลดรูปสินค้า 2 รูปแล้ว')
  })

  it('a part-way failure raises no dialog and keeps the error', async () => {
    postFile
      .mockImplementationOnce(() => ({ promise: Promise.resolve({ data: {} }), abort: () => {} }))
      .mockImplementationOnce(() => ({ promise: Promise.reject(new Err(413, 'ไฟล์ใหญ่เกินไป')), abort: () => {} }))
    const w = await mountProduct()
    await openTab(w, 'รูปภาพและสื่อ')

    await pick(w, ['a.png', 'b.png'])
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ไฟล์ใหญ่เกินไป')
  })
})

describe('ProductEditView — ADR-052 fixes from verification', () => {
  const RULE = { id: 7, product: { id: 11 }, rate_type: 'percentage', rate_value: 1000, effective_from: '2026-10-01', effective_to: null, renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false }

  it('a CREATE is announced by the page it lands on, only once that page has loaded the stored product', async () => {
    routeState.params = {}
    const creating = await mountProduct()
    await creating.find('input[required]').setValue('new one')
    post.mockResolvedValueOnce({ data: productRow({ id: 12, name: 'New One' }) })

    await creating.find('form').trigger('submit')
    await flushPromises()

    expect(push).toHaveBeenCalledWith({ name: 'product-edit', params: { id: 12 } })
    // The creating page says nothing — nothing stored is on screen yet.
    expect(saveFeedbackState.show).toBe(false)
    creating.unmount()

    // App.vue keys RouterView by path: a NEW instance mounts for /products/12.
    routeState.params = { id: '12' }
    product = productRow({ id: 12, name: 'New One' })
    const load = deferred<unknown>()
    heldGets = { '/products/12': load.promise }
    const landed = mountProduct()
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    load.resolve({ data: product })
    const w = await landed
    await flushPromises()

    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('สร้างสินค้า "New One" แล้ว')
    expect((w.find('input[required]').element as HTMLInputElement).value).toBe('New One')

    // One-shot: the next page mounted does not repeat it.
    w.unmount()
    await mountProduct()
    expect(saveFeedbackState.count).toBe(1)
  })

  it('a stored rule whose re-read fails is reported as saved-but-stale, never as a failed save', async () => {
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    await w.find('input[step="0.01"]').setValue('10')
    put.mockResolvedValueOnce({ data: productRow() })
    post.mockImplementationOnce(async () => {
      failingGets = ['/products/11']

      return { data: RULE }
    })

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
    expect(w.text()).not.toContain('บันทึกอัตราค่าแนะนำไม่สำเร็จ')
  })

  it('a rule-list re-read that fails is stale too (loadCommissionRules no longer passes as success)', async () => {
    product = productRow({ commission_rate_type: 'percentage' })
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    await w.find('input[step="0.01"]').setValue('10')
    put.mockResolvedValueOnce({ data: product })
    post.mockImplementationOnce(async () => {
      failingGets = ['/commission-rules']

      return { data: RULE }
    })

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })

  it('basics stored but the pin failed: a partial dialog says what WAS saved, and the pin error stays', async () => {
    const w = await mountProduct()
    put.mockResolvedValueOnce({ data: productRow() })
    const pinSwitch = w.findAll('button[type="button"]').find((b) => b.element.closest('div')?.textContent?.includes('ปักหมุดแนะนำ'))!
    await pinSwitch.trigger('click')
    post.mockRejectedValueOnce(new Err(422, 'ลำดับซ้ำกับสินค้าอื่น'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.title).toBe('บันทึกแล้วบางส่วน')
    expect(saveFeedbackState.body).toBe('บันทึกข้อมูลสินค้า "GENESENN Vital Blueprint" แล้ว — แต่การปักหมุดแนะนำยังไม่ได้บันทึก')
    expect(w.text()).toContain('ลำดับซ้ำกับสินค้าอื่น')
  })

  it('settings stored but the rate failed: a partial dialog, and the rate error stays', async () => {
    product = productRow({ commission_rate_type: 'percentage' })
    const w = await mountProduct()
    await openTab(w, 'ค่าแนะนำ')
    await w.find('input[step="0.01"]').setValue('10')
    put.mockResolvedValueOnce({ data: product })
    post.mockRejectedValueOnce(new Err(422, 'ช่วงวันที่ทับกับอัตราเดิม'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.title).toBe('บันทึกแล้วบางส่วน')
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าค่าแนะนำแล้ว — แต่ยังไม่ได้เพิ่มอัตราค่าแนะนำ')
    expect(w.text()).toContain('ช่วงวันที่ทับกับอัตราเดิม')
  })

  it('cover photos that landed but whose gallery re-read failed get the stale notice, not a plain success', async () => {
    postFile.mockImplementation(() => ({ promise: Promise.resolve({ data: {} }), abort: () => {} }))
    const w = await mountProduct()
    await openTab(w, 'รูปภาพและสื่อ')
    failingGets = ['/products/11/media']

    const input = w.find('input[type="file"][multiple][accept="image/*"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.png', { type: 'image/png' })], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})
