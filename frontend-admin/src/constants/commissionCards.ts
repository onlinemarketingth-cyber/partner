/**
 * WHICH QUESTIONS EACH COMMISSION PLAN ACTUALLY ASKS.
 *
 * ═══ WHY THIS FILE EXISTS BEFORE ANY SCREEN DOES ═══
 *
 * Owner, 2026-09-24: "การ setup ค่าต่างๆ ยังดูยาก ยุ่งยาก ไม่มีลำดับขั้นตอน".
 *
 * The diagnosis was not that the forms are ugly. It is that step 2 owns the
 * structural singletons, step 3 owns `commission_rules` and step 4 owns
 * `commission_override_rules` — a split that matches the SERVER's tables and
 * nothing a person thinks while setting a company up. Every plan therefore
 * shows every form, including the four plans out of six that never read
 * `commission_override_rules` at all.
 *
 * The agreed design (B + C1) replaces those forms with CARDS: one decision
 * per card, written as a question, generated only for the plans that use it.
 * This module is that list. It is deliberately the FIRST thing built and the
 * only thing that is pure — no Vue, no API, no refs — because getting it
 * wrong is the one failure the redesign can introduce that the old screen
 * could not:
 *
 *   A CARD WRONGLY OMITTED IS A SETTING THAT CAN NEVER BE REACHED, on a
 *   screen whose whole promise is that what you see is what this plan needs.
 *
 * That is the same class of silent gap as ADR-042's ladder findings, one
 * layer up, so it gets the same treatment: stated once, in one place, with
 * tests that fail when a plan's set drifts.
 *
 * ═══ HOW THE SCREEN USES IT ═══
 *
 * `cardsForPlan()` returns the ordered set for a plan. Each card answers, for
 * a given context, what it currently says and whether it is settled. The view
 * maps its own refs into a plain `CardContext` — that indirection is what
 * makes every rule here testable without mounting anything.
 *
 * `group` is for the overview screen (C1), which lists cards by TOPIC rather
 * than by step: that regrouping is the only thing that puts the rank ladder
 * next to the seller rate it has to agree with, which on the four-step screen
 * are two tabs apart.
 */

export type CommissionPlanType =
  | 'unilevel'
  | 'binary'
  | 'matrix'
  | 'stairstep_breakaway'
  | 'generation'
  | 'affiliate'

/**
 * `review` is NOT a softer `todo`. It means the value is set, valid, and
 * saveable — and still likely to surprise the company. The personal-volume
 * scope that silently pays a team-building leader nothing is the case it was
 * invented for; treating it as "not done" would be false, and treating it as
 * done would hide it.
 */
export type CardStatus = 'done' | 'todo' | 'review' | 'optional'

export type CardGroup =
  | 'plan'
  | 'seller'
  | 'ladder'
  | 'structure'
  | 'leader'
  | 'payout'

export type CardId =
  | 'plan-type'
  | 'basis'
  | 'pv-table'
  | 'seller-default-rate'
  | 'seller-scoped-rates'
  | 'rank-ladder'
  | 'rank-volume-scope'
  | 'rank-cadence'
  | 'binary-matched-rate'
  | 'binary-cycle'
  | 'binary-cap-carry'
  | 'matrix-shape'
  | 'matrix-level-rates'
  | 'generation-depth'
  | 'generation-rates'
  | 'leader-level-rates'
  | 'leader-depth-cap'
  | 'affiliate-window'
  | 'affiliate-leader-rate'
  | 'override-mode'
  | 'withdrawal-min'
  | 'withholding-tax'

/**
 * Everything a card needs to answer "what does this say, and is it settled".
 *
 * Plain data on purpose. The view owns the refs and the requests; this module
 * owns the rules. A card that reached for a store would be untestable and
 * would tie the question list to one screen's lifecycle.
 */
export interface CardContext {
  planType: CommissionPlanType
  basis: 'price' | 'pv' | null
  /** Sellable products with no PV set — only meaningful on the pv basis. */
  productsMissingPointValue: number
  /** A live company-wide commission_rules row, as a percentage, or null. */
  companyDefaultRatePct: number | null
  /** Live commission_rules rows scoped to a product or category. */
  scopedRateCount: number
  ranks: { name: string; thresholdSatang: number; ratePct: number | null; breakaway: boolean }[]
  rankSettings: {
    trailingWindowDays: number | null
    volumeScope: 'personal' | 'group' | null
    recalculationFrequency: 'daily' | 'weekly' | 'monthly' | null
  } | null
  binary: { matchedRatePct: number | null; cycle: string | null; capSatang: number | null; carryOver: boolean } | null
  matrix: { width: number | null; depth: number | null; levelRatesPct: number[] } | null
  generation: { maxDepth: number | null; ratesPct: number[] } | null
  affiliate: { attributionWindowDays: number | null } | null
  /** Unilevel's per-level leader ladder, level 1 first. */
  leaderLevelRatesPct: number[]
  /** Affiliate's single-hop leader rate. */
  leaderFlatRatePct: number | null
  maxOverrideDepth: number | null
  overrideMode: 'additive' | 'deduct_from_sale' | 'deduct_from_commission' | null
  withdrawalMinSatang: number | null
  withholdingTaxPct: number | null
}

export interface CommissionCard {
  id: CardId
  /** The tab it lives under while the four-step flow survives. */
  step: 2 | 3 | 4
  /** The topic it lives under on the overview, which ignores steps. */
  group: CardGroup
  /** The question, in the words an admin would use. Never a column name. */
  question: string
  /** What changes about the money if this is answered differently. */
  why: string
  /** False means a company can ship without it and nobody is unpaid. */
  required: boolean
  /** The table this writes to — for the people building it, not for the UI. */
  writes: string
  answer: (context: CardContext) => string
  status: (context: CardContext) => CardStatus
  /**
   * A card the plan HAS but this company has no use for right now — the PV
   * table on a company measuring in baht is the only one today.
   *
   * Kept separate from the per-plan set on purpose: `cardsForPlan()` answers
   * "what can this plan ever ask", which is a fact about the plan and is what
   * the tests pin, while `visibleCards()` answers "what does it ask this
   * company today", which changes as they answer.
   */
  hidden?: (context: CardContext) => boolean
}

const pct = (value: number | null): string => (value === null ? '—' : `${trimZeros(value)}%`)

function trimZeros(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)))
}

const baht = (satang: number | null): string =>
  satang === null ? '—' : `฿${(satang / 100).toLocaleString('th-TH')}`

const CADENCE_LABELS: Record<string, string> = {
  daily: 'รายวัน',
  weekly: 'รายสัปดาห์',
  monthly: 'รายเดือน',
}

const MODE_LABELS: Record<string, string> = {
  additive: 'บริษัทจ่ายเพิ่ม',
  deduct_from_sale: 'หักจากยอดขาย',
  deduct_from_commission: 'หักจากค่าคอมของคนขาย',
}

const PLAN_LABELS: Record<CommissionPlanType, string> = {
  unilevel: 'Unilevel',
  binary: 'Binary',
  matrix: 'Matrix',
  stairstep_breakaway: 'อันดับ (Stairstep)',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}

/**
 * ═══ THE ONE CARD WHOSE WORDING CHANGES WITH THE PLAN ═══
 *
 * ADR-043 moved a Stairstep seller's own rate onto their rank, leaving the
 * commission_rules figure as the fallback for agents who hold no rank yet.
 * Asking "คนขายได้กี่เปอร์เซ็นต์" on that plan would now be untrue for most
 * sales — so the question says what the number actually governs there.
 *
 * It is the same row in the same table either way. Only the sentence moves,
 * which is precisely what a question-shaped UI is for.
 */
function sellerDefaultRateCard(plan: CommissionPlanType): CommissionCard {
  const isStairstep = plan === 'stairstep_breakaway'

  return {
    id: 'seller-default-rate',
    step: 3,
    group: 'seller',
    question: isStairstep
      ? 'คนที่ยังไม่มีขั้น ได้กี่เปอร์เซ็นต์'
      : 'คนขายได้กี่เปอร์เซ็นต์',
    why: isStairstep
      ? 'ตัวแทนทุกคนเริ่มต้นโดยยังไม่มีขั้น จนกว่ารอบคำนวณอันดับจะทำงาน — อัตรานี้คือสิ่งที่พวกเขาได้ในช่วงนั้น และควรตั้งให้เท่ากับอัตราขั้นต่ำสุด'
      : 'ถ้าไม่มีค่านี้ สินค้าที่เพิ่มใหม่จะไม่มีอัตราใดรองรับ และดีลที่ปิดได้จะไม่มีใครได้เงิน',
    required: true,
    writes: 'commission_rules (product_id และ product_category_id เป็น null)',
    answer: (c) => (c.companyDefaultRatePct === null ? 'ยังไม่ได้ตั้ง' : `${pct(c.companyDefaultRatePct)} ทั้งบริษัท`),
    status: (c) => (c.companyDefaultRatePct === null ? 'todo' : 'done'),
  }
}

function rankLadderCard(plan: CommissionPlanType): CommissionCard {
  /*
   * GENERATION READS THE LADDER TOO — for its breakaway FLAG, never for its
   * rates (GenerationCommissionService prices from commission_generation_rules
   * and only checks `is_breakaway_rank` to find a generation boundary).
   *
   * Leaving this card out of Generation would hide the setting that decides
   * where its generations begin, which is the single most consequential value
   * that plan has. Asking it with Stairstep's wording would be just as wrong.
   */
  const isGeneration = plan === 'generation'

  return {
    id: 'rank-ladder',
    step: 2,
    group: 'ladder',
    question: isGeneration
      ? 'ขั้นไหนเป็นเส้นแบ่งรุ่น'
      : 'บันไดอันดับมีกี่ขั้น แต่ละขั้นได้กี่เปอร์เซ็นต์',
    why: isGeneration
      ? 'รุ่นใหม่เริ่มนับที่คนซึ่งไต่ถึงขั้นที่ติดธงตัดสาย — อัตราของขั้นไม่ถูกใช้ในแผนนี้ อัตราจริงมาจากตารางอัตราตามรุ่น'
      : 'หัวหน้าได้เฉพาะส่วนที่ขั้นตัวเองสูงกว่าลูกทีม ส่วนต่างจึงหักล้างกันเป็นทอด ๆ และยอดที่บริษัทจ่ายออกเท่ากับอัตราขั้นสูงสุดในสายพอดี',
    required: true,
    writes: 'agent_ranks',
    answer: (c) => {
      if (c.ranks.length === 0) return 'ยังไม่มีขั้น'

      if (isGeneration) {
        const breakaway = c.ranks.filter((rank) => rank.breakaway)

        return breakaway.length === 0
          ? `${c.ranks.length} ขั้น · ยังไม่มีขั้นตัดสาย`
          : `${c.ranks.length} ขั้น · ตัดสายที่ ${breakaway.map((rank) => rank.name).join(' · ')}`
      }

      const rates = c.ranks.map((rank) => pct(rank.ratePct)).join(' / ')

      return `${c.ranks.length} ขั้น · ${rates}`
    },
    status: (c) => {
      if (c.ranks.length === 0) return 'todo'

      // Generation pays nothing at all without a boundary to draw, so an
      // unflagged ladder is not "done" there even though it is a valid ladder.
      if (isGeneration && !c.ranks.some((rank) => rank.breakaway)) return 'todo'

      // No rung at ฿0 means an agent who clears nothing holds no rank, and
      // ADR-042 (choice 2ก) then writes no override row for their manager.
      if (!c.ranks.some((rank) => rank.thresholdSatang === 0)) return 'review'

      return 'done'
    },
  }
}

const rankVolumeScopeCard: CommissionCard = {
  id: 'rank-volume-scope',
  step: 2,
  group: 'ladder',
  question: 'เลื่อนขั้นโดยนับยอดของใคร',
  why: 'นับเฉพาะยอดที่ขายเอง หัวหน้าที่หันไปปั้นทีมจะขั้นตกต่ำกว่าลูกทีม ส่วนต่างติดลบ และไม่มีแถวค่าคอมเกิดขึ้นเลย · เปลี่ยนเป็นยอดทั้งทีมต้องปรับยอดขั้นต่ำของทุกขั้นขึ้นด้วย ไม่งั้นทุกคนจะขั้นเท่ากันและส่วนต่างเป็นศูนย์',
  required: true,
  writes: 'agent_rank_settings.volume_scope',
  answer: (c) => {
    const scope = c.rankSettings?.volumeScope

    if (!scope) return 'ยังไม่ได้ตั้ง'

    const window = c.rankSettings?.trailingWindowDays

    return `${scope === 'group' ? 'ยอดทั้งทีม' : 'ยอดที่ขายเอง'}${window ? ` · ย้อนหลัง ${window} วัน` : ''}`
  },
  /*
   * `review`, not `done`, on personal scope — the value is valid and a company
   * may mean it, but it is the setting that silently stops paying the people
   * the plan exists to pay, and an admin who never sees it flagged will never
   * question it. See the owner's decision of 2026-09-24.
   */
  status: (c) => {
    if (!c.rankSettings?.volumeScope) return 'todo'

    return c.rankSettings.volumeScope === 'personal' ? 'review' : 'done'
  },
}

const rankCadenceCard: CommissionCard = {
  id: 'rank-cadence',
  step: 2,
  group: 'ladder',
  question: 'คำนวณอันดับใหม่บ่อยแค่ไหน',
  why: 'ขั้นเป็นภาพนิ่งที่คำนวณเป็นรอบ ไม่ได้คิดสดตอนขาย — ตั้งรายเดือนแปลว่าคนที่ขายทะลุเกณฑ์วันนี้อาจได้อัตราเดิมไปอีกเกือบเดือน และค่าคอมที่ลงบัญชีไปแล้วแก้ย้อนหลังไม่ได้',
  required: true,
  writes: 'agent_rank_settings.recalculation_frequency',
  answer: (c) => {
    const cadence = c.rankSettings?.recalculationFrequency

    return cadence ? (CADENCE_LABELS[cadence] ?? cadence) : 'ยังไม่ได้ตั้ง'
  },
  status: (c) => {
    if (!c.rankSettings?.recalculationFrequency) return 'todo'

    // Monthly is legal and sometimes wanted; it is also the cadence that makes
    // a rank promotion invisible for up to 28 days, which on Stairstep now
    // moves the seller's OWN pay too (ADR-043). Worth surfacing, not blocking.
    return c.rankSettings.recalculationFrequency === 'monthly' ? 'review' : 'done'
  },
}

/** Cards every plan asks, in the order they are asked. */
function universalOpeningCards(): CommissionCard[] {
  return [
    {
      id: 'plan-type',
      step: 2,
      group: 'plan',
      question: 'บริษัทนี้จ่ายค่าคอมแบบไหน',
      why: 'ตัวเลือกนี้ตัดสินว่าจะถูกถามอะไรต่อจากนี้ และล็อกทันทีที่มีการขายเกิดขึ้นแล้ว',
      required: true,
      writes: 'companies.commission_plan_type',
      answer: (c) => PLAN_LABELS[c.planType],
      status: () => 'done',
    },
    {
      id: 'basis',
      step: 2,
      group: 'plan',
      question: 'คิดค่าคอมจากราคาขาย หรือจาก PV',
      why: 'เลือก PV แล้วส่วนลดโปรโมชันจะไม่ทำให้ค่าคอมลดตาม เพราะ PV เป็นตัวเลขของสินค้า ไม่ใช่ราคาที่ลูกค้าจ่าย',
      required: true,
      writes: 'companies.commission_basis',
      answer: (c) => (c.basis === null ? 'ยังไม่ได้ตั้ง' : c.basis === 'pv' ? 'PV' : 'ราคาขาย'),
      status: (c) => (c.basis === null ? 'todo' : 'done'),
    },
    {
      /*
       * Only on the PV basis, and that is the whole design: a company on
       * ราคาขาย never meets PV at all. A product with no PV falls back to its
       * price silently in the engine, so this card is the only place that gap
       * is visible before a payout.
       */
      id: 'pv-table',
      step: 2,
      group: 'plan',
      question: 'PV ของแต่ละสินค้าเป็นเท่าไร',
      why: 'สินค้าที่ยังไม่ได้กำหนด PV จะถูกคิดจากราคาขายไปก่อนแบบเงียบ ๆ — ซึ่งคือสิ่งที่การเปลี่ยนมาใช้ PV ตั้งใจจะเลิกทำ',
      required: true,
      writes: 'products.pv_satang',
      hidden: (c) => c.basis !== 'pv',
      answer: (c) =>
        c.productsMissingPointValue === 0
          ? 'กำหนดครบทุกสินค้า'
          : `ยังไม่ได้กำหนด ${c.productsMissingPointValue} รายการ`,
      status: (c) => (c.productsMissingPointValue === 0 ? 'done' : 'todo'),
    },
  ]
}

/** Cards every plan asks at the end, none of which blocks a payout. */
const universalClosingCards: CommissionCard[] = [
  {
    id: 'seller-scoped-rates',
    step: 3,
    group: 'seller',
    question: 'มีสินค้าหรือหมวดที่ต้องใช้อัตราต่างจากค่าเริ่มต้นไหม',
    why: 'ระบบใช้กล่องที่เจาะจงที่สุดเพียงกล่องเดียว — ตามสินค้า ก่อนตามหมวด ก่อนค่าเริ่มต้นทั้งบริษัท ไม่เอามาบวกกัน',
    required: false,
    writes: 'commission_rules (แบบมีขอบเขต)',
    answer: (c) => (c.scopedRateCount === 0 ? 'ใช้ค่าเริ่มต้นทั้งหมด' : `${c.scopedRateCount} อัตราเฉพาะ`),
    status: () => 'optional',
  },
  {
    id: 'withdrawal-min',
    step: 4,
    group: 'payout',
    question: 'ต้องมียอดขั้นต่ำก่อนเบิกไหม',
    why: 'ตั้งไว้แล้วตัวแทนจะกดเบิกไม่ได้จนกว่ายอดสะสมจะถึง — ไม่ได้ทำให้ค่าคอมหายไป แค่เลื่อนเวลาที่เบิกได้',
    required: false,
    writes: 'commission_withdrawal_settings.min_withdrawal_satang',
    answer: (c) => (c.withdrawalMinSatang === null ? 'ไม่กำหนด' : baht(c.withdrawalMinSatang)),
    status: () => 'optional',
  },
  {
    id: 'withholding-tax',
    step: 4,
    group: 'payout',
    question: 'หักภาษี ณ ที่จ่ายกี่เปอร์เซ็นต์',
    why: 'หักตอนโอนออก ไม่ได้เปลี่ยนตัวเลขค่าคอมที่ลงบัญชีไว้',
    required: false,
    writes: 'commission_withdrawal_settings.wht_rate',
    answer: (c) => (c.withholdingTaxPct === null ? 'ไม่หัก' : pct(c.withholdingTaxPct)),
    status: () => 'optional',
  },
]

const overrideModeCard: CommissionCard = {
  id: 'override-mode',
  step: 4,
  group: 'leader',
  question: 'เงินของหัวหน้าทีมมาจากไหน',
  why: 'บริษัทจ่ายเพิ่ม แปลว่าต้นทุนต่อดีลสูงขึ้น · หักจากค่าคอมของคนขาย แปลว่าต้นทุนเท่าเดิมแต่คนขายได้น้อยลง — สองอย่างนี้เป็นคนละเรื่องกันโดยสิ้นเชิง',
  required: true,
  writes: 'companies.commission_override_mode',
  answer: (c) => (c.overrideMode === null ? 'ยังไม่ได้เลือก' : (MODE_LABELS[c.overrideMode] ?? c.overrideMode)),
  status: (c) => (c.overrideMode === null ? 'todo' : 'done'),
}

/**
 * THE SET, PER PLAN.
 *
 * Order is reading order, not table order: what the plan IS, what it measures,
 * how it is shaped, what the seller gets, what the upline gets, how money
 * leaves. A card absent here is a setting the screen will never offer, so the
 * tests assert each plan's set by name rather than by count.
 */
export function cardsForPlan(plan: CommissionPlanType): CommissionCard[] {
  const opening = universalOpeningCards()
  const seller = sellerDefaultRateCard(plan)

  const structural: Record<CommissionPlanType, CommissionCard[]> = {
    unilevel: [],
    affiliate: [
      {
        id: 'affiliate-window',
        step: 2,
        group: 'structure',
        question: 'คลิกล่าสุดนับย้อนหลังได้กี่วัน',
        why: 'การซื้อที่เกิดหลังหน้าต่างนี้จะไม่ถูกนับว่ามาจากลิงก์พันธมิตร และผู้แนะนำจะไม่ได้ส่วนแบ่ง',
        required: true,
        writes: 'commission_affiliate_settings.attribution_window_days',
        answer: (c) =>
          c.affiliate?.attributionWindowDays ? `${c.affiliate.attributionWindowDays} วัน` : 'ยังไม่ได้ตั้ง',
        status: (c) => (c.affiliate?.attributionWindowDays ? 'done' : 'todo'),
      },
    ],
    binary: [
      {
        id: 'binary-matched-rate',
        step: 2,
        group: 'structure',
        question: 'จ่ายกี่เปอร์เซ็นต์ของยอดที่จับคู่ได้',
        why: 'Binary จ่ายจากยอดที่สองขาจับคู่กันได้ ไม่ใช่จากยอดรวมทั้งสองขา',
        required: true,
        writes: 'commission_binary_settings.matched_rate_value',
        answer: (c) => (c.binary?.matchedRatePct === null || !c.binary ? 'ยังไม่ได้ตั้ง' : pct(c.binary.matchedRatePct)),
        status: (c) => (c.binary?.matchedRatePct === null || !c.binary ? 'todo' : 'done'),
      },
      {
        id: 'binary-cycle',
        step: 2,
        group: 'structure',
        question: 'จับคู่และจ่ายรอบทุกเมื่อไร',
        why: 'ยอดที่ยังไม่ถูกจับคู่จะค้างอยู่จนกว่ารอบถัดไปจะทำงาน — ความถี่นี้จึงเป็นตัวกำหนดว่าตัวแทนรอเงินนานแค่ไหน',
        required: true,
        writes: 'commission_binary_settings.cycle_frequency',
        answer: (c) => c.binary?.cycle ?? 'ยังไม่ได้ตั้ง',
        status: (c) => (c.binary?.cycle ? 'done' : 'todo'),
      },
      {
        id: 'binary-cap-carry',
        step: 2,
        group: 'structure',
        question: 'มีเพดานต่อรอบไหม และยอดที่เหลือยกไปรอบหน้าหรือไม่',
        why: 'ไม่ยกยอดไปรอบหน้า แปลว่ายอดขาที่แข็งแรงกว่าถูกทิ้งทุกรอบ ซึ่งเปลี่ยนรายได้ระยะยาวของตัวแทนอย่างมาก',
        required: false,
        writes: 'commission_binary_settings.payout_cap_satang / carry_over_unmatched',
        answer: (c) => {
          if (!c.binary) return 'ยังไม่ได้ตั้ง'

          const cap = c.binary.capSatang === null ? 'ไม่มีเพดาน' : `เพดาน ${baht(c.binary.capSatang)}`

          return `${cap} · ${c.binary.carryOver ? 'ยกยอดไปรอบหน้า' : 'ไม่ยกยอด'}`
        },
        status: () => 'optional',
      },
    ],
    matrix: [
      {
        id: 'matrix-shape',
        step: 2,
        group: 'structure',
        question: 'ผังกว้างกี่คน ลึกกี่ชั้น',
        why: 'ความลึกคือเพดานการจ่ายจริง — ตั้งอัตราไว้ลึกกว่านี้ ชั้นที่เกินจะถูกบันทึกไว้และไม่มีวันได้เงิน',
        required: true,
        writes: 'commission_matrix_settings.width / depth',
        answer: (c) =>
          c.matrix?.width && c.matrix?.depth ? `กว้าง ${c.matrix.width} · ลึก ${c.matrix.depth} ชั้น` : 'ยังไม่ได้ตั้ง',
        status: (c) => (c.matrix?.width && c.matrix?.depth ? 'done' : 'todo'),
      },
      {
        id: 'matrix-level-rates',
        step: 2,
        group: 'structure',
        question: 'แต่ละชั้นในผังได้กี่เปอร์เซ็นต์',
        why: 'ชั้นที่ไม่ได้ตั้งอัตราจะไม่จ่ายอะไรเลย ไม่ใช่จ่ายเป็นศูนย์แล้วมีแถวให้เห็น',
        required: true,
        writes: 'commission_matrix_level_rates',
        answer: (c) => {
          const rates = c.matrix?.levelRatesPct ?? []

          return rates.length === 0 ? 'ยังไม่ได้ตั้ง' : rates.map((rate) => pct(rate)).join(' / ')
        },
        status: (c) => {
          const rates = c.matrix?.levelRatesPct ?? []

          if (rates.length === 0) return 'todo'

          // Priced deeper than the matrix goes: saveable, never paid.
          return c.matrix?.depth && rates.length > c.matrix.depth ? 'review' : 'done'
        },
      },
    ],
    generation: [
      {
        id: 'generation-depth',
        step: 2,
        group: 'structure',
        question: 'จ่ายย้อนขึ้นไปกี่รุ่น',
        why: 'รุ่นที่เกินเพดานนี้จะไม่ได้เงิน แม้จะตั้งอัตราไว้แล้วก็ตาม',
        required: true,
        writes: 'commission_generation_settings.max_generation_depth',
        answer: (c) => (c.generation?.maxDepth ? `${c.generation.maxDepth} รุ่น` : 'ยังไม่ได้ตั้ง'),
        status: (c) => (c.generation?.maxDepth ? 'done' : 'todo'),
      },
      {
        id: 'generation-rates',
        step: 2,
        group: 'structure',
        question: 'แต่ละรุ่นได้กี่เปอร์เซ็นต์',
        why: 'อัตราของแผนนี้มาจากตารางนี้ ไม่ใช่จากอัตราของขั้น — ขั้นถูกใช้เพียงเพื่อหาว่ารุ่นใหม่เริ่มตรงไหน',
        required: true,
        writes: 'commission_generation_rules',
        answer: (c) => {
          const rates = c.generation?.ratesPct ?? []

          return rates.length === 0 ? 'ยังไม่ได้ตั้ง' : rates.map((rate) => pct(rate)).join(' / ')
        },
        status: (c) => {
          const rates = c.generation?.ratesPct ?? []

          if (rates.length === 0) return 'todo'

          return c.generation?.maxDepth && rates.length > c.generation.maxDepth ? 'review' : 'done'
        },
      },
    ],
    stairstep_breakaway: [],
  }

  /*
   * THE LADDER BELONGS TO TWO PLANS, NOT ONE.
   *
   * Stairstep reads its rates AND its breakaway flag; Generation reads only
   * the flag, to find where a generation begins. Both therefore need the
   * ladder and the two settings that decide who holds which rung — without
   * them nobody is ever assigned a rank, and on Generation that means no
   * boundary is ever crossed and the plan pays no override at all.
   */
  const usesLadder = plan === 'stairstep_breakaway' || plan === 'generation'
  const ladder = usesLadder ? [rankLadderCard(plan), rankVolumeScopeCard, rankCadenceCard] : []

  /*
   * commission_override_rules is read by Unilevel and Affiliate ONLY. The
   * other four pay their upline out of their own structure, so asking them
   * for a leader rate would invent a setting with no effect — which is the
   * defect on the current screen this whole redesign starts from.
   */
  const leader: CommissionCard[] =
    plan === 'unilevel'
      ? [
          {
            id: 'leader-level-rates',
            step: 4,
            group: 'leader',
            question: 'หัวหน้าทีมได้กี่ชั้น ชั้นละเท่าไร',
            why: 'จ่ายขึ้นไปทั้งสาย ชั้นละอัตราของตัวเอง ไม่ใช่ส่วนต่าง — ยอดที่บริษัทจ่ายออกจึงโตตามจำนวนชั้น ไม่มีเพดานแบบแผนอันดับ',
            required: true,
            writes: 'commission_override_rules (แยกตาม level)',
            answer: (c) =>
              c.leaderLevelRatesPct.length === 0
                ? 'ยังไม่ได้ตั้ง'
                : `${c.leaderLevelRatesPct.length} ชั้น · ${c.leaderLevelRatesPct.map((rate) => pct(rate)).join(' / ')}`,
            status: (c) => {
              if (c.leaderLevelRatesPct.length === 0) return 'todo'

              // Rungs priced below the cap are saved and never paid — the
              // contradiction the old screen split across two steps.
              return c.maxOverrideDepth !== null && c.leaderLevelRatesPct.length > c.maxOverrideDepth
                ? 'review'
                : 'done'
            },
          },
          {
            id: 'leader-depth-cap',
            step: 4,
            group: 'leader',
            question: 'จ่ายขึ้นไปสูงสุดกี่ชั้น',
            why: 'ไม่ตั้งแปลว่าจ่ายทั้งสายเท่าที่มีอัตรา — ตั้งไว้ต่ำกว่าจำนวนชั้นที่ตั้งอัตราไว้ ชั้นล่างสุดจะไม่ได้เงิน',
            required: false,
            writes: 'companies.max_override_depth',
            answer: (c) => (c.maxOverrideDepth === null ? 'ไม่จำกัด' : `${c.maxOverrideDepth} ชั้น`),
            status: () => 'optional',
          },
          overrideModeCard,
        ]
      : plan === 'affiliate'
        ? [
            {
              id: 'affiliate-leader-rate',
              step: 4,
              group: 'leader',
              question: 'ผู้แนะนำได้กี่เปอร์เซ็นต์',
              why: 'แผนนี้จ่ายขึ้นไปชั้นเดียวเท่านั้น ไม่เดินต่อทั้งสายเหมือน Unilevel',
              required: true,
              writes: 'commission_override_rules',
              answer: (c) => (c.leaderFlatRatePct === null ? 'ยังไม่ได้ตั้ง' : pct(c.leaderFlatRatePct)),
              status: (c) => (c.leaderFlatRatePct === null ? 'todo' : 'done'),
            },
            overrideModeCard,
          ]
        : []

  const sellerSection = universalClosingCards.filter((card) => card.group === 'seller')
  const payoutSection = universalClosingCards.filter((card) => card.group === 'payout')

  return [
    ...opening,
    ...ladder,
    ...structural[plan],
    seller,
    ...sellerSection,
    ...leader,
    ...payoutSection,
  ]
}

/** What this plan asks THIS company right now, hidden cards dropped. */
export function visibleCards(plan: CommissionPlanType, context: CardContext): CommissionCard[] {
  return cardsForPlan(plan).filter((card) => !card.hidden?.(context))
}

/** The cards a company must answer before a deal can pay anybody. */
export function requiredCards(plan: CommissionPlanType): CommissionCard[] {
  return cardsForPlan(plan).filter((card) => card.required)
}

/**
 * The step tabs' "4 จาก 5 การ์ด" counter.
 *
 * `optional` cards are excluded from BOTH sides, deliberately: a step that
 * reads 3/5 because somebody skipped the withholding-tax question is telling
 * an admin they are unfinished when they are not — the same failure the
 * blanket amber banner had before TASK-213.
 */
export function stepProgress(
  plan: CommissionPlanType,
  context: CardContext,
  step: 2 | 3 | 4,
): { answered: number; total: number } {
  const cards = visibleCards(plan, context).filter(
    (card) => card.step === step && card.status(context) !== 'optional',
  )

  return {
    answered: cards.filter((card) => card.status(context) === 'done').length,
    total: cards.length,
  }
}

/**
 * Overview grouping (C1): cards by TOPIC, ignoring which step owns them.
 *
 * This regrouping is the only reason the overview fixes anything the card
 * list does not. On the four-step screen the rank ladder (step 2) and the
 * seller rate it must agree with (step 3) are two tabs apart and cannot be
 * compared by eye; under `seller` and `ladder` they sit one above the other.
 */
export function cardsByGroup(
  plan: CommissionPlanType,
  context: CardContext,
): { group: CardGroup; cards: CommissionCard[] }[] {
  const order: CardGroup[] = ['plan', 'seller', 'ladder', 'structure', 'leader', 'payout']
  const cards = visibleCards(plan, context)

  return order
    .map((group) => ({ group, cards: cards.filter((card) => card.group === group) }))
    .filter((entry) => entry.cards.length > 0)
}

export const GROUP_LABELS: Record<CardGroup, string> = {
  plan: 'แผนและฐานที่ใช้คิด',
  seller: 'เงินของคนขาย',
  ladder: 'บันไดอันดับและการเลื่อนขั้น',
  structure: 'โครงสร้างของแผน',
  leader: 'เงินของหัวหน้าทีม',
  payout: 'การจ่ายเงินออก',
}
