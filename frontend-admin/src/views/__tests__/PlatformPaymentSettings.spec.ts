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
  fields: [
    { key: 'public_key', label: 'Public key', required: true, secret: false, value: null, is_set: false },
    {
      key: 'webhook_secret',
      label: 'Webhook signature secret',
      required: true,
      secret: true,
      help: 'ได้หลังตั้ง webhook ใน Omise',
      info: 'Omise Dashboard → Settings → Webhooks',
      value: null,
      is_set: true,
    },
  ],
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
        InfoPopover: { props: ['text'], template: '<span class="info">{{ text }}<slot /></span>' },
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

describe('credential field wording (owner: "คำอธิบายตรงนี้มันตรงไหมสำหรับผู้ใช้")', () => {
  it('keeps one short line under the box and the steps behind the ⓘ', async () => {
    get.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    expect(wrapper.text()).toContain('ได้หลังตั้ง webhook ใน Omise')
    expect(wrapper.find('form .info').text()).toContain('Omise Dashboard → Settings → Webhooks')
    // A saved secret tells the admin what to do, not why it is hidden.
    expect(wrapper.text()).toContain('บันทึกไว้แล้ว · เว้นว่างไว้ถ้าไม่ต้องการเปลี่ยน')
  })
})

describe('webhook status and the ตรวจสอบ webhook button', () => {
  const STRIPE_VERIFIED = {
    ...OMISE,
    provider: 'stripe',
    label: 'Stripe (บัตรเครดิต / เดบิต)',
    is_active: true,
    is_configured: true,
    is_verified: true,
    verified_at: '2026-09-29T05:00:00Z',
    verified_note: 'เชื่อมต่อสำเร็จ',
    fields: [{ key: 'publishable_key', label: 'Publishable key', required: true, secret: false, value: 'pk_test_x', is_set: true }],
    webhook: {
      accepted_recent: 0,
      rejected_recent: 2,
      last_accepted_at: null,
      last_rejected_at: '2026-09-29T06:00:00Z',
      window_days: 7,
      problems: [{ level: 'error', code: 'signature_rejected', message: 'มี webhook ถูกปฏิเสธเพราะลายเซ็นไม่ตรง 2 ครั้งใน 7 วัน' }],
    },
  }

  it('warns on the card without pressing anything', async () => {
    get.mockResolvedValue({ data: platformOverview({ gateways: [MANUAL, STRIPE_VERIFIED] }) })
    const wrapper = await mountView('platform')

    const problem = wrapper.find('[data-test="webhook-problem"]')
    expect(problem.text()).toContain('ลายเซ็นไม่ตรง 2 ครั้ง')
    expect(problem.classes()).toContain('text-rose-700')
    expect(wrapper.find('[data-test="webhook-status"]').text()).toContain('ยังไม่เคยได้รับ')
  })

  it('asks the server to check, then shows its answer in place of the summary', async () => {
    get.mockImplementation(async (path: string) =>
      path.endsWith('/webhook-check')
        ? {
            data: {
              status: 'ok',
              setup_checkable: true,
              setup: { checked: true, endpoint_url: 'https://x.test/api/v1/webhooks/payments/stripe/platform', other_urls: [], missing_events: [] },
              deliveries: STRIPE_VERIFIED.webhook,
              problems: [],
            },
          }
        : { data: platformOverview({ gateways: [MANUAL, STRIPE_VERIFIED] }) },
    )
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="check-webhook"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/platform-payment-settings/gateways/stripe/webhook-check')
    expect(wrapper.find('[data-test="webhook-result"]').text()).toContain('ไม่พบปัญหา webhook')
    expect(wrapper.find('[data-test="webhook-result"]').text()).toContain('/stripe/platform')
    expect(wrapper.find('[data-test="webhook-problem"]').exists()).toBe(false)
  })

  it('says plainly when the provider dashboard cannot be read', async () => {
    const omise = { ...STRIPE_VERIFIED, provider: 'omise', label: 'Omise (Opn Payments)', webhook: { ...STRIPE_VERIFIED.webhook, rejected_recent: 0, problems: [] } }
    get.mockImplementation(async (path: string) =>
      path.endsWith('/webhook-check')
        ? { data: { status: 'ok', setup_checkable: false, setup: { checked: false, endpoint_url: null, other_urls: [], missing_events: [] }, deliveries: omise.webhook, problems: [] } }
        : { data: platformOverview({ gateways: [MANUAL, omise] }) },
    )
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="check-webhook"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="webhook-result"]').text()).toContain('ไม่มีช่องทางให้ระบบอ่านการตั้งค่าใน Dashboard')
  })

  it('shows no webhook block when the server sends no webhook status', async () => {
    get.mockResolvedValue({ data: platformOverview({ gateways: [MANUAL, { ...STRIPE_VERIFIED, webhook: null }] }) })
    const wrapper = await mountView('platform')

    expect(wrapper.find('[data-test="webhook-status"]').exists()).toBe(false)
  })

  it('shows no webhook block for a gateway that is not set up yet', async () => {
    get.mockResolvedValue({ data: platformOverview() })
    const wrapper = await mountView('platform')

    expect(wrapper.find('[data-test="webhook-status"]').exists()).toBe(false)
  })
})

