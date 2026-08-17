<script setup lang="ts">
/**
 * GradientPicker — the ONE two-stop + angle gradient control on the theme
 * screen (TASK-161 §4).
 *
 * There was no component to reuse: the app-background gradient control had
 * lived as inline markup inside ThemeSettingsView.vue since TASK-055. This
 * is that markup extracted VERBATIM (same labels, same swatch sizes, same
 * 0–360 range slider, same degree readout), and the background section now
 * renders this component instead of its own copy. The nav bar gained a
 * gradient option in the same task — hand-writing a second picker is how
 * two controls on one screen start behaving differently the first time
 * either is touched (the spec's own instruction: "do not build a second
 * gradient picker with different ergonomics").
 *
 * Deliberately dumb: two colours and an angle in, three events out. It
 * knows nothing about which theme field it edits, so each caller keeps
 * owning its own storage shape — the app background persists
 * `{ from, to, angle }` and the nav bar persists `{ color1, color2, angle }`
 * (TASK-161 §3.1). Those two key namings are NOT the same; mapping them
 * belongs at the call site, not in here.
 *
 * Usage (v-model with an argument, one per stop):
 *   <GradientPicker v-model:color1="from" v-model:color2="to" v-model:angle="deg" />
 */
defineProps<{
  /** First gradient stop (hex, e.g. #1e3a8a). */
  color1: string
  /** Second gradient stop (hex). */
  color2: string
  /** CSS gradient angle in degrees, 0–360. */
  angle: number
}>()

const emit = defineEmits<{
  'update:color1': [value: string]
  'update:color2': [value: string]
  'update:angle': [value: number]
}>()

function onColor1(e: Event): void {
  emit('update:color1', (e.target as HTMLInputElement).value)
}
function onColor2(e: Event): void {
  emit('update:color2', (e.target as HTMLInputElement).value)
}
function onAngle(e: Event): void {
  emit('update:angle', Number((e.target as HTMLInputElement).value))
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-3">
      <label class="text-xs font-bold text-slate-500 w-16">สีที่ 1</label>
      <input
        :value="color1"
        type="color"
        class="w-10 h-8 rounded cursor-pointer border border-slate-200"
        @input="onColor1"
      />
      <label class="text-xs font-bold text-slate-500 w-16">สีที่ 2</label>
      <input
        :value="color2"
        type="color"
        class="w-10 h-8 rounded cursor-pointer border border-slate-200"
        @input="onColor2"
      />
    </div>
    <div class="flex items-center gap-3">
      <label class="text-xs font-bold text-slate-500 w-16 shrink-0">องศา</label>
      <input :value="angle" type="range" min="0" max="360" class="flex-1" @input="onAngle" />
      <span class="text-xs font-bold text-slate-500 w-10 text-right">{{ angle }}°</span>
    </div>
  </div>
</template>
