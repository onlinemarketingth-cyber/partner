<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * PaymentPageView — PUBLIC, unauthenticated payment page (ADR-017 / TASK-054).
 * Route: /pay/:token (meta.public — App.vue renders it full-bleed with no app
 * chrome, same as AffiliateLeadCaptureView / LoginView).
 *
 * A client reaches this after their agent shares the pay link. It shows ONLY
 * what the backend's PublicOrderResource exposes — product name, amount, the
 * company's payment details, and (for PromptPay) an EMVCo payload we render
 * into a QR entirely client-side. NO auth store, NO PDPA/agent/commission data.
 *
 * The PromptPay QR is generated locally from `promptpay_payload` via the
 * `qrcode` package (QRCode.toDataURL) — the payment string is NEVER sent to a
 * third-party image service (§6 / ADR-017 security note).
 *
 * Two API calls, both against the public /pay routes only:
 *   - GET  /pay/{token}       — on mount (404 → dead-link state).
 *   - POST /pay/{token}/slip  — multipart slip upload (api.postForm).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'
import { compressImageToFit } from '@/utils/imageCompression'
// ADR-033 (TASK-189) §2.4/E1 — the voucher QR reuses this SAME util
// ShareLinkModal already uses for product-share/order-payment links,
// rather than a second inline QRCode.toDataURL() call (the PromptPay QR
// above predates this util and is left as-is — out of scope here).
import { generateQrDataUrl } from '@/utils/qrCode'
import { formatVoucherCode, isShortVoucherCode } from '@/utils/voucherCode'
// TASK-159 §4.2 — /pay/{token} carries no company slug, so boot's
// loadPublic() bails at resolveSlug(). The theme now rides along on the
// order payload instead; see the `theme` field on PublicOrder.
import { useThemeStore, type Theme } from '@/stores/theme'
// ADR-027 (TASK-139) — Omise's own hosted card form. Loaded on demand from
// inside that util, never at import time: most orders here are paid by bank
// transfer and must not fetch a third-party script to do it.
import { openCardForm } from '@/utils/omiseCard'

const route = useRoute()
const token = route.params.token as string
const themeStore = useThemeStore()

type OrderStatus = 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'

interface CompanyPayment {
  bank_name: string | null
  bank_account_number: string | null
  bank_account_name: string | null
  promptpay_id: string | null
}
// ADR-033 (TASK-189) §2.2/§2.4 — only present at all once the order is
// paid AND a voucher was actually issued (PublicOrderResource's own
// `when()`), so this is a genuinely optional key, not just optional
// fields inside an always-present object.
interface PublicVoucher {
  code: string
  status: 'active' | 'exhausted' | 'expired'
  status_label: string
  used_count: number
  usage_quota: number | null
  quota_remaining: number | null
  expires_at: string | null
}
/**
 * ADR-027 (TASK-139) — how this order is being paid, from the server.
 *
 * `intent` arrives ONLY on the response to POST /pay/{token}/intent — that
 * is, only after the customer has chosen to pay online. Whether they may
 * choose it at all is `gateway.online`, which the server decides: the company
 * has a verified gateway switched on, the order is still payable, and no
 * money has arrived yet. The page never decides that for itself — the same
 * rule has to hold at the charge endpoint, and two copies of it would
 * eventually disagree and show a form that cannot work.
 */
interface PaymentIntent {
  kind: 'tokenize' | 'redirect' | 'qr'
  amount_satang: number
  public_key: string | null
  redirect_url: string | null
  extra: Record<string, unknown>
}
/**
 * 2026-09-03 — the ONLINE gateway this company has switched on, by name.
 *
 * Named without being started. Starting it opens a chargeable session at the
 * provider, which must not happen just because somebody loaded the page —
 * see POST /pay/{token}/intent.
 */
interface OnlineGateway {
  provider: string
  label: string
  /** 'test' | 'live' — of the GATEWAY, known before any charge is stamped. */
  mode: string
}
interface PublicGateway {
  provider: string | null
  /** 'test' | 'live' — shown to the customer, because a test charge is not a purchase. */
  mode: string | null
  /** The slip / PromptPay flow is open. Always true while an order is payable. */
  transfer_available: boolean
  /** null = this company takes no card payments right now. */
  online: OnlineGateway | null
  /**
   * Money has arrived through the gateway.
   *
   * Read INSTEAD of `status` when deciding whether to offer payment: the
   * charge is recorded before the order is confirmed, so this can be true
   * while status is still 'pending'. Offering a card form in that gap would
   * charge somebody twice.
   */
  payment_received: boolean
  /*
   * 2026-09-10 (human, testing Stripe's card_declined number: "ผมทดสอบ stripe
   * แบบ card_declined ให้ผิด แต่หน้า frontend ยังขึ้นให้บัตรอยู่").
   *
   * The refusal WAS recorded — the webhook wrote it onto the order and told
   * the agent — and then never reached the one person it was about. The
   * customer came back to a page that looked exactly as it had before, still
   * offering the card button, with nothing anywhere saying an attempt had
   * been made. The obvious next move is to try the same card again.
   */
  last_error: string | null
  last_error_at: string | null
  intent: PaymentIntent | null
}
interface PublicOrder {
  order_number: string
  amount_satang: number
  amount_baht: number
  payment_method: 'bank_transfer' | 'promptpay'
  payment_method_label: string
  status: OrderStatus
  status_label: string
  product_name: string | null
  client_name: string | null
  company_payment: CompanyPayment
  promptpay_payload: string
  // ADR-027 (TASK-139) — which gateway this order is being paid through.
  gateway: PublicGateway
  // ADR-033 (TASK-189) §2.5/D3 — whether the pay page must render the
  // shipping-address form, and the current values (so a customer who
  // already filled it in sees them on a re-visit).
  requires_shipping: boolean
  shipping_recipient_name: string | null
  shipping_phone: string | null
  shipping_address: string | null
  // ADR-033 §2.4/E1 — absent (not null) until paid + issued.
  voucher?: PublicVoucher | null
  // TASK-159 §3 — the theme of the company that owns this order, same
  // shape as GET /public/theme/{slug}.
  theme: Theme | null
}

type PageState = 'loading' | 'ready' | 'not_found' | 'error'
const pageState = ref<PageState>('loading')
const order = ref<PublicOrder | null>(null)
const qrDataUrl = ref('')
// ADR-033 (TASK-189) §2.4/E1 — the voucher's redemption-code QR, separate
// from the PromptPay payment QR above (different payload, different
// lifetime — this one only exists once the order is already paid).
const voucherQrDataUrl = ref('')

const MAX_SLIP_BYTES = 5 * 1024 * 1024 // 5MB (client-side guard; server re-validates)

async function loadOrder() {
  pageState.value = 'loading'
  try {
    const res = await api.get<{ data: PublicOrder }>(`/pay/${token}`)
    // TASK-159 §4.2 — theme FIRST, then reveal. The branded card (logo,
    // colours, font) is rendered only once `pageState !== 'loading'`, so
    // applying here means a paying customer never watches platform slate
    // flip into the company's brand — the worst place in the product for
    // that to happen. Ordering, not timing: no race to lose.
    themeStore.applyResolved(res.data.theme)
    order.value = res.data
    // E2 — pre-fill the shipping form with whatever's already on the
    // order (a customer who filled it in on a previous visit, or an
    // agent-collected value) so a re-visit never blanks it.
    shippingRecipientName.value = res.data.shipping_recipient_name ?? ''
    shippingPhone.value = res.data.shipping_phone ?? ''
    shippingAddress.value = res.data.shipping_address ?? ''
    pageState.value = 'ready'
    await renderQr()
    await renderVoucherQr()
  } catch (e) {
    pageState.value = e instanceof ApiError && e.status === 404 ? 'not_found' : 'error'
  }
}
onMounted(loadOrder)

async function renderQr() {
  // The payload's presence IS the condition — the server builds one whenever
  // the company has a PromptPay id, and no longer asks what the agent picked
  // when the order was created.
  const payload = order.value?.promptpay_payload
  if (!payload) {
    qrDataUrl.value = ''
    return
  }
  try {
    qrDataUrl.value = await QRCode.toDataURL(payload, { margin: 1, width: 240 })
  } catch {
    qrDataUrl.value = '' // fall back to the copyable payment details below
  }
}

async function renderVoucherQr() {
  const code = order.value?.voucher?.code
  // Level H and a real quiet zone: this QR is scanned off a cracked screen, a
  // twice-forwarded screenshot, or an office laser print — see generateQrDataUrl.
  voucherQrDataUrl.value = code ? await generateQrDataUrl(code, 220, { level: 'H', margin: 2 }) : ''
}

// ── Downloadable voucher card (TASK-192) ────────────────────────────────────
// One branded PNG, generated entirely client-side onto an off-screen canvas
// and downloaded via the same "toDataURL → <a download>" mechanic
// ShareLinkModal.downloadQr() already uses. Reuses the page's OWN QR
// (voucherQrDataUrl) and formatter functions verbatim (spec §1) rather than
// re-deriving anything — this is a second ARTIFACT of the same on-screen
// data, not a second rendering of it.
const voucherCardGenerating = ref(false)

/**
 * Load an image and resolve/reject, never throw synchronously. `crossOrigin`
 * is set BEFORE `src` (per spec) so a remote `Storage::url()` logo doesn't
 * taint the canvas — harmless no-op for the QR's own data: URL.
 */
function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('image load failed'))
    img.src = src
  })
}

/**
 * 2026-08-17 bugfix: the redemption code is a 40-char random string
 * (Str::random(40)) with no spaces, so `ctx.fillText` draws it as one
 * unbroken run that overshoots the canvas width. `ctx.font` must already be
 * set on `ctx` before calling this (measurements use the active font).
 * Greedy char-by-char wrap — correct for a no-spaces string where word-break
 * wrapping doesn't apply.
 */
function wrapTextToLines(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const lines: string[] = []
  let current = ''
  for (const char of text) {
    const candidate = current + char
    if (current !== '' && ctx.measureText(candidate).width > maxWidth) {
      lines.push(current)
      current = char
    } else {
      current = candidate
    }
  }
  if (current !== '') lines.push(current)
  return lines
}

async function downloadVoucherCard() {
  const ord = order.value
  const voucher = ord?.voucher
  if (!ord || !voucher || voucherCardGenerating.value) return

  voucherCardGenerating.value = true
  try {
    const width = 600
    const height = 900
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    if (!ctx) return

    // Background + accent stripe.
    ctx.fillStyle = '#1b1b2b'
    ctx.fillRect(0, 0, width, height)
    ctx.fillStyle = '#c9a961'
    ctx.fillRect(0, 0, width, 10)

    ctx.textAlign = 'center'
    let y = 80

    // Logo — best-effort only. A missing config or a failed/blocked load
    // (network, CORS) must never stop the card from rendering text-only.
    const logoUrl = themeStore.navLogo ?? themeStore.loginLogo
    if (logoUrl) {
      try {
        const logoImg = await loadImage(logoUrl)
        const maxH = 64
        const scale = maxH / logoImg.height
        const w = logoImg.width * scale
        ctx.drawImage(logoImg, (width - w) / 2, y, w, maxH)
        y += maxH + 32
      } catch {
        // logo failed to load — fall through to text-only, no card change.
      }
    }

    // Company name.
    ctx.fillStyle = '#f5efe0'
    ctx.font = 'bold 28px "Kanit", sans-serif'
    ctx.fillText(themeStore.theme?.company?.name ?? '', width / 2, y + 28)
    y += 72

    // Product name.
    if (ord.product_name) {
      ctx.fillStyle = '#c9a961'
      ctx.font = '20px "Kanit", sans-serif'
      ctx.fillText(ord.product_name, width / 2, y)
      y += 44
    }

    ctx.fillStyle = '#9c9cb0'
    ctx.font = 'bold 16px "Kanit", sans-serif'
    ctx.fillText('บัตรกำนัลใช้บริการ', width / 2, y)
    y += 40

    // Voucher QR — the SAME data URL already rendered on screen
    // (voucherQrDataUrl), never regenerated from a different source.
    if (voucherQrDataUrl.value) {
      try {
        const qrImg = await loadImage(voucherQrDataUrl.value)
        const qrSize = 260
        ctx.fillStyle = '#ffffff'
        ctx.fillRect((width - qrSize) / 2 - 12, y - 12, qrSize + 24, qrSize + 24)
        ctx.drawImage(qrImg, (width - qrSize) / 2, y, qrSize, qrSize)
        y += qrSize + 48
      } catch {
        // QR failed to decode into an Image — skip it, the code text below
        // still identifies the voucher.
      }
    }

    /*
     * Voucher code. Still wrapped, still via wrapTextToLines(): a legacy
     * 40-character code overshoots a fixed fillText call, and those cards are
     * still being downloaded by customers who bought before 2026-09-10.
     *
     * A short code gets the larger type and the grouping, because on a card
     * somebody holds up at a counter it is the ONLY thing that matters — the
     * QR is a convenience, the code is the fallback when the screen is cracked
     * or the photo is a screenshot in a chat.
     */
    ctx.fillStyle = '#ffffff'
    const shortCode = isShortVoucherCode(voucher.code)
    ctx.font = shortCode ? 'bold 46px "Kanit", monospace' : 'bold 26px "Kanit", monospace'
    const codeLines = wrapTextToLines(ctx, formatVoucherCode(voucher.code), width - 80)
    for (const line of codeLines) {
      ctx.fillText(line, width / 2, y)
      y += shortCode ? 54 : 32
    }
    y += 24

    // Quota + expiry — same formatters the on-screen block uses verbatim.
    ctx.font = '18px "Kanit", sans-serif'
    ctx.fillStyle = '#d4d4de'
    ctx.fillText(`สิทธิ์การใช้งาน: ${formatVoucherQuota(voucher)}`, width / 2, y)
    y += 30
    ctx.fillText(`วันหมดอายุ: ${formatVoucherExpiry(voucher)}`, width / 2, y)
    y += 30

    // Status label — only when not active, matching the on-screen conditional.
    if (voucher.status !== 'active') {
      y += 20
      ctx.fillStyle = '#fb7185'
      ctx.font = 'bold 20px "Kanit", sans-serif'
      ctx.fillText(voucher.status_label, width / 2, y)
    }

    const dataUrl = canvas.toDataURL('image/png')
    const a = document.createElement('a')
    a.href = dataUrl
    a.download = `voucher-${ord.order_number}.png`
    a.click()
  } catch {
    // Never let a canvas/draw error surface to the user — the on-screen
    // voucher block is unaffected either way.
  } finally {
    voucherCardGenerating.value = false
  }
}

function formatBaht(baht: number): string {
  return '฿' + baht.toLocaleString('th-TH')
}

// ADR-033 §2.2/E1 — Thai copy for the two nullable voucher fields per
// TASK-189 §6 E1: null usage_quota reads "ไม่จำกัด" (unlimited), null
// expires_at reads "ไม่มีวันหมดอายุ" (never expires).
function formatVoucherQuota(v: PublicVoucher): string {
  return v.usage_quota === null ? 'ไม่จำกัด' : `${v.used_count} / ${v.usage_quota}`
}
function formatVoucherExpiry(v: PublicVoucher): string {
  if (v.expires_at === null) return 'ไม่มีวันหมดอายุ'
  return new Date(v.expires_at).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' })
}

// ── Copy account number ─────────────────────────────────────────────────────
const copied = ref(false)
async function copyAccount() {
  const acct = order.value?.company_payment.bank_account_number
  if (!acct) return
  try {
    await navigator.clipboard.writeText(acct)
    copied.value = true
    setTimeout(() => (copied.value = false), 1800)
  } catch {
    // Clipboard blocked — the number is displayed for manual copy.
  }
}

// ── Slip upload ─────────────────────────────────────────────────────────────
const fileInputEl = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const previewUrl = ref('')
const uploadError = ref('')
const uploading = ref(false)
const uploaded = ref(false)

// ADR-033 (TASK-189) §2.5/E2 — shipping-address form, collected in the
// SAME request as the slip upload (D1's extended SubmitSlipRequest), the
// "one door" ADR-033 describes rather than a second form on a second
// path. Only rendered when order.requires_shipping is true; required
// client-side ONLY in that case — a non-physical product must never be
// blocked on these three fields.
const shippingRecipientName = ref('')
const shippingPhone = ref('')
const shippingAddress = ref('')
const shippingValid = computed(() => {
  if (!order.value?.requires_shipping) return true
  return (
    shippingRecipientName.value.trim() !== '' &&
    shippingPhone.value.trim() !== '' &&
    shippingAddress.value.trim() !== ''
  )
})

async function onFilePicked(event: Event) {
  uploadError.value = ''
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  if (!file) return
  if (!file.type.startsWith('image/')) {
    uploadError.value = 'กรุณาเลือกไฟล์รูปภาพ (JPG / PNG)'
    return
  }
  // Best-effort client-side shrink, then hard-check the 5MB cap.
  const prepared = await compressImageToFit(file, MAX_SLIP_BYTES)
  if (prepared.size > MAX_SLIP_BYTES) {
    uploadError.value = 'ไฟล์มีขนาดใหญ่เกิน 5MB กรุณาเลือกรูปที่เล็กลง'
    return
  }
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  selectedFile.value = prepared
  previewUrl.value = URL.createObjectURL(prepared)
}

async function uploadSlip() {
  if (uploading.value || !selectedFile.value || !shippingValid.value) return
  uploadError.value = ''
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('slip', selectedFile.value)
    // ADR-033 §2.5/D1 — sent whenever filled in, not only when
    // requires_shipping (the field is genuinely optional for a
    // non-physical product per D2 — omitting empty values just avoids
    // sending three blank strings on every non-shipping order).
    if (shippingRecipientName.value.trim()) fd.append('shipping_recipient_name', shippingRecipientName.value.trim())
    if (shippingPhone.value.trim()) fd.append('shipping_phone', shippingPhone.value.trim())
    if (shippingAddress.value.trim()) fd.append('shipping_address', shippingAddress.value.trim())
    const res = await api.postForm<{ data: PublicOrder }>(`/pay/${token}/slip`, fd)
    order.value = res.data
    uploaded.value = true
  } catch (e) {
    if (e instanceof ApiError && e.body && typeof e.body === 'object') {
      const body = e.body as { message?: string; errors?: Record<string, string[]> }
      uploadError.value =
        body.message ?? Object.values(body.errors ?? {})[0]?.[0] ?? 'อัปโหลดสลิปไม่สำเร็จ กรุณาลองใหม่'
    } else {
      uploadError.value = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่'
    }
  } finally {
    uploading.value = false
  }
}

/*
 * ══ 2026-09-11 — THE PAGE NO LONGER SENDS THE CUSTOMER ANYWHERE ══
 *
 * (human: "เมื่อชำระเสร็จแล้ว ส่ง email ให้ลูกค้าต้องไม่ติด Login สามารถดูได้
 * เหมือนหน้าชำระสำเร็จ")
 *
 * ── WHAT WAS HERE, AND WHY IT HAD TO GO ──
 *
 * A day earlier the same person asked for the opposite, and they were right
 * about the problem: "เมื่อชำระแล้วไม่มีปุ่มไปไหนเลย ต้องมีปุ่มกลับหน้า
 * Frontend ตั้งเวลา 90 วินาทีกลับอัตโนมัติ". So this page grew a "กลับหน้าหลัก"
 * button and a 90-second timer, both of which called
 * `window.location.assign('/')`.
 *
 * `/` is the AGENT PORTAL's dashboard. It is not a public route (see
 * router/index.ts — every page except the token pages requires auth), so the
 * router bounced the visitor straight to /login.
 *
 * Which means: a customer paid ฿8,900, was shown a voucher code and a QR, put
 * their phone down for ninety seconds — and the page replaced their receipt
 * with a login form for a system they have no account on and never will. The
 * dead end was real; what replaced it was worse, because the old one at least
 * still had the voucher on it.
 *
 * ── WHY NOT JUST POINT IT SOMEWHERE ELSE ──
 *
 * Because there is nowhere to point it. Every public route in this app is a
 * token page belonging to somebody — /p/:token, /l/:token, /pay/:token — and
 * an order does not record which share link it came from, so we cannot even
 * send them back to the product they just bought. There is no public company
 * landing page in this system to return to. Inventing a destination would
 * mean inventing a page.
 *
 * ── SO THE PAGE IS THE DESTINATION ──
 *
 * That is the honest answer, not a fallback. `/pay/{token}` is permanent,
 * public and unauthenticated: the same link is in the confirmation email, and
 * it renders exactly what is on screen now — the code, the QR, the remaining
 * uses. A customer who closes the tab opens the email; a customer who keeps
 * it open keeps the thing they paid for. What replaces the button is a line
 * saying so, because "you can come back to this" is the reassurance the
 * button was standing in for.
 */

const isPaid = computed(() => order.value?.status === 'paid')
const isCancelled = computed(() => order.value?.status === 'cancelled')
// Awaiting verification means a slip is already in — either just uploaded or
// previously submitted. Hide the upload form and show the "waiting" notice.
const awaitingVerification = computed(() => uploaded.value || order.value?.status === 'awaiting_verification')
/*
 * 2026-09-10 — `showUploadForm` is gone.
 *
 * It existed because the slip picker was rendered unconditionally on any
 * unpaid order, so it needed its own "is this order still payable" test. The
 * picker now lives inside the transfer panels, which only open when a
 * transfer method has been chosen — and the chooser itself already answers
 * paid/cancelled/slip-received. One condition, in one place.
 */

// ── Card payment (ADR-027 / TASK-139) ───────────────────────────────────────
//
// A customer without a card, or one whose card is declined, still has the
// account number and the slip upload they have always had — the card is one
// row in a list of methods, not a replacement for the rail most people on
// this platform actually use.
const cardError = ref('')
const charging = ref(false)

/** True the moment money has arrived, even before the order says 'paid'. */
const paymentReceived = computed(() => order.value?.gateway.payment_received === true)

/**
 * The last card attempt was refused, and this customer still owes money.
 *
 * 2026-09-10. Guarded on the order NOT being settled: a failed attempt
 * followed by a successful one leaves the old message on the row, and showing
 * "your card was declined" above a paid order would be worse than showing
 * nothing at all.
 */
const lastPaymentError = computed(() => {
  if (isFinished.value) return ''

  return order.value?.gateway.last_error ?? ''
})

/*
 * Back from the gateway without paying.
 *
 * Stripe sends the customer to `cancel_url` when they leave its page — which
 * is what a person does after their card is refused, since Checkout keeps them
 * there with an error rather than redirecting. In that case NO webhook fires
 * at all, so `last_error` above is empty and this query parameter is the only
 * evidence the attempt ever happened.
 *
 * Deliberately worded as "not paid", not as "declined": leaving a payment page
 * and being refused by a bank are different facts, and this parameter cannot
 * tell them apart. It says what is certainly true and names the two ways
 * forward.
 */
const returnedUnpaid = computed(() => route.query.stripe === 'cancelled' && !isFinished.value)

/**
 * The page is finished with the customer once the money is in — whether the
 * order has been marked paid yet or not. `payment_received` is the earlier of
 * the two and is exactly the state the dead end was reported from: charged,
 * waiting for confirmation, nothing to press.
 */
const isFinished = computed(() => isPaid.value || paymentReceived.value)

/** The card form is offered only when the SERVER says a charge is possible. */
const cardIntent = computed(() => {
  const gateway = order.value?.gateway
  if (!gateway || gateway.intent?.kind !== 'tokenize' || !gateway.intent.public_key) return null
  return gateway.intent
})
/**
 * 2026-08-27 — the REDIRECT flow (Stripe Checkout).
 *
 * Some providers do not tokenise in our page at all: they host the payment
 * page themselves and we send the customer there. The server already says
 * which shape it wants via `intent.kind`, so this view only has to honour
 * it — nothing here names Stripe, and a second redirect-based provider
 * needs no change at all.
 */
const redirectIntent = computed(() => {
  const gateway = order.value?.gateway
  if (!gateway || gateway.intent?.kind !== 'redirect' || !gateway.intent.redirect_url) return null
  return gateway.intent
})

/**
 * Leave for the provider's page.
 *
 * A full navigation, not window.open: a popup is blocked on most phones,
 * and a payment flow that silently does nothing when tapped is worse than
 * one that takes over the tab. The customer comes back to this same page
 * afterwards (success_url), where the order's own state — not the return
 * URL — decides what they are shown.
 */
function payByRedirect() {
  const intent = redirectIntent.value
  if (!intent?.redirect_url || charging.value) return

  charging.value = true
  window.location.href = intent.redirect_url
}

/**
 * The gateway the customer MAY pay with — or null.
 *
 * Not a decision this page makes: the server weighs the company's settings,
 * the order's status and whether money has already arrived, and answers with
 * a name or with nothing.
 */
const onlineGateway = computed(() => order.value?.gateway.online ?? null)

/** Neither the card form nor the redirect has been opened yet. */
const showMethodChooser = computed(
  () => !cardIntent.value
    && !redirectIntent.value
    && !paymentReceived.value
    // 2026-09-10 — and not once a slip is in. The customer has done their
    // part and somebody is looking at it; re-offering the payment methods
    // reads as "that did not work, try again".
    && !awaitingVerification.value,
)

/*
 * ────────────────────────────────────────────────────────────────────────
 * 2026-09-10 — ONE CHOICE, THEN ONE PANEL (human: "Ui หน้านี้ไม่สากลเลย
 * ปรับให้เป็นมาตรฐานการชำระเงิน ให้เลือกวิธีชำระ หรือโอนผ่าน qr code").
 *
 * The page used to render every payment path at once: the chooser, the
 * PromptPay QR, the bank account, and the slip upload, stacked down the
 * screen whether or not the customer wanted any of them. Nothing said where
 * to start, and the two options were not even the same shape — the card was
 * a button and "transfer" was a paragraph, so only one of them looked like a
 * choice.
 *
 * Now it is the shape every checkout uses: pick a method, and only that
 * method's panel opens. Three equal rows, and a method the company has not
 * configured is not shown at all — an account number rendered as "—" is a
 * page that asks for money and will not say where to send it.
 * ────────────────────────────────────────────────────────────────────────
 */
type PaymentMethodKey = 'card' | 'promptpay' | 'bank'

/** The card rail, only when the company has a live gateway for it. */
const cardAvailable = computed(() => !!onlineGateway.value)

/** PromptPay, only when there is a payload to turn into a QR. */
const promptPayAvailable = computed(() => !!order.value?.promptpay_payload)

/**
 * Bank transfer, only when there is genuinely an account to transfer to.
 *
 * The account NUMBER is the test, not the bank name: a row that names a bank
 * and shows a dash where the number goes is worse than no row at all.
 */
const bankAvailable = computed(() => !!order.value?.company_payment.bank_account_number)

const availableMethods = computed<PaymentMethodKey[]>(() => {
  const methods: PaymentMethodKey[] = []
  if (cardAvailable.value) methods.push('card')
  if (promptPayAvailable.value) methods.push('promptpay')
  if (bankAvailable.value) methods.push('bank')

  return methods
})

/**
 * Nothing to offer. Said out loud rather than rendered as an empty card: the
 * customer cannot fix it and should be told to contact the seller, and the
 * seller finds out because their customer tells them.
 */
const noMethodAvailable = computed(() => availableMethods.value.length === 0)

const selectedMethod = ref<PaymentMethodKey | null>(null)

function chooseMethod(method: PaymentMethodKey): void {
  selectedMethod.value = method
  cardError.value = ''
  uploadError.value = ''
}

/*
 * Pre-select ONLY when there is exactly one method.
 *
 * With a real choice, nothing is chosen for the customer: a pre-ticked
 * payment method is a decision made on somebody's behalf about their money,
 * and the one the page happens to list first is not the one they want often
 * enough to be worth it. With a single method there is no choice to make, and
 * an unopened accordion would just be an extra tap.
 */
watch(availableMethods, (methods) => {
  if (methods.length === 1 && selectedMethod.value === null) {
    selectedMethod.value = methods[0] ?? null
  }
}, { immediate: true })

/** Both transfer rails end the same way: the customer sends money, then a slip. */
const transferChosen = computed(() => selectedMethod.value === 'promptpay' || selectedMethod.value === 'bank')

/*
 * ── ONE ACTION, PINNED TO THE BOTTOM ─────────────────────────────────────
 *
 * Every checkout worth copying ends in a single primary button that is always
 * reachable. This page had two — a card button half way up and a slip button
 * at the very bottom — and on a phone the second one was below the fold
 * behind an account number and a file picker, so the last step of a purchase
 * was the one you had to go looking for.
 *
 * The bar shows only while there is something to press: not on a paid order,
 * not while a slip is being checked, and not when there is no method to
 * choose.
 */
const showActionBar = computed(
  () => showMethodChooser.value && !noMethodAvailable.value && selectedMethod.value !== null,
)

const actionLabel = computed(() => {
  if (transferChosen.value) {
    return uploading.value ? td('pay.sending_slip') : td('pay.send_slip')
  }

  return startingOnline.value
    ? td('pay.online_starting')
    : td('pay.pay_amount', '', { amount: formatBaht(order.value?.amount_baht ?? 0) })
})

/**
 * Disabled says WHY, by never being the only signal: the reasons are also
 * written on screen (the address step, the slip picker), so a greyed-out
 * button is a confirmation rather than a puzzle.
 */
const actionDisabled = computed(() => {
  if (!shippingValid.value) return true
  if (transferChosen.value) return !selectedFile.value || uploading.value

  return startingOnline.value || charging.value
})

function runPrimaryAction(): void {
  if (transferChosen.value) {
    void uploadSlip()

    return
  }

  void startOnlinePayment()
}

const methodLabels: Record<PaymentMethodKey, { title: string; hint: string; icon: string }> = {
  card: { title: 'pay.method_card', hint: 'pay.method_card_hint', icon: 'credit_card' },
  promptpay: { title: 'pay.method_promptpay', hint: 'pay.method_promptpay_hint', icon: 'qr_code' },
  bank: { title: 'pay.method_bank', hint: 'pay.method_bank_hint', icon: 'money' },
}

/**
 * The address is asked for FIRST now, not folded into the slip upload.
 *
 * It has to be, once a card is payable: the card path never touches the slip
 * form, so a physical product bought with a card was never given an address
 * at all (see StartOnlinePaymentRequest). Asking before the method is chosen
 * is also simply where every checkout asks.
 */
const showShippingStep = computed(
  () => order.value?.requires_shipping === true && !isFinished.value && !awaitingVerification.value,
)

/** @returns the shipping fields that have been filled in, ready to send. */
function shippingPayload(): Record<string, string> {
  const payload: Record<string, string> = {}
  if (shippingRecipientName.value.trim()) payload.shipping_recipient_name = shippingRecipientName.value.trim()
  if (shippingPhone.value.trim()) payload.shipping_phone = shippingPhone.value.trim()
  if (shippingAddress.value.trim()) payload.shipping_address = shippingAddress.value.trim()

  return payload
}

/**
 * Copy the amount.
 *
 * Small, and the single most-mistyped thing on this page: a transfer for
 * 2,990 instead of 29,900 is a refund, a phone call and a re-payment. Digits
 * only — a banking app will not take "฿29,900.00".
 */
const amountCopied = ref(false)
async function copyAmount(): Promise<void> {
  const satang = order.value?.amount_satang
  if (satang === undefined) return

  const plain = (satang / 100).toFixed(2).replace(/\.00$/, '')
  try {
    await navigator.clipboard.writeText(plain)
    amountCopied.value = true
    window.setTimeout(() => { amountCopied.value = false }, 2000)
  } catch {
    // Clipboard blocked (insecure context, or a browser that asks). The
    // number is on screen and selectable; a red error for a convenience
    // would be worse than the convenience is good.
  }
}

/**
 * Save the PromptPay QR as an image.
 *
 * Scanning a QR from the same phone that is showing it is impossible, and
 * that is the common case: the customer is on their phone and the banking app
 * is on the same phone. Every Thai banking app can open a QR from the photo
 * library, so the way through is to save it there.
 */
function saveQrImage(): void {
  if (!qrDataUrl.value || !order.value) return

  const link = document.createElement('a')
  link.href = qrDataUrl.value
  link.download = `promptpay-${order.value.order_number}.png`
  document.body.appendChild(link)
  link.click()
  link.remove()
}

/**
 * Test mode, read from the GATEWAY before a charge exists.
 *
 * `gateway.mode` describes the order's own stamp, which says 'live' until the
 * customer picks a card — so relying on it alone would hide the test-mode
 * warning on the one screen where the customer is deciding whether to type a
 * real card number.
 */
const isTestMode = computed(
  () => (onlineGateway.value?.mode ?? order.value?.gateway.mode) === 'test',
)

const startingOnline = ref(false)

/**
 * The customer chose to pay online.
 *
 * This is the request that opens the payment at the provider and stamps the
 * order with the gateway now taking its money — which is why it is a POST
 * made on a press, and not part of loading this page. Doing it on load would
 * open a checkout session for every visitor, including the majority who
 * transfer instead, and every one of those would later expire and report the
 * customer as not having paid.
 *
 * A redirect gateway leaves immediately; a tokenising one reveals its card
 * form, which the customer then submits with payByCard().
 */
async function startOnlinePayment() {
  if (startingOnline.value || charging.value) return

  /*
   * 2026-09-10 — refuse before leaving, not after coming back.
   *
   * The gateway takes the customer to another site. If the address were
   * missing, the server would refuse this request and the message would land
   * on a page the customer has already left behind.
   */
  if (!shippingValid.value) {
    cardError.value = td('pay.shipping_required')

    return
  }

  cardError.value = ''
  startingOnline.value = true
  try {
    // The address travels with the request that opens the payment — the card
    // path never touches the slip form, which is where it used to be
    // collected (see StartOnlinePaymentRequest).
    const res = await api.post<{ data: PublicOrder }>(`/pay/${token}/intent`, shippingPayload())
    order.value = res.data

    // Leave straight away. The button the customer already pressed IS the
    // consent to go; making them press a second one on the next screen loses
    // sales for no gain.
    if (redirectIntent.value) payByRedirect()
  } catch (e) {
    if (e instanceof ApiError && e.body && typeof e.body === 'object') {
      const body = e.body as { message?: string; errors?: Record<string, string[]> }
      // The server's own reason — "ร้านค้ายังไม่เปิดรับชำระด้วยบัตร" — because
      // it tells the customer what to do instead, and the transfer details
      // are already on the same screen.
      cardError.value = body.errors?.gateway?.[0] ?? body.message ?? td('pay.online_failed')
    } else {
      cardError.value = td('pay.online_failed')
    }
  } finally {
    startingOnline.value = false
  }
}

async function payByCard() {
  const intent = cardIntent.value
  const ord = order.value
  if (!intent?.public_key || !ord || charging.value) return

  cardError.value = ''
  charging.value = true
  try {
    // Named cardToken, not token: `token` in this file is the pay LINK's
    // token, and two different secrets sharing one name in one function is
    // how the wrong one ends up in a URL.
    const cardToken = await openCardForm({
      publicKey: intent.public_key,
      // BR-3 — satang all the way through, no conversion at any layer.
      amountSatang: intent.amount_satang,
      description: ord.product_name ?? ord.order_number,
      merchantLabel: themeStore.theme?.company?.name ?? '',
    })

    // null = the customer closed the form. Not a failure, and showing a red
    // message for it would tell them they had done something wrong.
    if (cardToken === null) return

    const res = await api.post<{ data: PublicOrder }>(`/pay/${token}/charge`, {
      payment_token: cardToken,
    })
    order.value = res.data
    await renderVoucherQr()
  } catch (e) {
    if (e instanceof ApiError && e.body && typeof e.body === 'object') {
      const body = e.body as { message?: string; errors?: Record<string, string[]> }
      // The provider's own decline reason, surfaced verbatim: "ยอดเกินวงเงิน"
      // is something only the cardholder can act on, and a generic failure
      // turns a fixable problem into an abandoned sale.
      cardError.value =
        body.errors?.payment_token?.[0] ?? body.message ?? 'ชำระเงินไม่สำเร็จ กรุณาลองใหม่หรือใช้บัตรอื่น'
    } else {
      cardError.value = e instanceof Error ? e.message : 'ชำระเงินไม่สำเร็จ กรุณาลองใหม่'
    }
  } finally {
    charging.value = false
  }
}
</script>

<template>
  <!-- TASK-159 §4.1/§4.2 — the page surface was a hardcoded neutral
       gradient. It is now the `surface-app` token (derived from the
       company's background, falling back to its CARD colour) with the
       company's own image/gradient layered on top when configured — the
       same two-layer model App.vue uses. Full-bleed; not the phone shell. -->
  <div
    class="min-h-screen w-full flex items-center justify-center p-4 sm:p-8 font-sans bg-surface-app"
    :style="themeStore.companyBackgroundStyle"
  >
    <!-- Loading sits OUTSIDE the card, deliberately (TASK-159 §4.2). The
         card carries the company's logo, card colour and font, so
         rendering it before the theme resolves is exactly the flash of
         platform default this task exists to remove. A beat of a bare
         loading line is the cheaper trade. -->
    <p v-if="pageState === 'loading'" class="text-sm text-ink-app-muted">{{ td('common.loading2') }}</p>

    <div v-else class="w-full max-w-md rounded-[28px] bg-surface-card shadow-xl border border-line-card/80 overflow-hidden p-6 sm:p-8" :class="showActionBar ? 'mb-24' : ''">
      <div class="flex items-center justify-between">
        <AppLogo mode="wordmark" :height="28" />
        <span class="inline-flex items-center gap-1 text-xs font-bold text-ink-card-subtle">
          <Icon name="money" :size="14" />
          {{ td('pay.title') }}
        </span>
      </div>

      <!-- Invalid / expired token -->
      <div v-if="pageState === 'not_found'" class="mt-10 py-6 text-center">
        <div class="mx-auto w-14 h-14 rounded-full border border-rose-100 flex items-center justify-center">
          <Icon name="alert" :size="24" class="text-ink-danger" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">{{ td('pay.link_invalid') }}</h2>
        <p class="mt-2 text-sm text-ink-card-muted">{{ td('public.ask_member_new_link') }}</p>
      </div>

      <!-- Network / server error -->
      <div v-else-if="pageState === 'error'" class="mt-10 py-6 text-center">
        <p class="text-sm text-ink-danger">{{ td('common.error_network2') }}</p>
        <button type="button" class="mt-3 text-sm font-bold text-ink-brand hover:underline" @click="loadOrder">
          {{ td('common.try_again') }}
        </button>
      </div>

      <!-- Ready -->
      <div v-else-if="order" class="mt-6 space-y-5">
        <!-- Amount summary.
             2026-09-10 — the amount gained a copy button. It is the single
             most-mistyped thing on this page, and 2,990 typed instead of
             29,900 is a refund, a phone call and a second payment. -->
        <div class="text-center">
          <p class="text-sm text-ink-card-muted">{{ order.product_name ?? td('pay.title') }}</p>
          <p class="mt-1 text-3xl font-bold text-ink-card">{{ formatBaht(order.amount_baht) }}</p>
          <button
            v-if="!isFinished"
            type="button"
            class="mt-1 text-xs font-bold text-ink-brand inline-flex items-center gap-1"
            data-test="copy-amount"
            @click="copyAmount"
          >
            <Icon name="copy" :size="13" />
            {{ amountCopied ? td('common.copied') : td('pay.copy_amount') }}
          </button>
          <p class="mt-1 text-xs text-ink-card-subtle">{{ td('order.number', '', { number: order.order_number }) }}</p>
        </div>

        <!-- Paid state -->
        <div v-if="isPaid" class="py-4 text-center">
          <div class="mx-auto w-14 h-14 rounded-full border border-emerald-100 flex items-center justify-center">
            <Icon name="check" :size="24" class="text-ink-success" />
          </div>
          <h2 class="mt-4 text-lg font-bold text-ink-card">{{ td('pay.done') }}</h2>
          <p class="mt-1 text-sm text-ink-card-muted">{{ td('pay.thanks') }}</p>

          <!-- ADR-033 (TASK-189) §2.4/E1 — service-access voucher, rendered
               once paid AND a voucher was actually issued (older/legacy
               paid orders predate this feature and carry none). Analogous
               to a hotel voucher per the human's own framing (ADR-033) —
               the code + QR the customer presents to redeem the service at
               any branch.
               2026-08-17 bugfix: this block used to open its own sibling
               v-if instead of nesting inside isPaid, which pulled the
               isCancelled/v-else chain below off of ITS v-if instead of
               off isPaid — so a paid order with no voucher (or before this
               nesting fix, silently for every paid order rendering-order
               reasons) fell through to the "awaiting payment" QR/bank-
               transfer/slip-upload UI. Nesting here keeps one single
               isPaid / isCancelled / v-else chain. -->
          <div v-if="order.voucher" class="mt-4 rounded-2xl border border-line-card p-4 flex flex-col items-center gap-3 text-center">
            <p class="text-sm font-bold text-ink-card flex items-center gap-1.5">
              <Icon name="qr_code" :size="20" class="text-ink-brand" /> {{ td('pay.voucher') }}
            </p>
            <img v-if="voucherQrDataUrl" :src="voucherQrDataUrl" :alt="td('pay.voucher_code')" class="w-48 h-48" />
            <!-- 2026-09-10 — the code is now SIX characters (human: staff key
                 it in by hand), so it is finally allowed to be the largest
                 thing on the card: big, spaced, and grouped ABC-123 so it can
                 be copied in one glance or read down a phone line.
                 2026-08-17's break-all + small monospace stays as the branch
                 below, because a voucher issued before the change still
                 carries a 40-character token that overflows the card
                 otherwise. -->
            <p
              v-if="isShortVoucherCode(order.voucher.code)"
              class="w-full text-3xl font-bold font-mono tracking-[0.2em] text-ink-card"
              data-test="voucher-code"
            >{{ formatVoucherCode(order.voucher.code) }}</p>
            <p
              v-else
              class="w-full text-sm font-bold font-mono tracking-wide text-ink-card break-all"
              data-test="voucher-code"
            >{{ order.voucher.code }}</p>
            <div class="w-full grid grid-cols-2 gap-2 text-xs">
              <div class="rounded-xl bg-surface-chip p-2">
                <p class="text-ink-card-subtle">{{ td('pay.entitlement') }}</p>
                <p class="mt-0.5 font-bold text-ink-card">{{ formatVoucherQuota(order.voucher) }}</p>
              </div>
              <div class="rounded-xl bg-surface-chip p-2">
                <p class="text-ink-card-subtle">{{ td('common.expiry_date') }}</p>
                <p class="mt-0.5 font-bold text-ink-card">{{ formatVoucherExpiry(order.voucher) }}</p>
              </div>
            </div>
            <p v-if="order.voucher.status !== 'active'" class="text-xs font-bold text-ink-danger">
              {{ order.voucher.status_label }}
            </p>
            <p class="text-xs text-ink-card-subtle">{{ td('pay.voucher_help') }}</p>
            <!-- TASK-192 — downloadable branded PNG card (code + QR +
                 validity together), separate from the 3 TASK-191 share
                 buttons (which live elsewhere, out of scope here). -->
            <button
              type="button"
              :disabled="voucherCardGenerating"
              class="w-full min-h-[44px] py-2.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1.5"
              @click="downloadVoucherCard"
            >
              <Icon name="download" :size="16" />
              {{ voucherCardGenerating ? td('pay.voucher_generating') : td('pay.voucher_download') }}
            </button>
          </div>

        </div>

        <!-- Cancelled state -->
        <div v-else-if="isCancelled" class="py-4 text-center">
          <div class="mx-auto w-14 h-14 rounded-full border border-line-card flex items-center justify-center">
            <Icon name="x" :size="24" class="text-ink-card-subtle" />
          </div>
          <h2 class="mt-4 text-lg font-bold text-ink-card">{{ td('pay.order_cancelled') }}</h2>
          <p class="mt-1 text-sm text-ink-card-muted">{{ td('public.ask_member') }}</p>
        </div>

<template v-else>
          <!-- 2026-09-10 — the card was refused, said where the customer is
               standing. Above everything, because it is the reason they are
               looking at this page a second time. -->
          <div
            v-if="lastPaymentError || returnedUnpaid"
            class="rounded-2xl border border-rose-200 bg-surface-danger p-4 flex items-start gap-3"
            data-test="payment-failed-notice"
          >
            <Icon name="alert" :size="20" class="text-ink-danger shrink-0 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-ink-danger">{{ td('pay.attempt_failed') }}</p>
              <p v-if="lastPaymentError" class="mt-0.5 text-xs text-ink-card-muted" data-test="payment-failed-reason">
                {{ lastPaymentError }}
              </p>
              <p class="mt-1 text-xs text-ink-card-muted">{{ td('pay.attempt_failed_help') }}</p>
            </div>
          </div>

          <!-- Money has arrived but the order is not marked paid yet. Rare,
               and deliberately visible rather than hidden: the alternative is
               a customer who has been charged looking at a payment form. -->
          <div
            v-if="paymentReceived && !isPaid"
            class="rounded-2xl border border-brand-200 bg-brand-50 p-4 flex items-start gap-3"
          >
            <Icon name="check" :size="20" class="text-ink-brand shrink-0 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-ink-brand">{{ td('pay.received') }}</p>
              <p class="text-xs text-ink-card-muted">{{ td('pay.received_help') }}</p>
              <!-- 2026-09-10 (human: "ลูกค้าจะได้รหัสยืนยันใช้บริการได้อย่างไร").
                   The voucher is minted when staff CONFIRM the payment, not
                   when the money lands (ADR-033 §2.2/B1) — so there is a real
                   window where the customer has paid and there is genuinely
                   no code to show. Saying where it will appear is the only
                   honest thing to put in that gap. -->
              <p class="mt-1 text-xs text-ink-card-muted" data-test="voucher-pending-note">
                {{ td('pay.voucher_pending') }}
              </p>
            </div>
          </div>

          <!-- The slip is in and somebody is looking at it. -->
          <div v-if="awaitingVerification" class="rounded-2xl border border-brand-200 bg-brand-50 p-4 flex items-center gap-3">
            <Icon name="clock" :size="20" class="text-ink-brand shrink-0" />
            <div>
              <p class="text-sm font-bold text-ink-brand">{{ td('pay.slip_received') }}</p>
              <p class="text-xs text-ink-card-muted">{{ td('pay.slip_help') }}</p>
            </div>
          </div>

          <!-- ── STEP 1 — WHERE IT GOES ───────────────────────────────────
               2026-09-10 — moved OUT of the slip form and up here.
               ADR-033 §2.5/E2 collected this together with the slip ("one
               door"), which left a customer paying by card never asked for it
               at all: the order came back paid with nowhere to send the
               goods. It is also simply where a checkout asks — before the
               method, not after it. -->
          <div v-if="showShippingStep" class="rounded-2xl border border-line-card p-4 space-y-3" data-test="shipping-step">
            <div class="flex items-center gap-2">
              <span class="w-6 h-6 rounded-full bg-brand-600 text-ink-primary text-xs font-bold inline-flex items-center justify-center shrink-0">1</span>
              <p class="text-sm font-bold text-ink-card">{{ td('ship.title') }}</p>
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.recipient') }}</label>
              <input
                v-model="shippingRecipientName"
                type="text"
                required
                autocomplete="name"
                data-test="ship-name"
                class="mt-1 w-full min-h-[44px] px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card"
              />
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.recipient_phone') }}</label>
              <input
                v-model="shippingPhone"
                type="tel"
                inputmode="tel"
                required
                autocomplete="tel"
                class="mt-1 w-full min-h-[44px] px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card"
              />
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.address') }}</label>
              <textarea
                v-model="shippingAddress"
                required
                rows="3"
                autocomplete="street-address"
                class="mt-1 w-full px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card resize-none"
              ></textarea>
            </div>
          </div>

          <!-- ── STEP 2 — HOW IT IS PAID ──────────────────────────────────
               Three equal rows; only the chosen one opens. A method the
               company has not configured is not listed at all. -->
          <div v-if="showMethodChooser" class="rounded-2xl border border-line-card p-4 space-y-3" data-test="method-chooser">
            <div class="flex items-center gap-2">
              <span
                v-if="showShippingStep"
                class="w-6 h-6 rounded-full bg-brand-600 text-ink-primary text-xs font-bold inline-flex items-center justify-center shrink-0"
              >2</span>
              <p class="text-sm font-bold text-ink-card">{{ td('pay.choose_method') }}</p>
            </div>

            <!-- Nothing to offer. Said out loud: the customer cannot fix it,
                 and a page that asks for money without saying how to send it
                 is worse than one that admits the problem. -->
            <div
              v-if="noMethodAvailable"
              class="rounded-xl bg-surface-warning border border-amber-200 px-3 py-3 text-xs font-bold text-ink-warning"
              data-test="no-method"
            >
              {{ td('pay.no_method') }}
            </div>

            <template v-else>
              <div v-if="cardError" class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2 text-sm text-ink-danger">
                <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
                <span>{{ cardError }}</span>
              </div>

              <div v-for="method in availableMethods" :key="method" class="rounded-xl border transition-colors" :class="selectedMethod === method ? 'border-brand-600 bg-surface-chip' : 'border-line-card'">
                <!-- The whole row is the control, not a button hidden inside
                     it. The old page made the card a button and the transfer
                     option a paragraph, so only one of them read as a
                     choice. -->
                <button
                  type="button"
                  class="w-full min-h-[56px] px-3 py-3 flex items-center gap-3 text-left"
                  :data-test="`method-${method}`"
                  @click="chooseMethod(method)"
                >
                  <span
                    class="w-5 h-5 rounded-full border-2 shrink-0 inline-flex items-center justify-center"
                    :class="selectedMethod === method ? 'border-brand-600' : 'border-line-input'"
                  >
                    <span v-if="selectedMethod === method" class="w-2.5 h-2.5 rounded-full bg-brand-600"></span>
                  </span>
                  <Icon :name="methodLabels[method].icon" :size="20" class="text-ink-brand shrink-0" />
                  <span class="min-w-0">
                    <span class="block text-sm font-bold text-ink-card">{{ td(methodLabels[method].title) }}</span>
                    <span class="block text-xs text-ink-card-muted">{{ td(methodLabels[method].hint) }}</span>
                  </span>
                </button>

                <!-- CARD -->
                <div v-if="selectedMethod === 'card' && method === 'card'" class="px-3 pb-3 space-y-2" data-test="panel-card">
                  <!-- A test-mode charge is not a purchase, and the person
                       about to type a card number is entitled to know which
                       one this is. -->
                  <p v-if="isTestMode" class="rounded-xl bg-surface-warning border border-amber-200 px-3 py-2 text-xs font-bold text-ink-warning">
                    {{ td('pay.test_mode') }}
                  </p>
                  <p class="text-xs text-ink-card-muted">{{ td('pay.online_help') }}</p>
                </div>

                <!-- PROMPTPAY -->
                <div v-if="selectedMethod === 'promptpay' && method === 'promptpay'" class="px-3 pb-3 space-y-3" data-test="panel-promptpay">
                  <div class="rounded-xl bg-white p-3 flex flex-col items-center gap-2">
                    <img v-if="qrDataUrl" :src="qrDataUrl" alt="PromptPay QR" class="w-56 h-56" />
                    <p v-if="order.company_payment.promptpay_id" class="text-xs text-slate-500">
                      PromptPay: {{ order.company_payment.promptpay_id }}
                    </p>
                  </div>
                  <!-- Scanning a QR from the same phone that is showing it is
                       impossible, and that is the common case. Every Thai
                       banking app can open one from the photo library. -->
                  <button
                    type="button"
                    class="w-full min-h-[44px] rounded-xl border border-line-card text-sm font-bold text-ink-card hover:bg-surface-chip inline-flex items-center justify-center gap-2"
                    data-test="save-qr"
                    @click="saveQrImage"
                  >
                    <Icon name="download" :size="16" />
                    {{ td('pay.save_qr') }}
                  </button>
                  <p class="text-xs text-ink-card-muted text-center">{{ td('pay.after_transfer') }}</p>
                </div>

                <!-- BANK TRANSFER -->
                <div v-if="selectedMethod === 'bank' && method === 'bank'" class="px-3 pb-3 space-y-2 text-sm" data-test="panel-bank">
                  <div class="flex justify-between gap-3">
                    <span class="text-ink-card-muted">{{ td('bank.name') }}</span>
                    <span class="font-bold text-ink-card text-right">{{ order.company_payment.bank_name }}</span>
                  </div>
                  <div class="flex justify-between gap-3">
                    <span class="text-ink-card-muted">{{ td('bank.account_name') }}</span>
                    <span class="font-bold text-ink-card text-right">{{ order.company_payment.bank_account_name }}</span>
                  </div>
                  <div class="flex items-center justify-between gap-3">
                    <span class="text-ink-card-muted">{{ td('bank.account_number') }}</span>
                    <span class="inline-flex items-center gap-2">
                      <span class="font-bold text-ink-card">{{ order.company_payment.bank_account_number }}</span>
                      <button
                        type="button"
                        class="text-ink-brand inline-flex items-center gap-0.5 text-xs font-bold"
                        @click="copyAccount"
                      >
                        <Icon name="copy" :size="14" />
                        {{ copied ? td('common.copied') : td('common.copy') }}
                      </button>
                    </span>
                  </div>
                  <p class="text-xs text-ink-card-muted pt-1">{{ td('pay.after_transfer') }}</p>
                </div>
              </div>

              <!-- The slip, once a transfer rail is chosen. Never shown to a
                   card payer, who has nothing to upload. -->
              <div v-if="transferChosen" class="rounded-xl border border-line-card p-3 space-y-3" data-test="slip-step">
                <div class="flex items-center gap-2">
                  <Icon name="upload" :size="16" class="text-ink-brand" />
                  <p class="text-sm font-bold text-ink-card">{{ td('pay.upload_slip') }}</p>
                </div>

                <div v-if="uploadError" class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2 text-sm text-ink-danger">
                  <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
                  <span>{{ uploadError }}</span>
                </div>

                <input
                  ref="fileInputEl"
                  type="file"
                  accept="image/*"
                  class="hidden"
                  @change="onFilePicked"
                />
                <button
                  type="button"
                  class="w-full min-h-[44px] py-3 rounded-xl border border-dashed border-line-card text-sm font-bold text-ink-card-muted hover:bg-surface-chip inline-flex items-center justify-center gap-2"
                  data-test="pick-slip"
                  @click="fileInputEl?.click()"
                >
                  <Icon name="image" :size="18" />
                  {{ selectedFile ? td('pay.change_slip') : td('pay.pick_slip') }}
                </button>

                <img v-if="previewUrl" :src="previewUrl" :alt="td('order.slip')" class="w-full rounded-xl border border-line-card object-contain max-h-72" />
              </div>
            </template>
          </div>

          <!-- ADR-027 (TASK-139) — CARD PAYMENT.
               Both blocks below render only AFTER the customer pressed the
               card button above and the server answered with an intent. -->
          <!-- Provider-hosted checkout (Stripe). Normally on screen for a
               heartbeat only — startOnlinePayment() navigates as soon as the
               redirect arrives — so this is also the fallback for a browser
               that blocked that navigation. -->
          <div v-if="redirectIntent" class="rounded-2xl border border-line-card p-4 space-y-3">
            <div class="flex items-center gap-2">
              <Icon name="credit_card" :size="16" class="text-ink-brand" />
              <p class="text-sm font-bold text-ink-card">{{ td('pay.card_or_promptpay') }}</p>
            </div>

            <p v-if="isTestMode" class="rounded-xl bg-surface-warning border border-amber-200 px-3 py-2 text-xs font-bold text-ink-warning">
              {{ td('pay.test_mode') }}
            </p>

            <button
              type="button"
              :disabled="charging"
              class="w-full py-2.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed"
              @click="payByRedirect"
            >
              {{ charging ? td('pay.redirecting') : td('pay.pay_amount', '', { amount: formatBaht(order.amount_baht) }) }}
            </button>

            <p class="text-xs text-ink-card-subtle text-center">
              {{ td('pay.redirect_note') }}
            </p>
          </div>

          <div v-if="cardIntent" class="rounded-2xl border border-line-card p-4 space-y-3">
            <div class="flex items-center gap-2">
              <Icon name="credit_card" :size="16" class="text-ink-brand" />
              <p class="text-sm font-bold text-ink-card">{{ td('pay.card') }}</p>
            </div>

            <!-- A test-mode charge is not a purchase, and the person about to
                 type a card number is entitled to know which one this is. -->
            <p v-if="isTestMode" class="rounded-xl bg-surface-warning border border-amber-200 px-3 py-2 text-xs font-bold text-ink-warning">
              {{ td('pay.test_mode') }}
            </p>

            <div v-if="cardError" class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2 text-sm text-ink-danger">
              <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
              <span>{{ cardError }}</span>
            </div>

            <button
              type="button"
              :disabled="charging"
              class="w-full py-2.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed"
              @click="payByCard"
            >
              {{ charging ? td('pay.processing') : td('pay.pay_amount_card', '', { amount: formatBaht(order.amount_baht) }) }}
            </button>

            <!-- Said plainly, because it is the reason this form is an
                 iframe belonging to Omise rather than inputs belonging to
                 us, and a customer typing a card number deserves to know. -->
            <p class="text-xs text-ink-card-subtle text-center">
              {{ td('pay.omise_note') }}
            </p>
          </div>
        </template>

        <!-- 2026-09-11 — what replaced the "กลับหน้าหลัก" button and its
             90-second timer. Both navigated to `/`, which is the agent
             portal's dashboard and requires a login the customer does not
             have; see the block comment in the script for the full story.

             ONE instance, outside the paid / cancelled / awaiting chain,
             because this belongs to the PAGE having finished with the
             customer rather than to which of the two finished states they
             are in — `payment_received` (charged, confirmation pending) is
             one, and `paid` is the one with a voucher on screen. -->
        <p v-if="isFinished" data-test="keep-this-link" class="pt-2 text-center text-xs text-ink-card-subtle">
          {{ td('pay.keep_this_link') }}
        </p>
      </div>
    </div>

    <!-- 2026-09-10 — the single primary action, always reachable.
         Fixed to the viewport, not to the card: on a phone the old slip
         button sat below an account number and a file picker, so the last
         step of a purchase was the one you had to scroll to find. -->
    <div
      v-if="showActionBar"
      class="fixed inset-x-0 bottom-0 z-20 border-t border-line-card bg-surface-card/95 backdrop-blur px-4 py-3"
      data-test="action-bar"
    >
      <div class="mx-auto w-full max-w-md flex items-center gap-3">
        <div class="min-w-0">
          <p class="text-[11px] text-ink-card-subtle leading-none">{{ td('pay.title') }}</p>
          <p class="text-base font-bold text-ink-card leading-tight">{{ formatBaht(order?.amount_baht ?? 0) }}</p>
        </div>
        <button
          type="button"
          :disabled="actionDisabled"
          class="flex-1 min-h-[48px] rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed"
          data-test="primary-action"
          @click="runPrimaryAction"
        >
          {{ actionLabel }}
        </button>
      </div>
    </div>
  </div>
</template>