<script setup lang="ts">
/**
 * FilterSheet — TASK-085 (2026-08-03, human-confirmed: "ยุบเป็นปุ่ม
 * ตัวกรอง").
 *
 * A bottom sheet holding one single-select filter list.
 *
 * WHY IT EXISTS
 * ClientsView showed its client-category filter as a horizontally
 * scrolling chip row under the search box. Two problems, both visible in
 * the human's screenshot:
 *   1. The row ran past the card edge and the last chip was sliced in
 *      half — the same clipped-scroller failure TASK-084 fixed on the tab
 *      bar, and just as unreadable here.
 *   2. It cost a full 60px row of vertical space on a phone, on a screen
 *      whose header budget is capped at 20% of the viewport.
 * A sheet costs one row (the trigger button, which shares the search
 * row) and holds any number of options at a comfortable size, which is
 * why it wins on both counts. Categories are BR-7 config — an admin can
 * add as many as they like, so the layout must not assume "few enough to
 * fit on one line".
 *
 * Vertical list rather than a wrapped chip grid: options are read, not
 * scanned spatially, and a full-width row gives a 48px target with the
 * label never truncated.
 *
 * `value` is intentionally loose (string | number | null) because the
 * natural key differs per caller — a category id here, a status string
 * elsewhere. null is the reserved "no filter / show all" value.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-173 (2026-08-12) — GENERALISED, NOT COPIED.
 *
 * `AppSelect` needed exactly this surface: an overlay we render ourselves
 * holding one single-select list, because the OS-drawn `<select>` popup is
 * the one place ADR-018's white-labelling cannot reach. Standing a second
 * bottom sheet up next to this one would have duplicated the scrim, the
 * slide transition, the body-scroll lock, the safe-area padding, the
 * max-height scroller and the ADR-023 token choices — six things that then
 * drift apart. So two seams were added here instead, both opt-in and both
 * inert for the original caller:
 *
 *   1. A default SLOT. Present → it replaces the built-in option list;
 *      absent → the `options` v-for below still renders, unchanged. That is
 *      what lets AppSelect put a real `role="listbox"` inside this chrome
 *      while the category filter keeps its plain button rows.
 *   2. `placement`. `'sheet'` (default) is the phone bottom sheet, verbatim.
 *      `'anchored'` is the same panel positioned under a trigger for wider
 *      viewports — a popover is NOT a sheet, so it gets a transparent
 *      click-catcher and a fade instead of a scrim and a slide, but it is
 *      still this one component's panel, list container and tokens.
 *
 * `options`/`selected` became optional (defaulted) purely because a
 * slot-driven caller has nothing to pass them; every existing call site
 * still passes both.
 */
import { computed, watch } from 'vue'
import Icon from './Icon.vue'

export interface FilterOption {
    value: string | number | null
    label: string
    count?: number
}

/**
 * Viewport-relative trigger box for `placement="anchored"`. Deliberately a
 * plain object rather than a DOMRect so a caller can compute it once at open
 * time — the body scroll lock below means it cannot go stale while open.
 */
export interface SheetAnchor {
    top: number
    bottom: number
    left: number
    width: number
}

const props = withDefaults(
    defineProps<{
        /** v-model:open — the sheet is fully controlled by the caller. */
        open: boolean
        title: string
        options?: FilterOption[]
        selected?: string | number | null
        /** 'sheet' = bottom sheet (phone). 'anchored' = popover under `anchor`. */
        placement?: 'sheet' | 'anchored'
        anchor?: SheetAnchor | null
    }>(),
    {
        options: () => [],
        selected: null,
        placement: 'sheet',
        anchor: null,
    },
)

const emit = defineEmits<{
    'update:open': [value: boolean]
    select: [value: string | number | null]
}>()

function close() {
    emit('update:open', false)
}

function choose(value: string | number | null) {
    emit('select', value)
    close()
}

/**
 * Lock the page behind the sheet. Without this, dragging on the sheet
 * scrolls the client list underneath — the single most obvious "this is a
 * web page, not an app" tell on a modal surface.
 */
watch(() => props.open, (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
})

const isAnchored = computed(() => props.placement === 'anchored' && props.anchor !== null)

/** Popover height cap, in px — mirrors the `max-h-72` on the scroller. */
const ANCHORED_MAX_H = 288
/** Gap between the trigger and the popover edge. */
const ANCHORED_GAP = 4

/**
 * Anchored placement only. The panel opens BELOW the trigger unless there is
 * more room above — a 77-option list under a field near the bottom of the
 * viewport would otherwise open into 20px of space and be unusable, which is
 * the same "list you cannot read" failure this component exists to fix.
 */
const anchoredStyle = computed(() => {
    const a = props.anchor
    if (!a) return undefined
    const viewportH = typeof window === 'undefined' ? 0 : window.innerHeight
    const roomBelow = viewportH - a.bottom
    const openUpward = roomBelow < ANCHORED_MAX_H && a.top > roomBelow
    return {
        left: `${a.left}px`,
        width: `${a.width}px`,
        ...(openUpward
            ? { bottom: `${viewportH - a.top + ANCHORED_GAP}px` }
            : { top: `${a.bottom + ANCHORED_GAP}px` }),
    }
})
</script>

<template>
    <!-- TASK-098 / ADR-023: the sheet's colours now come from the
         surface/ink token layer (`bg-surface-card`, `text-ink-card*`), so a
         tenant with a dark card no longer gets a stark white panel sliding
         up between dark siblings (ADR-023 §2.1 — modals and sheets were
         outside the theme entirely because they used plain `bg-surface-card`).
         The backdrop stays `bg-slate-900/40`: a scrim is not a surface. -->
    <Teleport to="body">
        <!-- The scrim is a scrim only for the sheet. An anchored popover that
             darkened the page would read as a modal, so there it degrades to
             an invisible full-screen click-catcher — same dismissal, no
             visual claim on the page behind it. -->
        <Transition name="sheet-fade">
            <div v-if="open"
                 :class="isAnchored ? 'fixed inset-0 z-[60]' : 'fixed inset-0 z-[60] bg-slate-900/40'"
                 @click="close"></div>
        </Transition>

        <Transition :name="isAnchored ? 'sheet-fade' : 'sheet-slide'">
            <div v-if="open"
                 :class="isAnchored
                     ? 'fixed z-[61] rounded-xl border border-line-card bg-surface-card shadow-[0_8px_24px_rgba(0,0,0,0.18)]'
                     : 'fixed inset-x-0 bottom-0 z-[61] mx-auto w-full max-w-md rounded-t-3xl bg-surface-card shadow-[0_-8px_24px_rgba(0,0,0,0.18)] pb-[env(safe-area-inset-bottom)]'"
                 :style="anchoredStyle"
                 :role="isAnchored ? undefined : 'dialog'"
                 :aria-modal="isAnchored ? undefined : 'true'"
                 :aria-label="isAnchored ? undefined : title">
                <!-- Grabber. Purely affordance: it tells a thumb that this
                     panel came from the bottom edge and dismisses downward.
                     Meaningless on a popover that came from a trigger. -->
                <template v-if="!isAnchored">
                    <div class="flex justify-center pt-3 pb-1">
                        <div class="h-1 w-10 rounded-full bg-slate-300"></div>
                    </div>

                    <div class="flex items-center justify-between px-5 py-2">
                        <h2 class="text-base font-bold text-ink-card">{{ title }}</h2>
                        <button type="button"
                                @click="close"
                                class="w-11 h-11 -mr-2 flex items-center justify-center rounded-full text-ink-card-subtle active:bg-surface-chip"
                                aria-label="ปิด">
                            <Icon name="close" :size="18" />
                        </button>
                    </div>
                </template>

                <!-- max-h keeps a long BR-7 category list (or TASK-173's 77
                     provinces) from covering the whole screen; the list
                     scrolls inside the panel instead. -->
                <div :class="isAnchored ? 'max-h-72 overflow-y-auto p-1' : 'max-h-[55vh] overflow-y-auto px-2 pb-4'">
                    <!-- TASK-173 — a caller that owns its own list semantics
                         (AppSelect's role="listbox") fills this slot; the
                         fallback below is the original filter list. -->
                    <slot>
                        <button v-for="opt in options"
                                :key="String(opt.value)"
                                type="button"
                                @click="choose(opt.value)"
                                class="w-full min-h-[48px] px-3 flex items-center gap-3 rounded-xl text-left active:bg-surface-chip transition-colors">
                            <span class="flex-1 min-w-0 truncate text-sm font-bold"
                                  :class="selected === opt.value ? 'text-ink-brand' : 'text-ink-card'">
                                {{ opt.label }}
                            </span>
                            <span v-if="opt.count !== undefined" class="shrink-0 text-xs font-bold text-ink-card-subtle tabular-nums">
                                {{ opt.count }}
                            </span>
                            <Icon v-if="selected === opt.value" name="check" :size="18" class="shrink-0 text-ink-brand" />
                        </button>
                    </slot>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.sheet-fade-enter-active,
.sheet-fade-leave-active { transition: opacity 0.2s ease; }
.sheet-fade-enter-from,
.sheet-fade-leave-to { opacity: 0; }

.sheet-slide-enter-active,
.sheet-slide-leave-active { transition: transform 0.25s cubic-bezier(0.32, 0.72, 0, 1); }
.sheet-slide-enter-from,
.sheet-slide-leave-to { transform: translateY(100%); }

@media (prefers-reduced-motion: reduce) {
    .sheet-slide-enter-active,
    .sheet-slide-leave-active { transition-duration: 0.01ms; }
}
</style>
