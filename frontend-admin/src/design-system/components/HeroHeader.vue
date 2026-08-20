<script setup lang="ts">
/**
 * Sprint UI-WS-1.1 / UI-WS-4 — Reusable Workspace Header (Apple HIG polish)
 *
 * Apple HIG features:
 *  - bg-white/95 (Sprint A: content opacity high — user theme เห็นที่ขอบ)
 *  - KPI vertical key-value stack (Sprint B)
 *  - All KPI values slate-900 (Sprint C: single neutral)
 *  - Slot for tabs at bottom (Sprint D: flatten into single card)
 *  - Compact (default) / Expanded modes with localStorage memory
 *  - Mobile auto-compact
 *
 * Usage:
 *   <HeroHeader icon="..." title="..." :kpis="[...]" storage-key="page">
 *     <template #actions><button>+ สร้างใหม่</button></template>
 *     <template #tabs><TabFilterBar v-model="..." :tabs="..." /></template>
 *   </HeroHeader>
 */
import { ref, computed, onMounted, onUnmounted, type PropType } from 'vue'
import Icon from './Icon.vue'
import { readStored, writeStored } from '@/utils/safeStorage'

export interface HeroKpi {
    label: string
    value: string | number
    hint?: string
}

const props = defineProps({
    icon: { type: String, default: 'document' },
    iconColor: { type: String, default: 'text-brand-600' }, // CI-002: navy brand accent
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    description: { type: String, default: '' },
    kpis: { type: Array as PropType<HeroKpi[]>, default: () => [] },
    accentColor: { type: String, default: 'violet' },
    defaultCollapsed: { type: Boolean, default: true },
    storageKey: { type: String as PropType<string | null>, default: null },
    backPage: { type: String as PropType<string | null>, default: null },
    backLabel: { type: String as PropType<string | null>, default: null },
})

// Mobile detect — บังคับ compact mode
const isMobile = ref(false)
function checkMobile() { isMobile.value = window.innerWidth < 640 }

function loadCollapsedState() {
    if (!props.storageKey) return props.defaultCollapsed
    // safeStorage rather than a local try/catch: the optional-catch idiom
    // here only covered a THROWING storage, not one that exists without
    // working methods — the case that actually occurs. See safeStorage.js.
    const v = readStored(`sv_hero_${props.storageKey}`)
    if (v === '1') return true
    if (v === '0') return false
    return props.defaultCollapsed
}

const isCollapsed = ref(loadCollapsedState())

function toggleCollapsed() {
    if (isMobile.value) return
    isCollapsed.value = !isCollapsed.value
    if (props.storageKey) {
        writeStored(`sv_hero_${props.storageKey}`, isCollapsed.value ? '1' : '0')
    }
}

const effectiveCollapsed = computed(() => isMobile.value || isCollapsed.value)

function goBack() {
    if (!props.backPage) { window.history.back(); return }
    const url = '/' + props.backPage
    window.history.pushState({ page: props.backPage }, '', url)
    window.dispatchEvent(new CustomEvent('navigate', {
        detail: { page: props.backPage, label: props.backLabel || props.backPage },
    }))
}

onMounted(() => {
    checkMobile()
    window.addEventListener('resize', checkMobile)
    window.dispatchEvent(new CustomEvent('hide-page-breadcrumb'))
})
onUnmounted(() => {
    window.removeEventListener('resize', checkMobile)
    window.dispatchEvent(new CustomEvent('show-page-breadcrumb'))
})
</script>

<template>
    <!-- Sprint UI-WS-4 D: Single card wrapper (Hero + KPIs + Tabs flatten)
         Sprint A: bg-white/95 = high opacity (user theme เห็นที่ขอบรอบ workspace) -->
    <div class="hero-header rounded-2xl bg-white/95 border border-slate-200 shadow-sm overflow-hidden"
         style="font-family: Kanit, sans-serif;">

        <!-- ════════ COMPACT MODE ════════ -->
        <div v-if="effectiveCollapsed" class="flex items-center gap-4 px-4 py-3">
            <!-- Optional Back button -->
            <template v-if="backPage || backLabel">
                <button @click="goBack"
                        class="shrink-0 flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition text-xs font-bold whitespace-nowrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span class="hidden sm:inline">{{ backLabel }}</span>
                </button>
                <div class="shrink-0 w-px h-6 bg-slate-200"></div>
            </template>

            <!-- Optional caller-supplied content right before the icon
                 (e.g. a vue-router back button — added instead of reusing
                 backPage/backLabel above, since those do a raw
                 history.pushState + custom event this app's router
                 doesn't listen for). -->
            <slot name="before-icon" />

            <!-- Icon -->
            <div class="shrink-0 w-9 h-9 rounded-lg flex items-center justify-center bg-slate-50">
                <Icon :name="icon" :size="20" :class="iconColor" />
            </div>

            <!-- Title + Subtitle (key block, no inline KPIs) -->
            <div class="min-w-0 shrink">
                <h1 class="text-base sm:text-lg font-bold text-slate-900 truncate leading-tight">{{ title }}</h1>
                <p v-if="subtitle" class="text-xs text-slate-400 truncate hidden md:block leading-tight">{{ subtitle }}</p>
            </div>

            <!-- Sprint B+C: KPI vertical key-value stack — label small above, value bold below
                 All values slate-900 (single color, no chromatic noise) -->
            <div v-if="kpis.length" class="hidden md:flex items-center gap-6 ml-auto mr-2 shrink-0">
                <div v-for="(k, idx) in kpis.slice(0, 4)" :key="idx" class="text-right">
                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold whitespace-nowrap">{{ k.label }}</div>
                    <div class="text-sm font-bold text-slate-900 leading-tight">{{ k.value }}</div>
                </div>
            </div>

            <!-- Actions + Toggle. md:ml-0 only when kpis exist: the kpi
                 block (visible md+) already carries its own ml-auto/mr-2
                 push, so actions naturally lands at the right edge right
                 after it. Without kpis there's nothing left to do that
                 push on desktop, so actions needs to keep its own
                 ml-auto at every breakpoint or it collapses back next to
                 the title (bug fixed 2026-07-19, reported via
                 ProductEditView.vue's is_active toggle, which has no
                 kpis). -->
            <div :class="['shrink-0 flex items-center gap-2 ml-auto', kpis.length ? 'md:ml-0' : '']">
                <slot name="actions" />
                <button v-if="!isMobile" @click="toggleCollapsed"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition"
                        title="ขยาย">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- ════════ EXPANDED MODE ════════ -->
        <div v-else>
            <!-- Hero section -->
            <div class="p-5">
                <!-- Optional back -->
                <button v-if="backPage || backLabel" @click="goBack"
                        class="mb-3 flex items-center gap-1.5 px-2 py-1 -ml-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition text-xs font-bold">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>{{ backLabel }}</span>
                </button>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1 min-w-0">
                        <slot name="before-icon" />
                        <div class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center bg-slate-50">
                            <Icon :name="icon" :size="26" :class="iconColor" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 leading-tight">{{ title }}</h1>
                            <p v-if="subtitle" class="text-sm text-slate-400 mt-0.5">{{ subtitle }}</p>
                            <p v-if="description" class="text-sm text-slate-500 mt-2">{{ description }}</p>
                        </div>
                    </div>
                    <div class="shrink-0 flex items-center gap-2">
                        <slot name="actions" />
                        <button @click="toggleCollapsed"
                                class="w-9 h-9 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition"
                                title="ย่อ">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="18 15 12 9 6 15"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sprint B+C: KPI cards row — key-value stack, all slate-900 -->
            <div v-if="kpis.length" class="px-5 pb-5">
                <div :class="['grid gap-3',
                              kpis.length === 1 ? 'grid-cols-1' :
                              kpis.length === 2 ? 'grid-cols-2' :
                              kpis.length === 3 ? 'grid-cols-3' :
                              kpis.length <= 4 ? 'grid-cols-2 md:grid-cols-4' :
                              'grid-cols-2 md:grid-cols-3 lg:grid-cols-6']">
                    <div v-for="(k, idx) in kpis" :key="idx"
                         class="p-3 rounded-xl bg-slate-50/80 border border-slate-100">
                        <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold">{{ k.label }}</div>
                        <div class="text-xl font-bold text-slate-900 mt-1 leading-tight">{{ k.value }}</div>
                        <div v-if="k.hint" class="text-[10px] text-slate-400 mt-0.5">{{ k.hint }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sprint D: Tabs slot — รวมอยู่ใน card เดียวกัน (flatten) -->
        <div v-if="$slots.tabs" class="border-t border-slate-100">
            <slot name="tabs" />
        </div>
    </div>
</template>

<style scoped>
.hero-header { transition: all 0.3s ease; }
</style>
