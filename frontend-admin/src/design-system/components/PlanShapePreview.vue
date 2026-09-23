<script setup lang="ts">
/**
 * PlanShapePreview — ผังของแผน และตัวอย่างว่าใครได้เท่าไร (owner, 2026-09-19).
 *
 * ── WHY ──
 *
 * Owner, looking at step 2: "คือมันดูไม่ง่ายเลยในการ Setup ในแต่ละแผน ผมอยากได้
 * แบบแผนภูมิ ที่เป็นตัวอย่างในแต่ละแบบ และ Setup ค่าคอมในแต่ละชั้นพร้อมตัวอย่าง".
 *
 * Step 2 asked an admin to choose between six compensation plans from six
 * chips and one paragraph each. A paragraph can say "หัวหน้าสายได้ส่วนแบ่งจาก
 * ยอดลูกทีม" without letting the reader answer the only question they actually
 * have: if somebody sells one 9,900 package, who gets paid, and what does the
 * company pay out in total. Six plans that each read as one sentence are six
 * plans nobody can choose between.
 *
 * ── WHY IT COMPUTES CLIENT-SIDE, UNLIKE RateImpactPreview ──
 *
 * RateImpactPreview refuses to do its own arithmetic because it previews a
 * SAVE: if it disagreed with the server it would be lying at the exact moment
 * of commit. This component previews nothing. Every figure in it is INVENTED —
 * a sample price, a sample rate, a sample depth — none of it is this company's
 * configuration and none of it reaches any endpoint, so there is no server
 * number for it to contradict. Its subject is the SHAPE of a plan, not this
 * company's rates, and the header says so in as many words.
 *
 * What it does borrow from the real engine is the arithmetic: integer satang,
 * rate as basis points, exactly one round() at the multiply (BR-3, the same
 * shape as CommissionRateCalculator::compute). A teaching example that rounds
 * differently from the ledger teaches the wrong thing.
 *
 * ── 2026-09-21: IT IS NOW ALSO THE PLACE THE RATES ARE SET ──
 *
 * The paragraph that used to sit here said the per-level rows were "part of
 * the EXAMPLE, not a settings form", because Unilevel had no per-level rate
 * table in the backend and an editor would have been a form with nowhere to
 * save. That table shipped on 2026-09-19
 * (commission_override_rules.level), and leaving this as a sandbox turned out
 * to be the wrong half of the owner's request: they asked for a chart AND
 * "Setup ค่าคอมในแต่ละชั้นพร้อมตัวอย่าง" — the setting and its consequence in
 * one place — and what they got was a picture behind a button with the real
 * setting two steps away.
 *
 * So this component now has two modes, and the props decide which:
 *
 *   SANDBOX (no `seed`, no `liveLevelRates`) — unchanged. Invented numbers,
 *   nothing saved, the shape of a plan the company does NOT run. This is what
 *   the five chips the admin is only browsing still get.
 *
 *   LIVE (`seed` and/or `liveLevelRates` given) — the figures are the
 *   company's own: a real product's price and PV, the real default agent rate,
 *   the real per-level leader rates. The rows become read-only here and the
 *   parent renders the editor through the `rates` slot, because persistence
 *   belongs to the screen that owns the API, not to a design-system component.
 *
 * The header sentence changes with the mode. A card that says "ตัวเลขสมมติ
 * ทั้งหมด" over the company's real rates is worse than no card.
 */
import { computed, getCurrentInstance, ref, watch } from 'vue'

type PlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'
type Basis = 'price' | 'pv'

/**
 * The company's own numbers, in satang and percent, or null where the company
 * has not set one.
 *
 * Satang in, because BR-3 keeps money an integer everywhere it travels; the
 * one division by 100 happens as it lands in the baht-denominated inputs.
 */
export interface PlanShapeSeed {
  priceSatang: number | null
  pvSatang: number | null
  sellerRatePct: number | null
  productName: string | null
  /**
   * The company's Binary settings row, or null when it has none (and on
   * every other plan). See PlanShapeBinarySeed for why this is one object
   * rather than three nullable fields.
   */
  binary: PlanShapeBinarySeed | null
}

/**
 * 2026-09-23 — the Binary knobs the card CLAIMED were the company's.
 *
 * Owner, reading the Binary chart against the UAT payout screen: "ทำไมผลลัพธ์
 * ถึงขึ้นแบบในรูปที่ 2 รูปที่ 3". The card's own subtitle says ใช้ค่าจริงของ
 * บริษัท, and on Binary that was half true — price and seller rate came from
 * the company, while the matched rate, the cap and the carry-over were the
 * sandbox constants below, identical on every company that opened the screen.
 * The ฿5,000 cap belonged to no company at all, and the 10% matched rate
 * happening to equal the real one is what made the whole card look sourced.
 *
 * ── WHY ONE OBJECT AND NOT THREE NULLABLE FIELDS ──
 *
 * `payoutCapSatang: null` is a REAL answer — "no cap" — so the seed rule used
 * by every other field here ("null keeps the sandbox value") cannot express
 * it. The presence of this object is the question "does the company have a
 * Binary settings row at all", and the nulls inside it are answers.
 */
export interface PlanShapeBinarySeed {
  /**
   * Null when the company prices the match as a FIXED amount per cycle: the
   * chart can only draw "x% of the matched volume", and inventing the
   * percentage that a fixed amount happens to equal for one leg total would
   * move the moment anybody changed a leg. Same treatment as sellerRatePct.
   */
  matchedRatePct: number | null
  /** Null is the answer "ไม่จำกัด", not a missing value. */
  payoutCapSatang: number | null
  carryOverUnmatched: boolean
}

const props = withDefaults(defineProps<{
  planType: PlanType
  /** The company's real basis, so the example speaks in the units they chose. */
  basis: Basis
  /**
   * Real values to start from. Null keeps the invented sandbox numbers — which
   * is correct for a plan the company does not run, and for a company with no
   * sellable product yet.
   */
  seed?: PlanShapeSeed | null
  /**
   * The company's actual per-level leader rates, as percentages, level 1
   * first. Null means "no live ladder" and the sandbox rows stay editable.
   * An EMPTY array is a real answer — the company runs Unilevel and has
   * priced no level yet — and renders as such rather than as absence.
   */
  liveLevelRates?: number[] | null
  /** Open on mount. Step 2 passes true; a plan being browsed does not. */
  defaultOpen?: boolean
}>(), {
  seed: null,
  liveLevelRates: null,
  defaultOpen: false,
})

/**
 * 2026-09-21 — the boxes in the diagram are also the way INTO the setting.
 *
 * Owner: "สามารถคลิ๊กที่ฝั่งในแต่ละตำแหน่งเพื่อพาไป setup ค่าคอมได้สะดวก".
 *
 * The component names a DESTINATION and nothing else — `seller-rate`,
 * `level:2`, `rank-ladder`. It does not know which step owns that control, it
 * does not scroll anything, and it never navigates: the screen that renders
 * the controls is the only thing that can say where they are, and a
 * design-system component that hardcoded a step number would be wrong the
 * first time the steps are reordered.
 *
 * `level:N` is the Nth RUNG OF THE LADDER AS DRAWN, counting from the top,
 * not a `commission_override_rules.level`. The diagram is drawn from
 * `liveLevelRates`, which is a list in order — it carries no row ids and no
 * level numbers — so the index is the only thing this component honestly
 * knows. The parent maps rung → its own row, which is also what keeps this
 * working on a ladder whose levels are not 1,2,3.
 */
const emit = defineEmits<{ 'focus-target': [target: string] }>()

/** True when any figure on screen belongs to the company rather than to nobody. */
const isLive = computed(() => props.seed !== null || props.liveLevelRates !== null)

/*
 * Nothing is clickable unless somebody is listening.
 *
 * A box that looks pressable and does nothing is worse than a box that looks
 * like a picture, and this component is used in two places today: step 2,
 * which wires the jump, and any future caller that wants the chart alone. The
 * affordance follows the listener rather than a prop, so a caller cannot
 * forget to turn one of the two off.
 */
const listenerBound = computed(() => Boolean(getCurrentInstance()?.vnode.props?.onFocusTarget))

/**
 * And only over the company's OWN plan. In sandbox mode every figure is
 * invented, so "ไปตั้งค่าอัตรานี้" would point at a setting that has nothing
 * to do with the box that was pressed.
 */
const nodesClickable = computed(() => isLive.value && listenerBound.value)

/** Which box currently holds keyboard focus — SVG has no :focus-visible we can style. */
const focusedNode = ref<number | null>(null)

function canPress(n: Node): boolean {
  return nodesClickable.value && typeof n.focus === 'string'
}

function press(n: Node): void {
  if (!canPress(n) || !n.focus) return

  emit('focus-target', n.focus)
}

/* ── money: integer satang, one round at the multiply (BR-3) ────────────── */
const S = (baht: number) => Math.round((Number(baht) || 0) * 100)
/** rate% → basis points → satang, rounded once, exactly like the backend. */
const at = (baseSatang: number, ratePct: number) =>
  Math.round((baseSatang * ((Number(ratePct) || 0) * 100)) / 10000)
const fmt = (satang: number) =>
  (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })

/* ── sandbox inputs (nothing here is saved) ─────────────────────────────── */
const price = ref(9900)
const discount = ref(0)
const pv = ref(7000)
const sellerRate = ref(10)

const uniMode = ref<'flat' | 'level'>('flat')
const uniFlat = ref(3)
const uniLevels = ref<number[]>([5, 3, 1])

/*
 * The two leg totals stay invented, and that is not an oversight: there is no
 * company-level "left volume" to read. binary_leg_volumes is one running
 * balance PER AGENT, so the honest thing for a chart drawing one generic
 * person is a pair of what-if boxes — labelled as such under the inputs.
 */
const binLeft = ref(50000)
const binRight = ref(30000)
const binRate = ref(10)
/**
 * Empty means ไม่จำกัด, and empty is now a STARTING state, not just a
 * cleared one — a company whose payout_cap_satang is null seeds it that way.
 *
 * `v-model.number` leaves a cleared input as `''`, so the ref genuinely holds
 * three shapes and the type says so. The old reading (`=== null ? null :
 * S(value)`) sent `''` through `Number('')` → 0 and relied on a `cap > 0`
 * test further down to come out right; it did, but by a second rule in
 * another place, which is not something to leave under a value the seeding
 * now produces on load.
 */
const binCap = ref<number | '' | null>(5000)
const binCarry = ref(true)

/** The cap in satang, or null for ไม่จำกัด — the server's own two states. */
const binCapSatang = computed<number | null>(() => {
  const raw = binCap.value

  if (raw === null || raw === undefined || raw === '') return null

  const n = Number(raw)

  return Number.isFinite(n) ? S(n) : null
})

const mxWidth = ref(3)
const mxLevels = ref<number[]>([5, 3, 2])

const genLevels = ref<number[]>([5, 3, 2])

const afRate = ref(3)
const afMode = ref<'additive' | 'deductive'>('additive')

const RANKS = [
  { name: 'เริ่มต้น', threshold: 0, rate: 5, breakaway: false },
  { name: 'ผู้นำ', threshold: 200000, rate: 10, breakaway: false },
  { name: 'ผู้จัดการ', threshold: 500000, rate: 15, breakaway: true },
]
/* noUncheckedIndexedAccess is on: the two selects can only ever hold a valid
   index, but the compiler cannot know that, so clamp instead of asserting. */
type Rank = { name: string; threshold: number; rate: number; breakaway: boolean }
const FALLBACK_RANK: Rank = { name: 'ไม่มีขั้น', threshold: 0, rate: 0, breakaway: false }
const rankAt = (i: number): Rank => RANKS[Math.min(Math.max(i, 0), RANKS.length - 1)] ?? FALLBACK_RANK

const ssSeller = ref(0)
const ssManager = ref(2)

/*
 * 2026-09-21 — the default is now the caller's to choose, and step 2 chooses
 * OPEN.
 *
 * It was collapsed, with a comment arguing that step 2 is "a decision screen,
 * not a playground". That reasoning does not survive contact with what the
 * screen is for: the decision IS the numbers, and a decision aid nobody opens
 * is a decision aid nobody has. The owner asked for a chart and got a button
 * saying ดูตัวอย่าง.
 *
 * Still never re-collapses when the plan chip changes — closing it on every
 * click would be the same as not having it.
 */
const open = ref(props.defaultOpen)
watch(() => props.planType, () => { /* keep whatever the admin chose */ })

/*
 * ── SEEDING ──────────────────────────────────────────────────────────────
 *
 * The company's own figures replace the invented ones as soon as they arrive,
 * and again whenever they change (a different product adopted, the default
 * agent rate edited on step 3). A field the company has NOT set keeps the
 * sandbox value rather than collapsing to zero: a chart drawn at 0% teaches
 * that this plan pays nobody, which is a claim about the plan rather than
 * about the missing setting.
 *
 * `immediate` because the seed usually arrives before the first paint, and a
 * first frame of invented numbers over a live card is exactly the flicker
 * that makes somebody doubt the figure they are reading.
 */
watch(() => props.seed, (next) => {
  if (!next) return

  if (next.priceSatang !== null) price.value = next.priceSatang / 100
  if (next.pvSatang !== null) pv.value = next.pvSatang / 100
  if (next.sellerRatePct !== null) sellerRate.value = next.sellerRatePct

  /*
   * The cap and the carry-over are applied UNCONDITIONALLY once the row
   * exists, unlike every field above. Both have a legitimate "off" value —
   * ไม่จำกัด and ไม่ยกยอด — and keeping the sandbox's ฿5,000 / ticked box
   * because the company's answer was falsy would draw the opposite of what
   * the company configured, which is the exact bug this seeding fixes.
   */
  if (next.binary) {
    if (next.binary.matchedRatePct !== null) binRate.value = next.binary.matchedRatePct
    binCap.value = next.binary.payoutCapSatang === null ? '' : next.binary.payoutCapSatang / 100
    binCarry.value = next.binary.carryOverUnmatched
  }
}, { immediate: true, deep: true })

/*
 * The live ladder, mirrored into the sandbox array the whole model already
 * reads from. Mirrored rather than branched on in twenty places: every
 * computed below — the diagram, the rows, the total — keeps its single source,
 * and the only thing `liveLevelRates` changes is where that source got filled
 * from and whether the rows are editable here.
 *
 * An empty live ladder falls back to ONE level at 0%, because the diagram
 * needs at least one rung to draw and a company that has priced nothing is
 * exactly who most needs to see that nobody above the seller is being paid.
 */
watch(() => props.liveLevelRates, (next) => {
  if (next === null || next === undefined) return

  uniMode.value = 'level'
  uniLevels.value = next.length > 0 ? [...next] : [0]
}, { immediate: true, deep: true })

/** In live mode the rows are read-only here — the parent owns the editor. */
const levelsAreLive = computed(() => props.planType === 'unilevel' && props.liveLevelRates !== null)

/* ── derived ────────────────────────────────────────────────────────────── */
const saleSatang = computed(() =>
  Math.round((S(price.value) * (100 - (Number(discount.value) || 0))) / 100))
const baseSatang = computed(() => (props.basis === 'pv' ? S(pv.value) : saleSatang.value))

interface Row { who: string; rate: string; amount?: number; text?: string; tone?: 'self' | 'muted' | 'plain'; note?: string }
interface Node {
  x: number; y: number; w: number; h: number
  title: string; sub?: string
  kind?: 'self' | 'plain' | 'ghost'
  /**
   * Where pressing this box should take the reader, named for the CONTROL and
   * never for the step that happens to hold it today. Omitted on a box that
   * stands for a person rather than a setting (a matrix seat, a binary leg's
   * running total) — there is nothing to open, and inventing a nearby
   * destination would teach that the box and the setting are the same thing.
   */
  focus?: string
  /**
   * What the reader is told they are about to open, in their own words. Read
   * out by screen readers and, on a box whose own text does not already say
   * it, printed under the title — which is the whole point on the ghost row:
   * "ยังไม่ถึงขั้น" explains why the money stopped but not where the rule that
   * decides it lives.
   */
  focusLabel?: string
}
interface Edge { x1: number; y1: number; x2: number; y2: number; muted?: boolean }
interface Label { x: number; y: number; text: string; tone?: 'money' | 'quiet' | 'bad'; anchor?: 'start' | 'middle' | 'end' }
interface Shape { w: number; h: number; nodes: Node[]; edges: Edge[]; labels: Label[]; alt: string }

const NODE_H = 44

const model = computed<{ rows: Row[]; shape: Shape; caption: string; totalNote?: string }>(() => {
  const b = baseSatang.value

  if (props.planType === 'unilevel') {
    const rates = uniMode.value === 'flat' ? uniLevels.value.map(() => uniFlat.value) : uniLevels.value.slice()
    const rows: Row[] = [{ who: 'คนขาย', rate: `${sellerRate.value}%`, amount: at(b, sellerRate.value), tone: 'self' }]
    rates.forEach((r, i) => rows.push({
      who: i === 0 ? 'ชั้น 1 (หัวหน้าโดยตรง)' : `ชั้น ${i + 1}`,
      rate: `${r}%`, amount: at(b, r),
    }))
    const n = rates.length
    const gap = 30, x = 150, w = 190
    const nodes: Node[] = [], edges: Edge[] = [], labels: Label[] = []
    for (let i = n; i >= 0; i--) {
      const y = 16 + (n - i) * (NODE_H + gap)
      nodes.push({
        x, y, w, h: NODE_H,
        title: i === 0 ? 'คนขาย' : `ชั้น ${i}`,
        sub: i === 0 ? 'ปิดการขาย' : i === 1 ? 'หัวหน้าโดยตรง' : 'หัวหน้าเหนือขึ้นไป',
        kind: i === 0 ? 'self' : 'plain',
        focus: i === 0 ? 'seller-rate' : `level:${i}`,
        focusLabel: i === 0 ? 'อัตราของคนขาย' : `อัตราของชั้นที่ ${i}`,
      })
      if (i > 0) {
        const levelRate = rates[i - 1] ?? 0
        edges.push({ x1: x + w / 2, y1: y + NODE_H + gap, x2: x + w / 2, y2: y + NODE_H + 6 })
        labels.push({ x: x + w / 2 + 10, y: y + NODE_H + gap / 2 + 4, text: `${levelRate}% = ${fmt(at(b, levelRate))} บาท`, tone: 'money' })
      }
    }
    const h = 26 + (n + 1) * (NODE_H + gap)
    labels.push({ x: x + w / 2, y: h - 10, anchor: 'middle', tone: 'quiet', text: `ลูกค้าจ่าย ${fmt(saleSatang.value)} บาท · ฐานที่ใช้คิด ${fmt(b)}` })
    return {
      rows,
      shape: { w: 470, h, nodes, edges, labels, alt: 'ผัง Unilevel คนขายอยู่ล่างสุด เงินไหลขึ้นไปหาหัวหน้าทีละชั้น' },
      caption: uniMode.value === 'flat'
        ? 'แบบที่ระบบทำอยู่จริงวันนี้ — อัตราเดียวกันทุกชั้น และเดินขึ้นไปเรื่อย ๆ ไม่มีเพดาน กดเพิ่มชั้นแล้วดูยอดจ่ายออกรวม'
        : 'แบบที่เสนอ — อัตราลดหลั่นรายชั้นและหยุดที่ชั้นสุดท้าย ยังไม่มีในระบบ',
    }
  }

  if (props.planType === 'binary') {
    const L = S(binLeft.value), R = S(binRight.value)
    const matched = Math.min(L, R)
    const raw = at(matched, binRate.value)
    const cap = binCapSatang.value
    const paid = cap !== null && cap > 0 ? Math.min(raw, cap) : raw
    const cL = binCarry.value ? L - matched : 0
    const cR = binCarry.value ? R - matched : 0
    const rows: Row[] = [
      { who: 'ยอดขาซ้าย', rate: '—', amount: L, tone: 'plain' },
      { who: 'ยอดขาขวา', rate: '—', amount: R, tone: 'plain' },
      { who: 'จับคู่ได้ (ขาที่น้อยกว่า)', rate: '—', amount: matched, tone: 'plain' },
      { who: 'ค่าคอมก่อนเพดาน', rate: `${binRate.value}%`, amount: raw, tone: 'plain' },
    ]
    if (cap !== null && cap > 0 && raw > cap) rows.push({ who: 'ถูกเพดานตัดออก', rate: '—', amount: -(raw - cap), tone: 'muted' })
    rows.push({ who: 'จ่ายจริงรอบนี้', rate: '—', amount: paid, tone: 'self' })
    rows.push({ who: 'ยกไปรอบหน้า ซ้าย / ขวา', rate: '—', text: `${fmt(cL)} / ${fmt(cR)}`, tone: 'muted' })
    return {
      rows,
      shape: {
        w: 470, h: 250,
        nodes: [
          { x: 140, y: 16, w: 190, h: 46, title: 'คนรับค่าคอม', sub: 'จับคู่ซ้าย-ขวา', kind: 'self', focus: 'binary', focusLabel: 'อัตราจับคู่และเพดานต่อรอบ' },
          // The two legs are running totals of other people's sales, not
          // settings — there is no "ขาซ้าย" to open.
          { x: 20, y: 150, w: 170, h: 46, title: 'ขาซ้าย', sub: `${fmt(L)} หน่วย`, kind: 'plain' },
          { x: 280, y: 150, w: 170, h: 46, title: 'ขาขวา', sub: `${fmt(R)} หน่วย`, kind: 'plain' },
        ],
        edges: [
          { x1: 105, y1: 150, x2: 105, y2: 110, muted: true },
          { x1: 365, y1: 150, x2: 365, y2: 110, muted: true },
          { x1: 105, y1: 110, x2: 365, y2: 110, muted: true },
          { x1: 235, y1: 110, x2: 235, y2: 66 },
        ],
        labels: [
          { x: 235, y: 100, anchor: 'middle', tone: 'money', text: `จับคู่ได้ ${fmt(matched)} → จ่าย ${fmt(paid)} บาท` },
          { x: 20, y: 218, tone: 'quiet', text: `ยกไปรอบหน้า ${fmt(cL)}` },
          { x: 450, y: 218, anchor: 'end', tone: 'quiet', text: `ยกไปรอบหน้า ${fmt(cR)}` },
          /*
           * 2026-09-23 — this line used to read, in red, "ยังไม่มีหน้าจอให้
           * เลือกว่าใครอยู่ขาไหน". True when the chart was drawn; false since
           * 2026-09-19, when AgentEditModal gained the ขาในผัง Binary select.
           * The warning was simply never deleted, so the diagram kept raising
           * an alarm about a gap that had been closed — which is how a screen
           * teaches people to ignore its red text.
           *
           * Quiet, not bad: where the control lives is an instruction, not a
           * fault. It stays on the diagram rather than moving into a caption
           * because "ขาซ้าย / ขาขวา" is exactly what somebody is looking at
           * when the question occurs to them.
           */
          { x: 235, y: 238, anchor: 'middle', tone: 'quiet', text: 'ตั้งขาของแต่ละคนที่ จัดการผู้ใช้ระบบ → แก้ไขตัวแทน' },
        ],
        alt: 'ผัง Binary สองขาซ้ายขวา จ่ายจากยอดขาที่น้อยกว่า',
      },
      caption: 'จ่ายจากขาที่อ่อนกว่าเสมอ ส่วนเกินของขาแข็งจะยกไปรอบหน้าหรือถูกล้าง ขึ้นกับการตั้งค่า — ลองตั้งสองขาให้เท่ากันแล้วดูว่าจ่ายออกสูงสุดเท่าไร',
      totalNote: 'Binary คิดจากยอดสะสมของรอบ ไม่ได้ผูกกับการขายครั้งเดียว จึงไม่มี % ของราคาให้เทียบ',
    }
  }

  if (props.planType === 'matrix') {
    const rows: Row[] = [{ who: 'คนขาย', rate: `${sellerRate.value}%`, amount: at(b, sellerRate.value), tone: 'self' }]
    mxLevels.value.forEach((r, i) => rows.push({ who: `ขึ้นไป ${i + 1} ชั้นในผัง`, rate: `${r}%`, amount: at(b, r) }))
    const w = Math.max(2, Math.min(5, Number(mxWidth.value) || 3))
    let seats = 0, p = 1
    for (let k = 0; k < mxLevels.value.length; k++) { p *= w; seats += p }
    const gap = 12
    const cw = Math.min(120, (430 - (w - 1) * gap) / w)
    const x0 = (470 - (w * cw + (w - 1) * gap)) / 2
    const nodes: Node[] = [{ x: 160, y: 16, w: 150, h: 42, title: 'ชั้นบนสุด', kind: 'plain', focus: 'matrix-levels', focusLabel: 'อัตราตามชั้นของผัง' }]
    const edges: Edge[] = []
    for (let i = 0; i < w; i++) {
      const x = x0 + i * (cw + gap)
      // A seat, not a rate. What a seat is paid depends on its DEPTH, and the
      // seats drawn here are all on the same one.
      nodes.push({ x, y: 118, w: cw, h: 42, title: `ช่อง ${i + 1}`, sub: i === w - 1 ? 'ช่องสุดท้าย' : undefined, kind: 'plain' })
      edges.push({ x1: x + cw / 2, y1: 118, x2: 235, y2: 58, muted: true })
    }
    nodes.push({ x: 160, y: 210, w: 150, h: 46, title: 'คนขาย', sub: 'อยู่ชั้นล่าง', kind: 'self', focus: 'seller-rate', focusLabel: 'อัตราของคนขาย' })
    edges.push({ x1: 235, y1: 210, x2: 235, y2: 182, muted: true })
    edges.push({ x1: 235, y1: 182, x2: x0 + cw / 2, y2: 182, muted: true })
    edges.push({ x1: x0 + cw / 2, y1: 182, x2: x0 + cw / 2, y2: 160 })
    return {
      rows,
      shape: {
        w: 470, h: 300, nodes, edges,
        labels: [
          { x: 243, y: 176, tone: 'money', text: 'เงินเดินขึ้นตามผัง จ่ายตามอัตราของชั้นนั้น' },
          { x: 235, y: 284, anchor: 'middle', tone: 'quiet', text: `ผังกว้าง ${w} ลึก ${mxLevels.value.length} → รับได้สูงสุด ${seats.toLocaleString('th-TH')} คน · คนที่ ${w + 1} ล้นลงชั้นถัดไปอัตโนมัติ` },
        ],
        alt: 'ผัง Matrix กว้างจำกัดลึกจำกัด คนล้นลงชั้นถัดไป',
      },
      caption: 'กว้างเท่าไรก็รับได้เท่านั้นต่อชั้น คนที่ล้นถูกวางลงชั้นถัดไปให้เอง หัวหน้าจึงได้ลูกทีมที่ตัวเองไม่ได้ชวนด้วย — นี่คือแผนเดียวที่มีตารางอัตราทีละชั้นอยู่จริงแล้ว',
    }
  }

  if (props.planType === 'stairstep_breakaway') {
    const sr = rankAt(ssSeller.value), mr = rankAt(ssManager.value)
    const diff = mr.rate - sr.rate
    const blocked = sr.breakaway
    const mgrAmount = blocked || diff <= 0 ? 0 : at(b, diff)
    const rows: Row[] = [{ who: `คนขาย — ขั้น ${sr.name}`, rate: `${sr.rate}%`, amount: at(b, sr.rate), tone: 'self' }]
    if (blocked) rows.push({ who: `หัวหน้า — ขั้น ${mr.name}`, rate: 'ตัดสายแล้ว', text: '0', tone: 'muted', note: 'ได้ศูนย์ถาวร' })
    else if (diff <= 0) rows.push({ who: `หัวหน้า — ขั้น ${mr.name}`, rate: `ส่วนต่าง ${diff}%`, text: '0', tone: 'muted', note: 'อัตราไม่สูงกว่าลูกทีม' })
    else rows.push({ who: `หัวหน้า — ขั้น ${mr.name}`, rate: `ส่วนต่าง ${diff}%`, amount: mgrAmount })
    const nodes: Node[] = [], labels: Label[] = []
    RANKS.forEach((r, i) => {
      const x = 20 + i * 10, y = 196 - i * 56
      nodes.push({ x, y, w: 150, h: 44, title: r.name, sub: `${r.rate}% · ยอด ${r.threshold / 1000}k`, kind: 'plain', focus: 'rank-ladder', focusLabel: 'บันไดขั้นและเกณฑ์ยอด' })
      if (r.breakaway) labels.push({ x: x + 158, y: y + 27, tone: 'bad', text: 'ตัดสาย' })
    })
    labels.push({ x: 20, y: 252, tone: 'quiet', text: 'บันไดขั้น — ยอดถึงเกณฑ์แล้วเลื่อนขั้นเอง' })
    nodes.push({ x: 300, y: 190, w: 150, h: 46, title: 'คนขาย', sub: sr.name, kind: 'self', focus: 'rank-ladder', focusLabel: 'ขั้นของคนขายและอัตราของขั้นนั้น' })
    nodes.push({ x: 300, y: 60, w: 150, h: 46, title: 'หัวหน้า', sub: mr.name, kind: 'plain', focus: 'rank-ladder', focusLabel: 'ขั้นของหัวหน้าและอัตราของขั้นนั้น' })
    labels.push(blocked
      ? { x: 250, y: 152, tone: 'bad', text: 'ตัดสายแล้ว = 0' }
      : { x: 250, y: 152, tone: diff > 0 ? 'money' : 'quiet', text: `ส่วนต่าง ${diff}% = ${fmt(mgrAmount)}` })
    return {
      rows,
      shape: {
        w: 470, h: 282, nodes,
        edges: [{ x1: 375, y1: 190, x2: 375, y2: 112, muted: blocked || diff <= 0 }],
        labels, alt: 'ผังแผนอันดับ หัวหน้าได้ส่วนต่างของอัตราระหว่างขั้นตัวเองกับขั้นลูกทีม',
      },
      caption: 'หัวหน้าไม่ได้อัตราเต็ม แต่ได้เฉพาะส่วนที่สูงกว่าลูกทีม — เลื่อนลูกทีมขึ้นเป็นขั้นตัดสายแล้วดูว่าหัวหน้าเหลือเท่าไร',
    }
  }

  if (props.planType === 'generation') {
    const chain = [
      { name: 'คนขาย', breakaway: false, self: true },
      { name: 'หัวหน้า ก', breakaway: false, self: false },
      { name: 'หัวหน้า ข', breakaway: true, self: false },
      { name: 'หัวหน้า ค', breakaway: false, self: false },
      { name: 'หัวหน้า ง', breakaway: true, self: false },
    ]
    const reached = chain.filter((c, i) => i > 0 && c.breakaway).length
    const rows: Row[] = [{ who: 'คนขาย', rate: `${sellerRate.value}%`, amount: at(b, sellerRate.value), tone: 'self' }]
    genLevels.value.forEach((r, i) => {
      if (i < reached) rows.push({ who: `รุ่นที่ ${i + 1} (คนที่ถึงขั้นตัดสาย)`, rate: `${r}%`, amount: at(b, r) })
      else rows.push({ who: `รุ่นที่ ${i + 1}`, rate: `${r}%`, text: '0', tone: 'muted', note: 'ไม่มีใครถึงขั้นตัดสายในสายตัวอย่างนี้' })
    })
    const gap = 24
    const nodes: Node[] = [], edges: Edge[] = [], labels: Label[] = []
    let gen = 0
    for (let i = chain.length - 1; i >= 0; i--) {
      const c = chain[i]
      if (!c) continue
      const y = 12 + (chain.length - 1 - i) * (40 + gap)
      /*
       * Three boxes, three destinations.
       *
       * The seller's own rate is a rate; a breakaway leader is paid by the
       * generation table; and the GHOST — the one that reads "ยังไม่ถึงขั้น" —
       * is the box somebody actually wants to press, because it is the only
       * thing on the chart that explains why a real person got nothing. It
       * does not belong to the generation table at all: whether that person
       * counts is decided by the rank ladder, so that is where it points, and
       * the box says so rather than leaving the reader to press and find out.
       */
      nodes.push({
        x: 130, y, w: 190, h: 40,
        title: c.name,
        sub: c.breakaway ? 'ถึงขั้นตัดสาย' : 'ยังไม่ถึงขั้น',
        kind: c.self ? 'self' : c.breakaway ? 'plain' : 'ghost',
        focus: c.self ? 'seller-rate' : c.breakaway ? 'generation' : 'rank-ladder',
        focusLabel: c.self
          ? 'อัตราของคนขาย'
          : c.breakaway
            ? 'อัตราของแต่ละรุ่น'
            : 'เกณฑ์ขั้นตัดสาย',
      })
      if (i > 0) edges.push({ x1: 225, y1: y + 40 + gap, x2: 225, y2: y + 44, muted: !c.breakaway })
    }
    for (let i = 1; i < chain.length; i++) {
      const c = chain[i]
      if (!c) continue
      const y = 12 + (chain.length - 1 - i) * (40 + gap)
      if (c.breakaway && gen < genLevels.value.length) {
        gen++
        const genRate = genLevels.value[gen - 1] ?? 0
        labels.push({ x: 328, y: y + 24, tone: 'money', text: `รุ่นที่ ${gen} → ${genRate}% = ${fmt(at(b, genRate))}` })
      } else if (!c.breakaway) {
        labels.push({ x: 328, y: y + 24, tone: 'quiet', text: 'ข้ามไป ไม่ได้อะไร' })
      }
    }
    return {
      rows,
      shape: { w: 470, h: 20 + chain.length * (40 + gap), nodes, edges, labels, alt: 'ผัง Generation จ่ายเฉพาะคนที่ถึงขั้นตัดสาย คนอื่นถูกข้าม' },
      caption: 'เดินขึ้นจากคนขาย คนที่ยังไม่ถึงขั้นตัดสายถูกข้ามไปเฉย ๆ ไม่นับเป็นรุ่นและไม่ได้เงิน — รุ่นถูกนับเมื่อเจอคนที่ถึงขั้นแล้วเท่านั้น',
    }
  }

  // affiliate
  const sellerFull = at(b, sellerRate.value)
  const mgr = afMode.value === 'additive' ? at(b, afRate.value) : at(sellerFull, afRate.value)
  const sellerFinal = afMode.value === 'additive' ? sellerFull : sellerFull - mgr
  return {
    rows: [
      { who: afMode.value === 'additive' ? 'คนขาย' : 'คนขาย (หลังถูกหัก)', rate: `${sellerRate.value}%`, amount: sellerFinal, tone: 'self' },
      {
        who: afMode.value === 'additive' ? 'ผู้แนะนำ (บริษัทจ่ายเพิ่ม)' : 'ผู้แนะนำ (หักจากคนขาย)',
        rate: afMode.value === 'additive' ? `${afRate.value}% ของยอด` : `${afRate.value}% ของค่าคอมคนขาย`,
        amount: mgr,
      },
    ],
    shape: {
      w: 470, h: 212,
      nodes: [
        { x: 140, y: 142, w: 190, h: 46, title: 'คนขาย', sub: `${fmt(sellerFinal)} บาท`, kind: 'self', focus: 'seller-rate', focusLabel: 'อัตราของคนขาย' },
        { x: 140, y: 22, w: 190, h: 46, title: 'ผู้แนะนำ', sub: `${fmt(mgr)} บาท`, kind: 'plain', focus: 'affiliate', focusLabel: 'อัตราของผู้แนะนำ' },
      ],
      edges: [{ x1: 235, y1: 142, x2: 235, y2: 74 }],
      labels: [
        { x: 245, y: 112, tone: 'money', text: `${afMode.value === 'additive' ? 'บริษัทจ่ายเพิ่ม' : 'หักจากค่าคอมคนขาย'} ${afRate.value}%` },
        { x: 235, y: 204, anchor: 'middle', tone: 'quiet', text: 'จบแค่หนึ่งชั้น ไม่เดินขึ้นต่อ' },
      ],
      alt: 'ผังพันธมิตร จ่ายผู้แนะนำโดยตรงหนึ่งชั้นเท่านั้น',
    },
    caption: afMode.value === 'additive'
      ? 'บริษัทจ่ายผู้แนะนำเพิ่มจากกระเป๋าตัวเอง ค่าคอมคนขายไม่ลด — ยอดจ่ายออกรวมจึงสูงขึ้น'
      : 'ผู้แนะนำได้ส่วนแบ่งจากค่าคอมของคนขายเอง บริษัทจ่ายออกเท่าเดิม แต่คนขายได้น้อยลง',
  }
})

const payoutSatang = computed(() =>
  model.value.rows.reduce((sum, r) => (r.tone === 'plain' || r.tone === 'muted' || r.text != null ? sum : sum + (r.amount ?? 0)), 0))
const payoutPercent = computed(() => {
  if (!saleSatang.value) return '0'
  return (payoutSatang.value / saleSatang.value * 100).toFixed(2).replace(/\.?0+$/, '')
})

/** Which plans have a per-level example list, and what a level is called. */
const levelList = computed<{ arr: number[]; word: string } | null>(() => {
  if (props.planType === 'unilevel') return { arr: uniLevels.value, word: 'ชั้น' }
  if (props.planType === 'matrix') return { arr: mxLevels.value, word: 'ชั้น' }
  if (props.planType === 'generation') return { arr: genLevels.value, word: 'รุ่นที่' }
  return null
})
const flatShown = computed(() => props.planType === 'unilevel' && uniMode.value === 'flat')
function addLevel() { const l = levelList.value; if (l && l.arr.length < 8) l.arr.push(Math.max(0.5, l.arr[l.arr.length - 1] ?? 1)) }
function removeLevel() { const l = levelList.value; if (l && l.arr.length > 1) l.arr.pop() }

function nodeClass(kind?: Node['kind']) {
  if (kind === 'self') return 'fill-brand-50 stroke-brand-600'
  if (kind === 'ghost') return 'fill-transparent stroke-slate-300 [stroke-dasharray:4_4]'
  return 'fill-slate-50 stroke-slate-300'
}
function labelClass(tone?: Label['tone']) {
  if (tone === 'money') return 'fill-emerald-700 text-[11.5px] font-bold'
  if (tone === 'bad') return 'fill-rose-600 text-[11.5px] font-bold'
  return 'fill-slate-400 text-[11px]'
}
</script>

<template>
  <div class="rounded-2xl border border-slate-200 bg-white" data-test="plan-shape-preview">
    <button
      type="button"
      class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left"
      :aria-expanded="open"
      data-test="plan-shape-toggle"
      @click="open = !open"
    >
      <span class="min-w-0">
        <span class="block text-[15px] font-extrabold text-slate-900">
          {{ isLive ? 'เงินจะไหลแบบนี้ ด้วยค่าที่คุณตั้งไว้ตอนนี้' : 'ผังแผนนี้ และตัวอย่างว่าใครได้เท่าไร' }}
        </span>
        <!-- The sentence changes with the mode. A card that says "ตัวเลขสมมติ
             ทั้งหมด" over the company's real rates is worse than no card. -->
        <span class="mt-0.5 block text-[12.5px] text-slate-500" data-test="plan-shape-subtitle">
          <template v-if="isLive">
            ใช้ค่าจริงของบริษัท<template v-if="seed?.productName"> · ตัวอย่างจากสินค้า “{{ seed.productName }}”</template>
            · ปรับตัวเลขด้านล่างเพื่อลองดูได้ ไม่กระทบค่าที่บันทึกไว้
          </template>
          <template v-else>
            ตัวเลขสมมติทั้งหมด ไม่ใช่ค่าที่บริษัทตั้งไว้ และไม่มีอะไรถูกบันทึก
          </template>
        </span>
      </span>
      <span class="shrink-0 text-[12.5px] font-bold text-brand-600">{{ open ? 'ซ่อน' : 'ดูตัวอย่าง' }}</span>
    </button>

    <div v-if="open" class="border-t border-slate-200 px-4 py-4">
      <!-- sandbox inputs -->
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="block">
          <span class="block text-[12px] font-bold text-slate-500">ราคาขาย (บาท)</span>
          <input v-model.number="price" type="number" min="0" step="100" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="sample-price">
        </label>
        <label class="block">
          <span class="block text-[12px] font-bold text-slate-500">ส่วนลดโปรโมชัน (%)</span>
          <input v-model.number="discount" type="number" min="0" max="90" step="5" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="sample-discount">
        </label>
        <label v-if="basis === 'pv'" class="block">
          <span class="block text-[12px] font-bold text-slate-500">PV ของสินค้า</span>
          <input v-model.number="pv" type="number" min="0" step="100" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="sample-pv">
        </label>
        <label class="block">
          <span class="block text-[12px] font-bold text-slate-500">อัตราคนขาย (%)</span>
          <input v-model.number="sellerRate" type="number" min="0" max="100" step="0.5" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="sample-seller-rate">
        </label>
      </div>

      <p class="mt-2 text-[12px] text-slate-500" data-test="sample-basis-note">
        บริษัทนี้คิดค่าคอมจาก<b class="text-slate-700">{{ basis === 'pv' ? ' PV' : 'ราคาขาย' }}</b>
        — ฐานที่เอาไปคูณ % คือ <b class="text-slate-700">{{ fmt(baseSatang) }}</b>
        <span v-if="basis === 'pv'"> · ส่วนลดจึงไม่ทำให้ค่าคอมหายไป</span>
      </p>

      <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <!-- ผัง -->
        <figure class="min-w-0">
          <svg
            class="block h-auto w-full max-w-full"
            :viewBox="`0 0 ${model.shape.w} ${model.shape.h}`"
            role="img"
            :aria-label="model.shape.alt"
          >
            <defs>
              <marker id="psp-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                <path d="M0,0 L10,5 L0,10 z" class="fill-emerald-700" />
              </marker>
            </defs>
            <line
              v-for="(e, i) in model.shape.edges"
              :key="`e${i}`"
              :x1="e.x1" :y1="e.y1" :x2="e.x2" :y2="e.y2"
              :class="e.muted ? 'stroke-slate-300 [stroke-dasharray:4_4]' : 'stroke-emerald-700'"
              stroke-width="2"
              :marker-end="e.muted ? undefined : 'url(#psp-arrow)'"
            />
            <!--
              A pressable box is a BUTTON, not a rect with a click handler.

              role + tabindex + Enter/Space + a visible ring are not polish
              here: this screen is used on a tablet and with a keyboard, and an
              SVG shape that only answers to a mouse would put the one
              convenient route to every rate behind a pointing device. The ring
              is drawn rather than styled because SVG gives us no
              :focus-visible we can rely on across the browsers this admin runs.
            -->
            <g
              v-for="(n, i) in model.shape.nodes"
              :key="`n${i}`"
              :role="canPress(n) ? 'button' : undefined"
              :tabindex="canPress(n) ? 0 : undefined"
              :aria-label="canPress(n) ? `${n.title} — ไปตั้งค่า${n.focusLabel ? ' ' + n.focusLabel : ''}` : undefined"
              :class="canPress(n) ? 'cursor-pointer outline-none' : undefined"
              :data-test="canPress(n) ? `plan-node-${n.focus}` : undefined"
              @click="press(n)"
              @keydown.enter.prevent="press(n)"
              @keydown.space.prevent="press(n)"
              @focus="focusedNode = i"
              @blur="focusedNode = null"
            >
              <rect
                v-if="focusedNode === i && canPress(n)"
                :x="n.x - 4" :y="n.y - 4" :width="n.w + 8" :height="n.h + 8"
                rx="13" fill="none" stroke-width="2.5"
                class="stroke-brand-500"
                data-test="plan-node-focus-ring"
              />
              <rect
                :x="n.x" :y="n.y" :width="n.w" :height="n.h" rx="9" stroke-width="1.5"
                :class="[nodeClass(n.kind), canPress(n) ? 'transition-colors hover:stroke-brand-600' : '']"
              />
              <text :x="n.x + n.w / 2" :y="n.sub ? n.y + n.h / 2 - 3 : n.y + n.h / 2 + 4" text-anchor="middle" class="fill-slate-900 text-[12.5px] font-bold">{{ n.title }}</text>
              <text v-if="n.sub" :x="n.x + n.w / 2" :y="n.y + n.h / 2 + 13" text-anchor="middle" class="fill-slate-400 text-[11px]">{{ n.sub }}</text>
            </g>
            <text
              v-for="(l, i) in model.shape.labels"
              :key="`l${i}`"
              :x="l.x" :y="l.y" :text-anchor="l.anchor ?? 'start'"
              :class="labelClass(l.tone)"
            >{{ l.text }}</text>
          </svg>
          <figcaption class="mt-2 text-[12px] leading-relaxed text-slate-500">{{ model.caption }}</figcaption>
          <!--
            Two sentences, and which one shows is the honest answer to "why
            does pressing do nothing". A picture that is inert on five chips
            and live on the sixth, with no line saying which, reads as a bug
            in the five.
          -->
          <p v-if="nodesClickable" class="mt-1.5 text-[12px] font-bold text-brand-700" data-test="plan-node-hint">
            กดที่กล่องในผังเพื่อไปยังที่ตั้งค่าของกล่องนั้นได้
          </p>
          <p v-else-if="listenerBound" class="mt-1.5 text-[12px] text-slate-400" data-test="plan-node-hint-inert">
            กดกล่องในผังไม่ได้ — นี่เป็นแผนที่บริษัทยังไม่ได้ใช้ ตัวเลขทั้งหมดเป็นตัวอย่าง
          </p>
        </figure>

        <!-- ตัวเลข -->
        <div class="min-w-0">
          <!-- per-plan knobs -->
          <!--
            2026-09-21 — the labels used to read "(ระบบตอนนี้)" and "(ที่เสนอ)",
            written when per-level rates did not exist in the backend. They do
            now (commission_override_rules.level, 2026-09-19), so the second
            label was telling the admin that a thing they can actually save is
            only a proposal.

            Hidden entirely in live mode: there the ladder IS whatever the
            company has saved, and a toggle offering to pretend otherwise would
            invite somebody to "switch" their plan by pressing a button that
            saves nothing.
          -->
          <div v-if="planType === 'unilevel' && !levelsAreLive" class="mb-3 inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
            <button
              v-for="m in (['flat', 'level'] as const)"
              :key="m"
              type="button"
              class="rounded-lg px-3 py-1.5 text-[12.5px]"
              :class="uniMode === m ? 'bg-white font-extrabold text-slate-900 shadow-sm' : 'font-semibold text-slate-500'"
              :data-test="`uni-mode-${m}`"
              @click="uniMode = m"
            >{{ m === 'flat' ? 'อัตราเดียวทุกชั้น' : 'อัตราทีละชั้น' }}</button>
          </div>

          <label v-if="flatShown" class="mb-3 block">
            <span class="block text-[12px] font-bold text-slate-500">อัตราหัวหน้าทุกชั้น (%)</span>
            <input v-model.number="uniFlat" type="number" min="0" max="100" step="0.5" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="uni-flat-rate">
          </label>

          <div v-if="planType === 'binary'" class="mb-3 grid gap-3 sm:grid-cols-2">
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">ยอดขาซ้าย</span>
              <input v-model.number="binLeft" type="number" min="0" step="1000" data-test="bin-left" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></label>
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">ยอดขาขวา</span>
              <input v-model.number="binRight" type="number" min="0" step="1000" data-test="bin-right" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></label>
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">อัตราจับคู่ (%)</span>
              <input v-model.number="binRate" type="number" min="0" max="100" step="0.5" data-test="bin-rate" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></label>
            <!-- Label matches the real form's word for word: the same box on
                 two screens must not disagree about what empty means. -->
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">เพดานต่อรอบ (บาท, ว่าง = ไม่จำกัด)</span>
              <input v-model.number="binCap" type="number" min="0" step="500" data-test="bin-cap" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></label>
            <label class="flex items-center gap-2 text-[12.5px] font-semibold text-slate-600 sm:col-span-2">
              <input v-model="binCarry" type="checkbox" data-test="bin-carry" class="h-4 w-4"> ยกยอดที่เหลือไปรอบหน้า
            </label>
            <!--
              WHICH OF THESE FIVE IS THE COMPANY'S, said where the boxes are.
              The card's subtitle claims the whole chart is real, and on this
              plan two of the boxes cannot be: there is no company-level leg
              total to read, only a per-agent running balance. One line, at
              the controls it describes — an ⓘ would hide the correction from
              exactly the person already misreading the numbers.
            -->
            <p v-if="seed?.binary" class="text-[12px] text-slate-500 sm:col-span-2" data-test="bin-seed-note">
              อัตราจับคู่ เพดาน และการยกยอด มาจากค่าที่บริษัทตั้งไว้จริง ·
              <b>ยอดสองขาเป็นตัวเลขลองเล่น</b> — ระบบเก็บยอดขาแยกรายคน ไม่มียอดรวมระดับบริษัทให้ดึงมา
            </p>
          </div>

          <label v-if="planType === 'matrix'" class="mb-3 block">
            <span class="block text-[12px] font-bold text-slate-500">ผังกว้างกี่ช่อง</span>
            <input v-model.number="mxWidth" type="number" min="2" max="5" step="1" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
          </label>

          <div v-if="planType === 'stairstep_breakaway'" class="mb-3 grid gap-3 sm:grid-cols-2">
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">ขั้นของคนขาย</span>
              <select v-model.number="ssSeller" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" data-test="ss-seller-rank">
                <option v-for="(r, i) in RANKS" :key="i" :value="i">{{ r.name }} — {{ r.rate }}%{{ r.breakaway ? ' (ตัดสาย)' : '' }}</option>
              </select></label>
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">ขั้นของหัวหน้า</span>
              <select v-model.number="ssManager" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <option v-for="(r, i) in RANKS" :key="i" :value="i">{{ r.name }} — {{ r.rate }}%{{ r.breakaway ? ' (ตัดสาย)' : '' }}</option>
              </select></label>
          </div>

          <div v-if="planType === 'affiliate'" class="mb-3 space-y-3">
            <label class="block"><span class="block text-[12px] font-bold text-slate-500">อัตราผู้แนะนำ (%)</span>
              <input v-model.number="afRate" type="number" min="0" max="100" step="0.5" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></label>
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
              <button
                v-for="m in (['additive', 'deductive'] as const)"
                :key="m"
                type="button"
                class="rounded-lg px-3 py-1.5 text-[12.5px]"
                :class="afMode === m ? 'bg-white font-extrabold text-slate-900 shadow-sm' : 'font-semibold text-slate-500'"
                @click="afMode = m"
              >{{ m === 'additive' ? 'บริษัทจ่ายเพิ่ม' : 'หักจากคนขาย' }}</button>
            </div>
          </div>

          <!--
            ═══ THE EDITOR GOES HERE, AND IT BELONGS TO THE PARENT ═══

            In live mode the screen renders the real per-level rate form into
            this slot — right of the diagram, exactly where the prototype put
            it, so a rate and its consequence are read in one glance.

            The form is NOT built into this component. Persistence means an
            endpoint, an Ability and an error surface, and a design-system
            component that owned those would be a second door onto
            commission_override_rules — the thing every other rate control on
            this screen is careful not to be.

            The sandbox rows below stay for the plans being browsed.
          -->
          <slot v-if="levelsAreLive" name="rates" :base-satang="baseSatang" :format="fmt" :at="at" />

          <!-- per-level example rows -->
          <div v-else-if="levelList">
            <p class="text-[12.5px] font-bold text-slate-600">{{ flatShown ? 'สายลึกกี่ชั้น' : 'อัตราแต่ละ' + levelList.word }}</p>
            <div class="mt-1.5 space-y-1.5">
              <div v-for="(r, i) in levelList.arr" :key="i" class="grid grid-cols-[1fr_84px_110px] items-center gap-2">
                <span class="text-[13px] text-slate-600">{{ levelList.word }} {{ i + 1 }}</span>
                <span v-if="flatShown" class="text-right font-mono text-[13px] text-slate-500">{{ uniFlat }}%</span>
                <input
                  v-else
                  :value="levelList.arr[i]"
                  type="number" min="0" max="100" step="0.5"
                  class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm text-right"
                  :aria-label="`${levelList.word} ${i + 1}`"
                  :data-test="`level-rate-${i}`"
                  @input="levelList.arr[i] = Number(($event.target as HTMLInputElement).value) || 0"
                >
                <span class="text-right font-mono text-[13px] font-bold text-emerald-700">{{ fmt(at(baseSatang, flatShown ? uniFlat : r)) }} บาท</span>
              </div>
            </div>
            <div class="mt-2.5 flex gap-2">
              <button type="button" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-[12.5px] font-bold text-slate-600 transition-colors hover:border-brand-600 hover:text-brand-600 disabled:opacity-40 disabled:cursor-not-allowed" data-test="level-add" @click="addLevel">+ เพิ่ม{{ levelList.word }}</button>
              <button type="button" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-[12.5px] font-bold text-slate-600 transition-colors hover:border-brand-600 hover:text-brand-600 disabled:opacity-40 disabled:cursor-not-allowed" :disabled="levelList.arr.length <= 1" data-test="level-remove" @click="removeLevel">− ลด{{ levelList.word }}</button>
            </div>
          </div>

          <!-- who gets what -->
          <p class="mt-4 border-t border-slate-200 pt-3 text-[12.5px] font-bold text-slate-600">สรุปต่อการขาย 1 ครั้ง</p>
          <table class="mt-1.5 w-full text-[13.5px]">
            <thead>
              <tr class="text-[11.5px] uppercase tracking-wide text-slate-400">
                <th class="py-1.5 text-left font-bold">ใคร</th>
                <th class="py-1.5 text-right font-bold">อัตรา</th>
                <th class="py-1.5 text-right font-bold">ได้ (บาท)</th>
              </tr>
            </thead>
            <tbody data-test="plan-shape-rows">
              <tr
                v-for="(r, i) in model.rows"
                :key="i"
                class="border-t border-slate-100"
                :class="r.tone === 'self' ? 'bg-brand-50/60' : r.tone === 'muted' ? 'text-slate-400' : ''"
              >
                <td class="py-2">
                  {{ r.who }}
                  <span v-if="r.note" class="text-[11.5px] text-slate-400">· {{ r.note }}</span>
                </td>
                <td class="py-2 text-right font-mono tabular-nums">{{ r.rate }}</td>
                <td
                  class="py-2 text-right font-mono tabular-nums"
                  :class="r.tone === 'plain' || r.tone === 'muted' ? '' : 'font-bold text-emerald-700'"
                >{{ r.text ?? fmt(r.amount ?? 0) }}</td>
              </tr>
            </tbody>
          </table>

          <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-3" data-test="plan-shape-total">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <span class="text-[12.5px] font-extrabold text-emerald-800">บริษัทจ่ายออกรวม</span>
              <span class="font-mono text-[20px] font-bold tabular-nums text-emerald-800">
                {{ fmt(payoutSatang) }} บาท<template v-if="!model.totalNote"> · {{ payoutPercent }}%</template>
              </span>
            </div>
            <p class="mt-0.5 text-[11.5px] text-emerald-800/80">
              {{ model.totalNote ?? `จากราคาที่ลูกค้าจ่ายจริง ${fmt(saleSatang)} บาท` }}
            </p>
          </div>
        </div>
      </div>

      <p class="mt-4 border-t border-slate-100 pt-3 text-[11.5px] leading-relaxed text-slate-400">
        คำนวณเป็นสตางค์จำนวนเต็มและปัดครั้งเดียวตอนคูณอัตรา แบบเดียวกับที่ระบบคิดจริง —
        ตัวเลขตัวอย่างนี้จึงตรงกับที่บัญชีค่าแนะนำจะบันทึก ไม่ใช่การประมาณ
      </p>
    </div>
  </div>
</template>
