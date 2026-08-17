<script setup lang="ts">
/**
 * InfoPopover — the ⓘ that holds an explanation. TASK-188 Phase A.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT A `title=""`.
 *
 * TASK-188 D1 (human decision, 2026-08-13) moves *every* explanation on the
 * Academy course builder behind this icon — including the consequence
 * warnings. That makes this component the ONLY route to the screen's
 * guidance, so each requirement below is load-bearing rather than polish:
 *
 *  - It opens on CLICK/TAP. The Admin is used on tablets. A `@mouseenter`
 *    affordance, or the browser's native `title` attribute (239 uses in this
 *    app, 36 of them in AcademyManagementView.vue alone), never opens on a
 *    touch device — under D1 that means the guidance does not exist there.
 *  - The trigger is a real `<button type="button">`, which is what makes
 *    Enter and Space activate it. A `<div>` or `<span>` with a click handler
 *    is not reachable by Tab and is not activated by the keyboard; that is
 *    the single most common way this component gets built wrong.
 *  - Escape closes it and returns focus to the trigger, so a keyboard user is
 *    never stranded past the end of the form.
 *  - The panel is TELEPORTED TO <body> and positioned `fixed`. The builder is
 *    a two-pane `grid grid-cols-12 gap-4 items-start` (AcademyManagementView
 *    .vue:2283) with no scroll container, and the cards inside it use
 *    `overflow-hidden`. An absolutely-positioned panel inside such a card is
 *    clipped at the card edge — the popover on the last field of the narrow
 *    column would simply be cut in half. Teleporting escapes every ancestor's
 *    overflow and stacking context at once. `Teleport` is already the idiom
 *    used for this in the app (ProductCatalogView.vue:1130).
 *
 * THEMING. ADR-023's surface/ink pairs are the right tokens for this, but
 * ADR-023 §6 decision 3 scoped that work to the Agent Portal — `frontend-admin`
 * has no `--surface-*` / `--ink-*` custom properties and no theme runtime
 * today (src/assets/main.css defines none; tailwind.config.js's brand ramp is
 * literal hex). Writing `bg-card text-on-card` here would compile to nothing
 * and the panel would render unstyled. So the panel reads the ADR-023 token
 * NAMES with the current values as CSS fallbacks (see <style> below): correct
 * today, and correct the day ADR-023 is extended to this app, with no second
 * convention to migrate.
 *
 * The glyph is the existing `info` icon (Icon.vue:139).
 *
 * Usage:
 *   <InfoPopover label="อนุญาตดาวน์โหลด" text="เปิดแล้ว ผู้เรียนจะโหลดไฟล์ได้" />
 *
 *   <InfoPopover label="เกณฑ์ผ่าน">
 *     <p>...</p>
 *     <p class="mt-2">...</p>
 *   </InfoPopover>
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, type CSSProperties } from 'vue'
import Icon from './Icon.vue'
import { useI18n } from '../../composables/useI18n'

const props = withDefaults(
    defineProps<{
        /**
         * What this ⓘ explains, e.g. "อนุญาตดาวน์โหลด". Read into the button's
         * accessible name ("ดูคำอธิบาย: อนุญาตดาวน์โหลด") so a screen-reader
         * user hears WHICH control the icon belongs to — 32 identical
         * "ดูคำอธิบาย" buttons on one screen would be useless.
         */
        label?: string
        /** The explanation itself. Ignored when the default slot is used. */
        text?: string
        /** Icon size in px. 16 sits level with a 14px label. */
        size?: number | string
        /** Panel width in px. Always clamped to the viewport as well. */
        width?: number
    }>(),
    { label: '', text: '', size: 16, width: 288 },
)

const { t } = useI18n()

/** Vue 3.5 useId() — stable, collision-free, and SSR-safe. */
const panelId = `info-popover-${useId()}`

const triggerRef = ref<HTMLButtonElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)
const top = ref(0)
const left = ref(0)

/**
 * The button carries its own class list from the call site, and the component
 * has two template roots (button + Teleport), so attribute inheritance has to
 * be aimed explicitly or Vue drops it with a warning.
 */
defineOptions({ inheritAttrs: false })

const openLabel = computed(() => t('infoPopoverOpen', 'ดูคำอธิบาย', 'Show explanation'))
const triggerLabel = computed(() =>
    props.label ? `${openLabel.value}: ${props.label}` : openLabel.value,
)

const panelStyle = computed<CSSProperties>(() => ({
    position: 'fixed',
    top: `${top.value}px`,
    left: `${left.value}px`,
    // The clamp matters at tablet portrait width, where a 288px panel opened
    // from the right-hand column would otherwise overhang the viewport.
    width: `min(${props.width}px, calc(100vw - 16px))`,
    zIndex: 900, // above the sticky AdminNavigation (z-50), below ConfirmDialog (z-[1000])
}))

const GAP = 8 // between the icon and the panel
const EDGE = 8 // minimum breathing room against the viewport edge

/**
 * Places the panel in viewport coordinates. Called after the panel exists (so
 * its real height is known), and again on scroll/resize because `fixed`
 * coordinates do not follow the trigger on their own.
 */
function position(): void {
    const trigger = triggerRef.value
    const panel = panelRef.value
    if (!trigger || !panel) return

    const rect = trigger.getBoundingClientRect()
    const vw = window.innerWidth
    const vh = window.innerHeight
    const pw = panel.offsetWidth
    const ph = panel.offsetHeight

    let x = rect.left
    if (x + pw > vw - EDGE) x = vw - EDGE - pw
    if (x < EDGE) x = EDGE

    // Below the icon by default; above it when the panel would run off the
    // bottom of the viewport and there is room above. This is the "bottom of
    // the panel" case in TASK-188 §3 — the builder's tallest column ends well
    // below the fold.
    const below = rect.bottom + GAP
    const above = rect.top - GAP - ph
    let y = below
    if (below + ph > vh - EDGE && above >= EDGE) y = above

    left.value = x
    top.value = y
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') close({ returnFocus: true })
}

/**
 * Closes when the press lands outside both the trigger and the panel.
 *
 * The containment check is not decoration: this listener is attached from
 * inside the trigger's own click handler, and that click has not finished
 * propagating to `document` yet, so without the check the popover would close
 * on the very click that opened it.
 */
function onDocumentClick(event: MouseEvent): void {
    const target = event.target as Node | null
    if (!target) return
    if (triggerRef.value?.contains(target)) return
    if (panelRef.value?.contains(target)) return
    close()
}

function bind(): void {
    document.addEventListener('keydown', onDocumentKeydown)
    document.addEventListener('click', onDocumentClick)
    window.addEventListener('resize', position)
    // Capture, so a scroll inside any nested container repositions us too.
    window.addEventListener('scroll', position, true)
}

function unbind(): void {
    document.removeEventListener('keydown', onDocumentKeydown)
    document.removeEventListener('click', onDocumentClick)
    window.removeEventListener('resize', position)
    window.removeEventListener('scroll', position, true)
}

async function open(): Promise<void> {
    if (isOpen.value) return
    isOpen.value = true
    bind()
    await nextTick()
    position()
}

function close({ returnFocus = false }: { returnFocus?: boolean } = {}): void {
    if (!isOpen.value) return

    // Read BEFORE the panel is torn down. Escape is caught at document level,
    // so it also fires when the user has already tabbed on to another field —
    // in that case closing is right but yanking their focus backwards is not.
    // Focus is only restored when this component was holding it.
    const active = document.activeElement
    const heldFocus = Boolean(
        active && (triggerRef.value?.contains(active) || panelRef.value?.contains(active)),
    )

    isOpen.value = false
    unbind()

    // Otherwise removing the panel drops focus on <body>, and the keyboard user
    // restarts from the top of the document instead of the field they were
    // reading about.
    if (returnFocus && heldFocus) triggerRef.value?.focus()
}

/**
 * Enter and Space are deliberately NOT handled here. The trigger is a native
 * `<button>`, so the browser already turns both into a click — re-handling
 * them would fire twice on Space (keydown + the browser's synthesised click)
 * and toggle the panel shut again.
 */
function toggle(): void {
    if (isOpen.value) close()
    else void open()
}

onBeforeUnmount(unbind)
</script>

<template>
    <button
        ref="triggerRef"
        type="button"
        class="info-popover-trigger"
        :class="{ 'is-open': isOpen }"
        :aria-label="triggerLabel"
        :aria-expanded="isOpen ? 'true' : 'false'"
        :aria-controls="isOpen ? panelId : undefined"
        :aria-describedby="isOpen ? panelId : undefined"
        v-bind="$attrs"
        @click="toggle"
    >
        <Icon name="info" :size="size" />
    </button>

    <Teleport to="body">
        <div
            v-if="isOpen"
            :id="panelId"
            ref="panelRef"
            role="tooltip"
            class="info-popover-panel"
            :style="panelStyle"
        >
            <slot>{{ text }}</slot>
        </div>
    </Teleport>
</template>

<style scoped>
/*
 * ADR-023 surface/ink token NAMES with today's frontend-admin values as
 * fallbacks — see the header comment. When ADR-023 reaches this app the panel
 * follows the tenant's card surface automatically; until then it renders as
 * the white/slate card every other admin surface uses.
 */
.info-popover-trigger {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem; /* 28px visible box */
    height: 1.75rem;
    margin: -0.25rem 0; /* keeps the row height unchanged next to a 14px label */
    border-radius: 9999px;
    flex: none;
    color: rgb(var(--ink-card-muted, 100 116 139));
    transition: color 0.15s ease, background-color 0.15s ease;
}

/*
 * Expands the touch target to 44px without expanding the visual one. 28px is
 * a comfortable icon and an uncomfortable tap — and on the builder screen the
 * icons sit inches apart in dense two-column forms.
 */
.info-popover-trigger::after {
    content: '';
    position: absolute;
    inset: -0.5rem;
}

.info-popover-trigger:hover,
.info-popover-trigger.is-open {
    color: rgb(var(--ink-card, 15 23 42));
    background-color: rgb(var(--surface-chip, 241 245 249));
}

.info-popover-trigger:focus-visible {
    outline: 2px solid rgb(var(--surface-primary, 30 42 84));
    outline-offset: 2px;
}

.info-popover-panel {
    border-radius: 0.75rem;
    border: 1px solid rgb(var(--line-card, 226 232 240));
    background-color: rgb(var(--surface-card, 255 255 255));
    color: rgb(var(--ink-card, 15 23 42));
    box-shadow:
        0 10px 25px -5px rgb(15 23 42 / 0.18),
        0 8px 10px -6px rgb(15 23 42 / 0.12);
    padding: 0.75rem 0.875rem;
    font-size: 0.8125rem; /* 13px — hint text, floored at AA per ADR-023 §6a */
    line-height: 1.55;
    font-weight: 500;
    text-align: left;
    white-space: normal;
}
</style>
