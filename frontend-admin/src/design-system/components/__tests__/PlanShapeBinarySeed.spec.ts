/**
 * The Binary chart has to draw the company's own settings, or say it isn't.
 *
 * ═══ THE BUG ═══
 *
 * 2026-09-23. The owner put the Binary chart beside the UAT payout screen and
 * asked which number was right — the chart said a matched ฿30,000 paid ฿3,000,
 * the ledger said ฿1,000. Both were right about different inputs, and the
 * chart's own subtitle is what made that impossible to work out: it says
 * "ใช้ค่าจริงของบริษัท" over a drawing in which only the price and the seller
 * rate came from the company. The matched rate, the ฿5,000 cap and the
 * carry-over were constants inside the component, identical on every company
 * that opened the screen — and the cap in particular belonged to no company at
 * all, while the 10% matched rate happening to equal the real one made the
 * whole card look sourced.
 *
 * ═══ WHAT THESE TESTS HOLD ═══
 *
 * Three settings now follow the company, two knobs stay honest what-ifs, and
 * an emptied cap box means ไม่จำกัด rather than ฿0. The last of those is a
 * bug the seeding made reachable: before it, `binCap` was never null, so
 * nobody had met the `=== null ? null : S(value)` reading that turned a
 * cleared input into a cap of zero and paid nobody.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlanShapePreview, { type PlanShapeSeed } from '../PlanShapePreview.vue'

/** A company on Binary, with a settings row saying what this test is about. */
function seed(binary: PlanShapeSeed['binary']): PlanShapeSeed {
  return {
    // ฿10,000 at 10% — the UAT fixture, so the figures below are the ones the
    // owner was reading off the real screen.
    priceSatang: 1_000_000,
    pvSatang: null,
    sellerRatePct: 10,
    productName: 'UAT Package',
    binary,
  }
}

async function open(binary: PlanShapeSeed['binary'] | undefined = undefined) {
  const w = mount(PlanShapePreview, {
    props: {
      planType: 'binary' as const,
      basis: 'price' as const,
      ...(binary === undefined ? {} : { seed: seed(binary) }),
    },
  })
  await w.get('[data-test="plan-shape-toggle"]').trigger('click')

  return w
}

/**
 * "จ่ายจริงรอบนี้" — the one figure on this chart that becomes money.
 *
 * Found by its label rather than by index: the table grows a
 * "ถูกเพดานตัดออก" row only when a cap actually bites, so half these tests
 * would be reading a different row from the other half.
 */
function paid(w: Awaited<ReturnType<typeof open>>): string {
  const row = w.findAll('[data-test="plan-shape-rows"] tr')
    .find(r => r.findAll('td')[0]?.text().trim() === 'จ่ายจริงรอบนี้')

  return row?.findAll('td')[2]?.text().trim() ?? ''
}

function value(w: Awaited<ReturnType<typeof open>>, test: string): string {
  return (w.get(`[data-test="${test}"]`).element as HTMLInputElement).value
}

describe('the Binary settings the company actually saved', () => {
  it('draws the matched rate the company set, not the constant 10%', async () => {
    const w = await open({ matchedRatePct: 4, payoutCapSatang: null, carryOverUnmatched: true })

    expect(value(w, 'bin-rate')).toBe('4')
    // Legs stay at the sandbox 50,000 / 30,000, so matched is 30,000 and the
    // only thing under test is the rate: 4% of 30,000 = 1,200.
    expect(paid(w)).toBe('1,200')
  })

  it('leaves the cap box empty for a company that caps nothing', async () => {
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: true })

    expect(value(w, 'bin-cap')).toBe('')
    // The UAT Binary tenant's own configuration. Under the old constant this
    // drew ฿3,000 capped to ฿5,000 — the same answer by luck, which is why
    // nobody noticed the cap was invented until it mattered.
    expect(paid(w)).toBe('3,000')
  })

  it('applies a real cap and shows it in the box', async () => {
    const w = await open({ matchedRatePct: 10, payoutCapSatang: 90_000, carryOverUnmatched: true })

    expect(value(w, 'bin-cap')).toBe('900')
    expect(paid(w)).toBe('900') // ฿3,000 earned, ฿900 payable
  })

  it('follows the company that does NOT carry unmatched volume forward', async () => {
    /*
     * Applied unconditionally rather than under a truthiness check, unlike the
     * price and rate fields: false is the company's answer, not a missing one,
     * and keeping the sandbox's ticked box would draw the opposite of what
     * they configured.
     */
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: false })

    expect((w.get('[data-test="bin-carry"]').element as HTMLInputElement).checked).toBe(false)
    expect(w.get('[data-test="plan-shape-rows"]').text()).toContain('0 / 0')
  })

  it('keeps the sandbox rate when the company prices the match as a fixed amount', async () => {
    // A fixed amount per cycle cannot be drawn as "x% of matched", and the
    // percentage it happens to equal would move with every leg total.
    const w = await open({ matchedRatePct: null, payoutCapSatang: null, carryOverUnmatched: true })

    expect(value(w, 'bin-rate')).toBe('10')
  })

  it('leaves every knob alone for a company with no Binary settings row', async () => {
    const w = await open(null)

    expect(value(w, 'bin-rate')).toBe('10')
    expect(value(w, 'bin-cap')).toBe('5000')
  })
})

describe('the two knobs that stay what-ifs, and saying so', () => {
  it('names which figures are the company\'s and which are not', async () => {
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: true })

    expect(w.get('[data-test="bin-seed-note"]').text()).toContain('ยอดสองขาเป็นตัวเลขลองเล่น')
  })

  it('says nothing of the sort when there is nothing seeded to distinguish', async () => {
    const w = await open(null)

    expect(w.find('[data-test="bin-seed-note"]').exists()).toBe(false)
  })

  it('still lets the legs be edited, because that is what they are for', async () => {
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: true })

    await w.get('[data-test="bin-left"]').setValue('10000')
    await w.get('[data-test="bin-right"]').setValue('10000')

    // The UAT case, hand-derived: min(10,000 , 10,000) x 10% = ฿1,000 — the
    // ledger figure the owner was comparing the chart against.
    expect(paid(w)).toBe('1,000')
  })
})

describe('an emptied cap box', () => {
  it('means ไม่จำกัด', async () => {
    /*
     * Not a regression test — this already worked, by way of a `cap > 0` test
     * further down the same computation. It is pinned because the seeding
     * above turned "empty" from something only a person could produce into
     * the state this box LOADS in for any company that caps nothing, and a
     * behaviour that arrives on load deserves a test of its own rather than
     * an inherited one.
     */
    const w = await open({ matchedRatePct: 10, payoutCapSatang: 90_000, carryOverUnmatched: true })
    expect(paid(w)).toBe('900')

    await w.get('[data-test="bin-cap"]').setValue('')

    expect(paid(w)).toBe('3,000')
  })
})

describe('the warning that was true once', () => {
  it('no longer claims there is no screen for choosing a leg', async () => {
    /*
     * AgentEditModal grew the ขาในผัง Binary select on 2026-09-19 and this
     * label was never deleted, so the diagram kept raising a red alarm about a
     * gap that had been closed. Red text that is wrong is worse than no text:
     * it is how a screen teaches people to skip its warnings.
     */
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: true })

    expect(w.text()).not.toContain('ยังไม่มีหน้าจอให้เลือกว่าใครอยู่ขาไหน')
  })

  it('says where the control is instead', async () => {
    const w = await open({ matchedRatePct: 10, payoutCapSatang: null, carryOverUnmatched: true })

    expect(w.text()).toContain('จัดการผู้ใช้ระบบ')
  })
})
