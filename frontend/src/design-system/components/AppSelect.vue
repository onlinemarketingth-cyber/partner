<script setup lang="ts" generic="T extends string | number | null">
/**
 * AppSelect — TASK-173 (2026-08-12, human: "แก้ selectbox ทั้งระบบ frontend
 * ให้แสดงผลแบบ ui กำหนดเอง ไม่ใช้แบบ standard ทำให้เล็กมองไม่เห็น").
 *
 * A themed replacement for the native `<select>`.
 *
 * WHY IT EXISTS
 * The `<select>` ELEMENT was already themed — `bg-surface-input`,
 * `text-ink-input`, `border-line-input`, all tenant colours (ADR-023). The
 * LIST it opens is not part of the page: it is drawn by the operating
 * system and no browser exposes it to CSS. So under a dark tenant theme the
 * field is dark and the list that opens over it is a small white OS box at
 * default text size — the single place ADR-018's white-labelling stops
 * working, and the thing the human photographed. It cannot be closed by
 * styling; the list has to be markup we own. That is the whole point of
 * this component, so everything below exists to make OUR list at least as
 * good as the one it replaces.
 *
 * REUSE, NOT A SECOND SHEET
 * The overlay is `FilterSheet` (TASK-085), which already solved the phone
 * case for a list that could not be read. It was given a default slot and
 * an `anchored` placement (see its docblock) rather than being copied, so
 * the scrim, the slide transition, the body-scroll lock, the safe-area
 * padding, the max-height scroller and the ADR-023 tokens exist once. What
 * lives HERE is only what a select has and a filter sheet does not: the
 * trigger, the value, and the keyboard.
 *
 * ACCESSIBILITY IS THE ACCEPTANCE CRITERION, NOT POLISH
 * A custom select a keyboard cannot drive is a DOWNGRADE on the native
 * element. Implemented against the ARIA APG combobox/listbox pattern:
 *   - `role="combobox"` trigger with `aria-expanded` / `aria-controls` /
 *     `aria-haspopup="listbox"`; it is a <button>, which is a labelable
 *     element, so a call site's `<label :for>` still names it.
 *   - `role="listbox"` + `role="option"` + `aria-selected`, and
 *     `aria-activedescendant` rather than moving DOM focus per option (the
 *     APG's own recommendation for a listbox whose options are not links).
 *   - Arrow/Home/End move, Enter/Space select, Esc closes WITHOUT changing
 *     the value, Tab closes; focus returns to the trigger every time it
 *     closes, however it closed.
 *   - Type-ahead, including the APG's "same character repeated cycles
 *     through matches" rule — without it a 77-province list is a scroll.
 *   - The trigger keeps a 44px minimum tap target (TASK-087).
 *
 * VALUE SEMANTICS ARE THE NATIVE ONES ON PURPOSE
 * `T` is generic so `v-model` onto a `string` field stays a `string` — the
 * conversions this component exists for are presentation-only and must not
 * change a single submitted payload. Selecting emits exactly the `value`
 * that was put on the option, and Esc/backdrop emit nothing at all.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import FilterSheet, { type SheetAnchor } from './FilterSheet.vue'
import Icon from './Icon.vue'
import { useI18n } from '@/composables/useI18n'

export interface AppSelectOption<V> {
    value: V
    label: string
    disabled?: boolean
}

const { td } = useI18n()
const props = withDefaults(
    defineProps<{
        modelValue: T
        options: AppSelectOption<T>[]
        /**
         * Shown on the trigger when `modelValue` matches no option — the
         * equivalent of a native `<option value="" disabled>` prompt. An
         * empty-string option that the user is allowed to pick back is a
         * normal entry in `options`, not this.
         */
        placeholder?: string
        disabled?: boolean
        /** Bottom-sheet header. Defaults to `placeholder`. */
        title?: string
        /** Only needed where no <label for> names the trigger. */
        ariaLabel?: string
    }>(),
    {
        placeholder: '',
        disabled: false,
        title: '',
    },
)

const emit = defineEmits<{
    'update:modelValue': [value: T]
}>()

// Two roots (the trigger, and FilterSheet's <Teleport>), so class/id from
// the call site must be routed explicitly — they belong on the trigger,
// which is what stands where the old <select> stood.
defineOptions({ inheritAttrs: false })

const uid = useId()
const listboxId = `app-select-list-${uid}`
const optionId = (index: number) => `app-select-opt-${uid}-${index}`

const triggerEl = ref<HTMLButtonElement | null>(null)
const listboxEl = ref<HTMLUListElement | null>(null)

const open = ref(false)
/** Roving highlight. -1 = nothing highlighted (empty option list). */
const activeIndex = ref(-1)
const anchor = ref<SheetAnchor | null>(null)
/**
 * Sheet on a phone, popover under the trigger on anything wider. Sampled
 * once per open rather than watched: the body scroll lock means the layout
 * behind cannot move while the list is up, so there is nothing to react to.
 */
const isWide = ref(false)

const sheetTitle = computed(() => props.title || props.placeholder || td('ui.select_placeholder'))
const selectedIndex = computed(() => props.options.findIndex((o) => o.value === props.modelValue))
const selectedOption = computed(() =>
    selectedIndex.value >= 0 ? props.options[selectedIndex.value] : undefined,
)
const placement = computed<'sheet' | 'anchored'>(() => (isWide.value ? 'anchored' : 'sheet'))

function isWideViewport(): boolean {
    // jsdom has no matchMedia; falling back to the sheet keeps the
    // component mountable in tests instead of throwing on open.
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
    return window.matchMedia('(min-width: 640px)').matches
}

function firstEnabledIndex(): number {
    return props.options.findIndex((o) => !o.disabled)
}

function scrollActiveIntoView() {
    if (activeIndex.value < 0) return
    // The <li> rows are the listbox's only children, so index IS position —
    // no selector escaping needed for ids built from a useId() suffix.
    const el = listboxEl.value?.children[activeIndex.value] as HTMLElement | undefined
    // jsdom does not implement scrollIntoView; the guard keeps the keyboard
    // path testable rather than throwing on the first arrow press.
    if (el && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' })
}

async function openList() {
    if (props.disabled || open.value) return
    const rect = triggerEl.value?.getBoundingClientRect()
    anchor.value = rect
        ? { top: rect.top, bottom: rect.bottom, left: rect.left, width: rect.width }
        : null
    isWide.value = isWideViewport()
    activeIndex.value = selectedIndex.value >= 0 ? selectedIndex.value : firstEnabledIndex()
    open.value = true
    await nextTick()
    listboxEl.value?.focus()
    scrollActiveIntoView()
}

/**
 * Every close path lands here, so "focus returns to the trigger" cannot be
 * true for the keyboard and false for the backdrop.
 */
async function closeList() {
    if (!open.value) return
    open.value = false
    resetTypeAhead()
    await nextTick()
    triggerEl.value?.focus()
}

function choose(index: number) {
    const opt = props.options[index]
    if (!opt || opt.disabled) return
    emit('update:modelValue', opt.value)
    void closeList()
}

function moveActive(delta: number) {
    const n = props.options.length
    if (n === 0) return
    let i = activeIndex.value
    for (let step = 0; step < n; step++) {
        i += delta
        if (i < 0 || i >= n) return // clamp at the ends, like a native list
        if (!props.options[i]?.disabled) {
            activeIndex.value = i
            scrollActiveIntoView()
            return
        }
    }
}

function moveToEdge(edge: 'first' | 'last') {
    const n = props.options.length
    const range = edge === 'first' ? [...props.options.keys()] : [...props.options.keys()].reverse()
    for (const i of range) {
        if (!props.options[i]?.disabled) {
            activeIndex.value = i
            scrollActiveIntoView()
            return
        }
    }
    if (n === 0) activeIndex.value = -1
}

// ── Type-ahead ────────────────────────────────────────────────────────
// Without this the only way to reach province 77 of 77 is to scroll, which
// is exactly the complaint about the native list at a different size.
const TYPE_AHEAD_RESET_MS = 700
let typeAheadBuffer = ''
let typeAheadTimer: ReturnType<typeof setTimeout> | undefined

function resetTypeAhead() {
    typeAheadBuffer = ''
    if (typeAheadTimer) clearTimeout(typeAheadTimer)
    typeAheadTimer = undefined
}

function isPrintable(e: KeyboardEvent): boolean {
    return e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey
}

function typeAhead(char: string) {
    if (typeAheadTimer) clearTimeout(typeAheadTimer)
    typeAheadTimer = setTimeout(resetTypeAhead, TYPE_AHEAD_RESET_MS)
    typeAheadBuffer += char.toLocaleLowerCase()

    // APG: one character repeated cycles through the options starting with
    // it; anything else is a prefix that refines the CURRENT match, so the
    // search starts on it rather than after it.
    const allSame = [...typeAheadBuffer].every((c) => c === typeAheadBuffer[0])
    const query = allSame ? typeAheadBuffer[0]! : typeAheadBuffer
    const startAt = allSame ? activeIndex.value + 1 : activeIndex.value

    const n = props.options.length
    if (n === 0) return
    for (let step = 0; step < n; step++) {
        const i = ((((startAt + step) % n) + n) % n)
        const opt = props.options[i]
        if (!opt || opt.disabled) continue
        if (opt.label.toLocaleLowerCase().startsWith(query)) {
            activeIndex.value = i
            scrollActiveIntoView()
            return
        }
    }
}

function onTriggerKeydown(e: KeyboardEvent) {
    if (props.disabled) return
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        void openList()
        return
    }
    if (isPrintable(e)) {
        e.preventDefault()
        void openList().then(() => typeAhead(e.key))
    }
}

function onListKeydown(e: KeyboardEvent) {
    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault()
            moveActive(1)
            return
        case 'ArrowUp':
            e.preventDefault()
            moveActive(-1)
            return
        case 'Home':
            e.preventDefault()
            moveToEdge('first')
            return
        case 'End':
            e.preventDefault()
            moveToEdge('last')
            return
        case 'Enter':
        case ' ':
            e.preventDefault()
            choose(activeIndex.value)
            return
        case 'Escape':
            e.preventDefault()
            // The drawers and modals this control sits inside close on Esc
            // too; without this, one press would dismiss both.
            e.stopPropagation()
            void closeList()
            return
        case 'Tab':
            void closeList()
            return
        default:
            if (isPrintable(e)) {
                e.preventDefault()
                typeAhead(e.key)
            }
    }
}

function onTriggerClick() {
    if (open.value) void closeList()
    else void openList()
}

function onOptionHover(index: number) {
    if (!props.options[index]?.disabled) activeIndex.value = index
}

function onSheetOpenChange(next: boolean) {
    // FilterSheet only ever emits `false` (backdrop / its own close button);
    // either way the value is untouched and focus goes back to the trigger.
    if (!next) void closeList()
}

onBeforeUnmount(() => {
    if (typeAheadTimer) clearTimeout(typeAheadTimer)
})
</script>

<template>
    <button
        ref="triggerEl"
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        :aria-expanded="open"
        :aria-controls="listboxId"
        :aria-label="ariaLabel"
        :disabled="disabled"
        v-bind="$attrs"
        class="bg-surface-input text-ink-input border-line-input flex min-h-[44px] w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50"
        @click="onTriggerClick"
        @keydown="onTriggerKeydown"
    >
        <span
            class="min-w-0 flex-1 truncate"
            :class="selectedOption ? 'text-ink-input' : 'text-ink-input-placeholder'"
        >{{ selectedOption?.label ?? (placeholder || td('ui.select_placeholder')) }}</span>
        <Icon name="chevron_down" :size="16" class="text-ink-input-placeholder shrink-0" />
    </button>

    <FilterSheet
        :open="open"
        :title="sheetTitle"
        :placement="placement"
        :anchor="anchor"
        @update:open="onSheetOpenChange"
    >
        <ul
            :id="listboxId"
            ref="listboxEl"
            role="listbox"
            tabindex="-1"
            :aria-label="ariaLabel ?? sheetTitle"
            :aria-activedescendant="activeIndex >= 0 ? optionId(activeIndex) : undefined"
            class="outline-none"
            @keydown="onListKeydown"
        >
            <li
                v-for="(opt, i) in options"
                :id="optionId(i)"
                :key="`${i}-${String(opt.value)}`"
                role="option"
                :aria-selected="i === selectedIndex"
                :aria-disabled="opt.disabled || undefined"
                class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl px-3 transition-colors"
                :class="[
                    i === activeIndex ? 'bg-surface-chip' : '',
                    opt.disabled ? 'cursor-not-allowed opacity-50' : 'active:bg-surface-chip',
                ]"
                @click="choose(i)"
                @mousemove="onOptionHover(i)"
            >
                <span
                    class="min-w-0 flex-1 truncate text-sm font-bold"
                    :class="i === selectedIndex ? 'text-ink-brand' : 'text-ink-card'"
                >{{ opt.label }}</span>
                <Icon
                    v-if="i === selectedIndex"
                    name="check"
                    :size="18"
                    class="text-ink-brand shrink-0"
                />
            </li>
        </ul>
    </FilterSheet>
</template>
