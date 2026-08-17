<script setup lang="ts">
/**
 * CalendarDatePicker — TASK-189 follow-up v3 (human, 2026-08-16): "dropdown
 * เอาไว้ แต่เพิ่มปฏิทิน เมื่อเลือกค่าในปฏิทิน ค่าใน dropdown จะเปลี่ยนตาม".
 *
 * A real clickable month-grid calendar, meant to sit ALONGSIDE
 * BuddhistDateInput (the existing day/month/year dropdown component) —
 * not replace it. Both share the same v-model (a Gregorian "YYYY-MM-DD"
 * string, same convention as BuddhistDateInput): picking a day here emits
 * that string, and BuddhistDateInput's own `watch(() => props.modelValue)`
 * already re-syncs its three dropdowns whenever the value changes
 * externally — so wiring both to the same ref is the entire integration,
 * no cross-component event needed.
 *
 * Deliberately does NOT reproduce BuddhistDateInput's yearOptionsBE
 * range cap (TASK-189 follow-up v1 found that cap at the root of "ปี
 * เกิน 2574 ทำอย่างไร"). Year navigation here is a free ±1 stepper (« »),
 * so there is no ceiling to hit — for a far-future year, ยังใช้ dropdown
 * เดิมได้เหมือนกัน (its own yearsForward is generous, see ProductEditView).
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const BE_OFFSET = 543
const THAI_MONTHS_SHORT = [
  'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
  'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
]
const WEEKDAYS = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส']

function parseModel(v: string): { y: number; m: number; d: number } | null {
  if (!v) return null
  const [y, m, d] = v.split('-').map(Number)
  if (!y || !m || !d) return null
  return { y, m, d }
}

const today = new Date()
const initial = parseModel(props.modelValue)
// The month/year the grid is currently showing — independent of the
// selected value, exactly like BuddhistDateInput's own local day/month/
// year refs, so navigating the grid before picking never mutates the
// v-model prematurely.
const viewYear = ref(initial?.y ?? today.getFullYear())
const viewMonth = ref(initial?.m ?? today.getMonth() + 1)

watch(
  () => props.modelValue,
  (v) => {
    const p = parseModel(v)
    if (p) {
      viewYear.value = p.y
      viewMonth.value = p.m
    }
  },
)

const selected = computed(() => parseModel(props.modelValue))

function daysInMonth(y: number, m: number): number {
  return new Date(y, m, 0).getDate()
}
function firstWeekday(y: number, m: number): number {
  return new Date(y, m - 1, 1).getDay()
}

const cells = computed<Array<number | null>>(() => {
  const total = daysInMonth(viewYear.value, viewMonth.value)
  const lead = firstWeekday(viewYear.value, viewMonth.value)
  const arr: Array<number | null> = []
  for (let i = 0; i < lead; i++) arr.push(null)
  for (let d = 1; d <= total; d++) arr.push(d)
  return arr
})

function pad(n: number): string {
  return String(n).padStart(2, '0')
}
function pick(day: number): void {
  emit('update:modelValue', `${viewYear.value}-${pad(viewMonth.value)}-${pad(day)}`)
}
function shiftMonth(delta: number): void {
  let m = viewMonth.value + delta
  let y = viewYear.value
  if (m < 1) {
    m = 12
    y -= 1
  } else if (m > 12) {
    m = 1
    y += 1
  }
  viewMonth.value = m
  viewYear.value = y
}
function shiftYear(delta: number): void {
  viewYear.value += delta
}
function isSelected(day: number): boolean {
  return selected.value?.y === viewYear.value && selected.value?.m === viewMonth.value && selected.value?.d === day
}
function isToday(day: number): boolean {
  return (
    today.getFullYear() === viewYear.value &&
    today.getMonth() + 1 === viewMonth.value &&
    today.getDate() === day
  )
}
</script>

<template>
  <div class="rounded-lg border border-slate-200 bg-white p-2 w-full max-w-[260px]">
    <div class="flex items-center justify-between mb-1.5">
      <div class="flex items-center gap-0.5">
        <button
          type="button"
          class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-500 text-xs"
          aria-label="ปีก่อนหน้า"
          @click="shiftYear(-1)"
        >
          «
        </button>
        <button
          type="button"
          class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-500 text-xs"
          aria-label="เดือนก่อนหน้า"
          @click="shiftMonth(-1)"
        >
          ‹
        </button>
      </div>
      <span class="text-xs font-bold text-slate-600">{{ THAI_MONTHS_SHORT[viewMonth - 1] }} {{ viewYear + BE_OFFSET }}</span>
      <div class="flex items-center gap-0.5">
        <button
          type="button"
          class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-500 text-xs"
          aria-label="เดือนถัดไป"
          @click="shiftMonth(1)"
        >
          ›
        </button>
        <button
          type="button"
          class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-500 text-xs"
          aria-label="ปีถัดไป"
          @click="shiftYear(1)"
        >
          »
        </button>
      </div>
    </div>
    <div class="grid grid-cols-7 gap-0.5 text-center text-[10px] text-slate-400 mb-1">
      <span v-for="w in WEEKDAYS" :key="w">{{ w }}</span>
    </div>
    <div class="grid grid-cols-7 gap-0.5">
      <template v-for="(day, idx) in cells" :key="idx">
        <button
          v-if="day"
          type="button"
          class="h-7 rounded text-xs"
          :class="
            isSelected(day)
              ? 'bg-brand-600 text-white font-bold'
              : isToday(day)
                ? 'bg-slate-100 text-slate-700 font-bold'
                : 'text-slate-600 hover:bg-slate-100'
          "
          @click="pick(day)"
        >
          {{ day }}
        </button>
        <span v-else />
      </template>
    </div>
  </div>
</template>
