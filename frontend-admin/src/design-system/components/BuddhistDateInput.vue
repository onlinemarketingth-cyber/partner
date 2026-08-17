<script setup lang="ts">
/**
 * BuddhistDateInput — date/datetime entry that displays and accepts the
 * YEAR in Thai Buddhist Era (พ.ศ. = ค.ศ. + 543), per human request:
 * "ปรับระบบ UI ในการ Key วันที่เป็น พ.ศ. แต่บันทึกเป็น ค.ศ. ทั้งหมด".
 *
 * Native `<input type="date">`/`type="datetime-local">` cannot do this:
 * their `.value` is always ISO/Gregorian by spec, AND their on-screen
 * display + calendar popup is drawn by the browser/OS using the
 * system locale — a page has no way to force a Buddhist-era YEAR
 * display through them. This component replaces the year (and the
 * whole date) with plain <select> day/month/year(BE), plus a native
 * <input type="time"> for the datetime-local case (time has no
 * era/year concept, so the native time picker is fine as-is — only the
 * date's YEAR was ever the actual problem).
 *
 * v-model always binds/emits the underlying GREGORIAN ISO string
 * ("YYYY-MM-DD" or "YYYY-MM-DDTHH:mm") — every existing call site
 * keeps sending exactly what the API already expects, unchanged. Only
 * the on-screen year differs (พ.ศ.), never what's submitted/stored
 * (ค.ศ. — human's explicit instruction).
 *
 * Usage:
 *   <BuddhistDateInput v-model="ruleForm.effective_from" />
 *   <BuddhistDateInput v-model="createForm.preferred_time" type="datetime-local" />
 *   <BuddhistDateInput v-model="createForm.date_of_birth" :years-back="100" :years-forward="0" />
 *
 * yearsBack/yearsForward (TASK-014 follow-up, 2026-07-13): the year
 * range defaults to current-year..+3 (scheduling use cases — SWS
 * Referral preferred_time, commission effective_from), but a
 * past-dated field like date_of_birth needs the opposite direction
 * entirely. Rather than a second near-duplicate component, the range
 * is just configurable — every existing call site keeps its exact
 * prior behavior via the defaults.
 *
 * Bug fix (2026-07-13): day/month/year/time selections are tracked as
 * their OWN local refs, never re-derived from modelValue on every
 * render. An earlier version computed the "selected" day/month/year
 * straight from parsing modelValue — but since a combined ISO value
 * can only be emitted once ALL THREE are chosen, every single-field
 * pick before the date was complete emitted '' and (because parsed()
 * read that same unchanged '' back) silently forgot the field the user
 * had just picked. The native <select> DOM still *looked* filled in
 * (the browser doesn't reset an uncontrolled-looking selection on its
 * own), so the form appeared fully filled while the real v-model
 * stayed empty and "ส่ง Referral" never enabled. Local state fixes
 * this: each field remembers its own pick independently, and the
 * combined value is (re-)emitted after every change once complete.
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  type: { type: String, default: 'date' }, // 'date' | 'datetime-local'
  required: { type: Boolean, default: false },
  // Years before/after today's year to offer in the year <select> —
  // defaults match the original scheduling-only behavior exactly.
  yearsBack: { type: Number, default: 0 },
  yearsForward: { type: Number, default: 3 },
  // Bug fix (2026-07-23) — human report: "fillter วันที่ไม่ทำงาน" on
  // AgentCommissionSummaryView's date-range filters, THEN a follow-up
  // request the same day asking day/month to be freely, independently
  // optional ("ไม่เลือกเดือน ก็ใช้ตั้งแต่ 1 ถึง 12 ... วันเช่นเดียวกัน 1-31").
  // Root cause of the original bug: this component ALWAYS required
  // day+month+year to ALL be explicitly picked before it emits anything
  // (see emitCurrent() below) — a user who picked month+year but not day
  // got modelValue silently staying '' and the "กรอง" button re-fetched
  // with NO date param at all. That all-3-required behavior is correct
  // for a field that MUST resolve to one exact calendar date (e.g.
  // date_of_birth, preferred_time) — but wrong for a coarse date-RANGE
  // filter, where a user reasonably expects to leave month and/or day
  // blank and get a sensible boundary date instead.
  //
  // rangeEdge lets a caller opt into that boundary-resolving behavior:
  // 'start' -> blank month defaults to January (1), blank day defaults
  // to the 1st,   'end' -> blank month defaults to December (12), blank
  // day defaults to the last day of whatever month is selected. Only
  // YEAR remains required either way (never silently guessed). Default
  // '' preserves the exact prior all-3-required behavior for every other
  // existing call site (date_of_birth, preferred_time, effective_from,
  // etc.) — completely opt-in, zero risk of silently resolving a wrong
  // exact date where one was never intended.
  //
  // (Superseded the earlier autoDay: '' | 'first' | 'last' prop, which
  // only handled the day — no other call site had adopted it yet, so
  // renaming here is zero-risk. See DateRangeFilter.vue, the new shared
  // component that wires rangeEdge="start"/"end" plus quick presets
  // (this month/last month/this year/Q1-Q4) for both date-range filter
  // call sites — AgentCommissionSummaryView and PolicyReportView.)
  rangeEdge: { type: String, default: '' }, // '' | 'start' | 'end'
})
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const THAI_MONTHS = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
  'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
]
const BE_OFFSET = 543
const nowGregorianYear = new Date().getFullYear()

function parseValue(value: string) {
  if (!value) return { year: null as number | null, month: null as number | null, day: null as number | null, time: '' }
  const [datePart, timePart] = value.split('T')
  const [y, m, d] = (datePart ?? '').split('-').map(Number)
  return { year: y || null, month: m || null, day: d || null, time: timePart ?? '' }
}

// Defaults the year to the current BE year when there's no initial
// value, per human request: "เลือกปีปัจจุบันเป็นค่าเริ่มต้น" — but only
// for forward-looking/scheduling fields (yearsBack === 0, the original
// use case). A past-dated field like date_of_birth (yearsBack > 0)
// starts genuinely blank instead — defaulting a birthdate picker to
// "this year" would be actively misleading.
function defaultYearBE(): number | null {
  return props.yearsBack > 0 ? null : nowGregorianYear + BE_OFFSET
}

const initial = parseValue(props.modelValue)
// Local, independently-mutable selection state (see bug-fix note above).
const localDay = ref<number | null>(initial.day)
const localMonth = ref<number | null>(initial.month)
const localYearBE = ref<number | null>(initial.year ? initial.year + BE_OFFSET : defaultYearBE())
const localTime = ref<string>(initial.time || '00:00')

// Tracks what WE last emitted so the modelValue watcher below can tell
// "the parent genuinely changed this externally" (e.g. resetting the
// form after a successful submit) apart from "this is just our own
// emit echoing back" (which must NOT resync local state, or every
// incomplete-selection '' we emit mid-pick would wipe what the user
// just chose).
let lastEmitted = props.modelValue

watch(
  () => props.modelValue,
  (value) => {
    if (value === lastEmitted) return
    const p = parseValue(value)
    localDay.value = p.day
    localMonth.value = p.month
    localYearBE.value = p.year ? p.year + BE_OFFSET : defaultYearBE()
    localTime.value = p.time || '00:00'
  },
)

// Current BE year - yearsBack .. current BE year + yearsForward.
// Defaults (0/3) reproduce the original "current year + 3 years
// forward" range exactly — human request (2026-07-13 follow-up):
// "ใช้ปีไปข้างหน้า 3 ปี" (scheduling fields: SWS Referral
// preferred_time, commission effective_from). A past-dated field like
// date_of_birth passes yearsBack/yearsForward explicitly instead.
const yearOptionsBE = computed(() => {
  const span = props.yearsBack + props.yearsForward + 1
  return Array.from({ length: span }, (_, i) => nowGregorianYear - props.yearsBack + i + BE_OFFSET)
})

function daysInMonth(year: number, month: number): number {
  return new Date(year, month, 0).getDate()
}

const dayOptions = computed(() => {
  const year = localYearBE.value ? localYearBE.value - BE_OFFSET : nowGregorianYear
  const month = localMonth.value ?? (props.rangeEdge === 'end' ? 12 : 1)
  return Array.from({ length: daysInMonth(year, month) }, (_, i) => i + 1)
})

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

function emitCurrent(): void {
  // YEAR is always required, rangeEdge or not — never silently guessed.
  if (!localYearBE.value) {
    lastEmitted = ''
    emit('update:modelValue', '')
    return
  }
  const gregorianYear = localYearBE.value - BE_OFFSET

  // rangeEdge (bug fix, see prop docblock) — month and/or day left unset
  // by the user: fall back to the boundary of the range ('start' ->
  // Jan/1st, 'end' -> Dec/last-day-of-month) instead of refusing to
  // emit anything, ONLY when the caller opted in.
  let month = localMonth.value
  if (month === null) {
    if (!props.rangeEdge) {
      lastEmitted = ''
      emit('update:modelValue', '')
      return
    }
    month = props.rangeEdge === 'end' ? 12 : 1
  }

  let day = localDay.value
  if (day === null) {
    if (!props.rangeEdge) {
      lastEmitted = ''
      emit('update:modelValue', '')
      return
    }
    day = props.rangeEdge === 'end' ? daysInMonth(gregorianYear, month) : 1
  }

  // Clamp day if the newly-picked month/year has fewer days (e.g. was
  // 31st, switched to February) — never emit an invalid calendar date.
  const safeDay = Math.min(day, daysInMonth(gregorianYear, month))
  const datePart = `${gregorianYear}-${pad(month)}-${pad(safeDay)}`
  const result = props.type === 'datetime-local' ? `${datePart}T${localTime.value || '00:00'}` : datePart
  lastEmitted = result
  emit('update:modelValue', result)
}

function onDayChange(e: Event): void {
  localDay.value = Number((e.target as HTMLSelectElement).value) || null
  emitCurrent()
}
function onMonthChange(e: Event): void {
  localMonth.value = Number((e.target as HTMLSelectElement).value) || null
  emitCurrent()
}
function onYearChange(e: Event): void {
  localYearBE.value = Number((e.target as HTMLSelectElement).value) || null
  emitCurrent()
}
function onTimeChange(e: Event): void {
  localTime.value = (e.target as HTMLInputElement).value
  emitCurrent()
}
</script>

<template>
  <div class="flex gap-1.5 items-center flex-wrap">
    <select
      :value="localDay ?? ''"
      :required="required"
      class="px-2 py-2 rounded-lg border border-slate-200 text-sm min-w-[4.25rem]"
      @change="onDayChange"
    >
      <option value="" disabled>วัน</option>
      <option v-for="d in dayOptions" :key="d" :value="d">{{ d }}</option>
    </select>
    <select
      :value="localMonth ?? ''"
      :required="required"
      class="px-2 py-2 rounded-lg border border-slate-200 text-sm min-w-[7rem]"
      @change="onMonthChange"
    >
      <option value="" disabled>เดือน</option>
      <option v-for="(m, i) in THAI_MONTHS" :key="i" :value="i + 1">{{ m }}</option>
    </select>
    <select
      :value="localYearBE ?? ''"
      :required="required"
      class="px-2 py-2 rounded-lg border border-slate-200 text-sm min-w-[6rem]"
      @change="onYearChange"
    >
      <option value="" disabled>ปี (พ.ศ.)</option>
      <option v-for="y in yearOptionsBE" :key="y" :value="y">{{ y }}</option>
    </select>
    <input
      v-if="type === 'datetime-local'"
      type="time"
      :value="localTime"
      :required="required"
      class="px-2 py-2 rounded-lg border border-slate-200 text-sm"
      @change="onTimeChange"
    />
  </div>
</template>
