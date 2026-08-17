<script setup lang="ts">
/**
 * AppLogo — brand mark for Sync Vision Agent.
 *
 * Dot-grid mark inspired by the "Neural Atlas" CI reference (see
 * docs/design/CI-001-neural-atlas-reference.md) — a network of nodes
 * "syncing". Colors updated per CI-002 (2026-07-08): navy/gold sampled
 * from the GENESENN co-brand logo, replacing the earlier indigo/lime
 * placeholder pair project-wide. See docs/design/CI-002-genesenn-brand.md.
 *
 * Usage:
 *   <AppLogo mode="icon" :size="28" />
 *   <AppLogo mode="wordmark" :height="32" />
 */
withDefaults(
    defineProps<{
        mode?: 'icon' | 'wordmark'
        size?: number
        height?: number
    }>(),
    { mode: 'icon', size: 28, height: 32 },
)

// 3x3 dot grid, evenly spaced in a 24x24 viewBox.
const dotPositions = [6, 12, 18].flatMap((cy) => [6, 12, 18].map((cx) => ({ cx, cy })))
</script>

<template>
    <div
        v-if="mode === 'icon'"
        class="rounded-xl bg-brand-600 flex items-center justify-center shrink-0"
        :style="{ width: size + 'px', height: size + 'px' }"
    >
        <svg :width="Math.round(size * 0.6)" :height="Math.round(size * 0.6)" viewBox="0 0 24 24" class="text-gold-400">
            <circle v-for="(d, i) in dotPositions" :key="i" :cx="d.cx" :cy="d.cy" r="2" fill="currentColor" />
        </svg>
    </div>
    <div v-else class="flex items-center gap-2" :style="{ height: height + 'px' }">
        <div
            class="rounded-xl bg-brand-600 flex items-center justify-center shrink-0"
            :style="{ width: height + 'px', height: height + 'px' }"
        >
            <svg :width="Math.round(height * 0.6)" :height="Math.round(height * 0.6)" viewBox="0 0 24 24" class="text-gold-400">
                <circle v-for="(d, i) in dotPositions" :key="i" :cx="d.cx" :cy="d.cy" r="2" fill="currentColor" />
            </svg>
        </div>
        <span class="font-bold text-slate-900 tracking-tight" :style="{ fontSize: Math.round(height * 0.45) + 'px' }">
            Sync Vision <span class="text-brand-600">Agent</span>
        </span>
    </div>
</template>
