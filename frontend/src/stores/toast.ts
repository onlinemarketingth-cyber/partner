import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * TASK-079 Phase 2 (2026-08-03, UX audit) — app-wide transient feedback.
 *
 * The audit found the Agent Portal had NO toast/snackbar of any kind:
 * creating a client, advancing a pipeline stage, or submitting a referral
 * all just closed the panel and silently refetched. An agent had no way
 * to tell a success from a no-op, which on a phone in the field is the
 * difference between moving on and re-submitting the same lead twice.
 *
 * Deliberately a Pinia store rather than a composable-with-module-state:
 * the app already runs Pinia app-wide, and a store keeps the single
 * shared queue obvious + devtools-inspectable rather than hidden in
 * module scope.
 *
 * Placement/duration follow the common mobile toast convention (bottom of
 * the screen within thumb reach, auto-dismiss, never blocking) — see
 * ToastHost.vue for the render side.
 */

export type ToastVariant = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  message: string
  variant: ToastVariant
}

/** Errors linger longer — the user may need to read and act on them. */
const DURATION_MS: Record<ToastVariant, number> = {
  success: 2600,
  info: 3200,
  error: 5000,
}

let nextId = 1

export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  function push(message: string, variant: ToastVariant = 'info'): number {
    const id = nextId++
    toasts.value.push({ id, message, variant })
    // Cap the stack — a burst of failures must never bury the screen.
    if (toasts.value.length > 3) toasts.value.shift()
    window.setTimeout(() => dismiss(id), DURATION_MS[variant])
    return id
  }

  const success = (message: string) => push(message, 'success')
  const error = (message: string) => push(message, 'error')
  const info = (message: string) => push(message, 'info')

  return { toasts, push, success, error, info, dismiss }
})
