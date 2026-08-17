<script setup lang="ts">
/**
 * QuizQuestionEditor — the question/option authoring surface for ONE quiz.
 *
 * TASK-150 / ADR-030. This is the editor that used to live inline in
 * AcademyManagementView.vue's lesson quiz panel, EXTRACTED rather than
 * copied: ADR-030 gives the same questions two entry points (the lesson and
 * the library), and two copies of a "mark exactly one option correct"
 * surface is two things that can drift. The markup, the classes and the
 * behaviour below are the ones that were already there — the only new part
 * is `addQuestionPath`.
 *
 * ── Why `addQuestionPath` is a prop ──────────────────────────────────
 * Creating a question is the ONE verb whose endpoint differs between the
 * two entry points, and deliberately so (ADR-030 §3):
 *
 *   - `/module-lessons/{lesson}/quiz-questions` — the default path. Creates
 *     and attaches a quiz on first use, so an admin who never opens the
 *     library sees no change at all.
 *   - `/quizzes/{quiz}/questions` — the library path: author before any
 *     lesson exists, which is the entire reason ADR-030 was written.
 *
 * Every OTHER verb here is already quiz-scoped and flat
 * (`/module-lesson-quiz-questions/{id}`, `/module-lesson-quiz-options/{id}`),
 * so both callers hit exactly the same endpoints for them.
 *
 * ── Why `reload` is a function prop, not an emit ─────────────────────
 * The server is the single source of truth for this list (it enforces "at
 * most one correct option per question" by mutual exclusion), so every
 * mutation refetches rather than patching local state. An emit cannot be
 * awaited, and "add option → refetch → put the cursor back in the option
 * box" needs the refetch to have LANDED before the focus call, or the input
 * it focuses is the one about to be replaced.
 */
import { nextTick, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from './Icon.vue'
import EmptyState from './EmptyState.vue'
import InfoPopover from './InfoPopover.vue'
import { QUIZ_NO_CORRECT_ANSWER_HOWTO } from '@/constants/academyBuilderCopy'

interface EditorOption {
  id: number
  option_text: string
  is_correct: boolean | null
  sort_order: number
}
interface EditorQuestion {
  id: number
  question_text: string
  sort_order: number
  options: EditorOption[]
}

const props = defineProps<{
  questions: EditorQuestion[]
  /** POST here to create a question — see the docblock for why it differs per caller. */
  addQuestionPath: string
  /** Refetches `questions` from the server. Awaited after every mutation. */
  reload: () => Promise<void>
}>()

const errorMessage = ref('')

/** Shared with the Exam question bank's own copy in AcademyManagementView — same rule, both places. */
function questionHasNoCorrectAnswer(q: EditorQuestion): boolean {
  return !q.options.some((o) => o.is_correct)
}

// ── Questions ───────────────────────────────────────────────────────
const newQuestionText = ref('')
const addingQuestion = ref(false)

async function addQuestion() {
  const text = newQuestionText.value.trim()
  if (!text) return
  addingQuestion.value = true
  errorMessage.value = ''
  try {
    await api.post(props.addQuestionPath, { question_text: text })
    newQuestionText.value = ''
    await props.reload()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'เพิ่มคำถามไม่สำเร็จ'
  } finally {
    addingQuestion.value = false
  }
}

async function deleteQuestion(questionId: number) {
  errorMessage.value = ''
  try {
    await api.delete(`/module-lesson-quiz-questions/${questionId}`)
    await props.reload()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'ลบคำถามไม่สำเร็จ'
  }
}

// ── Options ─────────────────────────────────────────────────────────
const newOptionText = ref<Record<number, string>>({})
const addingOptionFor = ref<number | null>(null)
const optionInputEls: Record<number, HTMLInputElement | null> = {}

function setOptionInputEl(questionId: number, el: Element | null) {
  optionInputEls[questionId] = el as HTMLInputElement | null
}

async function addOption(questionId: number) {
  const text = newOptionText.value[questionId]?.trim()
  if (!text) return
  addingOptionFor.value = questionId
  errorMessage.value = ''
  try {
    await api.post(`/module-lesson-quiz-questions/${questionId}/options`, { option_text: text })
    newOptionText.value[questionId] = ''
    await props.reload()
    // Keep the cursor where the admin is typing — adding four options in a
    // row should not mean four trips back to the mouse.
    await nextTick()
    optionInputEls[questionId]?.focus()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'เพิ่มตัวเลือกไม่สำเร็จ'
  } finally {
    addingOptionFor.value = null
  }
}

/**
 * Single-correct-answer multiple choice. The SERVER enforces the mutual
 * exclusion (ModuleLessonQuizOptionService), so this just marks one and
 * refetches — no client-side bookkeeping, and therefore no second
 * implementation of the rule that could disagree with the first.
 */
async function markOptionCorrect(optionId: number) {
  errorMessage.value = ''
  try {
    await api.put(`/module-lesson-quiz-options/${optionId}`, { is_correct: true })
    await props.reload()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  }
}

async function deleteOption(optionId: number) {
  errorMessage.value = ''
  try {
    await api.delete(`/module-lesson-quiz-options/${optionId}`)
    await props.reload()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'ลบตัวเลือกไม่สำเร็จ'
  }
}
</script>

<template>
  <div>
    <p v-if="errorMessage" class="mb-2 text-xs font-bold text-rose-600">{{ errorMessage }}</p>

    <EmptyState v-if="!questions.length" icon="check_square" title="ยังไม่มีคำถาม" message="พิมพ์คำถามแรกในช่องด้านล่าง" class="mb-2" />
    <div v-else class="space-y-2 mb-3">
      <div v-for="q in questions" :key="q.id" class="p-3 rounded-lg bg-white border border-slate-100">
        <div class="flex items-start justify-between gap-2 mb-1.5">
          <p class="text-sm font-bold text-slate-700 min-w-0 break-words">{{ q.question_text }}</p>
          <button class="text-rose-500 hover:text-rose-700 shrink-0" title="ลบคำถาม" @click="deleteQuestion(q.id)">
            <Icon name="trash" :size="14" />
          </button>
        </div>
        <div class="space-y-1 mb-2">
          <div v-for="opt in q.options" :key="opt.id" class="flex items-center gap-2 text-xs">
            <button
              class="w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0"
              :class="opt.is_correct ? 'border-emerald-600 bg-emerald-600' : 'border-slate-300'"
              :title="opt.is_correct ? 'คำตอบที่ถูกต้อง' : 'ทำเครื่องหมายว่าถูกต้อง'"
              @click="markOptionCorrect(opt.id)"
            >
              <Icon v-if="opt.is_correct" name="check" :size="9" class="text-white" />
            </button>
            <span :class="opt.is_correct ? 'font-bold text-emerald-700' : 'text-slate-600'" class="flex-1 min-w-0 break-words">{{ opt.option_text }}</span>
            <button class="text-slate-300 hover:text-rose-600 shrink-0" title="ลบตัวเลือก" @click="deleteOption(opt.id)">
              <Icon name="x" :size="12" />
            </button>
          </div>
        </div>
        <!-- TASK-188 §4.B5 — the STATE ("no correct answer chosen") is an error
             about this question's data and stays visible; only the how-to moved
             behind the ⓘ. Hiding the error itself would leave an unanswerable
             quiz looking finished. -->
        <p v-if="questionHasNoCorrectAnswer(q)" class="flex items-center gap-1 text-[11px] font-bold text-amber-600 mb-2">
          <Icon name="alert" :size="12" class="shrink-0" />
          ยังไม่ได้เลือกคำตอบที่ถูกต้อง
          <InfoPopover label="ยังไม่ได้เลือกคำตอบที่ถูกต้อง" :text="QUIZ_NO_CORRECT_ANSWER_HOWTO" />
        </p>
        <div class="flex items-center gap-1.5">
          <button
            class="px-2 py-1 rounded-lg bg-brand-600 text-white text-xs font-bold disabled:opacity-50 shrink-0"
            :disabled="addingOptionFor === q.id || !newOptionText[q.id]?.trim()"
            @click="addOption(q.id)"
          >
            + เพิ่ม
          </button>
          <input
            :ref="(el) => setOptionInputEl(q.id, el as Element | null)"
            v-model="newOptionText[q.id]"
            placeholder="เพิ่มตัวเลือก..."
            class="flex-1 min-w-0 px-2 py-1 rounded border border-slate-200 text-xs"
            @keyup.enter="addOption(q.id)"
          />
        </div>
      </div>
    </div>

    <div class="flex items-center gap-1.5">
      <input
        v-model="newQuestionText"
        placeholder="เพิ่มคำถามใหม่..."
        class="flex-1 min-w-0 px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
        @keyup.enter="addQuestion"
      />
      <button class="btn-primary shrink-0" :disabled="addingQuestion || !newQuestionText.trim()" @click="addQuestion">
        + เพิ่มคำถาม
      </button>
    </div>
  </div>
</template>
