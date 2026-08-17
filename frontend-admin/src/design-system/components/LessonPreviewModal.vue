<script setup lang="ts">
/**
 * LessonPreviewModal (Admin) — "ตัวอย่างที่ตัวแทนจะเห็น".
 *
 * Human request (2026-08-09): "บทเรียน หลังจากเพิ่มแล้วต้องมีการ Preview
 * ให้ admin ได้เห็นว่าจะไปโชว์แบบไหนให้กับ Agent เห็น" — an admin authoring
 * a lesson had no way to see what the learner actually gets. The row showed
 * metadata only, so publishing was blind.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * The thing this previews is the lesson body in
 * `frontend/src/views/AcademyView.vue` — `openLesson()`,
 * `lessonActionLabel()`, `contentTypeLabel()`, `lessonContentIcon()` and
 * the lesson-row template around it. Every per-content-type branch below
 * is a copy of a branch there.
 *
 * ADR-003 accepts duplication between the two apps, but this is now a
 * THIRD place that knows how a lesson renders. If AcademyView changes and
 * this does not, the preview lies to the admin — which is worse than no
 * preview, because they will trust it. So: when you touch the learner's
 * lesson body, touch this file in the same PR, and keep this copy as thin
 * as it is (no clever extras — every line of divergent logic is a future
 * lie).
 * ─────────────────────────────────────────────────────────────────────
 *
 * ── THIS PREVIEW WRITES NOTHING (ADR-028 §2.3) ──────────────────────
 * An admin skimming a video here must not create a
 * `module_lesson_progress` row for themselves, and must never be able to
 * mark themselves complete. Guaranteed structurally, not by care:
 *  - the video player is `LessonVideoPlayer.vue` (Admin copy), which has
 *    no `position`/`flush` emits at all;
 *  - the PDF surface is the existing Admin `PdfViewerModal.vue`, which
 *    has no progress reporting and never had any;
 *  - `frontend-admin` ships no `useLessonProgress` composable and calls
 *    `PUT /module-lessons/{id}/progress` from nowhere;
 *  - the "ทำเครื่องหมายว่าเรียนจบ" control is rendered `disabled`, with no
 *    handler bound — `POST /module-completions` is not reachable from this
 *    component.
 * The only network traffic this modal causes is READ traffic for the
 * lesson's own media, through the same Sanctum-protected stream route the
 * learner uses (CLAUDE.md §5 rule 6).
 *
 * ── HONESTY ─────────────────────────────────────────────────────────
 * This is a preview, not a live learner session. The Agent Portal is
 * mobile-first and this is a desktop admin screen, so the frame below is
 * constrained to ~390px to give roughly the learner's proportions — it is
 * a rough rendering, deliberately not claimed to be pixel-identical.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import AuthenticatedMedia from './AuthenticatedMedia.vue'
import LessonVideoPlayer from './LessonVideoPlayer.vue'
import PdfViewerModal from './PdfViewerModal.vue'
import EmptyState from './EmptyState.vue'
// MUST be the same normaliser AcademyView uses, from the byte-identical
// copy in this app's utils/. A preview that frames a URL the learner's view
// would not (or vice versa) is the exact failure this modal exists to catch.
import { toEmbedUrl } from '@/utils/embedUrl'

/**
 * ADR-029 §2.7 — `is_correct` IS unmasked here (the viewer is an admin),
 * and this component deliberately does not read it.
 *
 * The whole point of this modal is to show what the LEARNER sees, and the
 * learner is never told which option is right. Colouring the correct
 * option here would make the preview a lie in exactly the direction that
 * matters. The authoring panel one screen below shows it properly.
 */
interface PreviewQuizOption {
  id: number
  option_text: string
  is_correct: boolean | null
  sort_order: number
}
interface PreviewQuizQuestion {
  id: number
  question_text: string
  sort_order: number
  options: PreviewQuizOption[]
}
// Structural subset of AcademyManagementView's ModuleLessonItem — the view
// passes its own object straight through. Kept local (a `<script setup>`
// block cannot export) and deliberately narrow: this modal reads only the
// fields the LEARNER's view reads.
interface PreviewLesson {
  id: number
  title: string
  content_type: string
  source_type: 'upload' | 'embed' | null
  content_ref: string | null
  stream_url: string | null
  inline_url: string | null
  is_downloadable: boolean
  duration_seconds: number | null
  page_count: number | null
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
  is_published: boolean
  // ADR-029 — ANY lesson may carry a quiz now (§2.1), so the preview keys
  // off the COUNT, never off content_type.
  quiz_question_count: number
  quiz_blocks_completion: boolean
  quiz_pass_percent: number | null
  quiz_questions?: PreviewQuizQuestion[]
}

/** ADR-028 §4 / ADR-029 §2.4 — the per-company BR-7 thresholds, read from the server. Never hardcoded. */
interface CompletionSettings {
  video_watch_percent: number
  pdf_read_percent: number
  quiz_pass_percent: number
}

const props = withDefaults(
  defineProps<{
    lesson: PreviewLesson
    /** GET /academy-completion-settings. Null while loading or if the read failed. */
    settings: CompletionSettings | null
    settingsLoading?: boolean
    settingsError?: string
  }>(),
  { settingsLoading: false, settingsError: '' },
)

const emit = defineEmits<{
  close: []
  /** Let the parent retry GET /academy-completion-settings without reopening. */
  reloadSettings: []
}>()

// ── Mirrored from AcademyView.vue (presentation only) ───────────────
// Copied verbatim INCLUDING the `image` gap: the learner's map has no
// `image` key, so an image lesson reads as "เนื้อหา" there. The preview
// shows what the learner sees, not what we wish they saw — flagged to
// ag-lead separately rather than silently "fixed" only here.
const CONTENT_TYPE_LABELS: Record<string, string> = {
  video: 'วิดีโอ',
  pdf: 'เอกสาร PDF',
  link: 'ลิงก์',
  // ADR-029 — mirrors AcademyView's rename: the quiz is graded now, so
  // "ทบทวน" would understate it.
  quiz: 'แบบทดสอบท้ายบท',
}

function lessonInlineSrc(lesson: PreviewLesson): string | null {
  return lesson.inline_url ?? lesson.stream_url
}
function lessonHasInlinePlayer(lesson: PreviewLesson): boolean {
  if (lesson.content_type !== 'video') return false
  if (lesson.source_type === 'upload') return !!lessonInlineSrc(lesson)
  if (lesson.source_type === 'embed') return !!lesson.content_ref
  return false
}
function isUploadedPdf(lesson: PreviewLesson): boolean {
  return lesson.content_type === 'pdf' && lesson.source_type === 'upload' && !!lessonInlineSrc(lesson)
}
function isUploadedImage(lesson: PreviewLesson): boolean {
  return lesson.content_type === 'image' && lesson.source_type === 'upload' && !!lessonInlineSrc(lesson)
}

/**
 * The honesty bar auto-dismisses (human, 2026-08-09: "ขึ้นแสดงแล้วหายไป
 * ไม่ต้องขึ้นค้าง"). 6s — long enough to read three Thai clauses at a glance,
 * short enough that it is gone before the admin starts actually reading the
 * lesson underneath it. The timer is cleared on unmount so closing the modal
 * mid-countdown cannot fire a setState into a dead component.
 */
const HONESTY_BAR_MS = 6000
const showHonestyBar = ref(true)
let honestyBarTimer: ReturnType<typeof setTimeout> | undefined
onMounted(() => {
  honestyBarTimer = setTimeout(() => {
    showHonestyBar.value = false
  }, HONESTY_BAR_MS)
})
onBeforeUnmount(() => {
  if (honestyBarTimer) clearTimeout(honestyBarTimer)
})

/** Same words as the bar, kept reachable after it fades (header `title`). */
const honestyText =
  'นี่คือตัวอย่างคร่าว ๆ ไม่ใช่หน้าจอจริงของผู้เรียน — แอปตัวแทนออกแบบมาสำหรับมือถือ ' +
  'การจัดวางจริงอาจต่างจากนี้เล็กน้อย · การเปิดดูตรงนี้ไม่ถูกบันทึกเป็นความคืบหน้า ' +
  'และกดเรียนจบแทนผู้เรียนไม่ได้'

const contentTypeLabel = computed(() => CONTENT_TYPE_LABELS[props.lesson.content_type] ?? 'เนื้อหา')
const contentIcon = computed(() =>
  props.lesson.content_type === 'video' ? 'play' : props.lesson.content_type === 'quiz' ? 'check_square' : 'document',
)
/** AcademyView's lessonActionLabel(), in its "not yet opened" state. */
const actionLabel = computed(() => {
  const l = props.lesson
  if (l.content_type === 'quiz') return 'ทำแบบทดสอบท้ายบท'
  if (isUploadedPdf(l)) return 'อ่านเอกสารในแอป'
  if (isUploadedImage(l)) return 'ดูรูปภาพ'
  if (lessonHasInlinePlayer(l)) return 'ดูวิดีโอ'
  if (l.content_type === 'video') return 'เปิดวิดีโอในแท็บใหม่'
  if (l.content_type === 'pdf') return 'เปิดเอกสาร PDF'
  return 'เปิดเนื้อหา'
})

/** AcademyView's moduleProcessingLabel() — the learner-facing wording, not the admin one. */
const processingLabel = computed(() => {
  switch (props.lesson.processing_status) {
    case 'pending':
    case 'processing':
      return 'วิดีโอกำลังประมวลผล กรุณารอสักครู่แล้วลองใหม่'
    case 'failed':
      return 'ย่อไฟล์ไม่สำเร็จ — เล่นจากไฟล์ต้นฉบับ'
    default:
      return ''
  }
})

/**
 * AcademyView's fall-through: everything that is not inline and not a quiz
 * is just a URL it opens in a new tab. Rendered here as a link card so the
 * admin can see WHAT it opens, which the lesson row never showed either.
 */
const opensExternally = computed(
  () =>
    props.lesson.content_type !== 'quiz' &&
    !lessonHasInlinePlayer(props.lesson) &&
    !isUploadedPdf(props.lesson) &&
    !isUploadedImage(props.lesson) &&
    !!props.lesson.content_ref,
)
/** The learner's "ยังไม่มีไฟล์เนื้อหาสำหรับบทเรียนนี้" row state. */
const hasNoContent = computed(
  () =>
    props.lesson.content_type !== 'quiz' &&
    !lessonHasInlinePlayer(props.lesson) &&
    !isUploadedPdf(props.lesson) &&
    !isUploadedImage(props.lesson) &&
    !props.lesson.content_ref,
)

/**
 * Quiz selections — LOCAL STATE ONLY, and it stays that way.
 *
 * ADR-029 gave the lesson quiz a real grading endpoint (POST
 * /module-lessons/{lesson}/quiz-attempts), so "there is no endpoint" is no
 * longer why this preview does not submit. The reason now is the one in
 * the file header: a preview must not write. An attempt created here would
 * land in the very readout the admin uses to judge learners, under the
 * admin's own name.
 *
 * Guaranteed structurally, not by care: `api` is not imported by this
 * component at all, so there is nothing here that could POST.
 */
const quizAnswers = ref<Record<number, number>>({})
function selectQuizAnswer(questionId: number, optionId: number) {
  quizAnswers.value[questionId] = optionId
}

// ── PDF: the existing Admin viewer, opened on demand ────────────────
// Deliberately NOT a copy of the learner's PdfViewerModal: that one carries
// the IntersectionObserver that REPORTS reading position, which is the one
// thing a preview must never do. This viewer renders the same document with
// no progress surface at all. The delta (no page counter, no resume, no
// download button) is stated in the gate panel rather than faked.


// ── The completion gate, described from server data (ADR-028 §2.3/§4) ──
// DESCRIPTIVE COPY ONLY. LessonCompletionGate on the backend is
// authoritative; nothing here is computed into a threshold, because a
// second implementation of the rule is a second thing that can drift.
// Percentages come from GET /academy-completion-settings (BR-7 config) —
// never hardcoded 80/100.
type GateKind = 'downloadable' | 'external' | 'video' | 'pdf' | 'untracked'
const gateKind = computed<GateKind>(() => {
  const l = props.lesson
  if (l.is_downloadable) return 'downloadable'
  if (l.source_type !== 'upload') return 'external'
  if (l.content_type === 'video') return 'video'
  if (l.content_type === 'pdf') return 'pdf'
  return 'untracked'
})

function formatClock(totalSeconds: number | null): string {
  if (totalSeconds === null) return '—'
  const seconds = Math.max(0, Math.floor(totalSeconds))
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  const mm = h > 0 ? String(m).padStart(2, '0') : String(m)
  return (h > 0 ? `${h}:` : '') + `${mm}:${String(s).padStart(2, '0')}`
}

function onBackdropClick(event: MouseEvent) {
  if (event.target === event.currentTarget) emit('close')
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4"
    style="font-family: Kanit, sans-serif"
    role="dialog"
    aria-modal="true"
    aria-label="ตัวอย่างที่ตัวแทนจะเห็น"
    @click="onBackdropClick"
  >
    <div class="relative w-full max-w-4xl max-h-[92vh] bg-white rounded-2xl shadow-xl flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-100 shrink-0">
        <!-- Carries the honesty text after the bar fades — hover to recall it. -->
        <Icon name="eye" :size="16" class="text-slate-400 shrink-0 cursor-help" :title="honestyText" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-bold text-slate-900 truncate" :title="honestyText">ตัวอย่างที่ตัวแทนจะเห็น</p>
          <p class="text-[11px] text-slate-400 truncate">{{ lesson.title }}</p>
        </div>
        <button
          class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition shrink-0"
          title="ปิด"
          aria-label="ปิด"
          @click="emit('close')"
        >
          <Icon name="x" :size="18" />
        </button>
      </div>

      <!--
        Honesty bar — shown on open, then it goes away.

        Human, 2026-08-09: "ขึ้นแสดงแล้วหายไป ไม่ต้องขึ้นค้าง" (same call they
        made about the register-page warning earlier: say it once, don't nail
        it to the screen). It is a caveat, not an error, and a permanent
        amber strip above the content teaches an admin to stop reading amber
        strips — which is a real cost the next time one of them matters.

        The text is not lost: it stays on the header's `title`, so hovering
        the eye icon brings it back on demand. Nothing here is a warning the
        admin must acknowledge before acting — the "writes nothing" guarantee
        is enforced structurally (see this file's header), not by them having
        read this sentence.
      -->
      <Transition
        enter-active-class="transition duration-200"
        enter-from-class="opacity-0 -translate-y-1"
        leave-active-class="transition duration-500"
        leave-to-class="opacity-0"
      >
        <div v-if="showHonestyBar" class="px-4 py-2 bg-amber-50 border-b border-amber-100 shrink-0">
          <p class="text-[11px] text-amber-800 leading-relaxed">
            นี่คือ<strong class="font-bold">ตัวอย่างคร่าว ๆ</strong> ไม่ใช่หน้าจอจริงของผู้เรียน —
            แอปตัวแทนออกแบบมาสำหรับมือถือ การจัดวางจริงอาจต่างจากนี้เล็กน้อย ·
            การเปิดดูตรงนี้<strong class="font-bold">ไม่ถูกบันทึกเป็นความคืบหน้า</strong>และกดเรียนจบแทนผู้เรียนไม่ได้
          </p>
        </div>
      </Transition>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto bg-slate-100 p-4">
        <!--
          Human request (2026-08-09): "ให้ Preview อยู่ใน row เดียว เท่ากับความ
          กว้างของ modal คำอธิบายอยู่ด้านล่าง".

          Was a two-column layout with the preview locked to a 390px phone
          frame on the left and the explanation panels on the right. Now a
          single vertical stack: preview first at full modal width, everything
          explanatory beneath it.

          The "มุมมองมือถือ · กว้าง 390px" caption was REMOVED rather than
          kept — at full width it would have been a false statement about what
          the admin is looking at, and the honesty bar above already says the
          real layout differs. A caption that lies is worse than no caption.
        -->
        <div class="flex flex-col gap-4">
          <!-- ── The preview, full modal width ─────────────────────── -->
          <div class="w-full">
            <div class="w-full rounded-2xl border border-slate-300 bg-white overflow-hidden">
              <div class="px-4 py-3">
                <!-- The lesson row, as the learner sees it in the course
                     outline. Not a button here: tapping is what the preview
                     has already done for them (the content is expanded
                     below), and a fake tap target would invite the admin to
                     think they are inside a live session. -->
                <div class="flex items-start gap-3 min-w-0 w-full">
                  <Icon :name="contentIcon" :size="20" class="mt-0.5 shrink-0 text-slate-400" />
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-900 break-words">{{ lesson.title }}</p>
                    <p class="text-[11px] mt-0.5 text-brand-600 font-bold">
                      {{ actionLabel }}
                      <span class="text-slate-400 font-normal"> · {{ contentTypeLabel }}</span>
                    </p>
                  </div>
                  <Icon name="chevron_right" :size="18" class="text-slate-300 shrink-0 mt-0.5" />
                </div>

                <!-- The learner's secondary completion control. DISABLED —
                     see the file header: POST /module-completions is not
                     reachable from this component. -->
                <div class="mt-2 pl-8 flex items-center gap-2 flex-wrap">
                  <button
                    type="button"
                    disabled
                    class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 opacity-60 cursor-not-allowed"
                  >
                    <Icon name="check_circle" :size="16" />
                    ทำเครื่องหมายว่าเรียนจบ
                  </button>
                  <span class="text-[10px] text-slate-400">(ปิดใช้งานในตัวอย่าง)</span>
                </div>

                <!-- ── Content, per type — mirrors AcademyView's branches ── -->
                <div class="mt-3 pt-3 border-t border-slate-100">
                  <p v-if="processingLabel && lesson.content_type === 'video'" class="text-xs font-bold text-amber-600 mb-2">
                    {{ processingLabel }}
                  </p>

                  <!-- 1. Our own uploaded video — same element the learner gets. -->
                  <LessonVideoPlayer
                    v-if="lesson.content_type === 'video' && lesson.source_type === 'upload' && lessonInlineSrc(lesson)"
                    :inline-url="lessonInlineSrc(lesson)!"
                    class="w-full"
                  />

                  <!-- 2. Our own uploaded image (ADR-028 §2.1). -->
                  <AuthenticatedMedia
                    v-else-if="isUploadedImage(lesson)"
                    :src="lessonInlineSrc(lesson)"
                    type="image"
                    class="w-full rounded-lg"
                  />

                  <!-- 3. Embedded video (YouTube/Vimeo/…). Normalised through
                       the SAME `toEmbedUrl` the learner's AcademyView uses, and
                       carrying the SAME always-visible escape link, because a
                       host that refuses to be framed cannot be detected from JS
                       — so the admin must see the fallback the learner gets
                       rather than be told the lesson plays. -->
                  <div v-else-if="lesson.source_type === 'embed' && lesson.content_ref" class="w-full">
                    <iframe
                      :src="toEmbedUrl(lesson.content_ref)"
                      class="w-full aspect-video rounded-lg border border-slate-200"
                      allowfullscreen
                      allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                    />
                    <a
                      :href="lesson.content_ref"
                      target="_blank"
                      rel="noopener"
                      class="mt-1 inline-flex items-center gap-1.5 text-xs font-bold text-brand-600 hover:underline"
                    >
                      <Icon name="link" :size="14" />
                      เปิดลิงก์ในแท็บใหม่
                    </a>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                      ถ้าวิดีโอด้านบนไม่ขึ้น ให้กดลิงก์นี้เพื่อเปิดดูในแท็บใหม่
                    </p>
                  </div>

                  <!--
                    4. Our own uploaded PDF.

                    Human request (2026-08-09): "ให้แสดงผล PDF ทันที ไม่ต้อง
                    คลิ๊กอีก 1 รอบ". Was a button that opened a second
                    full-screen modal on top of this one — two overlays deep
                    to look at a document the admin had already asked to
                    preview. Now the reader is rendered in place.
                  -->
                  <div v-else-if="isUploadedPdf(lesson)" class="space-y-2">
                    <PdfViewerModal
                      inline
                      :stream-url="lessonInlineSrc(lesson)!"
                      :title="lesson.title"
                    />
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                      ผู้เรียนจะเห็นเครื่องอ่าน PDF เต็มจอ พร้อมตัวนับหน้าและปุ่ม “อ่านต่อ”
                      จากหน้าที่ค้างไว้ — ตัวอ่านในหน้าผู้ดูแลนี้แสดงเนื้อหาเดียวกัน
                      แต่ไม่มีสองอย่างหลังนั้น
                    </p>
                  </div>

                  <!-- 5. Anything else with a URL — the learner's window.open path. -->
                  <div v-else-if="opensExternally" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <p class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                      <Icon name="link" :size="13" class="text-slate-400 shrink-0" />
                      เปิดในแท็บใหม่
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1 break-all">{{ lesson.content_ref }}</p>
                    <a
                      :href="lesson.content_ref!"
                      target="_blank"
                      rel="noopener"
                      class="mt-2 inline-flex items-center gap-1 text-[11px] font-bold text-brand-600 hover:underline"
                    >
                      ลองเปิดลิงก์นี้
                      <Icon name="arrow_right" :size="12" />
                    </a>
                  </div>

                  <!-- 6. Nothing at all — an authoring gap. Say so; never an empty box. -->
                  <div v-else-if="hasNoContent" class="rounded-lg border border-dashed border-amber-300 bg-amber-50 px-3 py-2.5">
                    <p class="text-xs font-bold text-amber-700">ยังไม่มีไฟล์เนื้อหาสำหรับบทเรียนนี้</p>
                    <p class="text-[11px] text-amber-700/80 mt-1 leading-relaxed">
                      ผู้เรียนจะเห็นข้อความนี้บนแถวบทเรียน และเมื่อแตะจะได้แจ้งเตือนให้ติดต่อผู้ดูแลระบบ
                    </p>
                  </div>
                </div>

                <!--
                  ── ADR-029 — the end-of-lesson quiz, as the learner gets it.

                  OUTSIDE the content chain above, not a branch of it: §2.1
                  made the quiz something a video or PDF lesson can also
                  carry, and it sits AFTER the material. Keyed on
                  `quiz_question_count` for the same reason.

                  THIS PREVIEW SUBMITS NOTHING. `POST /module-lessons/{id}/
                  quiz-attempts` is not called from this component, and
                  `frontend-admin` does not call it from anywhere at all —
                  the button below is rendered `disabled` with no handler
                  bound, exactly like the "ทำเครื่องหมายว่าเรียนจบ" control
                  above it. An admin skimming a quiz must not put an attempt
                  row under their own name into the readout the admin
                  themselves reads.
                -->
                <div v-if="lesson.quiz_question_count > 0" class="mt-3 pt-3 border-t border-slate-100">
                  <p class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                    <Icon name="check_square" :size="14" class="text-slate-400 shrink-0" />
                    แบบทดสอบท้ายบท {{ lesson.quiz_question_count }} ข้อ
                  </p>
                  <!-- §2.2 — the learner cannot see any of this until the
                       content gate is met. An admin is always unlocked
                       server-side (they are authoring, not learning), so the
                       locked state cannot be rendered honestly here — it is
                       stated in words instead of faked. -->
                  <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                    ผู้เรียนจะเห็นแบบทดสอบนี้<strong class="font-bold">หลังจากดู/อ่านเนื้อหาครบตามเกณฑ์แล้วเท่านั้น</strong> —
                    ก่อนหน้านั้นจะเห็นแค่ว่ามีแบบทดสอบกี่ข้อ และต้องทำอะไรก่อน (ไม่เห็นคำถาม ไม่เห็นเปอร์เซ็นต์)
                  </p>

                  <div v-if="lesson.quiz_questions?.length" class="mt-3 space-y-4">
                    <div v-for="(q, qi) in lesson.quiz_questions" :key="q.id">
                      <p class="text-sm font-bold text-slate-900 mb-2">{{ qi + 1 }}. {{ q.question_text }}</p>
                      <div class="space-y-1.5">
                        <!-- Styled ONLY by the previewer's own selection.
                             `opt.is_correct` is readable here but is not
                             read: the learner never sees which is right
                             (§2.7), so neither may the preview. -->
                        <button
                          v-for="opt in q.options"
                          :key="opt.id"
                          type="button"
                          class="w-full flex items-center gap-2 px-3 py-2 rounded-lg border text-left text-sm transition"
                          :class="
                            quizAnswers[q.id] === opt.id
                              ? 'border-brand-600 bg-brand-50 text-brand-700 font-bold'
                              : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                          "
                          @click="selectQuizAnswer(q.id, opt.id)"
                        >
                          <span
                            class="w-4 h-4 rounded-full border shrink-0 flex items-center justify-center"
                            :class="quizAnswers[q.id] === opt.id ? 'border-brand-600 bg-brand-600' : 'border-slate-300'"
                          >
                            <Icon v-if="quizAnswers[q.id] === opt.id" name="check" :size="10" class="text-white" />
                          </span>
                          {{ opt.option_text }}
                        </button>
                      </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                      <button
                        type="button"
                        disabled
                        class="px-4 py-2 rounded-lg bg-brand-600 text-white text-xs font-bold opacity-50 cursor-not-allowed"
                      >
                        ส่งคำตอบ
                      </button>
                      <span class="text-[10px] text-slate-400">(ปิดใช้งานในตัวอย่าง — ไม่บันทึกผลการทำแบบทดสอบ)</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                      เมื่อผู้เรียนกดส่ง ระบบจะตรวจให้ที่เซิร์ฟเวอร์ แล้วบอกว่า<strong class="font-bold">ตอบถูกกี่ข้อจากทั้งหมดกี่ข้อ</strong>
                      และ<strong class="font-bold">ข้อไหนตอบผิด</strong> — แต่จะไม่บอกว่าคำตอบที่ถูกคือข้อใด และไม่บอกเกณฑ์ผ่าน (ADR-029 §2.7) ·
                      ทำซ้ำได้ไม่จำกัดครั้ง
                    </p>
                  </div>
                  <EmptyState v-else icon="check_square" title="ยังไม่มีคำถามในแบบทดสอบนี้" class="mt-2" />
                </div>
              </div>
            </div>
          </div>

          <!-- ── What the learner has to do to pass ─────────────────── -->
          <!-- Below the preview now, and side-by-side between themselves on a
               wide screen — two short reference panels stacked vertically at
               full modal width would be a lot of empty space to scroll past. -->
          <div class="w-full grid grid-cols-1 lg:grid-cols-2 gap-3 items-start">
            <div class="rounded-xl bg-white border border-slate-200 p-4">
              <p class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                <Icon name="check_circle" :size="14" class="text-slate-400 shrink-0" />
                เกณฑ์การกดเรียนจบ
              </p>

              <p v-if="settingsLoading" class="mt-2 text-xs text-slate-400">กำลังโหลดเกณฑ์...</p>

              <div v-else-if="settingsError" class="mt-2">
                <p class="text-xs font-bold text-rose-600">{{ settingsError }}</p>
                <button
                  type="button"
                  class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-bold text-brand-600 hover:underline"
                  @click="emit('reloadSettings')"
                >
                  <Icon name="refresh" :size="12" />
                  ลองใหม่
                </button>
              </div>

              <template v-else>
                <p v-if="gateKind === 'downloadable'" class="mt-2 text-xs text-slate-600 leading-relaxed">
                  ไฟล์นี้ตั้งค่าให้<strong class="font-bold">ดาวน์โหลดได้</strong>
                  ระบบจึงไม่บังคับเปอร์เซ็นต์การดู/อ่าน —
                  ผู้เรียนกดปุ่ม “เรียนจบ” ได้ทันที (ADR-028 §2.3)
                </p>
                <p v-else-if="gateKind === 'external'" class="mt-2 text-xs text-slate-600 leading-relaxed">
                  เนื้อหานี้เป็น<strong class="font-bold">ลิงก์ภายนอก</strong>
                  ระบบวัดความคืบหน้าไม่ได้ ผู้เรียนกดปุ่ม “เรียนจบ” ได้ทันที
                </p>
                <template v-else-if="gateKind === 'video'">
                  <p class="mt-2 text-xs text-slate-600 leading-relaxed">
                    ผู้เรียนต้องดูอย่างน้อย
                    <strong class="font-bold text-slate-900">{{ settings?.video_watch_percent ?? '—' }}%</strong>
                    ของวิดีโอ จึงจะกดเรียนจบได้
                  </p>
                  <p v-if="lesson.duration_seconds" class="mt-1 text-[11px] text-slate-400">
                    ความยาววิดีโอที่เซิร์ฟเวอร์วัดได้: {{ formatClock(lesson.duration_seconds) }}
                  </p>
                  <!--
                    Human, 2026-08-09: "ปรับเป็นภาษาให้ Admin เข้าใจ".
                    Named the missing binary (ffprobe / poppler) — a fact an
                    admin can neither act on nor verify. What they need is the
                    CONSEQUENCE (this lesson's gate is off), whether it is their
                    fault (no), and who fixes it (whoever runs the server).
                  -->
                  <p v-else class="mt-1 text-[11px] font-bold text-amber-600 leading-relaxed">
                    ระบบยังอ่านความยาวของวิดีโอนี้ไม่ได้ จึงบังคับเปอร์เซ็นต์กับบทเรียนนี้ไม่ได้ —
                    ผู้เรียนกดเรียนจบได้เลยโดยไม่ต้องดูจนครบ
                    · ไม่ใช่เพราะตั้งค่าผิด แต่เป็นเรื่องของเครื่องเซิร์ฟเวอร์ แจ้งผู้ดูแลระบบให้ตรวจสอบ
                  </p>
                </template>
                <template v-else-if="gateKind === 'pdf'">
                  <p class="mt-2 text-xs text-slate-600 leading-relaxed">
                    ผู้เรียนต้องอ่านให้ถึงอย่างน้อย
                    <strong class="font-bold text-slate-900">{{ settings?.pdf_read_percent ?? '—' }}%</strong>
                    ของจำนวนหน้า จึงจะกดเรียนจบได้
                  </p>
                  <p v-if="lesson.page_count" class="mt-1 text-[11px] text-slate-400">
                    จำนวนหน้าที่เซิร์ฟเวอร์วัดได้: {{ lesson.page_count }} หน้า
                  </p>
                  <!-- Same rewrite as the video case above — consequence, not binary name. -->
                  <p v-else class="mt-1 text-[11px] font-bold text-amber-600 leading-relaxed">
                    ระบบยังนับจำนวนหน้าของไฟล์นี้เองไม่ได้ จึงต้องเชื่อจำนวนหน้าที่เครื่องผู้เรียนแจ้งมา
                    ซึ่งไม่แน่นอนเท่า · ไม่ใช่เพราะตั้งค่าผิด แต่เป็นเรื่องของเครื่องเซิร์ฟเวอร์
                    แจ้งผู้ดูแลระบบให้ตรวจสอบ
                  </p>
                </template>
                <p v-else class="mt-2 text-xs text-slate-600 leading-relaxed">
                  เนื้อหาประเภทนี้ไม่มีการวัดตำแหน่งการเรียน
                  ผู้เรียนกดปุ่ม “เรียนจบ” ได้ทันที
                </p>

                <!-- ADR-029 §2.4/§2.6 — the quiz half of the same gate.
                     Admin-facing, so the resolved pass mark is fine to show
                     HERE; it is never rendered in the learner preview above. -->
                <template v-if="lesson.quiz_question_count > 0">
                  <p class="mt-2.5 pt-2.5 border-t border-slate-100 text-xs text-slate-600 leading-relaxed">
                    <template v-if="lesson.quiz_blocks_completion">
                      บทเรียนนี้<strong class="font-bold text-slate-900">บังคับให้ทำแบบทดสอบท้ายบทผ่านก่อน</strong>จึงจะกดเรียนจบได้
                      และอยู่บนเส้นทางการได้ใบรับรอง (BR-1)
                    </template>
                    <template v-else>
                      แบบทดสอบท้ายบทนี้<strong class="font-bold text-slate-900">ไม่บล็อกการกดเรียนจบ</strong> —
                      ระบบบันทึกผลไว้ให้ผู้ดูแลดูเท่านั้น
                    </template>
                  </p>
                  <p class="mt-1 text-[11px] text-slate-400">
                    เกณฑ์ผ่านที่ใช้จริง:
                    <strong class="font-bold text-slate-700">{{ (lesson.quiz_pass_percent ?? settings?.quiz_pass_percent ?? null) !== null ? (lesson.quiz_pass_percent ?? settings?.quiz_pass_percent) + '%' : '—' }}</strong>
                    <span v-if="lesson.quiz_pass_percent === null"> (ใช้ค่าของบริษัท)</span>
                    <span v-else> (ตั้งเฉพาะบทเรียนนี้)</span>
                  </p>
                </template>

                <p class="mt-2.5 pt-2.5 border-t border-slate-100 text-[11px] text-slate-400 leading-relaxed">
                  ตัวเลขนี้เป็นค่าตั้งค่าต่อบริษัท (แก้ได้ที่ตั้งค่า Academy) และ
                  <strong class="font-bold">ผู้เรียนจะไม่เห็นตัวเลขนี้</strong> —
                  ถ้ายังไม่ถึงเกณฑ์ ระบบจะบอกแค่ให้ดู/อ่านให้ครบก่อน (ADR-028 §4)
                </p>
              </template>
            </div>

            <!-- Facts an admin is publishing blind on today -->
            <div class="rounded-xl bg-white border border-slate-200 p-4 space-y-1.5">
              <p class="text-xs font-bold text-slate-900 mb-1">รายละเอียดที่ตั้งไว้</p>
              <p class="text-[11px] text-slate-500">
                สถานะ:
                <span :class="lesson.is_published ? 'text-emerald-600 font-bold' : 'text-slate-500 font-bold'">
                  {{ lesson.is_published ? 'เผยแพร่แล้ว' : 'ฉบับร่าง' }}
                </span>
              </p>
              <p class="text-[11px] text-slate-500">
                แหล่งเนื้อหา:
                {{ lesson.source_type === 'upload' ? 'ไฟล์ที่อัปโหลดในระบบ' : lesson.source_type === 'embed' ? 'ลิงก์ embed' : 'ลิงก์ภายนอก / ไม่มีไฟล์' }}
              </p>
              <p v-if="lesson.source_type === 'upload'" class="text-[11px] text-slate-500 leading-relaxed">
                ดาวน์โหลด:
                <span class="font-bold">{{ lesson.is_downloadable ? 'ผู้เรียนจะเห็นปุ่มดาวน์โหลด' : 'ไม่แสดงปุ่มดาวน์โหลด' }}</span>
                <span v-if="!lesson.is_downloadable"> — เป็นการซ่อนปุ่มเท่านั้น ไม่ได้ทำให้ไฟล์คัดลอกไม่ได้</span>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!--
      The full-screen overlay reader is gone: since 2026-08-09 the PDF is
      rendered INLINE in the body above (human: "แสดงผล PDF ทันที ไม่ต้อง
      คลิ๊กอีก 1 รอบ"), so opening a second overlay on top of this modal
      would be the exact extra step that was removed. `showPdf` is kept as
      a ref only because removing it is a separate cleanup; nothing sets it
      to true any more.
    -->
  </div>
</template>
