<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * Sprint UI-WS-1.1 / UI-WS-4 — Reusable Workspace Header (Apple HIG polish)
 *
 * Apple HIG features:
 *  - bg-surface-card/95 (Sprint A: content opacity high — user theme เห็นที่ขอบ)
 *  - KPI vertical key-value stack (Sprint B)
 *  - All KPI values one neutral ink (Sprint C: single neutral)
 *  - Slot for tabs at bottom (Sprint D: flatten into single card)
 *  - Compact (default) / Expanded modes with localStorage memory
 *  - Mobile auto-compact
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-098 / ADR-023 (2026-08-04) — colours now come from the
 * surface/ink/line tokens, not from hardcoded slate/white utilities.
 *
 * ADR-023 §2.2 names THIS component first among the worst offenders,
 * because its KPI strip renders on EVERY view. The strip was
 * `bg-slate-50/80 border border-slate-100` wrapping `text-slate-500` /
 * `text-ink-card` / `text-ink-card-subtle`, and the icon chip was `bg-surface-chip`.
 * On a dark-card tenant the card-text override (main.css `.has-card-text`)
 * repainted those slate shades with the LIGHT card ink while the pill kept
 * its PALE background — light-on-light, unreadable, on every screen.
 *
 * The fix is not a darker text class, it is the pairing: the pill is now
 * `bg-surface-chip` and everything inside it is `text-ink-chip`, and the
 * theme store derives that pair from the card's own lightness. On a white
 * card the pill is still slate-100/slate-600; on a black card it becomes a
 * light-alpha overlay with light ink. Text OUTSIDE the pill sits directly
 * on the card, so it uses the card ink scale
 * (`text-ink-card` / `-muted` / `-subtle`) instead.
 *
 * Usage:
 *   <HeroHeader icon="..." title="..." :kpis="[...]" storage-key="page">
 *     <template #actions><button>+ สร้างใหม่</button></template>
 *     <template #tabs><TabFilterBar v-model="..." :tabs="..." /></template>
 *   </HeroHeader>
 */
import { ref, computed, onMounted, onUnmounted, watch, type PropType } from 'vue'
import { useRouter } from 'vue-router'
import Icon from './Icon.vue'
import { usePageHeaderStore } from '@/stores/pageHeader'
import { readStored, writeStored } from '@/utils/safeStorage'

export interface HeroKpi {
    label: string
    value: string | number
    hint?: string
}

const props = defineProps({
    icon: { type: String, default: 'document' },
    // TASK-098 — default is the card-aware brand ink, not a fixed ramp
    // step: `text-ink-brand` sank into a dark card (same failure the price
    // on ProductCard hit). Callers may still pass any class they want.
    iconColor: { type: String, default: 'text-ink-brand' },
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

/**
 * TASK-086 / ADR-021 — "promoted" means the identity row (icon + title +
 * back + action) is rendered by App.vue's top bar instead of here, and
 * this component contributes only the tabs/filter row to the page body.
 *
 * Mobile only, deliberately. The 15%-of-viewport budget is a phone
 * problem; desktop has the vertical room and also has the expand/collapse
 * chevron and the KPI cards, which have nowhere to go in a 57px bar.
 * Gating on `isMobile` keeps desktop byte-for-byte as it was and means
 * this change cannot regress it.
 */
const promoted = computed(() => isMobile.value)

const pageHeader = usePageHeaderStore()
const headerToken = Symbol('hero-header')

function publishHeader() {
    if (!promoted.value) {
        pageHeader.release(headerToken)

        return
    }

    pageHeader.claim(headerToken, {
        icon: props.icon,
        title: props.title,
        backPage: props.backPage,
        backLabel: props.backLabel,
    })
}

// Titles are not always static — several screens set them from loaded
// data — so re-publish on change rather than only on mount.
watch(
    () => [promoted.value, props.icon, props.title, props.backPage, props.backLabel],
    publishHeader,
)

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit) — two findings met here.
 *
 * 1. This component's back-button feature had ZERO callers app-wide, so
 *    the Agent Portal offered NO back affordance on any screen: an agent
 *    who tapped into /orders or /affiliate-links (neither of which is a
 *    BottomNav tab) could only get out via the browser's own back
 *    gesture. Phase 4 wires `back-page` on all 7 secondary views.
 *
 * 2. Wiring it revealed this function was dead code ported verbatim from
 *    frontend-admin, whose shell navigates by a hand-rolled
 *    pushState + 'navigate' CustomEvent. This app is vue-router — nothing
 *    listens for that event, so it changed the URL WITHOUT changing the
 *    view, i.e. the button would have looked broken the moment anyone
 *    used it. Uses the router directly now.
 *
 * `backPage` accepts either a full path ('/') or a bare page name
 * ('orders'), since the admin-side callers this was copied from pass the
 * latter.
 */
const router = useRouter()

/**
 * TASK-085 — whether the compact row also has to fit a back button.
 * Drives the conditional wrap on the actions block; see the comment there.
 */
const hasBack = computed(() => Boolean(props.backPage || props.backLabel))

function goBack() {
    if (!props.backPage) { router.back(); return }
    router.push(props.backPage.startsWith('/') ? props.backPage : '/' + props.backPage)
}

onMounted(() => {
    checkMobile()
    publishHeader()
    window.addEventListener('resize', checkMobile)
    window.dispatchEvent(new CustomEvent('hide-page-breadcrumb'))
})
onUnmounted(() => {
    pageHeader.release(headerToken)
    window.removeEventListener('resize', checkMobile)
    window.dispatchEvent(new CustomEvent('show-page-breadcrumb'))
})
</script>

<template>
    <!-- ════════ PROMOTED (mobile) ════════
         TASK-086 / ADR-021. The identity row is in App.vue's top bar (fed
         by the pageHeader store above); the action button is teleported
         there too, so the caller's `#actions` slot keeps working unchanged
         and views needed no edits at all.

         `defer` matters: without it the Teleport resolves its target on
         mount, and a view mounted before App.vue's header exists in the
         DOM would silently drop the button.

         When a screen has no `tabs` slot (7 of the 14 callers) NOTHING is
         rendered here — no empty card, no stray padding. That is the whole
         point: those screens now spend 0px of the 15% budget. -->
    <template v-if="promoted">
        <Teleport defer to="#page-header-action">
            <slot name="actions" />
        </Teleport>

        <div v-if="$slots.tabs"
             class="hero-header rounded-2xl bg-surface-card/95 border border-line-card shadow-sm overflow-hidden"
             style="font-family: var(--app-font);">
            <slot name="tabs" />
        </div>
    </template>

    <!-- Sprint UI-WS-4 D: Single card wrapper (Hero + KPIs + Tabs flatten)
         Sprint A: /95 = high opacity (user theme เห็นที่ขอบรอบ workspace)
         TASK-098: the surface is `bg-surface-card` — the same token every
         AppCard uses — so this shell and the cards below it can never
         drift apart on a themed tenant. -->
    <div v-else
         class="hero-header rounded-2xl bg-surface-card/95 border border-line-card shadow-sm overflow-hidden"
         style="font-family: var(--app-font);">

        <!-- ════════ COMPACT MODE ════════
             TASK-079 Phase 5 (2026-08-03, caught in live mobile QA): this row
             was a single non-wrapping flex line. Phase 4 wired `back-page` onto
             the 7 secondary views, and on those that ALSO have an actions-slot
             button (/orders "+ สร้างคำสั่งซื้อ", /referrals "+ สร้าง") the row
             then had back + divider + icon + title + actions + toggle competing
             for a 430px phone. Result: the title was crushed to one glyph per
             line and the action button was clipped off the right edge.
             `flex-wrap` + a `basis-full` title on the smallest breakpoint lets
             the title take its own line instead of being squeezed to nothing,
             and gap-4→gap-2/gap-4 buys back the horizontal room that caused it. -->
        <div v-if="effectiveCollapsed" class="flex flex-wrap items-center gap-2 sm:gap-4 px-4 py-3">
            <!-- Optional Back button -->
            <template v-if="backPage || backLabel">
                <button @click="goBack"
                        class="shrink-0 flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-ink-card-muted hover:bg-surface-chip hover:text-ink-card transition text-xs font-bold whitespace-nowrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>{{ backLabel }}</span>
                </button>
                <!-- A 1px vertical rule is a hairline, so it takes the
                     border token even though it is painted with `bg-`. -->
                <div class="shrink-0 w-px h-6 bg-line-card hidden sm:block"></div>
            </template>

            <!--
                COLLAPSED IS A TITLE AND ITS NUMBERS. NOTHING ELSE.
                (human report with a screenshot, 2026-08-21)

                What this row was, at md and up: the icon halo, then a title
                clipped to "ค่..." above a subtitle clipped to "ส...", then
                the KPIs — and the KPIs rendered BEFORE the icon, because the
                icon and title blocks carry `order-1` while the KPI block
                below carried no order at all, i.e. order-0. Three separate
                faults reading as one mess.

                Fixed by deletion rather than by tuning widths:

                * THE ICON IS GONE. Collapsed mode exists to buy back
                  vertical space, and a 36px decorative halo repeating the
                  glyph already lit in the bottom nav is the first thing that
                  should pay for it. Expanded mode keeps it, where there is
                  room for it to mean something.

                * THE SUBTITLE IS GONE. It was `truncate hidden md:block`, so
                  it only ever appeared at the widths where the KPIs were
                  ALSO competing for the row — exactly where it had no space
                  and became one glyph and an ellipsis. A subtitle that can
                  only render as "ส..." is not a subtitle.

                * THE TITLE NO LONGER TRUNCATES. `whitespace-nowrap`, and no
                  `flex-1`: it takes the width it needs and the row wraps
                  around it (the parent is already `flex-wrap`). A page title
                  is the one thing here that must always be readable — it is
                  the answer to "where am I".

                `order-1` matches the KPI block's new `order-1` so the two
                cannot swap places again; actions keep `order-2` and stay
                last.
            -->
            <div class="min-w-0 order-1">
                <h1 class="text-base sm:text-lg font-bold text-ink-card whitespace-nowrap leading-tight">{{ title }}</h1>
            </div>

            <!-- Sprint B+C: KPI vertical key-value stack — label small above, value bold below
                 All values one neutral ink (single color, no chromatic noise).
                 TASK-098: these KPIs are bare on the card (no pill), so they
                 take the CARD ink scale; the expanded-mode KPI cards below
                 sit on a pill and take the CHIP ink instead. -->
            <div v-if="kpis.length" class="hidden md:flex items-center gap-6 ml-auto mr-2 shrink-0 order-1">
                <div v-for="(k, idx) in kpis.slice(0, 4)" :key="idx" class="text-right">
                    <div class="text-[11px] text-ink-card-muted uppercase tracking-wider font-bold whitespace-nowrap">{{ k.label }}</div>
                    <div class="text-sm font-bold text-ink-card leading-tight">{{ k.value }}</div>
                </div>
            </div>

            <!-- Actions + Toggle.
                 TASK-079 Phase 5 forced this onto its own full-width line
                 below `sm` (`order-2 basis-full`) to stop it fighting the
                 title for one row. That fixed the crush but cost a whole
                 44px row on EVERY phone — `sm:` tracks the viewport, so on
                 a real 390px phone the second row was unconditional, never
                 a fallback.
                 TASK-085 (2026-08-03, human: header + filter must fit in
                 20% of the screen) removes the forced break. The title now
                 carries `min-w-0 flex-1` (added in the same Phase 5 pass),
                 which is what actually prevents the one-glyph-per-line
                 crush — the row can hold icon + title + action. `flex-wrap`
                 on the parent stays as the genuine fallback: a long action
                 label still wraps rather than clipping, it just no longer
                 wraps when there was room all along.

                 Two live-QA findings shaped the final form:
                   - `order-2` MUST stay. Dropping it sent this block to
                     order-0 — below the icon/title's `order-1` — so the
                     action button rendered BEFORE the icon and shoved the
                     title off the right edge.
                   - The forced row is still needed WHEN A BACK BUTTON IS
                     PRESENT. On /orders (back "หน้าหลัก" + divider + icon +
                     title + "+ สร้างคำสั่งซื้อ" + toggle) one row is
                     genuinely too crowded and the title went back to two
                     glyphs per line. So the wrap is now conditional on
                     `hasBack` instead of unconditional: screens without a
                     back button — every bottom-nav tab, including the
                     /clients screen this budget was set for — get their row
                     back, and the crowded secondary screens keep the
                     protection Phase 5 gave them. -->
            <div class="shrink-0 flex items-center gap-2 justify-end order-2 md:ml-0"
                 :class="hasBack ? 'basis-full sm:basis-auto sm:ml-auto' : 'ml-auto'">
                <slot name="actions" />
                <button v-if="!isMobile" @click="toggleCollapsed"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card transition"
                        :title="td('common.expand')">
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
                        class="mb-3 flex items-center gap-1.5 px-2 py-1 -ml-2 rounded-lg text-ink-card-muted hover:bg-surface-chip hover:text-ink-card transition text-xs font-bold">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    <span>{{ backLabel }}</span>
                </button>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1 min-w-0">
                        <!-- Icon halo — chip surface, see the compact-mode note. -->
                        <div class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center bg-surface-chip">
                            <Icon :name="icon" :size="26" :class="iconColor" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-xl sm:text-2xl font-bold text-ink-card leading-tight">{{ title }}</h1>
                            <p v-if="subtitle" class="text-sm text-ink-card-subtle mt-0.5">{{ subtitle }}</p>
                            <p v-if="description" class="text-sm text-ink-card-muted mt-2">{{ description }}</p>
                        </div>
                    </div>
                    <div class="shrink-0 flex items-center gap-2">
                        <slot name="actions" />
                        <button @click="toggleCollapsed"
                                class="w-9 h-9 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card transition"
                                :title="td('common.collapse')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="18 15 12 9 6 15"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sprint B+C: KPI cards row — key-value stack, one neutral ink.
                 TASK-098 / ADR-023 §2.2: THIS is the pill the report was
                 about. It was `bg-slate-50/80 border border-slate-100` with
                 slate-500 / slate-900 / slate-400 text inside; on a dark
                 card the `.has-card-text` override repainted the TEXT light
                 while the pill's own background stayed pale, so it rendered
                 light-on-light — on every view in the app.
                 The pill and its ink are now one derived pair
                 (`bg-surface-chip` + `text-ink-chip`), so they move
                 together whatever the tenant picks. The three-level
                 hierarchy label / value / hint is kept with alpha on the
                 SAME ink rather than three different slate steps — fading
                 an ink toward its own surface is safe on any theme,
                 hopping to another palette step is not. -->
            <div v-if="kpis.length" class="px-5 pb-5">
                <div :class="['grid gap-3',
                              kpis.length === 1 ? 'grid-cols-1' :
                              kpis.length === 2 ? 'grid-cols-2' :
                              kpis.length === 3 ? 'grid-cols-3' :
                              kpis.length <= 4 ? 'grid-cols-2 md:grid-cols-4' :
                              'grid-cols-2 md:grid-cols-3 lg:grid-cols-6']">
                    <div v-for="(k, idx) in kpis" :key="idx"
                         class="p-3 rounded-xl bg-surface-chip/80 border border-line-card-subtle">
                        <div class="text-[11px] text-ink-chip/70 uppercase tracking-wider font-bold">{{ k.label }}</div>
                        <div class="text-xl font-bold text-ink-chip mt-1 leading-tight">{{ k.value }}</div>
                        <div v-if="k.hint" class="text-[11px] text-ink-chip/60 mt-0.5">{{ k.hint }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sprint D: Tabs slot — รวมอยู่ใน card เดียวกัน (flatten) -->
        <div v-if="$slots.tabs" class="border-t border-line-card-subtle">
            <slot name="tabs" />
        </div>
    </div>
</template>

<style scoped>
.hero-header { transition: all 0.3s ease; }
</style>
