<script setup lang="ts">
/**
 * EmptyState — compact inline empty-state row.
 *
 * Follows the Apple HIG workspace pattern (medical-saas CLAUDE.md §6.3,
 * the reference this design system was ported from): horizontal ~60px
 * layout — small icon + message + inline CTA — never a tall centered
 * placeholder with a big icon.
 *
 * Tap target: the CTA is min-h-[44px] (Apple HIG minimum) with the label
 * centred by inline-flex — TASK-079 Phase 3 raised it from a ~28px box.
 * The font size is deliberately unchanged; it was the HIT AREA that was
 * failing, not the legibility.
 *
 * The CTA defaults to disabled: most workspace pages using this atom
 * don't have a real create-flow/API wired up yet (see each view's own
 * "TODO: CONFIRM" comment for what's blocking it) — never wire a button
 * to a dead end. Set `cta-disabled="false"` once the real action exists.
 */
import Icon from './Icon.vue'

withDefaults(
  defineProps<{
    icon: string
    title: string
    message?: string
    ctaLabel?: string
    ctaDisabled?: boolean
    ctaTooltip?: string
  }>(),
  { message: '', ctaLabel: '', ctaDisabled: true, ctaTooltip: '' },
)

defineEmits<{ cta: [] }>()
</script>

<template>
  <!-- TASK-098 / ADR-023: colours come from the surface/ink token layer
       (`bg-surface-card`, `text-ink-card*`, `border-line-card`) rather than
       hardcoded slate shades, so the row stays readable on a tenant whose
       card background is dark. -->
  <div class="mt-4 flex items-center gap-4 py-6 px-5 rounded-xl bg-surface-card/95 border border-dashed border-line-card">
    <Icon :name="icon" :size="24" class="text-ink-card-subtle shrink-0" />
    <div class="flex-1 min-w-0">
      <p class="text-sm text-ink-card-muted font-bold">{{ title }}</p>
      <p v-if="message" class="text-xs text-ink-card-subtle mt-0.5">{{ message }}</p>
      <!-- TASK-079 Phase 3 (UX audit): ctaTooltip used to be a `title=`
           attribute on the CTA. A native tooltip needs a hover, and a
           touchscreen never produces one — so on the phone this portal is
           actually used on, the reason the CTA is dead was invisible.
           Rendered as visible helper text instead, and only next to the
           disabled state it exists to explain. -->
      <p v-if="ctaTooltip && ctaDisabled" class="text-xs text-ink-warning mt-0.5">{{ ctaTooltip }}</p>
    </div>
    <!-- TASK-098 / ADR-023: the label was `text-white`, which broke the
         moment a tenant picked a pale Primary (the brand ramp IS generated
         from primary_hex). `text-ink-primary` is derived from that same
         primary by WCAG contrast, so it flips to dark ink when it has to.
         The disabled `bg-slate-300` branch is left hardcoded on purpose:
         "unavailable" should read as inert grey, not as a themed surface. -->
    <button
      v-if="ctaLabel"
      type="button"
      :disabled="ctaDisabled"
      class="shrink-0 min-h-[44px] px-4 rounded-lg text-ink-primary text-xs font-bold inline-flex items-center justify-center transition-all active:scale-95"
      :class="ctaDisabled ? 'bg-slate-300 cursor-not-allowed' : 'bg-brand-600 hover:bg-brand-700'"
      @click="$emit('cta')"
    >
      {{ ctaLabel }}
    </button>
  </div>
</template>
