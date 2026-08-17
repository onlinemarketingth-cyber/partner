<script setup lang="ts">
/**
 * ProgressRing — inline SVG circular progress (TASK-053 Phase 3).
 *
 * Presentation only: the caller computes `fraction` (0..1) from real
 * API values (XP / goal progress). `color` is a stroke color for the
 * progress arc; the track is always slate-200.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    fraction: number
    centerText: string
    label?: string
    color?: string
  }>(),
  { color: '#4f46e5' },
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
          stroke="#e2e8f0"
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
