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

/**
 * Is the big panel showing?
 *
 * This used to be implicit: the collapsed bottom_strip bar was `v-if` and the
 * panel was its `v-else-if`, so they could never both render. Wrapping the
 * panel in its own <Transition> broke that pairing — a `v-else-if` cannot
 * reach across an element boundary — and without stating the condition, a
 * collapsed strip would render with the full panel sitting on top of it.
 *
 * Written out rather than restored as a chain, so the fix survives anyone
 * moving these two blocks again.
 */
const showExpanded = computed(
  () =>
    Boolean(props.show && props.announcement) &&
    !(displayStyle.value === 'bottom_strip' && !stripExpanded.value),
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
      /*
       * 2026-08-22 (human, with a screenshot): "ปรับให้เป็น 80% ของ Screen พอ".
       *
       * This used to be `bg-surface-card` with NO scrim, because the overlay
       * WAS the panel — full_screen covered the viewport edge to edge, so a
       * backdrop would have been invisible underneath it.
       *
       * Once the panel is 80% that reasoning inverts: a card-coloured sheet
       * behind a card-coloured panel makes the 80% impossible to see, and the
       * modal reads exactly as full-screen as before. The scrim is what turns
       * "80% tall" into something the eye can actually register as a panel
       * floating over the page.
       *
       * ADR-023 note: `bg-black/60` is a SCRIM, not a surface — same call the
       * other three styles here already made. It is not themed and must not
       * be: it darkens whatever is behind it, on any tenant.
       */
      return 'bg-black/60 items-center justify-center p-4'
    case 'centered_card':
      return 'bg-black/60 items-center justify-center p-4'
    case 'bottom_strip':
    case 'bottom_sheet':
    default:
      return 'bg-black/60 items-end sm:items-center justify-center'
  }
})
/*
 * EVERY STYLE IS CAPPED AT 80vh (human, 2026-08-22).
 *
 * They were 100% / 85vh / 92vh. At 92vh the sliver of backdrop left above a
 * bottom sheet is about a finger's width — indistinguishable from full
 * screen on a phone, which is what the screenshot showed and what the
 * request is about. 80vh leaves a tenth of the screen at each end, so the
 * page behind stays visible and the panel reads as a panel.
 *
 * The four styles keep their distinct GEOMETRY — where the panel sits, how
 * wide it is, which corners are round. Only the height agrees now.
 */
const cardClasses = computed(() => {
  switch (displayStyle.value) {
    case 'full_screen':
      // No longer literally full-screen. It stays the WIDEST style (the
      // others clamp to max-w-md / max-w-lg), which is what distinguishes it
      // now — see the admin-setting note in the task report.
      return 'w-full h-[80vh] rounded-3xl'
    case 'centered_card':
      return 'w-full max-w-md rounded-3xl max-h-[80vh]'
    case 'bottom_strip':
    case 'bottom_sheet':
    default:
      return 'w-full sm:max-w-lg sm:rounded-3xl rounded-t-3xl max-h-[80vh]'
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
 * The card's own max-height + `overflow-y-auto` still stand behind this, so
 * the modal can never grow past the viewport.
 *
 * ── LOWERED TO 58vh, 2026-08-22 ──
 *
 * The panel is now capped at 80vh (see cardClasses). An image also capped at
 * 80vh therefore fills the ENTIRE panel on any portrait poster, and the title
 * is pushed below the fold inside a scrollable card — which is the exact
 * complaint 80vh was introduced to fix, reappearing one level down. Shrinking
 * the modal without shrinking the image would have changed nothing the human
 * can see.
 *
 * 58vh is roughly three quarters of the panel, leaving the title, the date
 * and the first lines of the body visible without scrolling — the point of
 * the cap in the first place. `object-contain` still guarantees a clamped
 * image is letterboxed rather than squashed, and an ordinary wide banner is
 * far below this cap so it renders untouched.
 */
const imageClasses = computed(() =>
  displayStyle.value === 'full_screen' ? 'max-h-[58vh]' : 'rounded-t-3xl max-h-[58vh]',
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
    <!-- FADE UP (human, 2026-08-22: "มี Animation Fade ขึ้นข้าๆ").
         The modal used to appear in a single frame, which on a panel this
         large reads as a flash rather than as something opening. The scrim
         and the panel are animated SEPARATELY — the scrim only fades, the
         panel fades AND rises — because a backdrop that slides is a backdrop
         you notice, and the whole job of a scrim is to not be noticed.
         Leaving is quicker than entering (200ms vs 340ms): dismissing is a
         decision already made, and waiting to watch it play out is the part
         that feels sluggish. The global prefers-reduced-motion rule in
         assets/main.css already neutralises both with !important. -->
    <Transition name="ann-modal">
      <!-- `&& announcement` is not redundant with showExpanded, which already
           checks it: vue-tsc narrows the template's `announcement` to
           non-null from the v-if EXPRESSION, not from a computed that
           happens to test the same thing. Without it every `announcement.x`
           below is a "possibly null" error. -->
      <div
        v-if="showExpanded && announcement"
        class="fixed inset-0 z-[70] flex"
        :class="overlayClasses"
        @click.self="handleBackdropClick"
      >
        <div class="ann-modal-panel bg-surface-card overflow-y-auto relative" :class="cardClasses">
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
    </Transition>
  </Teleport>
</template>

<style scoped>
/*
 * The scrim fades; the panel fades AND rises.
 *
 * Two separate rules rather than one on the wrapper, because animating the
 * backdrop's position is the difference between "a panel opened" and "the
 * whole screen moved". The scrim must not draw attention to itself.
 *
 * The easing is a decelerating curve (fast out, slow in): the panel arrives
 * quickly and settles, which reads as physical. A linear or ease-in-out rise
 * over the same 340ms feels like it is being dragged.
 *
 * 24px of travel, not more. A large panel rising a long distance looks like
 * it is coming from off-screen; the intent here is that it appears in place
 * and settles.
 *
 * NOT wrapped in a prefers-reduced-motion query: assets/main.css already
 * flattens every transition-duration to 0.01ms with !important under that
 * media query, so adding one here would be dead code that looks load-bearing.
 */
.ann-modal-enter-active {
  transition: opacity 240ms ease-out;
}
.ann-modal-leave-active {
  transition: opacity 200ms ease-in;
}
.ann-modal-enter-from,
.ann-modal-leave-to {
  opacity: 0;
}

.ann-modal-enter-active .ann-modal-panel {
  transition:
    transform 340ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 240ms ease-out;
}
.ann-modal-leave-active .ann-modal-panel {
  transition:
    transform 200ms ease-in,
    opacity 200ms ease-in;
}
.ann-modal-enter-from .ann-modal-panel,
.ann-modal-leave-to .ann-modal-panel {
  opacity: 0;
  transform: translateY(24px);
}
</style>
