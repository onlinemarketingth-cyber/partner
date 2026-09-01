<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * AcademyLessonQuizView — the graded END-OF-LESSON quiz, on its own route
 * (TASK-167 §2/§4.2). `/academy/lessons/:id/quiz`.
 *
 * TASK-152a — the two assessments have DIFFERENT NAMES and are not the same
 * object. This is **แบบทดสอบท้ายบทเรียน** (ADR-029): scoped to ONE lesson,
 * may block that lesson's completion. **แบบประเมินผล** (BR-1) is scoped to a
 * cert tier and lives on `/academy/exams/:id`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHAT THIS SCREEN NEVER SHOWS — §4.2, ADR-029 §3, ADR-028 §4
 * ═══════════════════════════════════════════════════════════════════════
 * PASS/FAIL ONLY. No score, no percentage, no which-answers-were-wrong.
 *
 * Three structural guarantees, in order of how easy each is to break by
 * accident:
 *
 *  1. An option's `is_correct` is typed `null` (the server masks it for the
 *     Agent role) and is read nowhere. No option is coloured, ordered or
 *     annotated by correctness.
 *  2. The attempt response's `score`, `total_questions` and per-question
 *     `results` are NOT STORED. Only `passed` enters this component's state,
 *     so there is nothing to accidentally render, diff or persist.
 *  3. The pass MARK is a threshold and is never sent to an Agent at all;
 *     nothing here computes one.
 *
 * Grading is server-side (§2.3). The request body is exactly
 * {question_id: option_id} — sending a score or a `passed` would be
 * self-grading a gate that, where `quiz_blocks_completion` is on, sits on
 * the BR-1 certification path.
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import {
  lockCountdownText,
  quizLockedHint,
  type ModuleLessonItem,
  type ModuleLessonQuizAttemptResult,
} from '@/utils/academy'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const lessonId = computed(() => Number(route.params.id))

const lesson = ref<ModuleLessonItem | null>(null)
const loading = ref(false)
const errorMessage = ref('')

/** question_id -> option_id. Survives a failed submit; a dropped connection must not cost the whole quiz. */
const answers = ref<Record<number, number>>({})
const submitting = ref(false)
const submitError = ref('')
/** §4.2 guarantee 2 — the ONLY thing kept from the attempt response. */
const lastResultPassed = ref<boolean | null>(null)

const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: ModuleLessonItem }>(
      `/module-lessons/${lessonId.value}`,
      pageAbort.signal,
    )
    lesson.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดแบบทดสอบท้ายบทเรียนไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

watch(lessonId, load, { immediate: true })

const questions = computed(() => lesson.value?.quiz_questions ?? [])
const answeredCount = computed(() => Object.keys(answers.value).length)
const allAnswered = computed(
  () => questions.value.length > 0 && questions.value.every((q) => answers.value[q.id] != null),
)

function selectAnswer(questionId: number, optionId: number) {
  answers.value = { ...answers.value, [questionId]: optionId }
}

async function submit() {
  const current = lesson.value
  if (!current || !allAnswered.value || submitting.value) return
  submitting.value = true
  submitError.value = ''
  try {
    const res = await api.post<{ data: ModuleLessonQuizAttemptResult }>(
      `/module-lessons/${current.id}/quiz-attempts`,
      { answers: answers.value },
    )
    // §4.2 / guarantee 2 — `passed` and nothing else. score,
    // total_questions and results are deliberately dropped on the floor.
    lastResultPassed.value = res.data.passed
    toast.success('ส่งคำตอบเรียบร้อย')
  } catch (e) {
    // Selections untouched on failure.
    submitError.value = apiErrorMessage(e, 'ส่งคำตอบไม่สำเร็จ กรุณาลองใหม่')
  } finally {
    submitting.value = false
  }
}

/**
 * ADR-029 §2.5 — unlimited retries, no cap and no cooldown. Keeps the
 * learner's selections so they rework their answers rather than re-entering
 * everything, and carries nothing from the graded attempt back into the
 * form (there is nothing to carry — see guarantee 2).
 */
function retry() {
  lastResultPassed.value = null
  submitError.value = ''
}

/** §4.2 — closing returns to /academy, NOT to the lesson just finished. */
function close() {
  void router.push('/academy')
}

const heroTitle = computed(() => lesson.value?.title ?? 'แบบทดสอบท้ายบทเรียน')
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="check_square"
      :title="heroTitle"
      :subtitle="td('academy.quiz_title')"
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

    <LoadingSkeleton v-if="loading && !lesson" type="list" :rows="3" class="mt-4" />

    <template v-else-if="lesson">
      <!-- ADR-031 §2.2 — a locked lesson's quiz-attempt POST is refused, and
           the server hands out no questions for it. The lock message is the
           server's, verbatim. -->
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

      <EmptyState
        v-else-if="lesson.quiz_question_count === 0"
        icon="check_square"
        :title="td('academy.no_quiz')"
        class="mt-4"
      />

      <!-- ADR-029 §2.2 — LOCKED BY THE CONTENT GATE. `quiz_questions` is
           absent from the payload entirely in this state, so nothing can be
           rendered by mistake. The learner is told WHAT TO DO, never how far
           they got (ADR-028 §4). -->
      <AppCard v-else-if="!lesson.quiz_unlocked" variant="raised" class="mt-4">
        <div class="flex items-start gap-3">
          <Icon name="key" :size="20" class="text-ink-card-subtle mt-0.5 shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-ink-card leading-relaxed">{{ quizLockedHint(lesson) }}</p>
            <RouterLink
              :to="`/academy/lessons/${lesson.id}`"
              class="mt-2 min-h-[44px] inline-flex items-center gap-1.5 text-xs font-bold text-ink-brand active:scale-95 transition-transform"
            >
              <Icon name="chevron_left" :size="14" />
              {{ td('academy.back_to_lesson') }}
            </RouterLink>
          </div>
        </div>
      </AppCard>

      <!-- ── THE RESULT (§4.2) — pass/fail, and STOP ────────────────
           No score, no percentage, no wrong-answer list. The learner closes
           it themselves, and closing goes to /academy, not back into the
           lesson they just finished. -->
      <AppCard v-else-if="lastResultPassed !== null" variant="raised" class="mt-4">
        <div class="flex items-start gap-3">
          <Icon
            :name="lastResultPassed ? 'check_circle' : 'alert'"
            :size="24"
            class="mt-0.5 shrink-0"
            :class="lastResultPassed ? 'text-ink-success' : 'text-ink-warning'"
          />
          <div class="min-w-0 flex-1">
            <p
              class="text-lg font-bold leading-tight"
              :class="lastResultPassed ? 'text-ink-success' : 'text-ink-warning'"
            >
              {{ lastResultPassed ? td('academy.passed') : td('academy.not_passed') }}
            </p>
            <p class="text-xs text-ink-card-muted mt-1 leading-relaxed">
              {{
                lastResultPassed
                  ? td('academy.quiz_passed_msg')
                  : td('academy.quiz_retry_msg')
              }}
            </p>
            <div class="mt-3 flex items-center gap-2 flex-wrap">
              <button
                type="button"
                class="min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform"
                @click="close"
              >
                {{ td('common.close2') }}
              </button>
              <!-- ADR-029 §2.5 — unlimited retries. Offered only on a fail:
                   after a pass there is nothing left to do here. -->
              <button
                v-if="!lastResultPassed"
                type="button"
                class="min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-brand bg-brand-50 hover:bg-brand-100 active:scale-95 transition-transform inline-flex items-center gap-1.5"
                @click="retry"
              >
                <Icon name="refresh" :size="14" /> {{ td('academy.retake') }}
              </button>
            </div>
          </div>
        </div>
      </AppCard>

      <!-- ── THE FORM ───────────────────────────────────────────── -->
      <template v-else>
        <div class="mt-4 space-y-4">
          <AppCard v-for="(q, qi) in questions" :key="q.id" variant="card">
            <p class="text-sm font-bold text-ink-card break-words">{{ qi + 1 }}. {{ q.question_text }}</p>
            <div class="mt-2 space-y-1.5">
              <!-- Styling depends ONLY on whether this is the learner's own
                   selection. `opt.is_correct` is null for an Agent and is
                   read nowhere (guarantee 1). -->
              <button
                v-for="opt in q.options"
                :key="opt.id"
                type="button"
                class="w-full min-h-[44px] flex items-center gap-2 px-3 py-2 rounded-lg border text-left text-sm active:scale-[0.99] transition-transform"
                :class="
                  answers[q.id] === opt.id
                    ? 'border-brand-600 bg-brand-50 text-brand-700 font-bold'
                    : 'border-line-card text-ink-card-muted hover:bg-surface-chip'
                "
                @click="selectAnswer(q.id, opt.id)"
              >
                <span
                  class="w-4 h-4 rounded-full border shrink-0 flex items-center justify-center"
                  :class="answers[q.id] === opt.id ? 'border-brand-600 bg-brand-600' : 'border-line-card'"
                >
                  <Icon v-if="answers[q.id] === opt.id" name="check" :size="10" class="text-white" />
                </span>
                <span class="min-w-0 break-words">{{ opt.option_text }}</span>
              </button>
            </div>
          </AppCard>
        </div>

        <div class="mt-4 flex items-center gap-3 flex-wrap">
          <button
            type="button"
            :disabled="submitting || !allAnswered"
            class="min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center justify-center"
            @click="submit"
          >
            {{ submitting ? td('academy.submitting') : td('academy.submit_answers') }}
          </button>
          <span class="text-xs text-ink-card-subtle">
            {{ td('academy.answered_count', '', { done: answeredCount, total: questions.length }) }}
          </span>
        </div>

        <p v-if="submitError" class="mt-2 text-xs font-bold text-ink-danger leading-relaxed">
          {{ submitError }}
        </p>
      </template>
    </template>
  </main>
</template>
