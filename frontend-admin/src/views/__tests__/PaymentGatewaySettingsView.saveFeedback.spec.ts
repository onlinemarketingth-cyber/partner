/**
 * ADR-052 — "ช่องทางรับชำระเงิน" / "ระบบชำระเงินกลาง": every write redraws the
 * screen from what the server returned, then says it saved. The inline green
 * lines (payout notice, per-card notice) are gone; the verification note the
 * server writes is now the dialog's text.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

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
  ApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown = null,
    ) {
      super(`API error ${status}`)
    }
  },
}))

import PaymentGatewaySettingsView from '../PaymentGatewaySettingsView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

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
    { key: 'secret_key', label: 'Secret key', required: true, secret: true, value: null, is_set: false },
  ],
}
const OMISE_VERIFIED = {
  ...OMISE,
  is_configured: true,
  is_verified: true,
  verified_at: '2026-09-29T05:00:00Z',
  verified_note: 'เชื่อมต่อกับบัญชี ACME แล้ว',
  fields: [
    { ...OMISE.fields[0], value: 'pkey_test_1', is_set: true },
    { ...OMISE.fields[1], is_set: true },
  ],
}
const MANUAL = { ...OMISE, provider: 'manual', label: 'โอนเงิน / PromptPay', requires_human_verification: true, always_available: true, fields: [] }

const COMPANY_PAYOUT = {
  payment_promptpay_id: null,
  payment_bank_name: 'กสิกรไทย',
  payment_bank_account_number: '111',
  payment_bank_account_name: 'บริษัท เอ',
}

function companyGets(gateways: unknown[]) {
  get.mockImplementation(async (path: string) =>
    path.endsWith('/payment-gateways')
      ? { data: { active_provider: null, gateways, account_mode: 'company' } }
      : { data: { ...COMPANY_PAYOUT } },
  )
}

async function mountView(scope: 'company' | 'platform' = 'company') {
  const wrapper = mount(PaymentGatewaySettingsView, {
    props: { scope },
    global: {
      stubs: {
        HeroHeader: true,
        Icon: true,
        InfoPopover: { template: '<span class="info"><slot /></span>' },
        RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}
type Wrapper = Awaited<ReturnType<typeof mountView>>

const accountNumber = (w: Wrapper) => (w.find('[data-test="bank-account-number"]').element as HTMLInputElement).value
const omiseRadio = (w: Wrapper) => w.find('button[title="เลือกใช้ช่องทางนี้"]')

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test')
  useAuthStore().user = { id: 1, name: 'แอดมิน', role: 'company_admin', company: { id: 9, name: 'บริษัท เอ' } } as never
})

describe('PaymentGatewaySettingsView — save feedback (ADR-052)', () => {
  it("company payout: the modal waits for the server, and the form shows what the server stored (not what was typed)", async () => {
    companyGets([MANUAL, OMISE])
    const wrapper = await mountView()
    await wrapper.find('[data-test="bank-account-number"]').setValue(' 222-3 ')

    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))
    await wrapper.find('[data-test="save-payout"]').trigger('click')
    await flushPromises()
    expect(put).toHaveBeenCalledWith('/companies/9', expect.objectContaining({ payment_bank_account_number: '222-3' }))
    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: { ...COMPANY_PAYOUT, payment_bank_account_number: '2223' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกบัญชีรับเงินแล้ว — กสิกรไทย 2223')
    expect(accountNumber(wrapper)).toBe('2223')
    expect(wrapper.text()).not.toContain('บันทึกบัญชีรับเงินแล้ว')
  })

  it('a rejected gateway save raises no modal, shows the provider error and keeps the form open', async () => {
    companyGets([MANUAL, OMISE])
    const wrapper = await mountView()
    put.mockRejectedValue(new ApiError(422, { errors: { credentials: ['Omise ปฏิเสธ secret key นี้'] } }))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('Omise ปฏิเสธ secret key นี้')
    expect(wrapper.text()).toContain('ยังไม่ได้บันทึก')
  })

  it("a verified gateway save shows the server's verification note as the modal text, not inline", async () => {
    companyGets([MANUAL, OMISE])
    const wrapper = await mountView()
    put.mockResolvedValue({
      data: { active_provider: null, gateways: [MANUAL, OMISE_VERIFIED], account_mode: 'company' },
      message: 'เชื่อมต่อกับบัญชี ACME แล้ว',
    })

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เชื่อมต่อกับบัญชี ACME แล้ว')
    // The card now reads the stored state.
    expect(wrapper.text()).toContain('ตั้งค่าแล้ว')
    // Secrets are still never shown.
    expect(wrapper.text()).not.toContain('sk_')
  })

  it('picking a gateway by its radio is a write: modal names what the server switched on', async () => {
    companyGets([MANUAL, OMISE_VERIFIED])
    const wrapper = await mountView()
    post.mockResolvedValue({
      data: { active_provider: 'omise', gateways: [MANUAL, { ...OMISE_VERIFIED, is_active: true }], account_mode: 'company' },
    })

    await omiseRadio(wrapper).trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/companies/9/payment-gateways/activate', { provider: 'omise' })
    expect(saveFeedbackState.body).toBe('เปิดใช้ Omise (Opn Payments) เป็นช่องทางชำระเงินออนไลน์แล้ว')
    expect(wrapper.text()).toContain('รับเงินอยู่')
  })

  it('a refused radio pick raises no modal and the radio stays on the stored choice', async () => {
    companyGets([MANUAL, OMISE_VERIFIED])
    const wrapper = await mountView()
    post.mockRejectedValue(new ApiError(422, { message: 'เปิดใช้งานไม่ได้' }))

    await omiseRadio(wrapper).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('เปิดใช้งานไม่ได้')
    expect(omiseRadio(wrapper).find('span').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('รับเงินอยู่')
  })

  it('"ไม่ใช้ช่องทางออนไลน์" shows the modal after the overview came back', async () => {
    companyGets([MANUAL, { ...OMISE_VERIFIED, is_active: true }])
    const wrapper = await mountView()
    post.mockResolvedValue({ data: { active_provider: null, gateways: [MANUAL, OMISE_VERIFIED], account_mode: 'company' } })

    await wrapper.findAll('button').find((b) => b.text().includes('ไม่ใช้ช่องทางออนไลน์'))!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toContain('ปิดช่องทางชำระเงินออนไลน์แล้ว')
    expect(wrapper.text()).not.toContain('รับเงินอยู่')
  })

  it('a refused "ไม่ใช้ช่องทางออนไลน์" shows its error in place and leaves the settings panel on screen', async () => {
    companyGets([MANUAL, { ...OMISE_VERIFIED, is_active: true }])
    const wrapper = await mountView()
    post.mockRejectedValue(new ApiError(422, { message: 'ปิดไม่ได้ตอนนี้' }))

    await wrapper.findAll('button').find((b) => b.text().includes('ไม่ใช้ช่องทางออนไลน์'))!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="deactivate-error"]').text()).toBe('ปิดไม่ได้ตอนนี้')
    // The panel is still there: the payout form, the gateway card, and the
    // stored choice (Omise still on).
    expect(wrapper.find('[data-test="save-payout"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Omise (Opn Payments)')
    expect(wrapper.text()).toContain('รับเงินอยู่')
  })

  it('platform: switching mode asks first, then the modal names the mode the server stored', async () => {
    const overview = (over: Record<string, unknown> = {}) => ({
      mode: 'company',
      platform_ready_problems: [],
      transfer_account: { promptpay_id: null, bank_name: 'Platform Bank', bank_account_number: '999', bank_account_name: null },
      active_provider: null,
      gateways: [MANUAL, OMISE],
      ...over,
    })
    get.mockResolvedValue({ data: overview() })
    put.mockResolvedValue({ data: overview({ mode: 'platform' }) })
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="mode-platform"]').trigger('click')
    expect(put).not.toHaveBeenCalled()
    await wrapper.findAll('button').find((b) => b.text() === 'ใช้ค่าเดียวทุกบริษัท')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('เปลี่ยนระบบชำระเงินเป็น "ใช้ค่าเดียวทุกบริษัท" แล้ว')
    expect(wrapper.find('[data-test="mode-platform"]').attributes('aria-pressed')).toBe('true')
  })

  it('platform payout: the modal quotes the stored account and the form shows it', async () => {
    const overview = (account: string) => ({
      mode: 'company',
      platform_ready_problems: [],
      transfer_account: { promptpay_id: null, bank_name: 'Platform Bank', bank_account_number: account, bank_account_name: null },
      active_provider: null,
      gateways: [MANUAL, OMISE],
    })
    get.mockResolvedValue({ data: overview('999') })
    put.mockResolvedValue({ data: overview('1234') })
    const wrapper = await mountView('platform')

    await wrapper.find('[data-test="bank-account-number"]').setValue('12-34')
    await wrapper.find('[data-test="save-payout"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('บันทึกบัญชีรับเงินกลางแล้ว — Platform Bank 1234')
    expect(accountNumber(wrapper)).toBe('1234')
  })
})
