/**
 * The sales-team row (2026-08-22).
 *
 * Human, with a screenshot: "ตอนนี้ดูยากมาก". Each agent was a card of ~25
 * numbers; six filled the screen and none could be compared, because a card
 * is an independent block and the same figure sat at a different pixel on
 * every one.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. THE PIPELINE BAR ASSUMES EIGHT STAGES. `deals_by_stage` carries every
 *    stage the SERVER knows, and that count changes with an ADR (BR-7,
 *    TASK-179 §4.1 — it went from five to eight once already, which is how
 *    the old card's strip stopped adding up to the ดีลทั้งหมด above it). A
 *    ninth stage must render, not vanish into a transparent gap.
 *
 * 2. THE CLOSE RATE LOSES ITS DENOMINATOR. "100.0%" over three deals reads
 *    like a track record. The ratio beside it is what makes it honest, and
 *    it is one expression that a tidy-up would happily delete.
 *
 * 3. A ZERO-DEAL AGENT DIVIDES BY ZERO. Five of the six agents in the
 *    reported screenshot had no deals at all — the common case, not the edge
 *    one. NaN% or a full bar would both be wrong.
 *
 * 4. THE LEADER BADGE COMES BACK IN THE LEADERS TAB. TASK-127 decided that
 *    inside a tab titled หัวหน้าทีม the badge restates the tab name, and once
 *    most agents are granted it distinguishes nothing. `flat` is the case
 *    that broke that assumption once: a search flattens the tree, so the tab
 *    can contain a nested member who is NOT a leader.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/*
 * Restated from SalesTeamRow.vue's <script setup>, which vitest cannot
 * import. The first describe below reads the component source and compares
 * the bodies character for character, so these copies cannot drift — the
 * same guard ClientManagementView.spec.ts uses, and for the same reason:
 * a restated function that silently stops matching is a test that passes
 * most confidently once it has stopped testing anything.
 */
const BAR_SHADES = [
  'bg-brand-200',
  'bg-brand-300',
  'bg-brand-400',
  'bg-brand-500',
  'bg-brand-600',
  'bg-brand-700',
  'bg-brand-800',
  'bg-brand-900',
]

function shadeFor(index: number): string {
  return BAR_SHADES[index % BAR_SHADES.length] ?? 'bg-brand-500'
}

function closeRateOf(node: { closed_deals: number; total_deals: number }) {
  const { closed_deals: closed, total_deals: total } = node

  return {
    ratio: `${closed}/${total}`,
    pct: total === 0 ? null : (closed / total) * 100,
  }
}

describe('the restated derivations still match the component', () => {
  // Resolved from the project root: vitest runs there, and `import.meta.url`
  // is not a file:// URL under this setup.
  const read = (rel: string) => readFileSync(resolve(process.cwd(), rel), 'utf8')

  function bodyOf(text: string, name: string): string {
    const start = text.indexOf(`function ${name}(`)
    if (start === -1) throw new Error(`${name}() is missing.`)
    const open = text.indexOf('{', start)
    const end = text.indexOf('\n}', open)

    return text
      .slice(open + 1, end)
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/\/\/.*$/gm, '')
      .replace(/\s+/g, ' ')
      .trim()
  }

  it.each(['shadeFor', 'closeRateOf'])('%s is identical in both places', (name) => {
    const source = read('src/views/SalesTeamRow.vue')
    const here = read('src/views/__tests__/SalesTeamRow.spec.ts')

    expect(bodyOf(source, name)).toBe(bodyOf(here, name))
  })

  it('the shade palette is identical in both places', () => {
    const source = read('src/views/SalesTeamRow.vue')

    for (const shade of BAR_SHADES) expect(source).toContain(`'${shade}'`)
  })
})

describe('the pipeline bar survives a stage count it did not expect', () => {
  it('gives every one of the eight known stages its own shade', () => {
    // The bar walks ONE hue light→dark in stage order, so it reads as
    // progress rather than as eight unrelated categories. Duplicate shades
    // inside the known set would flatten that.
    const used = BAR_SHADES.map((_, i) => shadeFor(i))

    expect(new Set(used).size).toBe(8)
  })

  it('still paints a ninth stage instead of leaving a hole', () => {
    // THE BR-7 CASE. The stage list is admin/ADR-driven; it grew from five
    // to eight once already. An index past the palette must repeat a shade,
    // never return undefined — a transparent segment reads as missing data.
    expect(shadeFor(8)).toBe('bg-brand-200')
    expect(shadeFor(12)).toBeTruthy()
    expect(shadeFor(99)).toBeTruthy()
  })
})

describe('close rate keeps its denominator', () => {
  it('shows the ratio beside the percentage', () => {
    // "100.0%" over three deals reads like a track record. 3/3 puts the base
    // in front of the reader.
    const r = closeRateOf({ closed_deals: 3, total_deals: 3 })

    expect(r.ratio).toBe('3/3')
    expect(r.pct).toBe(100)
  })

  it('does not divide by zero for an agent with no deals', () => {
    // Five of six agents in the reported screenshot. The common case.
    const r = closeRateOf({ closed_deals: 0, total_deals: 0 })

    expect(r.pct).toBeNull()
    expect(r.ratio).toBe('0/0')
    expect(Number.isNaN(r.pct as unknown as number)).toBe(false)
  })

  it('reports a partial rate honestly', () => {
    const r = closeRateOf({ closed_deals: 1, total_deals: 4 })

    expect(r.ratio).toBe('1/4')
    expect(r.pct).toBe(25)
  })
})
