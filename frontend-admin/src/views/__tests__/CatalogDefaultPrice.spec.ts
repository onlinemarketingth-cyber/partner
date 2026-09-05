/**
 * TASK-251 — the price that reaches every company.
 *
 * Saving this form no longer creates a row in one table; it creates a priced
 * listing in every tenant. Three things therefore have to be true of this
 * screen, and none of them is visible by looking at it:
 *
 *  1. BAHT IN, SATANG OUT. The admin types 8,900 and the API must receive
 *     890000 (BR-3). Sending the typed number would list the product at
 *     89 บาท in every company at once — a plausible-looking price, which is
 *     what makes it dangerous.
 *
 *  2. THE PRICE IS NOT OPTIONAL. Omitting it would mean the screen choosing
 *     one on the admin's behalf, and 0 บาท is not "blank" (BR-7).
 *
 *  3. THE CONSEQUENCE IS STATED BEFORE THE CLICK. "Save" here means "add this
 *     to every company". An admin who learns that afterwards has already done
 *     it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
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
}))

import CatalogManagementView from '../CatalogManagementView.vue'
import { useAuthStore } from '@/stores/auth'

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
  linked_product_count: 2,
  created_at: '2026-09-05T03:00:00Z',
  updated_at: '2026-09-05T03:00:00Z',
}

function mockApi(items = [ITEM]) {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/catalog-brands')) return { data: [BRAND] }
    if (path.startsWith('/catalog-categories')) return { data: [CATEGORY] }

    return { data: items }
  })
}

async function mountView() {
  const wrapper = mount(CatalogManagementView, {
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

/** Open "เพิ่มรายการแคตตาล็อก" and fill everything but the price. */
async function openCreateForm(wrapper: Wrapper) {
  await wrapper.findAll('button').find((b) => b.text().includes('เพิ่มรายการแคตตาล็อก'))!.trigger('click')
  await flushPromises()

  const selects = wrapper.findAll('select')
  await selects[0]!.setValue('1')
  await selects[1]!.setValue('2')
  await wrapper.findAll('input[type="text"], input:not([type])')[0]!.setValue('Vital Blueprint V5')
}

const priceField = (wrapper: Wrapper) => wrapper.find('[data-test="catalog-default-price"]')

async function save(wrapper: Wrapper) {
  await wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึก')!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  post.mockResolvedValue({ data: ITEM })
  put.mockResolvedValue({ data: ITEM })
  mockApi()

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CatalogManagementView — the shared default price', () => {
  it('sends satang, not the baht the admin typed', async () => {
    // BR-3. 8,900 baht is 890000 satang; sending 8900 would list the product
    // at 89 บาท in every company — a number that looks like a price, which is
    // exactly why nobody would catch it by looking at the screen.
    const wrapper = await mountView()
    await openCreateForm(wrapper)
    await priceField(wrapper).setValue('8900')

    await save(wrapper)

    expect(post).toHaveBeenCalledWith(
      '/product-catalog-items',
      expect.objectContaining({ default_price_satang: 890000 }),
    )
  })

  it('never sends a fraction of a satang', async () => {
    /*
     * `8900.005 * 100` is 890000.49999999994 in binary floating point, not
     * 890000.5 — which is also why the assertion here is "an integer", not a
     * guess at which way it rounds. Unrounded, a fractional satang reaches a
     * column that truncates it silently, and the price is wrong by a satang
     * in every company at once.
     */
    const wrapper = await mountView()
    await openCreateForm(wrapper)
    await priceField(wrapper).setValue('8900.005')

    await save(wrapper)

    const sent = (post.mock.calls[0]![1] as { default_price_satang: number }).default_price_satang
    expect(Number.isInteger(sent)).toBe(true)
    expect(sent).toBe(890000)
  })

  it('refuses to save without a price instead of choosing one', async () => {
    // BR-7 — and the failure mode is silent: a missing price would have to
    // become something, and every candidate (0, blank, the last item's) is a
    // number a person reads as a decision.
    const wrapper = await mountView()
    await openCreateForm(wrapper)

    await save(wrapper)

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณาระบุราคาเริ่มต้น')
  })

  it('accepts a deliberate zero, which is not the same as blank', async () => {
    /*
     * The bug a falsy check would introduce. Zero is a price a Super Admin
     * may genuinely mean (a free onboarding item), the server accepts it
     * (min:0), and refusing it would be the screen overruling a decision it
     * was not asked to make.
     */
    const wrapper = await mountView()
    await openCreateForm(wrapper)
    await priceField(wrapper).setValue('0')

    await save(wrapper)

    expect(post).toHaveBeenCalledWith(
      '/product-catalog-items',
      expect.objectContaining({ default_price_satang: 0 }),
    )
  })

  it('says what saving will do before it is clicked', async () => {
    // The whole reason this task is not just a new column: one save reaches
    // every tenant. Learning that from a success message is learning it too
    // late.
    const wrapper = await mountView()
    await openCreateForm(wrapper)

    const notice = wrapper.find('[data-test="propagation-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('ทุกบริษัท')
    expect(notice.text()).toContain('ปิดการใช้งานไว้')
  })

  it('shows the existing default price in baht on the list', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ราคาเริ่มต้น 8,900 บาท')
  })

  it('loads the price back into the form as baht when editing', async () => {
    // The round trip. Showing 890000 in a field labelled บาท would invite an
    // admin to "fix" it to 8900 — and that edit is what the next company
    // created would be priced from.
    const wrapper = await mountView()

    await wrapper.findAll('button').find((b) => b.attributes('title') === 'แก้ไข')!.trigger('click')
    await flushPromises()

    expect((priceField(wrapper).element as HTMLInputElement).value).toBe('8900')
  })

  it('does not repeat the every-company notice when editing an existing item', async () => {
    // Editing changes what the NEXT company starts from; it does not touch
    // the companies that already have this item. Showing the creation notice
    // here would promise something that does not happen.
    const wrapper = await mountView()

    await wrapper.findAll('button').find((b) => b.attributes('title') === 'แก้ไข')!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="propagation-notice"]').exists()).toBe(false)
  })
})
