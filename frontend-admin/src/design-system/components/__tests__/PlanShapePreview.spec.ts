/**
 * The plan preview teaches the plan, so its arithmetic has to be the real one.
 *
 * Owner, 2026-09-19, on step 2: "มันดูไม่ง่ายเลยในการ Setup ในแต่ละแผน ผมอยากได้
 * แบบแผนภูมิ ที่เป็นตัวอย่างในแต่ละแบบ". The answer was a sandbox — invented
 * price, invented rates, nothing saved — which is exactly the kind of thing
 * that quietly drifts away from the engine it is supposed to explain, because
 * no ledger row ever disagrees with it.
 *
 * So these tests do not check that a box rendered. They check the four numbers
 * an admin would act on, against how CommissionService actually behaves:
 * integer satang with one round() at the multiply (BR-3), PV ignoring a
 * promotion on purpose, the two Affiliate modes costing the company different
 * amounts, and a breakaway rank paying the former upline nothing.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlanShapePreview from '../PlanShapePreview.vue'

type Props = { planType: 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'; basis: 'price' | 'pv' }

async function open(props: Props) {
  const w = mount(PlanShapePreview, { props })
  await w.get('[data-test="plan-shape-toggle"]').trigger('click')
  return w
}

/** The amounts column, as an admin reads it off the screen. */
function amounts(w: ReturnType<typeof mount>): string[] {
  return w.findAll('[data-test="plan-shape-rows"] tr')
    .map(r => r.findAll('td')[2]?.text().trim() ?? '')
}

async function setNumber(w: ReturnType<typeof mount>, test: string, value: number) {
  const el = w.get(`[data-test="${test}"]`)
  await el.setValue(String(value))
}

describe('the example an admin uses to choose a plan', () => {
  it('stays collapsed until asked, because step 2 is a decision screen', () => {
    const w = mount(PlanShapePreview, { props: { planType: 'unilevel', basis: 'price' } })
    expect(w.find('[data-test="plan-shape-rows"]').exists()).toBe(false)
    expect(w.get('[data-test="plan-shape-toggle"]').text()).toContain('ดูตัวอย่าง')
  })

  it('pays the seller the rate off the sale price, rounded once', async () => {
    const w = await open({ planType: 'unilevel', basis: 'price' })
    // 9,900 x 10% = 990 exactly — the default the screen opens on.
    expect(amounts(w)[0]).toBe('990')
  })

  it('totals a flat Unilevel as one rate paid to every level', async () => {
    const w = await open({ planType: 'unilevel', basis: 'price' })
    // seller 990 + three managers at 3% of 9,900 (297 each) = 1,881 = 19%.
    const total = w.get('[data-test="plan-shape-total"]').text()
    expect(total).toContain('1,881')
    expect(total).toContain('19%')
  })

  it('shows the flat rate growing with depth — the whole reason per-level rates are wanted', async () => {
    const w = await open({ planType: 'unilevel', basis: 'price' })
    await w.get('[data-test="level-add"]').trigger('click')
    // A fourth level costs another 297 with no ceiling anywhere: 2,178 = 22%.
    expect(w.get('[data-test="plan-shape-total"]').text()).toContain('2,178')
  })

  it('lets each level carry its own rate once switched to the proposed mode', async () => {
    const w = await open({ planType: 'unilevel', basis: 'price' })
    await w.get('[data-test="uni-mode-level"]').trigger('click')
    // 5% / 3% / 1% of 9,900 → 495 / 297 / 99, under the seller's own 990.
    expect(amounts(w)).toEqual(['990', '495', '297', '99'])
  })

  /*
   * THE ONE THAT MATTERS MOST.
   *
   * This is the single behaviour the owner could not see anywhere in the
   * product, and the reason PV exists at all: a discount moves the price and
   * leaves PV alone, so the agent is not quietly paid less for selling a
   * product the company chose to discount. CommissionBasisResolver ignores the
   * promotion deliberately; if this preview ever stopped matching that, it
   * would be arguing against the feature it is there to explain.
   */
  it('lets a discount cut commission on the price basis and not on PV', async () => {
    const onPrice = await open({ planType: 'unilevel', basis: 'price' })
    await setNumber(onPrice, 'sample-discount', 20)
    expect(amounts(onPrice)[0]).toBe('792') // 9,900 less 20% = 7,920, x10%

    const onPv = await open({ planType: 'unilevel', basis: 'pv' })
    await setNumber(onPv, 'sample-discount', 20)
    expect(amounts(onPv)[0]).toBe('700') // PV 7,000 x 10%, discount ignored
  })

  it('costs the company more on additive Affiliate than on deductive', async () => {
    const w = await open({ planType: 'affiliate', basis: 'price' })
    // Additive: company pays the manager on top — 990 + 297 = 1,287.
    expect(w.get('[data-test="plan-shape-total"]').text()).toContain('1,287')

    await w.findAll('button').filter(b => b.text() === 'หักจากคนขาย')[0]?.trigger('click')
    // Deductive: the manager's 3% comes out of the seller's own 990, so the
    // company's outlay is unchanged and only the split moves.
    const total = w.get('[data-test="plan-shape-total"]').text()
    expect(total).toContain('990')
    expect(amounts(w)).toEqual(['960.3', '29.7'])
  })

  it('pays a former upline nothing once the downline reaches a breakaway rank', async () => {
    const w = await open({ planType: 'stairstep_breakaway', basis: 'price' })
    // Seller on the entry rank (5%), manager on the top rank (15%) — the
    // manager earns the 10% differential, not the full 15%.
    expect(amounts(w)).toEqual(['495', '990'])

    // Promote the seller onto the breakaway rank: the walk stops dead.
    await w.get('[data-test="ss-seller-rank"]').setValue('2')
    expect(amounts(w)).toEqual(['1,485', '0'])
    expect(w.get('[data-test="plan-shape-rows"]').text()).toContain('ตัดสายแล้ว')
  })

  it('says out loud that nothing here is the company configuration', async () => {
    const w = await open({ planType: 'matrix', basis: 'price' })
    expect(w.text()).toContain('ไม่ใช่ค่าที่บริษัทตั้งไว้')
    expect(w.text()).toContain('ไม่มีอะไรถูกบันทึก')
  })

  it('offers a PV figure to edit only when the company runs on PV', async () => {
    const onPrice = await open({ planType: 'unilevel', basis: 'price' })
    expect(onPrice.find('[data-test="sample-pv"]').exists()).toBe(false)

    const onPv = await open({ planType: 'unilevel', basis: 'pv' })
    expect(onPv.find('[data-test="sample-pv"]').exists()).toBe(true)
  })
})
