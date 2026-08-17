<script setup lang="ts">
/**
 * ToastHost — TASK-079 Phase 2 (2026-08-03, UX audit).
 *
 * Renders the shared toast queue (stores/toast.ts). Mounted ONCE in
 * App.vue; no view should ever mount its own.
 *
 * Placement follows the standard mobile toast convention: pinned to the
 * bottom, inside thumb reach, above the BottomNav (bottom-20 clears the
 * h-16 bar + safe area) so it never covers the tabs the user might be
 * reaching for. Teleported to <body> so no view's overflow/stacking
 * context can clip it.
 *
 * pointer-events-none on the container, auto on each toast: the toast
 * must never block a tap on the content behind it — only its own dismiss
 * area is interactive.
 */
import Icon from './Icon.vue'
import { useToastStore, type ToastVariant } from '@/stores/toast'

const toastStore = useToastStore()

const styles: Record<ToastVariant, { bg: string; icon: string }> = {
  success: { bg: 'bg-emerald-600', icon: 'check' },
  error: { bg: 'bg-rose-600', icon: 'alert' },
  info: { bg: 'bg-slate-800', icon: 'info' },
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-20 inset-x-0 z-[80] px-4 pointer-events-none flex flex-col items-center gap-2">
      <TransitionGroup name="toast">
        <div
          v-for="t in toastStore.toasts"
          :key="t.id"
          class="w-full max-w-sm pointer-events-auto flex items-start gap-2.5 px-4 py-3 rounded-xl shadow-lg text-white cursor-pointer active:scale-[0.98] transition-transform"
          :class="styles[t.variant].bg"
          role="status"
          @click="toastStore.dismiss(t.id)"
        >
          <Icon :name="styles[t.variant].icon" :size="18" class="shrink-0 mt-0.5" />
          <p class="text-sm font-bold leading-snug flex-1">{{ t.message }}</p>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
.toast-move {
  transition: transform 0.2s ease;
}
@media (prefers-reduced-motion: reduce) {
  .toast-enter-active,
  .toast-leave-active,
  .toast-move {
    transition-duration: 0.01ms !important;
  }
}
</style>
