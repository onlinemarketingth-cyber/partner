/**
 * THE QUESTION LIST IS THE PART THAT CANNOT BE WRONG.
 *
 * ═══ WHAT THESE TESTS ARE FOR ═══
 *
 * The redesign's promise is "you only see what this plan needs". That promise
 * introduces one failure the old screen could not have:
 *
 *   A CARD WRONGLY OMITTED IS A SETTING NOBODY CAN EVER REACH.
 *
 * On the old screen every form was always there, so a missing question was
 * impossible — only confusing. Here it is possible and it is silent, which
 * puts it in the same family as the ladder gaps of ADR-042: configured wrong,
 * paid wrong, nothing said.
 *
 * So each plan's set is pinned BY NAME rather than by count. A count test
 * passes when one card is swapped for another; the point is that Generation
 * keeps its ladder and Matrix never grows a leader rate.
 */
import { describe, expect, it } from 'vitest'
import {
  cardsByGroup,
  cardsForPlan,
  requiredCards,
  stepProgress,
  visibleCards,
  type CardContext,
  type CardId,
  type CommissionPlanType,
} from '../commissionCards'

const ALL_PLANS: CommissionPlanType[] = [
  'unilevel',
  'binary',
  'matrix',
  'stairstep_breakaway',
  'generation',
  'affiliate',
]

/** A company that has answered nothing. */
function context(over: Partial<CardContext> = {}): CardContext {
  return {
    planType: 'stairstep_breakaway',
    basis: 'price',
    productsMissingPointValue: 0,
    companyDefaultRatePct: null,
    scopedRateCount: 0,
    ranks: [],
    rankSettings: null,
    binary: null,
    matrix: null,
    generation: null,
    affiliate: null,
    leaderLevelRatesPct: [],
    leaderFlatRatePct: null,
    maxOverrideDepth: null,
    overrideMode: null,
    withdrawalMinSatang: null,
    withholdingTaxPct: null,
    ...over,
  }
}

/** The UAT ladder: 5 / 12 / 20 (breakaway) / 25. */
const UAT_LADDER = [
  { name: 'UAT ขั้นเริ่มต้น', thresholdSatang: 0, ratePct: 5, breakaway: false },
  { name: 'UAT ขั้นผู้นำ', thresholdSatang: 5_000_000, ratePct: 12, breakaway: false },
  { name: 'UAT ขั้นผู้จัดการ', thresholdSatang: 20_000_000, ratePct: 20, breakaway: true },
  { name: 'UAT ขั้นผู้อำนวยการ', thresholdSatang: 50_000_000, ratePct: 25, breakaway: false },
]

const idsFor = (plan: CommissionPlanType): CardId[] => cardsForPlan(plan).map((card) => card.id)

describe('which plan asks which questions', () => {
  it('asks every plan the same three opening questions', () => {
    for (const plan of ALL_PLANS) {
      expect(idsFor(plan).slice(0, 3)).toEqual(['plan-type', 'basis', 'pv-table'])
    }
  })

  it('gives Stairstep the ladder and the two settings that fill it', () => {
    const ids = idsFor('stairstep_breakaway')

    expect(ids).toContain('rank-ladder')
    expect(ids).toContain('rank-volume-scope')
    expect(ids).toContain('rank-cadence')
  })

  it('gives Generation the ladder too, because its boundaries are drawn there', () => {
    /*
     * THE OMISSION THIS TEST EXISTS TO PREVENT.
     *
     * Generation prices from commission_generation_rules and never reads a
     * rank's RATE — so "Generation does not use the rank ladder" is an easy
     * and completely wrong conclusion. It reads `is_breakaway_rank` to find
     * where each generation begins (GenerationCommissionService line 51), and
     * without ranks assigned at all, no boundary is ever crossed and the plan
     * pays nothing.
     */
    const ids = idsFor('generation')

    expect(ids).toContain('rank-ladder')
    expect(ids).toContain('rank-volume-scope')
    expect(ids).toContain('rank-cadence')
  })

  it('asks Generation about the boundary, not about rank rates', () => {
    // Same card, different sentence: its rates are inert on this plan, and
    // asking for them would send an admin to tune a number nothing reads.
    const ladder = cardsForPlan('generation').find((card) => card.id === 'rank-ladder')

    expect(ladder?.question).toContain('เส้นแบ่งรุ่น')
    expect(ladder?.question).not.toContain('เปอร์เซ็นต์')
  })

  it('never offers the ladder to the four plans that cannot read it', () => {
    for (const plan of ['unilevel', 'binary', 'matrix', 'affiliate'] as CommissionPlanType[]) {
      expect(idsFor(plan)).not.toContain('rank-ladder')
      expect(idsFor(plan)).not.toContain('rank-volume-scope')
    }
  })

  it('offers a leader rate only to the two plans paid from commission_override_rules', () => {
    /*
     * The defect the whole redesign starts from: step 4's "อัตราหัวหน้าทีม"
     * shows on all six plans while only Unilevel and Affiliate are paid from
     * that table. The other four pay their upline out of their own structure.
     */
    expect(idsFor('unilevel')).toContain('leader-level-rates')
    expect(idsFor('affiliate')).toContain('affiliate-leader-rate')

    for (const plan of ['binary', 'matrix', 'stairstep_breakaway', 'generation'] as CommissionPlanType[]) {
      const ids = idsFor(plan)

      expect(ids).not.toContain('leader-level-rates')
      expect(ids).not.toContain('affiliate-leader-rate')
      expect(ids).not.toContain('override-mode')
      expect(ids).not.toContain('leader-depth-cap')
    }
  })

  it('gives each structural plan its own shape questions and nobody else theirs', () => {
    expect(idsFor('binary')).toEqual(
      expect.arrayContaining(['binary-matched-rate', 'binary-cycle', 'binary-cap-carry']),
    )
    expect(idsFor('matrix')).toEqual(expect.arrayContaining(['matrix-shape', 'matrix-level-rates']))
    expect(idsFor('generation')).toEqual(expect.arrayContaining(['generation-depth', 'generation-rates']))
    expect(idsFor('affiliate')).toContain('affiliate-window')

    expect(idsFor('stairstep_breakaway')).not.toContain('matrix-shape')
    expect(idsFor('unilevel')).not.toContain('binary-cycle')
  })

  it('asks every plan for a seller rate and the two payout questions', () => {
    for (const plan of ALL_PLANS) {
      const ids = idsFor(plan)

      expect(ids).toContain('seller-default-rate')
      expect(ids).toContain('withdrawal-min')
      expect(ids).toContain('withholding-tax')
    }
  })

  it('never repeats a card within one plan', () => {
    for (const plan of ALL_PLANS) {
      const ids = idsFor(plan)

      expect(new Set(ids).size).toBe(ids.length)
    }
  })
})

describe('the wording', () => {
  it('asks Stairstep what the flat rate is FOR, not what the seller gets', () => {
    /*
     * ADR-043 moved a ranked seller's own rate onto their rung, leaving
     * commission_rules as the fallback for agents with no rank yet. "คนขาย
     * ได้กี่เปอร์เซ็นต์" would now be false for most sales on this plan.
     */
    const card = cardsForPlan('stairstep_breakaway').find((c) => c.id === 'seller-default-rate')

    expect(card?.question).toContain('ยังไม่มีขั้น')

    const other = cardsForPlan('unilevel').find((c) => c.id === 'seller-default-rate')

    expect(other?.question).toBe('คนขายได้กี่เปอร์เซ็นต์')
  })

  it('gives every card a question and a consequence, and names no column in either', () => {
    for (const plan of ALL_PLANS) {
      for (const card of cardsForPlan(plan)) {
        expect(card.question.length, `${plan}/${card.id} question`).toBeGreaterThan(8)
        expect(card.why.length, `${plan}/${card.id} why`).toBeGreaterThan(20)
        // `writes` is where a table name belongs — it is for the people
        // building this, and is never rendered to an admin.
        expect(card.question, `${plan}/${card.id}`).not.toMatch(/_id|_satang|commission_|agent_ranks/)
        expect(card.writes.length, `${plan}/${card.id} writes`).toBeGreaterThan(3)
      }
    }
  })
})

describe('what a card says about a company', () => {
  it('reports an unanswered required card as todo', () => {
    const cards = cardsForPlan('stairstep_breakaway')
    const ladder = cards.find((card) => card.id === 'rank-ladder')

    expect(ladder?.status(context())).toBe('todo')
    expect(ladder?.answer(context())).toBe('ยังไม่มีขั้น')
  })

  it('reads the UAT ladder back as four rungs and their rates', () => {
    const ladder = cardsForPlan('stairstep_breakaway').find((card) => card.id === 'rank-ladder')
    const c = context({ ranks: UAT_LADDER })

    expect(ladder?.answer(c)).toBe('4 ขั้น · 5% / 12% / 20% / 25%')
    expect(ladder?.status(c)).toBe('done')
  })

  it('flags a ladder with no rung at zero rather than calling it done', () => {
    // ADR-042 choice 2ก: an agent who clears nothing holds no rank, and their
    // manager is then paid no differential at all.
    const ladder = cardsForPlan('stairstep_breakaway').find((card) => card.id === 'rank-ladder')
    const c = context({ ranks: UAT_LADDER.map((rank) => ({ ...rank, thresholdSatang: rank.thresholdSatang || 1_000 })) })

    expect(ladder?.status(c)).toBe('review')
  })

  it('calls a Generation ladder with no breakaway rung unfinished', () => {
    const ladder = cardsForPlan('generation').find((card) => card.id === 'rank-ladder')
    const c = context({ planType: 'generation', ranks: UAT_LADDER.map((r) => ({ ...r, breakaway: false })) })

    expect(ladder?.status(c)).toBe('todo')
  })

  it('marks personal volume scope for review, never as done', () => {
    /*
     * The value is valid and a company may mean it. It is also the setting
     * that silently stops paying the people this plan exists to pay, so it
     * is neither an error nor something to leave unremarked.
     */
    const scope = cardsForPlan('stairstep_breakaway').find((card) => card.id === 'rank-volume-scope')

    const personal = context({
      rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' },
    })
    const group = context({
      rankSettings: { trailingWindowDays: 90, volumeScope: 'group', recalculationFrequency: 'daily' },
    })

    expect(scope?.status(personal)).toBe('review')
    expect(scope?.status(group)).toBe('done')
    expect(scope?.answer(personal)).toBe('ยอดที่ขายเอง · ย้อนหลัง 90 วัน')
  })

  it('flags rungs priced deeper than the plan pays', () => {
    // Saveable, and never paid — the contradiction the old screen split
    // across two steps and never stated.
    const rates = cardsForPlan('unilevel').find((card) => card.id === 'leader-level-rates')
    const c = context({ planType: 'unilevel', leaderLevelRatesPct: [5, 3, 1], maxOverrideDepth: 2 })

    expect(rates?.status(c)).toBe('review')
    expect(rates?.status(context({ planType: 'unilevel', leaderLevelRatesPct: [5, 3], maxOverrideDepth: 2 }))).toBe('done')
  })

  it('never calls an optional card unfinished', () => {
    for (const plan of ALL_PLANS) {
      for (const card of cardsForPlan(plan)) {
        if (card.required) continue

        expect(card.status(context()), `${plan}/${card.id}`).toBe('optional')
      }
    }
  })
})

describe('what a company is shown today', () => {
  it('hides the PV table from a company measuring in baht', () => {
    const priced = visibleCards('stairstep_breakaway', context({ basis: 'price' })).map((card) => card.id)
    const pv = visibleCards('stairstep_breakaway', context({ basis: 'pv' })).map((card) => card.id)

    expect(priced).not.toContain('pv-table')
    expect(pv).toContain('pv-table')
  })

  it('counts a step without letting optional cards drag it down', () => {
    /*
     * A step reading 3/5 because somebody skipped the withholding-tax
     * question tells an admin they are unfinished when they are not — the
     * failure the blanket amber banner had before TASK-213.
     */
    const c = context({
      planType: 'stairstep_breakaway',
      ranks: UAT_LADDER,
      rankSettings: { trailingWindowDays: 90, volumeScope: 'group', recalculationFrequency: 'daily' },
      basis: 'price',
    })

    expect(stepProgress('stairstep_breakaway', c, 2)).toEqual({ answered: 5, total: 5 })
    // Step 4 on this plan is withdrawal + tax, both optional: nothing to count.
    expect(stepProgress('stairstep_breakaway', c, 4)).toEqual({ answered: 0, total: 0 })
  })
})

describe('the overview grouping', () => {
  it('puts the seller rate and the ladder in neighbouring groups', () => {
    /*
     * The one thing regrouping by topic buys that the card list alone does
     * not: on the four-step screen these two live in different tabs and
     * cannot be compared by eye, which is exactly how they drift apart.
     */
    const groups = cardsByGroup('stairstep_breakaway', context({ ranks: UAT_LADDER })).map((entry) => entry.group)

    expect(groups.indexOf('ladder') - groups.indexOf('seller')).toBe(1)
  })

  it('drops a group with nothing in it', () => {
    // Matrix has no leader cards at all, so no empty "เงินของหัวหน้าทีม".
    const groups = cardsByGroup('matrix', context({ planType: 'matrix' })).map((entry) => entry.group)

    expect(groups).not.toContain('leader')
    expect(groups).toContain('structure')
  })

  it('accounts for every visible card exactly once', () => {
    for (const plan of ALL_PLANS) {
      const c = context({ planType: plan, basis: 'pv' })
      const grouped = cardsByGroup(plan, c).flatMap((entry) => entry.cards.map((card) => card.id))

      expect(new Set(grouped)).toEqual(new Set(visibleCards(plan, c).map((card) => card.id)))
      expect(grouped.length).toBe(visibleCards(plan, c).length)
    }
  })
})

describe('what a company must answer to pay anybody', () => {
  it('requires the seller rate on every plan', () => {
    for (const plan of ALL_PLANS) {
      expect(requiredCards(plan).map((card) => card.id)).toContain('seller-default-rate')
    }
  })

  it('leaves the three genuinely skippable settings optional on every plan', () => {
    for (const plan of ALL_PLANS) {
      const required = requiredCards(plan).map((card) => card.id)

      expect(required).not.toContain('withdrawal-min')
      expect(required).not.toContain('withholding-tax')
      expect(required).not.toContain('seller-scoped-rates')
    }
  })
})
