<script setup lang="ts">
/**
 * GroupCombobox — forced-select dropdown for a "group" field that's used
 * for exact-string grouping elsewhere (e.g. ProductEditView's spec_group,
 * grouped via groupedSpecs()). A free-text <input> lets typos/whitespace/
 * casing silently fragment what the admin intends as one group; this
 * forces picking from the set of group values already in use, with a
 * dedicated "add new group" button for genuinely new groups.
 *
 * Layout (human-requested 2026-07-20): select/input takes 80% width, the
 * add/cancel button takes 20% — always side-by-side rather than "add new"
 * being a hidden option inside the dropdown.
 *
 * Deliberately generic (no product/spec-specific naming) — options are
 * supplied by the caller so it can be reused for any exact-match grouping
 * field, not just product specs.
 */
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'

const props = defineProps<{
  modelValue: string | null
  options: string[]
  placeholder?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [string | null]
}>()

const addingNew = ref(false)
const draft = ref('')

// Bug fix 2026-07-20: `options` only contains groups already saved on
// existing rows (parent derives it from loaded data). A group just typed
// via commitNew() below isn't in there yet — the <select> would then be
// asked to display a value with no matching <option>, and browsers
// silently fall back to showing the first option ("ไม่มีกลุ่ม"), making a
// freshly-added group look like it reverted to none even though the
// underlying modelValue was actually set correctly. Always include the
// current modelValue as a renderable option so it stays visibly selected
// until the parent's list catches up (e.g. after the spec is saved and
// reloaded).
const selectableOptions = computed(() => {
  if (props.modelValue && !props.options.includes(props.modelValue)) {
    return [props.modelValue, ...props.options]
  }
  return props.options
})

function onSelect(e: Event) {
  const val = (e.target as HTMLSelectElement).value
  emit('update:modelValue', val || null)
}

function startAddNew() {
  addingNew.value = true
  draft.value = ''
}

function commitNew() {
  const trimmed = draft.value.trim()
  emit('update:modelValue', trimmed || null)
  addingNew.value = false
}

function cancelNew() {
  addingNew.value = false
  emit('update:modelValue', null)
}

// If the parent resets modelValue (e.g. form cleared after submit) while
// the inline "new group" input is open, close it so we don't leave stale
// draft text showing.
watch(
  () => props.modelValue,
  (val) => {
    if (val === null && addingNew.value) addingNew.value = false
  },
)
</script>

<template>
  <div class="flex gap-1">
    <select
      v-if="!addingNew"
      :value="modelValue ?? ''"
      class="w-[80%] px-2 py-1.5 rounded-lg border border-slate-200 text-xs bg-white"
      @change="onSelect"
    >
      <option value="">ไม่มีกลุ่ม</option>
      <option v-for="opt in selectableOptions" :key="opt" :value="opt">{{ opt }}</option>
    </select>
    <input
      v-else
      v-model="draft"
      type="text"
      :placeholder="placeholder ?? 'พิมพ์ชื่อกลุ่มใหม่'"
      autofocus
      class="w-[80%] px-2 py-1.5 rounded-lg border border-slate-200 text-xs"
      @keydown.enter.prevent="commitNew"
      @blur="commitNew"
    />

    <button
      v-if="!addingNew"
      type="button"
      title="เพิ่มกลุ่มใหม่"
      class="w-[20%] flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-brand-600 transition"
      @click="startAddNew"
    >
      <Icon name="plus" :size="14" />
    </button>
    <button
      v-else
      type="button"
      title="ยกเลิก"
      class="w-[20%] flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50"
      @mousedown.prevent="cancelNew"
    >
      <Icon name="x" :size="14" />
    </button>
  </div>
</template>
