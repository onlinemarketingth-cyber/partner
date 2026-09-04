<script setup lang="ts">
/**
 * ConfirmDialog — Light Glassmorphism confirmation modal (bilingual)
 * Replace native window.confirm() ทั่วระบบ
 *
 * Ported verbatim from frontend/src/design-system/components/ConfirmDialog.vue
 * (TASK-066, human-reported 2026-07-31 — the native browser confirm() popup
 * on the "grant cert without exam" action looked like an unstyled OS dialog,
 * not part of the app). Per CLAUDE.md §7 / ADR-003, design-system components
 * needed by both apps are duplicated rather than shared via a package yet —
 * keep both copies in sync (CI-001/CI-002).
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
    /*
     * 2026-09-04 — optional button wording.
     *
     * Empty keeps today's ยืนยัน / ยกเลิก everywhere it already reads
     * correctly. A dialog whose two answers are not "yes/no" needs its own
     * words: "เปลี่ยนบริษัท / แก้ไขต่อ" tells the human what each button
     * DOES, where "ยืนยัน" would leave them guessing which thing they are
     * confirming — the change, or the staying.
     */
    confirmLabel: { type: String, default: '' },
    cancelLabel:  { type: String, default: '' },
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

const iconBgClass = computed(() => ({
    danger:  'bg-rose-50 text-rose-500',
    primary: 'bg-brand-50 text-brand-600', // CI-002: navy brand accent
    warning: 'bg-amber-50 text-amber-600',
}[props.variant] || 'bg-rose-50 text-rose-500'))

const close = () => {
    emit('update:show', false)
    emit('cancel')
}

const confirm = () => emit('confirm')
</script>

<template>
    <transition name="fade">
        <div v-if="show"
             class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-500/30 backdrop-blur-sm"
             @click.self="close">
            <div class="w-full max-w-sm rounded-2xl bg-white/95 backdrop-blur-xl border border-white/60 shadow-2xl ring-1 ring-slate-100 p-6 text-center">
                <!-- Icon -->
                <div class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center" :class="iconBgClass">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>

                <h3 class="text-lg font-bold text-slate-800 mb-2">{{ titleText }}</h3>
                <p class="text-sm text-slate-500 leading-relaxed mb-6">{{ bodyText }}</p>

                <div class="flex gap-3">
                    <button @click="close"
                            :disabled="busy"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition disabled:opacity-50">
                        {{ cancelLabel || t('cancel', I18N.cancel.th, I18N.cancel.en) }}
                    </button>
                    <button @click="confirm"
                            :disabled="busy"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow transition disabled:opacity-50"
                            :class="variantClass">
                        <span v-if="busy">{{ t('saving', I18N.saving.th, I18N.saving.en) }}</span>
                        <span v-else>{{ confirmLabel || t('confirm', I18N.confirm.th, I18N.confirm.en) }}</span>
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
