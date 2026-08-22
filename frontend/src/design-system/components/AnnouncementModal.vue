<script setup lang="ts">
/**
 * AnnouncementModal — TASK-075 (2026-08-02, human-confirmed via
 * AskUserQuestion): "ข่าวสารประกาศจากระบบต้องเป็นแบบ banner แบบ modal
 * ขนาดใหญ่บน Mobile" — a full-screen (mobile) / large centered (desktop)
 * detail view for one Announcement, showing its image, video (upload or
 * embed), and full content — not just the small card preview.
 *
 * Used two ways:
 *   1. Auto-popup on HomeView load for the newest not-yet-seen
 *      announcement (see utils/seenAnnouncements.ts).
 *   2. Manually, when an agent taps a card on HomeView or
 *      AnnouncementsListView.
 *
 * TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) — adds 4
 * admin-configurable display styles (BR-7, one global value per company,
 * fetched by the caller from GET /announcement-settings and passed in as
 * `displayStyle`):
 *   - full_screen:   covers the entire viewport, no backdrop, opaque.
 *   - bottom_sheet:  slides up from the bottom (original TASK-075 look,
 *                    still the default/fallback).
 *   - centered_card: compact card centered on screen, backdrop visible
 *                    all around — least intrusive of the 3 "blocking" styles.
 *   - bottom_strip:  starts as a small non-blocking bar pinned to the
 *                    bottom (tap to expand into the same bottom_sheet-style
 *                    detail, tap the backdrop to collapse back to the
 *                    strip, X always fully closes). `startExpanded`
 *                    controls whether it opens already-expanded (manual
 *                    card taps) or collapsed (auto-popup on Home).
 *
 * Pure presentational component — the caller owns fetching data and
 * seen-state; this only renders `announcement` when `show` is true.
 */
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { toEmbedUrl } from '@/utils/embedUrl'

export interface AnnouncementModalVideo {
  type: 'upload' | 'embed'
  url: string
}
export interface AnnouncementModalItem {
  id: number
  title: string
  content: string
  is_pinned: boolean
  published_at: string | null
  image_url: string | null
  video: AnnouncementModalVideo | null
}
export type AnnouncementDisplayStyle = 'full_screen' | 'bottom_sheet' | 'centered_card' | 'bottom_strip'

const props = defineProps<{
  show: boolean
  announcement: AnnouncementModalItem | null
  displayStyle?: AnnouncementDisplayStyle
  /** Only relevant for displayStyle === 'bottom_strip'. Default true (manual opens). */
  startExpanded?: boolean
}>()
const emit = defineEmits<{ (e: 'close'): void }>()

const displayStyle = computed<AnnouncementDisplayStyle>(() => props.displayStyle ?? 'bottom_sheet')
const stripExpanded = ref(props.startExpanded ?? true)
watch(
  () => [props.show, props.announcement?.id],
  () => {
    if (props.show) stripExpanded.value = props.startExpanded ?? true
  },
)

function handleBackdropClick() {
  // bottom_strip: backdrop tap collapses back to the non-blocking strip
  // instead of fully dismissing — only the X button fully closes it.
  if (displayStyle.value === 'bottom_strip') {
    stripExpanded.value = false
  } else {
    emit('close')
  }
}

const overlayClasses = computed(() => {
  switch (displayStyle.value) {
    case 'full_screen':
      // TASK-098 / ADR-023: full_screen has NO backdrop — the overlay IS
      // the panel surface, so it takes the card token rather than a fixed
      // white (which on a dark tenant covered the screen in a white sheet).
      return 'bg-surface-card'
    case 'centered_card':
      return 'bg-black/60 items-center justify-center p-4'
    case 'bottom_strip':
    case 'bottom_sheet':
    default:
      return 'bg-black/60 items-end sm:items-center justify-center'
  }
})
const cardClasses = computed(() => {
  switch (displayStyle.value) {
    case 'full_screen':
      return 'w-full h-full max-h-none rounded-none'
    case 'centered_card':
      return 'w-full max-w-md rounded-3xl max-h-[85vh]'
    case 'bottom_strip':
    case 'bottom_sheet':
    default:
      return 'w-full sm:max-w-lg sm:rounded-3xl rounded-t-3xl max-h-[92vh]'
  }
})
/**
 * TASK-228 (2026-08-20, human-reported with a screenshot) — the banner
 * image is shown WHOLE, at its own aspect ratio.
 *
 * It used to be `h-56 sm:h-64 object-cover` (and `h-72` on full_screen):
 * a fixed-height box that `object-cover` then filled by CROPPING whatever
 * did not fit. Every announcement image was cut to 224/256/288px tall
 * regardless of its real shape, so a wide product banner lost its bottom
 * strip — in the reported case the "โซนดีล GENESENN" line, i.e. the part
 * the announcement existed to show.
 *
 * `w-full` + `h-auto` and no `object-*` at all: the browser derives the
 * height from the intrinsic ratio, so nothing is ever cropped. Note this
 * is NOT `object-contain` — contain would keep the fixed box and letterbox
 * the image inside it, which shows the whole image but leaves dead bars
 * above and below. Dropping the height instead means no bars and no crop.
 *
 * ── CAPPED AT 80vh, 2026-08-21 (human, revising their own earlier call) ──
 *
 * TASK-228 left the height DELIBERATELY UNCAPPED and this docblock recorded
 * the trade-off accepted at the time: "with a very tall image the title and
 * body sit below the fold". A tall portrait poster then did exactly that in
 * production — the image filled the sheet and the headline was somewhere
 * past the bottom edge, so the announcement opened as a picture with no
 * words. Hence "ปรับรูป banner หน้าแรกแสดง 80% ของหน้าจอ".
 *
 * `max-h-[80vh]` + `object-contain` keeps what TASK-228 was actually
 * defending — the image is never CROPPED — while reserving the last fifth
 * of the screen for the title.
 *
 * THE PAIRING IS NOT OPTIONAL. max-height alone would SQUASH a clamped
 * image, because `w-full` is still forcing the width; object-contain is what
 * makes the clamp preserve the ratio. And object-contain has no effect at
 * all until the cap bites, so an ordinary wide banner renders exactly as it
 * did before this change — only a very tall one is letterboxed at the
 * sides, which is the honest way to show a whole portrait image in a
 * landscape slot.
 *
 * The card's own `max-h-[85vh]`/`max-h-[92vh]` + `overflow-y-auto` still
 * stand behind this, so the modal can never grow past the viewport.
 */
const imageClasses = computed(() =>
  displayStyle.value === 'full_screen' ? 'max-h-[80vh]' : 'rounded-t-3xl max-h-[80vh]',
)

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- TASK-098 / ADR-023: panel colours come from the surface/ink token
       layer (`bg-surface-card`, `text-ink-card*`, `border-line-card`,
       `bg-surface-chip`) rather than hardcoded white/slate — every one of
       the 4 display styles was previously outside the theme (ADR-023 §2.1).
       The `bg-black/*` backdrops and the close button that sits on one stay
       as they are: scrims, not surfaces. -->
  <Teleport to="body">
    <!-- bottom_strip, collapsed: small non-blocking bar pinned to the bottom -->
    <div
      v-if="show && announcement && displayStyle === 'bottom_strip' && !stripExpanded"
      class="fixed bottom-0 inset-x-0 z-[70] bg-surface-card border-t border-line-card shadow-[0_-4px_16px_rgba(0,0,0,0.12)] px-4 py-3 flex items-center gap-3 cursor-pointer"
      @click="stripExpanded = true"
    >
      <img v-if="announcement.image_url" :src="announcement.image_url" alt="" class="w-10 h-10 rounded-lg object-cover shrink-0" />
      <Icon v-else name="megaphone" :size="20" class="text-ink-brand shrink-0" />
      <div class="flex-1 min-w-0">
        <p class="text-sm font-bold text-ink-card truncate">{{ announcement.title }}</p>
        <p class="text-xs text-ink-card-subtle truncate">{{ announcement.content }}</p>
      </div>
      <button
        type="button"
        class="w-7 h-7 rounded-full bg-surface-chip text-ink-chip flex items-center justify-center shrink-0"
        @click.stop="emit('close')"
      >
        <Icon name="x" :size="14" />
      </button>
    </div>

    <!-- Expanded detail overlay: full_screen / bottom_sheet / centered_card, or bottom_strip once tapped -->
    <div
      v-else-if="show && announcement"
      class="fixed inset-0 z-[70] flex"
      :class="overlayClasses"
      @click.self="handleBackdropClick"
    >
      <div class="bg-surface-card overflow-y-auto relative" :class="cardClasses">
        <button
          type="button"
          class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/40 text-white flex items-center justify-center z-10 hover:bg-black/60"
          @click="emit('close')"
        >
          <Icon name="x" :size="16" />
        </button>

        <!-- `block` kills the inline-image baseline gap that would otherwise
             show a sliver of card between the image and the panel edge.
             `bg-surface-chip` gives the area a themed placeholder while the
             image is still loading, instead of a flash of card colour. -->
        <img
          v-if="announcement.image_url"
          :src="announcement.image_url"
          alt=""
          class="w-full h-auto block bg-surface-chip object-contain"
          :class="imageClasses"
        />

        <div class="p-5 space-y-3">
          <div class="flex items-center gap-1.5">
            <Icon v-if="announcement.is_pinned" name="star" :size="16" class="text-ink-warning shrink-0" />
            <h2 class="text-lg font-bold text-ink-card">{{ announcement.title }}</h2>
          </div>
          <p v-if="announcement.published_at" class="text-xs text-ink-card-subtle">{{ formatDate(announcement.published_at) }}</p>

          <video
            v-if="announcement.video?.type === 'upload'"
            :src="announcement.video.url"
            controls
            class="w-full rounded-xl bg-black"
          ></video>
          <div v-else-if="announcement.video?.type === 'embed'" class="aspect-video w-full rounded-xl overflow-hidden bg-black">
            <!-- SECURITY AUDIT 2026-08-21 (V11) — through toEmbedUrl(), not
                 raw. This src is an admin-typed URL (announcements.video_embed_url)
                 and it was the one embed in either app that skipped the
                 shared helper, so it skipped the http(s)-only check the
                 helper now performs. It also gains the YouTube watch→embed
                 rewrite it never had, which is a bug fix in its own right:
                 a pasted youtube.com/watch link renders a dead grey box. -->
            <iframe :src="toEmbedUrl(announcement.video.url)" class="w-full h-full" allowfullscreen frameborder="0"></iframe>
          </div>

          <p class="text-sm text-ink-card whitespace-pre-line leading-relaxed">{{ announcement.content }}</p>
        </div>
      </div>
    </div>
  </Teleport>
</template>
