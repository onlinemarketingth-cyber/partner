<script setup lang="ts">
/**
 * NavBarAction — TASK-087 (2026-08-03, human: "ปุ่มเพิ่มต่างๆ มาตรฐาน
 * apple ทำอย่างไร ตอนนี้มันใหญ่ไปมาก").
 *
 * The button a screen puts in the app's navigation bar (HeroHeader's
 * `#actions` slot, which ADR-021 teleports into the top bar on mobile).
 *
 * WHY NOT AppButton
 * AppButton is the app's PRIMARY-action style: filled brand capsule,
 * 44px tall, ~128px wide with a Thai label. That is correct inside page
 * content and wrong in a navigation bar. Apple's HIG reserves filled,
 * prominent buttons for content areas; a navigation bar carries a bare
 * SF Symbol or a bare tinted word — Notes, Mail and Reminders all put a
 * plain "+" glyph in the top-right, never a filled pill. In a 57px bar
 * sitting next to a title, a 128×44 red capsule reads as the loudest
 * thing on the screen, which is exactly what the human saw.
 *
 * WHAT STAYS THE SAME
 * The 44×44pt minimum tap target. Apple's bar buttons look small because
 * the PADDING is transparent, not because the target is small — so this
 * component keeps a full 44px box and only shrinks what is painted.
 * Shrinking the target instead would trade one HIG violation for a worse
 * one.
 *
 * TWO FORMS, matching the two Apple uses:
 *   - `icon` set  → icon-only (e.g. plus). `label` is then REQUIRED and
 *     becomes the accessible name + tooltip; an unlabelled glyph is
 *     unusable with VoiceOver and unguessable for a new agent.
 *   - no `icon`   → tinted text button ("อ่านทั้งหมด", the equivalent of
 *     iOS "Done"/"Edit").
 *
 * Colour goes through the brand CSS vars so per-company theming
 * (TASK-055 / ADR-018) keeps applying — never a hardcoded hex.
 */
import Icon from './Icon.vue'

withDefaults(
    defineProps<{
        /** Icon name from Icon.vue. Omit for a text button. */
        icon?: string | null
        /** Accessible name. Required when `icon` is set. */
        label?: string | null
        disabled?: boolean
        /** Tooltip override — used for the BR-1 cert-gate explanation. */
        title?: string | null
    }>(),
    { icon: null, label: null, disabled: false, title: null },
)

defineEmits<{ click: [event: MouseEvent] }>()
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :aria-label="label || undefined"
        :title="title || label || undefined"
        class="shrink-0 inline-flex items-center justify-center min-h-[44px] rounded-full font-bold transition-colors active:opacity-60 disabled:opacity-40 disabled:cursor-not-allowed"
        :class="icon ? 'w-11 text-ink-brand' : 'px-2 min-w-[44px] text-sm text-ink-brand'"
        @click="$emit('click', $event)"
    >
        <Icon v-if="icon" :name="icon" :size="24" />
        <slot v-else />
    </button>
</template>
