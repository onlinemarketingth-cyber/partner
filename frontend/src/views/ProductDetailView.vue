<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * ProductDetailView — what an agent sees when they tap a product.
 *
 * ── WHY THIS SCREEN DID NOT EXIST UNTIL NOW (human request, 2026-08-21) ──
 *
 * /products was a grid of cards whose only action was "แชร์". The name was
 * clipped to two lines, the price was a number, and the description,
 * specification text and photo gallery lived only on the PUBLIC page the
 * customer would open. So an agent who wanted to know what they were selling
 * had to mint a share link and open it as if they were the prospect.
 *
 * ── WHAT IT IS NOT ──
 *
 * NOT the customer page. ProductShareView (/p/{token}) stays the customer's
 * view and keeps its own shape — it is unauthenticated, token-scoped, and
 * carries the agent's own attribution. This one is authenticated, reached by
 * product id, and exists so the person doing the selling can read the
 * product and then share it in one place.
 *
 * ── THE SHARE BUTTON IS THE SAME FLOW AS THE CARD'S ──
 *
 * Through useProductShare(), not a copy: that composable holds the 422
 * handling written for a real incident (a raw "The agent id field is
 * required" reaching an agent's screen). Two copies would drift, and the one
 * that drifts is the one nobody is looking at.
 *
 * BR-1 is checked twice on purpose and neither is decorative: the flag here
 * decides whether the button is live, and ProductShareLinkService::create()
 * decides whether a link is minted. A client-side gate protects nothing —
 * see the composable's docblock.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useAuthStore } from '@/stores/auth'
import { useProductShare } from '@/composables/useProductShare'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'

interface MediaItem {
  id: number
  media_type: 'image' | 'video'
  source_type: 'upload' | 'embed'
  stream_url: string | null
  thumbnail_url: string | null
  embed_url: string | null
  is_primary: boolean
}

interface ProductDetail {
  id: number
  name: string
  price_satang: number
  description: string | null
  spec_description: string | null
  thumbnail_url?: string | null
  brand?: { id: number; name: string } | null
  category?: { id: number; name: string } | null
}

interface Certification {
  user_id: number
  cert_tier?: { key: string } | null
}

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

const loading = ref(true)
const errorMessage = ref('')
const notFound = ref(false)
const product = ref<ProductDetail | null>(null)
const media = ref<MediaItem[]>([])
const certifications = ref<Certification[]>([])

/**
 * The TASK-067 filter, verbatim: a certification row belongs to a USER, and
 * `certifications` is the whole company's list. Dropping the user_id check
 * would let one certified colleague unlock the button for everyone.
 */
const hasPassedBasic = computed(() =>
  certifications.value.some((c) => c.user_id === authStore.user?.id && c.cert_tier?.key === 'basic'),
)

const { sharingProductId, shareError, showShareModal, shareLink, shareHeading, shareProduct } =
  useProductShare({ canShare: () => hasPassedBasic.value, signal: pageAbort.signal })

const productId = computed(() => Number(route.params.id))

/** Photos only. A video needs a player and this page is a reading surface. */
const gallery = computed(() =>
  media.value.filter((m) => m.media_type === 'image' && (m.stream_url || m.thumbnail_url)),
)
const activeIndex = ref(0)
const activeImage = computed(
  () => gallery.value[activeIndex.value]?.stream_url ?? product.value?.thumbnail_url ?? null,
)

function formatBaht(satang: number): string {
  return '฿' + (satang / 100).toLocaleString('th-TH')
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  notFound.value = false

  try {
    const res = await api.get<{ data: ProductDetail }>(`/products/${productId.value}`, pageAbort.signal)
    product.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    // 404 is a REAL state here, not a failure to report as one: an agent can
    // reach this id from a stale tab, and ProductController::show() also 404s
    // a product an admin has deactivated ("ปิดการใช้งาน ซ่อนทุกที่").
    if (e instanceof ApiError && e.status === 404) {
      notFound.value = true
    } else {
      errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลสินค้าไม่สำเร็จ')
    }
    loading.value = false

    return
  }

  // The gallery and the certifications are BOTH optional to this page being
  // useful, so neither is allowed to fail it. A missing gallery falls back to
  // the cover thumbnail; a failed certification read leaves the share button
  // locked, which is the safe direction — the server would refuse anyway.
  await Promise.allSettled([
    api
      .get<{ data: MediaItem[] }>(`/products/${productId.value}/media`, pageAbort.signal)
      .then((res) => {
        media.value = res.data
        const primary = gallery.value.findIndex((m) => m.is_primary)
        activeIndex.value = primary >= 0 ? primary : 0
      }),
    api
      .get<{ data: Certification[] }>('/user-certifications', pageAbort.signal)
      .then((res) => {
        certifications.value = res.data
      }),
  ])

  loading.value = false
}

function goBack(): void {
  // back() when there is somewhere to go back TO, so a tap from the grid
  // returns to the same scroll position; otherwise a real navigation, so a
  // pasted or bookmarked URL does not dead-end on an empty history.
  if (window.history.length > 1) {
    router.back()

    return
  }

  void router.push({ name: 'products' })
}

onMounted(load)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <button
      type="button"
      class="mb-3 flex items-center gap-1.5 px-2 py-1.5 -ml-2 rounded-lg text-ink-card-muted hover:bg-surface-chip hover:text-ink-card transition text-xs font-bold"
      @click="goBack"
    >
      <Icon name="arrow_left" :size="14" />
      {{ td('nav.products2') }}
    </button>

    <LoadingSkeleton v-if="loading" type="list" :rows="4" />

    <EmptyState
      v-else-if="notFound"
      icon="box"
      :title="td('product.not_found')"
      :message="td('product.not_found_help')"
    />

    <div v-else-if="errorMessage" class="px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="load"
      >
        {{ td('common.retry') }}
      </button>
    </div>

    <div v-else-if="product" class="max-w-2xl mx-auto">
      <div class="bg-surface-card/95 border border-line-card rounded-2xl overflow-hidden">
        <AuthenticatedMedia
          v-if="activeImage"
          :src="activeImage"
          type="image"
          class="w-full aspect-square object-cover bg-surface-chip"
        />

        <!-- Thumbnails only when there is more than one photo: a single-item
             filmstrip is a control that cannot do anything. -->
        <div v-if="gallery.length > 1" class="flex gap-2 overflow-x-auto no-scrollbar px-3 pt-3">
          <button
            v-for="(m, i) in gallery"
            :key="m.id"
            type="button"
            class="shrink-0 w-14 h-14 rounded-lg overflow-hidden border-2 transition"
            :class="i === activeIndex ? 'border-brand-500' : 'border-line-card'"
            @click="activeIndex = i"
          >
            <AuthenticatedMedia
              :src="m.thumbnail_url ?? m.stream_url"
              type="image"
              class="w-full h-full object-cover"
            />
          </button>
        </div>

        <div class="p-5">
          <p v-if="product.category" class="text-[11px] text-ink-card-subtle uppercase tracking-wider font-bold">
            {{ product.category.name }}
            <template v-if="product.brand"> · {{ product.brand.name }}</template>
          </p>
          <h1 class="text-xl font-bold text-ink-card leading-tight mt-1">{{ product.name }}</h1>
          <p class="text-2xl font-bold text-ink-brand mt-2">{{ formatBaht(product.price_satang) }}</p>

          <!-- whitespace-pre-line: an admin types these in a textarea, so the
               line breaks they put in are content, not incidental. -->
          <div v-if="product.description" class="mt-4">
            <p class="text-[11px] font-bold text-ink-card-subtle uppercase tracking-wider mb-1">{{ td('common.details') }}</p>
            <p class="text-sm text-ink-card-muted leading-relaxed whitespace-pre-line">{{ product.description }}</p>
          </div>

          <div v-if="product.spec_description" class="mt-4">
            <p class="text-[11px] font-bold text-ink-card-subtle uppercase tracking-wider mb-1">{{ td('product.specs') }}</p>
            <p class="text-sm text-ink-card-muted leading-relaxed whitespace-pre-line">{{ product.spec_description }}</p>
          </div>
        </div>
      </div>

      <p v-if="shareError" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger">
        {{ shareError }}
      </p>

      <!-- The BR-1 reason is spelled out here rather than left in a `title`
           attribute: this page has no amber banner above it the way the grid
           does, and a tooltip does not exist on a touchscreen. -->
      <p v-if="!hasPassedBasic" class="mt-4 px-4 py-3 rounded-xl bg-surface-chip text-xs text-ink-chip leading-relaxed">
        {{ td('cert.needs_basic_share2') }}
      </p>

      <button
        type="button"
        :disabled="!hasPassedBasic || sharingProductId === product.id"
        class="mt-4 w-full min-h-[48px] flex items-center justify-center gap-2 rounded-xl text-sm font-bold transition-all active:scale-95"
        :class="hasPassedBasic
          ? 'bg-surface-primary text-ink-primary hover:opacity-90'
          : 'bg-surface-chip text-ink-chip/50 cursor-not-allowed'"
        @click="shareProduct(product)"
      >
        <Icon name="share" :size="16" />
        {{ sharingProductId === product.id ? 'กำลังสร้าง...' : 'แชร์สินค้านี้' }}
      </button>
    </div>

    <ShareLinkModal
      v-model:show="showShareModal"
      :url="shareLink?.short_url ?? shareLink?.public_url ?? ''"
      :heading="shareHeading"
      email-type="product_share"
      :email-target-id="shareLink?.id ?? null"
    />
  </main>
</template>
