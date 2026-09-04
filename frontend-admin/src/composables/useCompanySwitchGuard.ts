/**
 * useCompanySwitchGuard — what a RECORD page does when the company changes.
 *
 * ── THE PROBLEM (human-reported 2026-09-04) ──
 *
 * Most Admin screens are lists, and a list simply reloads for the newly
 * picked company. Two screens are not lists: the product editor and a
 * client's file. Both are ABOUT one record that belongs to one company, so
 * switching underneath them used to leave the header naming one company
 * while the form on screen still belonged to another — and any half-typed
 * edit was lost or, worse, saved to the wrong place.
 *
 * ── THE RULE THE HUMAN CHOSE ──
 *
 * Switching wins, but ask first — and only when there is something to lose:
 *
 *   unsaved work  → "เปลี่ยนบริษัท" (leave, discard) / "แก้ไขต่อ" (stay)
 *   nothing typed → leave immediately, no dialog
 *
 * Either way the page does NOT sit on the old company's record afterwards:
 * it goes to `leaveTo`, the list this record came from, which then loads for
 * the company now selected. Staying put would recreate the exact mismatch
 * this exists to prevent.
 *
 * ── WHY THE GUARD RUNS BEFORE THE SWITCH ──
 *
 * "แก้ไขต่อ" has to leave the picker showing the company the page belongs
 * to. Undoing a write afterwards would flicker, and would lose the race with
 * every screen watching `companyId`. So the store asks this guard first and
 * writes nothing when it refuses (see stores/activeCompany.ts).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useActiveCompanyStore } from '@/stores/activeCompany'

export function useCompanySwitchGuard(options: {
  /** True while the page holds work that leaving would throw away. */
  isDirty: () => boolean
  /** Where this record's list lives. */
  leaveTo: RouteLocationRaw
}) {
  const store = useActiveCompanyStore()
  const router = useRouter()

  /** Drives the confirm dialog in the view's template. */
  const asking = ref(false)

  // The pending decision, resolved by the two buttons. Null whenever no
  // dialog is open, so a stray click on a closed dialog cannot resolve a
  // promise nobody is waiting for.
  let decide: ((leave: boolean) => void) | null = null

  async function guard(): Promise<boolean> {
    if (!options.isDirty()) {
      void router.push(options.leaveTo)

      return true
    }

    asking.value = true
    const leaving = await new Promise<boolean>((resolve) => {
      decide = resolve
    })
    asking.value = false
    decide = null

    if (leaving) void router.push(options.leaveTo)

    return leaving
  }

  /** "เปลี่ยนบริษัท" — throw the edits away and follow the switch. */
  function confirmLeave(): void {
    decide?.(true)
  }

  /** "แก้ไขต่อ" — refuse the switch; the picker snaps back on its own. */
  function stay(): void {
    decide?.(false)
  }

  onMounted(() => store.guardSwitch(guard))
  onBeforeUnmount(() => {
    // Resolve anything still waiting, or the store keeps a promise that can
    // never settle — every later switch would hang on it in silence.
    decide?.(true)
    store.releaseSwitch(guard)
  })

  return { asking, confirmLeave, stay }
}
