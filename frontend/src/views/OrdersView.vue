<script setup lang="ts">
/**
 * OrdersView — Agent Portal order / payment collection (ADR-017 / TASK-054).
 *
 * An agent creates an order bound to one of their own referrals, chooses a
 * payment method (bank transfer / PromptPay), and gets a shareable public
 * pay link (`/pay/{token}`) to send the client. The list shows each order's
 * status; per-order actions cover copy-link, view-slip, confirm (advance the
 * sale → BR-4 commission, server-side) and cancel.
 *
 * All money comes from the API already split into satang (BR-3) + a display
 * `amount_baht`; this view only formats, never computes. Business rules
 * (payable-stage gating on confirm) live server-side — a 422 there surfaces
 * the backend's Thai message verbatim rather than being re-derived here.
 */
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — this file used to carry its own local
// apiMessage() helper; it is deleted so apiErrorMessage()
// (utils/apiError.ts) is the single implementation app-wide. The shared
// one is a strict superset: same "prefer Laravel's own message/first
// field error" behaviour, plus status mapping the local version had no
// answer for (offline / 401 / 403 / 429) instead of dropping straight
// through to a generic fallback.
import { apiErrorMessage } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import NavBarAction from '@/design-system/components/NavBarAction.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'

type OrderStatus = 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'
type PaymentMethod = 'bank_transfer' | 'promptpay'

interface Order {
  id: number
  order_number: string
  status: OrderStatus
  status_label: string
  payment_method: PaymentMethod
  payment_method_label: string
  amount_satang: number
  amount_baht: number
  public_token: string
  public_pay_url: string
  /**
   * TASK-235 — /pay/<14 characters> instead of /pay/<40>. Fourteen, not the
   * ten every other group gets: this page shows the order's contents and
   * total, so shortening the front door had to not shorten the protection.
   * Null before the feature; every use site falls back rather than swaps.
   */
  short_pay_url: string | null
  client_name: string | null
  // TASK-212 — prefill for the share sheet's recipient box. whenLoaded on
  // OrderResource, hence optional here as well as nullable.
  client_email?: string | null
  product_name: string | null
  agent?: { id: number; name: string } | null
  referral_id: number
  has_slip: boolean
  paid_at: string | null
  created_at: string
}

interface ReferralOption {
  id: number
  client?: { id: number; name: string } | null
  product?: { id: number; name: string; price_satang: number } | null
  current_stage: { key: string; label: string }
}

// Both /orders and /referrals paginate server-side, so the payload is
// { data: T[], meta, links }. A plain { data: T[] } is handled too.
interface ListResponse<T> {
  data: T[]
}

const toast = useToastStore()

const loading = ref(true)
const errorMessage = ref('')
const orders = ref<Order[]>([])

/*
 * FILTER BY PAYMENT STATE (2026-08-22).
 *
 * GET /orders has accepted `?status=` all along; this view called it bare, so
 * an agent looking for "who still owes me money" scrolled the whole list and
 * read status chips one row at a time. The filter was already built — nobody
 * had connected it.
 *
 * `null` is the "ทั้งหมด" tab and sends no parameter at all, rather than a
 * sentinel the backend would have to know about.
 *
 * Ordered by who is blocked, not by the enum: a slip waiting to be checked is
 * work THIS agent has to do, while "รอชำระเงิน" waits on the customer.
 */
const STATUS_TABS: Array<{ status: string | null; label: string }> = [
  { status: null, label: 'ทั้งหมด' },
  { status: 'awaiting_verification', label: 'รอตรวจสลิป' },
  { status: 'pending', label: 'รอชำระเงิน' },
  { status: 'paid', label: 'ชำระแล้ว' },
]

const activeStatus = ref<string | null>(null)

async function loadOrders() {
  loading.value = true
  errorMessage.value = ''
  try {
    const path = activeStatus.value === null ? '/orders' : `/orders?status=${activeStatus.value}`
    const res = await api.get<ListResponse<Order>>(path)
    orders.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดคำสั่งซื้อไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function selectStatus(status: string | null) {
  if (activeStatus.value === status) return
  activeStatus.value = status
  await loadOrders()
}

onMounted(loadOrders)

function formatBaht(baht: number): string {
  return '฿' + baht.toLocaleString('th-TH')
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH')
}

const STATUS_CHIP: Record<OrderStatus, string> = {
  pending: 'bg-surface-warning text-ink-warning border-line-card',
  awaiting_verification: 'bg-brand-50 text-brand-700 border-brand-200',
  paid: 'bg-surface-success text-ink-success border-line-card',
  cancelled: 'bg-surface-chip text-ink-card-muted border-line-card',
}

// ── Create panel ──────────────────────────────────────────────────────────
const showCreate = ref(false)
const referrals = ref<ReferralOption[]>([])
const referralsLoading = ref(false)
const referralsError = ref('')
const form = ref<{ referral_id: number | ''; payment_method: PaymentMethod }>({
  referral_id: '',
  payment_method: 'bank_transfer',
})
const creating = ref(false)
const createError = ref('')
const createdOrder = ref<Order | null>(null)

async function openCreate() {
  showCreate.value = true
  createdOrder.value = null
  createError.value = ''
  form.value = { referral_id: '', payment_method: 'bank_transfer' }
  if (referrals.value.length) return
  referralsLoading.value = true
  referralsError.value = ''
  try {
    const res = await api.get<ListResponse<ReferralOption>>('/referrals')
    referrals.value = res.data
  } catch (e) {
    referralsError.value = apiErrorMessage(e, 'โหลดรายการอ้างอิงไม่สำเร็จ')
  } finally {
    referralsLoading.value = false
  }
}

function referralLabel(r: ReferralOption): string {
  const client = r.client?.name ?? 'ลูกค้า'
  const product = r.product?.name ?? 'ไม่ระบุแพ็กเกจ'
  return `${client} · ${product} · ${r.current_stage.label}`
}

function closeCreate() {
  showCreate.value = false
}

async function submitCreate() {
  if (creating.value) return
  if (!form.value.referral_id) {
    createError.value = 'กรุณาเลือกรายการอ้างอิง'
    return
  }
  creating.value = true
  createError.value = ''
  try {
    const res = await api.post<{ data: Order }>('/orders', {
      referral_id: Number(form.value.referral_id),
      payment_method: form.value.payment_method,
    })
    createdOrder.value = res.data
    await loadOrders()
    toast.success(`สร้างคำสั่งซื้อ ${res.data.order_number} แล้ว`)
  } catch (e) {
    // Inline banner only — the create panel stays open and already renders
    // createError right above the form, so a toast would double-report.
    createError.value = apiErrorMessage(e, 'สร้างคำสั่งซื้อไม่สำเร็จ')
  } finally {
    creating.value = false
  }
}

// ── Per-order actions ─────────────────────────────────────────────────────
// TASK-056 Sprint P4 — replaced the plain copy-to-clipboard button with
// the reusable ShareLinkModal (Copy / QR / LINE / Email), same
// component ProductBrowseView.vue uses for product-share links. The pay
// link itself is unchanged (order.public_pay_url) — this only changes
// how the Agent hands it to the client.
const showShareModal = ref(false)
const shareUrl = ref('')
const shareHeading = ref('')
// TASK-212 — the sheet emails this link itself now (human: "ระบบ อีเมล์ให้
// ส่งผ่านระบบ"). It needs the order's id, not its URL: /share-emails
// rebuilds the URL from the order it has just authorized, so that a login
// cannot be used to mail arbitrary links from the platform's address.
const shareOrderId = ref<number | null>(null)
const shareDefaultEmail = ref<string | null>(null)
function openShare(order: Order) {
  shareUrl.value = order.short_pay_url ?? order.public_pay_url
  shareHeading.value = `ชำระเงิน ${order.order_number}`
  shareOrderId.value = order.id
  shareDefaultEmail.value = order.client_email ?? null
  showShareModal.value = true
}

async function viewSlip(order: Order) {
  try {
    await api.download(`/orders/${order.id}/slip`, `slip-${order.order_number}.jpg`)
  } catch (e) {
    actionError.value = { id: order.id, message: apiErrorMessage(e, 'ดาวน์โหลดสลิปไม่สำเร็จ') }
  }
}

const actionError = ref<{ id: number; message: string } | null>(null)
const busyId = ref<number | null>(null)

async function confirmPayment(order: Order) {
  if (busyId.value) return
  busyId.value = order.id
  actionError.value = null
  try {
    await api.post(`/orders/${order.id}/confirm`)
    await loadOrders()
    // Confirming is the money moment (BR-4 commission triggers server-side
    // off this) — the audit flagged it as the highest-stakes action in the
    // app with zero acknowledgement. Failures still use the per-order
    // inline banner below, which is right next to the button.
    toast.success('ยืนยันการชำระเงินแล้ว')
  } catch (e) {
    actionError.value = { id: order.id, message: apiErrorMessage(e, 'ยืนยันการชำระเงินไม่สำเร็จ') }
  } finally {
    busyId.value = null
  }
}

// TASK-079 Phase 2 (UX audit) — cancel used to fire native window.confirm(),
// the last one left in this app. Native dialogs are unthemed, unreadable on
// a phone, and inconsistent with the ConfirmDialog every other destructive
// action already uses (see AffiliateLinksView.vue's revoke flow).
const cancelTarget = ref<Order | null>(null)
const showCancelConfirm = ref(false)

function askCancelOrder(order: Order) {
  if (busyId.value) return
  cancelTarget.value = order
  showCancelConfirm.value = true
}

async function confirmCancelOrder() {
  const order = cancelTarget.value
  if (!order) return
  busyId.value = order.id
  actionError.value = null
  try {
    await api.post(`/orders/${order.id}/cancel`)
    showCancelConfirm.value = false
    await loadOrders()
    toast.success('ยกเลิกคำสั่งซื้อแล้ว')
  } catch (e) {
    // Toast as well as the inline banner: the ConfirmDialog is still open
    // on the failure path and covers the order row it belongs to.
    const message = apiErrorMessage(e, 'ยกเลิกคำสั่งซื้อไม่สำเร็จ')
    actionError.value = { id: order.id, message }
    toast.error(message)
  } finally {
    busyId.value = null
    cancelTarget.value = null
  }
}

function canConfirmOrCancel(order: Order): boolean {
  return order.status === 'pending' || order.status === 'awaiting_verification'
}

const hasOrders = computed(() => orders.value.length > 0)
</script>

<template>
  <!-- TASK-079 Phase 4 (2026-08-03, UX audit): migrated off the hand-rolled
       `px-4 py-4` + bare <h1> shell onto the HeroHeader shell the other 9
       views use, so the header band and page rhythm stop changing between
       tabs. `back-page` is also new here — the audit found HeroHeader's
       back-button feature had ZERO callers app-wide, leaving the 7
       non-BottomNav pages (this one included) with no way out but the
       browser's own back gesture. -->
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: var(--app-font);">
    <HeroHeader
      icon="cart"
      title="คำสั่งซื้อ / รับชำระเงิน"
      subtitle="สร้างลิงก์ชำระเงินและติดตามสถานะ"
      accent-color="brand"
      storage-key="orders"
      back-page="/"
      back-label="หน้าหลัก"
    >
      <!-- TASK-087 — navigation-bar action per Apple HIG; see NavBarAction.vue. -->
      <template #actions>
        <NavBarAction v-if="!showCreate" icon="plus" label="สร้างคำสั่งซื้อ" @click="openCreate" />
      </template>

      <!-- Filter by payment state (2026-08-22). In the #tabs slot so it
           flattens into the header card instead of floating as a second
           surface — same placement AnnouncementsListView uses for its
           search box. Colours come from the token layer (ADR-023): a fixed
           ramp step here would be the pale-chip failure again. -->
      <template #tabs>
        <div class="px-4 py-3 flex gap-1.5 overflow-x-auto">
          <button
            v-for="tab in STATUS_TABS"
            :key="tab.status ?? 'all'"
            type="button"
            class="shrink-0 min-h-[38px] px-3.5 rounded-xl text-xs font-bold transition"
            :class="activeStatus === tab.status
              ? 'bg-surface-primary text-ink-primary'
              : 'bg-surface-chip text-ink-chip hover:opacity-80'"
            @click="selectStatus(tab.status)"
          >
            {{ tab.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <!-- Create panel -->
    <AppCard v-if="showCreate" class="mt-4 space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-bold text-ink-card">สร้างคำสั่งซื้อใหม่</h2>
        <button type="button" class="min-h-[44px] min-w-[44px] -mr-2 inline-flex items-center justify-center text-ink-card-subtle hover:text-ink-card-muted active:scale-90 transition-transform" @click="closeCreate">
          <Icon name="close" :size="18" />
        </button>
      </div>

      <!-- Created success: show the shareable link -->
      <div v-if="createdOrder" class="space-y-3">
        <div class="flex items-center gap-2 text-ink-success">
          <Icon name="check" :size="18" />
          <span class="text-sm font-bold">สร้างคำสั่งซื้อ {{ createdOrder.order_number }} แล้ว</span>
        </div>
        <p class="text-xs text-ink-card-muted">ส่งลิงก์นี้ให้ลูกค้าเพื่อชำระเงิน</p>
        <div class="flex items-center gap-2">
          <input
            :value="createdOrder.short_pay_url ?? createdOrder.public_pay_url"
            readonly
            class="flex-1 min-w-0 min-h-[44px] px-3 py-2 rounded-xl border border-line-card text-xs text-ink-chip bg-surface-chip"
          />
          <AppButton class="shrink-0" @click="openShare(createdOrder)">
            <Icon name="share" :size="14" />
            แชร์
          </AppButton>
        </div>
        <AppButton variant="secondary" block @click="closeCreate">เสร็จสิ้น</AppButton>
      </div>

      <!-- Create form -->
      <template v-else>
        <div v-if="createError" class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2 text-sm text-ink-danger">
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ createError }}</span>
        </div>

        <div>
          <label class="block text-xs font-bold text-ink-card-muted mb-1.5">เลือกรายการอ้างอิง (Referral)</label>
          <div v-if="referralsLoading" class="text-xs text-ink-card-subtle py-2">กำลังโหลด...</div>
          <div v-else-if="referralsError" class="text-xs text-ink-danger py-2">{{ referralsError }}</div>
          <select
            v-else
            v-model="form.referral_id"
            class="bg-surface-input w-full min-h-[44px] px-3 py-2.5 rounded-xl border border-line-input text-sm text-ink-input focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500"
          >
            <option value="" disabled>— เลือกรายการ —</option>
            <option v-for="r in referrals" :key="r.id" :value="r.id">{{ referralLabel(r) }}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-bold text-ink-card-muted mb-1.5">ช่องทางชำระเงิน</label>
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="min-h-[44px] py-2.5 rounded-xl border text-sm font-bold inline-flex items-center justify-center gap-1.5 transition-all active:scale-95"
              :class="form.payment_method === 'bank_transfer' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line-card text-ink-card-muted hover:bg-surface-chip'"
              @click="form.payment_method = 'bank_transfer'"
            >
              <Icon name="money" :size="16" />
              โอนเงิน
            </button>
            <button
              type="button"
              class="min-h-[44px] py-2.5 rounded-xl border text-sm font-bold inline-flex items-center justify-center gap-1.5 transition-all active:scale-95"
              :class="form.payment_method === 'promptpay' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line-card text-ink-card-muted hover:bg-surface-chip'"
              @click="form.payment_method = 'promptpay'"
            >
              <Icon name="credit_card" :size="16" />
              PromptPay
            </button>
          </div>
        </div>

        <!-- TASK-079 Phase 4 — AppButton's own spinner replaces the
             "กำลังสร้าง..." label swap: the label no longer reflows, and
             the button is inert while `loading` (double-submit guard). -->
        <AppButton :loading="creating" block @click="submitCreate">สร้างคำสั่งซื้อ</AppButton>
      </template>
    </AppCard>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <!-- Loading skeleton. TASK-079 Phase 4 (UX audit): was a hand-rolled
           pair of `animate-pulse` boxes — one of 5 views that each invented
           their own while LoadingSkeleton.vue sat unused. -->
      <LoadingSkeleton v-if="loading" type="list" :rows="3" />

      <!-- Error -->
      <div
        v-else-if="errorMessage"
        class="mt-4 bg-surface-card/95 border border-line-card rounded-2xl shadow-sm p-5 flex flex-col items-center gap-3 text-center"
      >
        <Icon name="alert" :size="32" class="text-ink-danger" />
        <p class="text-sm text-ink-card-muted font-bold">{{ errorMessage }}</p>
        <AppButton @click="loadOrders">ลองใหม่</AppButton>
      </div>

      <!-- Empty -->
      <div
        v-else-if="!hasOrders"
        class="mt-4 bg-surface-card/95 border border-dashed border-line-card rounded-2xl p-6 flex flex-col items-center gap-3 text-center"
      >
        <Icon name="cart" :size="32" class="text-ink-card-subtle" />
        <p class="text-sm text-ink-card-muted font-bold">ยังไม่มีคำสั่งซื้อ</p>
        <AppButton @click="openCreate">+ สร้างคำสั่งซื้อแรก</AppButton>
      </div>

      <!-- Order list -->
      <div v-else class="space-y-3 mt-4">
        <AppCard v-for="order in orders" :key="order.id" class="space-y-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-bold text-ink-card truncate">{{ order.order_number }}</span>
                <span class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full border" :class="STATUS_CHIP[order.status]">
                  {{ order.status_label }}
                </span>
              </div>
              <p class="text-xs text-ink-card-muted truncate mt-0.5">{{ order.product_name ?? '—' }}</p>
              <p class="text-xs text-ink-card-subtle truncate">{{ order.client_name ?? '—' }}</p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-base font-bold text-ink-card">{{ formatBaht(order.amount_baht) }}</p>
              <p class="text-[11px] text-ink-card-subtle">{{ formatDate(order.created_at) }}</p>
            </div>
          </div>

          <!-- Read-only pay link + copy. TASK-079 Phase 3 (UX audit): this
               row and the action buttons below were ~28px tall (px-2.5/px-3
               + py-1.5 + text-xs) — well under the 44px Apple HIG / 48px
               Material minimum. Raised via min-h-[44px] only: the type size
               and colour are unchanged, it is the HIT AREA that was
               failing, not the legibility. -->
          <div class="flex items-center gap-2">
            <input
              :value="order.short_pay_url ?? order.public_pay_url"
              readonly
              class="flex-1 min-w-0 min-h-[44px] px-3 py-1.5 rounded-lg border border-line-card text-[11px] text-ink-chip bg-surface-chip"
            />
            <AppButton variant="secondary" size="sm" class="shrink-0" @click="openShare(order)">
              <Icon name="share" :size="14" />
              แชร์ลิงก์ชำระเงิน
            </AppButton>
          </div>

          <div v-if="actionError && actionError.id === order.id" class="flex items-start gap-2 rounded-lg bg-surface-danger border border-rose-100 px-3 py-2 text-xs text-ink-danger">
            <Icon name="alert" :size="14" class="mt-0.5 shrink-0" />
            <span>{{ actionError.message }}</span>
          </div>

          <!-- Actions. TASK-079 Phase 4 — only "ดูสลิป" moved to AppButton.
               The other two stay raw Tailwind on purpose: `ยืนยันการชำระเงิน`
               is emerald (semantic success — it is the BR-4 money moment,
               and green is carrying meaning here, not decoration) and
               `ยกเลิก` is a rose OUTLINE, not the solid `danger` fill. Forcing
               either into the primitive would mean inventing one-off
               variants for a single call site each. -->
          <div class="flex flex-wrap items-center gap-2">
            <AppButton v-if="order.has_slip" variant="secondary" size="sm" @click="viewSlip(order)">
              <Icon name="download" :size="14" />
              ดูสลิป
            </AppButton>
            <button
              v-if="canConfirmOrCancel(order)"
              type="button"
              :disabled="busyId === order.id"
              class="min-h-[44px] px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 active:scale-95 transition-transform disabled:opacity-60 inline-flex items-center justify-center gap-1"
              @click="confirmPayment(order)"
            >
              <Icon name="check" :size="14" />
              ยืนยันการชำระเงิน
            </button>
            <button
              v-if="canConfirmOrCancel(order)"
              type="button"
              :disabled="busyId === order.id"
              class="min-h-[44px] px-3 py-1.5 rounded-lg border border-line-card text-ink-danger text-xs font-bold hover:bg-surface-danger active:scale-95 transition-transform disabled:opacity-60 inline-flex items-center justify-center gap-1"
              @click="askCancelOrder(order)"
            >
              <Icon name="x" :size="14" />
              ยกเลิก
            </button>
          </div>
        </AppCard>
      </div>
    </Transition>

    <ShareLinkModal
      v-model:show="showShareModal"
      :url="shareUrl"
      :heading="shareHeading"
      email-type="order"
      :email-target-id="shareOrderId"
      :default-email="shareDefaultEmail"
    />

    <!-- TASK-079 Phase 2 (UX audit) — replaces window.confirm(). MUST stay
         INSIDE this root element: a sibling of the root turns the view into
         a multi-root Fragment, which breaks App.vue's
         <Transition mode="out-in"> (regression previously fixed across 8
         views — see AnnouncementsView.vue). -->
    <ConfirmDialog
      v-model:show="showCancelConfirm"
      title="ยืนยันการยกเลิกคำสั่งซื้อ"
      :body="cancelTarget ? `คำสั่งซื้อ ${cancelTarget.order_number} จะถูกยกเลิก และลิงก์ชำระเงินจะใช้ไม่ได้อีก` : ''"
      variant="danger"
      :busy="busyId !== null"
      @confirm="confirmCancelOrder"
    />
  </main>
</template>
