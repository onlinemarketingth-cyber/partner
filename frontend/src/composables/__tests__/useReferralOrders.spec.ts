/**
 * useReferralOrders — TASK-191 §3.2's new `paid_at` field on `OrderSummary`.
 *
 * This is a light wiring test, not a re-test of the whole composable
 * (ClientsView.spec.ts and ReferralRow.spec.ts already cover the sweep, the
 * duplicate-order 422 recovery, and the share sheet through the views that
 * actually consume it). `paid_at` is a small typed-field addition — added
 * specifically so ClientsView's `mostRecentPaidReferralId()` can sort by it
 * — so what is asserted here is narrow: the field survives the GET /orders
 * sweep into `orderFor()` untouched, for both a real timestamp and `null`
 * (an order that has not been paid has no paid_at, and that must read as
 * `null`, not as a missing key that silently becomes `undefined`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import { useReferralOrders } from '../useReferralOrders'

function orderFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    order_number: 'ORD-0001',
    status: 'pending',
    status_label: 'รอชำระเงิน',
    public_pay_url: 'https://pay.test/1',
    referral_id: 1,
    paid_at: null,
    ...overrides,
  }
}

describe('useReferralOrders — paid_at wiring (TASK-191 §3.2)', () => {
  beforeEach(() => {
    // The composable calls useToastStore() — a real Pinia instance is
    // needed even though these tests never assert a toast.
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads a real paid_at timestamp through from the API response, untouched', async () => {
    get.mockResolvedValue({
      data: [
        orderFixture({
          id: 9,
          status: 'paid',
          status_label: 'ชำระเงินแล้ว',
          referral_id: 5,
          paid_at: '2026-08-10T09:00:00Z',
        }),
      ],
      meta: { current_page: 1, last_page: 1 },
    })

    const { ensureOrdersLoaded, orderFor } = useReferralOrders()
    await ensureOrdersLoaded()
    await flushPromises()

    expect(orderFor(5)?.paid_at).toBe('2026-08-10T09:00:00Z')
  })

  it('reads null (not undefined) for an order that has not been paid', async () => {
    get.mockResolvedValue({
      data: [orderFixture({ id: 3, referral_id: 7, status: 'pending', paid_at: null })],
      meta: { current_page: 1, last_page: 1 },
    })

    const { ensureOrdersLoaded, orderFor } = useReferralOrders()
    await ensureOrdersLoaded()
    await flushPromises()

    expect(orderFor(7)?.paid_at).toBeNull()
  })
})
