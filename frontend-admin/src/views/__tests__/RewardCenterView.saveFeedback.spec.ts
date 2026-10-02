/**
 * ADR-052 — RewardCenterView: reward items + redemption queue. Every write
 * ends in one "saved" dialog, raised only after the server answered and the
 * screen shows what it stored.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const patch = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: (...args: unknown[]) => patch(...args),
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

import RewardCenterView from '../RewardCenterView.vue'
import { ApiError } from '@/api/client'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const ITEM = {
  id: 4,
  company_id: 1,
  name: 'แก้วน้ำ',
  description: null,
  cost_points: 100,
  stock_quantity: null,
  is_active: true,
  reward_type: 'physical',
  created_at: '2026-10-01T00:00:00Z',
}
const REDEMPTION = {
  id: 9,
  company_id: 1,
  user_id: 2,
  agent_name: 'สมชาย',
  reward_item_id: 4,
  reward_item_name: 'แก้วน้ำ',
  reward_item_reward_type: 'physical',
  points_spent: 100,
  status: 'pending',
  requested_at: '2026-10-01T00:00:00Z',
  decided_by: null,
  decided_by_name: null,
  decided_at: null,
  decision_note: null,
  shipping_recipient_name: 'สมชาย',
  shipping_phone: '0800000000',
  shipping_address: 'กรุงเทพ',
  tracking_number: null as string | null,
}

let items = [ITEM]
let redemptions = [REDEMPTION]

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((r) => (resolve = r))

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(RewardCenterView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        TransitionGroup: { template: '<div><slot /></div>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>
const button = (w: Wrapper, text: string) => w.findAll('button').find((b) => b.text().trim() === text)!

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

async function openQueue(w: Wrapper) {
  await w.findAll('button').find((b) => b.text().includes('คำขอแลกแต้ม'))!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  items = [ITEM]
  redemptions = [{ ...REDEMPTION }]
  get.mockReset()
  post.mockReset()
  put.mockReset()
  patch.mockReset()
  del.mockReset()
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/reward-items')) return { data: items }
    if (path.startsWith('/reward-redemptions')) return { data: redemptions, meta: { last_page: 1 } }

    return { data: [] }
  })
})

describe('RewardCenterView — ADR-052 save feedback', () => {
  it('editing a reward item raises the dialog only after the server answered, and lists the SERVER name', async () => {
    const w = await mountView()
    await button(w, 'แก้ไข').trigger('click')
    await flushPromises()
    await w.find('form input:not([type])').setValue('  แก้วน้ำใหม่  ')

    const pending = deferred<unknown>()
    put.mockReturnValueOnce(pending.promise)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const stored = { ...ITEM, name: 'แก้วน้ำรุ่นใหม่' }
    items = [stored]
    pending.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกของรางวัล "แก้วน้ำรุ่นใหม่" แล้ว')
    expect(w.text()).toContain('แก้วน้ำรุ่นใหม่')
    expect(w.find('form').exists()).toBe(false)
  })

  it('a failing item save keeps the form open with the error and raises no dialog', async () => {
    const w = await mountView()
    await button(w, 'แก้ไข').trigger('click')
    await flushPromises()
    put.mockRejectedValueOnce(new (ApiError as unknown as new (s: number, m: string) => Error)(422, 'แต้มไม่ถูกต้อง'))

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(w.find('form').exists()).toBe(true)
    expect(w.text()).toContain('แต้มไม่ถูกต้อง')
  })

  it('deleting a reward item asks first, then raises the dialog once the server confirmed', async () => {
    del.mockResolvedValue(null)
    const w = await mountView()

    await button(w, 'ลบ').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/reward-items/4')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบของรางวัล "แก้วน้ำ" แล้ว')
  })

  it('approving a redemption raises the dialog with the status the server stored, after the queue re-read', async () => {
    const w = await mountView()
    await openQueue(w)
    await button(w, 'อนุมัติ').trigger('click')
    await flushPromises()

    post.mockImplementationOnce(async () => {
      redemptions = [{ ...REDEMPTION, status: 'approved' }]

      return { data: { ...REDEMPTION, status: 'approved' } }
    })
    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/reward-redemptions/9/decide', { status: 'approved', decision_note: null })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('คำขอแลก "แก้วน้ำ" ของ สมชาย — อนุมัติแล้ว')
    expect(w.text()).toContain('อนุมัติแล้ว')
  })

  it('a failing decision shows the error and raises no dialog', async () => {
    const w = await mountView()
    await openQueue(w)
    await button(w, 'ปฏิเสธ').trigger('click')
    post.mockRejectedValueOnce(new (ApiError as unknown as new (s: number, m: string) => Error)(409, 'คำขอนี้ถูกตัดสินไปแล้ว'))
    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()
    // 2026-10-02 — a reject asks first; the dialog's confirm sends.
    await answerDialog(w, 'confirm')

    expect(openDialog(w)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('คำขอนี้ถูกตัดสินไปแล้ว')
  })

  it('saving a tracking number shows the number the SERVER stored', async () => {
    redemptions = [{ ...REDEMPTION, status: 'approved' }]
    const w = await mountView()
    await openQueue(w)
    await button(w, 'แก้ไข').trigger('click')
    await w.find('input[placeholder="เลขพัสดุ"]').setValue(' th123 ')
    patch.mockResolvedValueOnce({ data: { ...REDEMPTION, status: 'approved', tracking_number: 'TH123' } })

    await button(w, 'บันทึก').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกเลขพัสดุ TH123 แล้ว')
    expect(w.text()).toContain('เลขพัสดุ: TH123')
  })
})

/*
 * 2026-10-02 (owner decision) — rejecting a redemption from the panel only
 * ASKS: a danger ConfirmDialog names the request and quotes the note; only its
 * confirm sends, with exactly the old payload. Approve still sends at once.
 */
describe('RewardCenterView — a reject asks a ConfirmDialog first', () => {
  async function pressReject(note: string) {
    const w = await mountView()
    await openQueue(w)
    await button(w, 'ปฏิเสธ').trigger('click')
    await w.find('input[placeholder="หมายเหตุ (ไม่บังคับ)"]').setValue(note)
    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    return w
  }

  it('the panel button sends nothing and opens a danger dialog quoting the note', async () => {
    const w = await pressReject('ของหมดสต็อก')

    expect(post).not.toHaveBeenCalled()
    const dialog = openDialog(w)!
    expect(dialog.props('variant')).toBe('danger')
    expect(dialog.props('title')).toBe('ยืนยันปฏิเสธ')
    expect(dialog.props('body')).toBe('ปฏิเสธคำขอแลก "แก้วน้ำ" ของ สมชาย — เหตุผล: ของหมดสต็อก')
  })

  it('cancel sends nothing and keeps the panel open with the note', async () => {
    const w = await pressReject('ของหมดสต็อก')

    await answerDialog(w, 'cancel')

    expect(post).not.toHaveBeenCalled()
    expect(openDialog(w)).toBeUndefined()
    expect((w.find('input[placeholder="หมายเหตุ (ไม่บังคับ)"]').element as HTMLInputElement).value).toBe('ของหมดสต็อก')
  })

  it('confirm sends the old payload once, busy while in flight, then the saved dialog', async () => {
    const pending = deferred<unknown>()
    post.mockReturnValueOnce(pending.promise)
    const w = await pressReject('ของหมดสต็อก')

    await answerDialog(w, 'confirm')
    expect(openDialog(w)!.props('busy')).toBe(true)
    expect(saveFeedbackState.show).toBe(false)

    redemptions = [{ ...REDEMPTION, status: 'rejected' }]
    pending.resolve({ data: { ...REDEMPTION, status: 'rejected' } })
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/reward-redemptions/9/decide', { status: 'rejected', decision_note: 'ของหมดสต็อก' })
    expect(openDialog(w)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('คำขอแลก "แก้วน้ำ" ของ สมชาย — ปฏิเสธแล้ว')
  })

  it('an approval still sends straight from the panel, with no dialog', async () => {
    post.mockResolvedValueOnce({ data: { ...REDEMPTION, status: 'approved' } })
    const w = await mountView()
    await openQueue(w)
    await button(w, 'อนุมัติ').trigger('click')
    await button(w, 'ยืนยัน').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(openDialog(w)).toBeUndefined()
  })
})
