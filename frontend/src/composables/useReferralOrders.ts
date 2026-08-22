/**
 * useReferralOrders — TASK-141's payment state per referral + the
 * one-press "เก็บเงินเลย", extracted from ReferralsView.vue so every
 * screen that shows a deal row runs the SAME logic (TASK-169 Phase 2,
 * where the client drawer became a second such screen).
 *
 * It owns the API side that ReferralRow.vue deliberately does not: the
 * order map, the paging strategy, and the duplicate-order 422 path. Two
 * copies of the 422 path in particular would be the kind of duplication
 * that drifts — one screen would keep re-sharing a settled link after the
 * other stopped.
 *
 * The order lifecycle itself (confirm / cancel / slip) is NOT here — that
 * stays OrdersView.vue's job. This is only "create the link and hand it
 * over".
 */
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'

/**
 * The subset of OrderResource a deal row reads. Deliberately NOT the full
 * 17-field Order interface OrdersView.vue declares: nothing here confirms,
 * cancels, downloads a slip or renders an amount, and copying fields it
 * does not use would invite them to drift apart. The status vocabulary IS
 * shared verbatim (see ReferralRow.vue's STATUS_CHIP).
 */
export type OrderStatus = 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'
export interface OrderSummary {
  id: number
  order_number: string
  status: OrderStatus
  status_label: string
  public_pay_url: string
  referral_id: number
  // TASK-191 §3.2 — needed to pick the MOST RECENTLY PAID order when a
  // client has more than one (ClientsView's collapsed-card share button).
  // OrderResource already sends this field; it was simply never read by
  // this composable before now.
  paid_at: string | null
  // TASK-212 — prefills <ShareLinkModal>'s recipient box. whenLoaded on
  // OrderResource, so it is absent (not null) when the caller did not
  // eager-load the client; `?? null` at every read site handles both.
  client_email?: string | null
  /**
   * Has the customer attached a payment slip?
   *
   * OrderResource has always sent it and OrdersView has always read it —
   * this composable simply never carried it, so the client drawer and the
   * pipeline board could SAY "รอตรวจสอบสลิป" and offer no way to look at
   * the thing they were naming (human report, 2026-08-21).
   */
  has_slip: boolean
}
/** Laravel paginates /orders (AnonymousResourceCollection). */
interface PaginatedResponse<T> {
  data: T[]
  meta?: { current_page: number; last_page: number }
}

/**
 * GET /orders paginates at Laravel's default 15 and OrderController::index()
 * does NOT honour a per_page param, so a single page would leave older rows
 * with no chip at all — which is precisely the "press it to find out" problem
 * TASK-141 requirement 3 exists to remove. Pages are therefore followed,
 * bounded: past MAX_ORDER_PAGES the remaining rows simply show no chip and
 * the button still resolves correctly through the duplicate-order path in
 * collectPayment().
 */
const MAX_ORDER_PAGES = 10

/**
 * @param signal optional AbortSignal for the GETs, so a view that cancels
 *   its in-flight requests on unmount (TASK-079 Phase 4) can cancel these
 *   too. The POST is never signalled: aborting it could leave an order
 *   created server-side that the agent never sees a link for.
 */
export function useReferralOrders(signal?: AbortSignal) {
  const toast = useToastStore()

  /** referral_id → its current ACTIVE order (cancelled ones are skipped). */
  const ordersByReferral = ref<Record<number, OrderSummary>>({})
  const ordersError = ref('')
  const collectingId = ref<number | null>(null)
  const payActionError = ref<{ id: number; message: string } | null>(null)

  async function loadOrders(): Promise<void> {
    ordersError.value = ''
    try {
      const map: Record<number, OrderSummary> = {}
      let page = 1
      let lastPage = 1
      do {
        const res = await api.get<PaginatedResponse<OrderSummary>>(`/orders?page=${page}`, signal)
        for (const order of res.data) {
          // A cancelled order does not block creating a new one
          // (OrderService::createForReferral), so it must not claim the row —
          // otherwise the agent would see a dead link and no way to remake it.
          if (order.status === 'cancelled') continue
          // /orders is latest-first, so the first hit per referral is the newest.
          if (!map[order.referral_id]) map[order.referral_id] = order
        }
        lastPage = res.meta?.last_page ?? 1
        page++
      } while (page <= lastPage && page <= MAX_ORDER_PAGES)
      ordersByReferral.value = map
    } catch (e) {
      if (isAbortError(e)) return
      // Non-fatal: the deal list is still fully usable without the chips.
      ordersError.value = apiErrorMessage(e, 'โหลดสถานะการชำระเงินไม่สำเร็จ')
    }
  }

  /**
   * Same sweep, but at most once per view — for a caller that defers it
   * until a deal list is actually on screen (the client drawer) instead of
   * paying for it on mount. A failed sweep is retried on the next call, so
   * a dropped connection does not leave the chips off for good.
   */
  let loadedOnce: Promise<void> | null = null
  function ensureOrdersLoaded(): Promise<void> {
    if (!loadedOnce) {
      loadedOnce = loadOrders().then(() => {
        if (ordersError.value) loadedOnce = null
      })
    }
    return loadedOnce
  }

  function orderFor(referralId: number): OrderSummary | undefined {
    return ordersByReferral.value[referralId]
  }

  // ── Share sheet ─────────────────────────────────────────────────────────
  const showShareModal = ref(false)
  const shareUrl = ref('')
  const shareHeading = ref('')
  // TASK-212 — what the sheet needs to email this link THROUGH the platform
  // rather than hand it to the phone's mail client. The id, not the URL:
  // the server rebuilds the URL from the order it authorizes.
  const shareOrderId = ref<number | null>(null)
  const shareDefaultEmail = ref<string | null>(null)

  function openShare(order: OrderSummary): void {
    shareUrl.value = order.public_pay_url
    shareHeading.value = `ชำระเงิน ${order.order_number}`
    shareOrderId.value = order.id
    shareDefaultEmail.value = order.client_email ?? null
    showShareModal.value = true
  }

  function openShareFor(referralId: number): void {
    const order = orderFor(referralId)
    if (order) openShare(order)
  }

  /**
   * Open the payment slip the customer attached.
   *
   * Lives here rather than in ClientsView so the client drawer and the
   * pipeline board reach the slip the same way — the same reason openShare()
   * is here. `api.download` and not a plain link: the slip is on the private
   * disk behind GET /orders/{order}/slip, which is access-checked
   * (OrderPolicy::view) and therefore needs the session, so an <a href> would
   * 401 rather than open.
   *
   * Failure is a toast, not an inline error. This is a READ — nothing is
   * half-done if it fails, there is no field to correct, and the row's
   * inline slot is reserved for the pay action's own errors, which the agent
   * must not confuse with this.
   */
  async function viewSlipFor(referralId: number): Promise<void> {
    const order = orderFor(referralId)
    if (!order?.has_slip) return

    try {
      // api.download takes (path, filename) only — it streams through fetch
      // and hands the blob to an <a download>, so there is no signal to pass.
      await api.download(`/orders/${order.id}/slip`, `slip-${order.order_number}.jpg`)
    } catch (e) {
      if (isAbortError(e)) return
      toast.error(apiErrorMessage(e, 'เปิดสลิปไม่สำเร็จ'))
    }
  }

  /**
   * Fetch the order that already exists for a referral. OrderController::index()
   * supports ?referral_id= server-side, so this is one narrow request, not a
   * client-side scan of every order the agent owns.
   */
  async function findActiveOrder(referralId: number): Promise<OrderSummary | null> {
    try {
      const res = await api.get<PaginatedResponse<OrderSummary>>(`/orders?referral_id=${referralId}`, signal)
      return res.data.find((o) => o.status !== 'cancelled') ?? null
    } catch {
      return null
    }
  }

  async function collectPayment(referralId: number): Promise<void> {
    if (collectingId.value !== null) return
    collectingId.value = referralId
    payActionError.value = null
    try {
      // payment_method is NOT asked for — 'promptpay' always.
      //
      // PaymentPageView.vue renders the bank-transfer block UNCONDITIONALLY and
      // the PromptPay QR only when payment_method === 'promptpay' (the payload
      // itself is likewise only emitted by PublicOrderResource for PromptPay).
      // 'promptpay' is therefore a strict SUPERSET of what 'bank_transfer' shows
      // the customer: picking 'bank_transfer' can only ever REMOVE the QR from a
      // page that still lists the bank details anyway. So there is no
      // customer-visible upside to asking, and a whole extra step of cost — the
      // customer chooses how they actually pay ON the pay page, not the agent in
      // advance.
      //
      // No business rule is being invented here: nothing in OrderService /
      // commission (BR-4) / the pipeline reads payment_method at all — its only
      // behavioural effect anywhere is that QR toggle. Reported to ag-lead as a
      // near-vestigial column.
      const res = await api.post<{ data: OrderSummary }>('/orders', {
        referral_id: referralId,
        payment_method: 'promptpay',
      })
      ordersByReferral.value = { ...ordersByReferral.value, [referralId]: res.data }
      openShare(res.data)
      toast.success(`สร้างลิงก์ชำระเงิน ${res.data.order_number} แล้ว`)
    } catch (e) {
      if (isAbortError(e)) return
      /**
       * OrderService::createForReferral() 422s on a second active order for the
       * same referral. An agent pressing this twice means "give me the link",
       * not "make me a duplicate", so the raw rejection is never surfaced —
       * the existing order is fetched and shared instead.
       *
       * Deliberately keyed on "is there an active order?" rather than on
       * matching the Thai message: 422 also covers the ownership rejection in
       * StoreOrderRequest, and string-matching a backend message would break
       * silently the day it is reworded. If no active order comes back, the
       * original error was something else and IS reported.
       */
      if (e instanceof ApiError && e.status === 422) {
        const existing = await findActiveOrder(referralId)
        if (existing) {
          ordersByReferral.value = { ...ordersByReferral.value, [referralId]: existing }
          if (existing.status === 'paid') {
            toast.info(`คำสั่งซื้อ ${existing.order_number} ชำระเงินเรียบร้อยแล้ว`)
          } else {
            openShare(existing)
          }
          return
        }
      }
      payActionError.value = { id: referralId, message: apiErrorMessage(e, 'สร้างลิงก์ชำระเงินไม่สำเร็จ') }
    } finally {
      collectingId.value = null
    }
  }

  return {
    ordersByReferral,
    ordersError,
    collectingId,
    payActionError,
    showShareModal,
    shareUrl,
    shareHeading,
    shareOrderId,
    shareDefaultEmail,
    loadOrders,
    ensureOrdersLoaded,
    orderFor,
    openShare,
    openShareFor,
    viewSlipFor,
    findActiveOrder,
    collectPayment,
  }
}
