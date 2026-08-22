<script setup lang="ts">
/**
 * ProgressRing — inline SVG circular progress (TASK-053 Phase 3).
 *
 * Presentation only: the caller computes `fraction` (0..1) from real API
 * values (XP / goal progress).
 *
 * ── BOTH COLOURS COME FROM THE TENANT'S THEME (2026-08-21) ──
 *
 * Reported by a human who simply asked what the blue ring was: on a
 * gold-themed company, the Home level ring was navy. Two hardcoded values
 * were behind it and both were invisible until somebody looked at a
 * non-default tenant.
 *
 *   * The ARC defaulted to `#4f46e5` and HomeView passed its own literal
 *     `#2F4183` — the PLATFORM's navy, not the company's Primary. Every
 *     other control on that screen uses `bg-brand-600`, which theme.ts
 *     generates from `primary_hex` (applyRamp), so this one component sat
 *     outside the theme system entirely.
 *
 *   * The TRACK was `#e2e8f0`, which is the LIGHT-mode value of
 *     `--line-card`. theme.ts derives that variable per tenant (it mixes
 *     the card background with the card ink), so on a dark company the
 *     track was a bright hairline against black instead of a faint one.
 *
 * The defaults now read those variables, so a caller that passes nothing
 * gets the tenant's colours. `rgb(var(--…))` and not a Tailwind class,
 * because these are SVG stroke values and the variables hold CHANNELS —
 * see tailwind.config.js, where every token is `rgb(var(--x) / <alpha>)`.
 *
 * `color` stays overridable for a ring that deliberately means something
 * other than "brand" (a danger or success ring). Passing brand explicitly
 * is now redundant and should be deleted, not copied.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    fraction: number
    centerText: string
    label?: string
    /** Arc colour. Defaults to the tenant's Primary — override only for a
     *  ring whose meaning is NOT "brand". */
    color?: string
  }>(),
  { color: 'rgb(var(--brand-600))' },
)

const SIZE = 96
const STROKE = 9
const RADIUS = (SIZE - STROKE) / 2
const CIRC = 2 * Math.PI * RADIUS

const clamped = computed(() => Math.min(1, Math.max(0, props.fraction || 0)))
const dashOffset = computed(() => CIRC * (1 - clamped.value))
</script>

<template>
  <div class="flex flex-col items-center gap-1">
    <div class="relative" :style="{ width: SIZE + 'px', height: SIZE + 'px' }">
      <svg :width="SIZE" :height="SIZE" :viewBox="`0 0 ${SIZE} ${SIZE}`" class="-rotate-90">
        <circle
          :cx="SIZE / 2"
          :cy="SIZE / 2"
          :r="RADIUS"
          fill="none"
          stroke="rgb(var(--line-card))"
          :stroke-width="STROKE"
        />
        <circle
          :cx="SIZE / 2"
          :cy="SIZE / 2"
          :r="RADIUS"
          fill="none"
          :stroke="color"
          :stroke-width="STROKE"
          stroke-linecap="round"
          :stroke-dasharray="CIRC"
          :stroke-dashoffset="dashOffset"
        />
      </svg>
      <div class="absolute inset-0 flex items-center justify-center">
        <span class="text-base font-bold text-ink-card">{{ centerText }}</span>
      </div>
    </div>
    <span v-if="label" class="text-xs font-bold text-ink-card-muted">{{ label }}</span>
  </div>
</template>
