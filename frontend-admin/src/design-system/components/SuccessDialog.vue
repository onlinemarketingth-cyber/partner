<script setup lang="ts">
/**
 * SuccessDialog — the "it worked" counterpart to ConfirmDialog.
 *
 * Human, 2026-08-19 (TASK-210): "กดบันทึก หากบันทึกสำเร็จให้ขึ้นปิดหน้าจอ
 * modal นี้ และขึ้น modal ใหม่ว่าบันทึกสำเร็จ".
 *
 * Why this exists at all: <AgentEditModal> already closed itself on a
 * successful save, which from the admin's side is indistinguishable from the
 * modal being dismissed — the one moment they most need told that the write
 * landed is the one moment the screen goes quiet. The inline
 * `editSavedMessage` line could not fill the gap: it lives INSIDE the modal
 * that just disappeared.
 *
 * Deliberately its own component rather than a `variant="success"` on
 * ConfirmDialog: this dialog asks nothing. It has one button, no cancel, and
 * no `confirm` event — folding it into ConfirmDialog would mean a two-button
 * component whose second button is sometimes a lie.
 *
 * Usage:
 *   <SuccessDialog v-model:show="showSaved" :body="savedMessage" />
 */
defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'บันทึกสำเร็จ' },
  body: { type: String, default: '' },
})

const emit = defineEmits(['update:show', 'close'])

const close = (): void => {
  emit('update:show', false)
  emit('close')
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="show"
      class="fixed inset-0 z-[1100] flex items-center justify-center p-4 bg-slate-500/30 backdrop-blur-sm"
      @click.self="close"
    >
      <!-- z-[1100]: one layer above ConfirmDialog, because a success dialog is
           always raised BY an action a confirm dialog may still be unwinding. -->
      <div
        class="w-full max-w-sm rounded-2xl bg-white/95 backdrop-blur-xl border border-white/60 shadow-2xl ring-1 ring-slate-100 p-6 text-center"
        role="alertdialog"
        aria-live="polite"
      >
        <div class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center bg-emerald-50 text-emerald-600">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>

        <h3 class="text-lg font-bold text-slate-800 mb-2">{{ title }}</h3>
        <p v-if="body" class="text-sm text-slate-500 leading-relaxed mb-6">{{ body }}</p>
        <div v-else class="mb-6" />

        <button
          type="button"
          autofocus
          class="w-full px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow transition bg-emerald-500 hover:bg-emerald-600"
          @click="close"
        >
          ตกลง
        </button>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
