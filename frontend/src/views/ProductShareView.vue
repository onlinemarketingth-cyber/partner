<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * ProductShareView — PUBLIC, unauthenticated product showcase page
 * (TASK-056 P3). Route: /p/:token (meta.public — full-bleed, no app
 * chrome, same treatment as PaymentPageView.vue / AffiliateLeadCaptureView.vue).
 *
 * A prospect reaches this after an Agent shares a product-share link.
 * Every URL on this page (media stream/thumbnail, sales-material stream)
 * comes straight from PublicProductShareResource as an already-public,
 * already-absolute backend URL — unlike the authenticated admin/agent
 * product screens, there is no Sanctum session here, so plain <img>/
 * <video>/<a> tags are used directly (no AuthenticatedMedia blob-fetch
 * needed, no credentials to carry).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SCOPE, AMENDED 2026-08-08 (TASK-137, ADR-026 §3.7 + TASK-132 Half B).
 *
 * This page USED to be view-only, and carried a note here saying so was
 * a human-confirmed scope call ("แสดงทั้งหมดอัตโนมัติ", ADR-019
 * Decision 1). That note is superseded, not deleted, because the reason
 * it was true has itself changed: view-only was correct while
 * `OrderService::confirmPayment()` hard-refused any referral that had
 * not finished a doctor meeting — a customer could have paid, but
 * nobody could ever have closed the order. ADR-026 made the journey
 * configurable (human decision, KreangYot 2026-08-08), so for a product
 * whose template reaches `complete_payment` from its entry stage, a
 * customer paying straight from this page is now a completable sale.
 *
 * The page therefore has TWO modes, and the switch is the server's
 * `product.can_checkout` — the EXACT predicate
 * `ProductShareCheckoutService` enforces on the POST, not a second
 * client-side opinion. The button and the endpoint cannot disagree:
 *
 *   can_checkout === true   → "ซื้อเลย" CTA + checkout sheet, then a
 *                             redirect to the returned pay_url.
 *   can_checkout === false  → exactly the old view-only page (that
 *                             product's journey still routes through an
 *                             appointment; see the note on the CTA).
 *
 * ADR-019 Decision 1 otherwise still holds: this is a product showcase,
 * not an AffiliateLink lead-capture funnel and not a SalesMaterial file
 * share. The three systems remain distinct.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
// TASK-137 — the checkout endpoint answers EVERY refusal with one
// identical generic 422 by design (anti-oracle, see
// PublicProductShareController::checkout()'s docblock). apiErrorMessage
// surfaces that server sentence verbatim; this view must not try to
// interpret it or guess which field caused it, because the whole point
// of the design is that the body does not say.
import { apiErrorMessage } from '@/utils/apiError'
// The one YouTube-URL normaliser, shared with AttachmentLightbox / Academy
// (see its header for why a `watch?v=` URL cannot be framed).
import { toEmbedUrl, youtubeThumbnailUrl } from '@/utils/embedUrl'
import Icon from '@/design-system/components/Icon.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import AttachmentLightbox, { type LightboxItem } from '@/design-system/components/AttachmentLightbox.vue'
// TASK-159 §4.2 — this page carries no company slug (a share link must
// stay short), so the boot-time loadPublic() bails at resolveSlug() and
// this page used to paint on platform defaults. The theme now rides along
// on the token response instead; see the `theme` field below.
import { useThemeStore, type Theme } from '@/stores/theme'

const route = useRoute()
const token = route.params.token as string
const themeStore = useThemeStore()

interface MediaItem {
  id: number
  media_type: 'image' | 'video'
  source_type: 'upload' | 'embed'
  stream_url: string | null
  thumbnail_url: string | null
  embed_url: string | null
  is_primary: boolean
}
interface SalesMaterialItem {
  id: number
  material_group: string | null
  original_filename: string | null
  mime_type: string | null
  stream_url: string | null
  embed_url: string | null
}
interface SpecItem {
  spec_group: string | null
  spec_key: string
  spec_value: string
}
interface PublicProductShare {
  company_name: string | null
  agent_name: string | null
  /**
   * The sharing agent's own contact channels (2026-08-21). Either can be
   * null — an agent an admin created may never have been given a phone —
   * and a null must render NO button rather than a dead `tel:`.
   */
  agent_phone: string | null
  agent_email: string | null
  // TASK-159 §3 — the SHARING company's theme, same shape as
  // GET /public/theme/{slug}. Null only when the company could not be
  // resolved at all (not reachable through a live token today).
  theme: Theme | null
  product: {
    id: number
    name: string
    description: string | null
    spec_description: string | null
    // The LIST price. NOT what the customer is charged when a promotion
    // is running — see payable_price_satang below, which is the only
    // number this page is allowed to headline (TASK-132 risk R1).
    price_satang: number
    // TASK-136 — what OrderService will actually snapshot onto the order
    // if they check out right now, derived from the same
    // ProductPricingService the order creation uses.
    payable_price_satang: number
    // Non-null only while a product_price_promotion is live.
    promotional_price_satang: number | null
    // ADR-026 §3.7 — may an anonymous visitor buy this straight from the
    // page? Same predicate the POST enforces server-side.
    can_checkout: boolean
    specs: SpecItem[]
    media: MediaItem[]
    sales_materials: SalesMaterialItem[]
  } | null
}

type PageState = 'loading' | 'ready' | 'not_found' | 'error'
const pageState = ref<PageState>('loading')
const share = ref<PublicProductShare | null>(null)
const activeMediaIndex = ref(0)

async function load() {
  pageState.value = 'loading'
  try {
    const res = await api.get<{ data: PublicProductShare }>(`/public/product-shares/${token}`)
    // TASK-159 §4.2 — theme FIRST, then reveal. Every branded pixel on
    // this page (the cards, the price in --ink-brand, the font) lives
    // behind `pageState === 'ready'`, so writing the CSS vars before that
    // flag flips means the customer never sees platform slate turn into
    // the company's colours: they only ever see the loading line, then
    // the branded page. Ordering, not timing — there is no race to lose.
    themeStore.applyResolved(res.data.theme)
    share.value = res.data
    activeMediaIndex.value = Math.max(0, res.data.product?.media.findIndex((m) => m.is_primary) ?? 0)
    pageState.value = 'ready'
  } catch (e) {
    pageState.value = e instanceof ApiError && e.status === 404 ? 'not_found' : 'error'
  }
}
onMounted(load)

const product = computed(() => share.value?.product ?? null)
const activeMedia = computed(() => product.value?.media[activeMediaIndex.value] ?? null)

function formatBaht(satang: number): string {
  return '฿' + (satang / 100).toLocaleString('th-TH')
}

/**
 * TASK-137 — is there a discount worth advertising?
 *
 * Deliberately checks BOTH `promotional_price_satang !== null` (the
 * server saying a promotion is live) AND that the payable price is
 * genuinely lower than the list price. Either alone is a way to show a
 * strikethrough that lies: a promotion configured AT the list price is
 * not a discount, and comparing only the two numbers would invent a
 * "discount" out of any future reason the payable price might differ.
 *
 * No arithmetic on money happens here or anywhere in this view (BR-3,
 * §7) — both numbers come from the server as integer satang, and only
 * formatBaht's display-layer /100 ever touches them.
 */
const hasDiscount = computed(
  () =>
    product.value !== null &&
    product.value.promotional_price_satang !== null &&
    product.value.payable_price_satang < product.value.price_satang,
)

// ── TASK-137 checkout ────────────────────────────────────────────────
// Three taps end to end (§9 "≤ 3 clicks"): ซื้อเลย → ติ๊กยินยอม → ยืนยัน.
// Typing name/phone is not a click, and consent must stay an explicit
// act (PDPA §6) — it is the one box that may never be pre-ticked.
const checkoutOpen = ref(false)
const submitting = ref(false)
const checkoutError = ref('')
const checkoutForm = ref({
  name: '',
  phone: '',
  email: '',
  consent: false,
  // Honeypot. Never shown, never focusable, never autofilled — a human
  // cannot fill it, so anything in it came from a bot. The backend
  // treats a filled hp_field as just another generic refusal
  // (StoreProductShareCheckoutRequest accepts it as nullable precisely
  // so the 422 does not announce "caught you").
  hp_field: '',
})

function openCheckout() {
  checkoutError.value = ''
  checkoutOpen.value = true
}

async function submitCheckout() {
  if (submitting.value) return
  submitting.value = true
  checkoutError.value = ''
  try {
    // Response is `{ pay_url }` and nothing else — no ids, no order
    // number, no amount (PDPA/BR-6, see the controller). It is NOT
    // wrapped in a `data` envelope, unlike every Resource-backed
    // endpoint this app calls.
    const res = await api.post<{ pay_url: string }>(`/public/product-shares/${token}/checkout`, {
      name: checkoutForm.value.name.trim(),
      phone: checkoutForm.value.phone.trim(),
      // Optional field: send null rather than '' so the Request's
      // `nullable|email` rule sees an absent value, not an invalid one.
      email: checkoutForm.value.email.trim() || null,
      consent: checkoutForm.value.consent,
      hp_field: checkoutForm.value.hp_field,
    })
    // Full page navigation, not router.push: pay_url is an absolute URL
    // built by the backend from services.agent_portal.frontend_url and
    // may point at a different origin than this page.
    window.location.assign(res.pay_url)
    // `submitting` intentionally stays true — the browser is navigating
    // away and the button must not become re-tappable in the meantime
    // (this call creates a Client + Referral + Order).
  } catch (e) {
    checkoutError.value = apiErrorMessage(e, 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง')
    submitting.value = false
  }
}

/**
 * TASK-103 (human: "link ที่ส่งให้ลูกค้าถ้าเป็น youtube facebook ขึ้น
 * thumbnail ได้ไหม").
 *
 * YouTube: yes — see `youtubeThumbnailUrl` in @/utils/embedUrl.
 *
 * Facebook: NO, and this is a platform limit rather than something left
 * undone. A Facebook video/post thumbnail comes from
 * `graph.facebook.com/<id>/picture`, which requires an app access token;
 * there is no unauthenticated equivalent. Shipping a token to a public
 * page a prospect opens would hand it to anyone who views source. Those
 * links get a labelled tile instead — see isFacebookUrl below.
 */
function linkThumbUrl(url: string | null): string | null {
  return youtubeThumbnailUrl(url)
}

function isFacebookUrl(url: string | null): boolean {
  return Boolean(url && /(?:facebook\.com|fb\.watch|fb\.me)/i.test(url))
}

const groupedSpecs = computed(() => {
  const groups = new Map<string, SpecItem[]>()
  for (const spec of product.value?.specs ?? []) {
    const key = spec.spec_group || 'รายละเอียด'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(spec)
  }
  return Array.from(groups.entries())
})

/**
 * TASK-100 — "รายละเอียดเพิ่มเติม" (was "เอกสารการขาย").
 *
 * Renamed at the human's request: the customer reading this page is not
 * buying "sales documents", they are looking for more information about
 * the product. The ungrouped fallback key changes with it, since it is
 * the visible group heading when an admin uploaded without a group.
 */
const groupedMaterials = computed(() => {
  const groups = new Map<string, SalesMaterialItem[]>()
  for (const material of product.value?.sales_materials ?? []) {
    const key = material.material_group || 'รายละเอียดเพิ่มเติม'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(material)
  }
  return Array.from(groups.entries())
})

type MaterialKind = 'image' | 'pdf' | 'video' | 'link'

/**
 * What tile to draw for an attachment.
 *
 * Reads `mime_type` first and only falls back to the filename extension,
 * because mime is what the backend actually stored from the upload while
 * the filename is whatever the admin's machine called it.
 *
 * `embed_url` (a YouTube/Vimeo link) wins over everything: there is no
 * file at all in that case.
 */
function materialKind(material: SalesMaterialItem): MaterialKind {
  if (material.embed_url) return 'link'

  const mime = material.mime_type ?? ''
  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/')) return 'video'
  if (mime === 'application/pdf') return 'pdf'

  const name = (material.original_filename ?? '').toLowerCase()
  if (/\.(jpe?g|png|webp|gif)$/.test(name)) return 'image'
  if (/\.(mp4|mov|webm|m4v)$/.test(name)) return 'video'
  if (name.endsWith('.pdf')) return 'pdf'

  return 'link'
}

const MATERIAL_ICON: Record<MaterialKind, string> = {
  image: 'image',
  pdf: 'document',
  video: 'play',
  link: 'link',
}

const MATERIAL_LABEL: Record<MaterialKind, string> = {
  image: 'รูปภาพ',
  pdf: 'PDF',
  video: 'วิดีโอ',
  link: 'ลิงก์',
}

/**
 * TASK-101 — tapping an attachment opens a full-screen viewer instead of
 * a new browser tab (human: "เป็น modal แบบเลื่อนซ้ายขวาได้").
 *
 * The lightbox list is FLAT across every group. Grouping is an authoring
 * convenience for the admin; a customer swiping through brochures does
 * not want the swipe to stop at an invisible boundary they never asked
 * for. Group headings still label the grid above.
 */
const lightboxItems = computed<LightboxItem[]>(() =>
  (product.value?.sales_materials ?? []).map((material) => ({
    id: material.id,
    kind: materialKind(material),
    url: material.stream_url ?? material.embed_url,
    label: material.original_filename ?? MATERIAL_LABEL[materialKind(material)],
    thumbUrl: linkThumbUrl(material.embed_url),
  })),
)

const lightboxOpen = ref(false)
const lightboxIndex = ref(0)

function openLightbox(material: SalesMaterialItem) {
  const idx = lightboxItems.value.findIndex((item) => item.id === material.id)
  if (idx === -1) return
  lightboxIndex.value = idx
  lightboxOpen.value = true
}
</script>

<template>
  <!-- TASK-159 §4.1/§4.2 — the page surface was a hardcoded neutral
       gradient, so this page could never be the sharing company's colour
       no matter what the admin configured. It is now the `surface-app`
       token (derived from the company's background, falling back to its
       CARD colour) with the company's own image/gradient layered on top
       when one is configured — the same two-layer model App.vue uses.
       This page stays full-bleed; it is not the phone shell. -->
  <div class="min-h-screen w-full bg-surface-app" :style="themeStore.companyBackgroundStyle">
    <!-- TASK-100 (human, 2026-08-04): "ส่วนที่แชร์หาลูกค้า ตัด header ออก
         ที่ขึ้นด้วย Sync Vision Agent รวมถึง Thai Life."

         The platform wordmark + company name row is gone. This page is
         what a PROSPECT sees after an agent sends them a link — the
         product is the message, and a SaaS vendor's branding above it is
         noise the customer has no use for. `company_name` is still
         returned by PublicProductShareResource and still used nowhere
         else here; the agent's own name below is the attribution that
         actually matters to the customer. -->
    <div class="max-w-2xl lg:max-w-5xl mx-auto p-4 sm:p-8">
      <!-- Loading -->
      <!-- Sits directly on the PAGE surface (no card around it), so it
           takes the app ink, not the card ink. -->
      <div v-if="pageState === 'loading'" class="mt-16 py-10 text-center text-sm text-ink-app-muted">{{ td('common.loading2') }}</div>

      <!-- Invalid / revoked token -->
      <div v-else-if="pageState === 'not_found'" class="mt-16 py-10 text-center bg-surface-card rounded-3xl shadow-xl border border-line-card/80">
        <div class="mx-auto w-14 h-14 rounded-full border border-rose-100 flex items-center justify-center">
          <Icon name="alert" :size="24" class="text-ink-danger" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">{{ td('share.link_invalid') }}</h2>
        <p class="mt-2 text-sm text-ink-card-muted">{{ td('public.ask_member_new_link') }}</p>
      </div>

      <div v-else-if="pageState === 'error'" class="mt-16 py-10 text-center bg-surface-card rounded-3xl shadow-xl border border-line-card/80">
        <p class="text-sm text-ink-danger">{{ td('common.error_network2') }}</p>
        <button type="button" class="mt-3 text-sm font-bold text-ink-brand hover:underline" @click="load">{{ td('common.try_again') }}</button>
      </div>

      <!-- Ready.
           `pb-28` only in checkout mode, so the sticky buy bar never
           covers the last card. A view-only share keeps the exact
           spacing it had before TASK-137. -->
      <div v-else-if="product" class="space-y-4" :class="product.can_checkout ? 'pb-28' : ''">
        <!--
             ATTRIBUTION, AND NOW A WAY TO REACH THE PERSON NAMED IN IT.

             Human request 2026-08-21, "เพิ่มให้ทุกกรณี" — deliberately NOT
             behind can_checkout. On a product whose journey needs an
             appointment the buy bar never renders, so a customer who had read
             the entire page was left with nothing to do but go back to LINE
             and hope they still had the conversation. The agent's name was
             already here as ATTRIBUTION; it was not a way to reach them.

             ABOVE THE GALLERY on purpose. Below it would put these past a
             long description and a specification list on a phone — exactly
             where somebody who has already decided stops scrolling.

             THE NUMBER AND THE ADDRESS ARE PRINTED, not merely linked.
             `tel:` does nothing on most desktops, and `mailto:` does nothing
             on a phone with no mail client configured — silently, both of
             them. ShareLinkModal's own history is the precedent: its Email
             button was moved OFF mailto: for exactly that reason. So the
             value stays readable and selectable whether or not the handler
             fires, and a customer can always copy it.

             Each channel renders only if the agent HAS one — an agent an
             admin created may never have been given a phone, and a dead
             `tel:` is worse than no button.
        -->
        <div
          v-if="share?.agent_name"
          class="rounded-2xl bg-surface-card/80 border border-line-card/80 px-4 py-3 space-y-3"
        >
          <div class="flex items-center gap-2 text-sm">
            <Icon name="user" :size="16" class="text-ink-brand" />
            <span class="text-ink-card-muted">{{ td('share.referred_by') }}</span>
            <span class="font-bold text-ink-card">{{ share.agent_name }}</span>
          </div>

          <div
            v-if="share.agent_phone || share.agent_email"
            class="grid gap-2"
            :class="share.agent_phone && share.agent_email ? 'sm:grid-cols-2' : ''"
          >
            <a
              v-if="share.agent_phone"
              :href="`tel:${share.agent_phone}`"
              class="min-h-[44px] flex items-center gap-2.5 px-3 py-2 rounded-xl bg-surface-chip active:scale-[0.98] transition"
            >
              <Icon name="phone" :size="16" class="shrink-0 text-ink-brand" />
              <span class="min-w-0">
                <span class="block text-[11px] text-ink-chip/70 leading-tight">{{ td('share.call_referrer') }}</span>
                <span class="block text-sm font-bold text-ink-chip truncate">{{ share.agent_phone }}</span>
              </span>
            </a>

            <a
              v-if="share.agent_email"
              :href="`mailto:${share.agent_email}`"
              class="min-h-[44px] flex items-center gap-2.5 px-3 py-2 rounded-xl bg-surface-chip active:scale-[0.98] transition"
            >
              <Icon name="mail" :size="16" class="shrink-0 text-ink-brand" />
              <span class="min-w-0">
                <span class="block text-[11px] text-ink-chip/70 leading-tight">{{ td('share.email_referrer') }}</span>
                <span class="block text-sm font-bold text-ink-chip truncate">{{ share.agent_email }}</span>
              </span>
            </a>
          </div>
        </div>

        <!--
             ── TWO COLUMNS FROM lg UP (human request, 2026-08-21) ──

             Below lg this is byte-for-byte the single column it always was,
             and that is the case that matters most: this link is opened from
             LINE on a phone. What was wrong was the OTHER end — on a desktop
             the page stayed a 672px ribbon down the middle with the gallery
             pushing the price and the description below the fold, and an
             empty half-screen either side of them.

             `items-start` so the two columns keep their own heights instead
             of the shorter one stretching, and `lg:sticky` so the photo stays
             in view while a long specification list scrolls past it — the
             gallery is the thing a buyer keeps glancing back at.
        -->
        <div class="lg:grid lg:grid-cols-2 lg:gap-6 lg:items-start space-y-4 lg:space-y-0">
          <div class="lg:sticky lg:top-6">
        <!-- Media gallery — TASK-103, Shopee/Lazada pattern: one large
             stage, prev/next arrows on it, and a thumbnail strip that
             makes the set's size visible at a glance. Aspect is square
             rather than 16:9 because product photos are shot square and
             letterboxing them wasted a third of the stage. -->
        <div class="bg-surface-card rounded-3xl shadow-xl border border-line-card/80 overflow-hidden">
          <div class="relative w-full aspect-square bg-surface-chip flex items-center justify-center">
            <template v-if="activeMedia">
              <iframe
                v-if="activeMedia.embed_url"
                :src="toEmbedUrl(activeMedia.embed_url)"
                class="w-full h-full"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
              ></iframe>
              <video
                v-else-if="activeMedia.media_type === 'video' && activeMedia.stream_url"
                :src="activeMedia.stream_url"
                controls
                class="w-full h-full object-contain bg-black"
              ></video>
              <img
                v-else-if="activeMedia.stream_url || activeMedia.thumbnail_url"
                :src="activeMedia.stream_url ?? activeMedia.thumbnail_url ?? ''"
                :alt="product.name"
                class="w-full h-full object-contain"
              />
            </template>
            <Icon v-else name="image" :size="32" class="text-ink-card-subtle" />

            <!-- Arrows hide on an embed: an iframe owns its own pointer
                 events, so a button drawn over YouTube's player would sit
                 there looking clickable and do nothing. -->
            <template v-if="product.media.length > 1 && !activeMedia?.embed_url">
              <button
                type="button"
                class="absolute left-2 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 active:scale-90 transition"
                :aria-label="td('common.prev')"
                @click="activeMediaIndex = (activeMediaIndex - 1 + product.media.length) % product.media.length"
              >
                <Icon name="chevron_left" :size="20" />
              </button>
              <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 active:scale-90 transition"
                :aria-label="td('common.next2')"
                @click="activeMediaIndex = (activeMediaIndex + 1) % product.media.length"
              >
                <Icon name="chevron_right" :size="20" />
              </button>
            </template>

            <span
              v-if="product.media.length > 1"
              class="absolute bottom-2 right-2 px-2 py-0.5 rounded-full bg-black/55 text-white text-[11px] font-bold tabular-nums"
            >
              {{ activeMediaIndex + 1 }} / {{ product.media.length }}
            </span>
          </div>

          <div v-if="product.media.length > 1" class="flex gap-2 p-3 overflow-x-auto no-scrollbar">
            <button
              v-for="(media, idx) in product.media"
              :key="media.id"
              class="relative shrink-0 w-16 h-16 rounded-lg overflow-hidden border-2 transition"
              :class="idx === activeMediaIndex ? 'border-brand-600' : 'border-line-card opacity-70 hover:opacity-100'"
              @click="activeMediaIndex = idx"
            >
              <!-- TASK-103 — an embedded YouTube item shows its poster in
                   the strip instead of a bare link glyph. -->
              <img
                v-if="linkThumbUrl(media.embed_url)"
                :src="linkThumbUrl(media.embed_url) ?? ''"
                class="w-full h-full object-cover"
              />
              <img
                v-else-if="media.thumbnail_url || (media.media_type === 'image' && media.stream_url)"
                :src="media.thumbnail_url ?? media.stream_url ?? ''"
                class="w-full h-full object-cover"
              />
              <div v-else class="w-full h-full flex items-center justify-center bg-surface-chip">
                <Icon name="play" :size="16" class="text-ink-card-subtle" />
              </div>

              <span
                v-if="media.media_type === 'video' || media.embed_url"
                class="absolute inset-0 flex items-center justify-center"
              >
                <span class="w-6 h-6 rounded-full bg-black/55 text-white flex items-center justify-center">
                  <Icon name="play" :size="12" />
                </span>
              </span>
            </button>
          </div>
        </div>
          </div>

          <!-- Everything a buyer READS, stacked in the second column. -->
          <div class="space-y-4">

        <!-- Name + price + description.

             TASK-137 / TASK-132 risk R1 — the HEADLINE number is
             `payable_price_satang`, never `price_satang`. That is the
             number OrderService snapshots onto the order, so "the price
             on the page" and "the amount you are charged" are the same
             value by construction rather than by coincidence. The list
             price only ever appears struck through, and only when it is
             genuinely higher. A customer seeing one number and being
             charged another is the single worst outcome on this page. -->
        <div class="bg-surface-card rounded-3xl shadow-xl border border-line-card/80 p-5">
          <h1 class="text-xl font-bold text-ink-card">{{ product.name }}</h1>
          <div class="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <p class="text-2xl font-bold text-ink-brand">{{ formatBaht(product.payable_price_satang) }}</p>
            <template v-if="hasDiscount">
              <p class="text-sm text-ink-card-subtle line-through">{{ formatBaht(product.price_satang) }}</p>
              <span class="px-2 py-0.5 rounded-full bg-rose-50 text-ink-danger text-[11px] font-bold">{{ td('product.promo_price') }}</span>
            </template>
          </div>
          <p v-if="product.description" class="mt-3 text-sm text-ink-card-muted leading-relaxed whitespace-pre-line">
            {{ product.description }}
          </p>
        </div>

        <!-- Specs -->
        <div v-if="groupedSpecs.length || product.spec_description" class="bg-surface-card rounded-3xl shadow-xl border border-line-card/80 p-5">
          <h2 class="text-sm font-bold text-ink-card mb-3">{{ td('product.description') }}</h2>
          <p v-if="product.spec_description" class="text-sm text-ink-card-muted leading-relaxed whitespace-pre-line mb-3">
            {{ product.spec_description }}
          </p>
          <div v-for="[group, specs] in groupedSpecs" :key="group" class="mb-3 last:mb-0">
            <p class="text-xs font-bold text-ink-card-subtle uppercase tracking-wider mb-1.5">{{ group }}</p>
            <div class="space-y-1.5">
              <div v-for="spec in specs" :key="spec.spec_key" class="flex justify-between gap-3 text-sm">
                <span class="text-ink-card-muted">{{ spec.spec_key }}</span>
                <span class="font-bold text-ink-card text-right">{{ spec.spec_value }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Attachments — TASK-100.
             Was a list of raw filenames ("GNS-MotherDay_1YearMini_amataya
             (1).jpg"), which tells a customer nothing about what they are
             about to open. Now a thumbnail grid: images preview
             themselves, PDFs and videos get a labelled icon tile. There is
             no server-side thumbnail for a sales material (no
             thumbnail_path column on product_sales_materials), so an
             image's own stream_url IS its thumbnail — fine at this size,
             and it is a file the customer is about to download anyway. -->
        <div v-if="groupedMaterials.length" class="bg-surface-card rounded-3xl shadow-xl border border-line-card/80 p-5">
          <h2 class="text-sm font-bold text-ink-card mb-3">{{ td('product.more_details') }}</h2>
          <div v-for="[group, materials] in groupedMaterials" :key="group" class="mb-4 last:mb-0">
            <p v-if="groupedMaterials.length > 1 || group !== 'รายละเอียดเพิ่มเติม'"
               class="text-xs font-bold text-ink-card-subtle uppercase tracking-wider mb-2">
              {{ group }}
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <button
                v-for="material in materials"
                :key="material.id"
                type="button"
                class="group block text-left w-full rounded-2xl overflow-hidden border border-line-card hover:border-brand-300 hover:shadow-md active:scale-[0.98] transition"
                @click="openLightbox(material)"
              >
                <div class="relative w-full aspect-square bg-surface-chip flex items-center justify-center">
                  <img
                    v-if="materialKind(material) === 'image' && material.stream_url"
                    :src="material.stream_url"
                    :alt="material.original_filename ?? ''"
                    loading="lazy"
                    class="w-full h-full object-cover"
                  />
                  <!-- TASK-103 — a YouTube link shows its own poster. -->
                  <img
                    v-else-if="linkThumbUrl(material.embed_url)"
                    :src="linkThumbUrl(material.embed_url) ?? ''"
                    :alt="material.original_filename ?? ''"
                    loading="lazy"
                    class="w-full h-full object-cover"
                  />
                  <Icon
                    v-else
                    :name="MATERIAL_ICON[materialKind(material)]"
                    :size="28"
                    class="text-ink-card-subtle"
                  />

                  <!-- Play glyph over anything video-ish, so a poster is
                       never mistaken for a still image. -->
                  <span
                    v-if="materialKind(material) === 'video' || linkThumbUrl(material.embed_url)"
                    class="absolute inset-0 flex items-center justify-center"
                  >
                    <span class="w-11 h-11 rounded-full bg-black/55 text-white flex items-center justify-center">
                      <Icon name="play" :size="20" />
                    </span>
                  </span>

                  <span
                    v-if="materialKind(material) !== 'image'"
                    class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded text-white text-[10px] font-bold"
                    :class="isFacebookUrl(material.embed_url) ? 'bg-[#1877F2]' : (linkThumbUrl(material.embed_url) ? 'bg-[#FF0000]' : 'bg-slate-900/70')"
                  >
                    {{ isFacebookUrl(material.embed_url) ? 'Facebook' : (linkThumbUrl(material.embed_url) ? 'YouTube' : MATERIAL_LABEL[materialKind(material)]) }}
                  </span>
                </div>
                <p class="px-2.5 py-2 text-[11px] font-bold text-ink-card-muted truncate">
                  {{ material.original_filename ?? MATERIAL_LABEL[materialKind(material)] }}
                </p>
              </button>
            </div>
          </div>
        </div>
          </div>
        </div>
      </div>
    </div>

    <!-- TASK-137 — sticky buy bar.

         Rendered ONLY when the server said can_checkout. There is no
         second client-side condition: the CTA's visibility and the
         endpoint's gate are the same fact, so the page can never offer a
         button that the POST would refuse. A product whose journey still
         routes through an appointment simply never reaches this block and
         keeps the pre-TASK-137 page byte-for-byte.

         Fixed to the bottom because this link is opened from LINE on a
         phone: the price and the action must be in thumb reach without
         scrolling back up past the gallery. -->
    <div
      v-if="pageState === 'ready' && product?.can_checkout"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-line-card/80 bg-surface-card/95 backdrop-blur px-4 py-3"
      style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom))"
    >
      <div class="max-w-2xl lg:max-w-5xl mx-auto flex items-center gap-3">
        <div class="min-w-0 flex-1">
          <p class="text-[11px] text-ink-card-subtle">{{ td('pay.amount_due') }}</p>
          <p class="text-lg font-bold text-ink-brand leading-tight truncate">
            {{ formatBaht(product.payable_price_satang) }}
          </p>
        </div>
        <AppButton class="shrink-0" @click="openCheckout">{{ td('share.buy_now') }}</AppButton>
      </div>
    </div>

    <!-- Checkout sheet. Bottom sheet on mobile (thumb reach), centred
         card from `sm` up. Same overlay/drawer pattern the portal's other
         sheets use; kept inside this view because it is single-use and
         carries the PDPA consent copy that belongs to this flow. -->
    <Transition name="drawer">
      <div v-if="checkoutOpen" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
        <div class="absolute inset-0 bg-slate-900/40" @click="checkoutOpen = false" />
        <div
          class="drawer-panel relative w-full sm:max-w-md bg-surface-card rounded-t-3xl sm:rounded-3xl shadow-xl p-5 max-h-[90vh] overflow-y-auto"
          role="dialog"
          aria-modal="true"
          aria-labelledby="checkout-sheet-title"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 id="checkout-sheet-title" class="text-lg font-bold text-ink-card">{{ td('share.order_form') }}</h2>
              <p class="mt-0.5 text-xs text-ink-card-muted truncate">{{ product?.name }}</p>
            </div>
            <button
              type="button"
              class="shrink-0 min-h-[44px] min-w-[44px] -mr-2 -mt-2 inline-flex items-center justify-center text-ink-card-subtle hover:text-ink-card-muted active:scale-90 transition-transform"
              :aria-label="td('common.close2')"
              @click="checkoutOpen = false"
            >
              <Icon name="close" :size="20" />
            </button>
          </div>

          <p v-if="product" class="mt-3 px-3 py-2 rounded-xl bg-surface-chip text-sm font-bold text-ink-card">
            ยอดชำระ {{ formatBaht(product.payable_price_satang) }}
          </p>

          <form class="mt-4 space-y-3" @submit.prevent="submitCheckout">
            <div>
              <label for="checkout-name" class="text-xs font-bold text-ink-card-muted">{{ td('field.full_name') }}</label>
              <input
                id="checkout-name"
                v-model="checkoutForm.name"
                required
                autocomplete="name"
                class="mt-1 w-full min-h-[44px] px-3 py-2 rounded-xl border border-line-input bg-surface-input text-sm text-ink-input"
                :placeholder="td('share.buyer_name')"
              />
            </div>
            <div>
              <label for="checkout-phone" class="text-xs font-bold text-ink-card-muted">{{ td('field.phone') }}</label>
              <input
                id="checkout-phone"
                v-model="checkoutForm.phone"
                required
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                class="mt-1 w-full min-h-[44px] px-3 py-2 rounded-xl border border-line-input bg-surface-input text-sm text-ink-input"
                placeholder="08x-xxx-xxxx"
              />
            </div>
            <div>
              <label for="checkout-email" class="text-xs font-bold text-ink-card-muted">
                {{ td('field.email') }} <span class="font-normal text-ink-card-subtle">{{ td('common.optional') }}</span>
              </label>
              <input
                id="checkout-email"
                v-model="checkoutForm.email"
                type="email"
                autocomplete="email"
                class="mt-1 w-full min-h-[44px] px-3 py-2 rounded-xl border border-line-input bg-surface-input text-sm text-ink-input"
                placeholder="name@example.com"
              />
            </div>

            <!-- Honeypot. Present in the DOM (a bot that reads the form
                 will fill it) but unreachable for a human: off-screen,
                 not tabbable, not autofillable, hidden from screen
                 readers. Never `display:none` — some bots skip those. -->
            <div aria-hidden="true" class="absolute -left-[9999px] top-0 w-px h-px overflow-hidden">
              <label for="checkout-hp">Company</label>
              <input id="checkout-hp" v-model="checkoutForm.hp_field" type="text" tabindex="-1" autocomplete="off" />
            </div>

            <!-- PDPA (§6) — explicit, never pre-ticked, and the wording
                 says what is being consented to rather than just "ยอมรับ". -->
            <label class="flex items-start gap-2.5 py-1 cursor-pointer">
              <input
                v-model="checkoutForm.consent"
                type="checkbox"
                required
                class="mt-0.5 w-5 h-5 shrink-0 rounded border-line-input accent-brand-600"
              />
              <span class="text-xs text-ink-card-muted leading-relaxed">
                {{ td('share.consent') }}
              </span>
            </label>

            <p v-if="checkoutError" class="px-3 py-2 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger">
              {{ checkoutError }}
            </p>

            <AppButton type="submit" block :loading="submitting">{{ td('share.confirm_pay') }}</AppButton>
            <p class="text-[11px] text-ink-card-subtle text-center">{{ td('share.confirm_note') }}</p>
          </form>
        </div>
      </div>
    </Transition>

    <!-- TASK-101 — full-screen attachment viewer, swipeable across every
         group. Mounted outside the max-w-2xl column so it is not clipped
         by the page's own width constraint. -->
    <AttachmentLightbox
      :open="lightboxOpen"
      :items="lightboxItems"
      :index="lightboxIndex"
      @close="lightboxOpen = false"
      @update:index="lightboxIndex = $event"
    />
  </div>
</template>

<style scoped>
/* Thumbnail strip scrolls horizontally; the bar itself is noise on a
   16px-tall row. Same rule ProductBrowseView already uses. */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
