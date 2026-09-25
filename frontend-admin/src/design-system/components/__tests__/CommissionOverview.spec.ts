/**
 * THE OVERVIEW'S ONE JOB IS THE REGROUPING.
 *
 * Prettier rows are not why this screen exists. The four-step flow cuts along
 * the server's tables, so three pairs of settings that must agree sit in
 * different tabs and cannot be compared by eye — and the worst of them, the
 * rank ladder against the seller rate, decides whether a Stairstep chain pays
 * out the top rung it promises (ADR-043).
 *
 * So the tests that matter here are about ADJACENCY and about the pairing
 * banner, not about markup.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CommissionOverview from '../CommissionOverview.vue'
import type { CardContext, CommissionPlanType } from '@/constants/commissionCards'

function context(over: Partial<CardContext> = {}): CardContext {
  return {
    planType: 'stairstep_breakaway',
    basis: 'price',
    productsMissingPointValue: 0,
    companyDefaultRatePct: 5,
    scopedRateCount: 0,
    ranks: [
      { name: 'UAT ขั้นเริ่มต้น', thresholdSatang: 0, ratePct: 5, breakaway: false },
      { name: 'UAT ขั้นผู้จัดการ', thresholdSatang: 20_000_000, ratePct: 20, breakaway: true },
    ],
    rankSettings: { trailingWindowDays: 90, volumeScope: 'group', recalculationFrequency: 'daily' },
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

function mountOverview(over: {
  plan?: CommissionPlanType
  context?: Partial<CardContext>
  canEdit?: boolean
  payout?: { baseSatang: number; totalSatang: number; totalPct: number } | null
} = {}) {
  return mount(CommissionOverview, {
    props: {
      planType: over.plan ?? 'stairstep_breakaway',
      context: context({ planType: over.plan ?? 'stairstep_breakaway', ...over.context }),
      canEdit: over.canEdit ?? true,
      payout: over.payout === undefined ? { baseSatang: 1_000_000, totalSatang: 200_000, totalPct: 20 } : over.payout,
    },
  })
}

describe('the regrouping', () => {
  it('puts the seller rate immediately above the ladder it must agree with', () => {
    /*
     * On the four-step screen these are two tabs apart. Document order here
     * is reading order — one column, nothing absolutely positioned — so this
     * assertion is the design's central claim, stated as a test.
     */
    const html = mountOverview().html()

    const seller = html.indexOf('data-test="overview-row-seller-default-rate"')
    const ladder = html.indexOf('data-test="overview-row-rank-ladder"')

    expect(seller).toBeGreaterThan(-1)
    expect(ladder).toBeGreaterThan(seller)
  })

  it('shows no group a plan has nothing in', () => {
    // Matrix is paid from its own structure, never from
    // commission_override_rules, so there is no leader group to render.
    const html = mountOverview({
      plan: 'matrix',
      context: { matrix: { width: 3, depth: 2, levelRatesPct: [5, 3] } },
    }).html()

    expect(html).not.toContain('overview-row-leader-level-rates')
    expect(html).toContain('overview-row-matrix-shape')
  })
})

describe('the pairing banner', () => {
  it('says so when the entry rung and the flat rate agree', () => {
    const wrapper = mountOverview({ context: { companyDefaultRatePct: 5 } })

    expect(wrapper.get('[data-test="overview-seller-pairing"]').text()).toContain('ตรงกันแล้ว')
  })

  it('says what goes wrong when they do not', () => {
    const wrapper = mountOverview({ context: { companyDefaultRatePct: 10 } })
    const banner = wrapper.get('[data-test="overview-seller-pairing"]').text()

    expect(banner).toContain('ไม่ตรงกัน')
    // The consequence, not just the mismatch — an admin reading "these differ"
    // still has to go and work out why it matters.
    expect(banner).toContain('อัตราขั้นสูงสุด')
  })

  it('never appears on a plan that has no such pairing', () => {
    /*
     * Only Stairstep pays a ranked agent their rung and an un-ranked one the
     * flat rate. On Unilevel the two numbers answer different questions and a
     * banner claiming they must match would be false.
     */
    const wrapper = mountOverview({
      plan: 'unilevel',
      context: { companyDefaultRatePct: 10, leaderLevelRatesPct: [5, 3] },
    })

    expect(wrapper.find('[data-test="overview-seller-pairing"]').exists()).toBe(false)
  })
})

describe('the counter', () => {
  it('counts only what the company must answer', () => {
    /*
     * A counter reading 8/11 because nobody set a withholding-tax rate tells
     * an admin they are unfinished when they are not — the failure the
     * blanket amber banner had before TASK-213.
     */
    const text = mountOverview().get('[data-test="overview-progress"]').text()

    // Nothing optional is in the denominator, so a fully-answered company
    // reads N/N even with every skippable setting untouched.
    expect(text).toMatch(/^(\d+) \/ \1/)
  })

  it('does not count a setting that needs review as answered', () => {
    const wrapper = mountOverview({
      context: { rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' } },
    })
    const text = wrapper.get('[data-test="overview-progress"]').text()

    expect(text).toContain('ข้อควรทบทวน')
    expect(text).not.toMatch(/^(\d+) \/ \1/)
  })
})

describe('what a row says about itself', () => {
  it('spells out the consequence on a row that needs attention', () => {
    // Amber with no reason is a puzzle. The "why" line only appears where
    // there is something to explain — a settled row stays one line.
    const wrapper = mountOverview({
      context: { rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' } },
    })

    expect(wrapper.get('[data-test="overview-why-rank-volume-scope"]').text()).toContain('ปั้นทีม')
    expect(wrapper.find('[data-test="overview-why-rank-ladder"]').exists()).toBe(false)
  })

  it('hands the card id back rather than editing anything itself', () => {
    /*
     * The boundary that keeps this from becoming the setup wizard removed on
     * 2026-09-12 — deleted because it became a SECOND set of writes to the
     * same money endpoints. This component owns no form and no request.
     */
    const wrapper = mountOverview()

    wrapper.get('[data-test="overview-edit-rank-ladder"]').trigger('click')

    expect(wrapper.emitted('edit')?.[0]).toEqual(['rank-ladder'])
  })

  it('offers no edit button to an admin who may not write', () => {
    const wrapper = mountOverview({ canEdit: false })

    expect(wrapper.find('[data-test="overview-edit-rank-ladder"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ดูได้อย่างเดียว')
  })
})

describe('the summary strip', () => {
  it('states the total a deal pays out', () => {
    const text = mountOverview().get('[data-test="overview-total"]').text()

    expect(text).toContain('2,000')
    expect(text).toContain('20%')
  })

  it('calls the top rung a ceiling, and says where a breakaway rung cuts below it', () => {
    /*
     * Corrected 2026-09-25. The strip used to print the top rung as THE total.
     * On a ladder with a breakaway rung below the top, a chain that passes a
     * holder of it stops there — UAT-017's T1 pays ฿2,000, not the ฿2,500
     * the strip claimed.
     */
    const wrapper = mountOverview({
      payout: { baseSatang: 1_000_000, totalSatang: 250_000, totalPct: 25, breakawayPct: 20, breakawaySatang: 200_000 },
    })

    expect(wrapper.get('[data-test="overview-summary"]').text()).toContain('สูงสุด')
    expect(wrapper.get('[data-test="overview-total"]').text()).toContain('2,500')
    const cut = wrapper.get('[data-test="overview-breakaway-cut"]').text()
    expect(cut).toContain('20%')
    expect(cut).toContain('2,000')
  })

  it('says nothing about a cut when no breakaway rung sits below the top', () => {
    expect(mountOverview().find('[data-test="overview-breakaway-cut"]').exists()).toBe(false)
  })

  it('says plainly when a deal would pay nobody', () => {
    /*
     * Red is reserved for one sentence being literally true, the same line
     * CommissionReadinessService draws server-side. Drawing it differently
     * here would give an admin two answers about money.
     */
    const wrapper = mountOverview({
      plan: 'unilevel',
      context: { companyDefaultRatePct: null },
      payout: null,
    })

    expect(wrapper.get('[data-test="overview-nobody-paid"]').text()).toContain('ไม่มีใครได้เงิน')
  })

  it('stays out of red while every product still resolves to a rate', () => {
    const wrapper = mountOverview({
      context: { rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' } },
    })

    expect(wrapper.find('[data-test="overview-nobody-paid"]').exists()).toBe(false)
  })
})
