/**
 * รอบจ่าย — the payout queue panel (/commission/runs).
 *
 * Owner: "ระบบเรามีข้อจำกัดในการโอนเงินไปให้ Agent เราใช้วิธีโอนเองผ่านระบบการ
 * ทำงาน Bank เราไม่ได้ Payment auto ซึ่งต้องได้รับข้อมูลจากฝ่ายบัญชีก่อนว่าโอนแล้ว
 * จึงมากดยืนยัน."
 *
 * This queue existed, modelled that three-step process correctly, and was
 * always empty — because the payout screen in a different menu settled the
 * same commission rows in one click before an agent could ever ask for them.
 * Both routes now raise the same object.
 *
 * Where it LIVES has moved twice since (own menu → tab → own route again);
 * none of that is tested here, because none of it changed the panel. The menu
 * and the routes are pinned in CommissionNavigation.spec.ts. What is pinned
 * here is what the panel says:
 *
 *   · EVERY ROW SAYS WHOSE DECISION IT WAS. In รอโอน an agent's approved
 *     request and a payout the company raised sit together and answer to
 *     different people.
 *   · THE EMPTY STATE TELLS THE TRUTH FOR THE NEW WORLD. Its previous
 *     wording — "settling rows on จ่ายเงิน takes them out of what an agent
 *     can ask for" — was correct yesterday and is now the opposite of what
 *     happens.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

const routeQuery: { value: Record<string, unknown> } = { value: {} }

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: routeQuery.value }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CommissionPayoutQueuePanel from '../CommissionPayoutQueuePanel.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const TLI = { id: 4, name: 'Thai Life insurance', slug: 'tli' }

function payout(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    agent_id: 42,
    agent_name: 'สมชาย ใจดี',
    source: 'agent_request',
    source_label: 'ตัวแทนขอเบิก',
    amount_satang: 250000,
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

/** What GET /commission-withdrawals/summary answers, per test. */
let summaryRows: Record<string, { count: number; satang: number }> = {}

function wireApi(rows: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (String(path).startsWith('/commission-withdrawals/summary')) return { data: summaryRows }
    if (String(path).startsWith('/commission-withdrawals')) return { data: rows }
    if (String(path).startsWith('/agent-commission-summary')) return { data: [], computed_at: '2026-09-15T00:00:00Z' }
    if (String(path).startsWith('/companies')) return { data: [TLI] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [TLI]
  active.selectedId = TLI.id
}

async function mountPanel(rows: unknown[] = []) {
  wireApi(rows)
  const wrapper = mount(CommissionPayoutQueuePanel, {
    global: { stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  routeQuery.value = {}
  summaryRows = {
    pending_review: { count: 1, satang: 90000 },
    approved: { count: 2, satang: 245000 },
    transferred: { count: 9, satang: 1248000 },
  }
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('every row says whose decision it was', () => {
  it('marks a payout the company raised', async () => {
    const wrapper = await mountPanel([payout({
      id: 7,
      source: 'company_payout',
      source_label: 'บริษัทตั้งจ่าย',
      status: 'approved',
      status_label: 'อนุมัติแล้ว รอโอน',
    })])

    expect(wrapper.get('[data-test="payout-source-7"]').text()).toBe('บริษัทตั้งจ่าย')
  })

  it('marks one the agent asked for', async () => {
    // Same tab, different author. In รอโอน the two sit together and are
    // answerable to different people.
    const wrapper = await mountPanel([payout({ id: 8 })])

    expect(wrapper.get('[data-test="payout-source-8"]').text()).toBe('ตัวแทนขอเบิก')
  })
})

describe('an empty queue says the one thing the numbers cannot', () => {
  it('names both doors, in one sentence', async () => {
    /*
     * This used to be four bullets and was the biggest thing on the screen —
     * an explainer outweighing the work, which is what the owner was looking
     * at when they said the page never told them what to do. The rail now
     * carries the numbers; this carries the fact they cannot: nothing lands
     * here by itself.
     */
    const wrapper = await mountPanel([])

    const help = wrapper.get('[data-test="withdrawal-empty-help"]').text()
    expect(help).toContain('ตัวแทนกดขอเบิก')
    expect(help).toContain('ตั้งจ่าย')
  })

  it('offers the way out instead of leaving the reader at a dead end', async () => {
    // An empty queue on a money screen is a moment where the reader has to
    // go somewhere; ตั้งจ่าย is the only place a payout can be started.
    const wrapper = await mountPanel([])

    expect(wrapper.find('[data-test="link-payouts"]').exists()).toBe(true)
  })

  it('gets out of the way once there is a queue', async () => {
    const wrapper = await mountPanel([payout()])

    expect(wrapper.find('[data-test="withdrawal-empty-help"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('สมชาย ใจดี')
  })
})

/**
 * 2026-09-15 (แบบ A) — THE THREE-STEP RAIL.
 *
 * Owner: "มันดูแล้วไม่เข้าใจทันทีว่า User เข้ามาต้องทำอะไร ดูอะไรบ้าง".
 *
 * Four flat chips became a band carrying each step's count and money. The
 * figures are server-computed on purpose — this panel loads one status at a
 * time, twenty rows at a time, so anything counted here would describe the
 * PAGE while being read as describing the STEP.
 */
describe('the rail', () => {
  it('shows the money and the count in every step', async () => {
    const wrapper = await mountPanel([payout()])

    const pending = wrapper.get('[data-test="queue-step-pending_review"]').text()
    expect(pending).toContain('900.00 บาท')
    expect(pending).toContain('1 รายการ')

    expect(wrapper.get('[data-test="queue-step-approved"]').text()).toContain('2,450.00 บาท')
    expect(wrapper.get('[data-test="queue-step-transferred"]').text()).toContain('12,480.00 บาท')
  })

  it('marks the step being read', async () => {
    const wrapper = await mountPanel([payout()])

    expect(wrapper.get('[data-test="queue-step-pending_review"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-test="queue-step-approved"]').attributes('aria-pressed')).toBe('false')
  })

  it('filters the list when a step is pressed', async () => {
    // The chips it replaced were filters; that has to survive the redraw, or
    // the band is decoration.
    const wrapper = await mountPanel([payout()])

    await wrapper.get('[data-test="queue-step-approved"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith(expect.stringContaining('status=approved'))
  })

  it('hides itself rather than printing zeros it could not read', async () => {
    /*
     * THE ONE THAT MATTERS. "รอโอน 0 บาท" is a statement that nothing is
     * owed, on the screen where somebody decides whether a payout run is
     * finished. A failed read must not be able to say that.
     */
    get.mockImplementation(async (path: string) => {
      if (String(path).startsWith('/commission-withdrawals/summary')) throw new Error('500')
      if (String(path).startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
      if (String(path).startsWith('/commission-withdrawals')) return { data: [payout()] }

      return { data: [] }
    })
    const active = useActiveCompanyStore()
    active.companies = [TLI]
    active.selectedId = TLI.id

    const wrapper = mount(CommissionPayoutQueuePanel, {
      global: { stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true } },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="queue-rail"]').exists()).toBe(false)
    // find(), not get(): get()'s wrapper has `exists` removed from its type,
    // and this is the third time that has reached a type-check this week.
    expect(wrapper.find('[data-test="queue-rail-error"]').exists()).toBe(true)
    // Asserted as the ABSENCE of the band, not as the absence of the string
    // "0 บาท" — every amount on the page ends in one (2,500.00 บาท), so that
    // spelling matched the rows it was meant to ignore.
    expect(wrapper.findAll('[data-test^="queue-step-"]').filter((b) => b.text().includes('รายการ'))).toHaveLength(0)
    // The queue underneath is unaffected — the band is context, not the work.
    expect(wrapper.text()).toContain('สมชาย ใจดี')
  })

  it('says what to DO with the step, not just what it is called', async () => {
    const wrapper = await mountPanel([payout()])

    expect(wrapper.get('[data-test="queue-instruction"]').text()).toContain('อนุมัติ')
  })
})
