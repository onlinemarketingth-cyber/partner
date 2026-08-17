<script setup lang="ts">
/**
 * IconPicker — shared curated icon-choice grid, extracted from
 * ThemeSettingsView.vue's inline NAV_ICON_FIELDS picker (TASK-057 /
 * ADR-018) per TASK-069 / ADR-020's "extract before a third copy exists"
 * consequence (a category-icon picker was about to become a third
 * hand-rolled copy of the same grid). Renders as a collapsible row:
 * current icon swatch + label, click to expand a grid of curated
 * choices, click a choice to pick it, optional "clear" action.
 *
 * Same empty-string-means-unset convention used everywhere else in this
 * codebase (labels, nav_icon_overrides) — modelValue is always a plain
 * `string`, never `null`; callers whose backend column is a nullable
 * string (e.g. product_categories.icon) convert '' -> null themselves
 * at the API-call boundary, exactly like ProductEditView.vue already
 * does for spec_group (`specForm.spec_group || undefined`). Keeps this
 * component free of a generic/nullable type parameter.
 *
 * The default `choices` list is a DELIBERATE mirror of the backend's
 * App\Support\CuratedIcons::WHITELIST (TASK-068 / ADR-020 row 3) — keep
 * both in sync by hand (no shared package between Vue and PHP yet,
 * CLAUDE.md §7). A caller that must accept a WIDER set than the backend
 * whitelist (e.g. nav_icon_overrides, which the backend deliberately
 * leaves unvalidated — see UpdateThemeRequest's own comment on why) can
 * still pass its own `choices` prop; both current callers
 * (ThemeSettingsView's nav icons + ProductCatalogView's category icon)
 * happen to use the exact same 24-name curated set today.
 */
import { ref } from 'vue'
import Icon from './Icon.vue'
import { CURATED_ICON_CHOICES } from '@/data/curatedIcons'

withDefaults(
  defineProps<{
    /** '' = unset (same convention as label_overrides/nav_icon_overrides). */
    modelValue: string
    choices?: string[]
    /** Row caption shown above the current value — omit for a bare picker. */
    label?: string
    /** Icon shown in the swatch when modelValue is '' (unset). */
    fallbackIcon?: string
    /** Text shown under the label when modelValue is '' (unset). */
    fallbackLabel?: string
    /** Show the "clear" action once a value is picked. */
    clearable?: boolean
    clearLabel?: string
  }>(),
  {
    choices: () => CURATED_ICON_CHOICES,
    label: '',
    fallbackIcon: 'sparkles',
    fallbackLabel: 'ยังไม่ได้เลือกไอคอน',
    clearable: true,
    clearLabel: 'ล้างไอคอน',
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const open = ref(false)
function toggle(): void {
  open.value = !open.value
}
function pick(name: string): void {
  emit('update:modelValue', name)
  open.value = false
}
function clear(): void {
  emit('update:modelValue', '')
  open.value = false
}
</script>

<template>
  <div class="rounded-xl border border-slate-200">
    <button type="button" class="w-full flex items-center gap-3 px-3 py-2.5 text-left" @click="toggle">
      <span class="w-9 h-9 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center shrink-0">
        <Icon :name="modelValue || fallbackIcon" :size="18" class="text-slate-700" />
      </span>
      <span class="flex-1 min-w-0">
        <span v-if="label" class="block text-xs font-bold text-slate-700">{{ label }}</span>
        <span class="block text-[11px] text-slate-400">{{ modelValue ? modelValue : fallbackLabel }}</span>
      </span>
      <Icon :name="open ? 'chevron_up' : 'chevron_down'" :size="14" class="text-slate-400 shrink-0" />
    </button>

    <div v-if="open" class="px-3 pb-3 border-t border-slate-100 pt-3">
      <!-- TASK-089 (2026-08-03, human: "ปรับ card เลือกไอคอนให้เล็กลง 60x60
           pixel ปรับขนาด icon ให้ใหญ่ขึ้น 50%").
           Was `grid-cols-6` + `aspect-square`: six columns of whatever width
           the container happened to be, so inside the full-width category
           edit card each cell blew up to ~317px square holding a 16px glyph
           — 2% ink, 98% empty box. Fixed 60x60 tiles in a wrapping flex row
           instead of a column-count grid, so the tile size is the constant
           and the number of columns follows the available width. -->
      <div class="flex flex-wrap gap-2">
        <button
          v-for="choice in choices"
          :key="choice"
          type="button"
          :title="choice"
          class="w-[60px] h-[60px] shrink-0 rounded-lg border flex items-center justify-center transition-colors"
          :class="modelValue === choice ? 'bg-brand-600 border-brand-600 text-white' : 'bg-slate-50 border-slate-100 text-slate-600 hover:bg-slate-100'"
          @click="pick(choice)"
        >
          <!-- 16 -> 24 = the requested +50%. -->
          <Icon :name="choice" :size="24" />
        </button>
      </div>
      <button v-if="clearable && modelValue" type="button" class="mt-3 text-[11px] font-bold text-slate-400 hover:text-slate-600" @click="clear">
        {{ clearLabel }}
      </button>
    </div>
  </div>
</template>
