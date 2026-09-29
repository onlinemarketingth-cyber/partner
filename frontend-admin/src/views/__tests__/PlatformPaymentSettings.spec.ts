/**
 * ADR-050 — "ระบบชำระเงินกลาง" and what a company's page does meanwhile.
 *
 * Owner: "สามารถ setup ได้จาก super admin ว่าระบบชำระเงินใช้ค่าเดียวทุกบริษัท
 * หรือแยกบริษัท". These pin: the switch is on the platform page, it is
 * confirmed before it moves anybody's money, the server's reasons for
 * refusing are shown, the platform's webhook URL is its own, and a company's
 * page stops offering forms the server now refuses.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import PaymentGatewaySettingsView from '../PaymentGatewaySettingsView.vue'

const OMISE = {
  provider: 'omise',
  label: 'Omise (Opn Payments)',
  requires_human_verification: false,
  always_available: false,
  is_active: false,
  is_live: false,
  is_configured: false,
  is_verified: false,
  verified_at: null,
  verified_note: null,
  fields: [{ key: 'public_key', label: 'Public key', required: true, secret: false, value: null, is_set: false }],
}
const MANUAL = { ...OMISE, provider: 'manual', label: 'โอนเงิน / PromptPay', requires_human_verification: true, always_available: true, fields: [] }

function platformOverview(over: Record<string, unknown> = {}) {
  return {
    mode: 'company',
    mode_label: 'แยกรายบริษัท',
    platform_ready_problems: ['ยังไม่ได้ตั้งบัญชีรับเงินกลาง (เลขบัญชีหรือพร้อมเพย์)'],
    transfer_account: { promptpay_id: null, bank_name: 'Platform Bank', bank_account_number: '999', bank_account_name: null },
    active_provider: null,
    gateways: [MANUAL, OMISE],
    ...over,
  }
}

async function mountView(scope: 'company' | 'platform') {
  const wrapper = mount(PaymentGatewaySettingsView, {
    props: { scope },
    global: {
      stubs: {
        HeroHeader: true,
        Icon: true,
        InfoPopover: { template: '<span class="info"><slot /></span>' },
        RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' },
        ConfirmDialog: {
          props: ['show', 'title', 'confirmLabel'],
          emits: ['confirm', 'cancel'],
          template: '<div v-if="show" data-test="confirm"><p>{{ title }}</p><button data-test="confirm-yes" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
        },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  put.mockReset()
  post.mockReset()
  vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test')
})

describe('the platform page', () => {
  it('reads the platform overview, not a company, and shows its transfer account', async () => {
    get.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    expect(get).toHaveBeenCalledWith('/platform-payment-settings')
    expect(wrapper.find('[data-test="platform-mode"]').exists()).toBe(true)
    expect((wrapper.find('[data-test="bank-account-number"]').element as HTMLInputElement).value).toBe('999')
  })

  it('says why "ใช้ค่าเดียวทุกบริษัท" cannot be chosen yet', async () => {
    get.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    expect(wrapper.find('[data-test="readiness"]').text()).toContain('ยังไม่ได้ตั้งบัญชีรับเงินกลาง')
  })

  it('asks before switching every company, then sends the mode', async () => {
    get.mockResolvedValue({ data: platformOverview({ platform_ready_problems: [] }) })
    put.mockResolvedValue({ data: platformOverview({ mode: 'platform', platform_ready_problems: [] }) })
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="mode-platform"]').trigger('click')
    expect(put).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="confirm"]').text()).toContain('ให้ทุกบริษัทใช้ช่องทางกลาง')

    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/platform-payment-settings/mode', { mode: 'platform' })
    expect(wrapper.find('[data-test="mode-platform"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false)
  })

  it('saves the transfer account to the platform, not to a company', async () => {
    get.mockResolvedValue({ data: platformOverview() })
    put.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="bank-account-number"]').setValue('123-4')
    await wrapper.find('[data-test="save-payout"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/platform-payment-settings/transfer-account', {
      promptpay_id: null,
      bank_name: 'Platform Bank',
      bank_account_number: '123-4',
      bank_account_name: null,
    })
  })

  it("gives the platform's own webhook URL", async () => {
    get.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    expect(wrapper.text()).toContain('https://api.example.test/api/v1/webhooks/payments/omise/platform')
  })
})

describe("a company's page while every company uses the platform", () => {
  it('offers no forms, says why, and links to the platform page', async () => {
    get.mockImplementation(async (path: string) =>
      path.endsWith('/payment-gateways')
        ? { data: { active_provider: null, gateways: [MANUAL, OMISE], account_mode: 'platform' } }
        : { data: {} },
    )
    const wrapper = await mountView('company')

    expect(wrapper.find('[data-test="company-locked"]').text()).toContain('ใช้ช่องทางชำระเงินกลางของแพลตฟอร์ม')
    expect(wrapper.find('[data-test="company-locked"] [data-to="/platform-payment-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="save-payout"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="platform-mode"]').exists()).toBe(false)
  })

  it('shows the usual forms when each company keeps its own', async () => {
    get.mockImplementation(async (path: string) =>
      path.endsWith('/payment-gateways')
        ? { data: { active_provider: null, gateways: [MANUAL, OMISE], account_mode: 'company' } }
        : { data: {} },
    )
    const wrapper = await mountView('company')

    expect(wrapper.find('[data-test="company-locked"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-payout"]').exists()).toBe(true)
    // A company's own endpoint, never the platform's.
    expect(wrapper.text()).not.toContain('/webhooks/payments/omise/platform')
  })
})
