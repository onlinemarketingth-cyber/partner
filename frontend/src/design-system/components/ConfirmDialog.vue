<script setup lang="ts">
/**
 * ConfirmDialog — Light Glassmorphism confirmation modal (bilingual)
 * Replace native window.confirm() ทั่วระบบ
 *
 * Usage:
 *   <ConfirmDialog v-model:show="showConfirm"
 *                  :title="..." :body="..."
 *                  variant="danger"
 *                  @confirm="onDelete" />
 */
import { computed } from 'vue'
import { useI18n, I18N } from '../../composables/useI18n'

const props = defineProps({
    show:    { type: Boolean, default: false },
    title:   { type: String,  default: '' },
    body:    { type: String,  default: '' },
    variant: { type: String,  default: 'danger' }, // danger | primary | warning
    busy:    { type: Boolean, default: false },
})

const emit = defineEmits(['update:show', 'confirm', 'cancel'])

const { t } = useI18n()

const titleText = computed(() => props.title || t('cdt', I18N.confirmDeleteTitle.th, I18N.confirmDeleteTitle.en))
const bodyText  = computed(() => props.body  || t('cdb', I18N.confirmDeleteBody.th,  I18N.confirmDeleteBody.en))

const variantClass = computed(() => ({
    danger:  'bg-rose-500 hover:bg-rose-600',
    primary: 'bg-[#3F6C92] hover:bg-[#325675]',
    warning: 'bg-amber-500 hover:bg-amber-600',
}[props.variant] || 'bg-rose-500 hover:bg-rose-600'))

// TASK-098 / ADR-023: the danger/warning icon halos were `bg-rose-50
// text-rose-500` / `bg-amber-50 text-amber-600` — a pale pill that stayed
// pale on a dark card and then inherited the light `--card-text`, i.e. the
// light-on-light half of the human's report (ADR-023 §2.2). They now use
// the semantic surface/ink pairs, which are tinted FROM the card, so the
// halo is always a sibling of the surface it sits on.
// The `primary` variant keeps the brand ramp: `bg-brand-*` is generated
// from primary_hex and is not ours to re-token here (CI-002 comment below).
const iconBgClass = computed(() => ({
    danger:  'bg-surface-danger text-ink-danger',
    primary: 'bg-brand-50 text-brand-600', // CI-002: navy brand accent
    warning: 'bg-surface-warning text-ink-warning',
}[props.variant] || 'bg-surface-danger text-ink-danger'))

const close = () => {
    emit('update:show', false)
    emit('cancel')
}

const confirm = () => emit('confirm')
</script>

<template>
    <!-- TASK-098 / ADR-023: colours here come from the surface/ink token
         layer (`bg-surface-card`, `text-ink-card*`, `bg-surface-chip`), not
         from hardcoded slate shades — a tenant's card background and the ink
         on it are derived together from WCAG contrast (theme/contrast.ts).
         The backdrop keeps `bg-slate-500/30`: a scrim is not a surface, it
         is a translucent veil over whatever is behind the dialog. -->
    <transition name="fade">
        <div v-if="show"
             class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-500/30 backdrop-blur-sm"
             @click.self="close">
            <div class="w-full max-w-sm rounded-2xl bg-surface-card/95 backdrop-blur-xl border border-line-card shadow-2xl p-6 text-center">
                <!-- Icon -->
                <div class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center" :class="iconBgClass">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>

                <h3 class="text-lg font-bold text-ink-card mb-2">{{ titleText }}</h3>
                <p class="text-sm text-ink-card-muted leading-relaxed mb-6">{{ bodyText }}</p>

                <div class="flex gap-3">
                    <!-- TASK-098 — ink-chip, not ink-card-muted: this button
                         HAS its own surface, and `--ink-chip` is the token
                         guaranteed AA against it. Hover dims rather than
                         stepping to another slate shade, which would be
                         near-white on a dark card. -->
                    <button @click="close"
                            :disabled="busy"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-ink-chip bg-surface-chip hover:opacity-80 transition disabled:opacity-50">
                        {{ t('cancel', I18N.cancel.th, I18N.cancel.en) }}
                    </button>
                    <button @click="confirm"
                            :disabled="busy"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow transition disabled:opacity-50"
                            :class="variantClass">
                        <span v-if="busy">{{ t('saving', I18N.saving.th, I18N.saving.en) }}</span>
                        <span v-else>{{ t('confirm', I18N.confirm.th, I18N.confirm.en) }}</span>
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
