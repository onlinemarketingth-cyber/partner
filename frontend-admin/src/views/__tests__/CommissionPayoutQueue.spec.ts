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

function wireApi(rows: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
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

describe('an empty queue explains the new world, not the old one', () => {
  it('names both doors', async () => {
    const wrapper = await mountPanel([])

    const help = wrapper.get('[data-test="withdrawal-empty-help"]').text()
    expect(help).toContain('ตัวแทนขอเบิก')
    expect(help).toContain('บริษัทตั้งจ่าย')
  })

  it('says where the money is actually closed, and when the agent hears', async () => {
    /*
     * The step the whole change exists for: the commission is not settled and
     * the agent is not told anything about money arriving until somebody
     * records the real bank transfer.
     */
    const wrapper = await mountPanel([])

    const help = wrapper.get('[data-test="withdrawal-empty-help"]').text()
    expect(help).toContain('บันทึกว่าโอนแล้ว')
    expect(help).toContain('อีเมล')
  })

  it('no longer claims the queue can be empty forever', async () => {
    // Yesterday's true sentence, today's false one: ตั้งจ่าย raises a payout
    // INTO this queue rather than closing the rows behind it.
    const wrapper = await mountPanel([])

    expect(wrapper.get('[data-test="withdrawal-empty-help"]').text()).not.toContain('ไม่ใช่ระบบเสีย')
  })

  it('gets out of the way once there is a queue', async () => {
    const wrapper = await mountPanel([payout()])

    expect(wrapper.find('[data-test="withdrawal-empty-help"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('สมชาย ใจดี')
  })
})
