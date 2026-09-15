/**
 * A stand-in for GET /commission-resolution, for the specs that are about the
 * SCREEN rather than about the ladder.
 *
 * ── WHY THIS EXISTS, AND WHY IT IS ALLOWED TO ──
 *
 * Since 2026-09-14 step 3's product list IS the resolution table: the per-
 * product cards were merged into it, so a spec that used to set up two
 * products and a company rule and then assert on rows now needs the server's
 * answer for those rows to exist at all. Writing that payload by hand in every
 * spec would mean fifty literal objects nobody maintains.
 *
 * This file computes it instead — which makes it a SECOND COPY OF THE LADDER,
 * the exact thing CommissionResolutionService's docblock says must not exist
 * in the browser. It is allowed here and nowhere else, on two conditions:
 *
 *   1. IT IS NEVER SHIPPED. Nothing under src/ outside __tests__ may import
 *      it. The application asks the server; only the fake server computes.
 *   2. IT IS NOT WHAT PROVES THE LADDER. That is backend/tests' own
 *      RateLadderAgreementTest and CommissionResolutionTest, which run against
 *      the code that pays. If this file and the server ever disagree, the
 *      server is right and these fixtures are wrong.
 *
 * Specs that are ABOUT the table's numbers (CommissionResolutionMatrix.spec)
 * still write their payload literally, so that what they assert is visible in
 * the file rather than derived by this one.
 */

export interface FixtureRule {
  id: number
  company_id?: number | null
  product?: { id: number } | null
  product_category?: { id: number } | null
  /*
   * `string`, not the union, on purpose: these fixtures are hand-written JSON
   * shaped like API responses, and narrowing it would make every existing
   * fixture in six spec files need a cast to say the thing it already says.
   */
  rate_type?: string
  rate_value: number
  effective_from?: string
  effective_to?: string | null
}

export interface FixtureProduct {
  id: number
  name: string
  category?: { id: number; name: string } | null
  price_satang?: number | null
  pv_satang?: number | null
  is_sellable_here?: boolean
}

type Mode = 'additive' | 'deduct_from_sale' | 'deduct_from_commission'

interface Options {
  companyId: number
  products: FixtureProduct[]
  rules?: FixtureRule[]
  overrideRules?: FixtureRule[]
  overrideMode?: Mode
  basis?: 'price' | 'pv'
  deepestManagerChain?: number
  maxOverridePerLevelSatang?: number | null
  now?: Date
}

/** Same window the server uses: started, and not finished. */
function live(rules: FixtureRule[], companyId: number, now: Date): FixtureRule[] {
  return rules.filter((r) =>
    (r.company_id === null || r.company_id === undefined || r.company_id === companyId)
    && new Date(r.effective_from ?? '2000-01-01') <= now
    && (!r.effective_to || new Date(r.effective_to) >= now))
}

function amountOf(rule: FixtureRule, baseSatang: number): number {
  return (rule.rate_type ?? 'percentage') === 'percentage'
    ? Math.floor((baseSatang * rule.rate_value) / 10000)
    : rule.rate_value
}

function rungOf(rule: FixtureRule | null, baseSatang: number) {
  return rule === null ? null : {
    rule_id: rule.id,
    rate_type: rule.rate_type ?? 'percentage',
    rate_value: rule.rate_value,
    amount_satang: amountOf(rule, baseSatang),
  }
}

function ladderFor(rules: FixtureRule[], product: FixtureProduct, displayBase: number, amountBase: number) {
  const product_rule = rules.find((r) => r.product?.id === product.id) ?? null
  const category_rule = product.category
    ? rules.find((r) => r.product_category?.id === product.category!.id) ?? null
    : null
  const company_rule = rules.find((r) => !r.product && !r.product_category) ?? null

  const winner = product_rule ? 'product' : category_rule ? 'category' : company_rule ? 'company' : null
  const winning = product_rule ?? category_rule ?? company_rule

  return {
    base_satang: displayBase,
    amount_base_satang: amountBase,
    company: rungOf(company_rule, amountBase),
    category: rungOf(category_rule, amountBase),
    product: rungOf(product_rule, amountBase),
    winner,
    amount_satang: winning ? amountOf(winning, amountBase) : null,
  }
}

/**
 * The payload for `GET /commission-resolution`, ordered the way the server
 * orders it: on sale first, then by Thai name. Closed products are INCLUDED
 * and flagged — the switch that reopens one lives on its row.
 */
export function buildResolution(opts: Options) {
  const {
    companyId,
    products,
    rules = [],
    overrideRules = [],
    overrideMode = 'additive',
    basis = 'price',
    deepestManagerChain = 0,
    maxOverridePerLevelSatang = null,
    now = new Date(),
  } = opts

  const liveRules = live(rules, companyId, now)
  const liveOverrides = live(overrideRules, companyId, now)

  const ordered = products.slice().sort((a, b) => {
    const aSelling = a.is_sellable_here !== false
    const bSelling = b.is_sellable_here !== false
    if (aSelling !== bSelling) return aSelling ? -1 : 1

    return a.name.localeCompare(b.name, 'th')
  })

  return {
    company_id: companyId,
    commission_basis: basis,
    commission_override_mode: overrideMode,
    deepest_manager_chain: deepestManagerChain,
    max_override_per_level_satang: maxOverridePerLevelSatang,
    products: ordered.map((p) => {
      const base = (basis === 'pv' ? p.pv_satang : p.price_satang) ?? p.price_satang ?? 0
      const agent = ladderFor(liveRules, p, base, base)
      // The 33x case: under deduct_from_commission the leader's percentage
      // applies to the SELLER'S commission, not to the sale.
      const leaderBase = overrideMode === 'deduct_from_commission' ? (agent.amount_satang ?? 0) : base

      return {
        product_id: p.id,
        name: p.name,
        is_sellable: p.is_sellable_here !== false,
        category: p.category ?? null,
        base_satang: base,
        agent,
        leader: {
          ...ladderFor(liveOverrides, p, base, leaderBase),
          override_mode: overrideMode,
          override_mode_source: 'company' as const,
        },
      }
    }),
  }
}
