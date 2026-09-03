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
import { computed, onMounted, ref } from 'vue'
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
  voucherQrDataUrl.value = code ? await generateQrDataUrl(code, 220) : ''
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

    // Voucher code — wrapped, see wrapTextToLines() for why (40-char
    // no-spaces string overshoots a fixed fillText call).
    ctx.fillStyle = '#ffffff'
    ctx.font = 'bold 26px "Kanit", monospace'
    const codeLines = wrapTextToLines(ctx, voucher.code, width - 80)
    for (const line of codeLines) {
      ctx.fillText(line, width / 2, y)
      y += 32
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

const isPaid = computed(() => order.value?.status === 'paid')
const isCancelled = computed(() => order.value?.status === 'cancelled')
// Awaiting verification means a slip is already in — either just uploaded or
// previously submitted. Hide the upload form and show the "waiting" notice.
const awaitingVerification = computed(() => uploaded.value || order.value?.status === 'awaiting_verification')
const showUploadForm = computed(
  () => !isPaid.value && !isCancelled.value && !awaitingVerification.value && !paymentReceived.value,
)

// ── Card payment (ADR-027 / TASK-139) ───────────────────────────────────────
//
// The bank-transfer path below is UNTOUCHED and stays on screen alongside
// this. A customer without a card, or one whose card is declined, still has
// the account number and the slip upload they have always had — removing
// that to make room for a card form would take away the only method that
// works for most people on this platform today.
const cardError = ref('')
const charging = ref(false)

/** True the moment money has arrived, even before the order says 'paid'. */
const paymentReceived = computed(() => order.value?.gateway.payment_received === true)

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
  () => !!onlineGateway.value && !cardIntent.value && !redirectIntent.value && !paymentReceived.value,
)

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

  cardError.value = ''
  startingOnline.value = true
  try {
    const res = await api.post<{ data: PublicOrder }>(`/pay/${token}/intent`, {})
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

    <div v-else class="w-full max-w-md rounded-[28px] bg-surface-card shadow-xl border border-line-card/80 overflow-hidden p-6 sm:p-8">
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
        <!-- Amount summary -->
        <div class="text-center">
          <p class="text-sm text-ink-card-muted">{{ order.product_name ?? td('pay.title') }}</p>
          <p class="mt-1 text-3xl font-bold text-ink-card">{{ formatBaht(order.amount_baht) }}</p>
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
            <!-- 2026-08-17 bugfix: the redemption code is a 40-char random
                 string (Str::random(40), OrderVoucherService::generateCode())
                 — at text-lg + tracking-widest on one line it overflows the
                 max-w-md card on any viewport narrower than the string
                 itself. break-all + a smaller monospace size lets it wrap
                 inside the card instead of bleeding past the border. -->
            <p class="w-full text-sm font-bold font-mono tracking-wide text-ink-card break-all">{{ order.voucher.code }}</p>
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
          <!-- 2026-09-03 — THE CUSTOMER CHOOSES, ON THIS SCREEN.
               Only shown when the company actually has a card gateway
               switched on; otherwise the page is exactly the transfer page it
               has always been, with no dead button on it. The transfer
               details below are never hidden or collapsed — they are the
               method that works for everyone, and burying them behind a
               choice would cost sales from customers with no card. -->
          <div v-if="showMethodChooser" class="rounded-2xl border border-line-card p-4 space-y-3">
            <p class="text-sm font-bold text-ink-card">{{ td('pay.choose_method') }}</p>

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
              :disabled="startingOnline"
              class="w-full min-h-[44px] py-2.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1.5"
              @click="startOnlinePayment"
            >
              <Icon name="credit_card" :size="16" />
              {{ startingOnline ? td('pay.online_starting') : td('pay.card') }}
            </button>
            <p class="text-xs text-ink-card-subtle text-center">{{ td('pay.online_help') }}</p>

            <div class="flex items-center gap-3">
              <span class="h-px flex-1 bg-line-card"></span>
              <span class="text-xs text-ink-card-subtle">{{ td('pay.online_or') }}</span>
              <span class="h-px flex-1 bg-line-card"></span>
            </div>

            <!-- Not a button: the transfer flow IS the rest of this page, so
                 this line points down to it rather than opening anything. -->
            <div class="rounded-xl border border-line-card px-3 py-2.5">
              <p class="text-sm font-bold text-ink-card">{{ td('pay.transfer_title') }}</p>
              <p class="mt-0.5 text-xs text-ink-card-muted">{{ td('pay.transfer_help') }}</p>
            </div>
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

          <!-- Money has arrived but the order is not marked paid yet. Rare,
               and deliberately visible rather than hidden: the alternative is
               a customer who has been charged looking at a payment form. -->
          <div
            v-if="paymentReceived && !isPaid"
            class="rounded-2xl border border-brand-200 bg-brand-50 p-4 flex items-center gap-3"
          >
            <Icon name="check" :size="20" class="text-ink-brand shrink-0" />
            <div>
              <p class="text-sm font-bold text-ink-brand">{{ td('pay.received') }}</p>
              <p class="text-xs text-ink-card-muted">
                {{ td('pay.received_help') }}
              </p>
            </div>
          </div>

          <!-- PromptPay QR — shown whenever the company HAS a PromptPay id,
               no longer only when the agent happened to tick "promptpay" when
               they created the order. Both settle into the same account, and
               the customer is the one holding the phone. -->
          <div v-if="qrDataUrl" class="rounded-2xl border border-line-card p-4 flex flex-col items-center gap-2">
            <p class="text-sm font-bold text-ink-card">{{ td('pay.scan_promptpay') }}</p>
            <img :src="qrDataUrl" alt="PromptPay QR" class="w-52 h-52" />
            <p v-if="order.company_payment.promptpay_id" class="text-xs text-ink-card-subtle">
              PromptPay: {{ order.company_payment.promptpay_id }}
            </p>
          </div>

          <!-- Bank details -->
          <div class="rounded-2xl border border-line-card p-4 space-y-3">
            <div class="flex items-center gap-2">
              <Icon name="money" :size="16" class="text-ink-brand" />
              <p class="text-sm font-bold text-ink-card">{{ td('pay.bank_transfer') }}</p>
            </div>
            <div class="space-y-2 text-sm">
              <div class="flex justify-between gap-3">
                <span class="text-ink-card-muted">{{ td('bank.name') }}</span>
                <span class="font-bold text-ink-card text-right">{{ order.company_payment.bank_name ?? '—' }}</span>
              </div>
              <div class="flex justify-between gap-3">
                <span class="text-ink-card-muted">{{ td('bank.account_name') }}</span>
                <span class="font-bold text-ink-card text-right">{{ order.company_payment.bank_account_name ?? '—' }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-ink-card-muted">{{ td('bank.account_number') }}</span>
                <span class="inline-flex items-center gap-2">
                  <span class="font-bold text-ink-card">{{ order.company_payment.bank_account_number ?? '—' }}</span>
                  <button
                    v-if="order.company_payment.bank_account_number"
                    type="button"
                    class="text-ink-brand hover:text-ink-brand inline-flex items-center gap-0.5 text-xs font-bold"
                    @click="copyAccount"
                  >
                    <Icon name="copy" :size="14" />
                    {{ copied ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                  </button>
                </span>
              </div>
            </div>
          </div>

          <!-- Awaiting verification notice -->
          <div v-if="awaitingVerification" class="rounded-2xl border border-brand-200 bg-brand-50 p-4 flex items-center gap-3">
            <Icon name="clock" :size="20" class="text-ink-brand shrink-0" />
            <div>
              <p class="text-sm font-bold text-ink-brand">{{ td('pay.slip_received') }}</p>
              <p class="text-xs text-ink-card-muted">{{ td('pay.slip_help') }}</p>
            </div>
          </div>

          <!-- ADR-033 (TASK-189) §2.5/E2 — shipping-address form, shown
               alongside the slip-upload card below (same "not yet paid"
               gate, showUploadForm) and submitted together in ONE
               request (uploadSlip()) — the "one door" ADR-033 §2.5
               describes, not a second checkout step. Only rendered when
               THIS product actually requires physical delivery. -->
          <div v-if="showUploadForm && order.requires_shipping" class="rounded-2xl border border-line-card p-4 space-y-3">
            <div class="flex items-center gap-2">
              <Icon name="map_pin" :size="16" class="text-ink-brand" />
              <p class="text-sm font-bold text-ink-card">{{ td('ship.title') }}</p>
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.recipient') }}</label>
              <input
                v-model="shippingRecipientName"
                type="text"
                required
                class="mt-1 w-full px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card"
              />
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.recipient_phone') }}</label>
              <input
                v-model="shippingPhone"
                type="tel"
                required
                class="mt-1 w-full px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card"
              />
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">{{ td('ship.address') }}</label>
              <textarea
                v-model="shippingAddress"
                required
                rows="3"
                class="mt-1 w-full px-3 py-2 rounded-xl border border-line-card text-sm bg-surface-input text-ink-card resize-none"
              ></textarea>
            </div>
          </div>

          <!-- Slip upload -->
          <div v-if="showUploadForm" class="rounded-2xl border border-line-card p-4 space-y-3">
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
              class="w-full py-3 rounded-xl border border-dashed border-line-card text-sm font-bold text-ink-card-muted hover:bg-surface-chip inline-flex items-center justify-center gap-2"
              @click="fileInputEl?.click()"
            >
              <Icon name="image" :size="18" />
              {{ selectedFile ? 'เปลี่ยนรูปสลิป' : 'เลือกรูปสลิป' }}
            </button>

            <img v-if="previewUrl" :src="previewUrl" :alt="td('order.slip')" class="w-full rounded-xl border border-line-card object-contain max-h-72" />

            <p v-if="order.requires_shipping && !shippingValid" class="text-xs text-ink-danger">
              {{ td('ship.required_first') }}
            </p>

            <button
              type="button"
              :disabled="!selectedFile || uploading || !shippingValid"
              class="w-full py-2.5 rounded-xl bg-brand-600 text-ink-primary text-sm font-bold hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed"
              @click="uploadSlip"
            >
              {{ uploading ? 'กำลังอัปโหลด...' : 'ส่งสลิปการโอนเงิน' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
