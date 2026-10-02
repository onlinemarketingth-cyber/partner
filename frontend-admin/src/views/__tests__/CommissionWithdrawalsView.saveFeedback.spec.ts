/**
 * ADR-052 — CommissionWithdrawalsView: approve / reject / mark-transferred
 * each raise the "saved" dialog only after the server answered and the queue
 * was re-read, quoting the server's row (BR-3 satang amounts).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
const post = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
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
  },
  ApiError: ApiErrorStub,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionWithdrawalsView from '../CommissionWithdrawalsView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const TLI = { id: 4, name: 'Thai Life insurance', slug: 'tli' }

function request(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    agent_id: 42,
    agent_name: 'สมชาย ใจดี',
    amount_satang: 250000,
    wht_rate_at_time: null,
    wht_satang: 0,
    net_transfer_satang: 250000,
    status: 'pending_review',
    status_label: 'รอตรวจสอบ',
    rejection_reason: null,
    decided_at: null,
    decided_by: null,
    transferred_at: null,
    transfer_reference: null,
    bank_name: 'กสิกรไทย',
    bank_account_number_masked: '****1234',
    bank_account_holder_name: 'สมชาย ใจดี',
    item_count: 3,
    created_at: '2026-09-14T00:00:00Z',
    ...over,
  }
}

let rows: unknown[] = []

async function mountQueue() {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (String(path).startsWith('/commission-withdrawals')) return { data: rows }
    if (String(path).startsWith('/companies')) return { data: [TLI] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [TLI]
  active.selectedId = TLI.id

  const wrapper = mount(CommissionWithdrawalsView, {
    global: { stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true, CompanyScopeNotice: true } },
  })
  await flushPromises()

  return wrapper
}

function button(wrapper: ReturnType<typeof mount>, label: string) {
  return wrapper.findAll('button').find((b) => b.text() === label)
}

type Wrapper = Awaited<ReturnType<typeof mountQueue>>

/** The ConfirmDialog currently open (it renders with `v-if`), if any. */
function openDialog(w: Wrapper) {
  return w.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
}

/** Press the open dialog's confirm (its last button) or cancel (its first). */
async function answerDialog(w: Wrapper, answer: 'confirm' | 'cancel') {
  const dialog = openDialog(w)
  expect(dialog, 'an open ConfirmDialog').toBeDefined()
  const buttons = dialog!.findAll('button')
  await (answer === 'confirm' ? buttons[buttons.length - 1] : buttons[0])!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  rows = [request()]
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('CommissionWithdrawalsView — ADR-052 save feedback', () => {
  it('approve: dialog only after the POST resolved and the queue was re-read, quoting the SERVER amount', async () => {
    let resolvePost: (v: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((r) => (resolvePost = r)))
    const wrapper = await mountQueue()

    await button(wrapper, 'อนุมัติ')!.trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    rows = []
    resolvePost({ data: request({ status: 'approved', amount_satang: 199900, agent_name: 'สมชาย (เซิร์ฟเวอร์)' }) })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('อนุมัติ')
    expect(saveFeedbackState.body).toContain('สมชาย (เซิร์ฟเวอร์)')
    expect(saveFeedbackState.body).toContain('1,999.00 บาท')
    // re-read: the approved row has left the pending queue
    expect(button(wrapper, 'อนุมัติ')).toBeUndefined()
  })

  it('approve: a refused decision raises no dialog and shows the server’s reason', async () => {
    post.mockRejectedValue(new ApiErrorStub(422, { errors: { status: ['คำขอนี้ถูกตัดสินไปแล้ว'] } }))
    const wrapper = await mountQueue()

    await button(wrapper, 'อนุมัติ')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('คำขอนี้ถูกตัดสินไปแล้ว')
    expect(button(wrapper, 'อนุมัติ')).toBeDefined()
  })

  it('reject: asks for the reason in the row (no browser prompt), then names the payee from the server response', async () => {
    const promptSpy = vi.spyOn(window, 'prompt')
    post.mockResolvedValue({ data: request({ status: 'rejected', agent_name: 'ชื่อที่เก็บไว้' }) })
    const wrapper = await mountQueue()

    await wrapper.get('[data-test="withdrawal-reject-1"]').trigger('click')
    expect(wrapper.find('[data-test="withdrawal-reject-panel-1"]').exists()).toBe(true)
    expect(post).not.toHaveBeenCalled()
    await wrapper.get('[data-test="withdrawal-reject-reason-1"]').setValue('  ยอดไม่ตรง ')
    await wrapper.get('[data-test="withdrawal-reject-submit-1"]').trigger('click')
    await flushPromises()
    // 2026-10-02 — the panel's button asks first; the dialog's confirm sends.
    await answerDialog(wrapper, 'confirm')

    expect(promptSpy).not.toHaveBeenCalled()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/1/reject', { rejection_reason: 'ยอดไม่ตรง' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ไม่อนุมัติ')
    expect(saveFeedbackState.body).toContain('ชื่อที่เก็บไว้')
    expect(wrapper.find('[data-test="withdrawal-reject-panel-1"]').exists()).toBe(false)
  })

  it('reject: will not send an empty reason', async () => {
    const wrapper = await mountQueue()

    await wrapper.get('[data-test="withdrawal-reject-1"]').trigger('click')
    await wrapper.get('[data-test="withdrawal-reject-submit-1"]').trigger('click')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณาระบุเหตุผลที่ไม่อนุมัติ')
    expect(saveFeedbackState.show).toBe(false)
  })

  it('mark transferred: dialog quotes the NET the server recorded', async () => {
    rows = [request({ status: 'approved', wht_satang: 7500, net_transfer_satang: 242500 })]
    const promptSpy = vi.spyOn(window, 'prompt')
    post.mockResolvedValue({
      data: request({ status: 'transferred', amount_satang: 250000, wht_satang: 7500, net_transfer_satang: 242500 }),
    })
    const wrapper = await mountQueue()

    await wrapper.get('[data-test="withdrawal-transfer-1"]').trigger('click')
    // the panel names the NET in front of the admin before anything is sent
    expect(wrapper.get('[data-test="withdrawal-transfer-panel-1"]').text()).toContain('โอนจริง 2,425.00 บาท')
    expect(post).not.toHaveBeenCalled()
    await wrapper.get('[data-test="withdrawal-transfer-reference-1"]').setValue(' TRF-1 ')
    await wrapper.get('[data-test="withdrawal-transfer-submit-1"]').trigger('click')
    await flushPromises()
    await answerDialog(wrapper, 'confirm')

    expect(promptSpy).not.toHaveBeenCalled()

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/1/mark-transferred', { transfer_reference: 'TRF-1' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('2,425.00 บาท')
  })

  it('mark transferred: the reference stays optional — empty sends null', async () => {
    rows = [request({ status: 'approved' })]
    post.mockResolvedValue({ data: request({ status: 'transferred' }) })
    const wrapper = await mountQueue()

    await wrapper.get('[data-test="withdrawal-transfer-1"]').trigger('click')
    await wrapper.get('[data-test="withdrawal-transfer-submit-1"]').trigger('click')
    await flushPromises()
    await answerDialog(wrapper, 'confirm')

    expect(post).toHaveBeenCalledWith('/commission-withdrawals/1/mark-transferred', { transfer_reference: null })
  })

  it('a failed re-read after a successful approve says the screen may be stale', async () => {
    post.mockResolvedValue({ data: request({ status: 'approved' }) })
    const wrapper = await mountQueue()
    get.mockImplementation(async (path: string) => {
      if (String(path).startsWith('/commission-withdrawals')) throw new ApiErrorStub(500, null)

      return { data: [] }
    })

    await button(wrapper, 'อนุมัติ')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
    expect(wrapper.text()).toContain('โหลดข้อมูลไม่สำเร็จ')
  })
})

/*
 * 2026-10-02 (owner decision) — both row panels only ASK. ไม่อนุมัติ opens a
 * `danger` ConfirmDialog; บันทึกการโอน, which cannot be taken back but
 * destroys nothing, opens a `warning` one. Each quotes what was typed and the
 * amount; only the dialog's confirm sends, with exactly the old payload.
 */
describe('CommissionWithdrawalsView — panel actions ask a ConfirmDialog first', () => {
  async function pressReject(reason: string) {
    const wrapper = await mountQueue()
    await wrapper.get('[data-test="withdrawal-reject-1"]').trigger('click')
    await wrapper.get('[data-test="withdrawal-reject-reason-1"]').setValue(reason)
    await wrapper.get('[data-test="withdrawal-reject-submit-1"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  async function pressTransfer(reference: string) {
    rows = [request({ status: 'approved', wht_satang: 7500, net_transfer_satang: 242500 })]
    const wrapper = await mountQueue()
    await wrapper.get('[data-test="withdrawal-transfer-1"]').trigger('click')
    await wrapper.get('[data-test="withdrawal-transfer-reference-1"]').setValue(reference)
    await wrapper.get('[data-test="withdrawal-transfer-submit-1"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  it('reject: the panel button sends nothing and opens a danger dialog with payee, amount and reason', async () => {
    const wrapper = await pressReject('ยอดไม่ตรง')

    expect(post).not.toHaveBeenCalled()
    const dialog = openDialog(wrapper)!
    expect(dialog.props('variant')).toBe('danger')
    expect(dialog.props('title')).toBe('ยืนยันไม่อนุมัติ')
    expect(dialog.props('body')).toBe('ไม่อนุมัติคำขอถอนของ สมชาย ใจดี 2,500.00 บาท — เหตุผล: ยอดไม่ตรง')
  })

  it('reject: an empty reason is still refused before any dialog opens', async () => {
    const wrapper = await pressReject('  ')

    expect(openDialog(wrapper)).toBeUndefined()
    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณาระบุเหตุผลที่ไม่อนุมัติ')
  })

  it('reject: cancel sends nothing and keeps the panel open with the reason', async () => {
    const wrapper = await pressReject('ยอดไม่ตรง')

    await answerDialog(wrapper, 'cancel')

    expect(post).not.toHaveBeenCalled()
    expect(openDialog(wrapper)).toBeUndefined()
    expect((wrapper.get('[data-test="withdrawal-reject-reason-1"]').element as HTMLTextAreaElement).value).toBe('ยอดไม่ตรง')
  })

  it('reject: confirm sends the old payload once, busy while in flight, then the saved dialog', async () => {
    let release: (v: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((r) => (release = r)))
    const wrapper = await pressReject('ยอดไม่ตรง')

    await answerDialog(wrapper, 'confirm')
    expect(openDialog(wrapper)!.props('busy')).toBe(true)
    expect(saveFeedbackState.show).toBe(false)

    rows = []
    release({ data: request({ status: 'rejected', agent_name: 'ชื่อที่เก็บไว้' }) })
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/commission-withdrawals/1/reject', { rejection_reason: 'ยอดไม่ตรง' })
    expect(openDialog(wrapper)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ไม่อนุมัติคำขอถอนของ ชื่อที่เก็บไว้ แล้ว')
  })

  it('reject: a refusal closes the dialog, keeps the panel and the server\'s reason, and raises no saved dialog', async () => {
    post.mockRejectedValue(new ApiErrorStub(422, { errors: { status: ['คำขอนี้ถูกตัดสินไปแล้ว'] } }))
    const wrapper = await pressReject('ยอดไม่ตรง')

    await answerDialog(wrapper, 'confirm')

    expect(openDialog(wrapper)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('คำขอนี้ถูกตัดสินไปแล้ว')
    expect(wrapper.find('[data-test="withdrawal-reject-panel-1"]').exists()).toBe(true)
  })

  it('transfer: the panel button sends nothing and opens a WARNING dialog naming the net and the reference', async () => {
    const wrapper = await pressTransfer('TRF-1')

    expect(post).not.toHaveBeenCalled()
    const dialog = openDialog(wrapper)!
    expect(dialog.props('variant')).toBe('warning')
    expect(dialog.props('title')).toBe('ยืนยันบันทึกการโอนเงิน')
    expect(dialog.props('body')).toContain('บันทึกว่าโอนให้ สมชาย ใจดี แล้ว — โอนจริง 2,425.00 บาท')
    expect(dialog.props('body')).toContain('เลขอ้างอิงการโอน: TRF-1')
  })

  it('transfer: cancel sends nothing and keeps the reference in the panel', async () => {
    const wrapper = await pressTransfer('TRF-1')

    await answerDialog(wrapper, 'cancel')

    expect(post).not.toHaveBeenCalled()
    expect((wrapper.get('[data-test="withdrawal-transfer-reference-1"]').element as HTMLInputElement).value).toBe('TRF-1')
  })

  it('transfer: confirm sends the old payload once, then the saved dialog', async () => {
    post.mockResolvedValue({ data: request({ status: 'transferred', wht_satang: 7500, net_transfer_satang: 242500 }) })
    const wrapper = await pressTransfer(' TRF-1 ')

    await answerDialog(wrapper, 'confirm')

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/commission-withdrawals/1/mark-transferred', { transfer_reference: 'TRF-1' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('2,425.00 บาท')
  })
})
