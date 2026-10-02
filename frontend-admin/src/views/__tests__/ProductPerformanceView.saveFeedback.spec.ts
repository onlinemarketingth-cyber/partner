/**
 * ADR-052 — ProductPerformanceView (price promotions): "saved" is said once,
 * by the dialog, only after the server answered and the list was re-read.
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

import ProductPerformanceView from '../ProductPerformanceView.vue'
import { ApiError } from '@/api/client'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const PROMO = {
  id: 7,
  company_id: 1,
  product_id: 3,
  product_name: 'Vital Blueprint',
  product_price_satang: 890000,
  discounted_price_satang: 790000,
  note: null,
  status: 'draft',
  is_currently_active: false,
  starts_at: '2026-10-01',
  ends_at: null,
  created_by: 1,
  created_at: '2026-10-01T00:00:00Z',
}

let promotions = [PROMO]

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => (resolve = r))

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(ProductPerformanceView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CompanyScopeNotice: true,
        RouterLink: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>
const button = (w: Wrapper, text: string) => w.findAll('button').find((b) => b.text().trim() === text)!

beforeEach(() => {
  promotions = [PROMO]
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/products-abc-grades')) return { data: [], window_days: null, computed_at: '' }
    if (path.startsWith('/product-price-promotions')) return { data: promotions }
    if (path.startsWith('/products')) return { data: [{ id: 3, company_id: 1, name: 'Vital Blueprint', price_satang: 890000 }] }

    return { data: [] }
  })
})

describe('ProductPerformanceView — ADR-052 save feedback', () => {
  it('editing a promotion raises the dialog only after the server answered, and shows the SERVER price', async () => {
    const w = await mountView()
    await button(w, 'แก้ไข').trigger('click')
    await flushPromises()
    await w.find('input[type="number"]').setValue('7000')

    const pending = deferred<unknown>()
    put.mockReturnValueOnce(pending.promise)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server stored a different price than was typed (e.g. rounded by a rule).
    const stored = { ...PROMO, discounted_price_satang: 750000 }
    promotions = [stored]
    pending.resolve({ data: stored })
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/product-price-promotions/7', expect.objectContaining({ discounted_price_satang: 700000 }))
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('Vital Blueprint')
    expect(saveFeedbackState.body).toContain((7500).toLocaleString('th-TH'))
    expect(w.text()).toContain(`${(7500).toLocaleString('th-TH')} บาท`)
    expect(w.find('form').exists()).toBe(false)
  })

  it('a failing save shows the error, keeps the form open, and raises no dialog', async () => {
    const w = await mountView()
    await button(w, 'แก้ไข').trigger('click')
    await flushPromises()
    put.mockRejectedValueOnce(new (ApiError as unknown as new (s: number, m: string) => Error)(422, 'ช่วงวันที่ทับกับส่วนลดเดิม'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.find('form').exists()).toBe(true)
    expect(w.text()).toContain('ช่วงวันที่ทับกับส่วนลดเดิม')
  })

  it('deleting a promotion asks first, then raises the dialog once the server confirmed', async () => {
    del.mockResolvedValue(null)
    const w = await mountView()

    await button(w, 'ลบ').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(w.text()).toContain('ยืนยันลบส่วนลดของ "Vital Blueprint"?')

    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/product-price-promotions/7')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบส่วนลดของ "Vital Blueprint" แล้ว')
  })

  it('a failing delete shows the error and raises no dialog', async () => {
    del.mockRejectedValue(new (ApiError as unknown as new (s: number, m: string) => Error)(409, 'ลบไม่ได้'))
    const w = await mountView()

    await button(w, 'ลบ').trigger('click')
    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ลบไม่ได้')
    expect(w.text()).toContain('Vital Blueprint')
  })
})
