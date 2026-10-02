/**
 * ADR-052 — adding an interested product from the client drawer.
 *
 * submitReferral() re-reads the open client (refreshSelectedClient) so the
 * drawer shows the new deal. That re-read used to swallow its failure
 * silently: the success toast appeared next to a drawer still showing the
 * state from BEFORE the write, which only F5 would fix. Pinned here:
 *   - the success toast appears only after POST resolves AND the re-read
 *     has landed; the drawer then shows the deal the SERVER returned;
 *   - a rejected POST gives no success toast and the error is reported;
 *   - a failed re-read after a successful POST is an info toast saying the
 *     screen may be out of date — never a failed save;
 *   - the same applies to the status change, a different write in this view.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import ClientsView from '../ClientsView.vue'
import AppSelect from '@/design-system/components/AppSelect.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const SELF_ID = 9
const PRODUCTS = [{ id: 10, name: 'แพ็กเกจที่เลือก', price_satang: 890000 }]
const STALE = 'บันทึกแล้ว แต่โหลดข้อมูลล่าสุดไม่สำเร็จ — ข้อมูลบนหน้าจออาจไม่เป็นปัจจุบัน'

function referral(id: number, productName: string) {
  return {
    id,
    product: { id: 10, name: productName, price_satang: 890000 },
    branch: null,
    preferred_time: null,
    current_stage: { key: 'complete_registered', label: 'Complete Registered' },
    co_agent: null,
    split_percentage: null,
  }
}

function client(referrals: unknown[], status = { key: 'new', label: 'New' }) {
  return {
    id: 1,
    name: 'คุณสมชาย ใจดี',
    phone: '0800000000',
    email: null,
    consent_given_at: null,
    health_notes: null,
    referring_agent_id: SELF_ID,
    status,
    lead_source: null,
    date_of_birth: null,
    address: null,
    province: null,
    occupation: null,
    referrals,
    created_at: '2026-08-01T00:00:00Z',
    client_category_id: null,
    client_category_name: null,
  }
}

/** `refresh` answers the single-client re-read the drawer does after a write. */
function wire(refresh: () => Promise<unknown>) {
  get.mockImplementation((path: string) => {
    if (path === '/clients' || path.startsWith('/clients?')) return Promise.resolve({ data: [client([])] })
    if (/^\/clients\/\d+$/.test(path)) return refresh()
    if (path === '/commission-split-settings') return Promise.resolve({ data: { is_enabled: false } })
    if (path === '/products') return Promise.resolve({ data: PRODUCTS })
    if (path === '/user-certifications')
      return Promise.resolve({ data: [{ id: 1, user_id: SELF_ID, cert_tier: { id: 1, key: 'basic', name: 'Basic' } }] })
    if (path === '/client-categories') return Promise.resolve({ data: [] })
    if (path.endsWith('/documents')) return Promise.resolve({ data: [] })
    if (path.endsWith('/activities')) return Promise.resolve({ data: [] })
    if (path.startsWith('/orders')) return Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1 } })
    throw new Error(`unexpected GET ${path}`)
  })
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

async function mountDrawer() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/clients', component: { template: '<div />' } }],
  })
  await router.push('/clients')
  await router.isReady()
  const wrapper = mount(ClientsView, {
    global: {
      stubs: {
        HeroHeader: true,
        LoadingSkeleton: true,
        FilterSheet: true,
        BuddhistDateInput: true,
        AuthenticatedMedia: true,
        ConfirmDialog: true,
      },
      plugins: [router],
    },
  })
  await flushPromises()
  await wrapper.find('.cursor-pointer').trigger('click')
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountDrawer>>

async function fillAndSubmitReferral(wrapper: Wrapper) {
  const trigger = wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่มสินค้าที่สนใจ'))
  if (!trigger) throw new Error('no add-interest trigger in the drawer')
  await trigger.trigger('click')

  // The product picker is the AppSelect inside the form (the one offering PRODUCTS).
  const select = appSelects(wrapper).find((s) => (s.props() as { options: { value: string }[] }).options.some((o) => o.value === '10'))
  if (!select) throw new Error('no product picker in the add-interest form')
  select.vm.$emit('update:modelValue', '10')
  await flushPromises()

  const save = wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึก')
  if (!save) throw new Error('no save button in the add-interest form')
  await save.trigger('click')
  await flushPromises()
}

/** AppSelect is a generic SFC, which VTU's typings resolve to a DOM wrapper. */
function appSelects(wrapper: Wrapper): VueWrapper[] {
  return wrapper.findAllComponents(AppSelect) as unknown as VueWrapper[]
}

const successes = () => useToastStore().toasts.filter((t) => t.variant === 'success')
const infos = () => useToastStore().toasts.filter((t) => t.variant === 'info')

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().user = { id: SELF_ID, name: 'สมาชิก ทดสอบ' } as AuthUser
  get.mockReset()
  post.mockReset()
  put.mockReset()
})

describe('ClientsView — add interested product (ADR-052)', () => {
  it('toasts only after POST and the re-read; the drawer shows the deal the SERVER returned', async () => {
    const refresh = deferred<unknown>()
    wire(() => refresh.promise)
    post.mockResolvedValue({ data: { id: 77 } })
    const wrapper = await mountDrawer()
    expect(wrapper.text()).toContain('ยังไม่มีสินค้าที่สนใจ')

    await fillAndSubmitReferral(wrapper)

    expect(post).toHaveBeenCalledWith('/referrals', { client_id: 1, product_id: 10, branch: undefined, preferred_time: undefined })
    // POST answered, the drawer re-read has not — nothing announced yet.
    expect(successes()).toHaveLength(0)

    // The server names the product differently from what the picker showed.
    refresh.resolve({ data: client([referral(77, 'แพ็กเกจตามที่เซิร์ฟเวอร์บันทึก')]) })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['เพิ่มสินค้าที่สนใจแล้ว'])
    expect(infos()).toHaveLength(0)
    expect(wrapper.text()).toContain('แพ็กเกจตามที่เซิร์ฟเวอร์บันทึก')
    expect(wrapper.text()).not.toContain('ยังไม่มีสินค้าที่สนใจ')
  })

  it('a rejected POST: no success toast, the error is reported, the drawer is unchanged', async () => {
    wire(() => Promise.resolve({ data: client([]) }))
    post.mockRejectedValue(new FakeApiError(422, { errors: { product_id: ['สินค้านี้ขายไม่ได้แล้ว'] } }))
    const wrapper = await mountDrawer()

    await fillAndSubmitReferral(wrapper)

    expect(successes()).toHaveLength(0)
    expect(useToastStore().toasts.some((t) => t.variant === 'error' && t.message.includes('สินค้านี้ขายไม่ได้แล้ว'))).toBe(true)
    expect(wrapper.text()).toContain('ยังไม่มีสินค้าที่สนใจ')
  })

  it('a failed re-read after a successful POST is an info toast — not a failed save', async () => {
    wire(() => Promise.reject(new FakeApiError(500, {})))
    post.mockResolvedValue({ data: { id: 77 } })
    const wrapper = await mountDrawer()

    await fillAndSubmitReferral(wrapper)

    expect(successes().map((t) => t.message)).toEqual(['เพิ่มสินค้าที่สนใจแล้ว'])
    expect(infos().map((t) => t.message)).toEqual([STALE])
    expect(useToastStore().toasts.some((t) => t.variant === 'error')).toBe(false)
  })
})

describe('ClientsView — status change (ADR-052)', () => {
  async function pickStatus(wrapper: Wrapper, key: string) {
    const select = appSelects(wrapper).find((s) => (s.props() as { options: { value: string }[] }).options.some((o) => o.value === 'contacted'))
    if (!select) throw new Error('no status picker in the drawer')
    select.vm.$emit('update:modelValue', key)
    await flushPromises()
  }

  it('toasts after PUT and the re-read, and the drawer shows the SERVER\'s status', async () => {
    const refresh = deferred<unknown>()
    wire(() => refresh.promise)
    put.mockResolvedValue({ data: {} })
    const wrapper = await mountDrawer()

    expect(wrapper.text()).not.toContain('Interested')
    await pickStatus(wrapper, 'contacted')
    expect(put).toHaveBeenCalledWith('/clients/1', { status: 'contacted' })
    expect(successes()).toHaveLength(0)

    refresh.resolve({ data: client([], { key: 'interested', label: 'Interested' }) })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['อัปเดตสถานะแล้ว'])
    expect(wrapper.text()).toContain('Interested')
  })

  it('a failed re-read after the PUT warns that the screen may be stale', async () => {
    wire(() => Promise.reject(new FakeApiError(500, {})))
    put.mockResolvedValue({ data: {} })
    const wrapper = await mountDrawer()

    await pickStatus(wrapper, 'contacted')

    expect(successes().map((t) => t.message)).toEqual(['อัปเดตสถานะแล้ว'])
    expect(infos().map((t) => t.message)).toEqual([STALE])
  })
})
