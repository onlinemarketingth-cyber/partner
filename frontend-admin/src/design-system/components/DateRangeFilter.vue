<script setup lang="ts">
/**
 * DateRangeFilter — shared "ตั้งแต่วันที่ / ถึงวันที่" date-range picker for
 * filter bars (Commission Summary, Policy & Report's Audit Log tab, and
 * any future filter needing the same pair).
 *
 * Human request (2026-07-23, follow-up to the "fillter วันที่ไม่ทำงาน" bug
 * fix): "fillter วันที่ยังไม่ทำงาน ให้เลือกได้อิสระ ใช้การคำนวณเช่นไม่เลือกเดือน
 * ก็ใช้ตั้งแต่ 1 สิ้นสุดเดือน 12 วันเช่นเดียวกัน 1-31 และทำการเลือกวันที่ filter
 * ให้ใช้งานง่ายเช่น date pick กำหนดวันเริ่มต้น สิ้นสุดอัตโนมัติ มีการเลือก เดือน
 * ปัจจุบัน เดือนก่อน ทั้งปี q1-q2-q3-q4" — two asks:
 *
 * 1) Free/independent day+month selection with sensible boundary
 *    fallback — delegated to BuddhistDateInput's `rangeEdge="start"/"end"`
 *    prop (see that file's docblock): blank month -> Jan (start) / Dec
 *    (end); blank day -> 1st (start) / last-day-of-month (end). Year is
 *    still required either way (BR-7/§8 guardrail — never silently
 *    guess a year the user hasn't picked).
 * 2) Quick preset buttons that set both dates in one click: เดือนนี้
 *    (this month) / เดือนก่อน (last month) / ทั้งปี (this year) / Q1-Q4.
 *    All presets are relative to the CURRENT real-world year (via
 *    `new Date()` at click time) — a user who needs a quarter/year
 *    other than the current one still has the free day/month/year
 *    selects above to set it manually, exactly as before.
 *
 * v-model:date-from / v-model:date-to bind the same Gregorian ISO
 * "YYYY-MM-DD" strings every existing call site's `filters.date_from` /
 * `filters.date_to` already expects — this component only changes HOW
 * those two strings get produced, never their format/meaning.
 */
import BuddhistDateInput from './BuddhistDateInput.vue'

// Not assigned to a local `props` binding: every field is read directly
// by name in the template (Vue's <script setup> macro exposes them
// there automatically) and none of the script logic below needs to
// read the CURRENT dateFrom/dateTo (the presets only ever WRITE new
// values via emit, never read the existing ones).
defineProps({
  dateFrom: { type: String, default: '' },
  dateTo: { type: String, default: '' },
  // Same defaults every existing date-range filter call site already
  // passed directly to BuddhistDateInput (current year - 3 .. current
  // year) — kept configurable for any future call site with a
  // different needed range.
  yearsBack: { type: Number, default: 3 },
  yearsForward: { type: Number, default: 0 },
})
const emit = defineEmits<{
  'update:dateFrom': [value: string]
  'update:dateTo': [value: string]
}>()

function pad(n: number): string {
  return String(n).padStart(2, '0')
}
function toIso(y: number, m: number, d: number): string {
  return `${y}-${pad(m)}-${pad(d)}`
}
function lastDayOf(y: number, m: number): number {
  return new Date(y, m, 0).getDate()
}
function setRange(from: string, to: string): void {
  emit('update:dateFrom', from)
  emit('update:dateTo', to)
}

function applyThisMonth(): void {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth() + 1
  setRange(toIso(y, m, 1), toIso(y, m, lastDayOf(y, m)))
}
function applyLastMonth(): void {
  const now = new Date()
  let y = now.getFullYear()
  let m = now.getMonth() // already "last month" as a 1-based number (getMonth() is 0-based for the CURRENT month)
  if (m === 0) {
    m = 12
    y -= 1
  }
  setRange(toIso(y, m, 1), toIso(y, m, lastDayOf(y, m)))
}
function applyThisYear(): void {
  const y = new Date().getFullYear()
  setRange(toIso(y, 1, 1), toIso(y, 12, 31))
}
function applyQuarter(q: 1 | 2 | 3 | 4): void {
  const y = new Date().getFullYear()
  const startMonth = (q - 1) * 3 + 1
  const endMonth = startMonth + 2
  setRange(toIso(y, startMonth, 1), toIso(y, endMonth, lastDayOf(y, endMonth)))
}
function clearRange(): void {
  setRange('', '')
}
</script>

<template>
  <div class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-xs font-bold text-slate-500 mb-1">ตั้งแต่วันที่ (พ.ศ.)</label>
      <BuddhistDateInput
        :model-value="dateFrom"
        :years-back="yearsBack"
        :years-forward="yearsForward"
        range-edge="start"
        @update:model-value="emit('update:dateFrom', $event)"
      />
    </div>
    <div>
      <label class="block text-xs font-bold text-slate-500 mb-1">ถึงวันที่ (พ.ศ.)</label>
      <BuddhistDateInput
        :model-value="dateTo"
        :years-back="yearsBack"
        :years-forward="yearsForward"
        range-edge="end"
        @update:model-value="emit('update:dateTo', $event)"
      />
    </div>
    <div class="flex flex-wrap gap-1.5">
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyThisMonth">เดือนนี้</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyLastMonth">เดือนก่อน</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyThisYear">ทั้งปี</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyQuarter(1)">Q1</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyQuarter(2)">Q2</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyQuarter(3)">Q3</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="applyQuarter(4)">Q4</button>
      <button type="button" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-slate-400 hover:text-slate-600" @click="clearRange">ล้าง</button>
    </div>
  </div>
</template>
