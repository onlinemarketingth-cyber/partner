/**
 * ADR-052 — CommissionManagementView's one write, "จ่ายแล้ว" (mark paid):
 * the dialog appears only after the server answered and the list was
 * re-read, and it quotes the server's amount (BR-3, satang).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
const post = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    constructor(public status: number) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
  ApiError: ApiErrorStub,
}))

import CommissionManagementView from '../CommissionManagementView.vue'

const STUBS = {
  HeroHeader: {
    props: ['kpis'],
    template: '<div><span v-for="k in kpis" :key="k.label">{{ k.label }}: {{ k.value }}</span><slot name="tabs" /><slot /></div>',
  },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  CompanyScopeNotice: true,
}

function row(over: Record<string, unknown> = {}) {
  return {
    id: 11,
    referral: { id: 1, client: { id: 1, name: 'ลูกค้า ก' } },
    agent: { id: 5, name: 'สมชาย' },
    cert_tier_at_time: null,
    product: { id: 1, name: 'แพ็กเกจ' },
    rate_type_applied: 'percentage',
    rate_applied: 300,
    amount_satang: 26700,
    payment_status: 'pending',
    earned_via: 'direct',
    override_source_agent: null,
    paid_at: null,
    created_at: '2026-09-01T00:00:00Z',
    ...over,
  }
}

let ledger: ReturnType<typeof row>[] = []

beforeEach(() => {
  vi.clearAllMocks()
  ledger = [row()]
  get.mockImplementation(async () => ({ data: ledger }))
})

async function mountView() {
  const wrapper = mount(CommissionManagementView, { global: { stubs: STUBS } })
  await flushPromises()

  return wrapper
}

function payButton(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').find((b) => b.text() === 'จ่ายแล้ว')
}

describe('CommissionManagementView — ADR-052 save feedback', () => {
  it('mark paid: dialog only after the POST resolved, quoting the SERVER amount and payee', async () => {
    let resolvePost: (v: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((r) => (resolvePost = r)))
    const wrapper = await mountView()

    await payButton(wrapper)!.trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // the server's answer differs from the row the admin clicked
    ledger = [row({ payment_status: 'paid', paid_at: '2026-10-01T00:00:00Z', amount_satang: 31500, agent: { id: 5, name: 'สมชาย ใจดี' } })]
    resolvePost({ data: ledger[0] })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain((31500 / 100).toLocaleString('th-TH'))
    expect(saveFeedbackState.body).toContain('สมชาย ใจดี')
    // re-read: the default "รอจ่าย" tab no longer lists the row
    expect(payButton(wrapper)).toBeUndefined()
  })

  it('mark paid: a refused write raises no dialog, shows the error and keeps the row pending', async () => {
    post.mockRejectedValue(new ApiErrorStub(403))
    const wrapper = await mountView()

    await payButton(wrapper)!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ (403)')
    expect(payButton(wrapper)).toBeDefined()
  })

  it('mark paid: the KPI totals come from the re-read ledger, not from the clicked row', async () => {
    post.mockImplementation(async () => {
      ledger = [row({ payment_status: 'paid', amount_satang: 50000 })]

      return { data: ledger[0] }
    })
    const wrapper = await mountView()

    await payButton(wrapper)!.trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('จ่ายแล้ว: ' + (50000 / 100).toLocaleString('th-TH') + ' บาท')
    expect(saveFeedbackState.show).toBe(true)
  })

  it('mark paid landed but the ledger could not be re-read: the dialog says the screen may be stale', async () => {
    post.mockResolvedValue({ data: row({ payment_status: 'paid' }) })
    const wrapper = await mountView()
    get.mockRejectedValue(new ApiErrorStub(500))

    await payButton(wrapper)!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
    expect(wrapper.text()).toContain('โหลดข้อมูลไม่สำเร็จ (500)')
  })
})
