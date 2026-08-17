<script setup lang="ts">
/**
 * TabFilterBar — filter tabs for the Agent Portal's list screens.
 *
 * TASK-084 (2026-08-03, human report: "แก้ไข tab ทุกหน้าจอให้เหมาะกับ
 * Mobile", with a screenshot of Academy at ~375px where "จบแล้ว" and its
 * count were sliced in half by the right edge).
 *
 * WHY THE OLD VERSION BROKE ON MOBILE
 * The bar was a single `overflow-x-auto no-scrollbar` row. On desktop the
 * tabs fit, so nobody noticed; on a 375px phone the third tab ran past
 * the edge and — because the scrollbar is deliberately hidden — the cut
 * looked like a rendering bug rather than "there is more to the right".
 * Three tabs is the common case here and three tabs DO fit 375px, so the
 * scroll container was buying flexibility the screens never needed while
 * costing legibility on every one of them.
 *
 * TWO LAYOUTS, CHOSEN AUTOMATICALLY (human-confirmed: "ผสมอัตโนมัติ")
 *  - ≤ SEGMENTED_MAX tabs → segmented control: each tab flex-1, equal
 *    width, no scrolling, nothing to clip. Covers Academy / Commission /
 *    Referrals, which all have exactly 3 fixed tabs.
 *  - more than that → horizontal scroll, but with the affordances the old
 *    version lacked: a fade on whichever edge has content beyond it,
 *    scroll-snap, trailing padding so the last tab can reach the middle
 *    of the viewport, and auto-centering of the active tab. Covers
 *    Pipeline, whose tab count follows the BR-4.3 stage list (up to 6) —
 *    those can never fit a phone, so scrolling there is honest, not a
 *    fallback.
 *
 * The switch is on tabs.length, not a prop, so a screen that grows a
 * fourth tab later degrades to the scrolling layout on its own instead of
 * silently regressing to a clipped row.
 *
 * COUNT BADGE
 * Human-confirmed ("เล็กลงและปรับตำแหน่งไปอยู่บนตัวเลขเหมือนระบบแจ้งเตือน"):
 * the count is now a notification-style badge pinned to the top-right
 * corner of the label, not an inline pill. Inline pills forced label and
 * number to compete for the same horizontal space, which is exactly what
 * pushed the last tab off-screen.
 *
 * Geometry and the >0 rule are copied from NotificationBell.vue on
 * purpose — "เหมือนระบบแจ้งเตือน" means the same badge, so an empty
 * bucket shows no badge at all rather than a "0". A first attempt DID
 * render 0 (muted, on the theory that "0" is useful information for a
 * filter); on the dark tenant theme it came out as an unreadable pale
 * dot, and three such dots across the bar were pure noise. The empty
 * bucket is communicated by the list's empty state instead.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-098 / ADR-023 (2026-08-04) — the neutral colours here now come
 * from the surface/ink token layer.
 *
 * That "unreadable pale dot" above was the same bug ADR-023 §2.2/§2.3
 * describe, worked around locally by going solid instead of tinted. The
 * workaround stays (solid still reads better at 18px) but the colours
 * behind it are no longer guessed: the inactive badge is the derived
 * `bg-surface-chip` + `text-ink-chip` pair, and the active brand badge's
 * label is `text-ink-primary` rather than an assumed `text-white`.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type PropType } from 'vue'

export interface FilterTab {
    id: string
    label: string
    count?: number
}

const props = defineProps({
    modelValue: { type: String, default: 'all' },
    tabs: { type: Array as PropType<FilterTab[]>, required: true },
    accentColor: { type: String, default: 'emerald' },  // emerald, violet, brand, etc.
    // ขนาด: 'sm' | 'md' (default)
    size: { type: String, default: 'md' },
})
const emit = defineEmits<{ 'update:modelValue': [id: string] }>()

function select(id: string) {
    emit('update:modelValue', id)
}

/**
 * Above this count the segmented layout stops being readable on a 375px
 * screen (labels here are Thai words, not one-word English ones — at 4
 * equal columns they start truncating mid-word). 3 is also exactly what
 * the three fixed-tab screens use.
 */
const SEGMENTED_MAX = 3
const segmented = computed(() => props.tabs.length <= SEGMENTED_MAX)

// Tailwind-friendly class mapping per accent color.
//
// TASK-098 / ADR-023: only the `brand` row is tokenised. `bg-brand-600`
// IS `--surface-primary`, so its label becomes `text-ink-primary` — the
// ink the theme store derived against that exact colour. The other rows
// are FIXED Tailwind ramps (emerald/violet/blue/amber/rose/slate/gold)
// that no tenant can repaint, so `text-white` on a -600 step is provably
// correct there and `--ink-primary` would be the wrong token to borrow
// (that is the mistake ADR-023 §2.4 records against ShareLinkModal).
const accentMap: Record<string, { border: string; text: string; bg: string; count: string }> = {
    emerald: { border: 'border-emerald-500', text: 'text-ink-success', bg: 'bg-surface-success', count: 'bg-emerald-600 text-white' },
    violet:  { border: 'border-violet-500',  text: 'text-violet-700',  bg: 'bg-violet-50',  count: 'bg-violet-600 text-white' },
    // Primary brand accent — navy sampled from the GENESENN co-brand
    // logo (CI-002, 2026-07-08), replacing the earlier "indigo" key.
    brand:   { border: 'border-brand-500',   text: 'text-ink-brand',   bg: 'bg-brand-50',   count: 'bg-brand-600 text-ink-primary' },
    blue:    { border: 'border-blue-500',    text: 'text-blue-700',    bg: 'bg-blue-50',    count: 'bg-blue-600 text-white' },
    amber:   { border: 'border-amber-500',   text: 'text-ink-warning',   bg: 'bg-surface-warning',   count: 'bg-amber-600 text-white' },
    rose:    { border: 'border-rose-500',    text: 'text-ink-danger',    bg: 'bg-surface-danger',    count: 'bg-rose-600 text-white' },
    slate:   { border: 'border-slate-500',   text: 'text-ink-card',   bg: 'bg-surface-chip',   count: 'bg-slate-600 text-white' },
    // Secondary accent per CI-002 (2026-07-08): brand (navy) stays the
    // primary/nav accent; gold (also from GENESENN) is reserved for
    // gamification/success moments (XP, badges earned, completed) —
    // fully replaces the earlier "lime" key. See docs/design/CI-002.
    gold:    { border: 'border-gold-500',    text: 'text-gold-700',    bg: 'bg-gold-50',    count: 'bg-gold-600 text-white' },
}
const accent = computed(() => accentMap[props.accentColor] ?? accentMap.emerald!)

/**
 * min-h-[44px] on both layouts is the Apple HIG / Material minimum touch
 * target, and was the other half of the mobile complaint — the old
 * `py-1.5`/`py-2` rows were ~30px tall.
 */
const sizeClasses = computed(() => props.size === 'sm'
    ? { tab: 'min-h-[40px] px-2 text-xs' }
    : { tab: 'min-h-[44px] px-2 text-sm' })

/** Same shape/size as NotificationBell.vue's unread badge. */
const BADGE_BASE = 'absolute -top-2 -right-3 min-w-[18px] h-[18px] px-1 rounded-full text-[11px] font-bold flex items-center justify-center shadow-sm tabular-nums'

function badgeClasses(tabId: string): string {
    // Solid fill in both states — the tenant theme can make this card
    // dark, where the old pale-tint pills lost all contrast.
    // TASK-098 / ADR-023: the inactive fill was a hardcoded
    // `bg-slate-500 text-white`, i.e. the same guess in the other
    // direction — a mid slate that vanishes into a mid-grey card. It is
    // now the chip pair, derived from the card's own lightness.
    return `${BADGE_BASE} ${props.modelValue === tabId ? accent.value.count : 'bg-surface-chip text-ink-chip'}`
}

function badgeText(count: number): string {
    return count > 99 ? '99+' : String(count)
}

// ---------------------------------------------------------------- scroll
// Only meaningful in the non-segmented layout; the refs stay inert
// otherwise because the scroller element is never rendered.

const scroller = ref<HTMLElement | null>(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(false)

function updateEdges() {
    const el = scroller.value
    if (!el) {
        canScrollLeft.value = false
        canScrollRight.value = false

        return
    }

    // 4px slack: sub-pixel layout rounding otherwise leaves the fade
    // permanently switched on at either end.
    canScrollLeft.value = el.scrollLeft > 4
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
}

/**
 * Centre the active tab. Without this, deep-linking to a late filter (or
 * simply re-entering Pipeline on the last stage you used) leaves the
 * selected tab off-screen, so the bar looks like nothing is selected.
 *
 * Done by hand rather than scrollIntoView() because scrollIntoView also
 * scrolls every scrollable ancestor — including the page — which yanks
 * the whole view down on mobile.
 */
function centerActiveTab() {
    const el = scroller.value
    if (!el) {
        return
    }

    const active = el.querySelector<HTMLElement>('[data-active="true"]')
    if (!active) {
        return
    }

    el.scrollTo({
        left: Math.max(0, active.offsetLeft - (el.clientWidth - active.clientWidth) / 2),
        behavior: 'smooth',
    })
}

/**
 * The "there is more this way" cue, as a mask rather than a white
 * gradient overlay.
 *
 * First attempt overlaid `bg-gradient-to-l from-white`; it was invisible
 * on the dark tenant theme (TASK-055 lets a company repaint this card),
 * because the fade has to match a background this component does not own
 * and cannot read. Masking fades the CONTENT to transparent instead, so
 * it is correct on every theme by construction — and it needs no extra
 * DOM, so nothing can sit on top of a tab and swallow a tap.
 */
const scrollerStyle = computed(() => {
    if (!canScrollLeft.value && !canScrollRight.value) {
        return undefined
    }

    const left = canScrollLeft.value ? 'transparent 0, black 20px' : 'black 0'
    const right = canScrollRight.value ? 'black calc(100% - 24px), transparent 100%' : 'black 100%'
    const gradient = `linear-gradient(to right, ${left}, ${right})`

    return { maskImage: gradient, WebkitMaskImage: gradient }
})

onMounted(async () => {
    await nextTick()
    updateEdges()
    centerActiveTab()
    window.addEventListener('resize', updateEdges)
})

onBeforeUnmount(() => window.removeEventListener('resize', updateEdges))

watch(() => props.modelValue, async () => {
    await nextTick()
    centerActiveTab()
})

// Tab counts change as data loads, which changes tab widths — and in
// Pipeline the number of tabs itself depends on the loaded stages.
watch(() => props.tabs, async () => {
    await nextTick()
    updateEdges()
    centerActiveTab()
}, { deep: true })
</script>

<template>
    <!-- Sprint UI-WS-4 A+D: ลบ border container ออก (inline ใน HeroHeader)
         bg transparent — รับสีจาก parent (HeroHeader bg-surface-card/95),
         ซึ่งเป็นเหตุผลที่ตัวอักษรใช้ ink ของ card (TASK-098) -->
    <div class="flex items-center gap-1" style="font-family: var(--app-font);">
        <!-- ── Segmented: ≤3 tabs, equal width, never scrolls ───────── -->
        <div v-if="segmented" class="flex flex-1 min-w-0 items-stretch">
            <button v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    @click="select(tab.id)"
                    :class="[
                        'flex flex-1 min-w-0 items-center justify-center gap-1 font-bold transition border-b-2 -mb-px active:opacity-70',
                        sizeClasses.tab,
                        modelValue === tab.id
                            ? `${accent.text} ${accent.border}`
                            : 'text-ink-card-muted border-transparent hover:text-ink-card'
                    ]">
                <span class="relative inline-flex min-w-0 items-center">
                    <span class="truncate">{{ tab.label }}</span>
                    <span v-if="tab.count" :class="badgeClasses(tab.id)">{{ badgeText(tab.count) }}</span>
                </span>
            </button>
        </div>

        <!-- ── Scrolling: >3 tabs (Pipeline's BR-4.3 stages) ─────────── -->
        <div v-else class="relative flex-1 min-w-0">
            <div ref="scroller"
                 @scroll="updateEdges"
                 :style="scrollerStyle"
                 class="flex items-stretch gap-1 overflow-x-auto no-scrollbar snap-x pr-8">
                <button v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        :data-active="modelValue === tab.id"
                        @click="select(tab.id)"
                        :class="[
                            'flex shrink-0 snap-start items-center gap-1 font-bold transition border-b-2 -mb-px active:opacity-70',
                            sizeClasses.tab,
                            modelValue === tab.id
                                ? `${accent.text} ${accent.border}`
                                : 'text-ink-card-muted border-transparent hover:text-ink-card'
                        ]">
                    <span class="relative inline-flex items-center">
                        <span class="whitespace-nowrap">{{ tab.label }}</span>
                        <span v-if="tab.count" :class="badgeClasses(tab.id)">{{ badgeText(tab.count) }}</span>
                    </span>
                </button>
            </div>

        </div>

        <!-- Right extra slot (optional summary, search, etc.) -->
        <div v-if="$slots['right-extra']" class="shrink-0 pr-2">
            <slot name="right-extra" />
        </div>
    </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
