/**
 * PipelineBoard — the cross-client stage board, as moved out of
 * PipelineView.vue by TASK-169 Phase 3.
 *
 * EVERY assertion here exists to catch ONE regression: someone "simplifying"
 * this board back into a fixed five-column medical Kanban. That is the exact
 * bug ADR-026 exists to prevent, it throws no error, and it looks fine on a
 * demo tenant that only sells medical packages.
 *
 * The four things the board derives CLIENT-SIDE from each referral's own
 * `pipeline.stages` — and which a fixed stage list would therefore get wrong
 * — are what is asserted:
 *
 *  1. JOURNEY GROUPING. Referrals on different templates get their own
 *     section (ADR-026 §4: "the board is filtered per template, or
 *     grouped"). One shared column set would merge them.
 *  2. THE STAGE SUB-MENU is derived from the referrals in the selected
 *     status bucket (TASK-171). A hardcoded five would never show จัดส่ง /
 *     นัดใช้บริการ / ติดตามผล, so a referral parked on a post-sale stage
 *     becomes unreachable.
 *  3. STAGE ORDER inside a group comes from THAT journey's sequence.
 *  4. THE BR-4 "ชำระเงินแล้ว" KPI compares indices WITHIN the referral's own
 *     journey. `complete_payment` is position 3 on the medical journey and
 *     position 1 on a direct sale, and a referral sitting at จัดส่ง has very
 *     much already paid — a fixed list scores that one as unpaid.
 *
 * The state machine itself is NOT tested here and must not be: advancing
 * posts to /referrals/{id}/advance with no body and the backend picks the one
 * legal next stage and writes the audit log (§4.3). What is asserted is that
 * the board never OFFERS a move the server did not say was available.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import PipelineBoard from '../PipelineBoard.vue'
import TabFilterBar from '../TabFilterBar.vue'
import ShareLinkModal from '../ShareLinkModal.vue'

// jsdom implements no scrolling at all. TabFilterBar centres the active tab
// with el.scrollTo() whenever a bar has more than 3 tabs (its scrolling
// layout — which the stage axis always is once two templates are loaded), and
// the resulting rejection is unhandled, not caught by the component.
Element.prototype.scrollTo = function () {} as Element['scrollTo']

interface Stage {
  key: string
  label: string
}

/** The seeded `medical_package_default` template (CLAUDE.md §4.3). */
const MEDICAL: Stage[] = [
  { key: 'complete_registered', label: 'Complete Registered' },
  { key: 'waiting_appointment', label: 'Waiting Appointment' },
  { key: 'finish_1st_doctor_meeting', label: 'Finish 1st Doctor Meeting' },
  { key: 'complete_payment', label: 'Complete Payment' },
  { key: 'ongoing_next_meeting', label: 'Ongoing Next Meeting' },
]

/** The seeded `direct_sale_default` template — two stages, no doctor. */
const DIRECT: Stage[] = [
  { key: 'complete_registered', label: 'Complete Registered' },
  { key: 'complete_payment', label: 'Complete Payment' },
]

/** ADR-026 §5 Q1 — a post-sale template. `delivery` sits AFTER payment. */
const DELIVERED: Stage[] = [
  { key: 'complete_registered', label: 'Complete Registered' },
  { key: 'complete_payment', label: 'Complete Payment' },
  { key: 'delivery', label: 'Delivery' },
]

function referral(id: number, clientName: string, stages: Stage[], at: number) {
  return {
    id,
    client: { id: id * 100, name: clientName, phone: '0800000000' },
    agent: null,
    product: { id: id * 10, name: `แพ็กเกจ ${id}`, price_satang: 890000 },
    branch: 'สาขาสีลม',
    preferred_time: null,
    current_stage: stages[at],
    meeting_number: null,
    // The server sends BOTH: the whole ordered journey, and the one legal
    // forward move (null at the end). The board must never compute the
    // second one itself.
    pipeline: { stages, next_stage: stages[at + 1] ?? null },
    submitted_at: '2026-08-01T00:00:00Z',
  }
}

function wire(referrals: unknown[]) {
  get.mockImplementation((path: string) => {
    if (path === '/referrals') return Promise.resolve({ data: referrals })
    if (path.endsWith('/stage-logs')) return Promise.resolve({ data: [] })
    throw new Error(`unexpected GET ${path}`)
  })
}

async function mountBoard(referrals: unknown[]) {
  wire(referrals)
  const wrapper = mount(PipelineBoard)
  await flushPromises()
  return wrapper
}

/**
 * The board renders a MAIN MENU and, when a status is selected, its
 * SUB-MENU (TASK-169 Phase 3b as amended by TASK-171). They are NOT
 * interchangeable, so every helper below names which one it means:
 *
 *   bar 0 — สถานะดีล : ทั้งหมด / รอดำเนินการ / เสร็จแล้ว. Always present.
 *   bar 1 — ขั้นตอน   : the stages the selected bucket holds. ABSENT under
 *                       ทั้งหมด, and absent when the bucket is empty.
 *
 * Both carry a tab literally labelled "ทั้งหมด", so an un-scoped lookup
 * would silently pick whichever came first in the DOM.
 */
function statusBar(wrapper: ReturnType<typeof mount>) {
  const found = wrapper.findAllComponents(TabFilterBar)[0]
  if (!found) throw new Error('the status bar is missing entirely')

  return found
}

function hasStageBar(wrapper: ReturnType<typeof mount>): boolean {
  return wrapper.findAllComponents(TabFilterBar).length > 1
}

function stageBar(wrapper: ReturnType<typeof mount>) {
  const bars = wrapper.findAllComponents(TabFilterBar)
  const found = bars[1]
  if (!found) throw new Error('no stage sub-menu is rendered under the selected status')

  return found
}

/**
 * The stage sub-menu's labels, in the order it renders them, WITHOUT the
 * count badge. Scoped to the bar so an advance button that happens to name
 * the same stage ("ไป: จัดส่ง") can never be mistaken for a tab.
 */
function stageLabels(wrapper: ReturnType<typeof mount>): string[] {
  return stageBar(wrapper)
    .findAll('button')
    .map((b) => b.text().replace(/\d+$/, ''))
}

/** As rendered, badge included — for asserting the contextual counts. */
function stageLabelsWithCounts(wrapper: ReturnType<typeof mount>): string[] {
  return stageBar(wrapper)
    .findAll('button')
    .map((b) => b.text())
}

function statusLabels(wrapper: ReturnType<typeof mount>): string[] {
  return statusBar(wrapper)
    .findAll('button')
    .map((b) => b.text())
}

function clickTab(wrapper: ReturnType<typeof mount>, text: string) {
  const tab = stageBar(wrapper)
    .findAll('button')
    .find((b) => b.text().includes(text))
  if (!tab) throw new Error(`no stage tab containing "${text}"`)
  return tab.trigger('click')
}

function clickStatus(wrapper: ReturnType<typeof mount>, text: string) {
  const tab = statusBar(wrapper)
    .findAll('button')
    .find((b) => b.text().includes(text))
  if (!tab) throw new Error(`no status tab containing "${text}"`)
  return tab.trigger('click')
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  const button = wrapper.findAll('button').find((b) => b.text().includes(text))
  if (!button) throw new Error(`no button containing "${text}"`)
  return button
}

describe('PipelineBoard — ADR-026 per-template journeys', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('renders a two-stage direct sale and a five-stage medical deal as SEPARATE journeys', async () => {
    const wrapper = await mountBoard([
      referral(1, 'ลูกค้าขายตรง', DIRECT, 0),
      referral(2, 'ลูกค้าแพ็กเกจแพทย์', MEDICAL, 1),
    ])

    // Both are on the board at all — neither is filtered out by the other's
    // template.
    expect(wrapper.text()).toContain('ลูกค้าขายตรง')
    expect(wrapper.text()).toContain('ลูกค้าแพ็กเกจแพทย์')

    // …and in TWO journey sections, not one merged column set. This is the
    // assertion a fixed-axis rewrite fails.
    const journeyHeadings = wrapper.findAll('p').filter((p) => p.text() === 'เส้นทางการขาย')
    expect(journeyHeadings).toHaveLength(2)

    // Each section names its own path (journeyLabel collapses >3 stages).
    expect(wrapper.text()).toContain('ลงทะเบียนสำเร็จ → ชำระเงินสำเร็จ')
    expect(wrapper.text()).toContain('ลงทะเบียนสำเร็จ → … → นัดหมายครั้งถัดไป (5 ขั้น)')

    // And each row offers only the move ITS OWN template allows next.
    expect(wrapper.text()).toContain('ไป: ชำระเงินสำเร็จ') // direct sale, from stage 0
    expect(wrapper.text()).toContain('ไป: พบแพทย์ครั้งแรกแล้ว') // medical, from stage 1
  })

  it('gives a post-sale stage its own sub-menu tab — no fixed five-stage axis', async () => {
    const wrapper = await mountBoard([
      referral(1, 'ลูกค้าแพ็กเกจแพทย์', MEDICAL, 0), // ลงทะเบียนสำเร็จ, open
      referral(2, 'ลูกค้าส่งของ', DELIVERED, 2), // จัดส่ง, terminal → done
    ])

    // TASK-171: the sub-menu belongs to a status, so a status must be
    // chosen before there is one to inspect.
    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    // จัดส่ง is in NO medical template. A hardcoded five-stage axis loses
    // it, and with it the only way to reach that referral through the
    // filter. It is here because a DONE referral is parked on it.
    expect(stageLabels(wrapper)).toEqual(['ทั้งหมด', 'จัดส่ง'])

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()

    // …and the open bucket's own sub-menu names only where ITS deals are.
    expect(stageLabels(wrapper)).toEqual(['ทั้งหมด', 'ลงทะเบียนสำเร็จ'])
  })

  it('counts BR-4 "ชำระเงินแล้ว" against each referral\'s own journey', async () => {
    const wrapper = await mountBoard([
      referral(1, 'ยังไม่จ่าย (ขายตรง)', DIRECT, 0), // index 0, payment at 1 → NOT paid, open
      referral(2, 'จ่ายแล้ว (ขายตรง)', DIRECT, 1), // index 1 == payment → paid, closed
      referral(3, 'จ่ายแล้ว (แพทย์)', MEDICAL, 4), // index 4 > payment at 3 → paid, closed
      referral(4, 'จ่ายแล้ว (ส่งของ)', DELIVERED, 2), // จัดส่ง, index 2 > payment at 1 → paid, closed
      referral(5, 'กำลังเดิน (แพทย์)', MEDICAL, 1), // mid-journey → NOT paid, open
    ])

    const emitted = wrapper.emitted('kpis-change')
    expect(emitted).toBeTruthy()
    const latest = emitted![emitted!.length - 1]![0] as { label: string; value: number }[]

    expect(latest.find((k) => k.label === 'ดีลทั้งหมด')?.value).toBe(5)
    // 3 of 5. The จัดส่ง row is the discriminating one: it is not in the
    // medical five at all, so a fixed-list implementation scores a delivered,
    // paid-for sale as unpaid.
    expect(latest.find((k) => k.label === 'ชำระเงินแล้ว')?.value).toBe(3)
    // "Open" is `next_stage !== null` — the template-aware definition of a
    // deal that still has somewhere to go. Note it is NOT the inverse of
    // paid: #4 has paid AND is closed, #2 has paid and is closed, while a
    // hypothetical post-sale referral mid-delivery would be paid AND open.
    expect(latest.find((k) => k.label === 'รอดำเนินการต่อ')?.value).toBe(2)
  })

  it('offers no advance on a referral at the end of its own journey, and flags an unreadable one', async () => {
    const terminal = referral(1, 'จบแล้ว', DIRECT, 1)
    const broken = {
      ...referral(2, 'เส้นทางพัง', DIRECT, 0),
      // ReferralResource sends BOTH arrays empty when the journey cannot be
      // read (template deleted/emptied). The server fails closed; so must the
      // board.
      pipeline: { stages: [], next_stage: null },
    }
    const wrapper = await mountBoard([terminal, broken])

    expect(wrapper.text()).toContain('จบเส้นทางแล้ว')
    expect(wrapper.text()).toContain('เส้นทางไม่ถูกต้อง')
    expect(wrapper.text()).not.toContain('ไป: ')
  })

  it('advances by POSTing to that referral, with no target stage in the body', async () => {
    post.mockResolvedValue({ data: null })
    const wrapper = await mountBoard([referral(7, 'ลูกค้าขายตรง', DIRECT, 0)])

    await findButton(wrapper, 'ไป: ชำระเงินสำเร็จ').trigger('click')
    await flushPromises()

    // No body: the backend owns the state machine and writes the audit log.
    // If the UI ever starts naming a target stage, it has started keeping a
    // second copy of §4.3 that can drift from the template.
    expect(post).toHaveBeenCalledWith('/referrals/7/advance')
    expect(post).toHaveBeenCalledTimes(1)
  })
})

describe('PipelineBoard — empty states', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('tells an agent with NO deals where deals come from', async () => {
    const wrapper = await mountBoard([])

    expect(wrapper.text()).toContain('ยังไม่มีดีลในกระบวนการขาย')
    expect(wrapper.text()).toContain('+ เพิ่มสินค้าที่สนใจ')
    // Not the filter message — this agent has applied no filter.
    expect(wrapper.text()).not.toContain('ไม่มีดีลในขั้นนี้')
  })

  it('cannot offer a stage tab that leads to an empty board', async () => {
    // TASK-171 §4, asserted as the STRUCTURAL guarantee rather than as an
    // apology message: the sub-menu lists only stages that hold one of the
    // bucket's deals, so there is no tap sequence that filters the board to
    // nothing. Before TASK-171 the stage row was the union over every
    // template and tapping ชำระเงินสำเร็จ here produced "ไม่มีดีลในขั้นนี้".
    const wrapper = await mountBoard([
      referral(1, 'ลูกค้าขายตรง', DIRECT, 0),
      referral(2, 'ลูกค้าแพ็กเกจแพทย์', MEDICAL, 1),
      referral(3, 'ส่งของแล้ว', DELIVERED, 2),
    ])

    for (const status of ['รอดำเนินการ', 'เสร็จแล้ว']) {
      await clickStatus(wrapper, status)
      await flushPromises()

      for (const label of stageLabels(wrapper)) {
        await clickTab(wrapper, label)
        await flushPromises()

        expect(wrapper.text()).not.toContain('ไม่มีดีลในขั้นนี้')
        expect(wrapper.text()).not.toContain('ยังไม่มีดีลในกระบวนการขาย')
      }
    }
  })

  it('names the STATUS axis, not the stage axis, when only status is narrowed', async () => {
    // Telling an agent to "เลือกขั้นอื่น" when they never touched the stage
    // bar sends them hunting for a control they did not use.
    const wrapper = await mountBoard([referral(1, 'ลูกค้าขายตรง', DIRECT, 0)])

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่มีดีลในกลุ่มนี้')
    expect(wrapper.text()).toContain('เลือกสถานะอื่น')
    expect(wrapper.text()).not.toContain('ไม่มีดีลในขั้นนี้')
    expect(wrapper.text()).not.toContain('ยังไม่มีดีลในกระบวนการขาย')
  })
})

/**
 * TASK-169 Phase 3b — the open/done filter axis.
 *
 * THE ENTIRE POINT OF THIS BLOCK is the first test. `ReferralsView`'s tabs —
 * which Phase 4 deletes — decided done-ness with
 *
 *     const DONE_STAGE_KEYS = ['complete_payment', 'ongoing_next_meeting']
 *
 * written before ADR-026. Since ADR-026 that list is wrong in BOTH
 * directions, and the fixture below is built so the hardcoded predicate and
 * the correct per-template one give OPPOSITE answers on two rows. If someone
 * ports the constant across "to keep it simple", this test goes red before
 * anything ships.
 *
 * The correct predicate is the referral's OWN template having a forward move
 * (`pipeline.next_stage !== null`) — the same one the KPI row reads, asserted
 * here to be literally the same number.
 */
describe('PipelineBoard — open/done filter (Phase 3b)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  /**
   * Both rows sit on templates the hardcoded list predates.
   *
   *  ส่งของแล้ว  — at `delivery`, the LAST stage of its template. Paid,
   *                delivered, finished. `DONE_STAGE_KEYS` does not contain
   *                'delivery', so the old predicate files it as รอดำเนินการ.
   *  จ่ายแล้วรอส่ง — at `complete_payment` on a template that continues to
   *                `delivery`. The money has landed (BR-4 fired) but the
   *                journey has a step left, so it is OPEN. The old predicate
   *                contains 'complete_payment' and files it as เสร็จแล้ว.
   *
   * Two more referrals on two OTHER templates keep this a mixed-template
   * board (ADR-026 §4) rather than a single-journey special case.
   */
  const MIXED = () => [
    referral(1, 'ส่งของแล้ว', DELIVERED, 2), // delivery, terminal → DONE
    referral(2, 'จ่ายแล้วรอส่ง', DELIVERED, 1), // complete_payment, → delivery → OPEN
    referral(3, 'แพทย์จบแล้ว', MEDICAL, 4), // ongoing_next_meeting, terminal → DONE
    referral(4, 'ขายตรงเพิ่งเริ่ม', DIRECT, 0), // → complete_payment → OPEN
  ]

  it('files a delivered deal as DONE and a paid-but-undelivered one as OPEN', async () => {
    const wrapper = await mountBoard(MIXED())

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    expect(wrapper.text()).toContain('ส่งของแล้ว')
    expect(wrapper.text()).toContain('แพทย์จบแล้ว')
    // The row a hardcoded ['complete_payment', ...] list would have put here.
    expect(wrapper.text()).not.toContain('จ่ายแล้วรอส่ง')
    expect(wrapper.text()).not.toContain('ขายตรงเพิ่งเริ่ม')

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()

    expect(wrapper.text()).toContain('จ่ายแล้วรอส่ง')
    expect(wrapper.text()).toContain('ขายตรงเพิ่งเริ่ม')
    // …and the row that same list would have left OUT of done.
    expect(wrapper.text()).not.toContain('ส่งของแล้ว')
    expect(wrapper.text()).not.toContain('แพทย์จบแล้ว')
  })

  it('counts the status tabs with the SAME predicate as the KPI row', async () => {
    const wrapper = await mountBoard(MIXED())

    // 2/2 — and note neither number is what DONE_STAGE_KEYS would produce
    // (it scores 2 done / 2 open too, but with the WRONG two in each).
    expect(statusLabels(wrapper)).toEqual(['ทั้งหมด4', 'รอดำเนินการ2', 'เสร็จแล้ว2'])

    const emitted = wrapper.emitted('kpis-change')!
    const latest = emitted[emitted.length - 1]![0] as { label: string; value: number }[]
    expect(latest.find((k) => k.label === 'รอดำเนินการต่อ')?.value).toBe(2)
  })

  it('composes with the stage sub-menu — both filters apply, neither replaces the other', async () => {
    const wrapper = await mountBoard(MIXED())

    // ชำระเงินสำเร็จ holds one OPEN row (#2, post-sale template) and no done
    // one, so the stage axis alone cannot separate them — which is exactly
    // why the second axis exists.
    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()
    await clickTab(wrapper, 'ชำระเงินสำเร็จ')
    await flushPromises()
    expect(wrapper.text()).toContain('จ่ายแล้วรอส่ง')
    // AND, not OR: the OTHER open row is filtered out by the stage tap.
    expect(wrapper.text()).not.toContain('ขายตรงเพิ่งเริ่ม')
    // …and the delivered row is not dragged in either — it is done AND at
    // จัดส่ง, and it fails both halves.
    expect(wrapper.text()).not.toContain('ส่งของแล้ว')

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()
    // No done deal sits at ชำระเงินสำเร็จ in this fixture, so that tab is not
    // in the done sub-menu at all and the selection resets to ทั้งหมด rather
    // than filtering the board to nothing (TASK-171 §4).
    // Ordered by the LONGEST journey in the bucket first (medical, 5 stages),
    // then whatever the shorter ones add — not by the fixture's array order.
    expect(stageLabels(wrapper)).toEqual(['ทั้งหมด', 'นัดหมายครั้งถัดไป', 'จัดส่ง'])
    expect(wrapper.text()).toContain('ส่งของแล้ว')
    expect(wrapper.text()).toContain('แพทย์จบแล้ว')

    await clickTab(wrapper, 'จัดส่ง')
    await flushPromises()
    expect(wrapper.text()).toContain('ส่งของแล้ว')
    expect(wrapper.text()).not.toContain('แพทย์จบแล้ว')
  })

  it('defaults to ทั้งหมด — no deal is hidden behind a control the agent never touched', async () => {
    const wrapper = await mountBoard(MIXED())

    for (const name of ['ส่งของแล้ว', 'จ่ายแล้วรอส่ง', 'แพทย์จบแล้ว', 'ขายตรงเพิ่งเริ่ม']) {
      expect(wrapper.text()).toContain(name)
    }
  })

  it('puts an UNREADABLE journey under รอดำเนินการ — a broken deal is work, not a sale', async () => {
    // ag-lead ruling, 2026-08-11. `next_stage: null` means two different
    // things: "nothing left to do" and "we could not read the journey".
    // Filed as done, a broken referral leaves the agent's open work and
    // nobody looks at it again — with a customer possibly already paid,
    // stranded mid-journey. So the predicate fails toward VISIBILITY.
    const broken = {
      ...referral(9, 'เส้นทางพัง', DIRECT, 0),
      pipeline: { stages: [], next_stage: null },
    }
    const wrapper = await mountBoard([broken, referral(1, 'ขายตรงเพิ่งเริ่ม', DIRECT, 0)])

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()

    expect(wrapper.text()).toContain('เส้นทางพัง')
    expect(wrapper.text()).toContain('เส้นทางไม่ถูกต้อง')

    // And it must NOT also read as a completed sale.
    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    expect(wrapper.text()).not.toContain('เส้นทางพัง')
  })
})

/**
 * TASK-171 — status is the MAIN MENU, stage is its CONTEXTUAL SUB-MENU.
 *
 * THE ENTIRE POINT OF THIS BLOCK is the first test. The human's first draft
 * of the hierarchy assigned stages to buckets statically:
 *
 *     open = ['complete_registered', 'waiting_appointment']
 *     done = ['finish_1st_doctor_meeting', 'complete_payment', 'ongoing_next_meeting']
 *
 * Since ADR-026 no such map can exist, because THE SAME STAGE KEY IS OPEN ON
 * ONE TEMPLATE AND DONE ON ANOTHER. `complete_payment` is the terminal stage
 * of `direct_sale_default` (done) and the fourth of five on
 * `medical_package_default` (open, a meeting still owed). The fixture below
 * parks one referral of each kind on that one key, so the stage must appear
 * under BOTH parents at once — which the static map above cannot produce,
 * and which `isOpen()` produces for free.
 *
 * This is the third time a fixed stage list has been proposed or found in
 * this codebase (TASK-171 §2 records the other two). It throws no error and
 * looks right on a single-template tenant, so it gets a test.
 */
describe('PipelineBoard — status → stage hierarchy (TASK-171)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  /**
   * BOTH of these are parked at `complete_payment`. Nothing else about them
   * differs on the board — only their own template does.
   *
   *  ขายตรงจ่ายแล้ว  — DIRECT[1]. Last stage of its template → next_stage
   *                    null → DONE.
   *  แพทย์จ่ายแล้ว    — MEDICAL[3]. `ongoing_next_meeting` still ahead →
   *                    next_stage set → OPEN. BR-4 has fired for this one
   *                    too; the money landing is not the same fact as the
   *                    journey ending.
   *
   * A third referral on a third stage keeps each bucket from being a
   * single-tab special case.
   */
  const BOTH_PARENTS = () => [
    referral(1, 'ขายตรงจ่ายแล้ว', DIRECT, 1), // complete_payment, terminal → DONE
    referral(2, 'แพทย์จ่ายแล้ว', MEDICAL, 3), // complete_payment, → นัดหมาย → OPEN
    referral(3, 'ส่งของแล้ว', DELIVERED, 2), // delivery, terminal → DONE
    referral(4, 'ขายตรงเพิ่งเริ่ม', DIRECT, 0), // complete_registered → OPEN
  ]

  it('shows ONE stage key under BOTH parents, and each tap yields its own referral', async () => {
    const wrapper = await mountBoard(BOTH_PARENTS())

    // ── รอดำเนินการ ────────────────────────────────────────────────────
    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()

    expect(stageLabels(wrapper)).toContain('ชำระเงินสำเร็จ')

    await clickTab(wrapper, 'ชำระเงินสำเร็จ')
    await flushPromises()

    expect(wrapper.text()).toContain('แพทย์จ่ายแล้ว')
    // The static draft's `done` list contains complete_payment, so it would
    // have shown this row nowhere near รอดำเนินการ.
    expect(wrapper.text()).not.toContain('ขายตรงจ่ายแล้ว')
    expect(wrapper.text()).not.toContain('ขายตรงเพิ่งเริ่ม')

    // ── เสร็จแล้ว ──────────────────────────────────────────────────────
    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    // The SAME key, under the other parent. Not a bug — TASK-171 §2.
    expect(stageLabels(wrapper)).toContain('ชำระเงินสำเร็จ')

    await clickTab(wrapper, 'ชำระเงินสำเร็จ')
    await flushPromises()

    expect(wrapper.text()).toContain('ขายตรงจ่ายแล้ว')
    expect(wrapper.text()).not.toContain('แพทย์จ่ายแล้ว')
    expect(wrapper.text()).not.toContain('ส่งของแล้ว')
  })

  it('counts each sub-menu WITHIN its own parent, while the status row counts everything', async () => {
    const wrapper = await mountBoard(BOTH_PARENTS())

    // Status counts stay over ALL deals, matching the KPI row (§4).
    expect(statusLabels(wrapper)).toEqual(['ทั้งหมด4', 'รอดำเนินการ2', 'เสร็จแล้ว2'])

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()
    // …while ชำระเงินสำเร็จ counts 1 here and 1 under the other parent — the
    // two rows at that key are never added together.
    expect(stageLabelsWithCounts(wrapper)).toEqual(['ทั้งหมด2', 'ชำระเงินสำเร็จ1', 'จัดส่ง1'])

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()
    expect(stageLabelsWithCounts(wrapper)).toEqual([
      'ทั้งหมด2',
      'ลงทะเบียนสำเร็จ1',
      'ชำระเงินสำเร็จ1',
    ])
  })

  it('renders NO stage row under ทั้งหมด', async () => {
    // The human's explicit instruction (TASK-171 §1): with no status chosen
    // there is no parent for a sub-menu to hang off.
    const wrapper = await mountBoard(BOTH_PARENTS())

    expect(hasStageBar(wrapper)).toBe(false)

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()
    expect(hasStageBar(wrapper)).toBe(true)

    await clickStatus(wrapper, 'ทั้งหมด')
    await flushPromises()
    expect(hasStageBar(wrapper)).toBe(false)
  })

  it('resets a stale stage selection instead of stranding the agent on an empty board', async () => {
    // TASK-171 §4. ชำระเงินสำเร็จ exists under รอดำเนินการ *and* under
    // เสร็จแล้ว here, so this is deliberately checked with a stage that does
    // NOT survive the switch: จัดส่ง holds a done deal and no open one.
    const wrapper = await mountBoard(BOTH_PARENTS())

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()
    await clickTab(wrapper, 'จัดส่ง')
    await flushPromises()
    expect(wrapper.text()).toContain('ส่งของแล้ว')
    expect(wrapper.text()).not.toContain('ขายตรงจ่ายแล้ว')

    await clickStatus(wrapper, 'รอดำเนินการ')
    await flushPromises()

    // จัดส่ง is gone from the sub-menu, so the selection cannot survive…
    expect(stageLabels(wrapper)).not.toContain('จัดส่ง')
    // …and the board shows the whole open bucket rather than nothing.
    expect(wrapper.text()).toContain('แพทย์จ่ายแล้ว')
    expect(wrapper.text()).toContain('ขายตรงเพิ่งเริ่ม')
    expect(wrapper.text()).not.toContain('ไม่มีดีลในขั้นนี้')
    expect(wrapper.text()).not.toContain('ไม่มีดีลในกลุ่มนี้')

    // Selecting ทั้งหมด clears it too — a hidden stage filter would narrow
    // the board with no control on screen to explain or undo it.
    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()
    await clickTab(wrapper, 'จัดส่ง')
    await flushPromises()
    await clickStatus(wrapper, 'ทั้งหมด')
    await flushPromises()

    for (const name of ['ขายตรงจ่ายแล้ว', 'แพทย์จ่ายแล้ว', 'ส่งของแล้ว', 'ขายตรงเพิ่งเริ่ม']) {
      expect(wrapper.text()).toContain(name)
    }
  })

  it('drops the sub-menu when the selected status holds nothing at all', async () => {
    // A bucket with no deals has no stages to offer. Rendering a lone
    // "ทั้งหมด 0" tab under the empty state would be a control with no
    // reachable state.
    const wrapper = await mountBoard([referral(1, 'ขายตรงเพิ่งเริ่ม', DIRECT, 0)])

    await clickStatus(wrapper, 'เสร็จแล้ว')
    await flushPromises()

    expect(hasStageBar(wrapper)).toBe(false)
    expect(wrapper.text()).toContain('ไม่มีดีลในกลุ่มนี้')
    expect(wrapper.text()).toContain('เลือกสถานะอื่น')
  })
})

/**
 * TASK-191 §3.3/§3.4 — the new "share the voucher" button.
 *
 * `order.public_pay_url` used to be excluded from `ReferralResource`'s
 * nested `order` on purpose ("no live payment link on a board"). Phase 1
 * (ag-dev) reversed that so this board could add the button; what matters
 * here is that the button is keyed on `order?.status === 'paid'` ALONE —
 * never on which of the confirm/advance/terminal branches happened to
 * render, because a paid order can still have `delivery`/`follow_up`
 * stages ahead of it (ADR-026). Deliberately does not touch the
 * confirm/advance `v-if`/`v-else-if` chain (TASK-177's "one door" rule):
 * this button sits OUTSIDE it, so it is asserted to coexist with whichever
 * of those branches is showing, not to replace any of them.
 */
describe('PipelineBoard — TASK-191 §3.3 share the paid voucher link', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  function orderFixture(overrides: Record<string, unknown> = {}) {
    return {
      id: 5,
      order_number: 'ORD-0005',
      status: 'pending',
      status_label: 'รอชำระเงิน',
      amount_satang: 890000,
      has_slip: false,
      paid_at: null,
      verified_by: null,
      public_pay_url: 'https://pay.test/order',
      ...overrides,
    }
  }

  function withOrder(r: ReturnType<typeof referral>, orderOverrides: Record<string, unknown> = {}) {
    return { ...r, order: orderFixture(orderOverrides) }
  }

  it('shows the button once paid regardless of remaining stage, and hides it for no order / an unpaid order', async () => {
    const noOrderAtAll = referral(1, 'ยังไม่มีคำสั่งซื้อ', DIRECT, 0)
    const unpaidOrder = withOrder(referral(2, 'ยังไม่จ่าย', MEDICAL, 1), {
      status: 'pending',
      status_label: 'รอชำระเงิน',
    })
    // Paid, but the journey is NOT over — `delivery` is still ahead of it.
    const paidMidJourney = withOrder(referral(3, 'จ่ายแล้วรอส่ง', DELIVERED, 1), {
      status: 'paid',
      status_label: 'ชำระเงินแล้ว',
    })
    const wrapper = await mountBoard([noOrderAtAll, unpaidOrder, paidMidJourney])

    expect(wrapper.findAll('[data-test="share-voucher"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('จ่ายแล้วรอส่ง')
  })

  it('opens ShareLinkModal with the order\'s own link and a "ชำระเงิน {order_number}" heading', async () => {
    const paid = withOrder(referral(1, 'จ่ายแล้ว', DELIVERED, 1), {
      status: 'paid',
      status_label: 'ชำระเงินแล้ว',
      order_number: 'ORD-9001',
      public_pay_url: 'https://pay.test/voucher-9001',
    })
    const wrapper = await mountBoard([paid])

    expect(wrapper.findComponent(ShareLinkModal).props('show')).toBe(false)

    await wrapper.find('[data-test="share-voucher"]').trigger('click')

    const modal = wrapper.findComponent(ShareLinkModal)
    expect(modal.props('show')).toBe(true)
    expect(modal.props('url')).toBe('https://pay.test/voucher-9001')
    expect(modal.props('heading')).toBe('ชำระเงิน ORD-9001')
  })

  it('renders ALONGSIDE the advance button — a paid order can still have stages ahead of it (ADR-026)', async () => {
    const paidMidJourney = withOrder(referral(1, 'จ่ายแล้วรอส่ง', DELIVERED, 1), {
      status: 'paid',
      status_label: 'ชำระเงินแล้ว',
    })
    const wrapper = await mountBoard([paidMidJourney])

    expect(wrapper.find('[data-test="advance"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="share-voucher"]').exists()).toBe(true)
    // canConfirmOrder() explicitly excludes an already-paid order, so the
    // two money-adjacent buttons never appear on the same row.
    expect(wrapper.find('[data-test="confirm-order"]').exists()).toBe(false)
  })

  it('renders ALONGSIDE the terminal "จบเส้นทางแล้ว" label at the end of the journey', async () => {
    const paidAndDelivered = withOrder(referral(1, 'ส่งของแล้ว', DELIVERED, 2), {
      status: 'paid',
      status_label: 'ชำระเงินแล้ว',
    })
    const wrapper = await mountBoard([paidAndDelivered])

    expect(wrapper.text()).toContain('จบเส้นทางแล้ว')
    expect(wrapper.find('[data-test="share-voucher"]').exists()).toBe(true)
  })

  it('never offers it alongside "รับชำระเงินแล้ว" — the order is either awaiting confirmation or already paid, never both', async () => {
    // canConfirmOrder() is true here (order awaiting_verification, next
    // stage is complete_payment) — the share button must not also appear,
    // since the order is not paid yet.
    const awaitingConfirm = withOrder(referral(1, 'รอยืนยัน', DIRECT, 0), {
      status: 'awaiting_verification',
      status_label: 'รอตรวจสอบ',
    })
    const wrapper = await mountBoard([awaitingConfirm])

    expect(wrapper.find('[data-test="confirm-order"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="share-voucher"]').exists()).toBe(false)
  })
})
