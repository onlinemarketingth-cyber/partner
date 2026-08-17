/**
 * useCommissionRateCap — TASK-196 §3.1.
 *
 * One shared implementation of the platform-wide commission-rate cap check,
 * imported by all 3 forms that write to `/commission-rules` (ProductEditView's
 * "คอมมิชชั่น" tab — both the create form and the inline per-rule edit form,
 * CommissionPlansView's "กฎคอมมิชชั่น" tab, ProductCatalogView's "อัตรา
 * คอมมิชชั่น" tab) — per the task spec's "not 3 copies" instruction.
 *
 * Two responsibilities, split on purpose:
 *   1. Load-once/cache-for-session the cap value itself (`useCommissionRateCap()`),
 *      shared across every consumer via a MODULE-scope ref (not a per-call ref) —
 *      so 3 views opening this composable independently still only ever issue
 *      ONE `GET /platform/commission-cap`.
 *   2. A pure boundary-math function (`checkCommissionRateCap`) with NO Vue
 *      state, so it's trivially testable and so the "is this over the cap"
 *      question can be asked from anywhere (a computed, a watcher, a submit
 *      handler) without re-deriving the cross-multiplication.
 *
 * BR-7 — the 30% default is config, Super-Admin-editable via
 * PlatformCommissionSettingController. This file never hardcodes it; if the
 * cap hasn't loaded yet (or the GET failed), checkCommissionRateCap simply
 * reports "not over the cap" rather than guessing a number — the Form
 * Request's own server-side ValidatesCommissionRateCap trait is the real
 * backstop regardless of what this client-side guard does (spec's own
 * "UX improvement, not the only line of defense" framing).
 */
import { ref } from 'vue'
import { api } from '@/api/client'

export type CommissionRateType = 'percentage' | 'fixed_satang'

// Module-scope — shared by every component that calls useCommissionRateCap(),
// exactly once per page session (cleared only on a full reload). Mirrors the
// backend's own short-lived Cache::remember() precedent
// (PlatformCommissionSettingService::row()) on the client side.
const capBasisPoints = ref<number | null>(null)
let loadPromise: Promise<void> | null = null

async function loadCap(): Promise<void> {
  if (capBasisPoints.value !== null) return
  if (!loadPromise) {
    loadPromise = api
      .get<{ data: { max_commission_rate_basis_points: number } }>('/platform/commission-cap')
      .then((res) => {
        capBasisPoints.value = res.data.max_commission_rate_basis_points
      })
      .catch(() => {
        // Non-fatal — §2.2 says this endpoint is reachable by any
        // authenticated user, so a failure here is a transient network
        // blip, not an authorization gap. Leave capBasisPoints null (the
        // pure check function below treats null as "skip the client-side
        // guard") and allow a retry on the next call rather than caching
        // a permanent failure for the rest of the session.
        loadPromise = null
      })
  }
  return loadPromise
}

export interface CapCheckResult {
  exceedsCap: boolean
  /** Thai message ready to show verbatim in the blocking modal, or null when not over the cap (or the cap/price aren't known yet). */
  message: string | null
}

function formatCapPercentText(capBp: number): string {
  // Mirrors ValidatesCommissionRateCap.php's
  // rtrim(rtrim(number_format($capBasisPoints / 100, 2), '0'), '.') — strip
  // trailing zeros, then a now-trailing decimal point, so 3000bp -> "30" but
  // 3333bp -> "33.33".
  return (capBp / 100).toFixed(2).replace(/\.?0+$/, '')
}

function formatBaht(satangOrBasisAmount: number): string {
  return (satangOrBasisAmount / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/**
 * TASK-196 §3.1 — the ONE place the cap boundary math is expressed
 * client-side, mirroring backend/app/Http/Requests/Catalog/Concerns/
 * ValidatesCommissionRateCap.php EXACTLY (same cross-multiplication, not
 * division, so a rate implying precisely the cap is never pushed over it by
 * a rounding step):
 *   - percentage: rate_value (basis points) > capBasisPoints
 *   - fixed_satang: rate_value * 10000 > capBasisPoints * price_satang
 *
 * `rateValueBasisOrSatang` MUST already be in the same unit the API payload
 * sends — basis points for `percentage`, satang for `fixed_satang` — i.e.
 * the caller converts the raw THB/% input the admin typed (e.g. via each
 * view's own `rateValueToBasisOrSatang()` helper) BEFORE calling this, never
 * the raw display-unit input directly.
 */
export function checkCommissionRateCap(
  rateType: CommissionRateType,
  rateValueBasisOrSatang: number,
  priceSatang: number | null,
  capBp: number | null,
): CapCheckResult {
  if (capBp === null || priceSatang === null || !Number.isFinite(rateValueBasisOrSatang)) {
    return { exceedsCap: false, message: null }
  }

  const exceeds =
    rateType === 'percentage'
      ? rateValueBasisOrSatang > capBp
      : rateValueBasisOrSatang * 10000 > capBp * priceSatang

  if (!exceeds) return { exceedsCap: false, message: null }

  const enteredText = rateType === 'percentage' ? `${formatBaht(rateValueBasisOrSatang)}%` : `${formatBaht(rateValueBasisOrSatang)} บาท`

  return {
    exceedsCap: true,
    message: `อัตราคอมมิชชั่นที่กรอกไว้ (${enteredText}) เกิน ${formatCapPercentText(capBp)}% ของราคาขายสินค้านี้ (${formatBaht(priceSatang)} บาท) กรุณาแก้ไขก่อนบันทึก`,
  }
}

/**
 * Vue-facing entry point. Kicks off the (deduped) cap load on first call and
 * hands back the shared reactive cap value plus the pure checker above.
 */
export function useCommissionRateCap() {
  void loadCap()
  return { capBasisPoints, checkCommissionRateCap }
}

/**
 * TASK-196 §3.2/§3.3 — a small per-form guard built on top of the pure
 * checker: tracks whether the CURRENT in-progress value is over the cap
 * (for disabling Save), and fires the blocking-modal flag exactly once per
 * *crossing into* violation (not on every recheck while it stays over the
 * cap, not on every keystroke — callers debounce/blur into `recheck()`
 * themselves, this only decides whether a NEW crossing happened).
 *
 * Each of the 3 forms (in ProductEditView: two instances, one for the
 * create form and one for the inline per-rule edit form; one each in
 * CommissionPlansView and ProductCatalogView) creates its OWN guard —
 * only the underlying cap value/fetch is shared, per §3.1's "one shared
 * implementation" instruction (which is about not re-deriving the boundary
 * math 3 times, not about there being only one Save button on the page).
 */
export function useCommissionRateCapGuard() {
  const { capBasisPoints: cap } = useCommissionRateCap()

  const isOverCap = ref(false)
  const violationMessage = ref<string | null>(null)
  const modalOpen = ref(false)

  let debounceHandle: ReturnType<typeof setTimeout> | null = null

  function recheckNow(rateType: CommissionRateType, rateValueBasisOrSatang: number, priceSatang: number | null): void {
    const wasOverCap = isOverCap.value
    const result = checkCommissionRateCap(rateType, rateValueBasisOrSatang, priceSatang, cap.value)
    isOverCap.value = result.exceedsCap
    violationMessage.value = result.message
    // Fire once per crossing into violation — not on every recheck while
    // the value stays over the cap (the admin may still be editing other
    // fields, each of which can trigger a recheck via blur/debounce).
    if (result.exceedsCap && !wasOverCap) {
      modalOpen.value = true
    }
  }

  /** Call on blur, and on rate-type toggle change (§3.2 — immediate, no debounce needed for a discrete toggle). */
  function recheck(rateType: CommissionRateType, rateValueBasisOrSatang: number, priceSatang: number | null): void {
    if (debounceHandle) {
      clearTimeout(debounceHandle)
      debounceHandle = null
    }
    recheckNow(rateType, rateValueBasisOrSatang, priceSatang)
  }

  /** Call on the rate-value input's `input` event — debounced so the modal never fires mid-keystroke, before the admin has finished typing a number. */
  function recheckDebounced(rateType: CommissionRateType, rateValueBasisOrSatang: number, priceSatang: number | null, delayMs = 500): void {
    if (debounceHandle) clearTimeout(debounceHandle)
    debounceHandle = setTimeout(() => {
      debounceHandle = null
      recheckNow(rateType, rateValueBasisOrSatang, priceSatang)
    }, delayMs)
  }

  function closeModal(): void {
    modalOpen.value = false
  }

  /** Reset when a form is closed/reset (create-another, cancel edit, etc.) so a stale violation doesn't linger into the next open. */
  function reset(): void {
    if (debounceHandle) {
      clearTimeout(debounceHandle)
      debounceHandle = null
    }
    isOverCap.value = false
    violationMessage.value = null
    modalOpen.value = false
  }

  return { isOverCap, violationMessage, modalOpen, recheck, recheckDebounced, closeModal, reset }
}
