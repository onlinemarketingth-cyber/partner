/**
 * THE RAIL'S JOB IS TO NAME THE MISSING ONE.
 *
 * The step pill above it already says ยังไม่ครบ. That tells an admin THAT
 * something is missing and never WHICH — which is the complaint this rail
 * answers. So the tests here are about what it singles out and what it
 * refuses to count, not about markup.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CommissionStepChecklist from '../CommissionStepChecklist.vue'
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

function mountRail(over: {
  plan?: CommissionPlanType
  step?: 2 | 3 | 4
  context?: Partial<CardContext>
  canEdit?: boolean
} = {}) {
  const plan = over.plan ?? 'stairstep_breakaway'

  return mount(CommissionStepChecklist, {
    props: {
      planType: plan,
      context: context({ planType: plan, ...over.context }),
      step: over.step ?? 2,
      canEdit: over.canEdit ?? true,
    },
  })
}

describe('what it lists', () => {
  it('lists only the cards this step owns', () => {
    const html = mountRail({ step: 2 }).html()

    expect(html).toContain('step-checklist-rank-ladder')
    // The seller rate is step 3's. Repeating it here would make the rail a
    // second table of contents for the whole screen rather than for this step.
    expect(html).not.toContain('step-checklist-seller-default-rate')
  })

  it('drops a card the plan has but this company cannot use', () => {
    // PV is asked only on the PV basis; a baht company never meets it.
    const html = mountRail({ step: 2, context: { basis: 'price' } }).html()

    expect(html).not.toContain('step-checklist-pv-table')
    expect(mountRail({ step: 2, context: { basis: 'pv' } }).html()).toContain('step-checklist-pv-table')
  })
})

describe('the counter', () => {
  it('counts only what the step must have answered', () => {
    // Step 4 on Stairstep is two optional settings and nothing else, so a
    // company that skipped both is finished, not 0/2.
    const text = mountRail({ step: 4 }).get('[data-test="step-checklist-progress"]').text()

    expect(text).toBe('0/0')
  })

  it('does not count a flagged setting as answered', () => {
    const wrapper = mountRail({
      step: 2,
      context: { rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' } },
    })
    const [answered, total] = wrapper.get('[data-test="step-checklist-progress"]').text().split('/')

    expect(Number(answered)).toBeLessThan(Number(total))
  })
})

describe('what it singles out', () => {
  it('names an unanswered setting rather than only counting it', () => {
    const wrapper = mountRail({ step: 2, context: { ranks: [] } })

    expect(wrapper.get('[data-test="step-checklist-next"]').text()).toContain('บันไดอันดับ')
  })

  it('prefers an unanswered setting over a flagged one', () => {
    /*
     * A missing ladder can leave a deal paying nobody; personal scope pays
     * the wrong person. Both matter, but only one of them is an outage, so
     * the rail points at that one first.
     */
    const wrapper = mountRail({
      step: 2,
      context: {
        ranks: [],
        rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' },
      },
    })

    expect(wrapper.get('[data-test="step-checklist-next"]').text()).toContain('บันไดอันดับ')
  })

  it('falls back to a flagged setting when nothing is unanswered', () => {
    const wrapper = mountRail({
      step: 2,
      context: { rankSettings: { trailingWindowDays: 90, volumeScope: 'personal', recalculationFrequency: 'daily' } },
    })

    expect(wrapper.get('[data-test="step-checklist-next"]').text()).toContain('นับยอดของใคร')
  })

  it('says nothing when the step is settled', () => {
    const wrapper = mountRail({ step: 2 })

    expect(wrapper.find('[data-test="step-checklist-next"]').exists()).toBe(false)
  })
})

describe('what it does not do', () => {
  it('hands the card id up rather than editing anything', () => {
    const wrapper = mountRail({ step: 2 })

    wrapper.get('[data-test="step-checklist-rank-ladder"]').trigger('click')

    expect(wrapper.emitted('focus')?.[0]).toEqual(['rank-ladder'])
  })

  it('still navigates for an admin who may not write', () => {
    /*
     * Read-only is a limit on changing, not on looking: a Company Admin who
     * cannot edit still has to find the setting to read what it says.
     */
    const wrapper = mountRail({ step: 2, canEdit: false, context: { ranks: [] } })

    wrapper.get('[data-test="step-checklist-rank-ladder"]').trigger('click')

    expect(wrapper.emitted('focus')?.[0]).toEqual(['rank-ladder'])
    // No "ถัดไป" prompt though — it would be an instruction they cannot follow.
    expect(wrapper.find('[data-test="step-checklist-next"]').exists()).toBe(false)
  })
})
