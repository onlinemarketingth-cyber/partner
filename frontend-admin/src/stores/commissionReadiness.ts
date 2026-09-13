import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'
import { readStored, writeStored } from '@/utils/safeStorage'

/**
 * commissionReadiness — 2026-09-11 (owner): "เรื่องค่าคอมเป็นเรื่องสำคัญ
 * หากยังไม่ได้มีการ setup ค่าคอม ให้แจ้งเตือนในทุกหน้า หลังจากมีการตั้งค่าแล้ว
 * ไม่แสดง หากมีการ setup ค่าคอมไม่ครบถ้วนที่ไม่สมบูรณ์ให้เตือนผู้ใช้"
 *
 * ── WHAT IT IS FOR ──
 *
 * A commission rate that was never configured is silent all the way down:
 * CommissionService::recordForReferral() logs a warning and returns null
 * rather than blocking the sale, so deals close, orders are immutable, and
 * nobody is paid — and no screen says so until payout day. This store holds
 * the server's one-line verdict so a banner can sit above every admin page
 * until somebody fixes it.
 *
 * ── WHY IT IS A STORE AND NOT A FETCH IN THE BANNER ──
 *
 * "ทุกหน้า" must not mean "a request per page". The banner is mounted once in
 * App.vue and would re-fetch on every route change if it owned the call, and
 * the settings screen needs the SAME answer — computing it a second time
 * there is how the shell and the settings page end up disagreeing in front of
 * the one person trying to fix it. So: one fetch per authenticated session
 * per company, cached by company id, invalidated on purpose by refresh().
 */

export type CommissionReadinessState = 'missing' | 'incomplete' | 'ready'
/** 1 เลือกบริษัท · 2 เลือกแผน · 3 ตั้งอัตราตัวแทน · 4 ส่วนเพิ่มเติม — CommissionPlansView's own steps. */
export type CommissionBlockingStep = 1 | 2 | 3 | 4

export interface CommissionReadinessIssue {
  code: string
  /** Thai, user-facing, already carrying its own numbers. Rendered verbatim. */
  label: string
  count: number
}

export interface CommissionReadinessPayload {
  state: CommissionReadinessState
  blocking_step: CommissionBlockingStep | null
  products_total: number
  products_covered: number
  issues: CommissionReadinessIssue[]
  /**
   * THE SERVER'S ANSWER TO "MAY THIS USER FIX IT", AND THE FRONTEND MUST NOT
   * RE-DERIVE IT FROM A ROLE STRING.
   *
   * Commission config became Super-Admin-only to write on 2026-09-11 (five
   * policies answer isSuperAdmin(); six Settings…Update abilities left the
   * Company Admin row). A Company Admin must be told the state — an agent
   * asks them first — but must not be handed a button that walks into a 403.
   * Reading the boolean the server already computed means the day that rule
   * gains a per-company exception, nothing here has to change.
   */
  can_fix: boolean
}

/**
 * Dismissal is per company + per state + per DAY.
 *
 * The date is the VALUE rather than part of the key, which is the one
 * deviation from the literal spec and is deliberate: a date in the key leaves
 * one dead entry in localStorage per company per state per day, forever, for
 * a preference whose whole point is that it expires. Same behaviour, bounded
 * storage — "dismissed" only counts when the stored day IS today.
 *
 * Per STATE as well as per day, because a banner that goes from amber to red
 * is telling you something new: a dismissal of "ไม่ครบ" must not silence
 * "ไม่มีใครได้เงิน".
 */
const DISMISS_KEY_PREFIX = 'commissionReadiness.dismissed'

/**
 * WHICH WARNING was dismissed — a fourth dimension, added 2026-09-13.
 *
 * The banner (surface `undefined`) keeps the key it has always written, so no
 * admin loses a dismissal to this change. `startHere` is CommissionPlansView's
 * once-a-day "this company cannot pay anybody yet" modal, and it gets its OWN
 * suffix rather than sharing the banner's key on purpose: the two are not the
 * same promise. "ปิดไว้ก่อน" on a modal means "not this interruption, now"; the
 * strip above every page is the safety net that must survive it, and silencing
 * it as a side effect of closing a dialog is precisely how a company buys a
 * month of the silence this whole store exists to break.
 *
 * The KEY SCHEME is deliberately still one scheme — same prefix, same company
 * and state dimensions, same "the date is the value" trick (see above) — so
 * every dismissal in this app still expires by itself and none of them
 * accumulates a dead key per day.
 */
export type CommissionReadinessSurface = 'startHere'

function today(): string {
  // Local date, not toISOString(): the admin's "tomorrow" is Bangkok's, and
  // UTC would bring the banner back at 07:00 for anyone in +07.
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

function dismissKey(companyKey: string, state: CommissionReadinessState, surface?: CommissionReadinessSurface): string {
  const base = `${DISMISS_KEY_PREFIX}:${companyKey}:${state}`

  return surface ? `${base}:${surface}` : base
}

export const useCommissionReadinessStore = defineStore('commissionReadiness', () => {
  const auth = useAuthStore()
  const activeCompany = useActiveCompanyStore()

  /** Cached per company so switching back and forth does not re-ask. */
  const byCompany = ref<Record<string, CommissionReadinessPayload>>({})
  const inFlight = new Map<string, Promise<void>>()
  /**
   * A 403 is a permanent answer for this session, not a transient failure.
   *
   * An agent (or any role the endpoint refuses) must never see the banner,
   * and must never retry the refusal on every route change. The server is the
   * wall; this flag only stops us walking into it repeatedly.
   */
  const denied = ref(false)
  const loadError = ref('')

  /** 'all' is a real scope, not a missing one — a Super Admin on ทุกบริษัท. */
  const companyKey = computed(() => (activeCompany.companyId === null ? 'all' : String(activeCompany.companyId)))

  const payload = computed<CommissionReadinessPayload | null>(() => byCompany.value[companyKey.value] ?? null)

  const state = computed<CommissionReadinessState | null>(() => payload.value?.state ?? null)
  const blockingStep = computed<CommissionBlockingStep | null>(() => payload.value?.blocking_step ?? null)
  const issues = computed<CommissionReadinessIssue[]>(() => payload.value?.issues ?? [])
  const canFix = computed(() => payload.value?.can_fix === true)
  const productsTotal = computed(() => payload.value?.products_total ?? 0)
  const productsCovered = computed(() => payload.value?.products_covered ?? 0)

  /**
   * Bumped by dismiss() so the computed below re-reads storage. localStorage
   * is not reactive, and the alternative — mirroring every key in a ref — is
   * a second copy of the truth that goes stale the moment another tab writes.
   */
  const dismissalTick = ref(0)

  /**
   * "Has THIS warning already been waved away today, for this company, in this
   * state" — asked by the banner (no surface) and by any other warning that
   * needs the same once-a-day rule.
   *
   * A plain function rather than a second computed per surface, and it is
   * still safe to call from inside one: it READS `dismissalTick`, `state` and
   * `companyKey`, so a computed that calls it tracks all three and re-runs when
   * any of them moves. That is what keeps a caller from mirroring localStorage
   * in a ref of its own — a second copy of the truth that goes stale the moment
   * another tab writes.
   */
  function dismissedTodayFor(surface?: CommissionReadinessSurface): boolean {
    // Referenced so callers re-run after dismiss(); the value itself is
    // meaningless.
    void dismissalTick.value

    const current = state.value
    if (current === null || current === 'ready') return false

    return readStored(dismissKey(companyKey.value, current, surface)) === today()
  }

  const dismissedToday = computed<boolean>(() => dismissedTodayFor())

  /**
   * The one question the banner asks.
   *
   * `ready` renders nothing at all — not a green bar, nothing (owner:
   * "หลังจากมีการตั้งค่าแล้วไม่แสดง"). Everything else here is a guard against
   * rendering a verdict we do not actually have: before /me has answered, for
   * a role the endpoint refuses, or while the first fetch is still open.
   */
  const shouldShow = computed<boolean>(() => {
    if (!auth.isAuthenticated || denied.value) return false
    const current = state.value

    return current !== null && current !== 'ready' && !dismissedToday.value
  })

  /**
   * Idempotent. Safe to call from every mount — the second call for a company
   * already loaded (or already in flight) costs nothing.
   *
   * Deduped by the IN-FLIGHT promise as well as by the cache, because the
   * banner and the settings screen can both ask in the same tick: `byCompany`
   * only gains its key after the response lands, so a cache-only check would
   * fire the request twice on exactly the screen that matters most.
   */
  async function ensureLoaded(): Promise<void> {
    if (!auth.isAuthenticated || denied.value) return

    const key = companyKey.value
    if (byCompany.value[key]) return

    const existing = inFlight.get(key)
    if (existing) return existing

    const run = fetchFor(key).finally(() => inFlight.delete(key))
    inFlight.set(key, run)

    return run
  }

  /**
   * Force a re-ask for the CURRENT company.
   *
   * Called by CommissionPlansView after every successful write. Without it
   * the admin fixes the last missing rate, the screen's own banner clears
   * (it re-reads this store), and the shell's banner keeps insisting nobody
   * is being paid — which is exactly the "two answers" failure this store
   * exists to prevent.
   */
  async function refresh(): Promise<void> {
    if (!auth.isAuthenticated || denied.value) return

    const key = companyKey.value
    delete byCompany.value[key]
    inFlight.delete(key)

    return ensureLoaded()
  }

  async function fetchFor(key: string): Promise<void> {
    loadError.value = ''
    try {
      const path = key === 'all' ? '/commission-readiness' : `/commission-readiness?company_id=${key}`
      byCompany.value[key] = await api.get<CommissionReadinessPayload>(path)
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) {
        denied.value = true

        return
      }
      /*
       * Any other failure leaves the banner absent rather than showing an
       * error of its own. A warning that cannot be trusted is worse than no
       * warning: an admin who sees "โหลดไม่สำเร็จ" on every page learns to
       * ignore this strip, and then misses the real red one.
       */
      loadError.value = e instanceof ApiError ? `โหลดสถานะค่าคอมไม่สำเร็จ (${e.status})` : 'โหลดสถานะค่าคอมไม่สำเร็จ'
    }
  }

  /**
   * Hide until tomorrow, for this company, this state and this surface only.
   *
   * `dismiss()` with no argument is the banner's own dismissal and writes the
   * key it always has.
   */
  function dismiss(surface?: CommissionReadinessSurface): void {
    const current = state.value
    if (current === null || current === 'ready') return

    writeStored(dismissKey(companyKey.value, current, surface), today())
    dismissalTick.value++
  }

  /**
   * Drop everything on logout. A second user signing in on the same machine
   * must not inherit the previous one's verdict — and `denied` in particular
   * would otherwise keep the banner switched off for an admin because an
   * agent logged in first.
   */
  function reset(): void {
    byCompany.value = {}
    inFlight.clear()
    denied.value = false
    loadError.value = ''
  }

  return {
    byCompany,
    denied,
    loadError,
    companyKey,
    payload,
    state,
    blockingStep,
    issues,
    canFix,
    productsTotal,
    productsCovered,
    dismissedToday,
    dismissedTodayFor,
    shouldShow,
    ensureLoaded,
    refresh,
    dismiss,
    reset,
  }
})
