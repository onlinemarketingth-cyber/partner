<script setup lang="ts">
/**
 * AppLogo — the brand mark, wherever it appears.
 *
 * TASK-121 (2026-08-05, human-reported: "logo นี้ปรับเป็น image ที่ผู้ใช้
 * upload ผ่าน admin"). A company can already upload its own nav / login
 * logos (TASK-055 / ADR-018, stored on company_theme_settings and served
 * as theme.navLogo / theme.loginLogo) — but resolving them was left to
 * each CALL SITE, as a `v-if="theme.navLogo" … <img> … <AppLogo v-else>`
 * pair. Only 2 of the 6 places that render this component ever did it, so
 * a tenant that uploaded a logo still saw the built-in Sync Vision mark on
 * /register, /verify-email, /pay/:token and /l/:token — every one of them
 * a page shown to someone OUTSIDE the company, which is exactly where a
 * white-label mark matters most.
 *
 * The lookup now lives here, so a call site cannot forget it. Same reason
 * the app-name label is resolved here rather than by each caller repeating
 * `:label="appName !== 'Sync Vision Agent' ? appName : undefined"`.
 *
 * `context` picks which uploaded slot to use:
 *   'nav'   — the in-app top bar (small, sits on the nav surface)
 *   'login' — full-page brand headers: login, register, verify-email,
 *             payment, lead capture. This is the default because most
 *             call sites are that kind of page.
 * `src` overrides both, for a caller that already has a URL in hand.
 *
 * The built-in dot-grid fallback is the "Neural Atlas" CI mark (a network
 * of nodes syncing) in the CI-002 navy/gold pair — see
 * docs/design/CI-001-neural-atlas-reference.md and CI-002-genesenn-brand.md.
 * It renders unchanged for any company that has not uploaded anything.
 */
import { computed } from 'vue'
import { useThemeStore } from '@/stores/theme'

const props = withDefaults(
    defineProps<{
        mode?: 'icon' | 'wordmark'
        size?: number
        height?: number
        /** Explicit app-name override. Falls back to the company's configured name. */
        label?: string
        /** Explicit image URL override. Falls back to the company's uploaded logo. */
        src?: string | null
        context?: 'nav' | 'login'
    }>(),
    { mode: 'icon', size: 28, height: 32, label: undefined, src: undefined, context: 'login' },
)

const theme = useThemeStore()

const logoSrc = computed<string | null>(() => {
    if (props.src !== undefined) return props.src
    return (props.context === 'nav' ? theme.navLogo : theme.loginLogo) ?? null
})

/**
 * The wordmark text. A company that set app_name gets it verbatim; the
 * default keeps the two-tone "Sync Vision Agent" treatment below, which is
 * why this returns null rather than the default string.
 */
const wordmarkLabel = computed<string | null>(() => {
    if (props.label) return props.label
    const configured = theme.label('app_name', 'Sync Vision Agent')
    return configured && configured !== 'Sync Vision Agent' ? configured : null
})

// 3x3 dot grid, evenly spaced in a 24x24 viewBox.
const dotPositions = [6, 12, 18].flatMap((cy) => [6, 12, 18].map((cx) => ({ cx, cy })))
</script>

<template>
    <!-- Uploaded logo — icon slot. Constrained to a square box so a wide
         logo cannot blow out a fixed-height top bar. -->
    <img
        v-if="logoSrc && mode === 'icon'"
        :src="logoSrc"
        :alt="wordmarkLabel ?? 'Sync Vision Agent'"
        class="object-contain shrink-0"
        :style="{ width: size + 'px', height: size + 'px' }"
    />
    <!-- Uploaded logo — wordmark slot. Height-locked, width free: an
         uploaded logo usually already contains the company name, so no
         text is rendered beside it (that would read as a duplicate). -->
    <img
        v-else-if="logoSrc"
        :src="logoSrc"
        :alt="wordmarkLabel ?? 'Sync Vision Agent'"
        class="w-auto object-contain shrink-0"
        :style="{ height: height + 'px' }"
    />

    <div
        v-else-if="mode === 'icon'"
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
        <span class="font-bold text-ink-card tracking-tight" :style="{ fontSize: Math.round(height * 0.45) + 'px' }">
            <template v-if="wordmarkLabel">{{ wordmarkLabel }}</template>
            <template v-else>Sync Vision <span class="text-ink-brand">Agent</span></template>
        </span>
    </div>
</template>
