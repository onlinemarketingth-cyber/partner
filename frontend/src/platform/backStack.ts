/**
 * MOB-25 (2026-10-02) — which overlay is on top, so Android's back button
 * can close it.
 *
 * ── WHY A REGISTRY ──
 *
 * The portal's modals, drawers and sheets are plain `v-if` blocks owned by
 * whichever component opened them (ShareLinkModal, ConfirmDialog, the client
 * drawer ...). Nothing knew which of them was open, let alone which was on
 * top. Without that, the hardware back button can only navigate — so with a
 * confirm dialog open over a drawer, "back" would leave the whole page and
 * take both with it. On Android that is the first thing a person presses to
 * dismiss a dialog.
 *
 * Each overlay registers itself while it is open (useCloseOnBack); the back
 * button closes the most recently opened one. Last opened = on top, which is
 * true of every overlay in this portal: they stack in the order they open.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * Nothing here touches the DOM or any plugin. In a browser nothing ever
 * calls closeTopmost(), so registering is a few array pushes that have no
 * effect — the modals behave exactly as before.
 */
import { getCurrentInstance, onBeforeUnmount, watch } from 'vue'

interface Entry {
  close: () => void
}

const stack: Entry[] = []

/** Register an open overlay. Returns the function that unregisters it. */
export function pushBackHandler(close: () => void): () => void {
  const entry: Entry = { close }
  stack.push(entry)

  return () => {
    const index = stack.lastIndexOf(entry)
    if (index !== -1) stack.splice(index, 1)
  }
}

/**
 * Close the overlay on top, if there is one. True when something was closed
 * — the back button then stops there instead of also navigating.
 */
export function closeTopmost(): boolean {
  const top = stack.pop()
  if (!top) return false

  top.close()

  return true
}

/** How many overlays are open. For tests and diagnostics. */
export function openOverlayCount(): number {
  return stack.length
}

/** Test helper — forget every registration. */
export function resetBackStack(): void {
  stack.length = 0
}

/**
 * Register this component's overlay with the back button while `isOpen()` is
 * true. `close` must do what the overlay's own close button does (emit
 * `update:show` false, clear the selected row ...), so the back button and
 * the ✕ can never disagree about what "closed" means.
 *
 * Unregisters on close and on unmount — a view that is navigated away from
 * with its drawer open must not leave a dead entry on top of the stack.
 */
export function useCloseOnBack(isOpen: () => boolean, close: () => void): void {
  let remove: (() => void) | null = null

  const register = () => {
    remove = pushBackHandler(() => {
      // closeTopmost() already took this entry off the stack.
      remove = null
      close()
      // Some closes refuse while busy (a sheet mid-save), and an overlay
      // driven by `update:show` only closes when its parent re-renders. Stay
      // registered until it really is closed — the watcher below removes the
      // entry the moment it is.
      if (isOpen()) register()
    })
  }

  watch(
    () => Boolean(isOpen()),
    (open) => {
      if (open && !remove) {
        register()
      } else if (!open && remove) {
        remove()
        remove = null
      }
    },
    { immediate: true },
  )

  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      remove?.()
      remove = null
    })
  }
}
