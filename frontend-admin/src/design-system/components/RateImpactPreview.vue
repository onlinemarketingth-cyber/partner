<script setup lang="ts">
/**
 * RateImpactPreview — "บันทึกแล้วจะเกิดอะไรขึ้น", inside the form, before the
 * save (owner's ข้อเสนอ 3, 2026-09-14).
 *
 * ── WHY A PREVIEW AND NOT MORE VALIDATION ──
 *
 * The form already refuses invalid input. The mistakes that actually cost
 * money on this screen are VALID rates that do something other than what the
 * admin pictured: a category rate touching four products instead of the one
 * they had in mind, a company default that changes nothing because everything
 * already overrides it, a rate that reaches no product at all. None of those
 * is an error, so none can be caught by a rule — the only honest intervention
 * is to show the arithmetic while the ledger is still empty, because
 * afterwards BR-4 means nobody may correct it.
 *
 * ── WHY INLINE AND LIVE, NOT A CONFIRMATION STEP ──
 *
 * The copy-rates flow uses a modal because copying writes many rates at once
 * from a decision made elsewhere; there, an unskippable stop is the feature.
 * One rate is different: the admin is already looking at the form, and a
 * second screen between them and บันทึก is a screen people learn to click
 * through without reading. Updating in place as they type puts the consequence
 * beside the cause, which is the only arrangement where it gets read.
 *
 * ── WHY IT NEVER COMPUTES ANYTHING ITSELF ──
 *
 * Same reason as RateResolutionMatrix, and it matters more here: this is what
 * the admin is trusting at the exact moment they commit. A preview that
 * disagrees with the save it is previewing is worse than no preview. Every
 * figure comes from POST /commission-rate-impact, which runs CommissionService's
 * own ladder and rounding.
 */
import { ref, watch } from 'vue'
import { api } from '@/api/client'

interface ChangedRow {
  product_id: number
  name: string
  before_satang: number | null
  after_satang: number
  unchanged: boolean
}

interface BlockedRow {
  product_id: number
  name: string
  blocked_by: 'product' | 'category'
  amount_satang: number | null
}

interface Impact {
  layer: 'company' | 'category' | 'product'
  changed: ChangedRow[]
  blocked: BlockedRow[]
  changed_count: number
  blocked_count: number
  reaches_nothing: boolean
}

const props = defineProps<{
  kind: 'agent' | 'leader'
  companyId: number | null
  rateType: 'percentage' | 'fixed_satang'
  /** Basis points or satang — already converted, exactly as the save sends it. */
  rateValue: number | null
  productId: number | null
  productCategoryId: number | null
  overrideMode?: string | null
  excludeRuleId?: number | null
  /** False while the form is incomplete; the preview stays silent rather than guessing. */
  ready: boolean
}>()

const impact = ref<Impact | null>(null)
const loading = ref(false)
const failed = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined
let requestId = 0

async function fetchImpact(): Promise<void> {
  if (!props.ready || props.rateValue === null) {
    impact.value = null
    failed.value = false

    return
  }

  // Every keystroke on the rate field would otherwise be a request, and the
  // answers can arrive out of order — the id is what stops an older reply
  // overwriting a newer one, which on this panel would mean showing the
  // consequence of a number the admin has already changed.
  const mine = ++requestId
  loading.value = true
  try {
    const r = await api.post<{ data: Impact }>('/commission-rate-impact', {
      ...(props.companyId === null ? {} : { company_id: props.companyId }),
      kind: props.kind,
      rate_type: props.rateType,
      rate_value: props.rateValue,
      product_id: props.productId,
      product_category_id: props.productCategoryId,
      ...(props.overrideMode ? { override_mode: props.overrideMode } : {}),
      ...(props.excludeRuleId ? { exclude_rule_id: props.excludeRuleId } : {}),
    })
    if (mine !== requestId) return
    impact.value = r.data
    failed.value = false
  } catch {
    if (mine !== requestId) return
    // Silent-but-empty, not a red alarm: failing to preview does not stop the
    // save being valid, and an error here that looked like a problem with the
    // RATE would send an admin hunting for a fault in the wrong place.
    impact.value = null
    failed.value = true
  } finally {
    if (mine === requestId) loading.value = false
  }
}

watch(
  () => [props.ready, props.kind, props.rateType, props.rateValue, props.productId, props.productCategoryId, props.overrideMode, props.excludeRuleId, props.companyId],
  () => {
    clearTimeout(timer)
    timer = setTimeout(fetchImpact, 400)
  },
  { immediate: true },
)

function baht(satang: number | null): string {
  if (satang === null) return '—'

  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="col-span-2 sm:col-span-4 rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-3" data-test="rate-impact">
    <p class="text-[12.5px] font-extrabold text-slate-700">บันทึกแล้วจะเกิดอะไรขึ้น</p>

    <p v-if="!ready" class="mt-1 text-[12px] text-slate-400" data-test="rate-impact-idle">
      กรอกอัตราให้ครบก่อน แล้วระบบจะคำนวณให้ว่ากระทบสินค้าตัวไหนบ้าง
    </p>
    <p v-else-if="loading && !impact" class="mt-1 text-[12px] text-slate-400">กำลังคำนวณ...</p>
    <p v-else-if="failed" class="mt-1 text-[12px] text-slate-400" data-test="rate-impact-failed">
      คำนวณผลกระทบไม่ได้ตอนนี้ — บันทึกได้ตามปกติ แต่จะยังไม่เห็นว่ากระทบสินค้าตัวไหน
    </p>

    <template v-else-if="impact">
      <!-- The outcome that is never an error and almost never intended. -->
      <p v-if="impact.reaches_nothing" class="mt-1 text-[12.5px] font-bold text-amber-700" data-test="rate-impact-nothing">
        อัตรานี้จะยังไม่มีผลกับสินค้าตัวไหนเลย — ขอบเขตที่เลือกไม่มีสินค้าที่บริษัทนี้เปิดขายอยู่
      </p>

      <template v-else>
        <p class="mt-1 text-[12.5px]" :class="impact.changed_count ? 'text-slate-700' : 'text-slate-500'" data-test="rate-impact-summary">
          <template v-if="impact.changed_count">
            <b>{{ impact.changed_count }} สินค้าจะเปลี่ยน</b>
          </template>
          <template v-else>ไม่มีสินค้าตัวไหนเปลี่ยน (ค่าเดิมเท่ากับค่าใหม่)</template>
          <template v-if="impact.blocked_count">
            · <b>{{ impact.blocked_count }} สินค้าไม่กระทบ</b> เพราะมีอัตราที่เจาะจงกว่าทับอยู่
          </template>
        </p>

        <ul v-if="impact.changed_count" class="mt-1.5 space-y-0.5 text-[12px] text-slate-600" data-test="rate-impact-changed">
          <li v-for="row in impact.changed.filter((r) => !r.unchanged).slice(0, 6)" :key="row.product_id">
            {{ row.name }} — <span class="tabular-nums">{{ baht(row.before_satang) }}</span>
            → <b class="tabular-nums">{{ baht(row.after_satang) }}</b> บาท
          </li>
          <li v-if="impact.changed_count > 6" class="text-slate-400">
            และอีก {{ impact.changed_count - 6 }} รายการ
          </li>
        </ul>

        <!-- Named, not just counted. A count says something is being shielded;
             only the name says whether that was the intention. -->
        <ul v-if="impact.blocked_count" class="mt-1.5 space-y-0.5 text-[12px] text-slate-400" data-test="rate-impact-blocked">
          <li v-for="row in impact.blocked.slice(0, 4)" :key="row.product_id">
            {{ row.name }} — ใช้อัตรา{{ row.blocked_by === 'product' ? 'ของสินค้าเอง' : 'ของหมวดหมู่' }}ต่อไป
            ({{ baht(row.amount_satang) }} บาท)
          </li>
          <li v-if="impact.blocked_count > 4">และอีก {{ impact.blocked_count - 4 }} รายการ</li>
        </ul>
      </template>

      <p class="mt-1.5 text-[11.5px] text-slate-400">
        มีผลกับดีลที่เกิดหลังบันทึกเท่านั้น — รายการที่ลงบัญชีไปแล้วไม่เปลี่ยน
      </p>
    </template>
  </div>
</template>
