/**
 * What the agent is told about ภาษีหัก ณ ที่จ่าย, and when.
 *
 * ═══ WHY THIS SCREEN AND NOT ONLY THE ADMIN ONE ═══
 *
 * It is the agent's money. An agent who asks for 1,500 and receives 1,455
 * with nothing on screen having mentioned it reads that as the company
 * short-paying them, and the first anybody hears of it is a complaint to
 * whoever pressed the transfer button. So the deduction is stated BEFORE the
 * request is made, not discovered after it is paid.
 *
 * ═══ THE INVARIANT ═══
 *
 * The headline figure on a past request stays the GROSS. It is what the agent
 * earned, it is what their commission statement says, and it is the number
 * their withholding certificate will be written against — the tax is money
 * paid to the Revenue Department in their name, not money they did not earn.
 * The net is a second line, and it is absent entirely when nothing was
 * withheld: a "0.00 tax" row on every request of every company that does not
 * withhold is noise, and noise is how a real deduction stops being read.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, name: 'withdrawals' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import WithdrawalsView from '../WithdrawalsView.vue'

function request(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    amount_satang: 150000,
    wht_rate_at_time: null,
    wht_satang: 0,
    net_transfer_satang: 150000,
    status: 'transferred',
    status_label: 'โอนแล้ว',
    rejection_reason: null,
    transferred_at: '2026-09-19T00:00:00.000000Z',
    transfer_reference: null,
    bank_name: null,
    bank_account_number_masked: null,
    created_at: '2026-09-18T00:00:00.000000Z',
    ...overrides,
  }
}

async function mountView(opts: { whtRate?: number | null; requests?: ReturnType<typeof request>[] } = {}) {
  const { whtRate = null, requests = [] } = opts

  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawals/available')) {
      return {
        available_satang: 150000,
        min_withdrawal_satang: null,
        wht_rate: whtRate,
        payout_details_complete: true,
      }
    }

    return { data: requests }
  })

  const wrapper = mount(WithdrawalsView, {
    global: {
      stubs: {
        HeroHeader: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        AppCard: { template: '<div><slot /></div>' },
        AppButton: { template: '<button><slot /></button>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('before the agent asks', () => {
  it('says nothing while the amount field is empty', async () => {
    // A tax warning over an empty field is a warning about nothing.
    const wrapper = await mountView({ whtRate: 300 })

    expect(wrapper.find('[data-test="withdraw-wht-preview"]').exists()).toBe(false)
  })

  it('estimates what will actually arrive, once an amount is typed', async () => {
    const wrapper = await mountView({ whtRate: 300 })
    await wrapper.get('#withdraw_amount').setValue('1500')
    await flushPromises()

    // 3% of 1,500.00 is 45.00 → 1,455.00 arrives.
    const text = wrapper.get('[data-test="withdraw-wht-preview"]').text()
    expect(text).toContain('3')
    expect(text).toContain('1,455.00')
  })

  it('truncates the tax rather than rounding it up', async () => {
    /*
     * Matches the server's intdiv. Rounding up here would quote a tax one
     * satang LARGER than the one actually withheld — the wrong direction to
     * be wrong in on somebody else's money, and a number they would then find
     * does not match their slip.
     *
     * 3% of 3.33 baht (333 satang) is 9.99 satang → 9, leaving 324.
     */
    const wrapper = await mountView({ whtRate: 300 })
    await wrapper.get('#withdraw_amount').setValue('3.33')
    await flushPromises()

    expect(wrapper.get('[data-test="withdraw-wht-preview"]').text()).toContain('3.24')
  })

  it('says nothing at all when the company withholds nothing', async () => {
    const wrapper = await mountView({ whtRate: null })
    await wrapper.get('#withdraw_amount').setValue('1500')
    await flushPromises()

    expect(wrapper.find('[data-test="withdraw-wht-preview"]').exists()).toBe(false)
  })
})

describe('the history', () => {
  it('keeps the gross as the headline and states the net beside it', async () => {
    const wrapper = await mountView({
      requests: [request({ wht_rate_at_time: 300, wht_satang: 4500, net_transfer_satang: 145500 })],
    })

    // THE INVARIANT: the big number is still what the agent earned.
    expect(wrapper.text()).toContain('1,500.00')

    const line = wrapper.get('[data-test="withdrawal-wht-7"]').text()
    expect(line).toContain('45.00')
    expect(line).toContain('1,455.00')
  })

  it('shows no tax line on a request that had none', async () => {
    // Every request made before withholding existed is this row.
    const wrapper = await mountView({ requests: [request()] })

    expect(wrapper.find('[data-test="withdrawal-wht-7"]').exists()).toBe(false)
  })
})
