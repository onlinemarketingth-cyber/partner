/**
 * Matrix, Generation, Stairstep and Affiliate draw the company's settings too.
 *
 * ═══ HOW THIS ONE WAS FOUND ═══
 *
 * 2026-09-23. Binary had been fixed hours earlier. The owner then opened the
 * Matrix chart beside the Matrix payout screen and asked how the figures were
 * arrived at: the chart said ฿1,400, the ledger said ฿1,260.
 *
 * The chart was drawing a matrix three levels deep at 5 / 3 / 2% — its own
 * constants — over a company running two levels at 5 / 3 / 1%. Width 3 and the
 * first two rates happened to match the real ones, so the fourth row looked
 * exactly as sourced as the three above it. Generation, Stairstep and
 * Affiliate carried the identical defect.
 *
 * ═══ THE PART THAT IS NOT JUST PLUMBING ═══
 *
 * Matrix and Generation each have a CAP that is configured separately from the
 * rates, and a company can price a level past its own cap. The engines stop;
 * the chart used to pay. So these tests also hold the chart to the engine's
 * own behaviour — a priced level beyond the depth draws ฿0 and says which
 * setting silenced it, rather than quietly inflating what the company thinks
 * it pays out.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlanShapePreview, { type PlanShapeSeed } from '../PlanShapePreview.vue'

type PlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'

/** ฿10,000 at 10% — the UAT fixtures, so these figures are the real screens'. */
function seed(over: Partial<PlanShapeSeed>): PlanShapeSeed {
  return {
    priceSatang: 1_000_000,
    pvSatang: 700_000,
    sellerRatePct: 10,
    productName: 'UAT Package',
    binary: null,
    matrix: null,
    generation: null,
    ranks: null,
    affiliate: null,
    ...over,
  }
}

async function open(planType: PlanType, over: Partial<PlanShapeSeed> | null, basis: 'price' | 'pv' = 'price') {
  const w = mount(PlanShapePreview, {
    props: { planType, basis, ...(over === null ? {} : { seed: seed(over) }) },
  })
  await w.get('[data-test="plan-shape-toggle"]').trigger('click')

  return w
}

type Wrapper = Awaited<ReturnType<typeof open>>

/** Every row as "who | amount", which is how a reader checks a chart. */
function rows(w: Wrapper): string[] {
  return w.findAll('[data-test="plan-shape-rows"] tr').map((r) => {
    const td = r.findAll('td')

    return `${td[0]?.text().trim() ?? ''} | ${td[2]?.text().trim() ?? ''}`
  })
}

function total(w: Wrapper): string {
  return w.get('[data-test="plan-shape-total"]').text()
}

describe('Matrix draws the company\'s own width, depth and level rates', () => {
  /** The UAT Matrix tenant: 3 wide, 2 deep, levels priced 5 / 3 / 1%. */
  const UAT_MATRIX = { matrix: { width: 3, depth: 2, levelRatesPct: [5, 3, 1] } }

  it('pays only as deep as the matrix goes, and says why the rest is zero', async () => {
    /*
     * THE ฿1,400-vs-฿1,260 CASE, pinned.
     *
     * On PV ฿7,000: seller 10% = 700, level 1 5% = 350, level 2 3% = 210.
     * Level 3 is priced at 1% (= 70) and never paid, because the matrix is
     * two deep. Total 1,260 — the ledger figure — not 1,330 and not the
     * 1,400 the old constants drew.
     */
    const w = await open('matrix', UAT_MATRIX, 'pv')

    expect(rows(w)).toEqual([
      'คนขาย | 700',
      'ขึ้นไป 1 ชั้นในผัง | 350',
      'ขึ้นไป 2 ชั้นในผัง | 210',
      'ขึ้นไป 3 ชั้นในผัง · ตั้งอัตราไว้ แต่ผังลึกแค่ 2 ชั้น จึงไม่ได้จ่าย | 0',
    ])
    expect(total(w)).toContain('1,260')
    expect(w.get('[data-test="plan-shape-rows"]').text()).toContain('ผังลึกแค่ 2 ชั้น')
  })

  it('counts the seats the real depth allows, not the priced levels', async () => {
    // 3 + 9 = 12 at depth 2. The old chart said 39 (3 + 9 + 27) because it
    // took its depth from however many rates happened to be priced.
    const w = await open('matrix', UAT_MATRIX, 'pv')

    expect(w.text()).toContain('ผังกว้าง 3 ลึก 2')
    expect(w.text()).toContain('12 คน')
  })

  it('keeps every sandbox constant when the company has no matrix settings', async () => {
    const w = await open('matrix', {}, 'pv')

    expect(w.text()).toContain('ผังกว้าง 3 ลึก 3')
    expect(w.find('[data-test="seed-source-note"]').exists()).toBe(false)
  })

  it('keeps the sandbox rates when the levels are priced as fixed amounts', async () => {
    // Null for the list, not a list with holes: a chart that skipped level 2
    // would renumber level 3 and nobody could tell.
    const w = await open('matrix', { matrix: { width: 4, depth: 2, levelRatesPct: null } }, 'pv')

    expect(w.text()).toContain('ผังกว้าง 4 ลึก 2')
    expect(rows(w)[1]).toBe('ขึ้นไป 1 ชั้นในผัง | 350') // sandbox 5%, still
  })
})

describe('Generation draws the company\'s own cap and per-generation rates', () => {
  /** The UAT Generation tenant: 2 generations paid, three priced 5 / 3 / 1%. */
  const UAT_GENERATION = { generation: { maxDepth: 2, generationRatesPct: [5, 3, 1] } }

  it('stops at the configured number of generations', async () => {
    const w = await open('generation', UAT_GENERATION)

    expect(rows(w)).toEqual([
      'คนขาย | 1,000',
      'รุ่นที่ 1 (คนที่ถึงขั้นตัดสาย) | 500',
      'รุ่นที่ 2 (คนที่ถึงขั้นตัดสาย) | 300',
      'รุ่นที่ 3 · ตั้งอัตราไว้ แต่จำกัดไว้ 2 รุ่น จึงไม่ได้จ่าย | 0',
    ])
    expect(total(w)).toContain('1,800')
  })

  it('distinguishes "nobody broke away that far" from "the cap stopped it"', async () => {
    /*
     * Two different findings that used to read as one line. A generation
     * nobody reached is a fact about the example chain; a generation past the
     * cap is money the company believes it is paying and is not — and only
     * the second is something to act on.
     */
    const w = await open('generation', UAT_GENERATION)
    const text = w.get('[data-test="plan-shape-rows"]').text()

    expect(text).toContain('จำกัดไว้ 2 รุ่น')

    // With a cap deeper than the example chain's two breakaways, the third
    // row goes back to the other reason.
    const deep = await open('generation', { generation: { maxDepth: 5, generationRatesPct: [5, 3, 1] } })

    expect(deep.get('[data-test="plan-shape-rows"]').text()).toContain('ไม่มีใครถึงขั้นตัดสาย')
  })
})

describe('Stairstep draws the company\'s own ladder', () => {
  /** The UAT Stairstep tenant after 2026-09-23: 5 / 12 / 20 (breakaway) / 25. */
  const UAT_RANKS = {
    ranks: [
      { name: 'UAT ขั้นเริ่มต้น', thresholdSatang: 0, ratePct: 5, breakaway: false },
      { name: 'UAT ขั้นผู้นำ', thresholdSatang: 5_000_000, ratePct: 12, breakaway: false },
      { name: 'UAT ขั้นผู้จัดการ', thresholdSatang: 20_000_000, ratePct: 20, breakaway: true },
      { name: 'UAT ขั้นผู้อำนวยการ', thresholdSatang: 50_000_000, ratePct: 25, breakaway: false },
    ],
  }

  it('names the company\'s ranks and prices the differential from their rates', async () => {
    const w = await open('stairstep_breakaway', UAT_RANKS)

    // Selects default to the lowest rank and the third: 20% − 5% = 15%.
    expect(rows(w)).toEqual([
      'คนขาย — ขั้น UAT ขั้นเริ่มต้น | 500',
      'หัวหน้า — ขั้น UAT ขั้นผู้จัดการ | 1,500',
    ])
  })

  it('offers every real rank in the picker, with its own rate', async () => {
    const w = await open('stairstep_breakaway', UAT_RANKS)
    const options = w.get('[data-test="ss-seller-rank"]').findAll('option').map(o => o.text())

    expect(options).toEqual([
      'UAT ขั้นเริ่มต้น — 5%',
      'UAT ขั้นผู้นำ — 12%',
      'UAT ขั้นผู้จัดการ — 20% (ตัดสาย)',
      'UAT ขั้นผู้อำนวยการ — 25%',
    ])
  })

  it('still cuts the walk dead when the downline is on a breakaway rank', async () => {
    // The plan's own rule, now drawn against real rank names: put the SELLER
    // on the breakaway rank and the manager above earns nothing.
    const w = await open('stairstep_breakaway', UAT_RANKS)

    await w.get('[data-test="ss-seller-rank"]').setValue('2')

    expect(w.get('[data-test="plan-shape-rows"]').text()).toContain('ตัดสายแล้ว')
  })

  it('draws an empty ladder as empty rather than inventing three rungs', async () => {
    /*
     * The one field here where an empty array is honoured instead of falling
     * back. A company on Stairstep with no ranks configured pays no override
     * at all — drawing the sandbox's เริ่มต้น / ผู้นำ / ผู้จัดการ over that
     * would hide the misconfiguration this card exists to surface.
     */
    const w = await open('stairstep_breakaway', { ranks: [] })

    expect(w.get('[data-test="plan-shape-rows"]').text()).toContain('ไม่มีขั้น')
    expect(w.get('[data-test="ss-seller-rank"]').findAll('option')).toHaveLength(0)
  })

  it('keeps the sandbox ladder when the ranks are priced as fixed amounts', async () => {
    const w = await open('stairstep_breakaway', { ranks: null })

    expect(rows(w)[0]).toBe('คนขาย — ขั้น เริ่มต้น | 500')
  })
})

describe('Affiliate draws the company\'s own rate and where the money comes from', () => {
  it('pays the introducer on top under บริษัทจ่ายเพิ่ม', async () => {
    const w = await open('affiliate', { affiliate: { ratePct: 3, mode: 'additive' } })

    // The UAT Affiliate tenant: seller keeps 1,000, company adds 300.
    expect(rows(w)).toEqual(['คนขาย | 1,000', 'ผู้แนะนำ (บริษัทจ่ายเพิ่ม) | 300'])
    expect(total(w)).toContain('1,300')
  })

  it('draws the two deduct modes as the 33x apart that they are', async () => {
    /*
     * THE MODE THE CHART COULD NOT DRAW AT ALL until today.
     *
     * It had two settings — additive, and "% of the seller's commission" —
     * while the company setting has three. A company on DeductFromSale was
     * drawn as one of the other two, whichever the constant happened to be.
     *
     *   deduct_from_sale       3% of the ฿10,000 SALE       = 300
     *   deduct_from_commission 3% of the seller's ฿1,000    =  30
     *
     * Both come out of the seller, and the company pays ฿1,000 either way.
     */
    const fromSale = await open('affiliate', { affiliate: { ratePct: 3, mode: 'deduct_from_sale' } })

    expect(rows(fromSale)).toEqual(['คนขาย (หลังถูกหัก) | 700', 'ผู้แนะนำ (หักจากคนขาย) | 300'])
    expect(total(fromSale)).toContain('1,000')

    const fromCommission = await open('affiliate', { affiliate: { ratePct: 3, mode: 'deduct_from_commission' } })

    expect(rows(fromCommission)).toEqual(['คนขาย (หลังถูกหัก) | 970', 'ผู้แนะนำ (หักจากคนขาย) | 30'])
    expect(total(fromCommission)).toContain('1,000')
  })

  it('keeps the sandbox rate when the introducer is paid a fixed amount', async () => {
    const w = await open('affiliate', { affiliate: { ratePct: null, mode: 'additive' } })

    expect((w.get('[data-test="af-rate"]').element as HTMLInputElement).value).toBe('3')
  })
})

describe('saying which figures are the company\'s', () => {
  it.each([
    ['matrix', { matrix: { width: 3, depth: 2, levelRatesPct: [5, 3] } }, 'ผังกว้าง ผังลึก และอัตราแต่ละชั้น'],
    ['generation', { generation: { maxDepth: 2, generationRatesPct: [5, 3] } }, 'จำนวนรุ่นสูงสุด และอัตราแต่ละรุ่น'],
    ['stairstep_breakaway', { ranks: [] }, 'บันไดขั้นทั้งหมด'],
    ['affiliate', { affiliate: { ratePct: 3, mode: 'additive' as const } }, 'อัตราผู้แนะนำ และโหมด'],
  ])('%s names its own seeded fields', async (plan, over, expected) => {
    const w = await open(plan as PlanType, over)

    expect(w.get('[data-test="seed-source-note"]').text()).toContain(expected)
  })

  it('says nothing on a plan the company does not run', async () => {
    // planShapeSeed returns null entirely when the admin is browsing another
    // plan's chip, and the card's subtitle already says every figure is made
    // up — a second note would be noise.
    const w = await open('matrix', null)

    expect(w.find('[data-test="seed-source-note"]').exists()).toBe(false)
  })
})
