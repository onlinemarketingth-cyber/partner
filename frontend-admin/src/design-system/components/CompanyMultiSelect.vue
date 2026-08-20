<script setup lang="ts">
/**
 * CompanyMultiSelect — tick-box company picker for "create this row in N
 * companies at once" forms.
 *
 * Human ruling, 2026-08-19 (TASK-203): "ในตอนเพิ่มแบรนด์ หรือ หมวดหมู่
 * ให้มีช่องในการเลือก...เป็นแบบ dropdown list แล้วติ๊ก All หรือ clear all
 * และติ๊กเลือกบริษัทได้". Brands and categories are per-company rows
 * (BR-6 — `brands.company_id` / `product_categories.company_id`), so
 * "the same brand in three companies" is genuinely three rows; the
 * caller fans out one POST per ticked company. This component only owns
 * the choosing.
 *
 * Deliberately NOT a native <select multiple>: on macOS that control
 * requires cmd-click to add a second option and silently drops the whole
 * selection on a plain click — exactly the "เข้าใจยาก" failure mode this
 * task exists to remove. Tick boxes have no hidden modifier key.
 *
 * Super-Admin-only by usage: Company Admin never sees a company choice at
 * all (BrandService infers their company_id server-side), so callers
 * simply do not render this for them.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    /** Ticked company ids. Empty = nothing chosen yet (callers must block save). */
    modelValue: number[]
    options: { id: number, name: string }[]
    label?: string
    /** Shown on the trigger while nothing is ticked. */
    placeholder?: string
  }>(),
  {
    label: '',
    placeholder: 'เลือกบริษัท...',
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)

const selectedCount = computed(() => props.modelValue.length)
const allSelected = computed(() => props.options.length > 0 && selectedCount.value === props.options.length)

/**
 * One name when a single company is ticked, "N บริษัท" beyond that — a
 * comma list of five company names would wrap the trigger to three lines.
 */
const summary = computed(() => {
  if (selectedCount.value === 0) return props.placeholder
  if (allSelected.value) return `ทุกบริษัท (${selectedCount.value})`
  if (selectedCount.value === 1) {
    return props.options.find((o) => o.id === props.modelValue[0])?.name ?? '1 บริษัท'
  }

  return `${selectedCount.value} บริษัท`
})

function isTicked(id: number): boolean {
  return props.modelValue.includes(id)
}

function toggle(id: number): void {
  emit('update:modelValue', isTicked(id)
    ? props.modelValue.filter((v) => v !== id)
    : [...props.modelValue, id])
}

function selectAll(): void {
  emit('update:modelValue', props.options.map((o) => o.id))
}

function clearAll(): void {
  emit('update:modelValue', [])
}

// Click-outside + Esc, same pair every other popover in this design system
// closes on (see InfoPopover.vue / GroupCombobox.vue).
function onDocumentClick(event: MouseEvent): void {
  if (!open.value) return
  if (root.value && !root.value.contains(event.target as Node)) open.value = false
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') open.value = false
}
onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="relative">
    <label v-if="label" class="text-xs font-bold text-slate-500 block mb-1">{{ label }}</label>

    <button
      type="button"
      class="w-full h-[38px] px-3 rounded-lg border text-sm font-bold flex items-center gap-2 transition-colors"
      :class="selectedCount ? 'border-brand-600 bg-white text-brand-700' : 'border-slate-200 bg-white text-slate-400'"
      @click="open = !open"
    >
      <Icon name="building" :size="14" class="shrink-0" />
      <span class="flex-1 text-left truncate">{{ summary }}</span>
      <!-- Inline chevron: Icon.vue's curated set has no chevron glyph
           (App\Support\CuratedIcons mirrors it, so adding one there is a
           backend change too) — not worth widening the whitelist for a
           disclosure arrow. -->
      <svg
        class="w-3.5 h-3.5 shrink-0 text-slate-400 transition-transform"
        :class="open ? 'rotate-180' : ''"
        viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
      >
        <path d="M5 7.5L10 12.5L15 7.5" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute z-50 mt-1 w-full max-h-[260px] overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"
    >
      <div class="sticky top-0 flex items-center gap-2 px-3 py-2 bg-white border-b border-slate-100">
        <button type="button" class="text-[11px] font-bold text-brand-600 hover:underline" @click="selectAll">
          เลือกทั้งหมด
        </button>
        <span class="text-slate-200">|</span>
        <button type="button" class="text-[11px] font-bold text-slate-500 hover:underline" @click="clearAll">
          ล้างทั้งหมด
        </button>
        <span class="ml-auto text-[11px] font-bold text-slate-400">เลือกแล้ว {{ selectedCount }}/{{ options.length }}</span>
      </div>

      <p v-if="!options.length" class="px-3 py-3 text-xs text-slate-400">ไม่มีบริษัทให้เลือก</p>

      <label
        v-for="o in options"
        :key="o.id"
        class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-brand-50/60"
      >
        <input
          type="checkbox"
          class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
          :checked="isTicked(o.id)"
          @change="toggle(o.id)"
        />
        <span class="text-sm text-slate-700 truncate">{{ o.name }}</span>
      </label>
    </div>
  </div>
</template>
