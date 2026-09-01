<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * AnnouncementsListView — TASK-075 (2026-08-02, human-confirmed via
 * AskUserQuestion): full announcements list + search, reached via the
 * "ดูทั้งหมด" link on HomeView's ข่าวสาร section (human explicitly chose
 * this over adding a 6th BottomNav tab).
 *
 * Search is client-side (title + content, case-insensitive) — the
 * `/announcements` response is already the agent's full company-scoped,
 * published/audience-filtered list (same endpoint HomeView uses), and
 * that list is small enough in practice that a server-side `q` param
 * isn't warranted (consistent with how e.g. AffiliateLinksView filters
 * client-side too).
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — one shared error normalizer, no raw HTTP
// status codes in user-facing copy (utils/apiError.ts).
import { apiErrorMessage } from '@/utils/apiError'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import AnnouncementModal,{ type AnnouncementDisplayStyle } from '@/design-system/components/AnnouncementModal.vue'
// TASK-080 — announcements can also render as an inline banner carousel.
import AnnouncementBanner from '@/design-system/components/AnnouncementBanner.vue'
import { bannerAnnouncementsForPage, type BannerAwareAnnouncement } from '@/utils/announcementBanners'
import { recordAnnouncementView } from '@/utils/seenAnnouncements'

// TASK-080 — the /announcements payload now also carries the three
// display flags (show_as_modal / show_as_banner / banner_pages) on top of
// what AnnouncementModal renders; see utils/announcementBanners.ts.
type Announcement = BannerAwareAnnouncement

// TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) — admin-
// configured display style (BR-7, one global value per company). Fallback
// matches config/announcements.php's default_display_style. startExpanded
// is intentionally left at AnnouncementModal's own default (true) — every
// open on this page is a manual card tap, never an auto-popup.
const FALLBACK_DISPLAY_STYLE: AnnouncementDisplayStyle = 'bottom_sheet'
const announcementDisplayStyle = ref<AnnouncementDisplayStyle>(FALLBACK_DISPLAY_STYLE)

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(true)
const errorMessage = ref('')
const announcements = ref<Announcement[]>([])
const searchQuery = ref('')

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [res, settingsRes] = await Promise.all([
      api.get<{ data: Announcement[] }>('/announcements'),
      api.get<{ data: { repeat_count: number; display_style: AnnouncementDisplayStyle } }>('/announcement-settings').catch(() => null),
    ])
    announcements.value = res.data
    announcementDisplayStyle.value = settingsRes?.data.display_style ?? FALLBACK_DISPLAY_STYLE
    openRequestedAnnouncement()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข่าวสารไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
onMounted(loadAll)

/**
 * `?a={id}` opens that announcement straight away.
 *
 * This is the other half of the 2026-08-22 notification fix. An announcement
 * notification carries `data.announcement_id`, and until now nothing read it:
 * the tap dropped the reader on a page of headlines and left them to find
 * which one had pinged them. On a company that publishes weekly, "which one
 * was it" is a real question by Friday.
 *
 * Resolved AFTER the list loads because the modal renders a row from that
 * list, not a fetch of its own. An id that is not in the agent's own
 * company-scoped, audience-filtered list simply opens nothing — the list is
 * already the authorisation boundary, so a guessed id in the URL discloses
 * nothing.
 */
function openRequestedAnnouncement(): void {
  const requested = route.query.a
  if (typeof requested !== 'string') return

  const match = announcements.value.find((a) => String(a.id) === requested)
  if (match) openAnnouncement(match)

  // Drop the param either way, so a refresh (or a back-navigation into this
  // page) does not re-open a modal the reader has already dismissed.
  router.replace({ path: '/announcements' })
}

const filteredAnnouncements = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return announcements.value
  return announcements.value.filter(
    (a) => a.title.toLowerCase().includes(q) || a.content.toLowerCase().includes(q),
  )
})

// TASK-080 — banner carousel for page key 'announcements'. Deliberately
// derived from the FULL list, not `filteredAnnouncements`: a banner is a
// promoted surface, not a search result, so typing in the search box
// filters the list below without yanking the banners out from under it.
const bannerAnnouncements = computed(() =>
  bannerAnnouncementsForPage(announcements.value, 'announcements'),
)

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH')
}

const modalAnnouncement = ref<Announcement | null>(null)
const showAnnouncementModal = ref(false)
function openAnnouncement(a: Announcement) {
  modalAnnouncement.value = a
  showAnnouncementModal.value = true
  recordAnnouncementView(auth.user?.id, a.id)
}
function closeAnnouncementModal() {
  showAnnouncementModal.value = false
}
</script>

<template>
  <!-- TASK-079 Phase 4 (2026-08-03, UX audit): last of the 4 views moved off
       the hand-rolled shell onto HeroHeader. The search box moves into the
       #tabs slot, which is what that slot is for — it flattens into the same
       card as the header instead of floating as a second surface. -->
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: var(--app-font);">
    <HeroHeader
      icon="megaphone"
      :title="td('news.all')"
      :subtitle="td('news.subtitle')"
      accent-color="brand"
      storage-key="announcements"
      back-page="/"
      :back-label="td('nav.home2')"
    >
      <template #tabs>
        <div class="px-4 py-3">
          <div class="relative">
            <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              v-model="searchQuery"
              type="text"
              :placeholder="td('news.search_ph')"
              class="text-ink-input placeholder:text-ink-input-placeholder w-full min-h-[44px] pl-9 pr-3 py-2.5 rounded-xl border border-line-input text-sm bg-surface-input focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-400"
            />
          </div>
        </div>
      </template>
    </HeroHeader>

    <!-- TASK-080 — announcement banners (page key 'announcements'), above
         the list. Outside the <Transition> below on purpose: it renders
         nothing while loading (empty items) and the Transition can only
         hold one child per branch. -->
    <AnnouncementBanner :items="bannerAnnouncements" class="mt-4" @select="openAnnouncement" />

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <!-- Loading. TASK-079 Phase 4 (UX audit): hand-rolled `animate-pulse`
           boxes replaced by the shared skeleton. -->
      <LoadingSkeleton v-if="loading" type="list" :rows="5" />

      <!-- Error -->
      <div
        v-else-if="errorMessage"
        class="mt-4 bg-surface-card/95 border border-line-card rounded-2xl shadow-sm p-5 flex flex-col items-center gap-3 text-center"
      >
        <Icon name="alert" :size="32" class="text-ink-danger" />
        <p class="text-sm text-ink-card-muted font-bold">{{ errorMessage }}</p>
        <AppButton @click="loadAll">{{ td('common.retry') }}</AppButton>
      </div>

      <!-- Empty (no announcements at all) -->
      <div
        v-else-if="!announcements.length"
        class="flex flex-col items-center justify-center gap-2 py-16 text-center"
      >
        <Icon name="megaphone" :size="40" class="text-ink-card-subtle" />
        <p class="text-sm text-ink-card-muted font-bold">{{ td('news.empty') }}</p>
      </div>

      <!-- Empty (search has no matches) -->
      <div
        v-else-if="!filteredAnnouncements.length"
        class="flex flex-col items-center justify-center gap-2 py-16 text-center"
      >
        <Icon name="search" :size="40" class="text-ink-card-subtle" />
        <p class="text-sm text-ink-card-muted font-bold">{{ td('news.no_result') }}</p>
      </div>

      <!-- List. TASK-079 Phase 3 (UX audit): `hover:` never fires on a touchscreen,
             so tapping this card used to produce zero visual response and
             agents tapped it twice. `active:` is the touch equivalent. -->
      <div v-else class="space-y-2 mt-4">
        <button v-for="a in filteredAnnouncements" :key="a.id" type="button" class="w-full text-left" @click="openAnnouncement(a)">
          <AppCard interactive padding="sm" class="flex items-center gap-3">
            <img
              v-if="a.image_url"
              :src="a.image_url"
              alt=""
              class="w-14 h-14 rounded-xl object-cover border border-line-card shrink-0"
            />
            <div v-else class="w-14 h-14 rounded-xl bg-surface-chip border border-line-card-subtle flex items-center justify-center shrink-0">
              <Icon name="megaphone" :size="20" class="text-ink-card-subtle" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-1.5">
                <Icon v-if="a.is_pinned" name="star" :size="14" class="text-ink-warning shrink-0" />
                <p class="text-sm font-bold text-ink-card truncate">{{ a.title }}</p>
              </div>
              <p v-if="a.content" class="text-xs text-ink-card-muted line-clamp-1 mt-0.5">{{ a.content }}</p>
              <p v-if="a.published_at" class="text-[11px] text-ink-card-subtle mt-0.5">{{ formatDate(a.published_at) }}</p>
            </div>
          </AppCard>
        </button>
      </div>
    </Transition>

    <AnnouncementModal
      :show="showAnnouncementModal"
      :announcement="modalAnnouncement"
      :display-style="announcementDisplayStyle"
      @close="closeAnnouncementModal"
    />
  </main>
</template>
