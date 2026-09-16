/**
 * PayoutReportView — รายงานการจ่าย, the read-only record (/commission/runs).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * 2026-09-16 — THIS ROUTE USED TO BE รอบจ่าย, A SECOND WORKING SCREEN.
 *
 * Owner: "การตั้งจ่าย กับรอบจ่าย มันแทบจะแทนกันได้แล้ว … รวมเป็นหน้าเดียวจริงๆ"
 * and, for what was left behind: "หน้าเดิมเป็นสรุปรายการ เป็น Log ที่โอนแล้ว
 * รอโอนโดยบัญชี Filter ได้ Export เป็น CSV ได้ตามที่ Filter".
 *
 * Two things are worth pinning with tests, and they are the two things that
 * would quietly undo the merge if they drifted:
 *
 *   1. THERE IS NO CONTROL HERE THAT WRITES ANYTHING. Not now, not later. The
 *      day an approve button appears on this page we have rebuilt the
 *      duplication the merge removed — so there is a test that fails if one
 *      does.
 *
 *   2. THE TOTALS, THE ROWS AND THE CSV ARE THE SAME SET. They are three reads
 *      of one server query, and every filter has to reach all three. A figure
 *      describing a different set from the rows under it is the defect this
 *      page exists to avoid.
 * ══════════════════════════════════════════════════════════════════════════
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const download = vi.fn()
const post = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: (...args: unknown[]) => download(...args),
  },
  ApiError: class extends Error {
    constructor(public status: number, public body: unknown) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import PayoutReportView from '../PayoutReportView.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'

const TWO_THOUSAND_BAHT = 200_000
const FIFTEEN_HUNDRED_BAHT = 150_000

function makeRequest(over: Record<string, unknown> = {}) {
  return {
    id: 501,
    agent_id: 1,
    agent_name: 'สมชาย ใจดี',
    source: 'company_payout',
    source_label: 'บริษัทตั้งจ่าย',
    amount_satang: TWO_THOUSAND_BAHT,
    status: 'transferred',
    status_label: 'โอนเงินแล้ว',
    rejection_reason: null,
    decided_at: '2026-09-15T00:00:00Z',
    decided_by: 'KreangYot',
    transferred_at: '2026-09-15T00:00:00Z',
    transfer_reference: 'TRF-690915-01',
    bank_name: 'กสิกรไทย',
    bank_account_number_masked: '••••7890',
    bank_account_holder_name: 'สมชาย ใจดี',
    item_count: 2,
    created_at: '2026-09-14T00:00:00Z',
    ...over,
  }
}

const DEFAULT_SUMMARY = {
  total_satang: TWO_THOUSAND_BAHT,
  count: 1,
  transferred_satang: TWO_THOUSAND_BAHT,
  transferred_count: 1,
  outstanding_satang: 0,
  outstanding_count: 0,
  payee_count: 1,
}

/** Every URL the page asked for, in order — the filter assertions read this. */
function urlsFor(fragment: string): string[] {
  return get.mock.calls.map(([p]) => String(p)).filter((p) => p.includes(fragment))
}

function wireApi(rows = [makeRequest()], summary = DEFAULT_SUMMARY, summaryFails = false) {
  get.mockImplementation((path: string) => {
    const p = String(path)

    if (p.includes('/report/summary')) {
      return summaryFails
        ? Promise.reject(new Error('boom'))
        : Promise.resolve({ data: summary })
    }
    if (p.includes('/commission-withdrawals/report')) {
      return Promise.resolve({
        data: rows,
        meta: { total: rows.length, current_page: 1, last_page: 1 },
      })
    }

    throw new Error(`unexpected GET ${p}`)
  })
}

async function mountView(rows = [makeRequest()], summary = DEFAULT_SUMMARY, summaryFails = false) {
  wireApi(rows, summary, summaryFails)
  const wrapper = mount(PayoutReportView)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  download.mockReset()
  download.mockResolvedValue(undefined)
})

describe('it is a record, not a second queue', () => {
  it('has no control anywhere on it that writes anything', async () => {
    /*
     * THE ONE THAT MATTERS. This page exists because two working screens had
     * become interchangeable; the thing that keeps them apart is that only one
     * of them can change anything. A write button here is how that erodes.
     *
     * Asserted against the API doubles rather than against button labels: a
     * differently-worded approve button would still fail this.
     */
    const wrapper = await mountView([
      makeRequest({ id: 601, status: 'pending_review', status_label: 'รอตรวจสอบ', transferred_at: null }),
      makeRequest({ id: 602, status: 'approved', status_label: 'อนุมัติแล้ว รอโอน', transferred_at: null }),
    ])

    for (const button of wrapper.findAll('button')) {
      await button.trigger('click')
    }
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(put).not.toHaveBeenCalled()
  })

  it('points back at the screen that can change things', async () => {
    // Somebody who spots a problem in the record needs somewhere to go, or the
    // read-only rule reads as a dead end rather than as a division of labour.
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="report-back-to-work"]').exists()).toBe(true)
  })

  it('shows statuses the working screen has no room for', async () => {
    // Rejected and cancelled rows have no step on the working screen — nothing
    // will ever happen to them again — and they are exactly what somebody opens
    // a report to find.
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="report-status-rejected"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="report-status-cancelled"]').exists()).toBe(true)
  })
})

describe('the filters reach the server, not just the browser', () => {
  it('sends the statuses that are lit', async () => {
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-status-transferred"]').trigger('click')
    await flushPromises()

    const last = urlsFor('/commission-withdrawals/report?').pop() ?? ''

    expect(last).toContain('statuses%5B%5D=transferred')
  })

  it('combines several statuses rather than replacing one with the next', async () => {
    // They are search conditions, not steps: unordered and combinable. A chip
    // row that behaved like a tab strip would make "รอโอน + โอนแล้ว" unaskable.
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-status-transferred"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="report-status-approved"]').trigger('click')
    await flushPromises()

    const last = urlsFor('/commission-withdrawals/report?').pop() ?? ''

    expect(last).toContain('statuses%5B%5D=transferred')
    expect(last).toContain('statuses%5B%5D=approved')
  })

  it('treats ทั้งหมด as a state of its own, not as an absence', async () => {
    /*
     * With no chip lit the table is unfiltered — and a row of unlit chips reads
     * as "no results yet" on a page whose whole job is to say how much money is
     * in a set. So ทั้งหมด is lit instead, and pressing it clears the rest.
     */
    const wrapper = await mountView()

    expect(wrapper.get('[data-test="report-status-all"]').classes().join(' ')).toContain('bg-brand-700')

    await wrapper.get('[data-test="report-status-transferred"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="report-status-all"]').classes().join(' ')).not.toContain('bg-brand-700')

    await wrapper.get('[data-test="report-status-all"]').trigger('click')
    await flushPromises()

    const last = urlsFor('/commission-withdrawals/report?').pop() ?? ''
    expect(last).not.toContain('statuses')
  })

  it('sends which date the window is counted from', async () => {
    /*
     * THE ONE THAT MATTERS about dates. A payout raised in August and
     * transferred in September is in both months depending on the question, so
     * the basis travels with the window instead of being assumed.
     */
    const wrapper = await mountView()

    wrapper.findComponent(DateRangeFilter).vm.$emit('update:dateFrom', '2026-09-01')
    await flushPromises()
    await wrapper.get('[data-test="report-apply"]').trigger('click')
    await flushPromises()

    expect(urlsFor('/commission-withdrawals/report?').pop() ?? '').toContain('date_basis=transferred_at')

    await wrapper.get('[data-test="report-basis-created_at"]').trigger('click')
    await flushPromises()

    expect(urlsFor('/commission-withdrawals/report?').pop() ?? '').toContain('date_basis=created_at')
  })

  it('says out loud that unsent money is outside a transfer-date window', async () => {
    // Correct, not a gap — but a reader looking for a payout they raised last
    // week would otherwise conclude the report had lost it.
    const wrapper = await mountView()

    wrapper.findComponent(DateRangeFilter).vm.$emit('update:dateFrom', '2026-09-01')
    await flushPromises()

    expect(wrapper.get('[data-test="report-basis-note"]').text()).toContain('ยังไม่ได้โอน')
  })

  it('sends the payee search to the server rather than filtering the page', async () => {
    // The list is paginated. Filtering in the browser would search the fifty
    // rows on screen and report "ไม่พบ" for somebody on page two.
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-search"]').setValue('สมหญิง')
    await wrapper.get('[data-test="report-search"]').trigger('change')
    await flushPromises()

    expect(urlsFor('/commission-withdrawals/report?').pop() ?? '').toContain('q=')
  })
})

describe('the totals, the rows and the file are one set', () => {
  it('asks for the totals with the same filters as the rows', async () => {
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-status-rejected"]').trigger('click')
    await flushPromises()

    const rowsUrl = urlsFor('/commission-withdrawals/report?').pop() ?? ''
    const totalsUrl = urlsFor('/report/summary').pop() ?? ''

    expect(rowsUrl).toContain('statuses%5B%5D=rejected')
    expect(totalsUrl).toContain('statuses%5B%5D=rejected')
  })

  it('exports with the same filters too', async () => {
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-status-rejected"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="report-export"]').trigger('click')
    await flushPromises()

    expect(String(download.mock.calls[0]?.[0] ?? '')).toContain('statuses%5B%5D=rejected')
  })

  it('prints the totals of the filtered set and says how many rows the file will have', async () => {
    const wrapper = await mountView([makeRequest()], {
      total_satang: 350_000,
      count: 2,
      transferred_satang: TWO_THOUSAND_BAHT,
      transferred_count: 1,
      outstanding_satang: FIFTEEN_HUNDRED_BAHT,
      outstanding_count: 1,
      payee_count: 2,
    })

    const totals = wrapper.get('[data-test="report-totals"]').text()

    expect(totals).toContain('3,500 บาท')
    expect(totals).toContain('2,000 บาท')
    expect(totals).toContain('1,500 บาท')
    expect(totals).toContain('CSV จะได้ 2 แถว')
  })

  it('refuses to print zeros when the totals could not be read', async () => {
    /*
     * A bar showing "0 บาท" over a table full of rows is a statement that no
     * money matched a filter the rows underneath it are listing. The rows still
     * render; the bar says it failed.
     */
    const wrapper = await mountView([makeRequest()], DEFAULT_SUMMARY, true)

    expect(wrapper.find('[data-test="report-totals"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="report-totals-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="report-row-501"]').exists()).toBe(true)
  })

  it('goes back to page one whenever the set changes', async () => {
    // Page four of a different set is a different set's page four.
    const wrapper = await mountView()

    await wrapper.get('[data-test="report-status-transferred"]').trigger('click')
    await flushPromises()

    expect(urlsFor('/commission-withdrawals/report?').pop() ?? '').toContain('page=1')
  })
})

describe('what a row shows', () => {
  it('shows both dates, because they answer different questions', async () => {
    const wrapper = await mountView()

    const row = wrapper.get('[data-test="report-row-501"]').text()

    expect(row).toContain('14 ก.ย.')
    expect(row).toContain('15 ก.ย.')
  })

  it('shows the transfer reference and who approved it', async () => {
    // What a record is for: reconciling a bank statement against a decision
    // somebody made and can be asked about.
    const wrapper = await mountView()

    const row = wrapper.get('[data-test="report-row-501"]').text()

    expect(row).toContain('TRF-690915-01')
    expect(row).toContain('KreangYot')
  })

  it('shows the refusal reason on a rejected row', async () => {
    const wrapper = await mountView([
      makeRequest({
        id: 502,
        status: 'rejected',
        status_label: 'ไม่อนุมัติ',
        rejection_reason: 'ยอดไม่ตรงกับที่ตรวจสอบ',
        transferred_at: null,
      }),
    ])

    expect(wrapper.get('[data-test="report-row-502"]').text()).toContain('ยอดไม่ตรงกับที่ตรวจสอบ')
  })

  it('never shows a full account number', async () => {
    /*
     * Deliberately unlike the bank file on the working screen, which carries
     * full numbers because it is an instruction somebody pays people from. This
     * is a record that gets filed, mailed and forwarded.
     */
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('••••7890')
    expect(wrapper.text()).not.toContain('1234567890')
  })

  it('says which account the masked number is', async () => {
    // A snapshot taken when the request was raised, not the agent's account
    // today — so a reader comparing it against a profile is not looking at a
    // discrepancy.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('บันทึกไว้ตอนยื่นใบนั้น')
  })
})

describe('when there is nothing to show', () => {
  it('distinguishes an empty filter from an empty system', async () => {
    const fresh = await mountView([])
    expect(fresh.get('[data-test="report-empty"]').text()).toContain('ยังไม่มีการจ่ายค่าแนะนำ')

    const filtered = await mountView([])
    await filtered.get('[data-test="report-status-rejected"]').trigger('click')
    await flushPromises()

    expect(filtered.get('[data-test="report-empty"]').text()).toContain('ไม่มีใบที่ตรงกับตัวกรองนี้')
  })
})
