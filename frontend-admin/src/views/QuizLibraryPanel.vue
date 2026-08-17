<script setup lang="ts">
/**
 * QuizLibraryPanel — the quiz LIBRARY (TASK-150 / ADR-030).
 *
 * "Author a quiz first, attach it to a lesson later. Possibly by a different
 * person, before the lesson content even exists." (§1)
 *
 * ── The exclusivity is the point, not a limitation ───────────────────
 * ADR-030 §1 records that the human's goal here is **preparation, not
 * reuse**: one quiz belongs to at most one lesson, forever, until it is
 * explicitly unlinked. So this screen is a STAGING AREA, not a shared bank,
 * and it is built to read that way — an attached quiz is shown as spoken
 * for, with the lesson holding it named, rather than offered again.
 *
 * ── Delete is refused while attached (§2.4) ──────────────────────────
 * DELETE /quizzes/{quiz} answers 422 (not 403) while the quiz is linked.
 * Rather than let an admin press a button that then fails, the control is
 * DISABLED and says why on the row: deleting under a lesson would remove
 * that lesson's completion gate, and where `quiz_blocks_completion` is on,
 * that gate sits on the BR-1 certification path. §2.5's principle — "the UI
 * should not teach the rule by rejecting the user" — is about the picker,
 * but it is the same principle.
 *
 * ── Rendered as a TAB of AcademyManagementView, not its own route ─────
 * A quiz is Academy content and the library only ever matters next to the
 * lessons it will be attached to; a separate route would mean a second page
 * shell, a second nav entry, and a back-and-forth for what is one job.
 *
 * BR-6: every read here is TenantScope'd server-side. A Super Admin sees
 * across companies (as they do on the โมดูล tab) and must name the company
 * when CREATING, exactly like POST /modules — StoreQuizRequest requires it.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import QuizQuestionEditor from '@/design-system/components/QuizQuestionEditor.vue'
import {
  QUIZ_LIBRARY_CREATE_EXPLANATION,
  QUIZ_LIBRARY_EXPLANATION,
} from '@/constants/academyBuilderCopy'

/** QuizResource — `question_count` is withCounted on every list/write response. */
interface QuizRow {
  id: number
  company_id: number
  title: string
  question_count: number
  is_attached: boolean
  module_lesson: { id: number; title: string; module_id: number } | null
}
interface QuizQuestionRow {
  id: number
  quiz_id: number
  question_text: string
  sort_order: number
  options: { id: number; option_text: string; is_correct: boolean | null; sort_order: number }[]
}

const props = defineProps<{
  isSuperAdmin: boolean
  /** The company picker's current value — required by POST /quizzes for a Super Admin. */
  selectedCompanyId: number | null
}>()

/**
 * Renaming or deleting a library quiz changes what the โมดูล tab shows on a
 * lesson (the attached quiz's title), so the parent reloads on `changed`.
 */
const emit = defineEmits<{ changed: [] }>()

// ── List ────────────────────────────────────────────────────────────
const quizzes = ref<QuizRow[]>([])
const loading = ref(false)
const hasLoadedOnce = ref(false)
const listError = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
/** ADR-030 §3 — "the library will accumulate orphans; show them as such." */
const unattachedOnly = ref(false)

async function loadQuizzes() {
  loading.value = true
  listError.value = ''
  try {
    const query = `?page=${page.value}${unattachedOnly.value ? '&unattached=1' : ''}`
    const res = await api.get<{ data: QuizRow[]; meta?: { last_page: number; total: number } }>(`/quizzes${query}`)
    quizzes.value = res.data
    lastPage.value = res.meta?.last_page ?? 1
    total.value = res.meta?.total ?? res.data.length
  } catch (e) {
    quizzes.value = []
    listError.value = e instanceof ApiError ? e.message : 'โหลดแบบทดสอบท้ายบทเรียนไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadQuizzes)

function setFilter(value: boolean) {
  if (unattachedOnly.value === value) return
  unattachedOnly.value = value
  page.value = 1
  void loadQuizzes()
}
function goToPage(next: number) {
  if (next < 1 || next > lastPage.value || next === page.value) return
  page.value = next
  void loadQuizzes()
}

// ── Create ──────────────────────────────────────────────────────────
const showCreateForm = ref(false)
const newQuizTitle = ref('')
const creating = ref(false)
const createError = ref('')

function openCreateForm() {
  showCreateForm.value = true
  createError.value = ''
}

async function createQuiz() {
  const title = newQuizTitle.value.trim()
  if (!title) return
  // Mirrors submitModule() — a Super Admin's company cannot be inferred, and
  // StoreQuizRequest would 422. Say so before the round trip (BR-6).
  if (props.isSuperAdmin && !props.selectedCompanyId) {
    createError.value = 'กรุณาเลือกบริษัทด้านบนก่อนบันทึก'
    return
  }
  creating.value = true
  createError.value = ''
  try {
    await api.post('/quizzes', {
      title,
      ...(props.isSuperAdmin ? { company_id: props.selectedCompanyId } : {}),
    })
    newQuizTitle.value = ''
    showCreateForm.value = false
    page.value = 1
    await loadQuizzes()
    emit('changed')
  } catch (e) {
    createError.value = e instanceof ApiError ? e.message : 'สร้างแบบทดสอบไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

// ── Rename ──────────────────────────────────────────────────────────
const renamingId = ref<number | null>(null)
const renameTitle = ref('')
const savingRename = ref(false)
const rowError = ref('')

function startRename(quiz: QuizRow) {
  renamingId.value = quiz.id
  renameTitle.value = quiz.title
  rowError.value = ''
}
function cancelRename() {
  renamingId.value = null
}
async function saveRename(quizId: number) {
  const title = renameTitle.value.trim()
  if (!title) return
  savingRename.value = true
  rowError.value = ''
  try {
    await api.put(`/quizzes/${quizId}`, { title })
    renamingId.value = null
    await loadQuizzes()
    emit('changed')
  } catch (e) {
    rowError.value = e instanceof ApiError ? e.message : 'เปลี่ยนชื่อไม่สำเร็จ'
  } finally {
    savingRename.value = false
  }
}

// ── Questions (reuses the lesson panel's editor, not a fork) ─────────
const expandedQuizId = ref<number | null>(null)
const questionsByQuiz = ref<Record<number, QuizQuestionRow[]>>({})
const loadingQuestionsFor = ref<number | null>(null)
const questionsError = ref('')

async function loadQuestions(quizId: number) {
  loadingQuestionsFor.value = quizId
  questionsError.value = ''
  try {
    const res = await api.get<{ data: QuizQuestionRow[] }>(`/quizzes/${quizId}/questions`)
    questionsByQuiz.value[quizId] = res.data
  } catch (e) {
    questionsError.value = e instanceof ApiError ? e.message : 'โหลดคำถามไม่สำเร็จ'
  } finally {
    loadingQuestionsFor.value = null
  }
}

async function toggleQuestions(quiz: QuizRow) {
  if (expandedQuizId.value === quiz.id) {
    expandedQuizId.value = null
    return
  }
  expandedQuizId.value = quiz.id
  await loadQuestions(quiz.id)
}

/**
 * Passed to QuizQuestionEditor. Refetches the open quiz's questions AND the
 * list row (its `question_count` is part of what the row shows), so the two
 * can never disagree after an add or a delete.
 */
async function reloadOpenQuiz(): Promise<void> {
  const quizId = expandedQuizId.value
  if (quizId === null) return
  await loadQuestions(quizId)
  await loadQuizzes()
}

// ── Delete (refused while attached — §2.4) ──────────────────────────
const pendingDelete = ref<QuizRow | null>(null)
const deleting = ref(false)

/**
 * The one-line reason shown on the row AND as the disabled button's tooltip.
 * Not a toast after a failed press: the rule is knowable before the click,
 * so it is stated before the click.
 */
function deleteBlockedReason(quiz: QuizRow): string {
  const lessonTitle = quiz.module_lesson?.title ?? 'บทเรียนหนึ่ง'
  // §4.B2 — was "(อยู่บนเส้นทางใบรับรอง BR-1)".
  return `ลบไม่ได้ — ชุดนี้ถูกใช้อยู่ในบทเรียน “${lessonTitle}” การลบจะทำให้บทเรียนนั้นเสียเงื่อนไขการเรียนจบ (อยู่บนเส้นทางใบรับรอง) · ให้ยกเลิกการเชื่อมโยงที่บทเรียนนั้นก่อน`
}

function requestDelete(quiz: QuizRow) {
  if (quiz.is_attached) return
  pendingDelete.value = quiz
}

async function confirmDelete() {
  const quiz = pendingDelete.value
  if (!quiz) return
  deleting.value = true
  rowError.value = ''
  try {
    await api.delete(`/quizzes/${quiz.id}`)
    if (expandedQuizId.value === quiz.id) expandedQuizId.value = null
    // A page can empty out under a delete; step back rather than show a
    // blank page-3 with a "ก่อนหน้า" button as the only way out.
    if (quizzes.value.length === 1 && page.value > 1) page.value -= 1
    await loadQuizzes()
    emit('changed')
  } catch (e) {
    rowError.value = e instanceof ApiError ? e.message : 'ลบไม่สำเร็จ'
  } finally {
    deleting.value = false
    pendingDelete.value = null
  }
}

const isFiltered = computed(() => unattachedOnly.value)
</script>

<template>
  <section class="mt-4">
    <!-- Filter + create -->
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
      <div class="flex items-center gap-1">
        <!-- TASK-188 §4.B1/B2 — this panel opened with a paragraph whose first
             word was literally "ADR-030". The explanation is kept; the
             reference to our own decision record is not something a customer
             can act on. -->
        <InfoPopover
          label="คลังแบบทดสอบท้ายบทเรียน"
          :text="QUIZ_LIBRARY_EXPLANATION"
          class="mr-1"
        />
        <button
          class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
          :class="!unattachedOnly ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
          @click="setFilter(false)"
        >
          ทั้งหมด
        </button>
        <button
          class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
          :class="unattachedOnly ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
          @click="setFilter(true)"
        >
          ยังไม่ถูกใช้งาน
        </button>
      </div>
      <button class="btn-primary" @click="showCreateForm = !showCreateForm">
        + สร้างแบบทดสอบ
      </button>
    </div>

    <form v-if="showCreateForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200" @submit.prevent="createQuiz">
      <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
        ชื่อชุดแบบทดสอบ
        <!-- §4.B1 — was a grey paragraph under the input. -->
        <InfoPopover label="ชื่อชุดแบบทดสอบ" :text="QUIZ_LIBRARY_CREATE_EXPLANATION" />
      </label>
      <input
        v-model="newQuizTitle"
        required
        maxlength="255"
        placeholder="เช่น แบบทดสอบท้ายบท — ความรู้พื้นฐานผลิตภัณฑ์"
        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
      />
      <!-- §4.B2 — was "…จึงจะสร้างได้ (BR-6)". This is a blocking condition an
           admin must act on, not an explanation, so it stays visible; only the
           citation went. -->
      <p v-if="props.isSuperAdmin && !props.selectedCompanyId" class="text-[11px] font-bold text-amber-600 mt-1">
        Super Admin: ต้องเลือกบริษัทด้านบนก่อน จึงจะสร้างได้
      </p>
      <p v-if="createError" class="text-xs font-bold text-rose-600 mt-2">{{ createError }}</p>
      <div class="mt-3 flex justify-end gap-2">
        <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="showCreateForm = false">
          ยกเลิก
        </button>
        <button type="submit" class="btn-primary" :disabled="creating || !newQuizTitle.trim()">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <p v-if="rowError" class="mb-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs font-bold text-rose-700">{{ rowError }}</p>

    <!-- Loading / error / empty / list — all four states, never a blank panel -->
    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" />
    <div v-else-if="listError" class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      <p class="font-bold">{{ listError }}</p>
      <button class="mt-2 px-2.5 py-1.5 rounded-lg border border-rose-200 text-xs font-bold text-rose-700 hover:bg-rose-100" @click="loadQuizzes">
        ลองใหม่
      </button>
    </div>
    <EmptyState
      v-else-if="!quizzes.length && isFiltered"
      icon="check_square"
      title="ไม่มีแบบทดสอบที่ยังไม่ถูกใช้งาน"
      message="ทุกชุดในคลังถูกผูกกับบทเรียนแล้ว"
      cta-label="ดูทั้งหมด"
      :cta-disabled="false"
      @cta="setFilter(false)"
    />
    <EmptyState
      v-else-if="!quizzes.length"
      icon="check_square"
      title="ยังไม่มีแบบทดสอบท้ายบทเรียน"
      message="สร้างชุดคำถามเตรียมไว้ก่อนได้ แล้วค่อยผูกกับบทเรียนภายหลัง"
      cta-label="+ สร้างชุดแรก"
      :cta-disabled="false"
      @cta="openCreateForm"
    />
    <div v-else class="space-y-2">
      <div v-for="quiz in quizzes" :key="quiz.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <!-- Rename form -->
        <div v-if="renamingId === quiz.id" class="flex flex-wrap items-center gap-2">
          <input v-model="renameTitle" maxlength="255" class="flex-1 min-w-[12rem] px-3 py-2 rounded-lg border border-slate-200 text-sm" @keyup.enter="saveRename(quiz.id)" />
          <button class="btn-primary" :disabled="savingRename || !renameTitle.trim()" @click="saveRename(quiz.id)">
            {{ savingRename ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="cancelRename">
            ยกเลิก
          </button>
        </div>

        <template v-else>
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-bold text-slate-900 break-words">{{ quiz.title }}</p>
              <p class="text-xs text-slate-400 mt-0.5">
                {{ quiz.question_count }} คำถาม ·
                <span v-if="quiz.is_attached" class="font-bold text-emerald-600">
                  ใช้อยู่ในบทเรียน “{{ quiz.module_lesson?.title ?? '—' }}”
                </span>
                <span v-else class="font-bold text-slate-500">ยังไม่ถูกใช้งาน</span>
              </p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
              <button
                class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                @click="toggleQuestions(quiz)"
              >
                <Icon name="check_square" :size="13" />
                {{ expandedQuizId === quiz.id ? 'ซ่อนคำถาม' : 'แก้ไขคำถาม' }}
              </button>
              <button title="เปลี่ยนชื่อ" class="text-slate-400 hover:text-brand-600 p-1" @click="startRename(quiz)">
                <Icon name="pencil" :size="15" />
              </button>
              <!-- §2.4 — disabled, not a button that 422s. -->
              <button
                :title="quiz.is_attached ? deleteBlockedReason(quiz) : 'ลบแบบทดสอบ'"
                :disabled="quiz.is_attached"
                class="p-1"
                :class="quiz.is_attached ? 'text-slate-300 cursor-not-allowed' : 'text-rose-600 hover:text-rose-700'"
                @click="requestDelete(quiz)"
              >
                <Icon name="trash" :size="15" />
              </button>
            </div>
          </div>

          <p v-if="quiz.is_attached" class="mt-1.5 flex items-start gap-1 text-[11px] text-slate-400 leading-relaxed">
            <Icon name="info" :size="12" class="shrink-0 mt-0.5" />
            {{ deleteBlockedReason(quiz) }}
          </p>

          <!-- Question editor — the SAME component the lesson panel uses. -->
          <div v-if="expandedQuizId === quiz.id" class="mt-3 pt-3 border-t border-slate-200">
            <p v-if="loadingQuestionsFor === quiz.id && !questionsByQuiz[quiz.id]" class="text-xs text-slate-400">กำลังโหลดคำถาม...</p>
            <p v-else-if="questionsError" class="text-xs font-bold text-rose-600">{{ questionsError }}</p>
            <QuizQuestionEditor
              v-else
              :questions="questionsByQuiz[quiz.id] ?? []"
              :add-question-path="`/quizzes/${quiz.id}/questions`"
              :reload="reloadOpenQuiz"
            />
          </div>
        </template>
      </div>

      <!-- Pagination — GET /quizzes is paginated, so page 2+ must be reachable. -->
      <div v-if="lastPage > 1" class="flex items-center justify-between gap-2 pt-1">
        <button
          class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 disabled:opacity-40"
          :disabled="page <= 1 || loading"
          @click="goToPage(page - 1)"
        >
          ก่อนหน้า
        </button>
        <span class="text-[11px] text-slate-400">หน้า {{ page }} / {{ lastPage }} · ทั้งหมด {{ total }} ชุด</span>
        <button
          class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 disabled:opacity-40"
          :disabled="page >= lastPage || loading"
          @click="goToPage(page + 1)"
        >
          ถัดไป
        </button>
      </div>
    </div>

    <ConfirmDialog
      :show="pendingDelete !== null"
      variant="danger"
      :title="pendingDelete ? `ลบ “${pendingDelete.title}”` : ''"
      body="ลบชุดแบบทดสอบนี้พร้อมคำถามทั้งหมดในชุด ยืนยันหรือไม่?"
      :busy="deleting"
      @confirm="confirmDelete"
      @update:show="(v) => { if (!v) pendingDelete = null }"
    />
  </section>
</template>
