<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * AcademyLessonView — ONE lesson, on its own route (TASK-167 §2/§4.1).
 *
 * `/academy/lessons/:id`. This used to be two inline expanders on the list
 * (`expandedLessonId` for video/image, `pdfLesson` for the reader), so the
 * more content a company published the less usable the list became — and
 * the PDF overlay meant Android back / iOS swipe-back left Academy
 * altogether instead of returning to the list. A route fixes both.
 *
 * Deep-linkable and refreshable: it fetches ITSELF from
 * GET /module-lessons/{id} (TASK-167 §3) rather than reading a lesson out
 * of a /modules payload it no longer has.
 *
 * BR-1: passing the Basic certification is what unlocks selling. This
 * screen has no gate of its own — the gates are server-side
 * (LessonAccessGate on stream/completion/progress/quiz-attempt).
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
// An embed lesson authored with a plain `youtube.com/watch?v=…` URL sets
// X-Frame-Options and refuses to be framed; only `/embed/<id>` works.
import { toEmbedUrl } from '@/utils/embedUrl'
import { useToastStore } from '@/stores/toast'
import { useLessonProgress } from '@/composables/useLessonProgress'
import {
  completedLessonIdsFrom,
  contentTypeLabel,
  firstIncompleteLesson,
  isUploadedImage,
  isUploadedPdf,
  lessonHasNoContent,
  lessonInlineSrc,
  lessonProcessingLabel,
  lockCountdownText,
  quizLockedHint,
  type ModuleCompletionItem,
  type ModuleItem,
  type ModuleLessonItem,
} from '@/utils/academy'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
import LessonVideoPlayer from '@/design-system/components/LessonVideoPlayer.vue'
import PdfViewerModal from '@/design-system/components/PdfViewerModal.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const lessonId = computed(() => Number(route.params.id))

const lesson = ref<ModuleLessonItem | null>(null)
const loading = ref(false)
const errorMessage = ref('')
const completedHere = ref(false)
const completing = ref(false)
/**
 * ADR-028 §4 / ADR-029 §2.6 — the SERVER'S refusal sentence, verbatim.
 * Nothing here composes one from quiz_blocks_completion + quiz_passed: only
 * LessonCompletionGate knows which half of the gate is unmet, and a local
 * explanation would be tempted to mention the pass mark the API withholds.
 */
const completionBlockedMessage = ref('')

/**
 * ADR-028 §4.1 — the learner's OWN bookmark, from
 * GET /me/module-lessons/{id}/progress. Two keys and no wrapper; it carries
 * no max, no threshold and no percentage, so resuming costs the learner
 * nothing that §4 withheld.
 *
 * This is why the lesson can be a deep link at all: without it a refresh
 * would drop the reader back to page 1 of a 40-page document.
 */
const resumeSeconds = ref<number | null>(null)
const resumePage = ref<number | null>(null)

const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [detail, bookmark] = await Promise.all([
      api.get<{ data: ModuleLessonItem }>(`/module-lessons/${lessonId.value}`, pageAbort.signal),
      api
        .get<{ last_position_seconds: number | null; last_page: number | null }>(
          `/me/module-lessons/${lessonId.value}/progress`,
          pageAbort.signal,
        )
        // A missing bookmark must never cost the learner the lesson itself.
        .catch(() => ({ last_position_seconds: null, last_page: null })),
    ])
    lesson.value = detail.data
    resumeSeconds.value = bookmark.last_position_seconds
    resumePage.value = bookmark.last_page
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดบทเรียนไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

watch(lessonId, load, { immediate: true })

// ── §4.1 — FINISHING THE CONTENT ────────────────────────────────────
/**
 * Where the learner goes once this lesson is finished (human decision,
 * 2026-08-11):
 *
 *   has a quiz → the quiz screen
 *   no quiz    → the next lesson that is ACTUALLY OPEN, else /academy
 *
 * "Actually open" is `firstIncompleteLesson()` in utils/academy — the SAME
 * predicate the list's next-step card uses (published, not optional, not
 * locked). There is deliberately only one copy: navigating to a lesson the
 * server would refuse is the "button that cannot work" failure in
 * navigation form, and a second predicate is one that drifts into it.
 *
 * `replace`, not `push`: the lesson just finished must not sit in the
 * history behind the next one, or back-back would walk a learner through
 * every lesson they completed instead of returning to the list.
 */
async function goAfterLesson() {
  const current = lesson.value
  if (!current) return

  if (current.quiz_question_count > 0) {
    await router.replace(`/academy/lessons/${current.id}/quiz`)

    return
  }

  try {
    const [modules, completions] = await Promise.all([
      api.get<{ data: ModuleItem[] }>('/modules', pageAbort.signal),
      api.get<{ data: ModuleCompletionItem[] }>('/module-completions', pageAbort.signal),
    ])
    const done = completedLessonIdsFrom(completions.data)
    // Belt and braces: the completion we just earned is in /module-completions
    // by now, but a stale read must not send the learner back into the lesson
    // they just left.
    done.add(current.id)

    const next = firstIncompleteLesson(modules.data, done)
    await router.replace(next ? `/academy/lessons/${next.lesson.id}` : '/academy')
  } catch (e) {
    if (isAbortError(e)) return
    // Failing to work out where to go next is not a reason to strand the
    // learner on a lesson they have finished.
    await router.replace('/academy')
  }
}

/**
 * TASK-165 §3.5 — the server reports `completed` on the progress reply, so
 * a measurable lesson ticks itself the instant the gate is satisfied,
 * without a reload and without the learner pressing anything.
 *
 * `completed: false` does nothing at all: it is the normal answer several
 * times a minute for a learner partway through, not a refusal, and §3.5 is
 * explicit that a gate refusal must never be surfaced for an automatic
 * lesson — nobody pressed anything, so there is nothing to explain.
 */
const progress = useLessonProgress({
  onCompleted: (id) => {
    if (id !== lesson.value?.id || completedHere.value) return
    completedHere.value = true
    toast.success('บันทึกว่าเรียนจบบทเรียนแล้ว')

    /*
     * DELIBERATELY DOES NOT NAVIGATE — ag-lead ruling after ag-dev flagged it.
     *
     * The gate trips at the CONFIGURED THRESHOLD, not at the end: 80% by
     * default. Navigating here yanked a learner out of a video with a fifth
     * of it still to play, mid-sentence. "ไปบทถัดไป" was an answer about what
     * happens when someone FINISHES, not about what happens at 80%.
     *
     * So completion is recorded (silently, automatically) and the next step
     * is OFFERED — see the call to action the template shows once
     * `completedHere` is true. The learner decides when they are done.
     *
     * The one case that does navigate on its own is `@ended` on the video
     * below: there is nothing left on screen to interrupt.
     */
  },
})

/**
 * TASK-146/147 — completion is EARNED (ADR-028 §2.3): the server checks the
 * progress it recorded and rejects a POST below the threshold.
 *
 * `progress.flush()` FIRST, or the last throttle window's worth of
 * watching/reading has not reached the server and a learner who finishes a
 * video and taps immediately is blocked on a position we simply had not
 * sent. The catch shows the server's message and nothing more (ADR-028 §4 —
 * the learner is not told how far they got).
 */
async function markComplete() {
  const current = lesson.value
  if (!current) return
  completing.value = true
  completionBlockedMessage.value = ''
  try {
    await progress.flush()
    await api.post('/module-completions', { module_lesson_id: current.id })
    completedHere.value = true
    toast.success('บันทึกว่าเรียนจบบทเรียนแล้ว')
    await goAfterLesson()
  } catch (e) {
    completionBlockedMessage.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    completing.value = false
  }
}

// ── Progress reporting (TASK-147 / ADR-028 §2.3) ────────────────────
/**
 * RAW POSITIONS only. The server clamps them, keeps its own monotonic max
 * and decides what they mean — ADR-028 §3 rejected a client-reported
 * completion. Nothing derived from them is shown to the learner: §4 ruled a
 * blocked learner is NOT told how far they got, so there is no percentage
 * anywhere on this screen.
 */
function reportVideoPosition(seconds: number) {
  if (!lesson.value) return
  progress.report(lesson.value.id, { last_position_seconds: seconds })
}

/**
 * `page` is where the reader is; `furthest` is the session high-water mark.
 * The SERVER is told `page` and keeps its own monotonic max. `total_pages`
 * is only a FALLBACK denominator — module_lessons.page_count, measured with
 * pdfinfo at upload, is the real one.
 */
function reportPdfPosition(page: number, furthest: number, totalPages: number) {
  if (!lesson.value) return
  resumePage.value = Math.max(page, furthest)
  progress.report(lesson.value.id, {
    last_page: page,
    ...(totalPages > 0 ? { total_pages: totalPages } : {}),
  })
}

/**
 * ADR-028 §2.2 — reachable only when is_downloadable is true, because that
 * is the only case in which the control is rendered. Stated plainly, as the
 * ADR insists: hiding this button is NOT protection — anyone whose browser
 * rendered the file already holds the bytes. It records intent.
 */
async function downloadLessonFile() {
  const current = lesson.value
  if (!current?.is_downloadable || !current.stream_url) return
  try {
    await api.downloadAbsolute(current.stream_url)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'ดาวน์โหลดไฟล์ไม่สำเร็จ'))
  }
}

function openExternal() {
  const ref_ = lesson.value?.content_ref
  // `noopener`, same rule the storefront banner's external links use.
  if (ref_) window.open(ref_, '_blank', 'noopener')
}

const heroTitle = computed(() => lesson.value?.title ?? 'บทเรียน')
const heroSubtitle = computed(() => (lesson.value ? contentTypeLabel(lesson.value) : ''))
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="book"
      :title="heroTitle"
      :subtitle="heroSubtitle"
      accent-color="brand"
      back-page="/academy"
      :back-label="td('academy.back')"
    />

    <div
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3"
    >
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="load"
      >
        {{ td('common.retry') }}
      </button>
    </div>

    <!-- Single-rooted <main> with everything inside it: App.vue used to wrap
         <RouterView> in a <Transition>, and a multi-root view broke it. -->
    <LoadingSkeleton v-if="loading && !lesson" type="list" :rows="2" class="mt-4" />

    <template v-else-if="lesson">
      <!-- ── ADR-031 §2.2/§2.3 — LOCKED ─────────────────────────────
           The server's own sentence, rendered VERBATIM: "ต้องเรียนบท
           ก่อนหน้าให้จบก่อน" and "จะเปิดในอีก 3 วัน" are different problems
           (one waits, the other goes and finishes a lesson) and only
           LessonAccessGate knows which bit. No player is rendered — the
           stream route answers 403, so a <video> here would be a broken
           frame instead of an explanation. The countdown is client-side
           because the enum message carries no date on purpose. -->
      <AppCard v-if="lesson.is_locked" variant="raised" class="mt-4">
        <div class="flex items-start gap-3">
          <Icon name="key" :size="20" class="text-ink-card-subtle mt-0.5 shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-ink-card leading-relaxed">{{ lesson.lock_message }}</p>
            <p v-if="lockCountdownText(lesson)" class="text-[11px] font-bold text-ink-brand mt-1">
              {{ lockCountdownText(lesson) }}
            </p>
          </div>
        </div>
      </AppCard>

      <template v-else>
        <!-- ── THE CONTENT ────────────────────────────────────────── -->
        <AppCard variant="card" class="mt-4">
          <p
            v-if="lessonProcessingLabel(lesson.processing_status)"
            class="text-xs font-bold text-ink-warning mb-2"
          >
            {{ lessonProcessingLabel(lesson.processing_status) }}
          </p>

          <!-- TASK-143/147 — our own uploaded video plays from a plain
               <video src> with crossorigin="use-credentials", so the BROWSER
               issues ranged GETs against the authenticated stream (206)
               rather than us pulling the whole file into a blob first. -->
          <LessonVideoPlayer
            v-if="lesson.content_type === 'video' && lesson.source_type === 'upload' && lessonInlineSrc(lesson)"
            :inline-url="lessonInlineSrc(lesson)!"
            :duration-seconds="lesson.duration_seconds"
            :resume-seconds="resumeSeconds"
            class="w-full"
            @position="reportVideoPosition"
            @flush="progress.flush()"
            @ended="completedHere && goAfterLesson()"
          />

          <!-- ADR-028 §2.4 / TASK-167 §2 — the reader is IN the page, not a
               layer over it. Its own reading mode still goes full-screen;
               that is the one case where covering everything is the point. -->
          <PdfViewerModal
            v-else-if="isUploadedPdf(lesson)"
            :key="lesson.id"
            embedded
            :inline-url="lessonInlineSrc(lesson)!"
            :title="lesson.title"
            :page-count="lesson.page_count"
            :resume-page="resumePage"
            :download-url="lesson.is_downloadable ? lesson.stream_url : null"
            @close="router.push('/academy')"
            @progress="(p) => reportPdfPosition(p.page, p.furthest, p.totalPages)"
            @download="downloadLessonFile"
          />

          <!-- ADR-028 §2.1 — an uploaded still image. Blob-fetched on
               purpose: small, no seeking, nothing to gain from ranges. -->
          <AuthenticatedMedia
            v-else-if="isUploadedImage(lesson)"
            :src="lessonInlineSrc(lesson)"
            type="image"
            class="w-full rounded-lg"
          />

          <!-- An embedded video. `toEmbedUrl` rewrites the YouTube forms we
               recognise; every other host is passed through and MAY still
               refuse to be framed. That is undetectable from JS (the iframe
               is cross-origin and its load event fires either way), so the
               escape link is ALWAYS rendered — a learner staring at a blank
               frame with no way out is the worst outcome here. -->
          <div v-else-if="lesson.source_type === 'embed' && lesson.content_ref" class="w-full">
            <iframe
              :src="toEmbedUrl(lesson.content_ref)"
              class="w-full aspect-video rounded-lg border border-line-card"
              allowfullscreen
              allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
            />
            <a
              :href="lesson.content_ref"
              target="_blank"
              rel="noopener"
              class="mt-1 min-h-[44px] inline-flex items-center gap-1.5 text-xs font-bold text-ink-brand active:scale-95 transition-transform"
            >
              <Icon name="link" :size="14" />
              {{ td('common.open_link_new_tab') }}
            </a>
            <p class="text-[11px] text-ink-card-subtle leading-relaxed">
              {{ td('academy.video_fallback') }}
            </p>
          </div>

          <!-- Everything else that IS a URL — an external pdf/link, and a
               video whose source_type was never set. It is somebody else's
               page: we have no file to render and no way to observe reading
               position on it, so the honest control is "open it". -->
          <div v-else-if="lesson.content_ref" class="flex flex-col items-start gap-2">
            <p class="text-sm text-ink-card-muted">
              {{ td('academy.external_lesson') }}
            </p>
            <button
              type="button"
              class="min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform inline-flex items-center gap-1.5"
              @click="openExternal"
            >
              <Icon name="link" :size="14" />
              {{ td('academy.open_content') }}
            </button>
          </div>

          <!-- A quiz-only lesson has no content of its own; the quiz block
               below IS the lesson. -->
          <p v-else-if="lesson.content_type === 'quiz'" class="text-sm text-ink-card-muted">
            {{ td('academy.is_quiz') }}
          </p>

          <!-- Missing content is an AUTHORING gap, not an app error. -->
          <p v-else-if="lessonHasNoContent(lesson)" class="text-xs font-bold text-ink-warning">
            {{ td('academy.no_file') }}
          </p>

          <!-- ADR-028 §2.2 — the download control exists only when the
               company said the file may be kept. The PDF reader renders its
               own, so this one is for video/image. -->
          <button
            v-if="lesson.is_downloadable && lesson.stream_url && !isUploadedPdf(lesson)"
            type="button"
            class="mt-3 min-h-[44px] inline-flex items-center gap-1.5 text-xs font-bold text-ink-brand active:scale-95 transition-transform"
            @click="downloadLessonFile"
          >
            <Icon name="download" :size="14" />
            {{ td('common.download_file') }}
          </button>
        </AppCard>

        <!-- ── COMPLETION ─────────────────────────────────────────── -->
        <AppCard variant="flat" class="mt-4">
          <div class="flex items-center gap-3 flex-wrap">
            <template v-if="completedHere">
              <span class="text-xs font-bold text-ink-success inline-flex items-center gap-1">
                <Icon name="check" :size="14" /> {{ td('academy.completed') }}
              </span>
              <!-- The offer that replaced the automatic jump (see onCompleted).
                   Same destination `goAfterLesson()` always chose — quiz if
                   this lesson has one, otherwise the next lesson that is
                   actually open — the learner just decides when. -->
              <button
                type="button"
                class="ml-auto min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform inline-flex items-center gap-1.5"
                @click="goAfterLesson"
              >
                {{ lesson.quiz_question_count > 0 ? 'ทำแบบทดสอบท้ายบทเรียน' : 'ไปบทถัดไป' }}
                <Icon name="chevron_right" :size="14" />
              </button>
            </template>
            <!--
              TASK-165 §2/§3.5 — NO BUTTON where the server can measure.
              ADR-028 §1 settled that completion is EARNED, and
              "ทำเครื่องหมายว่าเรียนจบ" is the language of asserting; for an
              uploaded video or PDF the server records it the moment the gate
              is met. `completion_is_automatic` is the SERVER'S answer
              (LessonCompletionGate::isMeasurable) and is never re-derived
              here from content_type/source_type/is_downloadable.
            -->
            <button
              v-else-if="!lesson.completion_is_automatic"
              type="button"
              :disabled="completing"
              class="min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center gap-1.5"
              @click="markComplete"
            >
              <Icon name="check_circle" :size="16" />
              {{ completing ? 'กำลังบันทึก...' : 'ทำเครื่องหมายว่าเรียนจบ' }}
            </button>
            <!--
              TASK-147 asked for a per-lesson progress bar. Deliberately NOT
              rendered: ADR-028 §4 (human decision) ruled a learner is not
              told how far they got, and a bar filled from the positions we
              report IS that number in another form. If a real bar is wanted,
              ADR-028 §4 has to be reopened first.
            -->
            <p v-else class="text-xs text-ink-card-muted">
              {{ td('academy.autosave') }}
            </p>
          </div>

          <!--
            ADR-028 §4 / ADR-029 §2.6 — WHY the server refused, in the
            SERVER'S words. Never shown for an automatic lesson (TASK-165
            §3.5): the message only ever explains a refused BUTTON PRESS, and
            an automatic lesson has no button.
          -->
          <p
            v-if="!lesson.completion_is_automatic && completionBlockedMessage"
            class="mt-2 text-[11px] font-bold text-ink-warning leading-relaxed"
          >
            {{ completionBlockedMessage }}
          </p>
        </AppCard>

        <!-- ── ADR-029 — THE END-OF-LESSON QUIZ ───────────────────── -->
        <!-- Keyed on `quiz_question_count`, NOT content_type: §2.1 made a
             quiz something a video or PDF lesson can carry too. It is a
             comprehension check that comes AFTER the material, so it is last
             on the screen and lives on its own route. -->
        <AppCard v-if="lesson.quiz_question_count > 0" variant="card" class="mt-4">
          <p class="text-sm font-bold text-ink-card inline-flex items-center gap-1.5">
            <Icon name="check_square" :size="16" class="text-ink-card-subtle" />
            แบบทดสอบท้ายบทเรียน {{ lesson.quiz_question_count }} ข้อ
          </p>

          <!-- LOCKED (§2.2). `quiz_questions` is not in the payload at all in
               this state, so there is nothing to render even by mistake. The
               learner is told the quiz exists and WHAT TO DO — never how far
               they got and never a percentage (ADR-028 §4). -->
          <p v-if="!lesson.quiz_unlocked" class="text-[11px] text-ink-card-muted mt-1 leading-relaxed">
            {{ quizLockedHint(lesson) }}
          </p>

          <template v-else>
            <span
              v-if="lesson.quiz_passed"
              class="mt-1 text-[11px] font-bold text-ink-success inline-flex items-center gap-1"
            >
              <Icon name="check_circle" :size="14" /> {{ td('academy.quiz_passed') }}
            </span>
            <RouterLink
              :to="`/academy/lessons/${lesson.id}/quiz`"
              class="mt-2 min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform inline-flex items-center gap-1.5"
            >
              {{ td('academy.take_quiz') }}
              <Icon name="chevron_right" :size="14" />
            </RouterLink>
          </template>
        </AppCard>
      </template>
    </template>
  </main>
</template>
