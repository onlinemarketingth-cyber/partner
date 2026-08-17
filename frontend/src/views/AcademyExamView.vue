<script setup lang="ts">
/**
 * AcademyExamView — the CERTIFICATION exam, on its own route (TASK-167 §2).
 * `/academy/exams/:id`.
 *
 * TASK-152a — **แบบประเมินผล**, not "แบบทดสอบ...". That word belongs
 * exclusively to the end-of-lesson quiz (`/academy/lessons/:id/quiz`), and a
 * learner cannot be asked to tell the two apart by a suffix. This one is
 * scoped to a CERT TIER and is scored against `passing_score`: failing it
 * means no certification, which under BR-1 means no selling rights.
 *
 * Grading is ALWAYS server-side. GET /exams/{id} returns
 * questions[].options[] with `is_correct` masked to null for the Agent role
 * (ExamResource); POST /exam-attempts takes `answers: [{question_id,
 * option_id}]` and computes `passed`/`score` itself. Nothing here is
 * trusted with either (BR-1).
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import type { ExamAttemptItem, ExamDetail } from '@/utils/academy'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const examId = computed(() => Number(route.params.id))

const exam = ref<ExamDetail | null>(null)
const loading = ref(false)
const errorMessage = ref('')

const answers = ref<Record<number, number>>({})
const submitting = ref(false)
const submitError = ref('')
/** The graded attempt the server returned. Null until the learner submits. */
const result = ref<ExamAttemptItem | null>(null)

const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: ExamDetail }>(`/exams/${examId.value}`, pageAbort.signal)
    exam.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดแบบประเมินผลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

watch(examId, load, { immediate: true })

const questions = computed(() => exam.value?.questions ?? [])
const allAnswered = computed(
  () => questions.value.length > 0 && questions.value.every((q) => answers.value[q.id] != null),
)

function selectAnswer(questionId: number, optionId: number) {
  answers.value = { ...answers.value, [questionId]: optionId }
}

async function submit() {
  const current = exam.value
  if (!current || !allAnswered.value || submitting.value) return
  submitting.value = true
  submitError.value = ''
  try {
    const payload = questions.value.map((q) => ({ question_id: q.id, option_id: answers.value[q.id] }))
    const res = await api.post<{ data: ExamAttemptItem }>('/exam-attempts', {
      exam_id: current.id,
      answers: payload,
    })
    result.value = res.data
    toast.success('ส่งคำตอบเรียบร้อย')
  } catch (e) {
    // Selections untouched on failure.
    submitError.value = apiErrorMessage(e, 'ส่งผลสอบไม่สำเร็จ')
  } finally {
    submitting.value = false
  }
}

function close() {
  void router.push('/academy')
}

const heroTitle = computed(() => exam.value?.title ?? 'แบบประเมินผล')
const heroSubtitle = computed(() =>
  exam.value ? `${exam.value.cert_tier?.name ?? ''} · เกณฑ์ผ่าน ${exam.value.passing_score} คะแนน` : '',
)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="check_square"
      :title="heroTitle"
      :subtitle="heroSubtitle"
      accent-color="brand"
      back-page="/academy"
      back-label="กลับไปหน้า Academy"
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
        ลองใหม่
      </button>
    </div>

    <LoadingSkeleton v-if="loading && !exam" type="list" :rows="3" class="mt-4" />

    <template v-else-if="exam">
      <!-- The graded result. Unlike the lesson quiz (TASK-167 §4.2,
           pass/fail only), an exam IS scored: `passing_score` is published on
           the exam itself and the score has always been shown on the Academy
           list's exam row. Nothing new is disclosed here. -->
      <AppCard v-if="result" variant="raised" class="mt-4">
        <div class="flex items-start gap-3">
          <Icon
            :name="result.passed ? 'check_circle' : 'alert'"
            :size="24"
            class="mt-0.5 shrink-0"
            :class="result.passed ? 'text-ink-success' : 'text-ink-danger'"
          />
          <div class="min-w-0 flex-1">
            <p
              class="text-lg font-bold leading-tight"
              :class="result.passed ? 'text-ink-success' : 'text-ink-danger'"
            >
              {{ result.passed ? 'ผ่าน' : 'ไม่ผ่าน' }}
            </p>
            <p class="text-xs text-ink-card-muted mt-1">
              ได้ {{ result.score }} คะแนน · เกณฑ์ผ่าน {{ exam.passing_score }} คะแนน
            </p>
            <button
              type="button"
              class="mt-3 min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform"
              @click="close"
            >
              ปิด
            </button>
          </div>
        </div>
      </AppCard>

      <EmptyState
        v-else-if="!questions.length"
        icon="check_square"
        title="แบบประเมินผลนี้ยังไม่มีคำถาม"
        class="mt-4"
      />

      <template v-else>
        <div class="mt-4 space-y-4">
          <AppCard v-for="(q, qi) in questions" :key="q.id" variant="card">
            <p class="text-sm font-bold text-ink-card break-words">{{ qi + 1 }}. {{ q.question_text }}</p>
            <div class="mt-2 space-y-1.5">
              <!-- `is_correct` is null for an Agent (ExamResource) and is
                   read nowhere; styling depends only on the learner's own
                   selection. -->
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
            {{ submitting ? 'กำลังส่ง...' : 'ส่งคำตอบ' }}
          </button>
          <span class="text-xs text-ink-card-subtle">
            ตอบแล้ว {{ Object.keys(answers).length }}/{{ questions.length }} ข้อ
          </span>
        </div>

        <p v-if="submitError" class="mt-2 text-xs font-bold text-ink-danger leading-relaxed">
          {{ submitError }}
        </p>
      </template>
    </template>
  </main>
</template>
