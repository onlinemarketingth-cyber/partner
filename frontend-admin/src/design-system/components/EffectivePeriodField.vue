<script setup lang="ts">
/**
 * EffectivePeriodField — "ใช้ตลอด" by default, dates only on request.
 *
 * Owner, 2026-09-14: "แก้ UI ให้มีการตั้งใช้ตลอดระยะเวลาเป็นค่าเริ่มต้น และการ
 * เลือกเวลาเป็น Option กับการตั้งค่า commission ทุกประเภท".
 *
 * ── WHAT WAS WRONG WITH TWO DATE PICKERS ──
 *
 * Every commission rate form opened with "มีผลตั้งแต่" and "มีผลถึง" sitting
 * there as equals, six <select>s between them, and an admin who just wanted a
 * rate had to read and dismiss both. That is backwards twice over:
 *
 *   · The overwhelmingly common answer is "from now until I change it".
 *     Making it the thing you have to notice you already have is a tax on the
 *     normal case paid by everyone, forever.
 *   · Worse, the pickers INVITED a date. A half-considered end date is not a
 *     cosmetic mistake here — an expired rate silently drops the product down
 *     the resolution ladder to the category or company rate, with no error and
 *     no warning, and the ledger rows it produced can never be corrected
 *     (BR-4). The screen was making its most dangerous option its most
 *     prominent one.
 *
 * So the default is one line of text stating what will happen, and the dates
 * are behind a deliberate choice — which is also what makes SCHEDULING legible
 * when somebody genuinely wants it.
 *
 * ── WHY EDITING KEEPS THE ORIGINAL START DATE ──
 *
 * In "ใช้ตลอด" mode this emits `from` = `fallbackFrom`, which the parent sets
 * to TODAY when creating and to the rule's STORED effective_from when editing.
 * Re-stamping an existing rule with today's date would quietly rewrite when it
 * began — a fact reports and audit rows are read against — in the course of
 * editing something else entirely. The read-only line shows that stored date,
 * so the mode never hides what it is about to save.
 *
 * ── WHY `from` IS NEVER EMPTY ──
 *
 * `effective_from` is required server-side and has no safe default ("this rate
 * starts at some point" is not a thing the system may assume). This component
 * therefore always emits a real date; "ใช้ตลอด" means "starts now, no end",
 * not "no dates at all".
 */
import { computed, ref, watch } from 'vue'
import BuddhistDateInput from './BuddhistDateInput.vue'
import CalendarDatePicker from './CalendarDatePicker.vue'

const props = withDefaults(defineProps<{
  /** 'YYYY-MM-DD' — never empty, see the docblock. */
  from: string
  /** 'YYYY-MM-DD' or '' for no end date. Ignored when `supportsEndDate` is false. */
  to?: string
  /**
   * False for the rate tables that have no `effective_to` column at all
   * (Matrix level rates, Generation rules). The choice then reads
   * "ใช้ตลอด / เริ่มวันอื่น" rather than offering an end date the server
   * cannot store.
   */
  supportsEndDate?: boolean
  /**
   * What "ใช้ตลอด" means for `from`: today when creating, the rule's stored
   * start date when editing. See the docblock.
   */
  fallbackFrom: string
  /** Hook prefix for tests, so two of these on one screen stay distinguishable. */
  testId?: string
}>(), {
  to: '',
  supportsEndDate: true,
  testId: 'effective-period',
})

const emit = defineEmits<{
  'update:from': [value: string]
  'update:to': [value: string]
}>()

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Which mode the CURRENT values already describe.
 *
 * An end date obviously means a custom period. A start date in the FUTURE does
 * too, and that case is the one worth spelling out: a rate that begins next
 * month is a scheduled rate, and showing it as "ใช้ตลอด" would tell the admin
 * it is in force when it pays nobody yet.
 */
function modeFor(from: string, to: string): 'always' | 'custom' {
  if (to) return 'custom'
  if (from && from > todayIso()) return 'custom'

  return 'always'
}

const mode = ref<'always' | 'custom'>(modeFor(props.from, props.to))

/*
 * Re-derived when the PARENT swaps the record being edited (the modal is
 * reused for create and for every row), not on every keystroke: watching
 * `fallbackFrom` rather than `from` is what makes that distinction. `from`
 * changes while the admin picks a date in custom mode, and re-deriving then
 * would fight the very control they are using.
 */
watch(() => props.fallbackFrom, () => {
  mode.value = modeFor(props.from, props.to)
})

const startsToday = computed(() => props.from === todayIso())

const formattedFrom = computed(() =>
  props.from ? new Date(props.from).toLocaleDateString('th-TH', { dateStyle: 'medium' }) : '—')

function choose(next: 'always' | 'custom'): void {
  if (next === mode.value) return
  mode.value = next

  if (next === 'always') {
    emit('update:to', '')
    // Back to the start date this rate is entitled to: today for a new one,
    // its own stored date for one being edited.
    emit('update:from', props.fallbackFrom || todayIso())

    return
  }

  // Entering custom mode must not silently move the start date — the admin is
  // about to set it themselves, and pre-moving it would be a change they never
  // made surviving a cancel.
  if (!props.from) emit('update:from', todayIso())
}
</script>

<template>
  <div class="col-span-2 sm:col-span-4" :data-test="testId">
    <label class="text-sm font-bold text-slate-500">ระยะเวลาที่ใช้อัตรานี้</label>

    <div class="mt-1.5 flex flex-wrap gap-2">
      <button
        type="button"
        class="px-3.5 py-2 rounded-lg border text-[13px] font-bold transition-colors"
        :class="mode === 'always'
          ? 'border-brand-500 bg-brand-50 text-brand-700'
          : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
        :data-test="`${testId}-always`"
        @click="choose('always')"
      >
        ใช้ตลอด
      </button>
      <button
        type="button"
        class="px-3.5 py-2 rounded-lg border text-[13px] font-bold transition-colors"
        :class="mode === 'custom'
          ? 'border-brand-500 bg-brand-50 text-brand-700'
          : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
        :data-test="`${testId}-custom`"
        @click="choose('custom')"
      >
        {{ supportsEndDate ? 'กำหนดช่วงเวลา' : 'เริ่มวันอื่น' }}
      </button>
    </div>

    <!-- The default states what it will save rather than leaving it implied.
         "ใช้ตลอด" on its own does not say which day it starts, and on an
         existing rate that day is not today. -->
    <p v-if="mode === 'always'" class="mt-2 text-[12.5px] text-slate-500" :data-test="`${testId}-summary`">
      <template v-if="startsToday">เริ่มมีผล <b>วันนี้</b></template>
      <template v-else>เริ่มมีผล <b>{{ formattedFrom }}</b></template>
      <template v-if="supportsEndDate"> · <b>ไม่มีวันสิ้นสุด</b> — ใช้ไปจนกว่าจะแก้หรือลบ</template>
      <template v-else> · ใช้ไปจนกว่าจะแก้หรือลบ</template>
    </p>

    <div v-else class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3" :data-test="`${testId}-dates`">
      <div>
        <label class="text-[12.5px] font-bold text-slate-500">มีผลตั้งแต่</label>
        <div class="mt-1 flex flex-wrap items-start gap-2">
          <BuddhistDateInput :model-value="from" required @update:model-value="emit('update:from', $event)" />
          <CalendarDatePicker :model-value="from" @update:model-value="emit('update:from', $event)" />
        </div>
      </div>
      <div v-if="supportsEndDate">
        <label class="text-[12.5px] font-bold text-slate-500">มีผลถึง (เว้นว่าง = ไม่มีวันสิ้นสุด)</label>
        <div class="mt-1 flex flex-wrap items-start gap-2">
          <BuddhistDateInput :model-value="to" @update:model-value="emit('update:to', $event)" />
          <CalendarDatePicker :model-value="to" @update:model-value="emit('update:to', $event)" />
        </div>
        <!-- The one consequence that is invisible on this form and expensive
             in production. An admin choosing an end date deserves to know it
             is not a stop button. -->
        <p class="mt-1 text-[11.5px] text-amber-700">
          พ้นวันนี้แล้วอัตราจะไม่หยุดจ่าย แต่จะ<b>ตกไปใช้อัตราชั้นที่กว้างกว่า</b> (หมวดหมู่ หรือค่าเริ่มต้นบริษัท) โดยไม่มีการแจ้งเตือน
        </p>
      </div>
    </div>
  </div>
</template>
