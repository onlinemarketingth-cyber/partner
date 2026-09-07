/**
 * TASK-256 / ADR-040 — the catalogue row, after "สินค้าใช้ร่วมกันทุกบริษัท".
 *
 * The first attempt at shared products copied each one into every company, and
 * the human rejected it by looking at this exact list: eight rows where there
 * should have been four ("ที่คุณทำคือการ Copy ไปไว้อีกบริษัทหนึ่งมันผิดโจทย์").
 * So the first thing asserted here is a count — one row per product, whatever
 * the header scope.
 *
 * The rest is about a row that now has TWO of everything and must never show
 * the wrong one:
 *
 *   is_active vs is_sellable_here   the platform's switch vs this company's
 *   price_satang vs effective_      the central price vs what they charge
 *   own_price_satang === null       and whether that number is even theirs
 *
 * Showing "ใช้งาน · 8,900 บาท" for a product AIA has never switched on would
 * be four words of fiction on the screen a Company Admin trusts, so every one
 * of those pairs is pinned to the sentence the row is supposed to say.
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

import ProductCatalogView from '../ProductCatalogView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const THAI_LIFE = { id: 1, name: 'Thai Life', slug: 'thai-life' }
const AIA = { id: 2, name: 'AIA', slug: 'aia' }

const BRAND = { id: 11, company_id: null, name: 'Genesenn', is_active: true }
const CATEGORY = { id: 21, company_id: null, name: 'Anti Aging', is_active: true, sort_order: 0, icon: null }

/**
 * ONE product row, platform-owned — the shape the API sends once
 * catalog:promote-products has run. The per-company fields are resolved
 * server-side for the company in the header (CompanyScopeFilter::
 * contextCompanyId), so a test changes the scope by changing THESE, exactly
 * as a real scope switch does.
 */
function sharedProduct(overrides: Record<string, unknown> = {}) {
  return {
    id: 100,
    is_shared: true,
    company_id: null,
    name: 'Vital Blueprint V5',
    price_satang: 890000,
    effective_price_satang: 890000,
    own_price_satang: null,
    is_sellable_here: false,
    is_active: true,
    brand: BRAND,
    category: CATEGORY,
    commission_rate_type: null,
    ...overrides,
  }
}

function ownProduct(overrides: Record<string, unknown> = {}) {
  return {
    id: 200,
    is_shared: false,
    company_id: THAI_LIFE.id,
    name: 'Thai Life Only',
    price_satang: 100000,
    effective_price_satang: 100000,
    own_price_satang: null,
    is_sellable_here: true,
    is_active: true,
    brand: BRAND,
    category: CATEGORY,
    commission_rate_type: null,
  }
}

function mockApi(products: unknown[]) {
  get.mockImplementation(async (path: string) => {
    // The real company list matters: activeCompany.loadCompanies() DROPS a
    // selected id that is not in it (it is how a stale persisted company is
    // cleaned up), so a stub returning [] would silently reset every test
    // here to ทุกบริษัท and hide exactly the controls under test.
    if (path.startsWith('/companies')) return { data: [THAI_LIFE, AIA] }
    if (path.startsWith('/brands')) return { data: [BRAND] }
    if (path.startsWith('/product-categories')) return { data: [CATEGORY] }
    if (path.startsWith('/products')) return { data: products }
    if (path.startsWith('/storefront-banners')) return { data: [] }
    if (path.startsWith('/cert-tiers')) return { data: [] }

    return { data: [] }
  })
}

async function mountView(products: unknown[], companyId: number | null = THAI_LIFE.id) {
  mockApi(products)

  const active = useActiveCompanyStore()
  active.companies = [THAI_LIFE, AIA]
  // `companyId` is a computed: for a Super Admin it reads selectedId, for
  // anyone else it is pinned to their own company (which the Company Admin
  // tests below set on the auth user). Writing the real input, not the
  // derived value, is what makes those two paths behave as they do in the app.
  active.selectedId = companyId

  const wrapper = mount(ProductCatalogView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        ConfirmDialog: true,
        PlatformScopeBadge: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function clickByText(wrapper: Wrapper, text: string) {
  const button = wrapper.findAll('button').find((b) => b.text().trim() === text)
  if (!button) throw new Error(`ไม่พบปุ่ม "${text}" — ปุ่มที่มี: ${wrapper.findAll('button').map((b) => b.text().trim()).join(' | ')}`)

  return button.trigger('click')
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('ProductCatalogView — a shared product is ONE row', () => {
  it('does not repeat the product once per company', async () => {
    // The rejected design, caught by counting. Two companies, one product.
    const wrapper = await mountView([sharedProduct()])

    expect(wrapper.text().split('Vital Blueprint V5').length - 1).toBe(1)
  })

  it('marks it as central so nobody edits it thinking it is theirs', async () => {
    const wrapper = await mountView([sharedProduct()])

    expect(wrapper.text()).toContain('ของกลาง')
  })

  it('keeps showing a company-owned product with no shared badge', async () => {
    const wrapper = await mountView([ownProduct()])

    expect(wrapper.text()).toContain('Thai Life Only')
    expect(wrapper.text()).not.toContain('ของกลาง')
  })

  it('still lists the shared product when a company is scoped', async () => {
    /*
     * The bug this guards: the row arrives with company_id null, and the old
     * client-side narrowing dropped anything that was not === the scoped id.
     * The shared catalogue would have been invisible on the only screen that
     * can switch it on — and the list would have looked simply empty.
     */
    const wrapper = await mountView([sharedProduct()], AIA.id)

    expect(wrapper.text()).toContain('Vital Blueprint V5')
  })
})

describe('ProductCatalogView — whose state is on the row', () => {
  it('says the company is not selling it yet, not that it is inactive', async () => {
    // is_active is the PLATFORM's switch and is true here. Rendering that
    // would tell an AIA admin the product is live for them. It is not.
    const wrapper = await mountView([sharedProduct({ is_sellable_here: false })], AIA.id)

    expect(wrapper.text()).toContain('ยังไม่เปิดขายที่ AIA')
  })

  it('names the company that is selling it', async () => {
    const wrapper = await mountView([sharedProduct({ is_sellable_here: true })], THAI_LIFE.id)

    expect(wrapper.text()).toContain('ขายอยู่ที่ Thai Life')
  })

  it('shows what THIS company charges, not the central price', async () => {
    const wrapper = await mountView([
      sharedProduct({ own_price_satang: 790000, effective_price_satang: 790000, is_sellable_here: true }),
    ])

    expect(wrapper.text()).toContain('7,900 บาท')
    expect(wrapper.text()).not.toContain('8,900 บาท')
  })

  it('flags an inherited price, because it will move when the centre moves', async () => {
    const wrapper = await mountView([sharedProduct()], AIA.id)

    expect(wrapper.text()).toContain('(ราคากลาง)')
  })

  it('does not flag a price the company deliberately chose', async () => {
    // Even when it happens to equal the central one — which is exactly why
    // own_price_satang is sent instead of being inferred from equality.
    const wrapper = await mountView([
      sharedProduct({ own_price_satang: 890000, effective_price_satang: 890000 }),
    ])

    expect(wrapper.text()).not.toContain('(ราคากลาง)')
  })

  it('hides the per-company controls in ทุกบริษัท mode', async () => {
    /*
     * With no company scoped the API has nobody to answer for: it reports the
     * central price and false. Rendering "ยังไม่เปิดขายที่…" from that would
     * invent a decision no company made.
     */
    const wrapper = await mountView([sharedProduct()], null)

    expect(wrapper.text()).not.toContain('ยังไม่เปิดขายที่')
    expect(wrapper.findAll('button').some((b) => b.text().trim() === 'เปิดขาย')).toBe(false)
  })

  it('still says ปิดใช้งาน when the platform switched the product off', async () => {
    // Off centrally is off everywhere, whatever a company set — the row must
    // not let the per-company line read as the whole truth.
    const wrapper = await mountView([sharedProduct({ is_active: false, is_sellable_here: true })])

    expect(wrapper.text()).toContain('ปิดใช้งาน')
  })
})

describe('ProductCatalogView — changing one company\'s settings', () => {
  it('opens the product for sale without touching its price', async () => {
    /*
     * Deliberately two separate actions. Sending a price here would decide
     * what a company charges as a side effect of listing the product, and the
     * inherited central price would silently become a chosen one.
     */
    const wrapper = await mountView([sharedProduct({ is_sellable_here: false })], AIA.id)

    await clickByText(wrapper, 'เปิดขาย')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/100/company-settings', {
      company_id: AIA.id,
      is_active: true,
    })
    expect(put.mock.calls[0]![1]).not.toHaveProperty('price_satang')
  })

  it('closes it again from the same button', async () => {
    const wrapper = await mountView([sharedProduct({ is_sellable_here: true })], AIA.id)

    await clickByText(wrapper, 'ปิดขาย')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/100/company-settings', {
      company_id: AIA.id,
      is_active: false,
    })
  })

  it('sends satang, not the baht that was typed', async () => {
    // BR-3. 7,900 typed as 79000 satang would list the product at 790 บาท —
    // a plausible-looking number, which is what makes it dangerous.
    const wrapper = await mountView([sharedProduct()], AIA.id)

    await clickByText(wrapper, 'ตั้งราคา')
    await wrapper.find('input[type="checkbox"]').setValue(false)
    await wrapper.find('input[type="number"]').setValue('7900')
    await clickByText(wrapper, 'บันทึก')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/100/company-settings', {
      company_id: AIA.id,
      price_satang: 790000,
    })
  })

  it('sends an explicit null to go back to the central price', async () => {
    /*
     * null is an instruction ("stop overriding"), not an empty field, and it
     * is not the same as omitting the key (which means "leave the price
     * alone"). The server reads the two separately.
     */
    const wrapper = await mountView([
      sharedProduct({ own_price_satang: 790000, effective_price_satang: 790000 }),
    ], AIA.id)

    await clickByText(wrapper, 'ตั้งราคา')
    // The box starts unticked because this company HAS its own price.
    expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false)
    await wrapper.find('input[type="checkbox"]').setValue(true)
    await clickByText(wrapper, 'บันทึก')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/100/company-settings', {
      company_id: AIA.id,
      price_satang: null,
    })
  })

  it('refuses a negative price rather than sending it', async () => {
    const wrapper = await mountView([sharedProduct()], AIA.id)

    await clickByText(wrapper, 'ตั้งราคา')
    await wrapper.find('input[type="checkbox"]').setValue(false)
    await wrapper.find('input[type="number"]').setValue('-1')
    await clickByText(wrapper, 'บันทึก')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ราคาไม่ถูกต้อง')
  })

  it('offers no per-company controls to a Company Admin', async () => {
    /*
     * Pricing a shared product is Super Admin's, confirmed twice by the human
     * (ADR-036's decision table and again on 2026-09-05). The server refuses
     * it too — UpdateCompanyProductSettingRequest::authorize() — so this is
     * about not showing a button that would 403.
     */
    const auth = useAuthStore()
    auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView([sharedProduct()], AIA.id)

    expect(wrapper.findAll('button').some((b) => b.text().trim() === 'ตั้งราคา')).toBe(false)
    expect(wrapper.findAll('button').some((b) => b.text().trim() === 'เปิดขาย')).toBe(false)
    // But they still see whether their own company is selling it.
    expect(wrapper.text()).toContain('ยังไม่เปิดขายที่ AIA')
  })

  it('hides แก้ไข on a shared product from a Company Admin', async () => {
    // Editing the central row changes it for every company at once.
    const auth = useAuthStore()
    auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never

    const wrapper = await mountView([sharedProduct()], AIA.id)

    expect(wrapper.text()).not.toContain('แก้ไข')
  })
})
