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
 * It also does not replace the three edit boxes above it. Those list the rows
 * that EXIST — including a category rate covering nothing, which has no row
 * here at all and would go invisible again. Boxes say what you set; this says
 * what happens.
 */
import { computed, ref } from 'vue'
import Icon from './Icon.vue'

type Layer = 'company' | 'category' | 'product'

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
  testId?: string
}>(), {
  loading: false,
  failed: false,
  canEdit: false,
  basisLabel: 'ยอดขาย',
  modeLabels: () => ({}),
  testId: 'resolution-matrix',
})

const emit = defineEmits<{
  /** A cell was clicked — open the rate form already pointed at that scope. */
  edit: [payload: { layer: Layer; row: ResolutionRow; ruleId: number | null }]
  retry: []
}>()

const LAYERS: Array<{ key: Layer; label: string }> = [
  { key: 'company', label: 'บริษัท' },
  { key: 'category', label: 'หมวดหมู่' },
  { key: 'product', label: 'สินค้า' },
]

/**
 * Only the rows that need attention, unless asked otherwise.
 *
 * A catalogue of forty products where thirty-eight simply inherit the company
 * default is a table nobody reads — and the two that do not are the entire
 * point. "น่าสนใจ" here means: nobody gets paid, OR something narrower is
 * overriding something broader. Everything else is one click away.
 */
const showAll = ref(false)

const interestingRows = computed(() => props.rows.filter((row) => {
  const ladder = props.kind === 'agent' ? row.agent : row.leader
  if (ladder.winner === null) return true

  return ladder.winner !== 'company'
}))

const visibleRows = computed(() => (showAll.value ? props.rows : interestingRows.value))
const hiddenCount = computed(() => props.rows.length - interestingRows.value.length)

/** How many products nobody can be paid on — the one number worth a colour. */
const unpaidCount = computed(() =>
  props.rows.filter((row) => (props.kind === 'agent' ? row.agent : row.leader).winner === null).length)

function ladderOf(row: ResolutionRow): LadderPayload {
  return props.kind === 'agent' ? row.agent : row.leader
}

function rungOf(row: ResolutionRow, layer: Layer): Rung | null {
  return ladderOf(row)[layer]
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

  return !(layer === 'category' && row.category === null)
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
</script>

<template>
  <section class="rounded-2xl border border-slate-200 bg-white" :data-test="testId">
    <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-slate-100">
      <p class="text-[14px] font-extrabold text-slate-900">
        {{ kind === 'agent' ? 'ผลลัพธ์: ตัวแทนได้เท่าไหร่' : 'ผลลัพธ์: หัวหน้าทีมได้เท่าไหร่' }}
      </p>
      <span
        v-if="unpaidCount"
        class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-rose-100 text-rose-700"
        :data-test="`${testId}-unpaid`"
      >
        {{ unpaidCount }} สินค้าไม่มีใครได้เงิน
      </span>
      <span v-else-if="rows.length" class="text-[11px] font-bold rounded-full px-2.5 py-1 bg-emerald-100 text-emerald-700">
        ครบทุกสินค้า
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

    <p v-else-if="!rows.length" class="px-4 py-6 text-[13px] text-slate-400">ยังไม่มีสินค้าที่บริษัทนี้เปิดขาย</p>

    <template v-else>
      <div class="overflow-x-auto">
        <table class="w-full text-[12.5px] min-w-[36rem]">
          <thead>
            <tr class="text-slate-500 bg-slate-50/70">
              <th class="text-left font-bold px-4 py-2">สินค้า</th>
              <th v-for="l in LAYERS" :key="l.key" class="text-right font-bold px-3 py-2">{{ l.label }}</th>
              <th class="text-right font-bold px-4 py-2">จ่ายจริง</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in visibleRows"
              :key="row.product_id"
              class="border-t border-slate-100 align-top"
              :data-test="`${testId}-row-${row.product_id}`"
            >
              <td class="px-4 py-2.5">
                <p class="font-bold text-slate-900">{{ row.name }}</p>
                <p class="text-[11.5px] text-slate-400">
                  {{ row.category?.name ?? 'ไม่มีหมวดหมู่' }} · {{ baht(row.base_satang) }}
                </p>
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

                <!-- EMPTY AND SETTABLE. -->
                <button
                  v-else-if="cellActionable(row, l.key)"
                  type="button"
                  class="text-slate-300 hover:text-brand-600 font-bold"
                  @click="emit('edit', { layer: l.key, row, ruleId: null })"
                >
                  + ตั้ง
                </button>
                <span v-else class="text-slate-300">—</span>
              </td>

              <td class="px-4 py-2.5 text-right tabular-nums">
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
                     where anyone finds out before an agent asks. -->
                <span v-else class="font-bold text-rose-600" :data-test="`${testId}-unpaid-${row.product_id}`">
                  {{ kind === 'agent' ? 'ไม่มีใครได้เงิน' : 'หัวหน้าไม่ได้' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="hiddenCount > 0" class="px-4 py-2.5 border-t border-slate-100">
        <button
          type="button"
          class="text-[12.5px] font-bold text-brand-600 hover:underline"
          :data-test="`${testId}-toggle-all`"
          @click="showAll = !showAll"
        >
          <Icon :name="showAll ? 'chevron_up' : 'chevron_down'" :size="12" class="inline" />
          {{ showAll ? 'ย่อเหลือเฉพาะที่ต้องดู' : `แสดงอีก ${hiddenCount} สินค้าที่ใช้ค่าเริ่มต้นบริษัทตามปกติ` }}
        </button>
      </div>
    </template>
  </section>
</template>
