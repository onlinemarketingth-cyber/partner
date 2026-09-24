/**
 * THE WAY OUT OF THE SANDBOX.
 *
 * ═══ WHAT THE OWNER REPORTED, 2026-09-24 ═══
 *
 * They opened the preview card on the UAT Stairstep company, pulled down
 * ขั้นของคนขาย, saw 5% / 12% / 20% / 25% listed and no way to change any of
 * them, and said: "คุณบอกว่า UI set ค่าได้ แต่นี่มัน Fix เลยนิครับ ใน
 * Dropdown list".
 *
 * They read the screen correctly. On this card the ladder IS fixed — it is
 * the company's own saved ladder, shown read-only, and the dropdown picks
 * only which rung to run the example at. The editor sits further down step
 * 2, past a PV table, and nothing in the card pointed at it.
 *
 * What makes the wrong guess so easy is Unilevel: there the rate ladder
 * really IS editable inside this card, through the `rates` slot. So the
 * default plan teaches "the preview is where I edit" and five other plans
 * inherit the lesson while being false.
 *
 * ═══ WHY THE EXISTING JUMP WAS NOT ENOUGH ═══
 *
 * Pressing a box in the diagram already emitted `focus-target`. But only
 * boxes carrying a note are pressable, nothing says so, and an admin hunting
 * for a settings form does not try clicking a picture. The destination is
 * now stated in the header, where they are already looking.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlanShapePreview, { type PlanShapeSeed } from '../PlanShapePreview.vue'

type PlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'

const JUMP = '[data-test="plan-shape-structure-jump"]'

function seed(over: Partial<PlanShapeSeed> = {}): PlanShapeSeed {
  return {
    priceSatang: 1_000_000,
    pvSatang: 700_000,
    sellerRatePct: 5,
    productName: 'UAT Package',
    binary: null,
    matrix: null,
    generation: null,
    ranks: null,
    affiliate: null,
    ...over,
  }
}

/** The UAT ladder the owner was looking at. */
const UAT_RANKS = [
  { name: 'UAT ขั้นเริ่มต้น', thresholdSatang: 0, ratePct: 5, breakaway: false },
  { name: 'UAT ขั้นผู้นำ', thresholdSatang: 5_000_000, ratePct: 12, breakaway: false },
  { name: 'UAT ขั้นผู้จัดการ', thresholdSatang: 20_000_000, ratePct: 20, breakaway: true },
  { name: 'UAT ขั้นผู้อำนวยการ', thresholdSatang: 50_000_000, ratePct: 25, breakaway: false },
]

function mountCard(
  planType: PlanType,
  over: { seed?: PlanShapeSeed | null; listen?: boolean; slots?: Record<string, string> } = {},
) {
  const listen = over.listen ?? true

  return mount(PlanShapePreview, {
    slots: over.slots ?? {},
    props: {
      planType,
      basis: 'price' as const,
      seed: over.seed === undefined ? seed({ ranks: UAT_RANKS }) : over.seed,
      liveLevelRates: null,
      defaultOpen: true,
      // The affordance follows the listener, not a prop — see the component's
      // own `listenerBound`. Omitting it must remove the button.
      ...(listen ? { onFocusTarget: () => {} } : {}),
    },
  })
}

describe('the header jump to the real settings', () => {
  it('names the rank ladder, and asks for it, on a Stairstep company', () => {
    const wrapper = mountCard('stairstep_breakaway')
    const button = wrapper.get(JUMP)

    // The label has to name the destination in the admin's own words —
    // "ไปตั้งค่า" alone would land them on the same hunt one screen down.
    expect(button.text()).toContain('ไปแก้บันไดอันดับ')

    button.trigger('click')

    // 'rank-ladder' is the destination CommissionPlansView already maps to
    // [data-test="plan-structure-ranks"]. The component names a destination
    // and never a step — see its docblock.
    expect(wrapper.emitted('focus-target')?.[0]).toEqual(['rank-ladder'])
  })

  it.each<[PlanType, string, string]>([
    // Unilevel joined the list on 2026-09-24. This test asserted the
    // OPPOSITE hours earlier — "no jump on Unilevel, its ladder is edited
    // inside this card" — and that was true and was the whole problem: the
    // default plan taught that the preview is where you edit, and five other
    // plans inherited the lesson while being false. The form moved out to
    // [data-test="plan-structure-unilevel"] and the exception went with it.
    ['unilevel', 'ไปตั้งอัตราตามชั้น', 'unilevel-levels'],
    ['binary', 'ไปตั้งค่า Binary', 'binary'],
    ['matrix', 'ไปตั้งอัตราตาม Level', 'matrix-levels'],
    ['generation', 'ไปตั้งอัตราตาม Generation', 'generation'],
    ['affiliate', 'ไปตั้งค่าพันธมิตร', 'affiliate'],
  ])('points %s at its own settings block', (planType, label, target) => {
    const wrapper = mountCard(planType)

    expect(wrapper.get(JUMP).text()).toContain(label)

    wrapper.get(JUMP).trigger('click')
    expect(wrapper.emitted('focus-target')?.[0]).toEqual([target])
  })

  it('no longer renders a rate form of its own', () => {
    /*
     * THE EXCEPTION THAT CAUSED THE CONFUSION, now gone.
     *
     * Until 2026-09-24 this card accepted a `rates` slot and step 2 passed
     * the real Unilevel rate form into it. So on the default plan the
     * preview WAS the editor — and an owner who learned that there read the
     * Stairstep card's read-only ladder as settings too.
     *
     * The slot is removed rather than left unused, so a caller cannot
     * quietly put a form back inside the example.
     */
    const wrapper = mountCard('unilevel', {
      seed: seed(),
      // A slot the component no longer declares renders nothing at all.
      slots: { rates: '<p data-test="smuggled-form">form</p>' },
    })

    expect(wrapper.find('[data-test="smuggled-form"]').exists()).toBe(false)
  })

  it('offers no jump while the card is showing invented numbers', () => {
    /*
     * Sandbox mode — a company that does not run this plan, or has no
     * sellable product yet. "ไปแก้บันไดอันดับ" would point at a ladder that
     * has nothing to do with the figures on screen. Same guard the pressable
     * diagram boxes use.
     */
    expect(mountCard('stairstep_breakaway', { seed: null }).find(JUMP).exists()).toBe(false)
  })

  it('offers no jump when no one is listening for it', () => {
    // A button that looks pressable and does nothing is worse than no button.
    expect(mountCard('stairstep_breakaway', { listen: false }).find(JUMP).exists()).toBe(false)
  })
})

describe('the card saying what it is', () => {
  it('carries "ทดลอง · ไม่บันทึก" on the title line', () => {
    /*
     * The caption already said ไม่กระทบค่าที่บันทึกไว้ and was read past —
     * which is what a caption under a heading gets. On the title line it is
     * in the same glance as the thing it qualifies.
     */
    const badge = mountCard('stairstep_breakaway').get('[data-test="plan-shape-sandbox-badge"]')

    expect(badge.text()).toContain('ไม่บันทึก')
  })

  it('keeps the badge in sandbox mode too', () => {
    // Nothing is saved from this card in either mode; the badge must not
    // read as "live figures are the saveable ones".
    const wrapper = mountCard('unilevel', { seed: null })

    expect(wrapper.find('[data-test="plan-shape-sandbox-badge"]').exists()).toBe(true)
  })
})
