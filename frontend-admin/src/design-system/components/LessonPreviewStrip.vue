<script setup lang="ts">
/**
 * LessonPreviewStrip (Admin) — the 120px inline preview on a lesson row.
 *
 * Human request (2026-08-09): "ย้ายการ Preview แบบทดสอบมาโชว์ที่หน้าหลัก
 * ไม่ต้องมี Modal กำหนดความสูง ส่วน Preview 120 px และคลิ๊กดูภาพขยายได้".
 * The preview used to be reachable only through a "ดูตัวอย่าง" button that
 * opened `LessonPreviewModal`; an admin scanning a section of 20 lessons had
 * to open and close 20 modals to see what they had actually published.
 *
 * So this strip is the AT-A-GLANCE surface and the modal is the ENLARGED
 * one. The strip is a single `<button>` — the whole thing is the target, it
 * takes keyboard focus, and it emits `open` for the parent to raise
 * `LessonPreviewModal` on that lesson. There is deliberately no second
 * control: two buttons that open the same modal is a UI that implies they
 * differ.
 *
 * ── THIS STRIP WRITES NOTHING (ADR-028 §2.3) ────────────────────────
 * Same rule the modal already obeys, for the same reason: an admin
 * skimming lesson rows must not create `module_lesson_progress` rows for
 * themselves, nor be able to mark anything complete. Structural, not
 * careful:
 *  - `frontend-admin` ships no `useLessonProgress` composable and calls
 *    `PUT /module-lessons/{id}/progress` from nowhere;
 *  - nothing here emits a position, and the `<video>` below is muted,
 *    control-less and never played — it exists to paint one frame;
 *  - `POST /module-completions` is not imported or reachable from this
 *    component.
 * The only traffic this causes is READ traffic for the lesson's own media,
 * over the same Sanctum-protected stream route the learner uses
 * (CLAUDE.md §5 rule 6).
 *
 * ── LAZY BY CONSTRUCTION ────────────────────────────────────────────
 * A section with 20 lessons must not fire 20 authenticated fetches and 20
 * pdfjs renders the moment it expands. Every branch that costs a network
 * request or a canvas render is gated behind `visible`, which an
 * IntersectionObserver flips only once the strip scrolls within 200px of
 * the viewport. Branches that cost nothing (quiz card, link card, the
 * "no content" notice) render immediately — a grey placeholder where a
 * static card would do is worse than no lazy-loading at all.
 *
 * ── HONESTY ─────────────────────────────────────────────────────────
 * Academy video has no server-generated poster: ADR-007 §35 trimmed it and
 * `CompressUploadedVideo` only makes posters for `ProductMedia`. So an
 * uploaded video shows its own first frame via a muted
 * `preload="metadata"` element seeking to `#t=0.1`; if the browser never
 * paints one, the strip keeps its play-icon placeholder rather than
 * inventing a thumbnail. An embedded video shows the YouTube still — never
 * a live iframe, because N autoplaying frames on one page is not a
 * thumbnail.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import AuthenticatedMedia from './AuthenticatedMedia.vue'
// Static, and it costs the page nothing new: pdfjs-dist (~426 kB raw /
// ~128 kB gzipped) is ALREADY a static dependency of the Academy admin
// screen through LessonPreviewModal → PdfViewerModal. Wrapping this in
// `defineAsyncComponent` was tried and measured — it moved pdfjs between
// chunks and changed nothing about what the page downloads, so it was
// reverted rather than left in as a lazy-loading claim that is not true.
// What IS deferred is the render: see the `visible` gate below.
import PdfThumbnail from './PdfThumbnail.vue'
import InfoPopover from './InfoPopover.vue'
import { youtubeThumbnailUrl } from '@/utils/embedUrl'
import { PREVIEW_NOT_RECORDED_EXPLANATION } from '@/constants/academyBuilderCopy'

/**
 * Structural subset of AcademyManagementView's `ModuleLessonItem` — the view
 * passes its own object straight through. Kept local (a `<script setup>`
 * block cannot export) and narrow: the strip reads only what it draws.
 */
interface StripLesson {
  id: number
  title: string
  content_type: string
  source_type: 'upload' | 'embed' | null
  content_ref: string | null
  inline_url: string | null
  stream_url: string | null
  page_count: number | null
  quiz_questions?: { id: number }[]
}

const props = defineProps<{ lesson: StripLesson }>()
const emit = defineEmits<{ open: [] }>()

/** Mirrors LessonPreviewModal's `lessonInlineSrc` — inline_url first. */
const inlineSrc = computed(() => props.lesson.inline_url ?? props.lesson.stream_url)

const embedThumbnail = computed(() =>
  props.lesson.source_type === 'embed' ? youtubeThumbnailUrl(props.lesson.content_ref) : null,
)

/**
 * One closed set, so the template has exactly one branch per case and no
 * chain of overlapping `v-else-if` conditions to keep in step with the
 * modal's.
 */
type StripKind = 'upload_video' | 'upload_image' | 'upload_pdf' | 'embed_thumb' | 'external' | 'quiz' | 'empty'

const kind = computed<StripKind>(() => {
  const l = props.lesson
  if (l.content_type === 'quiz') return 'quiz'
  if (l.source_type === 'upload' && inlineSrc.value) {
    if (l.content_type === 'video') return 'upload_video'
    if (l.content_type === 'image') return 'upload_image'
    if (l.content_type === 'pdf') return 'upload_pdf'
  }
  if (embedThumbnail.value) return 'embed_thumb'
  if (l.content_ref) return 'external'

  return 'empty'
})

/** True for the branches that cost a network request or a pdfjs render. */
const isLazyKind = computed(() =>
  ['upload_video', 'upload_image', 'upload_pdf', 'embed_thumb'].includes(kind.value),
)

const CONTENT_TYPE_LABELS: Record<string, string> = {
  video: 'วิดีโอ',
  pdf: 'เอกสาร PDF',
  image: 'รูปภาพ',
  link: 'ลิงก์',
  quiz: 'แบบทดสอบทบทวน',
}
const contentTypeLabel = computed(() => CONTENT_TYPE_LABELS[props.lesson.content_type] ?? 'เนื้อหา')

/** The one-line description under the heading — what the admin is looking at. */
const detailLine = computed(() => {
  switch (kind.value) {
    case 'upload_video':
      return 'วิดีโอที่อัปโหลด — แสดงเฟรมแรก'
    case 'embed_thumb':
      return 'วิดีโอ embed — ภาพปกจาก YouTube'
    case 'upload_pdf':
      return props.lesson.page_count ? `เอกสาร PDF · ${props.lesson.page_count} หน้า` : 'เอกสาร PDF — หน้าแรก'
    case 'upload_image':
      return 'รูปภาพที่อัปโหลด'
    case 'quiz':
      return `${props.lesson.quiz_questions?.length ?? 0} คำถาม`
    case 'external':
      return 'ลิงก์ภายนอก — ผู้เรียนจะเปิดในแท็บใหม่'
    default:
      return 'ยังไม่มีไฟล์เนื้อหา'
  }
})

// ── Lazy loading ─────────────────────────────────────────────────────
const rootEl = ref<HTMLElement | null>(null)
const visible = ref(false)
let observer: IntersectionObserver | null = null

onMounted(() => {
  if (!isLazyKind.value) {
    visible.value = true
    return
  }
  // No IntersectionObserver (old browser, jsdom in a unit test) — show the
  // content rather than a permanent skeleton. Failing open on a READ-only
  // preview costs bandwidth; failing closed would hide the feature.
  if (typeof IntersectionObserver === 'undefined' || !rootEl.value) {
    visible.value = true
    return
  }
  observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        visible.value = true
        observer?.disconnect()
        observer = null
      }
    },
    // Start a little before the strip is on screen so scrolling does not
    // reveal a row of skeletons.
    { rootMargin: '200px 0px' },
  )
  observer.observe(rootEl.value)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
})

// ── Uploaded video first frame ───────────────────────────────────────
const videoReady = ref(false)
const videoFailed = ref(false)
/**
 * `#t=0.1` asks the browser to seek before painting, which is what makes a
 * frame (rather than a black box) appear under `preload="metadata"`. The
 * fragment is never sent to the server, and `crossorigin="use-credentials"`
 * is what lets the browser's own ranged GETs carry the session cookie
 * (ADR-028 §2.5) with the Policy check still running before any bytes.
 */
const videoPosterSrc = computed(() => (inlineSrc.value ? `${inlineSrc.value}#t=0.1` : ''))
</script>

<template>
  <!--
    TASK-188 §4.B1 — the ⓘ is a SIBLING of the strip, not a child of it.
    InfoPopover's trigger is a real <button>, and a button inside a button is
    invalid HTML that browsers silently reflow; `rootEl` (the
    IntersectionObserver target) moves out to this wrapper for the same reason.
  -->
  <div ref="rootEl" class="flex items-start gap-1">
  <button
    type="button"
    class="group flex-1 min-w-0 flex items-stretch gap-3 h-[120px] rounded-xl border border-slate-200 bg-white text-left overflow-hidden transition hover:border-brand-400 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1"
    :aria-label="`ดูตัวอย่างที่ตัวแทนจะเห็นของบทเรียน ${lesson.title} แบบขยาย`"
    @click="emit('open')"
  >
    <!-- ── Media box — fixed width, never stretched (object-cover) ──── -->
    <div class="relative shrink-0 w-[140px] sm:w-[190px] h-[120px] bg-slate-100 overflow-hidden">
      <!-- Not scrolled into view yet: a skeleton, not a fetch. -->
      <div v-if="!visible" class="w-full h-full flex items-center justify-center bg-slate-100">
        <Icon name="clock" :size="18" class="text-slate-300 animate-pulse" />
      </div>

      <!-- 1. Uploaded video — its own first frame, muted and never played. -->
      <template v-else-if="kind === 'upload_video'">
        <video
          v-if="!videoFailed"
          :src="videoPosterSrc"
          crossorigin="use-credentials"
          muted
          playsinline
          preload="metadata"
          class="w-full h-full object-cover bg-black"
          :class="videoReady ? '' : 'opacity-0'"
          @loadeddata="videoReady = true"
          @error="videoFailed = true"
        />
        <!-- Placeholder underneath until a real frame paints, and for good
             after a failure. Never a broken-image glyph. -->
        <div
          v-if="!videoReady || videoFailed"
          class="absolute inset-0 flex items-center justify-center bg-slate-100 text-slate-300"
        >
          <Icon :name="videoFailed ? 'alert' : 'play'" :size="22" :class="videoFailed ? 'text-slate-400' : ''" />
        </div>
      </template>

      <!-- 2. Uploaded image — the image itself. -->
      <AuthenticatedMedia
        v-else-if="kind === 'upload_image'"
        :src="inlineSrc"
        type="image"
        class="w-full h-full object-cover"
      />

      <!-- 3. Uploaded PDF — page 1, rendered by the existing thumbnailer. -->
      <PdfThumbnail v-else-if="kind === 'upload_pdf'" :stream-url="inlineSrc" />

      <!-- 4. Embedded video — the still, plus a play glyph. Never an iframe. -->
      <template v-else-if="kind === 'embed_thumb'">
        <img :src="embedThumbnail!" alt="" class="w-full h-full object-cover" />
        <span class="absolute inset-0 flex items-center justify-center">
          <span class="w-9 h-9 rounded-full bg-black/55 flex items-center justify-center">
            <Icon name="play" :size="20" class="text-white" />
          </span>
        </span>
      </template>

      <!-- 5. Quiz — an icon card carrying the question count. -->
      <div
        v-else-if="kind === 'quiz'"
        class="w-full h-full flex flex-col items-center justify-center gap-1 bg-slate-50 text-slate-400"
      >
        <Icon name="check_square" :size="22" />
        <span class="text-[11px] font-bold text-slate-500">{{ lesson.quiz_questions?.length ?? 0 }} คำถาม</span>
      </div>

      <!-- 6. External link / external pdf — a compact card, no iframe. -->
      <div
        v-else-if="kind === 'external'"
        class="w-full h-full flex flex-col items-center justify-center gap-1 px-2 bg-slate-50 text-slate-400"
      >
        <Icon name="link" :size="20" />
        <span class="text-[10px] text-slate-500 text-center break-all line-clamp-2">{{ lesson.content_ref }}</span>
      </div>

      <!-- 7. Nothing at all — the authoring gap, at full strip height. -->
      <div
        v-else
        class="w-full h-full flex flex-col items-center justify-center gap-1 px-2 bg-amber-50 border-r border-dashed border-amber-200 text-amber-600"
      >
        <Icon name="alert" :size="20" />
        <span class="text-[10px] font-bold text-center leading-tight">ยังไม่มีไฟล์เนื้อหา</span>
      </div>
    </div>

    <!-- ── Label — states plainly what the strip is and what a click does ── -->
    <div class="min-w-0 flex-1 flex flex-col justify-center py-2 pr-3">
      <p class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
        <Icon name="eye" :size="13" class="text-slate-400 shrink-0" />
        <span class="truncate">ตัวอย่างที่ตัวแทนจะเห็น</span>
      </p>
      <p class="mt-0.5 text-[11px] text-slate-400 truncate">{{ contentTypeLabel }} · {{ detailLine }}</p>
      <span
        class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-bold text-brand-600 group-hover:underline"
      >
        <Icon name="search" :size="12" class="shrink-0" />
        คลิกเพื่อดูขนาดใหญ่
      </span>
    </div>
  </button>
    <InfoPopover
      label="ตัวอย่างที่ตัวแทนจะเห็น"
      :text="PREVIEW_NOT_RECORDED_EXPLANATION"
      class="mt-1"
    />
  </div>
</template>
