/**
 * ADR-052 — สรุปค่าแนะนำ: saving an agent's bank account ends in the one
 * global "saved" dialog. The audit found the old version built its sentence
 * from the LOCAL payload ("บันทึกสำเร็จ — เลขที่บัญชี <what was typed>") and
 * left the still-open form holding the typed value — so a number the server
 * normalised, or never stored, looked saved. Both now come from the server.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
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
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import AgentCommissionSummaryView from '../AgentCommissionSummaryView.vue'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

function row(over: Record<string, unknown> = {}) {
  return {
    agent_id: 1,
    agent_name: 'สมชาย',
    total_paid_satang: 150_000,
    total_pending_satang: 200_000,
    entry_count: 3,
    bank_name: 'กสิกรไทย',
    bank_account_number: '1111111111',
    bank_account_holder_name: 'สมชาย ใจดี',
    avatar_url: null,
    cert_tier: null,
    ...over,
  }
}

let rows: unknown[] = []
let failSummaryRead = false

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(AgentCommissionSummaryView)
  await flushPromises()
  await wrapper.find('[data-test="open-bank-edit"]').trigger('click')

  return wrapper
}

const numberInput = (w: Awaited<ReturnType<typeof mountView>>) =>
  w.find('[data-test="bank-number"]').element as HTMLInputElement

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  rows = [row()]
  failSummaryRead = false
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/agent-commission-summary')) {
      if (failSummaryRead) throw new FakeApiError(500, null)

      return { data: rows, computed_at: '2026-10-01T00:00:00Z' }
    }
    throw new Error(`unexpected GET ${path}`)
  })
})

describe('AgentCommissionSummaryView — ADR-052 bank account save', () => {
  it('raises the dialog after the save resolved, quoting the SERVER\'s number, and re-fills the form from the re-read row', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-test="bank-number"]').setValue('222-2-22222-2')

    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    await wrapper.find('[data-test="save-bank"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server normalised the dashes away.
    rows = [row({ bank_account_number: '2222222222' })]
    pending.resolve({ data: { bank_name: 'กสิกรไทย', bank_account_number: '2222222222', bank_account_holder_name: 'สมชาย ใจดี' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('2222222222')
    expect(saveFeedbackState.body).not.toContain('222-2-22222-2')
    // The still-open panel holds the server's copy, not the typed one.
    expect(numberInput(wrapper).value).toBe('2222222222')
    expect(wrapper.find('[data-test="bank-current"]').text()).toContain('2222222222')
    // The old inline "บันทึกสำเร็จ — เลขที่บัญชี …" line is gone.
    expect(wrapper.text()).not.toContain('บันทึกสำเร็จ')
  })

  it('shows the error and raises no dialog when the save is refused', async () => {
    put.mockRejectedValue(new FakeApiError(422, null))
    const wrapper = await mountView()
    await wrapper.find('[data-test="bank-number"]').setValue('999')
    await wrapper.find('[data-test="save-bank"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="page-error"]').text()).toContain('บันทึกบัญชีธนาคารไม่สำเร็จ (422)')
    expect(wrapper.find('[data-test="bank-current"]').text()).toContain('1111111111')
  })

  it('says the screen may be behind when the save landed but the summary could not be re-read, and still shows the server\'s copy', async () => {
    put.mockImplementation(async () => {
      failSummaryRead = true

      return { data: { bank_name: 'กสิกรไทย', bank_account_number: '3333333333', bank_account_holder_name: 'สมชาย ใจดี' } }
    })
    const wrapper = await mountView()
    await wrapper.find('[data-test="bank-number"]').setValue('333-333-3333')
    await wrapper.find('[data-test="save-bank"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
    expect(numberInput(wrapper).value).toBe('3333333333')
  })
})
