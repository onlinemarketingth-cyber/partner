/**
 * The redesigned client list (2026-08-22).
 *
 * Human, with a screenshot: "ดูยากมาก … แค่นี้ น้อยไปมาก".
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. THE DEALS DISAPPEAR AGAIN. `referrals` was eager-loaded on this endpoint
 *    since TASK-049 and rendered NOWHERE — data arriving in the browser and
 *    being thrown away. It is the single biggest thing this redesign adds and
 *    it cost no backend work. A future refactor that drops the column leaves
 *    a screen that still looks fine.
 *
 * 2. A SLIP GETS PAINTED AS PAID. The chip ranks an unverified slip above
 *    unpaid money because the first is blocked on US. Collapse the branches
 *    and an unverified transfer reads as revenue — on the exact screen an
 *    admin uses to decide what to chase.
 *
 * 3. `undefined` BECOMES "no orders". The detail endpoint does not select the
 *    rollups. Reporting absent-as-zero is a confident wrong answer, and it is
 *    the same failure that hid the missing `order` relation for weeks.
 *
 * 4. A RAW ID IS SHOWN TO A HUMAN AGAIN. `#3` appeared on three of four rows
 *    in the report. The lookup is fixed at its source (fetchAllPages), but
 *    the fallback must never print an id even when a name genuinely is
 *    missing.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/*
 * These derivations are pure and live in the view's <script setup>, which
 * vitest cannot import directly. They are restated here EXACTLY as written
 * there — and the mutation check on the view is what proves the two agree.
 * The alternative (mounting the whole view with a mocked router, pinia,
 * company store and four endpoints) tests the plumbing, not the rules, and
 * the rules are what produce a wrong answer nobody notices.
 */
interface Row {
  unpaid_orders_count?: number
  unpaid_amount_satang?: number
  awaiting_slip_orders_count?: number
  paid_orders_count?: number
  last_activity_at?: string | null
  referrals: Array<{ id: number; product: { name: string } | null; current_stage: { label: string } }>
}

type PaymentTone = 'slip' | 'unpaid' | 'paid' | 'none' | 'unknown'

interface PaymentChip {
  tone: PaymentTone
  label: string
}

function paymentChip(c: Row): PaymentChip {
  if (c.unpaid_orders_count === undefined) return { tone: 'unknown', label: 'ไม่ทราบ' }
  if ((c.awaiting_slip_orders_count ?? 0) > 0) return { tone: 'slip', label: 'รอตรวจสลิป' }
  if (c.unpaid_orders_count > 0) {
    const satang = c.unpaid_amount_satang ?? 0

    return { tone: 'unpaid', label: `รอชำระ ฿${(satang / 100).toLocaleString('th-TH')}` }
  }
  if ((c.paid_orders_count ?? 0) > 0) return { tone: 'paid', label: 'ชำระแล้ว' }

  return { tone: 'none', label: '—' }
}

function primaryDeal(c: Row) {
  return c.referrals.length > 0 ? (c.referrals[c.referrals.length - 1] ?? null) : null
}

function relativeTime(iso: string | null | undefined): string {
  if (iso === undefined) return 'ไม่ทราบ'
  if (iso === null) return 'ยังไม่มีการติดต่อ'
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return 'ยังไม่มีการติดต่อ'
  const minutes = Math.max(0, Math.round((Date.now() - then) / 60000))
  if (minutes < 60) return `${minutes} นาที`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} ชม.`
  const days = Math.round(hours / 24)
  if (days < 7) return `${days} วัน`
  const weeks = Math.round(days / 7)
  if (weeks < 5) return `${weeks} สัปดาห์`

  return `${Math.round(days / 30)} เดือน`
}

function row(over: Partial<Row> = {}): Row {
  return { referrals: [], ...over }
}

/**
 * The copies above are only worth anything if they still match the view.
 *
 * Restating a function in its own test is a known cheat: the test keeps
 * passing while the real code drifts, and it passes most confidently exactly
 * when it has stopped testing anything. This case closes that hole by reading
 * the .vue source and comparing the function bodies character for character.
 *
 * If it fails, the fix is to copy the view's version up here — never to
 * loosen the comparison.
 */
describe('the restated derivations still match the component', () => {
  // Resolved from the project root: vitest runs there, and `import.meta.url`
  // is not a file:// URL under this setup.
  const read = (rel: string) => readFileSync(resolve(process.cwd(), rel), 'utf8')
  const source = read('src/views/ClientManagementView.vue')

  /**
   * The BODY of `function <name>(...)`, signature excluded.
   *
   * The signatures legitimately differ — the component annotates
   * `(c: ClientRow): PaymentChip` against its own local types, the spec uses
   * a minimal `Row`. Comparing them would fail on a difference that is not a
   * difference. What must not drift is the logic between the braces, so that
   * is what is compared, with comments and whitespace normalised away.
   */
  function bodyOf(text: string, name: string): string {
    const start = text.indexOf(`function ${name}(`)
    if (start === -1) throw new Error(`${name}() is missing.`)
    const open = text.indexOf('{', start)
    const end = text.indexOf('\n}', open)
    if (open === -1 || end === -1) throw new Error(`Could not delimit ${name}().`)

    return text
      .slice(open + 1, end)
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/\/\/.*$/gm, '')
      .replace(/\s+/g, ' ')
      .trim()
  }

  it.each(['paymentChip', 'primaryDeal', 'relativeTime'])('%s is identical in both places', (name) => {
    const here = bodyOf(read('src/views/__tests__/ClientManagementView.spec.ts'), name)
    const there = bodyOf(source, name)

    expect(there).toBe(here)
  })

  /*
   * ── TWO SOURCE-LEVEL GUARDS, AND WHY THEY ARE SOURCE-LEVEL ──
   *
   * These assert on the file's text, not on behaviour. That is a weaker kind
   * of test and worth naming as such — but both pin a regression that
   * actually shipped and that a behavioural test would need the whole view
   * mounted (router, pinia, company store, four endpoints) to reach. The
   * cost/benefit falls the other way here: the bugs are one line each, and
   * one line each is what is being watched.
   */

  it('loads the WHOLE agent roster, not just the first page', () => {
    // The reported bug: "Agent: #3" on three of four rows. UserController
    // paginates at 15 with no ?per_page, so a bare GET sees a fraction of
    // the roster — alphabetically first, while clients arrive newest first.
    expect(source).toContain("fetchAllPages<AgentOption>('/users?include_inactive=1')")
    expect(source).not.toMatch(/api\.get<\{ data: AgentOption\[\] \}>\(activeCompany\.scopedPath\('\/users'\)\)/)
  })

  it('never prints a raw database id where a person expects a name', () => {
    // `#3` means nothing to the reader. When the name genuinely is missing,
    // "ไม่ระบุ" is the honest answer; an id is a leaked implementation detail
    // dressed up as content.
    const fallback = bodyOf(source, 'agentNameFor')

    expect(fallback).toContain("'ไม่ระบุ'")
    expect(fallback).not.toContain('#${')
  })
})

const DEAL = { id: 1, product: { name: 'Vital Blueprint mini' }, current_stage: { label: 'ชำระเงิน' } }

describe('payment chip — what an admin should chase', () => {
  it('ranks an unverified slip above unpaid money', () => {
    // A slip is blocked on US; unpaid money is blocked on the customer.
    // The admin is scanning for work they can do right now.
    const both = row({ unpaid_orders_count: 2, awaiting_slip_orders_count: 1, unpaid_amount_satang: 500000 })

    expect(paymentChip(both).tone).toBe('slip')
  })

  it('never paints an unverified slip as paid', () => {
    const slip = row({ unpaid_orders_count: 1, awaiting_slip_orders_count: 1 })

    expect(paymentChip(slip).tone).not.toBe('paid')
  })

  it('states the amount still owed, so the row is actionable without opening it', () => {
    const owing = row({ unpaid_orders_count: 1, unpaid_amount_satang: 890000, awaiting_slip_orders_count: 0 })

    expect(paymentChip(owing).tone).toBe('unpaid')
    expect(paymentChip(owing).label).toContain('8,900')
  })

  it('says paid only when nothing is outstanding', () => {
    const settled = row({ unpaid_orders_count: 0, awaiting_slip_orders_count: 0, paid_orders_count: 2 })

    expect(paymentChip(settled).tone).toBe('paid')
  })

  it('distinguishes "no orders" from "nobody counted"', () => {
    // THE ONE THAT WOULD HIDE A BUG. undefined = the endpoint did not select
    // the rollups; reporting that as "—" would state a payment fact about a
    // customer nobody asked about.
    const none = row({ unpaid_orders_count: 0, awaiting_slip_orders_count: 0, paid_orders_count: 0 })
    const notCounted = row({})

    expect(paymentChip(none).tone).toBe('none')
    expect(paymentChip(notCounted).tone).toBe('unknown')
    expect(paymentChip(notCounted).label).toContain('ไม่ทราบ')
  })
})

describe('the deal column — the data that was already there', () => {
  it('shows the newest deal', () => {
    const c = row({ referrals: [{ ...DEAL, id: 1 }, { ...DEAL, id: 2, product: { name: 'Health Tracker V8' } }] })

    expect(primaryDeal(c)?.product?.name).toBe('Health Tracker V8')
  })

  it('answers with null rather than throwing when there are no deals', () => {
    expect(primaryDeal(row())).toBeNull()
  })

  it('survives a deal whose product was removed', () => {
    const c = row({ referrals: [{ id: 1, product: null, current_stage: { label: 'ลงทะเบียนแล้ว' } }] })

    expect(primaryDeal(c)?.product).toBeNull()
    expect(primaryDeal(c)?.current_stage.label).toBe('ลงทะเบียนแล้ว')
  })
})

describe('last contact — who has been left alone', () => {
  const ago = (ms: number) => new Date(Date.now() - ms).toISOString()

  it('reads as elapsed time, not as a calendar date', () => {
    // "3 วัน" answers the question; "20 สิงหาคม 2569" makes the reader do the
    // subtraction first.
    expect(relativeTime(ago(3 * 864e5))).toBe('3 วัน')
    expect(relativeTime(ago(2 * 36e5))).toBe('2 ชม.')
    expect(relativeTime(ago(14 * 864e5))).toBe('2 สัปดาห์')
  })

  it('says "never contacted" rather than inventing a date', () => {
    // These are exactly the customers the column exists to surface.
    expect(relativeTime(null)).toContain('ยังไม่มีการติดต่อ')
  })

  it('says "unknown" when the endpoint did not select it', () => {
    expect(relativeTime(undefined)).toBe('ไม่ทราบ')
  })

  it('does not crash on an unparseable timestamp', () => {
    expect(() => relativeTime('not-a-date')).not.toThrow()
  })
})
