/**
 * SalesTeamView + SalesTeamCard — TASK-179 §3.6 / §4.1 / §4.4.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Three things on the "ทีมขาย" cockpit were arithmetic nobody had checked:
 *
 *  1. ลูกค้ารวม was `SUM(per-agent client_count)`. A client referred by two
 *     agents appears on both cards, so the header could report MORE clients
 *     than the company has — and TASK-049 exists precisely because one client
 *     under several agents is a first-class scenario here, not an edge case.
 *     No amount of adding the cards up produces the right number; it has to
 *     come from `meta.clients_total`. The test below therefore feeds cards
 *     whose counts deliberately do NOT sum to the meta figure: an
 *     implementation that adds them up prints a different number and fails.
 *
 *  2. The per-stage strip on each card read five NAMED keys off a five-key
 *     interface (`salesTeam.ts`'s STAGE_ORDER — the second of the three
 *     copies of §4.3's medical stage list that TASK-179 §4.1 deletes). Since
 *     ADR-026 the server sends eight, so three stages were dropped silently.
 *
 *  3. อัตราปิดรวม printed "0.0%" for a company with no deals — a ratio with
 *     no denominator, rendered as a measured result (§4.4).
 *
 * The API is mocked at `@/api/client`; the drawer, the cert-grant and the
 * edit modal are other tasks' concerns and are not exercised here.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

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
    put: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: FakeApiError,
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ query: {} }),
}))

// The full agent editor fetches its own lookups on open; it is never opened
// here and is a different task's surface.
vi.mock('../AgentEditModal.vue', async () => {
  const { h } = await import('vue')
  return { default: { name: 'AgentEditModalStub', props: ['agentId'], render: () => h('div') } }
})

import SalesTeamView from '../SalesTeamView.vue'
import { PIPELINE_STAGE_LABELS_TH } from '@/utils/pipelineStages'

// ── Fixtures ────────────────────────────────────────────────────────────

/** All eight PipelineStage cases, as AgentSalesAggregateService sends them. */
const ALL_EIGHT_STAGES: Record<string, number> = {
  complete_registered: 3,
  waiting_appointment: 2,
  finish_1st_doctor_meeting: 1,
  complete_payment: 4,
  ongoing_next_meeting: 0,
  delivery: 5,
  service_appointment: 6,
  follow_up: 7,
}

interface AgentOverrides {
  agent_id?: number
  agent_name?: string
  client_count?: number
  total_deals?: number
  closed_deals?: number
  closed_deals_without_order?: number
  deals_by_stage?: Record<string, number>
}

function makeAgent(overrides: AgentOverrides = {}) {
  return {
    agent_id: overrides.agent_id ?? 1,
    agent_name: overrides.agent_name ?? 'สมชาย',
    agent_email: 'a@x.co',
    agent_phone: '0800000000',
    manager_id: null,
    // TASK-125 — the page opens on the หัวหน้าทีม tab, and partitionRoots()
    // sends an agent with no flag and no reports to the ตัวแทนอิสระ tab
    // instead. Flagged by default so the card under test is actually on
    // screen; the tab split itself is TASK-125's concern, not this file's.
    is_team_leader: true,
    avatar_url: null,
    client_count: overrides.client_count ?? 5,
    deals_by_stage: overrides.deals_by_stage ?? ALL_EIGHT_STAGES,
    total_deals: overrides.total_deals ?? 28,
    closed_deals: overrides.closed_deals ?? 7,
    closed_deals_without_order: overrides.closed_deals_without_order ?? 0,
    conversion: 25,
    total_sales_satang: 100_000,
    total_commission_satang: 10_000,
  }
}

function wireApi(agents: ReturnType<typeof makeAgent>[], clientsTotal: number | undefined) {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/sales-team-overview')) {
      return Promise.resolve(
        clientsTotal === undefined
          ? { data: agents }
          : { data: agents, meta: { clients_total: clientsTotal } },
      )
    }
    if (path.startsWith('/cert-tiers')) return Promise.resolve({ data: [] })
    if (path.startsWith('/user-certifications')) return Promise.resolve({ data: [] })
    throw new Error(`unexpected GET ${path}`)
  })
}

async function mountView(agents: ReturnType<typeof makeAgent>[], clientsTotal: number | undefined = 9) {
  wireApi(agents, clientsTotal)
  const wrapper = mount(SalesTeamView)
  await flushPromises()
  return wrapper
}

/**
 * Open every agent row and return the page text.
 *
 * ── WHY THIS HELPER APPEARED (2026-08-22) ──
 *
 * The top level became a TABLE (human: "ตอนนี้ดูยากมาก" — six cards of ~25
 * numbers each, none comparable to another). The per-stage counts, the money
 * LABELS and the uncounted-deals sentence moved into the row's expansion,
 * which is SalesTeamCard in compact mode.
 *
 * Four cases below started failing on that change, and that is the system
 * working exactly as intended: they were written so a redesign could not
 * quietly drop the rules they defend. The rules are unchanged and still
 * enforced — only the click needed to reach them is new. Nothing here was
 * weakened to make the redesign pass.
 */
async function expandedText(wrapper: ReturnType<typeof mount>): Promise<string> {
  for (const row of wrapper.findAll('tbody tr')) {
    await row.trigger('click')
  }
  await flushPromises()

  return wrapper.text()
}

beforeEach(() => {
  get.mockReset()
})

// ════════════════════════════════════════════════════════════════════════
// §3.6 (F-15) — ลูกค้ารวม comes from the server, never from adding the cards
// ════════════════════════════════════════════════════════════════════════
describe('ลูกค้ารวม header KPI', () => {
  it('uses meta.clients_total, NOT the sum of the per-agent counts', async () => {
    // Two agents share clients: 5 + 6 = 11 cards' worth, 9 real people.
    const wrapper = await mountView(
      [
        makeAgent({ agent_id: 1, client_count: 5 }),
        makeAgent({ agent_id: 2, agent_name: 'สมหญิง', client_count: 6 }),
      ],
      9,
    )

    const kpiRow = wrapper.text()
    expect(kpiRow).toContain('ลูกค้ารวม')
    expect(kpiRow).toContain('9')
    // 11 is the sum-the-cards answer, and it is wrong.
    expect(kpiRow).not.toContain('ลูกค้ารวม11')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.1 (F-4, BR-7) — the card's stage strip follows the server
// ════════════════════════════════════════════════════════════════════════
describe('per-agent stage strip', () => {
  it('renders ALL eight stages the server sent, with their Thai labels', async () => {
    const wrapper = await mountView([makeAgent()])
    const text = await expandedText(wrapper)

    for (const key of Object.keys(ALL_EIGHT_STAGES)) {
      expect(text).toContain(PIPELINE_STAGE_LABELS_TH[key])
    }
  })

  it('renders exactly the stages of a SHORT template — no padding to five', async () => {
    const wrapper = await mountView([
      makeAgent({ deals_by_stage: { complete_registered: 2, complete_payment: 1 }, total_deals: 3 }),
    ])
    const text = await expandedText(wrapper)

    expect(text).toContain(PIPELINE_STAGE_LABELS_TH.complete_registered)
    expect(text).toContain(PIPELINE_STAGE_LABELS_TH.complete_payment)
    // A five-element hardcoded list would print these three anyway, at 0.
    expect(text).not.toContain(PIPELINE_STAGE_LABELS_TH.waiting_appointment)
    expect(text).not.toContain(PIPELINE_STAGE_LABELS_TH.finish_1st_doctor_meeting)
    expect(text).not.toContain(PIPELINE_STAGE_LABELS_TH.ongoing_next_meeting)
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.4 — a ratio with no denominator is not 0.0%
// ════════════════════════════════════════════════════════════════════════
describe('อัตราปิดรวม', () => {
  it('says ยังไม่มีข้อมูล when the company has no deals at all', async () => {
    const wrapper = await mountView(
      [makeAgent({ total_deals: 0, closed_deals: 0, deals_by_stage: { complete_registered: 0 } })],
      0,
    )

    expect(wrapper.text()).toContain('ยังไม่มีข้อมูล')
    expect(wrapper.text()).not.toContain('0.0%')
  })

  it('still prints the real percentage when there ARE deals', async () => {
    const wrapper = await mountView([makeAgent({ total_deals: 28, closed_deals: 7 })])

    expect(wrapper.text()).toContain('25.0%')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §4.2 (D1/D2) — the card's ยอดขาย says what it is, and discloses what it
// could not count. This is the TASK-179 blocker: the dashboard was fixed and
// this identically-labelled figure was not, so one company had two "ยอดขาย".
// ════════════════════════════════════════════════════════════════════════
describe('the card money labels', () => {
  it('labels the sale figure ยอดขาย with no (จ่ายแล้ว) — that reads as a payout', async () => {
    const wrapper = await mountView([makeAgent()])
    const text = wrapper.text()

    expect(text).toContain('ยอดขาย')
    expect(text).not.toContain('ยอดขาย (จ่ายแล้ว)')
    // The COMMISSION figure beside it keeps its suffix: for that number
    // "จ่ายแล้ว" is true and load-bearing (pending commission is excluded).
    expect(text).toContain('ค่าคอม (จ่ายแล้ว)')
  })
})

describe('per-agent closed_deals_without_order disclosure', () => {
  it('states it as a sentence when the server reports any', async () => {
    const wrapper = await mountView([makeAgent({ closed_deals_without_order: 4 })])

    expect(await expandedText(wrapper)).toContain('อีก 4 ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ')
  })

  it('says NOTHING when it is 0 — a permanent caveat is an ignored caveat', async () => {
    const wrapper = await mountView([makeAgent({ closed_deals_without_order: 0 })])

    expect(wrapper.text()).not.toContain('ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ')
  })
})

// ════════════════════════════════════════════════════════════════════════
// §3.5 (F-8) — the roster label names the set it actually contains
// ════════════════════════════════════════════════════════════════════════
describe('agent-count label', () => {
  it('says ตัวแทนที่ใช้งานอยู่ — the endpoint excludes deactivated agents', async () => {
    const wrapper = await mountView([makeAgent()])

    expect(wrapper.text()).toContain('ตัวแทนที่ใช้งานอยู่')
    expect(wrapper.text()).not.toContain('ตัวแทนทั้งหมด')
  })
})
