/**
 * 2026-09-09 (human: "ตอนนี้ไม่มี Ui ลบสินค้ากลาง กับสินค้าจาก company ผู้ใช้
 * ไม่ทราบ ควรแยกกัน" / "ออกแบบ Ui สำหรับปุ่มกู้คืนสินค้าซึ่งจะทำใน tab ใหม่").
 *
 * Two deletes wearing the same grey trash icon.
 *
 * Hiding a company's package takes it off one storefront. Hiding a PLATFORM
 * package takes it off every storefront on the system at once — including
 * companies nobody on this screen is looking at, whose names the row does not
 * even carry. The confirmation said the same sentence for both, so the second
 * one was always performed blind.
 *
 * What this file pins:
 *
 *   THE CONTROL IS DIFFERENT, not just the dialog. A bare icon cannot say
 *   "ทุกบริษัท"; by the time the dialog is open the person has already decided.
 *
 *   THE DIALOG SPEAKS FROM THE SERVER. The body is built from
 *   GET /products/{id}/deletion-impact — the same computation that enforces
 *   the refusal — so it can NAME the affected companies and can never promise
 *   a delete the server then refuses. It used to recite the rules from a
 *   string literal in the template.
 *
 *   THE PLATFORM DELETE IS TYPED, NOT CLICKED. The human's ruling, asked and
 *   answered: "ให้พิมพ์ชื่อสินค้ายืนยันไหม? — พิมพ์".
 *
 *   AND IT CAN BE UNDONE. Everything here has soft-deleted since TASK-091 and
 *   nothing on any screen could bring a row back; the only route was a
 *   hand-written UPDATE against production.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
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

import ProductCatalogView from '../ProductCatalogView.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const AIA = { id: 2, name: 'AIA', slug: 'aia' }
const THAI_LIFE = { id: 1, name: 'Thai Life', slug: 'thai-life' }

const WRITABLE = { update: true, delete: true, restore: true }
const READ_ONLY = { update: false, delete: false, restore: false }

const SHARED = {
  id: 11,
  company_id: null,
  name: 'GENESENN Vital Blueprint',
  price_satang: 2990000,
  is_active: true,
  is_shared: true,
  is_sellable_here: true,
  effective_price_satang: 2990000,
  own_price_satang: null,
  brand: { id: 1, name: 'Genesenn' },
  category: { id: 2, name: 'Anti Aging' },
  permissions: { ...WRITABLE, set_commission_rule: true },
}
const OWN = {
  id: 12,
  company_id: AIA.id,
  name: 'AIA Only Package',
  price_satang: 100000,
  is_active: true,
  is_shared: false,
  is_sellable_here: true,
  effective_price_satang: 100000,
  own_price_satang: 100000,
  brand: { id: 1, name: 'Genesenn' },
  category: { id: 2, name: 'Anti Aging' },
  permissions: { ...WRITABLE, set_commission_rule: true },
}

/** What GET /products/{id}/deletion-impact answers. */
function impact(overrides: Record<string, unknown> = {}) {
  return { is_shared: true, blockers: {}, selling_companies: [], ...overrides }
}

let impactPayload = impact()
let trashPayload: Record<string, unknown[]> = { products: [], brands: [], categories: [] }

function mockApi(products: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (path.includes('deletion-impact')) return { data: impactPayload }
    if (path.startsWith('/catalog-trash')) return { data: trashPayload }
    if (path.startsWith('/product-recommendation-pins')) return { data: [] }
    if (path.startsWith('/companies')) return { data: [THAI_LIFE, AIA] }
    if (path.startsWith('/brands')) return { data: [] }
    if (path.startsWith('/product-categories')) return { data: [] }
    if (path.startsWith('/products')) return { data: products }

    return { data: [] }
  })
}

async function mountView(products: unknown[] = [SHARED, OWN], role = 'super_admin') {
  mockApi(products)

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role, company: role === 'super_admin' ? null : AIA } as never

  const active = useActiveCompanyStore()
  active.companies = [THAI_LIFE, AIA]
  active.selectedId = AIA.id

  const wrapper = mount(ProductCatalogView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /><slot name="actions" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        CompanyMultiSelect: true,
        PlatformScopeBadge: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

/**
 * The confirm button is the last one in the dialog. Named rather than indexed
 * inline because `noUncheckedIndexedAccess` is on and `!` would turn a
 * markup change into "Cannot read properties of undefined" rather than a
 * sentence saying the button went missing.
 */
function confirmButton(w: { findAll: (s: string) => { trigger: (e: string) => Promise<void> }[] }) {
  const buttons = w.findAll('button')
  const last = buttons[buttons.length - 1]
  if (!last) throw new Error('ไม่พบปุ่มยืนยันในกล่องยืนยัน')

  return last
}

/** Open the bin tab. */
async function openTrashTab(w: Wrapper) {
  const tab = w.findAll('button').find((b) => b.text().includes('ถังขยะ'))
  if (!tab) throw new Error('ไม่พบแท็บถังขยะ')
  await tab.trigger('click')
  await flushPromises()
}

/** The one dialog that is currently open, whichever it is. */
function openDialog(w: Wrapper) {
  const dialog = w.findAllComponents(ConfirmDialog).find((d) => d.props('show') === true)
  if (!dialog) throw new Error('ไม่มีกล่องยืนยันเปิดอยู่')

  return dialog
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  del.mockReset()
  del.mockResolvedValue({})
  post.mockResolvedValue({ data: {} })
  impactPayload = impact()
  trashPayload = { products: [], brands: [], categories: [] }
})

// ── The control, before any dialog opens ─────────────────────────────

describe('the primary image is shown on the row', () => {
  it('renders the thumbnail the server resolved', async () => {
    /*
     * 2026-09-09 (human: "นำรูปสินค้าหลักมาแสดงเป็น thumbnail หน้าชื่อสินค้า").
     *
     * `thumbnail_url` has been in every /products response since TASK-056 —
     * resolved server-side as the PRIMARY cover first, so it is the same
     * image the star button on the product's images tab controls. This list
     * simply never read it.
     */
    const w = await mountView([{ ...SHARED, thumbnail_url: '/api/v1/product-media/9/stream' }])

    expect(w.find('[data-test="product-thumbnail"]').exists()).toBe(true)
  })

  it('keeps the box when a product has no photo yet', async () => {
    // Not a collapsed gap: names starting at two different x positions read
    // as two lists rather than one.
    const w = await mountView([{ ...SHARED, thumbnail_url: null }])

    expect(w.find('[data-test="product-thumbnail"]').exists()).toBe(false)
    expect(w.find('.w-\\[52px\\]').exists()).toBe(true)
  })

  it('is as tall as the three lines beside it', async () => {
    // The human asked for exactly this, and a Tailwind step (h-14 = 56px)
    // would have dragged every row taller.
    const w = await mountView()

    const box = w.find('.w-\\[52px\\]')
    expect(box.classes()).toContain('h-[52px]')
  })
})

describe('a closed product reads as closed, and sinks', () => {
  /*
   * 2026-09-09 (human: "ปรับเมนูเปิด ปิด สินค้าให้เป็นสวิทซ์ … เปลี่ยนสีตัว
   * อักษรทั้งแถวใหม่เป็นสีเทา ปรับตัวรูป Thumbnail เป็นสีขาวดำ และเลื่อน
   * ตำแหน่งล่างสุด").
   *
   * Nothing is hidden or disabled: the reason to look at a closed row is to
   * change something about it. It is only visibly not part of today's shop —
   * which the eye sorts far faster than it reads a status word.
   */
  const OPEN = { ...SHARED, id: 41, name: 'ขายอยู่', is_sellable_here: true }
  const CLOSED = { ...SHARED, id: 42, name: 'ปิดขายอยู่', is_sellable_here: false }

  it('puts what is on sale above what is not', async () => {
    // Passed in closed-first, so a passing assertion means it was sorted, not
    // that it happened to arrive in the right order.
    const w = await mountView([CLOSED, OPEN])

    const names = w.findAll('[data-test^="product-row-"]').map((row) => row.text())
    expect(names[0]).toContain('ขายอยู่')
    expect(names[1]).toContain('ปิดขายอยู่')
  })

  it('drains the colour from a closed row', async () => {
    const w = await mountView([CLOSED])

    expect(w.find('[data-test="product-row-closed"]').exists()).toBe(true)
    expect(w.find('[data-test="product-row-closed"]').html()).toContain('grayscale')
  })

  it('leaves an open row alone', async () => {
    const w = await mountView([OPEN])

    expect(w.find('[data-test="product-row-open"]').exists()).toBe(true)
    expect(w.find('[data-test="product-row-open"]').html()).not.toContain('grayscale')
  })

  it('says which state it is in, in words as well as colour', async () => {
    /*
     * 2026-09-09 (human: "เพิ่ม copy เปิด หรือ ปิดสินค้าด้วย"). A switch shows
     * a STATE where the old button announced an ACTION — but a state with no
     * word beside it has to be inferred from which end a dot sits at, and the
     * colour alone does not carry for a reader who does not separate the two
     * blues.
     */
    expect((await mountView([OPEN])).find('[data-test="sell-here-label"]').text()).toBe('เปิดขาย')
    expect((await mountView([CLOSED])).find('[data-test="sell-here-label"]').text()).toBe('ปิดขาย')
  })

  it('parks the knob on the LEFT when the product is closed', async () => {
    /*
     * 2026-09-09 (human: "ปรับเลื่อนสวิตช์ปิดให้มาด้านซ้ายมือ UI ได้เข้าใจว่า
     * ปิดอยู่").
     *
     * On the build the human was looking at, the knob sat right-of-centre in
     * BOTH states — so "off" looked like "on". The cause was the old markup:
     * an absolutely positioned span with `top`/`bottom` but no `left`, which
     * falls back to its STATIC position, and a <button> is text-align:center
     * by default. The knob was therefore centred, and the translate that was
     * supposed to move it never compiled.
     *
     * Asserted on both states, because a switch whose two ends are not
     * visibly different is not a switch.
     */
    const closed = await mountView([CLOSED])
    expect(closed.find('[data-test="sell-here-switch"] span.rounded-full.border').classes()).toContain('justify-start')

    const open = await mountView([OPEN])
    expect(open.find('[data-test="sell-here-switch"] span.rounded-full.border').classes()).toContain('justify-end')
  })

  it('moves the knob with a utility that actually exists', async () => {
    /*
     * The knob was invisible in the ON state on the real build: its travel was
     * written as the arbitrary value `translate-x-[26px]`, and an arbitrary
     * value only exists if Tailwind's scanner found that exact literal — it
     * had not. This asserts the flex approach that replaced it, because the
     * failure was invisible to every other test: the element WAS in the DOM,
     * with the right classes, and simply did not move.
     */
    const w = await mountView([OPEN])
    const track = w.find('[data-test="sell-here-switch"] span.rounded-full.border')

    expect(track.classes()).toContain('justify-end')
    expect(w.find('[data-test="sell-here-switch"]').html()).not.toContain('translate-x-[')
  })

  it('puts the switch last, after the delete control', async () => {
    // Human asked for exactly this position.
    const w = await mountView([OPEN])

    const buttons = w.find('[data-test="product-row-open"]').findAll('button')
    expect(buttons[buttons.length - 1]?.attributes('data-test')).toBe('sell-here-switch')
  })
})

describe('ปักหมุดแนะนำ from the list', () => {
  const OPEN = { ...SHARED, id: 51, is_sellable_here: true }
  const CLOSED = { ...SHARED, id: 52, is_sellable_here: false }

  it('pins without opening the product', async () => {
    // human: "เพิ่ม icon ดาวปักหมุด ในหน้านี้เลยไม่ต้องเข้าไปข้างใน".
    const w = await mountView([OPEN])

    await w.find('[data-test="toggle-pin"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/product-recommendation-pins', {
      product_id: 51,
      sort_order: 0,
      company_id: AIA.id,
    })
  })

  it('says in words whether it is being recommended', async () => {
    /*
     * 2026-09-09 (human: "ย้ายดาวไปคู่กับปุ่ม switch พร้อม label อธิบาย").
     *
     * A gold star rather than a grey one is something a person has to have
     * been taught. The word means nobody has to be — and it is the same
     * grammar as the switch beside it, which reads its state out too.
     */
    expect((await mountView([{ ...OPEN, id: 61 }])).find('[data-test="pin-label"]').text()).toBe('ยังไม่แนะนำ')
  })

  it('sits with the sell switch, because closing the sale puts it out', async () => {
    /*
     * The two controls that decide what this product does on the STOREFRONT,
     * and they are chained: closing the sale switches the pin off server-side.
     * A star four controls away would appear to go dark for no reason.
     */
    const w = await mountView([OPEN])
    const group = w.find('[data-test="toggle-pin"]').element.parentElement

    expect(group?.querySelector('[data-test="sell-here-switch"]')).not.toBeNull()
  })

  it('will not let you pin something the company is not selling', async () => {
    /*
     * The server refuses it (a pin is a storefront promise and needs the sell
     * grant), so the star is disabled rather than allowed to 422 — and the
     * tooltip says why, because a control that simply vanishes teaches nothing.
     */
    const w = await mountView([CLOSED])

    const star = w.find('[data-test="toggle-pin"]')
    expect(star.attributes('disabled')).toBeDefined()
    expect(star.attributes('title')).toContain('ยังไม่เปิดขาย')
  })

  it('re-reads the pins after a sale is closed', async () => {
    /*
     * Closing a sale switches that company's pin off SERVER-side. Without the
     * refetch the star would stay lit next to a switch that says closed — the
     * row contradicting itself until the next reload.
     */
    const w = await mountView([OPEN])
    get.mockClear()

    await w.find('[data-test="sell-here-switch"]').trigger('click')
    await flushPromises()

    expect(get.mock.calls.some(([path]) => path === '/product-recommendation-pins')).toBe(true)
  })
})

describe('the two deletes do not look alike', () => {
  it('gives a platform package a labelled button, not a bare icon', async () => {
    // By the time a dialog is open the person has already decided. The
    // difference has to be visible on the row.
    const w = await mountView()

    expect(w.find('[data-test="delete-platform-product"]').exists()).toBe(true)
    expect(w.find('[data-test="delete-platform-product"]').text()).toContain('ลบสินค้ากลาง')
  })

  it("leaves a company package's delete exactly as it was", async () => {
    const w = await mountView()

    expect(w.find('[data-test="delete-company-product"]').exists()).toBe(true)
  })

  it('tells a Company Admin why a shared row has no controls at all', async () => {
    /*
     * ADR-040 puts a shared package's price, on/off switch and deletion with
     * Super Admin. That is the rule; an empty row reads as a broken screen.
     */
    const w = await mountView([{ ...SHARED, permissions: { ...READ_ONLY, set_commission_rule: false } }], 'company_admin')

    expect(w.text()).toContain('จัดการโดยผู้ดูแลระบบ')
  })

  it('can show one kind at a time', async () => {
    const w = await mountView()
    expect(w.text()).toContain('AIA Only Package')

    await w.find('[data-test="owner-filter-platform"]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('GENESENN Vital Blueprint')
    expect(w.text()).not.toContain('AIA Only Package')
  })
})

// ── The dialog ───────────────────────────────────────────────────────

describe('the platform delete says what it will actually do', () => {
  it('names every company that would lose the product', async () => {
    // "3 บริษัท" is a number to agree with. "AIA · Thai Life" is a list to
    // recognise, and recognising one you did not expect is the whole point.
    impactPayload = impact({ selling_companies: ['AIA', 'Thai Life'] })
    const w = await mountView()

    await w.find('[data-test="delete-platform-product"]').trigger('click')
    await flushPromises()

    const body = openDialog(w).props('body') as string
    expect(body).toContain('มีผลกับทุกบริษัท')
    expect(body).toContain('AIA · Thai Life')
  })

  it('asks the server rather than reciting the rules from the template', async () => {
    const w = await mountView()
    await w.find('[data-test="delete-platform-product"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/products/11/deletion-impact')
  })

  it('lists what is blocking the delete, with counts', async () => {
    // The refusal, said before the click rather than as a 422 afterwards.
    impactPayload = impact({ blockers: { 'Referral / การขาย': 3, 'รายการคอมมิชชั่น': 0 } })
    const w = await mountView()

    await w.find('[data-test="delete-platform-product"]').trigger('click')
    await flushPromises()

    const body = openDialog(w).props('body') as string
    expect(body).toContain('ลบไม่ได้ตอนนี้')
    expect(body).toContain('Referral / การขาย 3 รายการ')
    // A zero blocker is not a blocker; listing it would read as a reason.
    expect(body).not.toContain('รายการคอมมิชชั่น 0')
  })

  it('says plainly when a company package affects nobody else', async () => {
    impactPayload = impact({ is_shared: false })
    const w = await mountView()

    await w.find('[data-test="delete-company-product"]').trigger('click')
    await flushPromises()

    const body = openDialog(w).props('body') as string
    expect(body).toContain('บริษัทอื่นไม่ได้รับผลกระทบ')
    expect(body).not.toContain('มีผลกับทุกบริษัท')
  })

  it('demands the name typed for a platform package and nothing for a company one', async () => {
    const w = await mountView()

    await w.find('[data-test="delete-platform-product"]').trigger('click')
    await flushPromises()
    expect(openDialog(w).props('confirmPhrase')).toBe('GENESENN Vital Blueprint')

    await openDialog(w).vm.$emit('update:show', false)
    await flushPromises()

    await w.find('[data-test="delete-company-product"]').trigger('click')
    await flushPromises()
    expect(openDialog(w).props('confirmPhrase')).toBe('')
  })
})

describe('ConfirmDialog — the typed gate', () => {
  const mountDialog = (confirmPhrase: string) =>
    mount(ConfirmDialog, { props: { show: true, body: 'ลบ?', confirmPhrase } })

  it('will not confirm until the phrase matches', async () => {
    const w = mountDialog('Vital Blueprint')

    await confirmButton(w).trigger('click')
    expect(w.emitted('confirm')).toBeUndefined()

    await w.find('[data-test="confirm-phrase"]').setValue('Vital Blueprint')
    await confirmButton(w).trigger('click')
    expect(w.emitted('confirm')).toHaveLength(1)
  })

  it('ignores stray whitespace, since a pasted name often carries it', async () => {
    const w = mountDialog('Vital Blueprint')

    await w.find('[data-test="confirm-phrase"]').setValue('  Vital Blueprint ')
    await confirmButton(w).trigger('click')

    expect(w.emitted('confirm')).toHaveLength(1)
  })

  it('does not carry a satisfied gate over to the next dialog', async () => {
    /*
     * The failure that would quietly undo the whole feature: reopen for a
     * different, larger delete and find the gate already open.
     */
    const w = mountDialog('Vital Blueprint')
    await w.find('[data-test="confirm-phrase"]').setValue('Vital Blueprint')

    await w.setProps({ show: false })
    await w.setProps({ show: true })

    await confirmButton(w).trigger('click')
    expect(w.emitted('confirm')).toBeUndefined()
  })

  it('leaves every existing dialog untouched when no phrase is set', async () => {
    const w = mountDialog('')

    expect(w.find('[data-test="confirm-phrase"]').exists()).toBe(false)
    await confirmButton(w).trigger('click')
    expect(w.emitted('confirm')).toHaveLength(1)
  })
})

// ── The bin ──────────────────────────────────────────────────────────

describe('ถังขยะ', () => {
  const deletedProduct = { ...OWN, id: 30, name: 'ลบไปแล้ว', deleted_at: '2026-09-08T00:00:00Z' }

  it('is empty until something has been deleted', async () => {
    const w = await mountView()
    await openTrashTab(w)

    expect(w.find('[data-test="trash-products-row"]').exists()).toBe(false)
  })

  it('lists a deleted package with a restore button', async () => {
    trashPayload = { products: [deletedProduct], brands: [], categories: [] }
    const w = await mountView()
    await openTrashTab(w)

    expect(w.find('[data-test="trash-products-row"]').text()).toContain('ลบไปแล้ว')
    expect(w.find('[data-test="restore-row"]').exists()).toBe(true)
  })

  it('brings the row back through its own endpoint', async () => {
    trashPayload = { products: [deletedProduct], brands: [], categories: [] }
    const w = await mountView()
    await openTrashTab(w)

    await w.find('[data-test="restore-row"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/products/30/restore', {})
  })

  it('shows a platform row to a Company Admin but offers no button', async () => {
    /*
     * Hiding it would make the catalogue look as though the product simply
     * ceased to exist. Offering a button that 403s is worse. So: listed, with
     * the reason in place of the control.
     */
    trashPayload = {
      products: [{ ...SHARED, deleted_at: '2026-09-08T00:00:00Z', permissions: { ...READ_ONLY, set_commission_rule: false } }],
      brands: [],
      categories: [],
    }
    const w = await mountView([], 'company_admin')
    await openTrashTab(w)

    expect(w.find('[data-test="trash-products-row"]').exists()).toBe(true)
    expect(w.find('[data-test="restore-row"]').exists()).toBe(false)
    expect(w.text()).toContain('กู้คืนได้เฉพาะผู้ดูแลระบบ')
  })

  it('survives a response that is missing its buckets', async () => {
    // trashCount reads .length off all three on every render; a payload from
    // an older backend or a proxy error page must not blank the page.
    trashPayload = {} as Record<string, unknown[]>
    const w = await mountView()

    expect(w.text()).toContain('แพ็กเกจ')
  })
})
