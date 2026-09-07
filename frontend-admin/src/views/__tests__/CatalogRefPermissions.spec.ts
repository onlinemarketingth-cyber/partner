/**
 * TASK-245 — the brand & category cards, once a row can belong to the platform.
 *
 * These cards group rows BY NAME, which was a good decision for TASK-204 and
 * became a permission bug the day a brand could be platform-owned: one card on
 * screen now holds a company's own row AND the platform's, and "may I rename
 * this card" has two different answers inside one card.
 *
 * The old code sent both. A Company Admin renaming their own brand got a 403
 * from the half that was never theirs — and the delete dialog counted the
 * platform row in "จาก 2 บริษัท" before deleting one and failing on the other,
 * which is worse than no dialog: it is the sentence they read before agreeing.
 *
 * So the card asks the server. These tests pin the three consequences:
 * a card that is entirely the platform's offers no buttons; a mixed card still
 * works and touches only their half; and the confirmation counts what will
 * actually happen.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const del = vi.fn()
const put = vi.fn()
const postForm = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
    postForm: (...args: unknown[]) => postForm(...args),
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

const AIA = { id: 2, name: 'AIA', slug: 'aia' }
const THAI_LIFE = { id: 1, name: 'Thai Life', slug: 'thai-life' }

const READ_ONLY = { update: false, delete: false }
const WRITABLE = { update: true, delete: true }

/** The platform's brand — every company's promoted products point at it. */
const PLATFORM_BRAND = {
  id: 11, company_id: null, name: 'Genesenn', is_active: true, products_count: 4, permissions: READ_ONLY,
}
/** AIA's own brand, which happens to carry the same name. */
const OWN_BRAND = {
  id: 12, company_id: AIA.id, name: 'Genesenn', is_active: true, products_count: 1, permissions: WRITABLE,
}
const CATEGORY = {
  id: 21, company_id: null, name: 'Anti Aging', is_active: true, sort_order: 0, icon: null, permissions: READ_ONLY,
}

function mockApi(brands: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/companies')) return { data: [THAI_LIFE, AIA] }
    if (path.startsWith('/brands')) return { data: brands }
    if (path.startsWith('/product-categories')) return { data: [CATEGORY] }
    if (path.startsWith('/products')) return { data: [] }
    if (path.startsWith('/storefront-banners')) return { data: [] }
    if (path.startsWith('/cert-tiers')) return { data: [] }

    return { data: [] }
  })
}

/** Mounts the view and opens the brand/category drawer, where these cards live. */
async function mountDrawer(brands: unknown[], tab: 'brands' | 'categories' = 'brands') {
  mockApi(brands)

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
        CompanyMultiSelect: true,
        // Rendered inline so the dialog's body text is assertable.
        ConfirmDialog: { props: ['show', 'body'], template: '<div v-if="show" data-test="confirm">{{ body }}</div>' },
        PlatformScopeBadge: true,
        // The drawer teleports; disabling it keeps the cards inside the wrapper.
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

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountDrawer>>

const editButtons = (w: Wrapper) => w.findAll('[title="แก้ไข"]')
const deleteButtons = (w: Wrapper) => w.findAll('[title="ลบ"]')

function asCompanyAdmin() {
  const auth = useAuthStore()
  auth.user = { id: 2, name: 'แอดมินบริษัท', role: 'company_admin', company: AIA } as never
}

beforeEach(() => {
  get.mockReset()
  del.mockReset()
  put.mockReset()
  postForm.mockReset()
  del.mockResolvedValue({})
  put.mockResolvedValue({ data: {} })
  postForm.mockResolvedValue({ data: {} })

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('ProductCatalogView — a card that is only the platform\'s', () => {
  it('offers a Company Admin no edit or delete at all', async () => {
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND])

    expect(editButtons(wrapper)).toHaveLength(0)
    expect(deleteButtons(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain('อ่านอย่างเดียว')
  })

  it('still offers both to a Super Admin', async () => {
    // Same row, different viewer — so the SERVER sends a different answer.
    // That is the point: the screen renders whatever it is told.
    const wrapper = await mountDrawer([{ ...PLATFORM_BRAND, permissions: WRITABLE }])

    expect(editButtons(wrapper).length).toBeGreaterThan(0)
    expect(deleteButtons(wrapper).length).toBeGreaterThan(0)
  })

  it('applies the same rule to categories', async () => {
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND], 'categories')

    expect(wrapper.text()).toContain('Anti Aging')
    expect(editButtons(wrapper)).toHaveLength(0)
  })
})

describe('ProductCatalogView — a card holding both rows', () => {
  it('still lets a Company Admin edit, and warns which half moves', async () => {
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND, OWN_BRAND])

    const edit = editButtons(wrapper)[0]
    expect(edit).toBeTruthy()
    await edit!.trigger('click')

    // Said BEFORE the save, not discovered from a 403 afterwards.
    expect(wrapper.text()).toContain('มีผลเฉพาะแบรนด์ของบริษัทคุณ')
  })

  it('saves only the row that is theirs', async () => {
    /*
     * The actual 403. The platform row is deliberately in the update set for a
     * Super Admin — a rename must reach it or the group splits into two names
     * on the next reload — and it must be skipped for everyone else.
     */
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND, OWN_BRAND])
    await editButtons(wrapper)[0]!.trigger('click')
    await wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึก')!.trigger('click')
    await flushPromises()

    const paths = postForm.mock.calls.map((c) => c[0])
    expect(paths).toContain(`/brands/${OWN_BRAND.id}`)
    expect(paths).not.toContain(`/brands/${PLATFORM_BRAND.id}`)
  })

  it('counts only what the delete will actually remove', async () => {
    // The dialog used to say "จาก 2 บริษัท" and name ของกลาง among them.
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND, OWN_BRAND])
    await deleteButtons(wrapper)[0]!.trigger('click')
    await flushPromises()

    const body = wrapper.find('[data-test="confirm"]').text()
    expect(body).toContain('จาก 1 บริษัท')
    expect(body).toContain('AIA')
    expect(body).toContain('ของกลางจะยังอยู่')
  })

  it('and deletes only that row', async () => {
    asCompanyAdmin()

    const wrapper = await mountDrawer([PLATFORM_BRAND, OWN_BRAND])
    await deleteButtons(wrapper)[0]!.trigger('click')
    await flushPromises()
    // ConfirmDialog is stubbed, so the confirm handler is invoked directly —
    // what is under test is which rows it touches, not the dialog's own wiring.
    await (wrapper.vm as unknown as { confirmDeleteBrand: () => Promise<void> }).confirmDeleteBrand()
    await flushPromises()

    expect(del.mock.calls.map((c) => c[0])).toEqual([`/brands/${OWN_BRAND.id}`])
  })
})
