<script setup lang="ts">
/**
 * EmptyState — compact inline empty-state row.
 *
 * Follows the Apple HIG workspace pattern (medical-saas CLAUDE.md §6.3,
 * the reference this design system was ported from): horizontal ~60px
 * layout — small icon + message + inline CTA — never a tall centered
 * placeholder with a big icon.
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
  <div class="mt-4 flex items-center gap-4 py-6 px-5 rounded-xl bg-white/95 border border-dashed border-slate-200">
    <Icon :name="icon" :size="24" class="text-slate-300 shrink-0" />
    <div class="flex-1 min-w-0">
      <p class="text-sm text-slate-600 font-bold">{{ title }}</p>
      <p v-if="message" class="text-xs text-slate-400 mt-0.5">{{ message }}</p>
    </div>
    <button
      v-if="ctaLabel"
      type="button"
      :disabled="ctaDisabled"
      :title="ctaTooltip"
      class="shrink-0 px-3 py-1.5 rounded-lg text-white text-xs font-bold transition-colors"
      :class="ctaDisabled ? 'bg-slate-300 cursor-not-allowed' : 'bg-brand-600 hover:bg-brand-700'"
      @click="$emit('cta')"
    >
      {{ ctaLabel }}
    </button>
  </div>
</template>
