<script setup lang="ts">
/**
 * RateResolutionMatrix — สินค้า x 3 ชั้น, one row per product.
 *
 * Owner, 2026-09-14: "การ setup 3 ระดับ … ส่งผลต่อคำนวณค่าคอมตอน setting
 * ทำได้ไม่ชัดเจน" — and, when offered a read-only explainer versus this,
 * "ข้อเสนอ 2 ค่อนข้างเห็นได้ง่ายชัดเจนทำให้แก้ไขได้เลย". He was right and the
 * reason he was right is worth keeping: an explainer tells you why and then
 * sends you somewhere else to act. This does both in one place.
 *
 * ── WHAT THE THREE COLUMNS ARE ──
 *
 * The same ladder the server walks, laid out horizontally instead of as a
 * sequence of steps: บริษัท, หมวดหมู่, สินค้า. The cell that WINS is solid;
 * the ones that lost are struck through. That is Chrome DevTools' CSS panel,
 * borrowed deliberately — the cascade is the same problem and strikethrough is
 * how that problem was solved legibly fifteen years ago.
 *
 * ── WHY IT DOES NOT COMPUTE ANYTHING ──
 *
 * Every number here arrives from GET /commission-resolution, produced by
 * CommissionService itself. This component does no arithmetic and resolves no
 * rules, on purpose: the screen's OWN copy of this ladder is what once showed
 * (and paid) a Thai Life rate on AIA. A table people use to decide what agents
 * earn is answered by the code that pays them, or it is not answered.
 *
 * ── IT IS ALSO THE PER-PRODUCT EDIT LIST NOW (2026-09-14) ──
 *
 * It used to sit BELOW a box called "3.3 แยกเฉพาะสินค้าที่มาร์จิ้นต่างจริง",
 * which listed the same products with the same rates and one edit button each.
 * The owner asked whether the two could be one thing; they could, and the
 * duplication was mine to begin with — 3.1 and 3.2 list RULES (a category rate
 * covering no product has a row there and none here, which is why they stay),
 * but 3.3 listed PRODUCTS, which is exactly what this table's rows are.
 *
 * So the box is gone and its three controls moved into these rows: the selling
 * switch, ทดสอบคำนวณ, and the per-product rate button — the last one being the
 * สินค้า cell, which was already a button that opened that very form.
 *
 * Two consequences worth keeping in mind before changing anything here:
 *
 *   · CLOSED PRODUCTS ARE ROWS. The server stopped filtering them out
 *     (CommissionResolutionService::configurableProducts) because the switch
 *     that reopens one lives on its row. Do not "tidy" them back out.
 *   · NOTHING IS HIDDEN BY DEFAULT. The old collapse showed only "interesting"
 *     rows — fine for a read-only explainer, wrong for the list where an admin
 *     checks that every product has been dealt with. The filter chips are the
 *     replacement: the reader narrows the list, the table never decides to.
 */
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'

type Layer = 'company' | 'category' | 'product'
type RowFilter = 'all' | 'own' | 'unpaid' | 'closed'

interface Rung {
  rule_id: number
  rate_type: 'percentage' | 'fixed_satang'
  rate_value: number
  amount_satang: number
}

interface LadderPayload {
  base_satang: number
  amount_base_satang: number
  company: Rung | null
  category: Rung | null
  product: Rung | null
  winner: Layer | null
  amount_satang: number | null
  override_mode?: string
  override_mode_source?: 'rule' | 'company'
}

export interface ResolutionRow {
  product_id: number
  name: string
  /** Whether this company sells it TODAY. Rating it is allowed either way. */
  is_sellable: boolean
  category: { id: number; name: string } | null
  base_satang: number
  agent: LadderPayload
  leader: LadderPayload
}

const props = withDefaults(defineProps<{
  rows: ResolutionRow[]
  /** Which of the two ladders this table is about. */
  kind: 'agent' | 'leader'
  loading?: boolean
  failed?: boolean
  /** Hidden entirely for a reader who cannot act on it — the house rule. */
  canEdit?: boolean
  basisLabel?: string
  modeLabels?: Record<string, string>
  /**
   * The selling switch and ทดสอบคำนวณ belong to the catalogue half of step 3.
   * Step 4's copy of this table is about leader rates and would be repeating
   * controls the reader already passed, so it asks for neither.
   */
  showSelling?: boolean
  showSimulate?: boolean
  /**
   * Which rows' switches this viewer may actually move. A per-row question,
   * not a role one: a shared product's on-sale flag belongs to the Super Admin
   * who owns the product (ADR-040), so a company admin sees the state and no
   * control — the house rule since 2026-09-11 is hide, never disable.
   */
  togglableProductIds?: number[]
  /**
   * Which rows may carry a PRODUCT-scoped rate at all — the server's own
   * answer (`permissions.set_commission_rule`), passed through.
   *
   * TASK-245 / ADR-036 §5/§6: a product linked to the shared catalogue is
   * rated centrally and a per-product rate on it is refused. The สินค้า cell
   * is the button that would ask for one, so a row that is not in this list
   * gets a dash there rather than an invitation to a 403.
   *
   * `null` means no restriction was supplied, not "none allowed" — a table
   * whose parent never answers the question is not the place to invent a
   * refusal.
   */
  rateableProductIds?: number[] | null
  savingProductId?: number | null
  sellingError?: string | null
  testId?: string
}>(), {
  loading: false,
  failed: false,
  canEdit: false,
  basisLabel: 'ยอดขาย',
  modeLabels: () => ({}),
  showSelling: false,
  showSimulate: false,
  togglableProductIds: () => [],
  rateableProductIds: null,
  savingProductId: null,
  sellingError: null,
  testId: 'resolution-matrix',
})

const emit = defineEmits<{
  /** A cell was clicked — open the rate form already pointed at that scope. */
  edit: [payload: { layer: Layer; row: ResolutionRow; ruleId: number | null }]
  toggleSelling: [row: ResolutionRow]
  simulate: [row: ResolutionRow]
  retry: []
}>()

const LAYERS: Array<{ key: Layer; label: string }> = [
  { key: 'company', label: 'บริษัท' },
  { key: 'category', label: 'หมวดหมู่' },
  { key: 'product', label: 'สินค้า' },
]

/** Narrowest first — the order the server actually tries them in. */
const LADDER_ORDER: Array<{ key: Layer; label: string }> = [
  { key: 'product', label: 'อัตราเฉพาะสินค้า' },
  { key: 'category', label: 'อัตราของหมวดหมู่' },
  { key: 'company', label: 'ค่าเริ่มต้นบริษัท' },
]

function ladderOf(row: ResolutionRow): LadderPayload {
  return props.kind === 'agent' ? row.agent : row.leader
}

function rungOf(row: ResolutionRow, layer: Layer): Rung | null {
  return ladderOf(row)[layer]
}

/**
 * "Nobody gets paid" — and a rate of zero counts, which a null winner alone
 * does not catch. Somebody who typed 0% at the product rung has switched this
 * product off for commission purposes just as completely as never setting one,
 * and only one of those two states used to be visible.
 */
function paysNobody(row: ResolutionRow): boolean {
  const ladder = ladderOf(row)

  return ladder.winner === null || ladder.amount_satang === 0
}

const activeFilter = ref<RowFilter>('all')

const counts = computed(() => ({
  all: props.rows.length,
  own: props.rows.filter((row) => ladderOf(row).product !== null).length,
  // Closed products are excluded from the count on purpose: a product nobody
  // sells paying nobody is not a problem, it is a tautology, and counting it
  // would put a red number on a screen with nothing to fix.
  unpaid: props.rows.filter((row) => row.is_sellable && paysNobody(row)).length,
  closed: props.rows.filter((row) => !row.is_sellable).length,
}))

const FILTERS: Array<{ key: RowFilter; label: string }> = [
  { key: 'all', label: 'ทั้งหมด' },
  { key: 'own', label: 'ตั้งเฉพาะสินค้านี้' },
  { key: 'unpaid', label: 'ไม่มีใครได้เงิน' },
  { key: 'closed', label: 'ปิดขายอยู่' },
]

/** A chip for an empty set is a control that does nothing — ทั้งหมด always shows. */
const visibleFilters = computed(() => FILTERS.filter((f) => f.key === 'all' || counts.value[f.key] > 0))

const visibleRows = computed(() => props.rows.filter((row) => {
  if (activeFilter.value === 'own') return ladderOf(row).product !== null
  if (activeFilter.value === 'unpaid') return row.is_sellable && paysNobody(row)
  if (activeFilter.value === 'closed') return !row.is_sellable

  return true
}))

/*
 * A filter whose rows all went away leaves an empty table with no explanation
 * — the reader's last action was clicking something else entirely. Falling
 * back to ทั้งหมด is the honest recovery: it is what they can see.
 */
watch(counts, (next) => {
  if (activeFilter.value !== 'all' && next[activeFilter.value] === 0) activeFilter.value = 'all'
})

const openRowId = ref<number | null>(null)

function toggleRow(row: ResolutionRow): void {
  openRowId.value = openRowId.value === row.product_id ? null : row.product_id
}

const columnCount = computed(() => 5 + (props.showSelling ? 1 : 0))

function canToggle(row: ResolutionRow): boolean {
  return props.showSelling && props.togglableProductIds.includes(row.product_id)
}

/**
 * Whether this cell can be clicked to set a rate.
 *
 * The category column on a product with no category is the one cell that is
 * neither set nor settable: a category rate cannot reach it whatever anybody
 * types. It renders as a dash, and inviting a click there would produce a rule
 * that resolves to nothing and reads as a system bug.
 */
function cellActionable(row: ResolutionRow, layer: Layer): boolean {
  if (!props.canEdit) return false
  if (layer === 'category' && row.category === null) return false
  if (layer === 'product' && props.rateableProductIds && !props.rateableProductIds.includes(row.product_id)) return false

  return true
}

function baht(satang: number | null): string {
  if (satang === null) return '—'

  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function rate(rung: Rung): string {
  return rung.rate_type === 'percentage'
    ? `${(rung.rate_value / 100).toFixed(2)}%`
    : `${baht(rung.rate_value)} บาท`
}

/** Why this rung lost — the sentence a strikethrough alone cannot say. */
function losingReason(row: ResolutionRow, layer: Layer): string {
  const winner = ladderOf(row).winner
  if (winner === null || winner === layer) return ''
  const by = winner === 'product' ? 'อัตราของสินค้า' : 'อัตราของหมวดหมู่'

  return `ถูกทับโดย${by}`
}

/**
 * The ladder as sentences, for the row somebody opened because the columns
 * did not settle it. Same four cases the server distinguishes, in the order
 * it tries them.
 */
function ladderSteps(row: ResolutionRow): Array<{ key: Layer; text: string; won: boolean }> {
  const winner = ladderOf(row).winner

  return LADDER_ORDER.map(({ key, label }) => {
    const rung = rungOf(row, key)
    let text: string
    if (key === 'category' && row.category === null) text = `${label} — สินค้านี้ไม่มีหมวดหมู่ จึงข้ามชั้นนี้`
    else if (!rung) text = `${label} — ยังไม่ได้ตั้ง จึงเลื่อนไปชั้นถัดไป`
    else if (key === winner) text = `${label} — ${rate(rung)} ใช้อันนี้ และหยุดที่นี่`
    else text = `${label} — ${rate(rung)} ตั้งไว้แต่ไม่ถูกใช้ เพราะชั้นที่แคบกว่าชนะไปก่อน`

    return { key, text, won: key === winner }
  })
}
</script>

<template>
  <section class="rounded-2xl border border-slate-200 bg-white" :data-test="testId">
    <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-slate-100">
      <p class="text-[14px] font-extrabold text-slate-900">
        {{ kind === 'agent' ? 'อัตราต่อสินค้า และผลลัพธ์' : 'ผลลัพธ์: หัวหน้าทีมได้เท่าไหร่' }}
      </p>
      <span
        v-if="counts.unpaid"
        class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-rose-100 text-rose-700"
        :data-test="`${testId}-unpaid`"
      >
        {{ counts.unpaid }} สินค้าไม่มีใครได้เงิน
      </span>
      <span v-else-if="rows.length" class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-emerald-100 text-emerald-700">
        ครบทุกสินค้าที่เปิดขาย
      </span>
      <span class="ml-auto text-[11.5px] text-slate-400">
        ตัวเลขจากระบบที่จ่ายเงินจริง · คิดจาก{{ basisLabel }}
      </span>
    </div>

    <p v-if="loading" class="px-4 py-6 text-[13px] text-slate-400">กำลังคำนวณ...</p>

    <div v-else-if="failed" class="px-4 py-5" :data-test="`${testId}-failed`">
      <p class="text-[13px] font-extrabold text-rose-700">คำนวณผลลัพธ์ไม่สำเร็จ</p>
      <!-- No half-table, and no table built from the browser's own guess. The
           whole reason this comes from the server is that a wrong number here
           gets believed. -->
      <p class="mt-1 text-[12.5px] text-rose-600">
        ระบบยังไม่รู้ว่าสินค้าแต่ละตัวจ่ายเท่าไหร่ จึงไม่แสดงตัวเลขที่อาจไม่ตรงกับที่จ่ายจริง
      </p>
      <button type="button" class="mt-2 btn-secondary" :data-test="`${testId}-retry`" @click="emit('retry')">ลองใหม่</button>
    </div>

    <p v-else-if="!rows.length" class="px-4 py-6 text-[13px] text-slate-400">ยังไม่มีสินค้าในแคตตาล็อกของบริษัทนี้</p>

    <template v-else>
      <!-- A failed switch has to say so where the switch is. A visible line,
           never a silently reverted row. -->
      <p
        v-if="sellingError"
        class="px-4 pt-3 text-[12.5px] font-bold text-rose-600"
        :data-test="`${testId}-selling-error`"
      >{{ sellingError }}</p>

      <!--
        THE READER NARROWS THE LIST; THE TABLE NEVER DOES.
        This replaced a "show the other 38 products" collapse that was right
        while this was an explainer and wrong the moment it became the place
        you check that every product has been dealt with — a product silently
        omitted is exactly the product nobody notices is unset.
      -->
      <div
        v-if="visibleFilters.length > 1"
        class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-slate-100"
        :data-test="`${testId}-filters`"
      >
        <button
          v-for="f in visibleFilters"
          :key="f.key"
          type="button"
          class="text-[12px] font-bold rounded-full px-3 py-1 border transition-colors"
          :class="activeFilter === f.key
            ? 'border-brand-500 bg-brand-50 text-brand-700'
            : 'border-slate-200 text-slate-500 hover:bg-slate-50'"
          :aria-pressed="activeFilter === f.key"
          :data-test="`${testId}-filter-${f.key}`"
          @click="activeFilter = f.key"
        >
          {{ f.label }}
          <span class="ml-1 tabular-nums opacity-70">{{ counts[f.key] }}</span>
        </button>
        <span class="ml-auto text-[11.5px] text-slate-400" :data-test="`${testId}-showing`">
          แสดง {{ visibleRows.length }} จาก {{ rows.length }} สินค้า
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-[12.5px] min-w-[44rem]">
          <thead>
            <tr class="text-slate-500 bg-slate-50/70">
              <th class="text-left font-bold px-4 py-2">สินค้า</th>
              <th v-for="l in LAYERS" :key="l.key" class="text-right font-bold px-3 py-2">{{ l.label }}</th>
              <th class="text-right font-bold px-4 py-2">จ่ายจริง</th>
              <th v-if="showSelling" class="text-right font-bold px-4 py-2">เปิดขาย</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in visibleRows" :key="row.product_id">
              <tr
                class="border-t border-slate-100 align-top"
                :class="row.is_sellable ? '' : 'border-l-2 border-l-slate-300'"
                :data-test="`${testId}-row-${row.product_id}`"
                :data-selling="row.is_sellable ? 'open' : 'closed'"
              >
                <td class="px-4 py-2.5">
                  <button
                    type="button"
                    class="flex items-start gap-2 text-left w-full"
                    :aria-expanded="openRowId === row.product_id"
                    :data-test="`${testId}-expand-${row.product_id}`"
                    @click="toggleRow(row)"
                  >
                    <Icon
                      :name="openRowId === row.product_id ? 'chevron_up' : 'chevron_down'"
                      :size="12"
                      class="mt-1 shrink-0 text-slate-400"
                    />
                    <span class="min-w-0">
                      <!-- A CLOSED PRODUCT IS NOT GREY. Grey is what a locked
                           control wears on this screen, and "ปิดขายอยู่" is a
                           state the admin chose and can undo with the switch
                           in this very row — not a refusal. A label and a
                           dashed edge; the text stays readable. -->
                      <span
                        v-if="!row.is_sellable"
                        class="mr-1.5 align-middle text-[11px] font-bold rounded-full px-2 py-0.5 bg-slate-100 text-slate-500"
                        :data-test="`${testId}-closed-${row.product_id}`"
                      >ปิดขายอยู่</span>
                      <span class="font-bold text-slate-900">{{ row.name }}</span>
                      <span class="block text-[11.5px] text-slate-400">
                        {{ row.category?.name ?? 'ไม่มีหมวดหมู่' }} · {{ baht(row.base_satang) }}
                      </span>
                    </span>
                  </button>
                </td>

                <td
                  v-for="l in LAYERS"
                  :key="l.key"
                  class="px-3 py-2.5 text-right tabular-nums"
                  :data-test="`${testId}-cell-${row.product_id}-${l.key}`"
                >
                  <!-- SET, AND WINNING. Solid, and the money is the big number:
                       the percentage is the input, the baht is the consequence,
                       and the consequence is what people are checking. -->
                  <button
                    v-if="rungOf(row, l.key) && ladderOf(row).winner === l.key"
                    type="button"
                    class="font-extrabold text-slate-900"
                    :class="cellActionable(row, l.key) ? 'hover:underline' : 'cursor-default'"
                    :disabled="!cellActionable(row, l.key)"
                    @click="emit('edit', { layer: l.key, row, ruleId: rungOf(row, l.key)!.rule_id })"
                  >
                    {{ rate(rungOf(row, l.key)!) }}
                    <span class="block text-[11px] font-bold text-brand-700">{{ baht(rungOf(row, l.key)!.amount_satang) }}</span>
                  </button>

                  <!-- SET, AND LOSING. Struck through, with the reason on hover
                       — borrowed from DevTools, where the strikethrough teaches
                       the cascade without a word of documentation. -->
                  <button
                    v-else-if="rungOf(row, l.key)"
                    type="button"
                    class="text-slate-400 line-through decoration-slate-300"
                    :class="cellActionable(row, l.key) ? 'hover:text-slate-600' : 'cursor-default'"
                    :disabled="!cellActionable(row, l.key)"
                    :title="losingReason(row, l.key)"
                    @click="emit('edit', { layer: l.key, row, ruleId: rungOf(row, l.key)!.rule_id })"
                  >
                    {{ rate(rungOf(row, l.key)!) }}
                    <span class="block text-[11px]">{{ baht(rungOf(row, l.key)!.amount_satang) }}</span>
                  </button>

                  <!-- CANNOT APPLY. A product in no category can never be reached
                       by a category rate, so this is a dash and not an invitation.
                       Rendering it as an empty settable cell would produce a rule
                       that resolves to nothing and reads as a bug in the system. -->
                  <span v-else-if="l.key === 'category' && row.category === null" class="text-slate-300">—</span>

                  <!-- EMPTY AND SETTABLE. On the สินค้า column this IS the old
                       "+ ตั้งอัตราเฉพาะสินค้านี้" button from the box below,
                       which is why that box no longer exists. -->
                  <button
                    v-else-if="cellActionable(row, l.key)"
                    type="button"
                    class="text-slate-300 hover:text-brand-600 font-bold"
                    :data-test="l.key === 'product' ? `${testId}-add-product-rate-${row.product_id}` : undefined"
                    @click="emit('edit', { layer: l.key, row, ruleId: null })"
                  >
                    + ตั้ง
                  </button>
                  <!-- Empty, and NOT settable. The commonest reason is that the
                       viewer may not write rates at all, which the note under
                       the table says once rather than forty times; the other is
                       a catalogue-linked product, which cannot carry its own
                       rate however much anybody wants it to (ADR-036 §5/§6). -->
                  <span
                    v-else
                    class="text-slate-300"
                    :title="canEdit && l.key === 'product'
                      ? 'สินค้านี้ใช้อัตราจากแคตตาล็อกกลาง — ตั้งอัตราเฉพาะสินค้าไม่ได้'
                      : undefined"
                  >—</span>
                </td>

                <td class="px-4 py-2.5 text-right tabular-nums" :data-test="`${testId}-pays-${row.product_id}`">
                  <template v-if="ladderOf(row).winner">
                    <span class="font-extrabold text-slate-900">{{ baht(ladderOf(row).amount_satang) }}</span>
                    <span class="block text-[11px] text-slate-400">
                      จากชั้น{{ LAYERS.find((l) => l.key === ladderOf(row).winner)?.label }}
                    </span>
                    <span
                      v-if="kind === 'leader' && ladderOf(row).override_mode"
                      class="block text-[11px] text-slate-400"
                    >
                      {{ modeLabels[ladderOf(row).override_mode!] ?? ladderOf(row).override_mode }}
                      <template v-if="ladderOf(row).override_mode_source === 'company'">(ตามบริษัท)</template>
                    </span>
                  </template>
                  <!-- The one red thing in this table, and it earns it: a closed
                       deal on this product pays NOBODY, silently, and this row is
                       where anyone finds out before an agent asks.
                       Amber, not red, on a product nobody sells: there is
                       nothing to fix until somebody opens it. -->
                  <span
                    v-else
                    class="font-bold"
                    :class="row.is_sellable ? 'text-rose-600' : 'text-slate-400'"
                    :data-test="`${testId}-unpaid-${row.product_id}`"
                  >
                    {{ kind === 'agent' ? 'ไม่มีใครได้เงิน' : 'หัวหน้าไม่ได้' }}
                  </span>
                </td>

                <td v-if="showSelling" class="px-4 py-2.5 text-right">
                  <!--
                    A switch, not a button labelled "ปิดขาย": a button states the
                    ACTION it performs, a switch states the STATE it is in — and
                    the state is what somebody scanning twenty rows is reading for.

                    IT IS NOT GATED BY canEdit, unlike every other control in the
                    row. Owner's standing decision: opening a product for sale is
                    a CATALOGUE action, not a commission one. Locking it for a
                    commission reason would invent a dead end — an admin who came
                    to close a product they must not sell would be told to set a
                    commission rate first.
                  -->
                  <button
                    v-if="canToggle(row)"
                    type="button"
                    class="inline-flex items-center gap-2 disabled:opacity-50"
                    :disabled="savingProductId === row.product_id"
                    :data-test="`${testId}-selling-switch-${row.product_id}`"
                    :title="row.is_sellable ? 'เปิดขายอยู่ — แตะเพื่อปิดขาย' : 'ปิดขายอยู่ — แตะเพื่อเปิดขาย'"
                    @click="emit('toggleSelling', row)"
                  >
                    <span
                      class="text-[11px] font-bold whitespace-nowrap"
                      :class="row.is_sellable ? 'text-brand-700' : 'text-slate-400'"
                      :data-test="`${testId}-selling-label-${row.product_id}`"
                    >
                      {{ savingProductId === row.product_id ? 'กำลังบันทึก…' : (row.is_sellable ? 'เปิดขาย' : 'ปิดขาย') }}
                    </span>
                    <!-- The knob is a FLEX CHILD pushed to one end, never an
                         absolutely positioned span shifted by an arbitrary
                         Tailwind translate: an arbitrary value the scanner never
                         saw is absent from the compiled CSS, so the knob carries
                         the right classes and simply never moves. -->
                    <span
                      class="w-10 h-5 rounded-full border flex items-center p-0.5 transition-colors shrink-0"
                      :class="row.is_sellable ? 'bg-brand-600 border-brand-600 justify-end' : 'bg-white border-slate-300 justify-start'"
                    >
                      <span
                        class="w-4 h-4 rounded-full shadow-sm transition-colors"
                        :class="row.is_sellable ? 'bg-white' : 'bg-slate-300'"
                      ></span>
                    </span>
                  </button>
                  <!-- A control somebody cannot use is not shown — a switch you
                       can see and cannot move reads as broken, not as forbidden.
                       The STATE is still news to them, so it stays as a pill that
                       was never a control in the first place. -->
                  <span
                    v-else
                    class="text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                    :class="row.is_sellable ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                    :data-test="`${testId}-selling-state-${row.product_id}`"
                  >
                    {{ row.is_sellable ? 'เปิดขาย' : 'ปิดขาย' }}
                  </span>
                </td>
              </tr>

              <!--
                THE OPENED ROW. Everything the columns cannot fit, for the one
                product somebody is currently asking about — rather than on
                every row at once, which is what made the old box tall enough
                to hide the table underneath it.
              -->
              <tr
                v-if="openRowId === row.product_id"
                class="bg-slate-50/70"
                :data-test="`${testId}-detail-${row.product_id}`"
              >
                <td :colspan="columnCount" class="px-4 pb-4 pt-1">
                  <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-white px-3.5 py-3">
                      <p class="text-[12px] font-extrabold text-slate-500 mb-1.5">ระบบเลือกอัตรายังไง</p>
                      <ol class="space-y-1">
                        <li
                          v-for="(step, i) in ladderSteps(row)"
                          :key="step.key"
                          class="flex gap-2 text-[12.5px]"
                          :class="step.won ? 'font-bold text-emerald-700' : 'text-slate-500'"
                        >
                          <span class="tabular-nums shrink-0 opacity-60">{{ i + 1 }}.</span>
                          <span>{{ step.text }}</span>
                        </li>
                      </ol>
                      <div v-if="showSimulate" class="mt-2.5">
                        <button
                          type="button"
                          class="px-3 py-1.5 rounded-lg text-slate-600 border border-slate-200 text-xs font-bold hover:bg-slate-50"
                          :data-test="`${testId}-simulate-${row.product_id}`"
                          @click="emit('simulate', row)"
                        >
                          ทดสอบคำนวณสินค้านี้
                        </button>
                      </div>
                    </div>

                    <!--
                      The parent's per-product notes — readiness, an expired
                      rate's date, the jump to whichever step owns the gap.
                      They stay in CommissionPlansView because they are the only
                      part of this row that needs to know about the OTHER steps,
                      and a design-system component that knew about step 4 would
                      not be one.
                    -->
                    <div v-if="$slots['row-detail']" class="rounded-xl border border-slate-200 bg-white px-3.5 py-3">
                      <slot name="row-detail" :row="row" />
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!--
        WHY EVERY CELL IS A DASH, SAID ONCE.

        A table with every control removed and no sentence explaining it reads
        as broken, not as read-only — the same thing the step-1 lock note says
        about the screen as a whole. Once, under the table, rather than on each
        row: forty copies of a permission rule is the repetition the owner
        objected to ("แดงไปหมด"), just in grey.
      -->
      <p
        v-if="!canEdit"
        class="px-4 py-2.5 border-t border-slate-100 text-[11.5px] text-slate-400"
        :data-test="`${testId}-read-only`"
      >
        ตั้งค่าโดย Super Admin — หน้านี้ดูตัวเลขได้ทั้งหมด แต่แก้ไขอัตราไม่ได้
      </p>
    </template>
  </section>
</template>
