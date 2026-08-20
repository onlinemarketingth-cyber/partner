<script setup lang="ts">
/**
 * AcademyManagementView — Admin CRUD for Module/Exam + read-only Agent
 * progress reporting (ERD-001 §Academy, BR-1, BR-5).
 *
 * Company Admin authors syllabus content and exams here; the actual
 * BR-1 gate logic (pass -> user_certifications row) lives entirely in
 * the backend's ExamAttemptService — this screen only manages content
 * and reads outcomes, it never computes pass/fail itself.
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import LessonPreviewModal from '@/design-system/components/LessonPreviewModal.vue'
import LessonPreviewStrip from '@/design-system/components/LessonPreviewStrip.vue'
// TASK-150 / ADR-030 — the question/option authoring surface, EXTRACTED from
// this file rather than copied, so the lesson entry point and the library
// entry point can never drift apart. See the component's own docblock.
import QuizQuestionEditor from '@/design-system/components/QuizQuestionEditor.vue'
// TASK-188 Phase A/B — every explanation on this screen now lives behind this
// ⓘ (human decision D1, 2026-08-13), including the consequence warnings.
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import CertTierPanel from './CertTierPanel.vue'
import QuizLibraryPanel from './QuizLibraryPanel.vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — the app-wide company scope.
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { classifyEmbedUrl, toEmbedUrl } from '@/utils/embedUrl'
import { readStored, writeStored } from '@/utils/safeStorage'
// TASK-188 §4.B3 — the builder's copy, defined once. Four of these strings used
// to exist twice in this file and two of the pairs had already drifted.
import {
  COMPANY_QUIZ_PASS_PERCENT_EXPLANATION,
  COMPLETION_SETTINGS_EXPLANATION,
  contentTypeChangeConfirmBody,
  DOWNLOADABLE_EXPLANATION,
  DRAG_LESSON_HANDLE_TITLE,
  DRAG_REORDER_EXPLANATION,
  dragSectionHandleTitle,
  embedUrlExplanation,
  INSPECTOR_SCOPE_EXPLANATION,
  LESSON_GATE_IS_COMPANY_LEVEL_EXPLANATION,
  LESSON_PROGRESS_EXPLANATION,
  LESSON_QUIZ_PASS_PERCENT_EXPLANATION,
  OPTIONAL_LESSON_EXPLANATION,
  OPTIONAL_LESSON_PILL_TITLE,
  OUTLINE_SELECT_EXPLANATION,
  QUIZ_ATTEMPTS_EXPLANATION,
  QUIZ_BLOCKS_COMPLETION_EXPLANATION,
  QUIZ_LESSON_TYPE_EXPLANATION,
  QUIZ_NO_CORRECT_ANSWER_HOWTO,
  QUIZ_PICKER_EXPLANATION,
  QUIZ_SOURCE_EXPLANATION,
  RETYPE_CONTENT_TYPE_EXPLANATION,
  SECTION_DRIP_EXPLANATION,
  SECTION_PUBLISH_EXPLANATION,
  SECTION_SEQUENTIAL_EXPLANATION,
  type ContentTypeChangeImpact,
} from '@/constants/academyBuilderCopy'

interface CertTier {
  id: number
  key: string
  name: string
}
interface Product {
  id: number
  name: string
}
// ADR-009 — Module is now a "Section" (pure grouping/ordering
// container under a cert tier); the actual content item (video/pdf/
// link, or a formative quiz) lives on ModuleLesson, many per Section.
interface ModuleLessonQuizOptionItem {
  id: number
  option_text: string
  is_correct: boolean | null
  sort_order: number
}
interface ModuleLessonQuizQuestionItem {
  id: number
  question_text: string
  sort_order: number
  options: ModuleLessonQuizOptionItem[]
}
// ADR-007 — video content_type gained source_type (upload|embed) +
// processing_status + stream_url; content_ref is nulled in the API
// response for an uploaded video (private path never exposed raw —
// stream_url is the only access path for that case).
// ADR-028 §2.1/§2.2 (TASK-142/145) — `image` joins the content types, an
// uploaded pdf/image is stored on our private disk exactly like a video,
// and each uploaded file carries is_downloadable. duration_seconds /
// page_count are SERVER measurements (ffprobe / pdfinfo) and are the
// denominators the completion gate uses — never client-supplied.
type LessonContentType = 'video' | 'pdf' | 'image' | 'quiz' | 'link'
interface ModuleLessonItem {
  id: number
  module_id: number
  title: string
  content_type: LessonContentType
  source_type: 'upload' | 'embed' | null
  content_ref: string | null
  stream_url: string | null
  inline_url: string | null
  is_downloadable: boolean
  duration_seconds: number | null
  page_count: number | null
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
  sort_order: number
  xp_reward: number
  is_published: boolean
  /*
   * ADR-031 §2.4 — "shown, not counted". An optional lesson is excluded
   * from every progress denominator and never blocks a sequential chain.
   * Authored from the lesson edit form below.
   */
  is_optional: boolean
  /*
   * ── ADR-029, the quiz block ──────────────────────────────────────
   *
   * §2.1 — a quiz is no longer tied to `content_type === 'quiz'`. ANY
   * lesson may carry one, so every control below keys off
   * `quiz_question_count`, never off the content type.
   *
   * `quiz_pass_percent` is ADMIN-ONLY (ModuleLessonResource mergeWhen) and
   * NULL means INHERIT the company setting (§2.4). It is absent from an
   * Agent's payload entirely, which is why an empty field here has to read
   * as "ใช้ค่าของบริษัท" rather than as zero.
   *
   * `quiz_unlocked` is always true for an admin (they are authoring, not
   * learning), so it cannot be used here to preview the learner's locked
   * state — the preview modal says so in words instead.
   */
  quiz_question_count: number
  quiz_unlocked: boolean
  quiz_blocks_completion: boolean
  quiz_passed: boolean | null
  quiz_pass_percent: number | null
  quiz_questions?: ModuleLessonQuizQuestionItem[]
  /*
   * ── ADR-030, the LINK ────────────────────────────────────────────
   *
   * §2.1 — the questions now live on a `quizzes` row, and the lesson
   * points at it. `quiz_id` is null for a lesson that has never had a
   * quiz; `quiz` is ADMIN-ONLY (ModuleLessonResource mergeWhen) and is the
   * only way to tell a library quiz from one typed in place — a quiz
   * created by typing here is named after the lesson (QuizService::
   * ensureForLesson), one picked from the library carries its own name.
   *
   * The UNIQUE index on `module_lessons.quiz_id` is what makes "one quiz,
   * at most one lesson, forever" true; nothing in this file enforces it,
   * and nothing here should try to.
   */
  quiz_id: number | null
  quiz?: { id: number; title: string }
}
/**
 * ADR-029 §2.5 — the admin readout of a lesson's quiz attempts, so an
 * admin "can see someone who took eleven tries".
 *
 * SCORE ONLY. ADR-029 §4 item 2 — whether an admin may see which answers a
 * learner chose — is UNRESOLVED and PDPA-adjacent, and the answers are not
 * merely omitted from the API, they are NOT STORED AT ALL. So this UI must
 * not imply they exist: no "ดูคำตอบ" affordance, no per-question column.
 */
interface ModuleLessonQuizAttemptRow {
  id: number
  user_id: number
  user?: { id: number; first_name: string; last_name: string } | null
  module_lesson_id: number
  score: number
  total_questions: number
  passed: boolean
  attempted_at: string
}
/**
 * ADR-028 §4 — the ADMIN readout of recorded learner progress.
 *
 * The human ruled that a blocked LEARNER is not told how far they got,
 * and named the cost: "expect support contacts from learners who believe
 * they finished and did not... Admin needs to *see* the recorded progress
 * even though the learner does not." This is that readout; without it
 * every blocked learner becomes an unresolvable ticket.
 *
 * Both halves of each pair are shown deliberately: a learner sitting at
 * last_page 3 with max_page 12 is a different support conversation from
 * one whose max_page is 3.
 */
interface LessonProgressRow {
  id: number
  user_id: number
  user?: { id: number; first_name: string; last_name: string } | null
  last_position_seconds: number | null
  max_position_seconds: number | null
  last_page: number | null
  max_page: number | null
  total_pages: number | null
  updated_at: string
}
interface ModuleItem {
  id: number
  // ADR-031 §2.1 — needed to group Sections for the bulk reorder:
  // `cert_tiers` is GLOBAL config, so a Super Admin's flat list can hold two
  // companies' Sections under the same tier, and ModuleOrderService rejects a
  // cross-company payload outright. The drag group key is company + tier.
  company_id: number
  title: string
  cert_tier: CertTier | null
  product: Product | null
  is_published: boolean
  sort_order: number
  /*
   * ADR-031 §2.2/§2.3 — the Section's two release controls, edited behind the
   * gear on the Section row (§3: the two rarely-used ones "belong behind the
   * Section's settings, not inline on every row").
   */
  enforce_sequential: boolean
  drip_days: number | null
  /*
   * ADR-031 §2.4 / TASK-151 — THE SERVER-SIDE COUNTS. Every "X บทเรียน" on
   * this screen used to be `lessons.length`, which counts drafts and optional
   * lessons alike. Three counts because the two audiences want different
   * numbers: `lesson_count` is the admin's "this Section holds 12 lessons"
   * (drafts included), `required_lesson_count` is the LEARNER'S denominator
   * (published AND not optional), `optional_lesson_count` is the "+2 บทเสริม"
   * beside it. A denominator is never computed in this file any more.
   */
  lesson_count: number
  required_lesson_count: number
  optional_lesson_count: number
  lessons: ModuleLessonItem[]
}
interface ExamItem {
  id: number
  title: string
  passing_score: number
  cert_tier: CertTier | null
}
// Academy Sprint 1/2 (2026-07-21) — question bank authoring.
interface ExamQuestionOptionItem {
  id: number
  exam_question_id: number
  option_text: string
  is_correct: boolean
  sort_order: number
}
interface ExamQuestionItem {
  id: number
  exam_id: number
  question_text: string
  sort_order: number
  options: ExamQuestionOptionItem[]
}
/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-152 — GET /academy-progress-summary, the ความคืบหน้าตัวแทน payload
 * ═══════════════════════════════════════════════════════════════════════
 *
 * WHAT THIS REPLACED, AND WHY IT CANNOT COME BACK
 * -----------------------------------------------
 * This tab used to build every "X/Y บทเรียน" in the browser by joining THREE
 * separately PAGINATED endpoints: /modules (15 Sections a page), the
 * company-wide /module-completions (15 rows a page) and /user-certifications
 * (15 rows a page). Only /users was ever read past page 1. So on any company
 * with more than 15 Sections, or more than 15 completions IN TOTAL across all
 * agents — essentially every real company — the numerator AND the denominator
 * were both silently truncated and the fraction on screen was fiction.
 *
 * Those fractions are how a Company Admin judges progress toward the Basic
 * certification that unlocks selling rights (BR-1), so a wrong number there is
 * not cosmetic. The three reads and the client-side join are GONE, not kept as
 * a fallback: a fallback would leave the wrong numbers reachable.
 *
 * Every count below is a SQL aggregate (AcademyProgressSummaryService). The
 * agent LIST is the only paginated part, which is honest — the admin asked for
 * a screenful of people and gets a screenful of people, with `meta` saying how
 * many more there are.
 */
interface ProgressSummaryOutlineLesson {
  id: number
  title: string
  sort_order: number
  is_optional: boolean
  is_published: boolean
}
/** One Section of the course outline, shipped ONCE for the whole company. */
interface ProgressSummarySection {
  id: number
  title: string
  sort_order: number
  cert_tier_id: number
  is_published: boolean
  lesson_count: number
  // ADR-031 §2.4 — published AND not optional. THE denominator; never
  // recomputed here from `lessons` below (which deliberately includes drafts
  // so an admin can see what they have not published yet).
  required_lesson_count: number
  optional_lesson_count: number
  lessons: ProgressSummaryOutlineLesson[]
  // Company-wide roll-up, present on `summary.sections` only.
  agents_completed?: number
  completed_required_total?: number
}
interface ProgressSummaryAgentSection {
  module_id: number
  required_lesson_count: number
  completed_required_count: number
}
interface ProgressSummaryRow {
  user_id: number
  name: string
  first_name: string
  last_name: string
  /** The company-wide denominator, repeated per row so the row is self-describing. */
  required_lesson_count: number
  completed_required_count: number
  /** Completions on a lesson that is NOW optional — reported beside the fraction, never inside it. */
  completed_optional_count: number
  completed_lesson_ids: number[]
  cert_tiers_passed: { id: number; key: string; name: string; passed_at: string }[]
  sections: ProgressSummaryAgentSection[]
}
interface ProgressSummaryResponse {
  data: ProgressSummaryRow[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  summary: {
    company_id: number
    agent_count: number
    required_lesson_count: number
    sections: ProgressSummarySection[]
  }
  computed_at: string
}

// TASK-150 / ADR-030 — the quiz LIBRARY is a fourth TAB here rather than its
// own route: it is Academy content, it only matters next to the lessons it
// will be attached to, and a separate page would mean a second shell, a
// second nav entry and a back-and-forth for one job. It sits directly after
// โมดูล because that is the tab an admin comes from and returns to.
type Tab = 'modules' | 'quizzes' | 'exams' | 'progress' | 'tiers'
const activeTab = ref<Tab>('modules')

/*
 * TASK-221 — "ระดับใบรับรอง" is a FIFTH tab, and a Super-Admin-only one.
 *
 * A tab rather than its own route for the same reason the quiz library is
 * one (ADR-030): it is Academy config that only matters next to the
 * Sections and exams that reference it, and every other Academy screen
 * already lives here.
 *
 * LAST, not first, even though nothing else on this page works without at
 * least one tier. It is set up once and then almost never touched, and a
 * tab an admin passes over daily to reach the one they want is a tax on
 * every other visit. The empty states of the tabs that DO need a tier are
 * what point here.
 *
 * superAdminOnly because `cert_tiers` has no company_id — one list shared
 * by every tenant. See CertTierPolicy on the server, which enforces it.
 */
const allTabs: { key: Tab; label: string; icon: string; superAdminOnly?: boolean }[] = [
  { key: 'modules', label: 'โมดูล', icon: 'book' },
  { key: 'quizzes', label: 'แบบทดสอบท้ายบทเรียน', icon: 'layers' },
  { key: 'exams', label: 'แบบประเมินผล', icon: 'check_square' },
  { key: 'progress', label: 'ความคืบหน้าตัวแทน', icon: 'users' },
  { key: 'tiers', label: 'ระดับใบรับรอง', icon: 'shield_check', superAdminOnly: true },
]

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const certTiers = ref<CertTier[]>([])
const products = ref<Product[]>([])
const modules = ref<ModuleItem[]>([])
const exams = ref<ExamItem[]>([])

// ModuleService/ExamService require company_id in the payload when the
// actor is Super Admin (Company Admin's own company_id is inferred
// server-side) — same gap/fix shape as ProductCatalogView.vue's Brand/
// Category forms (found via E2E UAT: Super Admin had no way at all to
// author Academy content for any company, including Thai Life, through
// this screen — POST /modules and POST /exams both 422'd).
const authStore = useAuthStore()
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')

const tabs = computed(() => allTabs.filter((t) => !t.superAdminOnly || isSuperAdmin.value))
// TASK-208 — this screen carried TWO copies of the same company <select>
// (one for modules/quizzes/progress, one for exams) plus its own /companies
// fetch. All of it now comes from the global store; the alias keeps the rest
// of this 4000-line file reading unchanged.
const activeCompany = useActiveCompanyStore()
const selectedCompanyId = computed(() => activeCompany.companyId)
activeCompany.loadCompanies()

/**
 * Follows every page of a paginated index endpoint.
 *
 * STILL NEEDED FOR /modules, and now load-bearing rather than cosmetic:
 * ModuleController::index() paginates at 15, and ADR-031 §2.1's bulk reorder
 * requires the COMPLETE sibling set of a cert tier in one payload
 * (ModuleOrderService::assertCompleteSet → 422 "กรุณารีเฟรชหน้าแล้วลองใหม่").
 * A course with a sixteenth Section would therefore have had a drag that
 * could only ever fail, and — before that — a โมดูล tab that silently stopped
 * drawing Sections at fifteen.
 *
 * It is NO LONGER used for the progress tab's roster: that whole
 * three-endpoint client-side join is gone (see ProgressSummaryResponse), and
 * the agent list now paginates server-side where the fractions do not depend
 * on it.
 */
async function fetchAllPages<T>(path: string): Promise<T[]> {
  const sep = path.includes('?') ? '&' : '?'
  const first = await api.get<{ data: T[]; meta?: { last_page: number } }>(`${path}${sep}page=1`)
  const items = [...first.data]
  const lastPage = first.meta?.last_page ?? 1
  for (let page = 2; page <= lastPage; page++) {
    const next = await api.get<{ data: T[] }>(`${path}${sep}page=${page}`)
    items.push(...next.data)
  }
  return items
}

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [t, p, m, e] = await Promise.all([
      api.get<{ data: CertTier[] }>('/cert-tiers'),
      api.get<{ data: Product[] }>('/products'),
      fetchAllPages<ModuleItem>('/modules'),
      api.get<{ data: ExamItem[] }>('/exams'),
    ])
    certTiers.value = t.data
    products.value = p.data
    modules.value = m
    exams.value = e.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

// ── Section (Module) form — ADR-009: pure grouping/ordering, no
// content fields at all anymore (those moved to Lesson below). ──
const showModuleForm = ref(false)
const moduleForm = ref({ title: '', cert_tier_id: '', product_id: '', is_published: true })
const submittingModule = ref(false)
const moduleError = ref('')

async function submitModule() {
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    moduleError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  submittingModule.value = true
  moduleError.value = ''
  try {
    await api.post('/modules', {
      title: moduleForm.value.title,
      cert_tier_id: Number(moduleForm.value.cert_tier_id),
      product_id: moduleForm.value.product_id ? Number(moduleForm.value.product_id) : null,
      is_published: moduleForm.value.is_published,
      ...(isSuperAdmin.value ? { company_id: selectedCompanyId.value } : {}),
    })
    moduleForm.value = { title: '', cert_tier_id: '', product_id: '', is_published: true }
    showModuleForm.value = false
    await loadAll()
  } catch (e) {
    moduleError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ — ตรวจสอบข้อมูลที่กรอก (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    submittingModule.value = false
  }
}

function moduleProcessingLabel(status: ModuleLessonItem['processing_status']): string {
  switch (status) {
    case 'pending':
    case 'processing':
      return 'กำลังย่อไฟล์วิดีโอ...'
    case 'failed':
      return 'ย่อไฟล์ไม่สำเร็จ (ใช้ไฟล์ต้นฉบับ)'
    default:
      return ''
  }
}

// ── Section edit/delete ──
const editingModuleId = ref<number | null>(null)
const editModuleForm = ref({ title: '', cert_tier_id: '', product_id: '', sort_order: '0', is_published: true })
function startEditModule(m: ModuleItem) {
  editingModuleId.value = m.id
  editModuleForm.value = {
    title: m.title,
    cert_tier_id: String(m.cert_tier?.id ?? ''),
    product_id: m.product?.id ? String(m.product.id) : '',
    sort_order: String(m.sort_order),
    is_published: m.is_published,
  }
}
function cancelEditModule() {
  editingModuleId.value = null
}
async function saveEditModule(moduleId: number) {
  try {
    await api.put(`/modules/${moduleId}`, {
      title: editModuleForm.value.title,
      cert_tier_id: Number(editModuleForm.value.cert_tier_id),
      product_id: editModuleForm.value.product_id ? Number(editModuleForm.value.product_id) : null,
      sort_order: Number(editModuleForm.value.sort_order),
      is_published: editModuleForm.value.is_published,
    })
    editingModuleId.value = null
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  }
}
// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal. deleteModule() opens the dialog;
// confirmDeleteModule() (wired to @confirm) does the actual delete.
const pendingDeleteModuleId = ref<number | null>(null)
function deleteModule(moduleId: number) {
  pendingDeleteModuleId.value = moduleId
}
async function confirmDeleteModule() {
  const moduleId = pendingDeleteModuleId.value
  if (moduleId === null) return
  try {
    await api.delete(`/modules/${moduleId}`)
    modules.value = modules.value.filter((m) => m.id !== moduleId)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
  } finally {
    pendingDeleteModuleId.value = null
  }
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-151 / ADR-031 §2.1 — DRAG-AND-DROP ORDERING
 * ═══════════════════════════════════════════════════════════════════════
 *
 * NATIVE HTML5 DRAG EVENTS, no library: neither `vuedraggable` nor
 * `sortablejs` is in this app's package.json, and ADR-031 does not justify
 * adding a dependency for two lists.
 *
 * TWO INVARIANTS THIS CODE EXISTS TO HOLD:
 *
 *  1. ONE request per drop, carrying the FULL ordered sibling set — never
 *     one PUT per row (§2.1: "which would leave the list half-reordered if
 *     the tab closed mid-way"). ModuleOrderService rejects an incomplete
 *     list with a 422, so a partial payload is not merely discouraged, it is
 *     not representable.
 *
 *  2. A FAILED REORDER RESTORES THE PREVIOUS ORDER **AND SAYS SO**. A list
 *     that silently snaps back with no message reads as a broken drag, and
 *     the admin's next move is to try again — which fails identically.
 *
 * The numeric `sort_order` field stays in both edit forms untouched as the
 * keyboard-accessible fallback (§2.1: "drag is added, not substituted").
 *
 * SCOPE OF A DRAG. Sections reorder within one cert tier (that is the
 * endpoint's parent), and `cert_tiers` is GLOBAL config with no company_id —
 * so for a Super Admin one tier can hold two companies' Sections, and
 * ModuleOrderService refuses a cross-company payload. The group key is
 * therefore company + tier, and the list below is rendered grouped so the
 * boundary an admin may drag within is visible rather than discovered by a
 * rejected drop.
 */
interface ModuleGroup {
  key: string
  certTier: CertTier | null
  modules: ModuleItem[]
}
const moduleGroups = computed<ModuleGroup[]>(() => {
  const groups = new Map<string, ModuleGroup>()
  for (const m of modules.value) {
    const key = `${m.company_id}:${m.cert_tier?.id ?? 'none'}`
    let group = groups.get(key)
    if (!group) {
      group = { key, certTier: m.cert_tier, modules: [] }
      groups.set(key, group)
    }
    group.modules.push(m)
  }
  return [...groups.values()]
})

const reorderError = ref('')
const savingOrder = ref(false)
// The handle "arms" its row: `draggable` is only true while the grab handle
// is held, so text selection inside the row's inputs still works and a
// mis-drag on the card body does nothing.
const armedModuleId = ref<number | null>(null)
const draggingModuleId = ref<number | null>(null)
const dragOverModuleId = ref<number | null>(null)
const armedLessonId = ref<number | null>(null)
const draggingLessonId = ref<number | null>(null)
const dragOverLessonId = ref<number | null>(null)

function moduleGroupKey(m: ModuleItem): string {
  return `${m.company_id}:${m.cert_tier?.id ?? 'none'}`
}

/** Moves `fromId` to `toId`'s position within `list`, returning a new array. */
function moveWithin<T extends { id: number }>(list: T[], fromId: number, toId: number): T[] {
  const from = list.findIndex((x) => x.id === fromId)
  const to = list.findIndex((x) => x.id === toId)
  if (from === -1 || to === -1 || from === to) return list
  const next = [...list]
  const [moved] = next.splice(from, 1)
  if (moved) next.splice(to, 0, moved)
  return next
}

function onModuleDragStart(m: ModuleItem, event: DragEvent) {
  if (armedModuleId.value !== m.id) {
    // Not grabbed by the handle — refuse rather than starting a drag the
    // admin did not ask for.
    event.preventDefault()
    return
  }
  draggingModuleId.value = m.id
  reorderError.value = ''
  event.dataTransfer?.setData('text/plain', String(m.id))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onModuleDragOver(m: ModuleItem, event: DragEvent) {
  const dragged = modules.value.find((x) => x.id === draggingModuleId.value)
  // Not a drop target across a group boundary: the endpoint's sibling set is
  // one cert tier of one company, and a cross-group payload is a 422.
  if (!dragged || moduleGroupKey(dragged) !== moduleGroupKey(m)) return
  event.preventDefault()
  dragOverModuleId.value = m.id
}

function endModuleDrag() {
  armedModuleId.value = null
  draggingModuleId.value = null
  dragOverModuleId.value = null
}

async function onModuleDrop(target: ModuleItem) {
  const draggedId = draggingModuleId.value
  const dragged = modules.value.find((x) => x.id === draggedId)
  endModuleDrag()
  if (!dragged || dragged.id === target.id || moduleGroupKey(dragged) !== moduleGroupKey(target)) return

  const certTierId = dragged.cert_tier?.id
  if (!certTierId) {
    reorderError.value = 'จัดลำดับไม่ได้: Section นี้ยังไม่ได้ผูกกับระดับใบรับรอง'
    return
  }

  // Snapshot the groups (and the flat array) BEFORE the optimistic write —
  // `moduleGroups` is derived from `modules`, so reading it after the write
  // would give the new order back and there would be nothing to restore.
  const groups = moduleGroups.value
  const previous = modules.value
  const group = groups.find((g) => g.key === moduleGroupKey(dragged))
  if (!group) return
  const reordered = moveWithin(group.modules, dragged.id, target.id)
  if (reordered === group.modules) return

  modules.value = groups.flatMap((g) => (g.key === group.key ? reordered : g.modules))

  savingOrder.value = true
  reorderError.value = ''
  try {
    const res = await api.put<{ data: ModuleItem[] }>(`/cert-tiers/${certTierId}/modules/reorder`, {
      module_ids: reordered.map((m) => m.id),
    })
    // Take the server's renumbering rather than assuming 0..n-1: sort_order
    // is displayed on the row and in the edit form.
    const byId = new Map(res.data.map((m) => [m.id, m]))
    modules.value = modules.value.map((m) => byId.get(m.id) ?? m)
  } catch (e) {
    modules.value = previous
    reorderError.value =
      e instanceof ApiError ? `จัดลำดับไม่สำเร็จ — คืนลำดับเดิมแล้ว: ${e.message}` : 'จัดลำดับไม่สำเร็จ — คืนลำดับเดิมแล้ว'
  } finally {
    savingOrder.value = false
  }
}

function onLessonDragStart(l: ModuleLessonItem, event: DragEvent) {
  if (armedLessonId.value !== l.id) {
    event.preventDefault()
    return
  }
  draggingLessonId.value = l.id
  reorderError.value = ''
  event.dataTransfer?.setData('text/plain', String(l.id))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onLessonDragOver(m: ModuleItem, l: ModuleLessonItem, event: DragEvent) {
  const dragged = m.lessons.find((x) => x.id === draggingLessonId.value)
  // Same Section only — the sibling set is one Section's lessons.
  if (!dragged) return
  event.preventDefault()
  dragOverLessonId.value = l.id
}

function endLessonDrag() {
  armedLessonId.value = null
  draggingLessonId.value = null
  dragOverLessonId.value = null
}

async function onLessonDrop(m: ModuleItem, target: ModuleLessonItem) {
  const dragged = m.lessons.find((x) => x.id === draggingLessonId.value)
  endLessonDrag()
  if (!dragged || dragged.id === target.id) return

  const reordered = moveWithin(m.lessons, dragged.id, target.id)
  if (reordered === m.lessons) return

  const previous = m.lessons
  m.lessons = reordered

  savingOrder.value = true
  reorderError.value = ''
  try {
    const res = await api.put<{ data: ModuleLessonItem[] }>(`/modules/${m.id}/lessons/reorder`, {
      lesson_ids: reordered.map((l) => l.id),
    })
    m.lessons = res.data
  } catch (e) {
    m.lessons = previous
    reorderError.value =
      e instanceof ApiError ? `จัดลำดับบทเรียนไม่สำเร็จ — คืนลำดับเดิมแล้ว: ${e.message}` : 'จัดลำดับบทเรียนไม่สำเร็จ — คืนลำดับเดิมแล้ว'
  } finally {
    savingOrder.value = false
  }
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-151 / ADR-031 §2.2, §2.3 — THE SECTION'S RELEASE CONTROLS
 * ═══════════════════════════════════════════════════════════════════════
 *
 * §3: "the two rarely-used ones (drip, sequential) belong behind the
 * Section's settings, not inline on every row" — the same treatment
 * `expandedQuizSettingsLessonId` already gives the per-lesson quiz settings.
 * The collapsed summary still states both values, so this hides the FORM,
 * not the CONFIGURATION: `enforce_sequential` gates lessons on the BR-1
 * certification path, and an admin must be able to see it is on without
 * opening anything.
 *
 * Saved through the ordinary PUT /modules/{module}. `drip_days` is sent as
 * NULL when the field is blank, because null is what "available immediately"
 * IS in the schema — 0 would mean "unlocks at the anchor", which is nearly
 * the same thing today but is a configured rule rather than the absence of
 * one, and the two must not be conflated.
 */
const expandedSectionSettingsId = ref<number | null>(null)
/*
 * TASK-152b — `is_published` joined the two release controls here rather than
 * getting a save path of its own. It is the third thing §4 asks the inspector
 * to carry for a Section, it already travels on the same PUT /modules/{module}
 * (saveEditModule sends it), and a second save button beside this one would be
 * two buttons that write the same row. The gear panel on a narrow viewport
 * does not render the checkbox, so it simply echoes the Section's current
 * value back — unchanged behaviour there.
 */
const sectionSettingsForm = ref<Record<number, { enforce_sequential: boolean; drip_days: string; is_published: boolean }>>({})
const savingSectionSettingsFor = ref<number | null>(null)
const sectionSettingsSavedFor = ref<number | null>(null)
const sectionSettingsError = ref('')

function toggleSectionSettings(m: ModuleItem) {
  sectionSettingsSavedFor.value = null
  sectionSettingsError.value = ''
  if (expandedSectionSettingsId.value === m.id) {
    expandedSectionSettingsId.value = null
    return
  }
  sectionSettingsForm.value[m.id] = {
    enforce_sequential: m.enforce_sequential,
    drip_days: m.drip_days === null ? '' : String(m.drip_days),
    is_published: m.is_published,
  }
  expandedSectionSettingsId.value = m.id
}

/** The collapsed one-line summary — the configuration stays readable while the form is hidden. */
function sectionSettingsSummary(m: ModuleItem): string {
  const sequential = m.enforce_sequential ? 'ต้องเรียนตามลำดับ' : 'เรียนข้ามบทได้'
  const drip = m.drip_days === null ? 'เปิดให้เรียนทันที' : `เปิดให้เรียนหลังอนุมัติบัญชี ${m.drip_days} วัน`
  return `${sequential} · ${drip}`
}

async function saveSectionSettings(m: ModuleItem) {
  const form = sectionSettingsForm.value[m.id]
  if (!form) return
  savingSectionSettingsFor.value = m.id
  sectionSettingsSavedFor.value = null
  sectionSettingsError.value = ''
  try {
    const raw = form.drip_days.trim()
    const res = await api.put<{ data: ModuleItem }>(`/modules/${m.id}`, {
      enforce_sequential: form.enforce_sequential,
      drip_days: raw === '' ? null : Number(raw),
      is_published: form.is_published,
    })
    // Patch the row in place rather than reloading the whole screen: a full
    // loadAll() would collapse the lessons panel the admin is working in.
    const index = modules.value.findIndex((x) => x.id === m.id)
    if (index !== -1) modules.value[index] = res.data
    sectionSettingsSavedFor.value = m.id
  } catch (e) {
    sectionSettingsError.value = e instanceof ApiError ? e.message : 'บันทึกการตั้งค่าไม่สำเร็จ'
  } finally {
    savingSectionSettingsFor.value = null
  }
}

// ── Lesson (content item) CRUD — ADR-009, carries forward the exact
// ADR-007 video upload/embed create+edit-in-place logic that used to
// live directly on Module, now scoped to one Lesson under a Section. ──
const expandedModuleId = ref<number | null>(null)
function toggleModuleLessons(moduleId: number) {
  expandedModuleId.value = expandedModuleId.value === moduleId ? null : moduleId
}

/**
 * ADR-028 §2.1 — which content types may carry an uploaded file, and what
 * the file picker should accept for each.
 *
 * The accept lists mirror config/media.php's allow-lists exactly
 * (video.allowed_mimes, pdf.allowed_mimes, image.allowed_mimes). They are
 * a CONVENIENCE, never the check: `mimes:` on the server validates the
 * SNIFFED type, which is what makes a .exe renamed to .pdf a 422 rather
 * than a stored executable. If the two ever drift, the server wins.
 */
const UPLOADABLE_CONTENT_TYPES: LessonContentType[] = ['video', 'pdf', 'image']
const FILE_ACCEPT: Record<string, string> = {
  video: 'video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm,.m4v',
  pdf: 'application/pdf,.pdf',
  image: 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp',
}
const FILE_HINT: Record<string, string> = {
  video: 'รองรับ .mp4 .mov .webm .m4v',
  pdf: 'รองรับ .pdf เท่านั้น (สูงสุด 20 MB)',
  image: 'รองรับ .jpg .png .webp (สูงสุด 20 MB)',
}
function isUploadableType(type: LessonContentType): boolean {
  return UPLOADABLE_CONTENT_TYPES.includes(type)
}
function acceptFor(type: LessonContentType): string {
  return FILE_ACCEPT[type] ?? ''
}
function hintFor(type: LessonContentType): string {
  return FILE_HINT[type] ?? ''
}

// ── Embed-link authoring help (2026-08-09) ──────────────────────────
// An admin pasted `youtube.com/watch?v=…` and both the learner view and
// this app's preview rendered a dead grey box: that URL sets
// X-Frame-Options and refuses to be framed. The learner view now rewrites
// it (@/utils/embedUrl, shared with AcademyView's copy), so the paste
// works — but the admin still deserves to SEE what the app will use, and
// to be told when we cannot help.
//
// GUIDANCE, NEVER A GATE. There is no reliable way to know from JS whether
// an arbitrary host will allow framing, so "unknown" means unknown, not
// "wrong" — a company's internal video portal may embed perfectly. Blocking
// on a guess would stop an admin publishing a lesson that actually works.
// Only `video` is framed at all; an external pdf/image/link lesson opens in
// a new tab, where none of this applies.
function isFramedLesson(contentType: string, sourceType: string): boolean {
  return contentType === 'video' && sourceType === 'embed'
}

/** The URL the learner's iframe will actually load. '' when nothing to show. */
function embedUrlInUse(url: string): string {
  return toEmbedUrl(url.trim())
}

/** True once we have rewritten the pasted URL into a different, framable one. */
function embedUrlWasRewritten(url: string): boolean {
  const value = url.trim()

  return value !== '' && embedUrlInUse(value) !== value
}

/** True for a URL we neither recognise nor can rewrite — warn, do not block. */
function embedUrlMayNotDisplay(url: string): boolean {
  const value = url.trim()

  return value !== '' && classifyEmbedUrl(value) === 'unknown'
}

function defaultLessonForm() {
  return {
    title: '',
    content_type: 'video' as LessonContentType,
    // ADR-028 §2.1 — pdf/image accept BOTH an external URL and an upload,
    // so the source picker is no longer video-only.
    source_type: 'embed' as 'upload' | 'embed',
    content_ref: '',
    is_published: true,
    // ADR-028 §2.2 — per-file admin choice. Default false, matching the
    // migration: a company opts a document OUT of being kept, it does not
    // have to opt every document in.
    is_downloadable: false,
  }
}
const showLessonForm = ref<Record<number, boolean>>({})
const lessonForm = ref<Record<number, ReturnType<typeof defaultLessonForm>>>({})
const lessonVideoFile = ref<Record<number, File | null>>({})
const submittingLessonFor = ref<number | null>(null)
const lessonError = ref<Record<number, string>>({})
// TASK-145 — visible byte progress for the chunked upload below.
const lessonUploadProgress = ref<Record<number, number>>({})
const lessonUploadAbort: Record<number, (() => void) | null> = {}
// A cancelled upload rejects with ApiError(0, null), whose message is the
// unhelpful "API error 0". Distinguish it from a genuine network failure
// so Cancel does not look like a crash.
const lessonUploadCancelled: Record<number, boolean> = {}

function toggleLessonForm(moduleId: number) {
  if (!lessonForm.value[moduleId]) lessonForm.value[moduleId] = defaultLessonForm()
  showLessonForm.value[moduleId] = !showLessonForm.value[moduleId]
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-188 Phase C — MAKING "+ เพิ่มบทเรียน" FINDABLE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Human, 2026-08-13: "ผมหาปุ่มไม่เจอในการเพิ่มบทเรียน".
 *
 * There was exactly one add-lesson button and it was behind TWO conditions: it
 * is inside the Section card, which in the two-pane layout only exists once a
 * Section has been selected, and it was then hidden AGAIN while a lesson was
 * selected (`v-if="!isWideLayout || !selectedLesson"`). The human was looking
 * at a lesson, so it was not on screen — and on FIRST render, with nothing
 * selected at all, no Section card renders either, so it was not in the DOM.
 *
 * The second condition is gone. §5.C3 rules out the alternative fix (a
 * paragraph explaining where the button is), which is why this is a button.
 *
 * ── AMENDED 2026-08-16 (human, after seeing it on screen) ──
 *
 * The button this drives no longer sits at the top of the tab next to
 * "+ เพิ่ม Section". It sits on EACH SECTION ROW, right-aligned beside that
 * Section's lesson count — in the outline panel at `lg:` and above, and on the
 * Section card's header row below it, because the outline does not exist there.
 *
 * That is why `moduleId` is now REQUIRED. The old signature took it optionally
 * and fell back to "the selected Section, else the first one on the screen" —
 * a guess the top-level button had to make because it could not know which
 * Section the admin meant. A per-Section button always knows, and a fallback
 * that can silently add a lesson to the wrong Section is worth deleting rather
 * than leaving in place unused.
 *
 * ONE action, not two: this both selects the Section and opens its create form,
 * so the admin never has to click a Section first and hunt for the form after.
 */
function startAddLesson(moduleId: number) {
  const m = modules.value.find((x) => x.id === moduleId)
  if (!m) return

  if (isWideLayout.value) {
    // Selecting it is what puts the Section's card — and therefore the form —
    // in the right pane at all; it also clears any selected lesson, so the form
    // is not competing with a lesson inspector.
    selectSection(m)
  } else {
    // Below `lg:` the card is already on screen and only needs expanding.
    // Deliberately NOT selectSection() here: that also opens the Section's
    // release-settings panel (`toggleSectionSettings`), which in this layout
    // sits ABOVE the lesson list and would push the form the admin just asked
    // for below a panel they did not ask for. The selection is still recorded
    // so that widening the window lands on this same Section.
    selectedSectionId.value = moduleId
    selectedLessonId.value = null
    editingLessonId.value = null
  }
  expandedModuleId.value = moduleId

  if (!lessonForm.value[moduleId]) lessonForm.value[moduleId] = defaultLessonForm()
  showLessonForm.value[moduleId] = true
}
function onLessonFileChange(moduleId: number, event: Event) {
  lessonVideoFile.value[moduleId] = (event.target as HTMLInputElement).files?.[0] ?? null
}
function cancelLessonUpload(moduleId: number) {
  lessonUploadCancelled[moduleId] = true
  lessonUploadAbort[moduleId]?.()
}

/**
 * TASK-145 / ADR-028 §2.6 — uploads go through the CHUNKED transport that
 * TASK-094 already built (api.postFileWithProgress → uploadInChunks).
 *
 * This used to be a single `api.postForm`, which PHP rejects outright once
 * Content-Length exceeds `post_max_size` — a 413 that never reaches
 * Laravel, so no validation message and no way for an admin to know why.
 * The routes have carried `resolve.chunked-upload` since TASK-094
 * (routes/api.php:528,531); only the client was still sending one request.
 * No new transport is introduced here — the ceiling simply stops
 * depending on a php.ini an admin cannot edit on shared hosting.
 */
async function submitLesson(moduleId: number) {
  const form = lessonForm.value[moduleId]
  // Pre-existing TS18048 fix (unrelated to TASK-034): lessonForm is a
  // Record<number, ...>, so indexed access is always `possibly
  // undefined` to the type checker even though toggleLessonForm()
  // guarantees it's set before this handler can fire from the UI.
  if (!form) return
  submittingLessonFor.value = moduleId
  lessonError.value[moduleId] = ''
  lessonUploadProgress.value[moduleId] = 0
  lessonUploadCancelled[moduleId] = false
  try {
    const isFileUpload = isUploadableType(form.content_type) && form.source_type === 'upload'

    if (isFileUpload) {
      const file = lessonVideoFile.value[moduleId]
      if (!file) {
        lessonError.value[moduleId] = 'กรุณาเลือกไฟล์'
        return
      }

      const upload = api.postFileWithProgress(
        `/modules/${moduleId}/lessons`,
        file,
        {
          title: form.title,
          content_type: form.content_type,
          source_type: 'upload',
          is_published: form.is_published ? '1' : '0',
          // Prohibited by the API for anything that is not an upload, so
          // it is sent on this branch only.
          is_downloadable: form.is_downloadable ? '1' : '0',
        },
        (fraction) => {
          lessonUploadProgress.value[moduleId] = fraction
        },
      )
      lessonUploadAbort[moduleId] = upload.abort
      await upload.promise
    } else {
      await api.post(`/modules/${moduleId}/lessons`, {
        title: form.title,
        content_type: form.content_type,
        // A video that's an iframe/external embed still uses
        // content_ref (ADR-007), but must also send source_type=embed.
        // An EXTERNAL pdf/image omits source_type entirely — the API
        // prohibits it for a non-upload of those types.
        // A quiz lesson has no content_ref — its content is authored
        // separately below (quiz-questions endpoints).
        ...(form.content_type === 'video' ? { source_type: 'embed' } : {}),
        ...(form.content_type !== 'quiz' ? { content_ref: form.content_ref } : {}),
        is_published: form.is_published,
      })
    }

    lessonForm.value[moduleId] = defaultLessonForm()
    lessonVideoFile.value[moduleId] = null
    showLessonForm.value[moduleId] = false
    expandedModuleId.value = moduleId
    await loadAll()
  } catch (e) {
    // ApiError.extractMessage() already surfaces Laravel's real validation
    // message (and a readable 413), so show it rather than a status code.
    // A user-initiated cancel is not a failure and must not read as one.
    lessonError.value[moduleId] = lessonUploadCancelled[moduleId]
      ? 'ยกเลิกการอัปโหลดแล้ว'
      : e instanceof ApiError
        ? e.message
        : 'บันทึกไม่สำเร็จ'
  } finally {
    submittingLessonFor.value = null
    lessonUploadAbort[moduleId] = null
    lessonUploadProgress.value[moduleId] = 0
  }
}

const editingLessonId = ref<number | null>(null)
// ADR-031 §2.4 — `is_optional` lives here, on the lesson itself, rather than
// behind the Section gear: unlike sequential/drip it is a property of THIS
// piece of content ("supplementary reading"), and it is decided while the
// lesson is being written.
const editLessonForm = ref({
  title: '',
  content_ref: '',
  sort_order: '0',
  xp_reward: '0',
  is_published: true,
  is_downloadable: false,
  is_optional: false,
  /*
   * TASK-188 Phase D (human decision D2, 2026-08-13). Until now the content
   * type was chosen once, in the create form, and could never be changed: the
   * only way out of a wrong choice was to delete the lesson, which takes every
   * learner's progress on it with it. `editingLessonContentType` below still
   * holds the ORIGINAL, so `isRetypingLesson` can tell a retype from a save.
   */
  content_type: 'video' as LessonContentType,
  /* Only read when the new type is uploadable — see isRetypeContentComplete. */
  source_type: 'embed' as 'upload' | 'embed',
})
const editLessonVideoFile = ref<File | null>(null)
const editLessonUploadProgress = ref(0)
let editLessonUploadAbort: (() => void) | null = null
let editLessonUploadCancelled = false
/** The lesson currently being edited stores a file of ours (ADR-028 §2.1). */
const editingLessonIsUpload = ref(false)
const editingLessonContentType = ref<LessonContentType>('video')

function onEditLessonFileChange(event: Event) {
  editLessonVideoFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
function cancelEditLessonUpload() {
  editLessonUploadCancelled = true
  editLessonUploadAbort?.()
}
/**
 * Fills `editLessonForm` from a lesson WITHOUT entering edit mode.
 *
 * Extracted from startEditLesson (which now calls it) for TASK-152b: the
 * desktop inspector edits `is_optional` / `is_published` through the same form
 * and the same saveEditLesson(), so the two surfaces cannot drift into sending
 * different payloads — but selecting a lesson in the outline must not swap the
 * inspector into the full edit form.
 */
function seedEditLessonForm(l: ModuleLessonItem) {
  editLessonVideoFile.value = null
  editLessonUploadProgress.value = 0
  editingLessonContentType.value = l.content_type
  editingLessonIsUpload.value = l.source_type === 'upload' && isUploadableType(l.content_type)
  editLessonForm.value = {
    title: l.title,
    content_ref: l.content_ref ?? '',
    sort_order: String(l.sort_order),
    xp_reward: String(l.xp_reward),
    is_published: l.is_published,
    is_downloadable: l.is_downloadable,
    is_optional: l.is_optional,
    // Seeded FROM the lesson, so opening the form and saving it again is never
    // a retype — the API treats "the type it already has" as a no-op and resets
    // nothing (TASK-188 Phase D, ag-dev).
    content_type: l.content_type,
    source_type: l.source_type ?? 'embed',
  }
}
function startEditLesson(l: ModuleLessonItem) {
  editingLessonId.value = l.id
  seedEditLessonForm(l)
}
function cancelEditLesson() {
  editingLessonId.value = null
  editLessonVideoFile.value = null
}
/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-188 Phase D — CHANGING A LESSON'S CONTENT TYPE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Human decision D2 (2026-08-13). Before this, `content_type` was chosen once
 * in the create form and was unreachable afterwards: `editLessonForm` did not
 * even carry the field. The only way out of a wrong choice was to delete the
 * lesson and rebuild it, which takes every learner's progress on it with it —
 * so the "safe" absence of the control was the destructive option.
 *
 * THE API REQUIRES THE NEW TYPE'S CONTENT IN THE SAME REQUEST (ag-dev): an
 * upload type needs a new `file`, an external type needs a new `content_ref`,
 * and `quiz` takes neither. Sending the type the lesson already has is not a
 * retype and resets nothing, which is why `editingLessonContentType` keeps the
 * ORIGINAL and every branch below compares against it rather than against a
 * "dirty" flag.
 */
const isRetypingLesson = computed(
  () => editLessonForm.value.content_type !== editingLessonContentType.value,
)
/** After the retype, will this lesson be storing a file of ours? */
const retypeWillBeUpload = computed(
  () =>
    isUploadableType(editLessonForm.value.content_type) &&
    editLessonForm.value.source_type === 'upload',
)
const retypeNeedsFile = computed(() => isRetypingLesson.value && retypeWillBeUpload.value)
const retypeNeedsUrl = computed(
  () =>
    isRetypingLesson.value &&
    editLessonForm.value.content_type !== 'quiz' &&
    !retypeWillBeUpload.value,
)
/**
 * Checked BEFORE the impact request, so an admin is never shown a confirmation
 * for a change the API would then 422. A `quiz` lesson satisfies this with
 * nothing at all — that is the one type with no content spec.
 */
const isRetypeContentComplete = computed(() => {
  if (!isRetypingLesson.value) return true
  if (retypeNeedsFile.value) return editLessonVideoFile.value !== null
  if (retypeNeedsUrl.value) return editLessonForm.value.content_ref.trim() !== ''

  return true
})

const pendingRetype = ref<{
  lessonId: number
  from: LessonContentType
  to: LessonContentType
  impact: ContentTypeChangeImpact
} | null>(null)
const loadingRetypeImpact = ref(false)
const retypeError = ref('')
const retypeSaving = ref(false)

/**
 * Every number in this body is READ from the endpoint — none of it is inferred
 * here and none of it is softened (TASK-188 §6.D2: ag-dev measured what the
 * retype actually does, and "progress may be affected" teaches an admin
 * nothing they can act on).
 */
const retypeConfirmBody = computed(() =>
  pendingRetype.value
    ? contentTypeChangeConfirmBody(
        pendingRetype.value.impact,
        contentTypeLabel(pendingRetype.value.from),
        contentTypeLabel(pendingRetype.value.to),
      )
    : '',
)

/**
 * The save button on the lesson edit form.
 *
 * A plain save goes straight through. A RETYPE reads the impact first and then
 * stops: nothing is written until the dialog is accepted, because the write is
 * what deletes the stored file and clears the progress rows.
 */
async function requestSaveEditLesson(lessonId: number) {
  retypeError.value = ''
  if (!isRetypingLesson.value) {
    await saveEditLesson(lessonId)
    return
  }
  if (!isRetypeContentComplete.value) {
    retypeError.value = retypeNeedsFile.value
      ? 'เลือกไฟล์ของประเภทใหม่ก่อน จึงจะเปลี่ยนประเภทเนื้อหาได้'
      : 'ใส่ลิงก์เนื้อหาของประเภทใหม่ก่อน จึงจะเปลี่ยนประเภทเนื้อหาได้'
    return
  }

  loadingRetypeImpact.value = true
  try {
    const res = await api.get<{ data: ContentTypeChangeImpact }>(
      `/module-lessons/${lessonId}/content-type-change-impact`,
    )
    pendingRetype.value = {
      lessonId,
      from: editingLessonContentType.value,
      to: editLessonForm.value.content_type,
      impact: res.data,
    }
  } catch (e) {
    // Refuse rather than guess: a confirmation that invents the numbers is
    // worse than no confirmation, because the admin would believe it.
    retypeError.value =
      e instanceof ApiError
        ? `ยังตรวจสอบผลกระทบของการเปลี่ยนประเภทไม่ได้ จึงยังไม่เปลี่ยนให้ — ${e.message}`
        : 'ยังตรวจสอบผลกระทบของการเปลี่ยนประเภทไม่ได้ จึงยังไม่เปลี่ยนให้'
  } finally {
    loadingRetypeImpact.value = false
  }
}

async function confirmRetypeLesson() {
  const pending = pendingRetype.value
  if (!pending) return
  retypeSaving.value = true
  try {
    await saveEditLesson(pending.lessonId)
  } finally {
    retypeSaving.value = false
    pendingRetype.value = null
  }
}

async function saveEditLesson(lessonId: number) {
  editLessonUploadProgress.value = 0
  editLessonUploadCancelled = false
  // A retype settles what "is this an upload" means for THIS request; without
  // a retype it is still the lesson's own stored state.
  const sendsFile = isRetypingLesson.value ? retypeWillBeUpload.value : editingLessonIsUpload.value
  const retypeFields = isRetypingLesson.value
    ? {
        content_type: editLessonForm.value.content_type,
        // Mirrors the create path exactly: an embedded VIDEO must name
        // source_type, an external pdf/image must not, and a quiz has neither.
        ...(retypeWillBeUpload.value
          ? { source_type: 'upload' as const }
          : editLessonForm.value.content_type === 'video'
            ? { source_type: 'embed' as const }
            : {}),
      }
    : {}
  try {
    if (editLessonVideoFile.value) {
      // Same chunked transport as create. `_method=PUT` rides along as a
      // normal form field, which is how Laravel method-spoofing works and
      // is preserved unchanged through the chunked path.
      const fields: Record<string, string> = {
        _method: 'PUT',
        title: editLessonForm.value.title,
        sort_order: editLessonForm.value.sort_order,
        xp_reward: editLessonForm.value.xp_reward,
        is_published: editLessonForm.value.is_published ? '1' : '0',
        // ADR-031 §2.4 — travels with the multipart replace too, or saving a
        // new file would silently revert the flag to the form's default.
        is_optional: editLessonForm.value.is_optional ? '1' : '0',
        ...Object.fromEntries(Object.entries(retypeFields).map(([k, v]) => [k, String(v)])),
      }
      if (sendsFile) fields.is_downloadable = editLessonForm.value.is_downloadable ? '1' : '0'

      const upload = api.postFileWithProgress(
        `/module-lessons/${lessonId}`,
        editLessonVideoFile.value,
        fields,
        (fraction) => {
          editLessonUploadProgress.value = fraction
        },
      )
      editLessonUploadAbort = upload.abort
      await upload.promise
    } else {
      await api.put(`/module-lessons/${lessonId}`, {
        title: editLessonForm.value.title,
        ...retypeFields,
        // Prohibited by the API for an uploaded lesson (§5 rule 6 — the
        // server owns the path), so it is only sent for the URL case.
        ...(sendsFile ? {} : { content_ref: editLessonForm.value.content_ref || undefined }),
        ...(sendsFile ? { is_downloadable: editLessonForm.value.is_downloadable } : {}),
        sort_order: Number(editLessonForm.value.sort_order),
        xp_reward: Number(editLessonForm.value.xp_reward),
        is_published: editLessonForm.value.is_published,
        is_optional: editLessonForm.value.is_optional,
      })
    }
    // The lesson IS the new type now. Without this the inspector (which keeps
    // its form open across a save) would offer to retype it a second time.
    editingLessonContentType.value = editLessonForm.value.content_type
    editingLessonIsUpload.value = sendsFile
    editingLessonId.value = null
    editLessonVideoFile.value = null
    await loadAll()
  } catch (e) {
    errorMessage.value = editLessonUploadCancelled
      ? 'ยกเลิกการอัปโหลดแล้ว'
      : e instanceof ApiError
        ? e.message
        : 'บันทึกไม่สำเร็จ'
  } finally {
    editLessonUploadAbort = null
    editLessonUploadProgress.value = 0
  }
}
async function deleteLesson(lessonId: number) {
  try {
    await api.delete(`/module-lessons/${lessonId}`)
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
  }
}

// ── Recorded learner progress per lesson (ADR-028 §4) ───────────────
/**
 * GET /module-lessons/{lesson}/progress — Company Admin / Super Admin
 * only (ModulePolicy::update, deliberately NOT ::view: an Agent can view
 * a module in order to learn from it and must not be able to read this).
 *
 * This is the support tool ADR-028 §4 asked for by name. The learner is
 * told nothing about their own numbers, so without a readout here every
 * blocked learner is an unresolvable ticket. Paired with the override
 * below, which ADR-028 §5 says must be discoverable BEFORE rollout.
 */
const expandedProgressLessonId = ref<number | null>(null)
const lessonProgressRows = ref<Record<number, LessonProgressRow[]>>({})
const loadingLessonProgressFor = ref<number | null>(null)
const lessonProgressError = ref('')

async function toggleLessonProgress(lesson: ModuleLessonItem) {
  if (expandedProgressLessonId.value === lesson.id) {
    expandedProgressLessonId.value = null
    return
  }
  expandedProgressLessonId.value = lesson.id
  await loadLessonProgress(lesson.id)
}

async function loadLessonProgress(lessonId: number) {
  loadingLessonProgressFor.value = lessonId
  lessonProgressError.value = ''
  try {
    const res = await api.get<{ data: LessonProgressRow[] }>(`/module-lessons/${lessonId}/progress`)
    lessonProgressRows.value[lessonId] = res.data
  } catch (e) {
    lessonProgressError.value = e instanceof ApiError ? e.message : 'โหลดความคืบหน้าไม่สำเร็จ'
  } finally {
    loadingLessonProgressFor.value = null
  }
}

function progressRowName(row: LessonProgressRow): string {
  const user = row.user
  return user ? `${user.first_name} ${user.last_name}`.trim() : `ผู้ใช้ #${row.user_id}`
}

function formatSeconds(value: number | null): string {
  if (value === null) return '—'
  const m = Math.floor(value / 60)
  const s = value % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' })
}

/**
 * ADR-028 §2.3 guard 2 — mark a lesson complete FOR an agent who could
 * not satisfy the verified-progress gate. Files fail to render, devices
 * break, a learner reads a printout; a rule with no override becomes a
 * support queue. Audit-logged server-side (CLAUDE.md §6 — it feeds the
 * BR-1 cert gate) and idempotent, so a double click is a safe no-op.
 */
const pendingCompletionOverride = ref<{ lesson: ModuleLessonItem; row: LessonProgressRow } | null>(null)
const overridingCompletion = ref(false)

function requestCompletionOverride(lesson: ModuleLessonItem, row: LessonProgressRow) {
  pendingCompletionOverride.value = { lesson, row }
}

async function confirmCompletionOverride() {
  const pending = pendingCompletionOverride.value
  if (!pending) return
  overridingCompletion.value = true
  try {
    await api.post(`/module-lessons/${pending.lesson.id}/completions/override`, { user_id: pending.row.user_id })
    // An override writes a completion, so the ความคืบหน้าตัวแทน fractions move —
    // refresh them, but only if that tab has ever been opened (the endpoint is
    // several GROUP BY passes and is throttled server-side).
    if (progressLoadedOnce.value) await loadProgressSummary()
  } catch (e) {
    lessonProgressError.value = e instanceof ApiError ? e.message : 'ทำเครื่องหมายว่าเรียนจบไม่สำเร็จ'
  } finally {
    overridingCompletion.value = false
    pendingCompletionOverride.value = null
  }
}

// ── Lesson preview — "ตัวอย่างที่ตัวแทนจะเห็น" ────────────────────────
/**
 * Human request (2026-08-09): "บทเรียน หลังจากเพิ่มแล้วต้องมีการ Preview
 * ให้ admin ได้เห็นว่าจะไปโชว์แบบไหนให้กับ Agent เห็น".
 *
 * The row used to show metadata only (`video (ลิงก์ภายนอก) · ลำดับ 0 · 0 XP`),
 * so an admin published without ever seeing the learner's surface. The
 * rendering itself lives in LessonPreviewModal.vue — see its header for
 * the keep-in-sync obligation against frontend/src/views/AcademyView.vue,
 * and for why the preview cannot write progress or a completion.
 *
 * Amended 2026-08-09 (human): the preview is no longer modal-only. Every
 * lesson row now carries a 120px `LessonPreviewStrip`, and THAT strip is
 * what calls this function — the modal became the enlarged view rather
 * than the only view. The old "ดูตัวอย่าง" button was removed with it, so
 * there is exactly one control per row that opens this.
 *
 * NOTE this view already opens NO progress-writing endpoint: there is no
 * `useLessonProgress` in this app and `PUT /module-lessons/{id}/progress`
 * is called from nowhere in frontend-admin. Keep it that way.
 */
interface AcademyCompletionSettings {
  video_watch_percent: number
  pdf_read_percent: number
  // ADR-029 §2.4 — the COMPANY-level pass mark, the fallback end of
  // `module_lessons.quiz_pass_percent ?? this`. Admin-only, like its two
  // siblings: it is a threshold, and ADR-028 §4 settled that thresholds
  // are not shown to learners.
  quiz_pass_percent: number
}
const previewLesson = ref<ModuleLessonItem | null>(null)
const completionSettings = ref<AcademyCompletionSettings | null>(null)
const completionSettingsLoading = ref(false)
const completionSettingsError = ref('')
// Declared beside the value it mirrors (and above loadCompletionSettings,
// which reseeds it) rather than next to its own form handlers below.
const completionSettingsForm = ref({ video_watch_percent: '', pdf_read_percent: '', quiz_pass_percent: '' })

function openLessonPreview(lesson: ModuleLessonItem) {
  previewLesson.value = lesson
  void loadCompletionSettings()
}
function closeLessonPreview() {
  previewLesson.value = null
}

/**
 * ADR-028 §4 / BR-7 — the thresholds are per-company config, never
 * constants, so they are READ here and never hardcoded in the modal.
 *
 * Super Admin caveat, stated rather than papered over: this screen lists
 * modules across companies but ModuleResource carries no company_id, so
 * we cannot resolve which company a given lesson belongs to from here.
 * Rather than show another company's numbers as if they were this
 * lesson's, we ask for the company picker to be used. Flagged to ag-lead:
 * exposing company_id on ModuleResource would remove the caveat.
 */
async function loadCompletionSettings() {
  completionSettingsError.value = ''
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    completionSettings.value = null
    completionSettingsError.value = 'Super Admin: เลือกบริษัทด้านบนก่อน จึงจะแสดงเกณฑ์การเรียนจบของบริษัทนั้นได้'
    return
  }
  completionSettingsLoading.value = true
  try {
    const query = isSuperAdmin.value && selectedCompanyId.value ? `?company_id=${selectedCompanyId.value}` : ''
    const res = await api.get<{ data: AcademyCompletionSettings }>(`/academy-completion-settings${query}`)
    completionSettings.value = res.data
    // Keep the edit form in step with what the server actually holds, so it
    // is correct whether the admin opens it before or after this resolves.
    completionSettingsForm.value = {
      video_watch_percent: String(res.data.video_watch_percent),
      pdf_read_percent: String(res.data.pdf_read_percent),
      quiz_pass_percent: String(res.data.quiz_pass_percent),
    }
  } catch (e) {
    completionSettings.value = null
    completionSettingsError.value = e instanceof ApiError ? e.message : 'โหลดเกณฑ์การเรียนจบไม่สำเร็จ'
  } finally {
    completionSettingsLoading.value = false
  }
}

// The inherited pass mark has to be READABLE the moment a lesson's quiz
// panel opens (it is what an empty per-lesson override means), so the
// company settings are fetched on mount rather than only when the preview
// modal is raised. A second onMounted is deliberate: this read has its own
// Super-Admin caveat and must not be able to fail loadAll().
onMounted(loadCompletionSettings)

/**
 * ADR-029 §2.4 / ADR-028 §4 — the COMPANY-level thresholds form.
 *
 * PUT /academy-completion-settings takes all three together
 * (`video_watch_percent` and `pdf_read_percent` are `required`), so this
 * form owns all three rather than being a quiz-only field bolted next to a
 * read-only display of the other two.
 *
 * BR-7: every number here is admin-editable config. Nothing is defaulted
 * in this file — the form is seeded from what the server returns, and the
 * platform default (80) lives in config/academy.php.
 */
const showCompletionSettingsForm = ref(false)
const savingCompletionSettings = ref(false)
const completionSettingsSaved = ref(false)

function toggleCompletionSettingsForm() {
  showCompletionSettingsForm.value = !showCompletionSettingsForm.value
  completionSettingsSaved.value = false
  const current = completionSettings.value
  if (showCompletionSettingsForm.value && current) {
    completionSettingsForm.value = {
      video_watch_percent: String(current.video_watch_percent),
      pdf_read_percent: String(current.pdf_read_percent),
      quiz_pass_percent: String(current.quiz_pass_percent),
    }
  }
}

async function saveCompletionSettings() {
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    completionSettingsError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  savingCompletionSettings.value = true
  completionSettingsError.value = ''
  completionSettingsSaved.value = false
  try {
    const res = await api.put<{ data: AcademyCompletionSettings }>('/academy-completion-settings', {
      video_watch_percent: Number(completionSettingsForm.value.video_watch_percent),
      pdf_read_percent: Number(completionSettingsForm.value.pdf_read_percent),
      quiz_pass_percent: Number(completionSettingsForm.value.quiz_pass_percent),
      ...(isSuperAdmin.value ? { company_id: selectedCompanyId.value } : {}),
    })
    completionSettings.value = res.data
    completionSettingsSaved.value = true
  } catch (e) {
    completionSettingsError.value = e instanceof ApiError ? e.message : 'บันทึกเกณฑ์ไม่สำเร็จ'
  } finally {
    savingCompletionSettings.value = false
  }
}

// ── Lesson quiz authoring (ADR-009 + ADR-029) ───────────────────────
/**
 * ADR-029 §2.1 — the authoring panel is NO LONGER tied to
 * `content_type === 'quiz'`. A video or PDF lesson can carry an
 * end-of-lesson quiz, which is what the feature was always named after;
 * the old `v-if="l.content_type === 'quiz'"` on the button and the panel
 * was the only thing preventing it. A standalone quiz lesson still works
 * exactly as before — nothing was removed.
 *
 * GET /modules already eager-loads lessons WITH quiz_questions (is_correct
 * unmasked for Company/Super Admin), so no separate lazy-load step is
 * needed here, unlike the Exam pattern above — every mutation just reloads
 * via loadAll().
 */
const expandedQuizLessonId = ref<number | null>(null)
const quizError = ref('')

/**
 * ADR-029 §2.4/§2.6 — the two per-lesson quiz settings, saved through the
 * ordinary PUT /module-lessons/{lesson}.
 *
 * `quiz_pass_percent` is sent as `null` when the field is blank, because
 * null is what "inherit the company value" IS in the resolution chain
 * (`module_lessons.quiz_pass_percent ?? academy_completion_settings...`).
 * Sending 0 or omitting it would mean something else entirely — the API
 * rejects 0 outright (min:1), for the reason stated in
 * UpdateAcademyCompletionSettingRequest: a 0% pass mark silently disables
 * the gate for everyone.
 */
const quizSettingsForm = ref<Record<number, { quiz_pass_percent: string; quiz_blocks_completion: boolean }>>({})
const savingQuizSettingsFor = ref<number | null>(null)
const quizSettingsSavedFor = ref<number | null>(null)

function ensureQuizSettingsForm(lesson: ModuleLessonItem) {
  if (quizSettingsForm.value[lesson.id]) return
  quizSettingsForm.value[lesson.id] = {
    quiz_pass_percent: lesson.quiz_pass_percent === null ? '' : String(lesson.quiz_pass_percent),
    quiz_blocks_completion: lesson.quiz_blocks_completion,
  }
}

async function saveQuizSettings(lessonId: number) {
  const form = quizSettingsForm.value[lessonId]
  if (!form) return
  savingQuizSettingsFor.value = lessonId
  quizSettingsSavedFor.value = null
  quizError.value = ''
  try {
    const raw = form.quiz_pass_percent.trim()
    await api.put(`/module-lessons/${lessonId}`, {
      quiz_pass_percent: raw === '' ? null : Number(raw),
      quiz_blocks_completion: form.quiz_blocks_completion,
    })
    await loadAll()
    expandedQuizLessonId.value = lessonId
    quizSettingsSavedFor.value = lessonId
  } catch (e) {
    quizError.value = e instanceof ApiError ? e.message : 'บันทึกการตั้งค่าแบบทดสอบไม่สำเร็จ'
  } finally {
    savingQuizSettingsFor.value = null
  }
}

/**
 * ADR-029 §2.5 — "Every attempt is still recorded, so the admin can see
 * someone who took eleven tries." GET
 * /module-lessons/{lesson}/quiz-attempts, authorized on ModulePolicy::
 * update (Admin), never ::view.
 *
 * SCORE ONLY, and that is a deliberate product decision rather than a gap
 * to fill in later: §4 item 2 (may an admin see the learner's chosen
 * answers?) is unresolved and PDPA-adjacent, and the answers are not
 * stored at all. So nothing below hints that a per-answer drill-down
 * exists — a disabled "ดูคำตอบ" button would be a promise the schema
 * cannot keep.
 */
const expandedAttemptsLessonId = ref<number | null>(null)
const quizAttemptRows = ref<Record<number, ModuleLessonQuizAttemptRow[]>>({})
const loadingQuizAttemptsFor = ref<number | null>(null)
const quizAttemptsError = ref('')

async function toggleQuizAttempts(lesson: ModuleLessonItem) {
  if (expandedAttemptsLessonId.value === lesson.id) {
    expandedAttemptsLessonId.value = null
    return
  }
  expandedAttemptsLessonId.value = lesson.id
  loadingQuizAttemptsFor.value = lesson.id
  quizAttemptsError.value = ''
  try {
    const res = await api.get<{ data: ModuleLessonQuizAttemptRow[] }>(`/module-lessons/${lesson.id}/quiz-attempts`)
    quizAttemptRows.value[lesson.id] = res.data
  } catch (e) {
    quizAttemptsError.value = e instanceof ApiError ? e.message : 'โหลดผลการทำแบบทดสอบไม่สำเร็จ'
  } finally {
    loadingQuizAttemptsFor.value = null
  }
}

function attemptRowName(row: ModuleLessonQuizAttemptRow): string {
  const user = row.user
  return user ? `${user.first_name} ${user.last_name}`.trim() : `ผู้ใช้ #${row.user_id}`
}

/** ADR-029 §2.4 — the mark that actually applies to this lesson, most-specific-wins. */
function effectivePassPercent(lesson: ModuleLessonItem): number | null {
  return lesson.quiz_pass_percent ?? completionSettings.value?.quiz_pass_percent ?? null
}

function toggleLessonQuiz(lesson: ModuleLessonItem) {
  if (expandedQuizLessonId.value === lesson.id) {
    if (!hideIncompleteWarning.value) {
      const incomplete = (lesson.quiz_questions ?? []).filter(questionHasNoCorrectAnswer)
      if (incomplete.length) {
        incompleteWarningQuestions.value = incomplete
        showIncompleteWarningModal.value = true
      }
    }
    expandedQuizLessonId.value = null
    return
  }
  // ADR-029 §2.4/§2.6 — the per-lesson settings live in the same panel, so
  // seed their form from the lesson before it is rendered.
  ensureQuizSettingsForm(lesson)
  expandedQuizLessonId.value = lesson.id
}

/**
 * Passed to QuizQuestionEditor as its `reload`. GET /modules eager-loads
 * every lesson's questions, so a full reload is what already happened after
 * each of the individual handlers this replaced — kept identical rather than
 * optimised, so the reader of the list and the writer stay the same code
 * path. Re-opens the panel because loadAll() re-creates the lesson objects.
 */
async function reloadOpenLessonQuiz(): Promise<void> {
  const lessonId = expandedQuizLessonId.value
  await loadAll()
  expandedQuizLessonId.value = lessonId
}

// ── ADR-030 §2.3/§2.5 — attach / detach a LIBRARY quiz ──────────────
/**
 * ADR-030 §3 — "keeping 'create a new quiz right here' as the default path;
 * the library is an option, not a required detour."
 *
 * So none of this lengthens today's flow: typing a question into the box at
 * the bottom of the panel still POSTs to
 * /module-lessons/{lesson}/quiz-questions and the server creates+attaches a
 * quiz on first use. The picker below is a second, opt-in door.
 */
interface AvailableQuizItem {
  id: number
  title: string
  question_count: number
  is_attached: boolean
  module_lesson: { id: number; title: string; module_id: number } | null
}
/**
 * ADR-030 §2.5 — "the lesson's quiz selector lists unattached quizzes in the
 * same company, plus the one currently attached. A quiz already taken by
 * another lesson must not appear as a choice that then fails."
 *
 * THIS IS WHY THE LIST COMES FROM ITS OWN ENDPOINT AND IS NEVER FILTERED
 * HERE. GET /module-lessons/{lesson}/available-quizzes runs the exact
 * predicate the attach will re-check (QuizService::availableFor), including
 * the parts a client could not know: soft-deleted lessons still hold their
 * quiz_id as far as the UNIQUE index is concerned, and a Super Admin's own
 * tenant is not the lesson's tenant. Filtering GET /quizzes client-side
 * would reproduce a rule that the server owns, and would be wrong on both
 * of those. It is also re-fetched every time the picker opens, so a quiz
 * claimed by a colleague since the page loaded is simply not offered.
 */
const availableQuizzes = ref<Record<number, AvailableQuizItem[]>>({})
const showQuizPickerFor = ref<number | null>(null)
const loadingAvailableFor = ref<number | null>(null)
const availableQuizzesError = ref('')
const attachingQuizId = ref<number | null>(null)

async function toggleQuizPicker(lesson: ModuleLessonItem) {
  if (showQuizPickerFor.value === lesson.id) {
    showQuizPickerFor.value = null
    return
  }
  showQuizPickerFor.value = lesson.id
  loadingAvailableFor.value = lesson.id
  availableQuizzesError.value = ''
  try {
    const res = await api.get<{ data: AvailableQuizItem[] }>(`/module-lessons/${lesson.id}/available-quizzes`)
    availableQuizzes.value[lesson.id] = res.data
  } catch (e) {
    availableQuizzesError.value = e instanceof ApiError ? e.message : 'โหลดรายการแบบทดสอบในคลังไม่สำเร็จ'
  } finally {
    loadingAvailableFor.value = null
  }
}

async function attachQuiz(lesson: ModuleLessonItem, quizId: number) {
  attachingQuizId.value = quizId
  quizError.value = ''
  try {
    await api.put(`/module-lessons/${lesson.id}/quiz`, { quiz_id: quizId })
    showQuizPickerFor.value = null
    await reloadOpenLessonQuiz()
  } catch (e) {
    availableQuizzesError.value = e instanceof ApiError ? e.message : 'เชื่อมโยงแบบทดสอบไม่สำเร็จ'
  } finally {
    attachingQuizId.value = null
  }
}

/**
 * ADR-030 §2.3 — unlinking returns the quiz to the library with its
 * questions intact, and recorded attempts are NOT touched (an attempt is a
 * record of a learner doing a LESSON).
 *
 * What DOES change is worth a plain sentence rather than a shrug: the lesson
 * has no questions afterwards, so LessonCompletionGate::isQuizSatisfied()
 * short-circuits to true — a lesson with `quiz_blocks_completion` ON becomes
 * completable again immediately. That is a gate on the BR-1 certification
 * path switching off, which is exactly what ADR-030 §4 item 2 ("whether
 * unlinking should warn") recommended warning about. The warning below is
 * that; it is UI copy, and it invents no rule.
 */
const pendingDetachLesson = ref<ModuleLessonItem | null>(null)
const detaching = ref(false)

/**
 * Human, 2026-08-09: "บันทึกการตั้งค่า ถ้าบันทึกครั้งแรกแล้วไม่ต้องแสดงทุกครั้ง
 * เนื่องจากใช้ไม่บ่อย ปรับเป็นรูปเฟือง พร้อมคำบรรยาย".
 *
 * Only ONE lesson's settings form is open at a time (same shape as
 * expandedQuizLessonId / expandedAttemptsLessonId above). The collapsed
 * summary still prints both values, so this hides the FORM, not the
 * configuration — an admin scanning the list can still see that a lesson
 * blocks completion at 80% without opening anything, which matters because
 * `quiz_blocks_completion` sits on the BR-1 certification path.
 */
const expandedQuizSettingsLessonId = ref<number | null>(null)

const detachWarningBody = computed(() => {
  const lesson = pendingDetachLesson.value
  if (!lesson) return ''
  const base =
    'ชุดคำถามจะกลับเข้าคลังโดยไม่สูญหาย และผลการทำแบบทดสอบที่บันทึกไว้แล้วจะยังอยู่ครบ แต่บทเรียนนี้จะไม่มีคำถามเหลืออยู่'
  return lesson.quiz_blocks_completion
    // §4.B2 — the "(BR-1)" citation that used to close this sentence is a
    // reference to OUR rulebook and told the admin nothing; the consequence it
    // pointed at ("มีผลต่อเส้นทางใบรับรอง") is what stays.
    ? `${base} — และเนื่องจากบทเรียนนี้ตั้งไว้ว่า “ต้องทำแบบทดสอบให้ผ่านจึงจะเรียนจบได้” การยกเลิกการเชื่อมโยงจะทำให้ผู้เรียนกดเรียนจบได้ทันทีโดยไม่ต้องทำแบบทดสอบ ซึ่งมีผลต่อเส้นทางใบรับรอง ยืนยันหรือไม่?`
    : `${base} ยืนยันหรือไม่?`
})

function requestDetachQuiz(lesson: ModuleLessonItem) {
  pendingDetachLesson.value = lesson
}

async function confirmDetachQuiz() {
  const lesson = pendingDetachLesson.value
  if (!lesson) return
  detaching.value = true
  quizError.value = ''
  try {
    await api.delete(`/module-lessons/${lesson.id}/quiz`)
    showQuizPickerFor.value = null
    await reloadOpenLessonQuiz()
  } catch (e) {
    quizError.value = e instanceof ApiError ? e.message : 'ยกเลิกการเชื่อมโยงไม่สำเร็จ'
  } finally {
    detaching.value = false
    pendingDetachLesson.value = null
  }
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-152b — OUTLINE + INSPECTOR (desktop only)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * The โมดูล tab was accordions inside accordions: a Section opened to reveal
 * its lessons, a lesson opened to reveal its quiz, and the quiz opened again to
 * reveal its settings — by which point the admin could no longer see where in
 * the course they were standing. TASK-152 §4: the LEFT pane is the whole
 * outline, always visible and still draggable; the RIGHT pane is the settings
 * of whatever is selected. Selecting something never collapses the outline —
 * that is the entire point of the change.
 *
 * ONLY AT `lg:` AND ABOVE. Below it the stacked accordion is kept exactly as it
 * was (§4: "an inspector on a phone is a drawer, and this admin app is used on
 * a laptop"). The 1024px below is Tailwind's own `lg` breakpoint; the split is
 * STRUCTURAL — a different DOM, not a different stylesheet — so it has to be
 * asked in JS rather than expressed with `lg:` utility classes. The right pane
 * reuses the SAME markup the accordion renders, filtered to the selection,
 * rather than a second copy of it: two copies of a lesson's settings inside one
 * file is the drift CI-001/CI-002 warns about, at closer range.
 *
 * NO NEW ENDPOINT AND NO NEW SAVE PATH. Selecting a node seeds the forms the
 * gear panels already own and saves through the SAME functions
 * (saveSectionSettings / saveEditLesson / saveQuizSettings), each on an
 * explicit button. §4's ruling names the cost of the alternative: a half-typed
 * `7` landing as a 7% pass mark while somebody is mid-attempt, because ADR-029
 * reads quiz_pass_percent at the moment the learner submits.
 */
const wideLayoutQuery = window.matchMedia('(min-width: 1024px)')
const isWideLayout = ref(wideLayoutQuery.matches)
function onWideLayoutChange(event: MediaQueryListEvent) {
  isWideLayout.value = event.matches
}
onMounted(() => wideLayoutQuery.addEventListener('change', onWideLayoutChange))
onUnmounted(() => wideLayoutQuery.removeEventListener('change', onWideLayoutChange))

const selectedSectionId = ref<number | null>(null)
const selectedLessonId = ref<number | null>(null)
/*
 * Resolved from `modules` BY ID on every read, never held as an object: almost
 * every save on this screen ends in loadAll(), which replaces the Section and
 * lesson objects wholesale. Holding the object would leave the inspector
 * editing a detached copy of a row that no longer exists, and deleting the
 * selected lesson would leave it editing a ghost.
 */
const selectedModule = computed<ModuleItem | null>(
  () => modules.value.find((m) => m.id === selectedSectionId.value) ?? null,
)
const selectedLesson = computed<ModuleLessonItem | null>(
  () => selectedModule.value?.lessons.find((l) => l.id === selectedLessonId.value) ?? null,
)

/** The right pane holds exactly one Section in wide mode; the whole list in narrow mode. */
const inspectorGroups = computed<ModuleGroup[]>(() => {
  if (!isWideLayout.value) return moduleGroups.value
  const selected = selectedModule.value
  if (!selected) return []
  return moduleGroups.value
    .filter((g) => g.modules.some((m) => m.id === selected.id))
    .map((g) => ({ ...g, modules: g.modules.filter((m) => m.id === selected.id) }))
})
/** …and exactly one lesson, or none at all when a Section itself is selected. */
function inspectorLessons(m: ModuleItem): ModuleLessonItem[] {
  if (!isWideLayout.value) return m.lessons
  const lesson = selectedLesson.value
  return lesson ? m.lessons.filter((l) => l.id === lesson.id) : []
}

function selectSection(m: ModuleItem) {
  selectedSectionId.value = m.id
  selectedLessonId.value = null
  editingLessonId.value = null
  // Keeps the narrow-viewport accordion in step, so shrinking the window does
  // not land the admin on a collapsed card.
  expandedModuleId.value = m.id
  // Reuses the gear panel's own seeding rather than repeating it — same form,
  // same save function, one place where the shape is decided.
  if (expandedSectionSettingsId.value !== m.id) toggleSectionSettings(m)
}
function selectLesson(m: ModuleItem, l: ModuleLessonItem) {
  selectedSectionId.value = m.id
  selectedLessonId.value = l.id
  editingLessonId.value = null
  expandedModuleId.value = m.id
  seedEditLessonForm(l)
  ensureQuizSettingsForm(l)
  // §4 lists quiz_pass_percent and quiz_blocks_completion as LESSON settings,
  // so the inspector opens them instead of hiding them behind two more
  // disclosures. Set directly rather than through toggleLessonQuiz(), which is
  // a toggle and would close the panel when re-selecting the same lesson.
  expandedQuizLessonId.value = l.id
  expandedQuizSettingsLessonId.value = l.id
}
function isSectionSelected(m: ModuleItem): boolean {
  return isWideLayout.value && selectedSectionId.value === m.id && selectedLesson.value === null
}
function isLessonSelected(l: ModuleLessonItem): boolean {
  return isWideLayout.value && selectedLesson.value?.id === l.id
}
/** The Section row is the expand/collapse toggle in narrow mode only — in wide mode the lessons live in the left pane. */
function onSectionRowActivate(m: ModuleItem) {
  if (isWideLayout.value) return
  toggleModuleLessons(m.id)
}

const CONTENT_TYPE_ICON: Record<LessonContentType, string> = {
  video: 'play',
  pdf: 'document',
  image: 'image',
  quiz: 'check_square',
  link: 'link',
}
const CONTENT_TYPE_LABEL: Record<LessonContentType, string> = {
  video: 'วิดีโอ',
  pdf: 'PDF',
  image: 'รูปภาพ',
  quiz: 'แบบทดสอบท้ายบทเรียน',
  link: 'ลิงก์',
}
function contentTypeIcon(type: LessonContentType): string {
  return CONTENT_TYPE_ICON[type] ?? 'book'
}
function contentTypeLabel(type: LessonContentType): string {
  return CONTENT_TYPE_LABEL[type] ?? type
}

/**
 * ADR-028 §2.3 — which completion gate this lesson actually has, in one line.
 *
 * The branches mirror LessonPreviewModal's `gateKind` exactly (downloadable →
 * external → uploaded video → uploaded pdf → untracked); they are the same
 * rule read twice, not a second rule. Both percentages are READ from the
 * company's `academy_completion_settings` and never defaulted here (BR-7) —
 * there is no per-lesson watch/read threshold in the schema, only the
 * per-lesson quiz pass mark below, so this line is a readout, not a field.
 */
function lessonGateSummary(l: ModuleLessonItem): string {
  const settings = completionSettings.value
  if (l.is_downloadable) return 'ดาวน์โหลดได้ → ไม่บังคับเปอร์เซ็นต์ ผู้เรียนกด “เรียนจบ” ได้ทันที'
  if (l.source_type !== 'upload') return 'ลิงก์ภายนอก → วัดความคืบหน้าไม่ได้ ผู้เรียนกด “เรียนจบ” ได้ทันที'
  if (l.content_type === 'video') {
    return settings ? `ต้องดูวิดีโออย่างน้อย ${settings.video_watch_percent}% (ค่าของบริษัท)` : 'ยังอ่านเกณฑ์ของบริษัทไม่ได้'
  }
  if (l.content_type === 'pdf') {
    return settings ? `ต้องอ่านเอกสารอย่างน้อย ${settings.pdf_read_percent}% (ค่าของบริษัท)` : 'ยังอ่านเกณฑ์ของบริษัทไม่ได้'
  }
  return 'เนื้อหาประเภทนี้ไม่มีการวัดตำแหน่งการเรียน ผู้เรียนกด “เรียนจบ” ได้ทันที'
}

// ── Exam form ──
const showExamForm = ref(false)
const examForm = ref({ title: '', cert_tier_id: '', passing_score: '70' })
const examFormError = ref('')
async function submitExam() {
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    examFormError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  examFormError.value = ''
  await api.post('/exams', {
    title: examForm.value.title,
    cert_tier_id: Number(examForm.value.cert_tier_id),
    passing_score: Number(examForm.value.passing_score),
    ...(isSuperAdmin.value ? { company_id: selectedCompanyId.value } : {}),
  })
  examForm.value = { title: '', cert_tier_id: '', passing_score: '70' }
  showExamForm.value = false
  await loadAll()
}

// ── Exam edit/delete (Academy Sprint 2 — previously create-only) ──
const editingExamId = ref<number | null>(null)
const editExamForm = ref({ title: '', cert_tier_id: '', passing_score: '' })
function startEditExam(ex: ExamItem) {
  editingExamId.value = ex.id
  editExamForm.value = { title: ex.title, cert_tier_id: String(ex.cert_tier?.id ?? ''), passing_score: String(ex.passing_score) }
}
function cancelEditExam() {
  editingExamId.value = null
}
async function saveEditExam(examId: number) {
  try {
    await api.put(`/exams/${examId}`, {
      title: editExamForm.value.title,
      cert_tier_id: Number(editExamForm.value.cert_tier_id),
      passing_score: Number(editExamForm.value.passing_score),
    })
    editingExamId.value = null
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  }
}
async function deleteExam(examId: number) {
  try {
    await api.delete(`/exams/${examId}`)
    exams.value = exams.value.filter((e) => e.id !== examId)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
  }
}

// ── Question bank authoring (Academy Sprint 1/2) ──
// Single-correct-answer multiple choice only (human-confirmed). The
// server enforces "at most one correct option per question" by mutual
// exclusion (see ExamQuestionOptionService) — the UI just calls
// markOptionCorrect() and reloads, no client-side bookkeeping needed.
const expandedExamId = ref<number | null>(null)
const questionsByExam = ref<Record<number, ExamQuestionItem[]>>({})
const loadingQuestionsFor = ref<number | null>(null)
const questionError = ref('')

// Shared by both the Exam question bank (above) and the Lesson quiz
// authoring panel (Section tab, ADR-009) — same "at most one correct
// option, never leave a question unanswerable" shape in both places.
interface QuestionLike {
  id: number
  question_text: string
  options: { is_correct: boolean | null }[]
}
function questionHasNoCorrectAnswer(q: QuestionLike): boolean {
  return !q.options.some((o) => o.is_correct)
}

// "Forgot to pick a correct answer" reminder — a per-question inline
// note (always visible while unresolved) plus a one-time modal nudge
// when the admin collapses the question panel with something still
// unresolved. Dismissible for good via a checkbox, persisted in
// localStorage (this is a pure UI nag, not business data — no backend
// needed, matches this app's existing localStorage usage for
// UI-only preferences like HeroHeader's storage-key).
//
// Via safeStorage, not localStorage: this read runs during setup(), so a
// storage that exists but is unusable (Safari private mode, sandboxed
// iframe, a partial object in a test environment) took the entire Academy
// screen down with "localStorage.getItem is not a function" rather than
// losing a dismissed nag. See safeStorage.js.
const HIDE_INCOMPLETE_WARNING_KEY = 'academy-hide-incomplete-answer-warning'
const hideIncompleteWarning = ref(readStored(HIDE_INCOMPLETE_WARNING_KEY) === '1')
const showIncompleteWarningModal = ref(false)
const incompleteWarningQuestions = ref<QuestionLike[]>([])
const dontShowIncompleteWarningAgain = ref(false)

function closeIncompleteWarningModal() {
  if (dontShowIncompleteWarningAgain.value) {
    hideIncompleteWarning.value = true
    writeStored(HIDE_INCOMPLETE_WARNING_KEY, '1')
  }
  showIncompleteWarningModal.value = false
}

async function toggleExamQuestions(examId: number) {
  if (expandedExamId.value === examId) {
    if (!hideIncompleteWarning.value) {
      const incomplete = (questionsByExam.value[examId] ?? []).filter(questionHasNoCorrectAnswer)
      if (incomplete.length) {
        incompleteWarningQuestions.value = incomplete
        showIncompleteWarningModal.value = true
      }
    }
    expandedExamId.value = null
    return
  }
  expandedExamId.value = examId
  if (!questionsByExam.value[examId]) await loadQuestionsFor(examId)
}

async function loadQuestionsFor(examId: number) {
  loadingQuestionsFor.value = examId
  questionError.value = ''
  try {
    const res = await api.get<{ data: ExamQuestionItem[] }>(`/exams/${examId}/questions`)
    questionsByExam.value[examId] = res.data
  } catch (e) {
    questionError.value = e instanceof ApiError ? `โหลดคำถามไม่สำเร็จ (${e.status})` : 'โหลดคำถามไม่สำเร็จ'
  } finally {
    loadingQuestionsFor.value = null
  }
}

const newQuestionText = ref<Record<number, string>>({})
const addingQuestionFor = ref<number | null>(null)
async function addQuestion(examId: number) {
  const text = newQuestionText.value[examId]?.trim()
  if (!text) return
  addingQuestionFor.value = examId
  questionError.value = ''
  try {
    await api.post(`/exams/${examId}/questions`, { question_text: text })
    newQuestionText.value[examId] = ''
    await loadQuestionsFor(examId)
  } catch (e) {
    questionError.value = e instanceof ApiError ? `เพิ่มคำถามไม่สำเร็จ (${e.status})` : 'เพิ่มคำถามไม่สำเร็จ'
  } finally {
    addingQuestionFor.value = null
  }
}

async function deleteQuestion(examId: number, questionId: number) {
  try {
    await api.delete(`/exam-questions/${questionId}`)
    await loadQuestionsFor(examId)
  } catch (e) {
    questionError.value = e instanceof ApiError ? `ลบคำถามไม่สำเร็จ (${e.status})` : 'ลบคำถามไม่สำเร็จ'
  }
}

const newOptionText = ref<Record<number, string>>({})
const addingOptionFor = ref<number | null>(null)
// Plain (non-reactive) map of the per-question "add option" input
// elements — only needed for the imperative re-focus after add, not
// for reactivity, so a ref() wrapper would be pointless overhead here.
const optionInputEls: Record<number, HTMLInputElement | null> = {}
function setOptionInputEl(questionId: number, el: Element | null) {
  optionInputEls[questionId] = el as HTMLInputElement | null
}
async function addOption(examId: number, questionId: number) {
  const text = newOptionText.value[questionId]?.trim()
  if (!text) return
  addingOptionFor.value = questionId
  questionError.value = ''
  try {
    await api.post(`/exam-questions/${questionId}/options`, { option_text: text })
    newOptionText.value[questionId] = ''
    await loadQuestionsFor(examId)
    // Keep focus in the input so an admin can add several options in a
    // row without re-clicking — wait a tick for the re-render first.
    await nextTick()
    optionInputEls[questionId]?.focus()
  } catch (e) {
    questionError.value = e instanceof ApiError ? `เพิ่มตัวเลือกไม่สำเร็จ (${e.status})` : 'เพิ่มตัวเลือกไม่สำเร็จ'
  } finally {
    addingOptionFor.value = null
  }
}

async function markOptionCorrect(examId: number, optionId: number) {
  try {
    await api.put(`/exam-question-options/${optionId}`, { is_correct: true })
    await loadQuestionsFor(examId)
  } catch (e) {
    questionError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  }
}

async function deleteOption(examId: number, optionId: number) {
  try {
    await api.delete(`/exam-question-options/${optionId}`)
    await loadQuestionsFor(examId)
  } catch (e) {
    questionError.value = e instanceof ApiError ? `ลบตัวเลือกไม่สำเร็จ (${e.status})` : 'ลบตัวเลือกไม่สำเร็จ'
  }
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * TASK-152 — ความคืบหน้าตัวแทน, SERVER-AGGREGATED
 * ═══════════════════════════════════════════════════════════════════════
 *
 * THE CLIENT-SIDE JOIN IS GONE. There is no `moduleCompletions`, no
 * `certifications` and no `/users` roster in this file any more, and no
 * fallback path back to them — leaving one would leave the truncated
 * fractions reachable (see ProgressSummaryResponse for the bug).
 *
 * Everything below reads fields off the response. Nothing on this screen
 * divides one number by another any more; `required_lesson_count` arrives
 * ready-made from the same SQL predicate ModuleResource uses.
 *
 * SEARCH: `q` is now SERVER-side (name/phone/email, the same spelling
 * UserController::index uses), because the roster is paginated server-side
 * and a client-side filter over one page would silently miss everyone else.
 *
 * The national-ID search box that used to sit beside it is REMOVED, not
 * broken: AcademyProgressSummaryService deliberately does not return or
 * search `national_id` (it is encrypted at rest and its reveal gate lives in
 * UserResource — PDPA, CLAUDE.md §6), and re-adding a /users read here to
 * restore it would rebuild exactly the client-side join this task deleted.
 * National-ID lookup stays on Manage Agents. Flagged to ag-lead.
 */
const progressSearch = ref('')
const expandedProgressAgentId = ref<number | null>(null)

const progressRows = ref<ProgressSummaryRow[]>([])
const progressOutline = ref<ProgressSummarySection[]>([])
const progressMeta = ref<ProgressSummaryResponse['meta'] | null>(null)
const progressAgentCount = ref(0)
const progressRequiredTotal = ref(0)
const progressPage = ref(1)
const progressLoading = ref(false)
const progressLoadedOnce = ref(false)
const progressError = ref('')
let progressSearchTimer: ReturnType<typeof setTimeout> | null = null

async function loadProgressSummary() {
  // Super Admin is not scoped by TenantScope, so the endpoint REQUIRES an
  // explicit company_id (BR-6): aggregating across every tenant is not a
  // number anyone asked for. Say so rather than firing a request that 422s.
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    progressRows.value = []
    progressOutline.value = []
    progressMeta.value = null
    progressError.value = 'Super Admin: เลือกบริษัทด้านบนก่อน จึงจะแสดงความคืบหน้าของตัวแทนบริษัทนั้นได้'
    progressLoadedOnce.value = true
    return
  }
  progressLoading.value = true
  progressError.value = ''
  try {
    const params = new URLSearchParams({ page: String(progressPage.value), per_page: '25' })
    const q = progressSearch.value.trim()
    if (q) params.set('q', q)
    if (isSuperAdmin.value && selectedCompanyId.value) params.set('company_id', String(selectedCompanyId.value))

    const res = await api.get<ProgressSummaryResponse>(`/academy-progress-summary?${params.toString()}`)
    progressRows.value = res.data
    progressOutline.value = res.summary.sections
    progressMeta.value = res.meta
    progressAgentCount.value = res.summary.agent_count
    progressRequiredTotal.value = res.summary.required_lesson_count
  } catch (e) {
    progressRows.value = []
    progressError.value = e instanceof ApiError ? e.message : 'โหลดความคืบหน้าไม่สำเร็จ'
  } finally {
    progressLoading.value = false
    progressLoadedOnce.value = true
  }
}

/** Debounced: `q` is a server round-trip now, not a filter over memory. */
function onProgressSearchInput() {
  if (progressSearchTimer) clearTimeout(progressSearchTimer)
  progressSearchTimer = setTimeout(() => {
    progressPage.value = 1
    void loadProgressSummary()
  }, 300)
}

function goToProgressPage(page: number) {
  const meta = progressMeta.value
  if (!meta || page < 1 || page > meta.last_page) return
  progressPage.value = page
  expandedProgressAgentId.value = null
  void loadProgressSummary()
}

/*
 * Loaded when the tab is opened rather than on mount: it is several GROUP BY
 * passes over module_completions (and throttled server-side for that reason),
 * so an admin who only came to author a lesson should not pay for it.
 * Re-fired when a Super Admin picks a different company.
 */
watch(activeTab, (tab) => {
  if (tab === 'progress' && !progressLoadedOnce.value) void loadProgressSummary()
})
watch(selectedCompanyId, () => {
  if (activeTab.value === 'progress') {
    progressPage.value = 1
    void loadProgressSummary()
  }
})

/** Per-agent, per-Section fraction — read from the row, never recomputed. */
function agentSectionCompleted(row: ProgressSummaryRow, moduleId: number): number {
  return row.sections.find((s) => s.module_id === moduleId)?.completed_required_count ?? 0
}
function isLessonCompletedByAgent(row: ProgressSummaryRow, lessonId: number): boolean {
  return row.completed_lesson_ids.includes(lessonId)
}
function toggleProgressAgent(agentId: number) {
  expandedProgressAgentId.value = expandedProgressAgentId.value === agentId ? null : agentId
}

// TASK-058 (human-requested 2026-07-30) — manual "grant without exam"
// override. POST /user-certifications (StoreUserCertificationRequest +
// ManualCertificationService, BR-1) — Company Admin (own company) /
// Super Admin only; deliberately awards no XP (see backend docblock,
// flagged // TODO: CONFIRM). Idempotent server-side: re-granting an
// already-passed tier is a safe no-op, not an error.
const grantingTierKey = ref<string | null>(null)
const grantError = ref('')
function certTiersNotYetPassed(row: ProgressSummaryRow): CertTier[] {
  // `cert_tiers_passed` is COMPLETE per agent (TASK-152) — it used to come
  // from /user-certifications, which paginates at 15 for the whole company, so
  // an agent's badges could silently disappear and this list could offer to
  // re-grant a tier they already held.
  const passedIds = new Set(row.cert_tiers_passed.map((t) => t.id))
  return certTiers.value.filter((t) => !passedIds.has(t.id))
}
// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal. grantCertification() opens the dialog;
// confirmGrantCertification() (wired to @confirm) runs the actual API call.
const pendingGrant = ref<{ agentId: number; tier: CertTier } | null>(null)
function grantCertification(agentId: number, tier: CertTier) {
  pendingGrant.value = { agentId, tier }
}
async function confirmGrantCertification() {
  const pending = pendingGrant.value
  if (!pending) return
  const { agentId, tier } = pending
  const key = `${agentId}:${tier.id}`
  grantingTierKey.value = key
  grantError.value = ''
  try {
    await api.post('/user-certifications', { user_id: agentId, cert_tier_id: tier.id })
    // Only the progress readout changed — reloading the whole screen would
    // also collapse whatever the admin had open on the โมดูล tab.
    await loadProgressSummary()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      grantError.value = body.errors?.user_id?.[0] ?? body.errors?.cert_tier_id?.[0] ?? 'อนุมัติไม่สำเร็จ'
    } else {
      grantError.value = e instanceof ApiError ? `อนุมัติไม่สำเร็จ (${e.status})` : 'อนุมัติไม่สำเร็จ'
    }
  } finally {
    grantingTierKey.value = null
    pendingGrant.value = null
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <!-- §4.B2 — `description` was "ERD-001 §Academy — BR-1 … (BR-7)". Three
         references to OUR documents, printed at the top of a customer's screen.
         The statement they qualified (the syllabus and pass marks are still
         provisional config) is the part an admin can act on, so that stays. -->
    <HeroHeader
      icon="book"
      title="Academy"
      subtitle="โมดูล / แบบทดสอบท้ายบทเรียน / แบบประเมินผล / ความคืบหน้าตัวแทน"
      description="เนื้อหาซิลลาบัสและเกณฑ์การผ่านยังเป็นค่าตัวอย่างชั่วคราว รอการยืนยัน"
      accent-color="brand"
      storage-key="academy-management"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="14" />
            {{ t.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
    <!-- Super Admin company picker — hoisted out of the โมดูล section
         (TASK-150) because the แบบทดสอบท้ายบทเรียน tab needs the same value:
         StoreQuizRequest requires company_id from a Super Admin exactly as
         StoreModuleRequest does (BR-6 — never inferred from the client). -->
    <!-- TASK-152 added `progress`: GET /academy-progress-summary REQUIRES an
         explicit company_id from a Super Admin (BR-6 — TenantScope does not
         constrain them, so an unnamed company would mean "aggregate every
         tenant on the platform"). -->
    <CompanyScopeNotice
      v-if="activeTab === 'modules' || activeTab === 'quizzes' || activeTab === 'progress'"
      action="จัดการเนื้อหา Academy"
    />

    <!-- TASK-221 — cert tiers. No CompanyScopeNotice above it on purpose:
         the list is global, so there is no company to pick and telling the
         admin to pick one would be wrong. -->
    <CertTierPanel v-if="activeTab === 'tiers'" @changed="loadAll" />

    <!-- TASK-150 / ADR-030 — the quiz LIBRARY. -->
    <QuizLibraryPanel
      v-if="activeTab === 'quizzes'"
      :is-super-admin="isSuperAdmin"
      :selected-company-id="selectedCompanyId"
      @changed="loadAll"
    />

    <!-- Modules (Sections) — ADR-009 Udemy-style hierarchy: Section → Lesson → optional formative lesson quiz -->
    <section v-if="activeTab === 'modules'" class="mt-4">
      <div class="flex justify-end gap-2 mb-2">
        <!-- ADR-028 §4 / ADR-029 §2.4 — the company-level thresholds. They
             were readable (the preview modal quoted them) but not editable
             anywhere in this app, so `quiz_pass_percent` had no home. All
             three live together because PUT /academy-completion-settings
             requires the other two. -->
        <button
          class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
          @click="toggleCompletionSettingsForm"
        >
          <Icon name="cog" :size="13" />
          เกณฑ์การเรียนจบของบริษัท
        </button>
        <button class="btn-primary" @click="showModuleForm = !showModuleForm">
          + เพิ่ม Section
        </button>
        <!-- TASK-188 §5.C1/C2 amendment (human, 2026-08-16) — the top-level
             "+ เพิ่มบทเรียน" that used to sit here has MOVED onto each Section
             row (`data-test="add-lesson-row"`). It is not duplicated: a
             top-level button could not say WHICH Section it was adding to, and
             had to guess (selected Section, else the first one). Only
             "+ เพิ่ม Section" is a top-level create, because a Section is the
             only thing on this tab that has no parent to hang off. -->
      </div>
      <div v-if="showCompletionSettingsForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200">
        <p class="text-xs font-bold text-slate-900 flex items-center gap-1">
          เกณฑ์การเรียนจบ (ใช้กับทุกบทเรียนของบริษัทนี้)
          <!-- §4.B1/B2 — was a permanent paragraph opening "BR-7 —" and closing
               "(ADR-028 §4)". -->
          <InfoPopover label="เกณฑ์การเรียนจบของบริษัท" :text="COMPLETION_SETTINGS_EXPLANATION" />
        </p>
        <p v-if="completionSettingsLoading" class="mt-2 text-xs text-slate-400">กำลังโหลดเกณฑ์...</p>
        <div v-else class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="text-xs font-bold text-slate-500">ต้องดูวิดีโออย่างน้อย (%)</label>
            <input v-model="completionSettingsForm.video_watch_percent" type="number" min="1" max="100" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">ต้องอ่านเอกสารอย่างน้อย (%)</label>
            <input v-model="completionSettingsForm.pdf_read_percent" type="number" min="1" max="100" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
              เกณฑ์ผ่านแบบทดสอบท้ายบท (%)
              <!-- §4.B1/B2 — was a paragraph ending "(ADR-029 §2.4)". -->
              <InfoPopover
                label="เกณฑ์ผ่านแบบทดสอบท้ายบท"
                :text="COMPANY_QUIZ_PASS_PERCENT_EXPLANATION"
              />
            </label>
            <input v-model="completionSettingsForm.quiz_pass_percent" type="number" min="1" max="100" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
          </div>
        </div>
        <p v-if="completionSettingsError" class="mt-2 text-xs font-bold text-rose-600">{{ completionSettingsError }}</p>
        <div class="mt-3 flex items-center justify-end gap-2">
          <span v-if="completionSettingsSaved" class="text-[11px] font-bold text-emerald-600">บันทึกแล้ว</span>
          <button class="btn-primary" :disabled="savingCompletionSettings || completionSettingsLoading" @click="saveCompletionSettings">
            {{ savingCompletionSettings ? 'กำลังบันทึก...' : 'บันทึกเกณฑ์' }}
          </button>
        </div>
      </div>
      <form v-if="showModuleForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitModule">
        <div class="col-span-2">
          <label class="text-xs font-bold text-slate-500">ชื่อ Section</label>
          <input v-model="moduleForm.title" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">Cert tier</label>
          <select v-model="moduleForm.cert_tier_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <option value="" disabled>เลือก tier</option>
            <option v-for="t in certTiers" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">แพ็กเกจที่เกี่ยวข้อง (ไม่บังคับ)</label>
          <select v-model="moduleForm.product_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <option value="">ทั่วไป (ไม่ผูกกับแพ็กเกจ)</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="col-span-2 flex items-center gap-1.5">
          <input id="module-is-published" v-model="moduleForm.is_published" type="checkbox" />
          <label for="module-is-published" class="text-xs font-bold text-slate-500">เผยแพร่ทันที (ถ้าไม่เลือก จะบันทึกเป็นฉบับร่าง)</label>
        </div>
        <p v-if="moduleError" class="col-span-2 text-xs font-bold text-rose-600">{{ moduleError }}</p>
        <div class="col-span-2 flex justify-end">
          <button type="submit" :disabled="submittingModule" class="btn-primary">
            {{ submittingModule ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>

      <!-- ADR-031 §2.1 — a failed reorder RESTORES the previous order and says
           so. A list that snaps back silently reads as a broken drag. -->
      <div v-if="reorderError" class="mb-2 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-xs font-bold text-amber-700">
        {{ reorderError }}
      </div>

      <!-- TASK-188 §5.C1 amendment — with the top-level "+ เพิ่มบทเรียน" gone,
           a company with no Sections at all has no add-lesson affordance
           anywhere, which is CORRECT (a lesson has to live in a Section) but
           left the admin staring at three words and no way forward. The empty
           state now points at the one action that IS available here. Not a
           paragraph explaining where a button is (§5.C3) — a button. -->
      <EmptyState
        v-if="!modules.length"
        icon="book"
        title="ยังไม่มี Section"
        message="บทเรียนต้องอยู่ใน Section — สร้าง Section แรกก่อน"
        cta-label="+ เพิ่ม Section"
        :cta-disabled="false"
        data-test="no-sections-empty"
        @cta="showModuleForm = true"
      />
      <!-- Grouped by cert tier (and, for a Super Admin, by company): that is
           the sibling set PUT /cert-tiers/{certTier}/modules/reorder rewrites,
           so the boundary a drag may cross is shown rather than discovered by
           a rejected drop. -->
      <template v-else>
      <!-- TASK-152b §4 — two panes at `lg:` and above, the stacked accordion
           below it. The grid classes are bound rather than written as `lg:`
           utilities because the two layouts are DIFFERENT DOM, not the same
           DOM restyled: the left pane exists only in wide mode, and the right
           pane is the same cards filtered to the selection. -->
      <div :class="isWideLayout ? 'grid grid-cols-12 gap-4 items-start' : ''">

      <!-- ═══ LEFT — the course outline ═══════════════════════════════════
           cert tier → Section → lesson, ALWAYS visible. Selecting a row fills
           the inspector on the right and never collapses this list; that is
           the whole point of TASK-152b. Drag uses the SAME handlers as the
           cards on the right (arm-on-handle, one bulk PUT per sibling set,
           restore-and-say-why on failure) — no second implementation. -->
      <div v-if="isWideLayout" class="col-span-7 min-w-0">
        <div class="rounded-xl bg-white/95 border border-slate-200 p-3">
          <!-- §4.B1/B3 — was a two-line paragraph; the ordering half of it was
               one of the four explanations written out twice (three times, in
               fact) and now reads from DRAG_REORDER_EXPLANATION. -->
          <p class="text-xs font-bold text-slate-500 mb-2 px-1 flex items-center gap-1">
            โครงสร้างคอร์ส
            <InfoPopover label="โครงสร้างคอร์ส">
              <p>{{ OUTLINE_SELECT_EXPLANATION }}</p>
              <p class="mt-2">{{ DRAG_REORDER_EXPLANATION }}</p>
            </InfoPopover>
          </p>
          <div v-for="g in moduleGroups" :key="g.key" class="mb-3 last:mb-0">
            <p class="text-xs font-bold text-slate-500 px-1 mb-1.5">
              {{ g.certTier?.name ?? 'ยังไม่ได้ผูกกับระดับใบรับรอง' }}
            </p>
            <TransitionGroup tag="div" name="list-fade" class="space-y-1.5">
              <div
                v-for="m in g.modules"
                :key="m.id"
                class="rounded-lg border transition-colors"
                :class="[
                  dragOverModuleId === m.id ? 'border-brand-400 bg-brand-50/40' : 'border-slate-200 bg-white',
                  draggingModuleId === m.id ? 'opacity-50' : '',
                ]"
                :draggable="armedModuleId === m.id"
                @dragstart="onModuleDragStart(m, $event)"
                @dragover="onModuleDragOver(m, $event)"
                @dragleave="dragOverModuleId = dragOverModuleId === m.id ? null : dragOverModuleId"
                @drop.prevent="onModuleDrop(m)"
                @dragend="endModuleDrag"
              >
                <!-- Section row. Plain div + role/tabindex rather than a
                     <button>, for the same reason the card on the right is:
                     it carries its own controls, and a button inside a button
                     is invalid HTML. -->
                <div
                  class="flex items-center gap-1.5 px-2 py-2 rounded-lg cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                  :class="isSectionSelected(m) ? 'bg-brand-50' : 'hover:bg-slate-50'"
                  role="button"
                  tabindex="0"
                  :aria-current="isSectionSelected(m) ? 'true' : undefined"
                  @click="selectSection(m)"
                  @keydown.enter.prevent="selectSection(m)"
                  @keydown.space.prevent="selectSection(m)"
                >
                  <span
                    class="shrink-0 p-1 -m-0.5 rounded-lg text-slate-300 hover:text-slate-500 cursor-grab active:cursor-grabbing"
                    :title="dragSectionHandleTitle(m.cert_tier?.name)"
                    aria-hidden="true"
                    @mousedown.stop="armedModuleId = m.id"
                    @mouseup="armedModuleId = null"
                    @touchstart.stop="armedModuleId = m.id"
                    @click.stop
                  >
                    <Icon name="list" :size="13" />
                  </span>
                  <Icon name="book" :size="13" class="shrink-0 text-slate-400" />
                  <span class="min-w-0 flex-1 text-sm font-bold truncate" :class="isSectionSelected(m) ? 'text-brand-700' : 'text-slate-900'">
                    {{ m.title }}
                  </span>
                  <!-- §4 AC — "a draft Section is visibly a draft in the
                       outline". Not a colour alone: a pill with the word. -->
                  <span
                    v-if="!m.is_published"
                    class="shrink-0 px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold"
                  >ฉบับร่าง</span>
                  <span
                    v-if="m.enforce_sequential || m.drip_days !== null"
                    class="shrink-0 px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-600 text-[10px] font-bold"
                    :title="sectionSettingsSummary(m)"
                  >เงื่อนไขการเปิด</span>
                  <!-- ADR-031 §2.4 — the admin's total (drafts included). -->
                  <span class="shrink-0 text-[11px] text-slate-400 tabular-nums">{{ m.lesson_count }} บท</span>
                  <!--
                    TASK-188 §5.C1 amendment (human, 2026-08-16) — "+ เพิ่มบทเรียน"
                    lives HERE now, one per Section, right-aligned beside the
                    count, instead of once at the top of the tab.

                    Per SECTION, not per cert-tier group: a lesson belongs to a
                    Section, so a tier-level button would not know which Section
                    to add to. This one does, and says so by where it sits.

                    Shown even at zero lessons — that is the case where it
                    matters most; the row below it reads "ยังไม่มีบทเรียน".

                    Deliberately NOT btn-primary: it repeats on every row, and a
                    column of solid buttons would out-shout the Section names
                    (CLAUDE.md §6.5 — one accent per page, kept for
                    "+ เพิ่ม Section"). Tinted, not solid.

                    `.stop` because the row itself is the select handler; without
                    it the click would both select and add, and the outline's own
                    comment above explains why the row is a div + role=button.
                  -->
                  <button
                    type="button"
                    class="shrink-0 px-1.5 py-0.5 rounded-lg border border-brand-200 bg-brand-50/70 text-[11px] font-bold text-brand-700 hover:bg-brand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    data-test="add-lesson-row"
                    @click.stop="startAddLesson(m.id)"
                  >
                    + เพิ่มบทเรียน
                  </button>
                </div>

                <div v-if="m.lessons.length" class="pl-7 pr-2 pb-2 space-y-1">
                  <div
                    v-for="l in m.lessons"
                    :key="l.id"
                    class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg border cursor-pointer transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    :class="[
                      isLessonSelected(l) ? 'bg-brand-50 border-brand-200' : 'bg-white border-slate-100 hover:bg-slate-50',
                      dragOverLessonId === l.id ? 'border-brand-400 bg-brand-50/50' : '',
                      draggingLessonId === l.id ? 'opacity-50' : '',
                    ]"
                    role="button"
                    tabindex="0"
                    :aria-current="isLessonSelected(l) ? 'true' : undefined"
                    :draggable="armedLessonId === l.id"
                    @click="selectLesson(m, l)"
                    @keydown.enter.prevent="selectLesson(m, l)"
                    @keydown.space.prevent="selectLesson(m, l)"
                    @dragstart="onLessonDragStart(l, $event)"
                    @dragover="onLessonDragOver(m, l, $event)"
                    @dragleave="dragOverLessonId = dragOverLessonId === l.id ? null : dragOverLessonId"
                    @drop.prevent.stop="onLessonDrop(m, l)"
                    @dragend="endLessonDrag"
                  >
                    <span
                      class="shrink-0 p-1 -m-0.5 rounded-lg text-slate-300 hover:text-slate-500 cursor-grab active:cursor-grabbing"
                      :title="DRAG_LESSON_HANDLE_TITLE"
                      aria-hidden="true"
                      @mousedown.stop="armedLessonId = l.id"
                      @mouseup="armedLessonId = null"
                      @touchstart.stop="armedLessonId = l.id"
                      @click.stop
                    >
                      <Icon name="list" :size="12" />
                    </span>
                    <Icon
                      :name="contentTypeIcon(l.content_type)"
                      :size="12"
                      class="shrink-0 text-slate-400"
                      :title="contentTypeLabel(l.content_type)"
                    />
                    <span class="min-w-0 flex-1 text-xs font-bold truncate" :class="isLessonSelected(l) ? 'text-brand-700' : 'text-slate-700'">
                      {{ l.title }}
                    </span>
                    <span
                      v-if="!l.is_published"
                      class="shrink-0 px-1.5 rounded-full bg-slate-100 text-slate-400 text-[10px] font-bold"
                    >ฉบับร่าง</span>
                    <!-- ADR-031 §2.4 — "shown, not counted". -->
                    <span
                      v-if="l.is_optional"
                      class="shrink-0 px-1.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold"
                      :title="OPTIONAL_LESSON_PILL_TITLE"
                    >บทเสริม</span>
                    <!-- ADR-029 §2.1 — ANY content type may carry one, so this
                         keys off the question count, never off content_type. -->
                    <span
                      v-if="l.quiz_question_count"
                      class="shrink-0 px-1.5 rounded-full bg-blue-50 text-blue-600 text-[10px] font-bold"
                      :title="`มีแบบทดสอบท้ายบทเรียน ${l.quiz_question_count} คำถาม`"
                    >แบบทดสอบ {{ l.quiz_question_count }}</span>
                  </div>
                </div>
                <p v-else class="px-2 pb-2 pl-9 text-[11px] text-slate-400">ยังไม่มีบทเรียน</p>
              </div>
            </TransitionGroup>
          </div>
        </div>
      </div>

      <!-- ═══ RIGHT — the inspector (and, below `lg:`, the whole accordion) ══
           Same cards, filtered: in wide mode `inspectorGroups` holds only the
           selected Section and `inspectorLessons()` only the selected lesson,
           so there is ONE copy of every settings form on this screen. -->
      <div :class="isWideLayout ? 'col-span-5 min-w-0' : ''">
      <div
        v-if="isWideLayout && !selectedModule"
        class="rounded-xl bg-white/95 border border-dashed border-slate-200 px-5 py-8 text-center"
      >
        <Icon name="book" :size="22" class="text-slate-300 mx-auto" />
        <p class="mt-2 text-xs font-bold text-slate-600">เลือก Section หรือบทเรียนจากรายการด้านซ้าย</p>
        <p class="mt-1 text-[11px] text-slate-400 leading-relaxed">
          การตั้งค่าของสิ่งที่เลือกจะแสดงตรงนี้ โดยรายการด้านซ้ายจะไม่ถูกยุบ
        </p>
      </div>
      <div v-for="g in inspectorGroups" :key="g.key" class="mb-4 last:mb-0">
        <div v-if="!isWideLayout" class="flex flex-wrap items-center justify-between gap-2 mb-1.5 px-1">
          <p class="text-xs font-bold text-slate-500 flex items-center gap-1">
            {{ g.certTier?.name ?? 'ยังไม่ได้ผูกกับระดับใบรับรอง' }}
            <!-- §4.B3 — second of the three copies of the ordering explanation. -->
            <InfoPopover label="การจัดลำดับ Section" :text="DRAG_REORDER_EXPLANATION" />
          </p>
        </div>
        <TransitionGroup tag="div" name="list-fade" class="space-y-2">
        <div
          v-for="m in g.modules"
          :key="m.id"
          class="bg-white/95 border rounded-xl p-4 transition-colors"
          :class="[
            dragOverModuleId === m.id ? 'border-brand-400 bg-brand-50/40' : 'border-slate-200',
            draggingModuleId === m.id ? 'opacity-50' : '',
          ]"
          :draggable="armedModuleId === m.id"
          @dragstart="onModuleDragStart(m, $event)"
          @dragover="onModuleDragOver(m, $event)"
          @dragleave="dragOverModuleId = dragOverModuleId === m.id ? null : dragOverModuleId"
          @drop.prevent="onModuleDrop(m)"
          @dragend="endModuleDrag"
        >
          <!-- Section edit form -->
          <template v-if="editingModuleId === m.id">
            <div class="grid grid-cols-2 gap-3">
              <div class="col-span-2">
                <label class="text-xs font-bold text-slate-500">ชื่อ Section</label>
                <input v-model="editModuleForm.title" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">Cert tier</label>
                <select v-model="editModuleForm.cert_tier_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                  <option v-for="t in certTiers" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">แพ็กเกจที่เกี่ยวข้อง (ไม่บังคับ)</label>
                <select v-model="editModuleForm.product_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                  <option value="">ทั่วไป (ไม่ผูกกับแพ็กเกจ)</option>
                  <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">ลำดับการแสดง (sort order)</label>
                <input v-model="editModuleForm.sort_order" type="number" min="0" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div class="col-span-2 flex items-center gap-1.5">
                <input id="edit-module-is-published" v-model="editModuleForm.is_published" type="checkbox" />
                <label for="edit-module-is-published" class="text-xs font-bold text-slate-500">เผยแพร่แล้ว</label>
              </div>
              <div class="col-span-2 flex justify-end gap-2">
                <button class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200" @click="cancelEditModule">ยกเลิก</button>
                <button class="btn-primary" @click="saveEditModule(m.id)">บันทึก</button>
              </div>
            </div>
          </template>

          <!-- Section row -->
          <template v-else>
            <!--
              Human request (2026-08-09): "คลิ๊กที่ card แต่ละ section ให้ขยาย
              จัดการบทเรียนขึ้นมาเลย และคลิ๊กซ้ำที่ card เป็นการปิด".

              The toggle lives on the ROW, and every control inside it carries
              `.stop`. Without that, pressing แก้ไข would open the edit form and
              collapse the panel underneath it in the same click — the classic
              nested-interactive bug, and the reason this is worth a comment.

              Kept as a plain div + role/tabindex rather than wrapping the row
              in a <button>: the row already contains four buttons, and a button
              inside a button is invalid HTML that browsers silently reflow.
            -->
            <div
              class="flex items-center justify-between rounded-lg -m-1 p-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
              :class="isWideLayout ? '' : 'cursor-pointer hover:bg-slate-50/70'"
              :role="isWideLayout ? undefined : 'button'"
              :tabindex="isWideLayout ? undefined : 0"
              :aria-expanded="isWideLayout ? undefined : expandedModuleId === m.id"
              :title="isWideLayout ? undefined : expandedModuleId === m.id ? 'คลิกเพื่อซ่อนบทเรียน' : 'คลิกเพื่อจัดการบทเรียน'"
              @click="onSectionRowActivate(m)"
              @keydown.enter.prevent="onSectionRowActivate(m)"
              @keydown.space.prevent="onSectionRowActivate(m)"
            >
              <div class="flex items-center gap-2 min-w-0">
                <!-- ADR-031 §2.1 — the grab handle ARMS the row: `draggable` is
                     false until the handle is held, so text selection inside
                     the row's own inputs still works and a stray drag on the
                     card body does nothing. `.stop` because the row itself is
                     the expand/collapse toggle.
                     TASK-152b — in wide mode this card IS the inspector and the
                     draggable list is the outline on the left, so the handle
                     that arms it is not offered twice. -->
                <span
                  v-if="!isWideLayout"
                  class="shrink-0 p-1.5 -m-1 rounded-lg text-slate-300 hover:text-slate-500 cursor-grab active:cursor-grabbing"
                  :title="dragSectionHandleTitle(m.cert_tier?.name)"
                  aria-hidden="true"
                  @mousedown.stop="armedModuleId = m.id"
                  @mouseup="armedModuleId = null"
                  @touchstart.stop="armedModuleId = m.id"
                  @click.stop
                >
                  <Icon name="list" :size="14" />
                </span>
                <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-900">{{ m.title }}</p>
                  <p class="text-xs text-slate-400">
                    {{ m.cert_tier?.name }}<span v-if="m.product"> · {{ m.product.name }}</span> · ลำดับ {{ m.sort_order }} ·
                    <!-- ADR-031 §2.4 — SERVER counts, not `lessons.length`.
                         `lesson_count` is the admin's total (drafts included);
                         `required_lesson_count` is what the LEARNER's "X/Y"
                         divides by, and optional/draft lessons are outside it.
                         Spelled out rather than collapsed to one number so an
                         admin can see why the two differ. -->
                    {{ m.lesson_count }} บทเรียน
                    <span class="text-slate-300">(นับความคืบหน้า {{ m.required_lesson_count }}<span v-if="m.optional_lesson_count"> · เสริม {{ m.optional_lesson_count }}</span>)</span>
                  </p>
                  <!-- §3 — the collapsed summary of the release controls. This
                       hides the FORM, not the configuration: enforce_sequential
                       gates lessons on the BR-1 certification path. -->
                  <p v-if="m.enforce_sequential || m.drip_days !== null" class="text-[11px] font-bold text-amber-600 mt-0.5">
                    {{ sectionSettingsSummary(m) }}
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span :class="m.is_published ? 'text-emerald-600' : 'text-slate-400'" class="text-xs font-bold">{{ m.is_published ? 'เผยแพร่แล้ว' : 'ฉบับร่าง' }}</span>
                <!--
                  Human request (2026-08-09): a blue count balloon on this
                  button showing how much content the Section holds.

                  `relative` + an absolutely-positioned badge rather than a
                  third inline item, so the badge overlaps the border the way
                  a notification bubble does and the button's own width never
                  shifts as the number changes (1 → 12 would otherwise nudge
                  every control to its left). Same superscript treatment
                  TASK-084 established for count badges.

                  Hidden at zero on purpose: a badge reading "0" is noise, and
                  the empty case is already stated inside the panel
                  ("ยังไม่มีบทเรียนใน Section นี้"). The metadata line above still
                  carries the count in words for anyone who needs it spelled out.
                -->
                <div v-if="!isWideLayout" class="relative shrink-0">
                  <button
                    class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                    @click.stop="toggleModuleLessons(m.id)"
                  >
                    <Icon name="book" :size="13" />
                    {{ expandedModuleId === m.id ? 'ซ่อนบทเรียน' : 'จัดการบทเรียน' }}
                  </button>
                  <!-- ADR-031 §2.4 — `lesson_count` (the admin's total, drafts
                       included), not `lessons.length`: the count balloon is a
                       content tally, not a denominator. -->
                  <span
                    v-if="m.lesson_count"
                    class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-blue-600 text-white text-[10px] font-bold leading-[18px] text-center shadow-sm pointer-events-none"
                    :title="`${m.lesson_count} บทเรียน`"
                  >{{ m.lesson_count > 99 ? '99+' : m.lesson_count }}</span>
                </div>
                <!--
                  TASK-188 §5.C1 amendment (human, 2026-08-16) — the SAME
                  per-Section button as the outline row, for the layout that has
                  no outline. The outline panel is `v-if="isWideLayout"` and the
                  breakpoint is 1024px, so on the tablet this Admin is actually
                  used on the outline does not exist: putting the button only
                  there would have taken the top-level one away and given
                  nothing back at that width, re-opening §5.C1 ("reachable
                  without first selecting anything") on the human's own device.

                  Not a duplicate — it is the same affordance on the same
                  Section row, drawn where this layout draws that row. It hides
                  once the lessons panel is open, because the panel's own
                  "+ เพิ่มบทเรียน" is then a few pixels below it: exactly one per
                  Section is on screen at any moment, never two.
                -->
                <button
                  v-if="!isWideLayout && expandedModuleId !== m.id"
                  type="button"
                  class="shrink-0 px-2.5 py-1.5 rounded-lg border border-brand-200 bg-brand-50/70 text-xs font-bold text-brand-700 hover:bg-brand-100"
                  data-test="add-lesson-row"
                  @click.stop="startAddLesson(m.id)"
                >
                  + เพิ่มบทเรียน
                </button>
                <!-- ADR-031 §3 — the two rarely-used release controls, behind a
                     gear, exactly like the per-lesson quiz settings. -->
                <button
                  title="การเปิดให้เรียน (ลำดับ / หน่วงเวลา)"
                  class="p-1.5 rounded-lg hover:bg-slate-100"
                  :class="m.enforce_sequential || m.drip_days !== null ? 'text-amber-600' : 'text-slate-500'"
                  :aria-expanded="expandedSectionSettingsId === m.id"
                  @click.stop="toggleSectionSettings(m)"
                >
                  <Icon name="settings" :size="14" />
                </button>
                <button title="แก้ไข" class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-500" @click.stop="startEditModule(m)">
                  <Icon name="pencil" :size="14" />
                </button>
                <button title="ลบ" class="p-1.5 rounded-lg hover:bg-rose-50 text-rose-500" @click.stop="deleteModule(m.id)">
                  <Icon name="trash" :size="14" />
                </button>
              </div>
            </div>
          </template>

          <!--
            ADR-031 §2.2/§2.3 — the Section's release controls.

            Behind the gear (§3: "the two rarely-used ones (drip, sequential)
            belong behind the Section's settings, not inline on every row"),
            with the consequences stated plainly rather than as flag names —
            §2.2 names the cost out loud ("one lesson whose content is broken
            blocks everyone behind it") and requires the ADR-028 admin override
            to be reachable from the progress readout, so the copy points at it.
          -->
          <div
            v-if="expandedSectionSettingsId === m.id && sectionSettingsForm[m.id]"
            class="mt-3 pt-3 border-t border-slate-100"
          >
            <p class="text-xs font-bold text-slate-900">การเปิดให้เรียนของ Section นี้</p>

            <!-- TASK-152b §4 — publish state is a Section setting, so on the
                 two-pane layout it belongs in the inspector beside the other
                 two. Below `lg:` nothing changes: it stays on the edit form
                 where it has always been, and this checkbox is not rendered. -->
            <template v-if="isWideLayout">
              <label class="flex items-center gap-1.5 mt-3">
                <input v-model="sectionSettingsForm[m.id]!.is_published" type="checkbox" />
                <span class="text-xs font-bold text-slate-500">เผยแพร่ Section นี้</span>
                <!-- §4.B1 — TASK-152 §4 ruling ("a draft Section with learners
                     already in it"): unpublishing removes its lessons from the
                     denominator but never revokes a completion already
                     recorded. -->
                <InfoPopover label="เผยแพร่ Section นี้" :text="SECTION_PUBLISH_EXPLANATION" />
              </label>
            </template>

            <label class="flex items-center gap-1.5 mt-3">
              <input v-model="sectionSettingsForm[m.id]!.enforce_sequential" type="checkbox" />
              <span class="text-xs font-bold text-slate-500">บังคับเรียนตามลำดับ</span>
              <!-- §4.B1 — the 384-character block the audit named as the longest
                   on the screen. ADR-031 §2.2 requires the cost to be stated out
                   loud ("one lesson whose content is broken blocks everyone
                   behind it") and the ADR-028 override to be reachable, so all
                   of that is in SECTION_SEQUENTIAL_EXPLANATION, not trimmed. -->
              <InfoPopover label="บังคับเรียนตามลำดับ" :text="SECTION_SEQUENTIAL_EXPLANATION" />
            </label>

            <label class="text-xs font-bold text-slate-500 flex items-center gap-1 mt-3">
              หน่วงเวลาก่อนเปิดให้เรียน (วัน)
              <!-- §4.B4 — "เว้นว่าง = เปิดให้เรียนทันที" is said TWICE today: once as
                   the placeholder below and once as the first clause of the
                   paragraph. The placeholder is already the shortest possible
                   version of that sentence, so it stays and only the paragraph
                   moved (it also carried an "ADR-031 §4 ข้อ 1" citation). -->
              <InfoPopover label="หน่วงเวลาก่อนเปิดให้เรียน" :text="SECTION_DRIP_EXPLANATION" />
            </label>
            <input
              v-model="sectionSettingsForm[m.id]!.drip_days"
              type="number"
              min="0"
              max="3650"
              placeholder="เว้นว่าง = เปิดให้เรียนทันที"
              class="mt-1 w-full sm:max-w-xs px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
            />

            <p v-if="sectionSettingsError" class="mt-2 text-xs font-bold text-rose-600">{{ sectionSettingsError }}</p>
            <div class="mt-3 flex items-center gap-2">
              <button class="btn-primary" :disabled="savingSectionSettingsFor === m.id" @click.stop="saveSectionSettings(m)">
                {{ savingSectionSettingsFor === m.id ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
              </button>
              <span v-if="sectionSettingsSavedFor === m.id" class="text-[11px] font-bold text-emerald-600">บันทึกแล้ว</span>
            </div>
          </div>

          <!-- Lessons panel. In wide mode it is always open — the panel IS the
               inspector, and hiding it behind the same disclosure the outline
               replaced would put the accordion back. -->
          <div v-if="expandedModuleId === m.id || isWideLayout" class="mt-3 pt-3 border-t border-slate-100">
            <p v-if="lessonError[m.id]" class="mb-2 text-xs font-bold text-rose-600">{{ lessonError[m.id] }}</p>

            <EmptyState v-if="!m.lessons.length" icon="book" title="ยังไม่มีบทเรียนใน Section นี้" class="mb-2" />
            <!-- `inspectorLessons()` is every lesson in narrow mode and only the
                 selected one in wide mode, so this block renders once either
                 way — there is no second copy of a lesson's settings. -->
            <template v-else-if="inspectorLessons(m).length">
            <!-- §4.B3 — third and last copy of the ordering explanation. -->
            <p
              v-if="!isWideLayout && m.lessons.length > 1"
              class="text-xs font-bold text-slate-500 mb-1.5 flex items-center gap-1"
            >
              บทเรียนใน Section นี้
              <InfoPopover label="การจัดลำดับบทเรียน" :text="DRAG_REORDER_EXPLANATION" />
            </p>
            <TransitionGroup tag="div" name="list-fade" class="space-y-2 mb-3">
              <div
                v-for="l in inspectorLessons(m)"
                :key="l.id"
                class="p-3 rounded-lg bg-slate-50/60 border transition-colors"
                :class="[
                  dragOverLessonId === l.id ? 'border-brand-400 bg-brand-50/50' : 'border-slate-100',
                  draggingLessonId === l.id ? 'opacity-50' : '',
                ]"
                :draggable="armedLessonId === l.id"
                @dragstart="onLessonDragStart(l, $event)"
                @dragover="onLessonDragOver(m, l, $event)"
                @dragleave="dragOverLessonId = dragOverLessonId === l.id ? null : dragOverLessonId"
                @drop.prevent.stop="onLessonDrop(m, l)"
                @dragend="endLessonDrag"
              >
                <!-- Lesson edit form -->
                <template v-if="editingLessonId === l.id">
                  <div class="grid grid-cols-2 gap-2.5">
                    <div class="col-span-2">
                      <label class="text-xs font-bold text-slate-500">ชื่อบทเรียน</label>
                      <input v-model="editLessonForm.title" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
                    </div>

                    <!-- TASK-188 Phase D — the control that did not exist. The
                         create form has always had this select; the edit form
                         had no `content_type` at all, so a wrong choice could
                         only be undone by deleting the lesson (and its
                         learners' progress). -->
                    <div class="col-span-2">
                      <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
                        ประเภทเนื้อหา
                        <InfoPopover
                          label="ประเภทเนื้อหา"
                          :text="RETYPE_CONTENT_TYPE_EXPLANATION"
                        />
                      </label>
                      <select
                        v-model="editLessonForm.content_type"
                        data-test="edit-lesson-content-type"
                        class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
                      >
                        <option value="video">วิดีโอ</option>
                        <option value="pdf">PDF</option>
                        <option value="image">รูปภาพ</option>
                        <option value="quiz">แบบทดสอบท้ายบท</option>
                        <option value="link">ลิงก์</option>
                      </select>
                    </div>

                    <!-- The new type's own content spec. The API requires it
                         COMPLETE in the same request, so the fields for the OLD
                         type are replaced rather than shown alongside. -->
                    <div v-if="isRetypingLesson" class="col-span-2 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                      <p class="text-xs font-bold text-slate-900">
                        เนื้อหาใหม่สำหรับประเภท “{{ contentTypeLabel(editLessonForm.content_type) }}”
                      </p>

                      <div v-if="isUploadableType(editLessonForm.content_type)" class="mt-2 flex gap-3">
                        <label class="flex items-center gap-1.5 text-xs">
                          <input v-model="editLessonForm.source_type" type="radio" value="embed" />
                          {{ editLessonForm.content_type === 'video' ? 'ลิงก์ iframe/embed' : 'ลิงก์ภายนอก' }}
                        </label>
                        <label class="flex items-center gap-1.5 text-xs">
                          <input v-model="editLessonForm.source_type" type="radio" value="upload" /> อัปโหลดไฟล์
                        </label>
                      </div>

                      <template v-if="retypeNeedsFile">
                        <label class="text-xs font-bold text-slate-500 flex items-center gap-1 mt-2">
                          ไฟล์ใหม่
                          <!-- §4.B1 — the accepted-formats hint was a permanent
                               grey line under the picker. -->
                          <InfoPopover label="ไฟล์ใหม่" :text="hintFor(editLessonForm.content_type)" />
                        </label>
                        <input
                          type="file"
                          :accept="acceptFor(editLessonForm.content_type)"
                          class="mt-1 w-full text-xs"
                          @change="onEditLessonFileChange"
                        />
                      </template>

                      <template v-else-if="retypeNeedsUrl">
                        <label class="text-xs font-bold text-slate-500 flex items-center gap-1 mt-2">
                          ลิงก์เนื้อหาใหม่
                          <InfoPopover
                            v-if="isFramedLesson(editLessonForm.content_type, editLessonForm.source_type)"
                            label="ลิงก์เนื้อหาใหม่"
                            :text="
                              embedUrlExplanation({
                                rewritten: embedUrlWasRewritten(editLessonForm.content_ref),
                                mayNotDisplay: embedUrlMayNotDisplay(editLessonForm.content_ref),
                              })
                            "
                          />
                        </label>
                        <input
                          v-model="editLessonForm.content_ref"
                          type="url"
                          data-test="retype-content-ref"
                          class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
                        />
                      </template>

                      <p v-else class="mt-2 text-xs text-slate-500">
                        แบบทดสอบท้ายบทไม่ต้องแนบไฟล์หรือลิงก์
                      </p>

                      <!-- §4.B5 — a computed value, not an explanation: it is
                           the URL the learner's iframe will actually load. -->
                      <p
                        v-if="
                          retypeNeedsUrl &&
                          isFramedLesson(editLessonForm.content_type, editLessonForm.source_type) &&
                          embedUrlInUse(editLessonForm.content_ref)
                        "
                        class="text-[11px] text-slate-500 mt-1 leading-relaxed break-all"
                      >
                        ลิงก์ที่ระบบจะใช้แสดงในบทเรียน:
                        <span class="font-bold text-slate-700">{{ embedUrlInUse(editLessonForm.content_ref) }}</span>
                      </p>

                      <!-- §4.B5 — an error stays visible. -->
                      <p v-if="retypeError" class="mt-2 text-xs font-bold text-rose-600">{{ retypeError }}</p>
                    </div>

                    <!-- URL-backed lesson (external pdf/image/link, or an
                         embedded video). An UPLOADED lesson has no editable
                         content_ref: the server owns that path (§5 rule 6),
                         and the API prohibits the field for uploads.
                         Suppressed during a retype: the block above owns the
                         content spec then. -->
                    <div
                      v-if="!isRetypingLesson && l.content_type !== 'quiz' && l.source_type !== 'upload'"
                      class="col-span-2"
                    >
                      <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
                        {{ l.content_type === 'video' ? 'ลิงก์ embed' : 'ลิงก์เนื้อหา' }}
                        <!-- §4.B1/B3 — the two authoring-help paragraphs (one of
                             which had already drifted from the create form's
                             copy) now read from embedUrlExplanation(). -->
                        <InfoPopover
                          v-if="isFramedLesson(l.content_type, l.source_type ?? '')"
                          label="ลิงก์ embed"
                          :text="
                            embedUrlExplanation({
                              rewritten: embedUrlWasRewritten(editLessonForm.content_ref),
                              mayNotDisplay: embedUrlMayNotDisplay(editLessonForm.content_ref),
                            })
                          "
                        />
                      </label>
                      <input v-model="editLessonForm.content_ref" type="url" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />

                      <!-- §4.B5 — the URL the learner's iframe will load is a
                           COMPUTED VALUE and stays on screen. -->
                      <p
                        v-if="isFramedLesson(l.content_type, l.source_type ?? '') && embedUrlInUse(editLessonForm.content_ref)"
                        class="text-[11px] text-slate-500 mt-1 leading-relaxed break-all"
                      >
                        ลิงก์ที่ระบบจะใช้แสดงในบทเรียน:
                        <span class="font-bold text-slate-700">{{ embedUrlInUse(editLessonForm.content_ref) }}</span>
                      </p>
                    </div>

                    <!-- ADR-028 §2.1 — the in-place file replace now covers an
                         uploaded PDF or image as well as a video. -->
                    <div v-if="!isRetypingLesson && l.source_type === 'upload' && isUploadableType(l.content_type)" class="col-span-2">
                      <label class="text-xs font-bold text-slate-500">ไฟล์ปัจจุบัน</label>
                      <p v-if="l.content_type === 'video' && moduleProcessingLabel(l.processing_status)" class="text-xs font-bold text-amber-600 mt-1">
                        {{ moduleProcessingLabel(l.processing_status) }}
                      </p>
                      <AuthenticatedMedia
                        v-if="l.content_type === 'video' && l.inline_url"
                        :src="l.inline_url"
                        type="video"
                        class="mt-1 w-full max-w-xs rounded-lg"
                      />
                      <AuthenticatedMedia
                        v-else-if="l.content_type === 'image' && l.inline_url"
                        :src="l.inline_url"
                        type="image"
                        class="mt-1 w-full max-w-xs rounded-lg"
                      />
                      <p v-else-if="l.content_type === 'pdf'" class="text-xs text-slate-500 mt-1">
                        เอกสาร PDF<span v-if="l.page_count"> · {{ l.page_count }} หน้า</span>
                      </p>

                      <div class="mt-2 flex items-center gap-1">
                        <input type="file" :accept="acceptFor(l.content_type)" class="w-full text-xs" @change="onEditLessonFileChange" />
                        <!-- §4.B1 — was a permanent grey "รองรับ .mp4 …" line. -->
                        <InfoPopover label="ไฟล์ที่รองรับ" :text="hintFor(l.content_type)" />
                      </div>
                      <!-- §4.B5 — WHICH file is about to replace the current one
                           is data. It stays. -->
                      <p v-if="editLessonVideoFile" class="text-xs text-brand-600 mt-1">
                        จะแทนที่ด้วย: {{ editLessonVideoFile.name }}<span v-if="l.content_type === 'video'"> — ต้องประมวลผลใหม่หลังบันทึก</span>
                      </p>

                      <label class="flex items-center gap-1.5 mt-3">
                        <input v-model="editLessonForm.is_downloadable" type="checkbox" />
                        <span class="text-xs font-bold text-slate-500">อนุญาตให้ผู้เรียนดาวน์โหลดไฟล์นี้</span>
                        <!-- §4.B1/B3 — ADR-028 §2.2. This form's copy was the
                             SHORTER of the two: it omitted the sentence about
                             the file being on the reader's machine and the
                             ดู/อ่านให้ครบ gate interaction, which is exactly what
                             flipping the flag here changes. Both are in
                             DOWNLOADABLE_EXPLANATION now, for both call sites. -->
                        <InfoPopover
                          label="อนุญาตให้ผู้เรียนดาวน์โหลดไฟล์นี้"
                          :text="DOWNLOADABLE_EXPLANATION"
                        />
                      </label>

                      <div v-if="editLessonUploadProgress > 0" class="mt-2 flex items-center gap-2">
                        <div class="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                          <div class="h-full rounded-full bg-brand-600 transition-all duration-150" :style="{ width: `${Math.round(editLessonUploadProgress * 100)}%` }"></div>
                        </div>
                        <span class="text-[11px] font-bold text-slate-500 tabular-nums shrink-0">{{ Math.round(editLessonUploadProgress * 100) }}%</span>
                        <button type="button" class="text-[11px] font-bold text-rose-600 shrink-0" @click="cancelEditLessonUpload">ยกเลิก</button>
                      </div>
                    </div>
                    <div>
                      <label class="text-xs font-bold text-slate-500">ลำดับการแสดง</label>
                      <input v-model="editLessonForm.sort_order" type="number" min="0" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
                    </div>
                    <div>
                      <label class="text-xs font-bold text-slate-500">XP ที่ได้รับ</label>
                      <input v-model="editLessonForm.xp_reward" type="number" min="0" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
                    </div>
                    <div class="col-span-2 flex items-center gap-1.5">
                      <input id="edit-lesson-is-published" v-model="editLessonForm.is_published" type="checkbox" />
                      <label for="edit-lesson-is-published" class="text-xs font-bold text-slate-500">เผยแพร่แล้ว</label>
                    </div>
                    <div class="col-span-2">
                      <label class="flex items-center gap-1.5">
                        <input id="edit-lesson-is-optional" v-model="editLessonForm.is_optional" type="checkbox" />
                        <span class="text-xs font-bold text-slate-500">เป็นบทเสริม (ไม่บังคับเรียน)</span>
                        <!-- §4.B1/B3 — ADR-031 §2.4, "shown, not counted". The
                             first of the two identical copies of this
                             explanation; both now read
                             OPTIONAL_LESSON_EXPLANATION. -->
                        <InfoPopover
                          label="เป็นบทเสริม (ไม่บังคับเรียน)"
                          :text="OPTIONAL_LESSON_EXPLANATION"
                        />
                      </label>
                    </div>
                    <div class="col-span-2 flex justify-end gap-2">
                      <button class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200" @click="cancelEditLesson">ยกเลิก</button>
                      <!-- TASK-188 Phase D — a plain save goes straight through;
                           a retype reads the impact and stops at the dialog. -->
                      <button
                        class="btn-primary"
                        data-test="edit-lesson-save"
                        :disabled="loadingRetypeImpact"
                        @click="requestSaveEditLesson(l.id)"
                      >
                        {{ loadingRetypeImpact ? 'กำลังตรวจสอบผลกระทบ...' : 'บันทึก' }}
                      </button>
                    </div>
                  </div>
                </template>

                <!-- Lesson row -->
                <template v-else>
                  <div class="flex items-center justify-between gap-2">
                    <!-- ADR-031 §2.1 — same arm-on-handle pattern as the
                         Section row: the lesson card is only `draggable` while
                         this handle is held. -->
                    <span
                      v-if="!isWideLayout"
                      class="shrink-0 p-1.5 -m-1 rounded-lg text-slate-300 hover:text-slate-500 cursor-grab active:cursor-grabbing"
                      :title="DRAG_LESSON_HANDLE_TITLE"
                      aria-hidden="true"
                      @mousedown.stop="armedLessonId = l.id"
                      @mouseup="armedLessonId = null"
                      @touchstart.stop="armedLessonId = l.id"
                    >
                      <Icon name="list" :size="13" />
                    </span>
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-bold text-slate-700">
                        {{ l.title }}
                        <!-- ADR-031 §2.4 — say what "optional" MEANS, not just
                             that the flag is on: it is outside the progress
                             denominator and never blocks the next lesson. -->
                        <span
                          v-if="l.is_optional"
                          class="ml-1.5 align-middle px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold"
                          :title="OPTIONAL_LESSON_PILL_TITLE"
                        >บทเสริม</span>
                      </p>
                      <p class="text-xs text-slate-400">
                        {{ l.content_type
                        }}<span v-if="isUploadableType(l.content_type)"> ({{ l.source_type === 'upload' ? 'อัปโหลด' : 'ลิงก์ภายนอก' }})</span>
                        · ลำดับ {{ l.sort_order }} · {{ l.xp_reward }} XP
                        · <span :class="l.is_published ? 'text-emerald-600 font-bold' : ''">{{ l.is_published ? 'เผยแพร่แล้ว' : 'ฉบับร่าง' }}</span>
                        <!-- ADR-028 §2.2 — say what the flag DOES, not what it
                             is often assumed to do. -->
                        <span v-if="l.source_type === 'upload' && l.is_downloadable"> · ดาวน์โหลดได้</span>
                        <span v-if="l.content_type === 'pdf' && l.page_count"> · {{ l.page_count }} หน้า</span>
                        <!-- ADR-030 §2.1 — WHICH quiz this lesson holds. A
                             quiz typed in place is named after the lesson
                             (QuizService::ensureForLesson), so a name that
                             differs from the lesson title is how an admin
                             tells a library quiz from an in-place one at a
                             glance. -->
                        <span v-if="l.quiz"> · แบบทดสอบ: “{{ l.quiz.title }}”</span>
                      </p>
                      <p v-if="l.content_type === 'video' && moduleProcessingLabel(l.processing_status)" class="text-xs font-bold text-amber-600 mt-0.5">
                        {{ moduleProcessingLabel(l.processing_status) }}
                      </p>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                      <!-- The "ดูตัวอย่าง" button that used to sit here was
                           REMOVED (human request 2026-08-09): the preview is
                           now the always-visible strip below the row, and the
                           strip itself opens the enlarged modal. Two controls
                           opening the same modal reads as two different
                           actions. -->
                      <!-- ADR-029 §2.1 — NO `content_type === 'quiz'` gate
                           any more: a video or PDF lesson may carry an
                           end-of-lesson quiz, and this button is the only
                           way to author one. A standalone quiz lesson still
                           reaches the same panel, unchanged. -->
                      <button
                        class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                        @click="toggleLessonQuiz(l)"
                      >
                        <Icon name="check_square" :size="13" />
                        {{ expandedQuizLessonId === l.id ? 'ซ่อนแบบทดสอบ' : 'แบบทดสอบท้ายบท' }}
                        <span v-if="l.quiz_question_count" class="text-slate-400">({{ l.quiz_question_count }})</span>
                      </button>
                      <!-- ADR-029 §2.5 — the attempts readout. Only offered
                           once a quiz exists: an empty table on a lesson
                           with no questions says nothing. -->
                      <button
                        v-if="l.quiz_question_count > 0"
                        class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                        @click="toggleQuizAttempts(l)"
                      >
                        <Icon name="list" :size="13" />
                        {{ expandedAttemptsLessonId === l.id ? 'ซ่อนผลแบบทดสอบ' : 'ผลแบบทดสอบผู้เรียน' }}
                      </button>
                      <!-- ADR-028 §4 — the support readout. Only meaningful for
                           the two types that carry positional progress. -->
                      <button
                        v-if="l.source_type === 'upload' && (l.content_type === 'video' || l.content_type === 'pdf')"
                        class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                        @click="toggleLessonProgress(l)"
                      >
                        <Icon name="chart" :size="13" />
                        {{ expandedProgressLessonId === l.id ? 'ซ่อนความคืบหน้า' : 'ความคืบหน้าผู้เรียน' }}
                      </button>
                      <button title="แก้ไข" data-test="edit-lesson" class="text-slate-400 hover:text-brand-600" @click="startEditLesson(l)">
                        <Icon name="pencil" :size="15" />
                      </button>
                      <button title="ลบ" class="text-rose-600 hover:text-rose-700" @click="deleteLesson(l.id)">
                        <Icon name="trash" :size="15" />
                      </button>
                    </div>
                  </div>

                  <!-- Inline 120px preview (human request 2026-08-09), for
                       EVERY content type including a lesson with no file yet —
                       "there is nothing here" is exactly what an admin needs to
                       see before publishing, and it is now visible without a
                       click at all.

                       This REPLACES the three eager media elements that used to
                       sit here (an uploaded video/image via AuthenticatedMedia,
                       and an embed link). That old block pulled the ENTIRE
                       video file into a blob for every uploaded-video lesson
                       the moment a section expanded; the strip renders one
                       frame, and only once it is scrolled into view.

                       An upload still streams through the sanctum-protected
                       route (ADR-007/ADR-028 §2.1) from inline_url, never
                       stream_url (which switches to Content-Disposition:
                       attachment once the file is downloadable). -->
                  <div class="mt-2">
                    <LessonPreviewStrip :lesson="l" @open="openLessonPreview(l)" />
                  </div>

                  <!-- ── TASK-152b §4 — the lesson's settings, in the inspector ──
                       Wide mode only: below `lg:` these live where they always
                       have (the edit form / the company thresholds panel), and
                       nothing about the accordion changes.

                       The two READ-ONLY lines are read-only because there is no
                       per-lesson watch/read threshold in the schema — the gate
                       is `academy_completion_settings` per COMPANY (BR-7,
                       ADR-028 §4), and inventing a per-lesson field to fill a
                       row in a layout would be inventing a business rule. The
                       editable pair saves through the SAME saveEditLesson() the
                       full edit form uses, on an explicit button (§4 ruling —
                       never on change). -->
                  <div v-if="isWideLayout" class="mt-3 pt-3 border-t border-slate-200">
                    <p class="text-xs font-bold text-slate-900">การตั้งค่าบทเรียนนี้</p>
                    <div class="mt-2 space-y-1.5">
                      <div class="flex items-start gap-2">
                        <span class="w-24 shrink-0 text-[11px] font-bold text-slate-400">ประเภทเนื้อหา</span>
                        <span class="min-w-0 text-[11px] text-slate-600">
                          {{ contentTypeLabel(l.content_type)
                          }}<span v-if="isUploadableType(l.content_type)"> · {{ l.source_type === 'upload' ? 'อัปโหลด' : 'ลิงก์ภายนอก' }}</span><span
                            v-if="l.content_type === 'pdf' && l.page_count"
                          > · {{ l.page_count }} หน้า</span>
                        </span>
                      </div>
                      <div class="flex items-start gap-2">
                        <span class="w-24 shrink-0 text-[11px] font-bold text-slate-400">เกณฑ์ดู/อ่าน</span>
                        <span class="min-w-0 text-[11px] text-slate-600 leading-relaxed flex items-start gap-1">
                          <!-- §4.B5 — lessonGateSummary() is the lesson's ACTUAL
                               gate, computed from the company settings. Data,
                               so it stays. Only the trailing "where to change
                               it" sentence moved. -->
                          <span class="min-w-0">{{ lessonGateSummary(l) }}</span>
                          <InfoPopover
                            label="เกณฑ์ดู/อ่านของบทเรียนนี้"
                            :text="LESSON_GATE_IS_COMPANY_LEVEL_EXPLANATION"
                          />
                        </span>
                      </div>
                    </div>

                    <label class="flex items-center gap-1.5 mt-3">
                      <input v-model="editLessonForm.is_published" type="checkbox" />
                      <span class="text-xs font-bold text-slate-500">เผยแพร่บทเรียนนี้</span>
                    </label>
                    <label class="flex items-center gap-1.5 mt-2">
                      <input v-model="editLessonForm.is_optional" type="checkbox" />
                      <span class="text-xs font-bold text-slate-500">เป็นบทเสริม (ไม่บังคับเรียน)</span>
                      <!-- §4.B3 — the SECOND copy of the บทเสริม explanation. It
                           was already worded differently from the edit form's;
                           both now read OPTIONAL_LESSON_EXPLANATION. -->
                      <InfoPopover
                        label="เป็นบทเสริม (ไม่บังคับเรียน)"
                        :text="OPTIONAL_LESSON_EXPLANATION"
                      />
                    </label>
                    <div class="mt-3 flex items-center gap-1">
                      <button class="btn-primary" @click="saveEditLesson(l.id)">บันทึกการตั้งค่าบทเรียน</button>
                      <!-- §4.B1 — was a grey line beside the button. -->
                      <InfoPopover
                        label="ขอบเขตของแผงตั้งค่านี้"
                        :text="INSPECTOR_SCOPE_EXPLANATION"
                      />
                    </div>
                  </div>

                  <!-- ── Recorded learner progress (ADR-028 §4) ──────────
                       The learner is never shown these numbers; support
                       must see them, or "I finished it, the button won't
                       work" is an unresolvable ticket. Rows exist only for
                       learners who have actually opened the lesson. -->
                  <div v-if="expandedProgressLessonId === l.id" class="mt-3 pt-3 border-t border-slate-200">
                    <p class="text-xs font-bold text-slate-900 mb-2 flex items-center gap-1">
                      ความคืบหน้าผู้เรียน
                      <!-- §4.B1/B2 — was a paragraph carrying an "(ADR-028)". -->
                      <InfoPopover label="ความคืบหน้าผู้เรียน" :text="LESSON_PROGRESS_EXPLANATION" />
                    </p>
                    <p v-if="loadingLessonProgressFor === l.id" class="text-xs text-slate-400">กำลังโหลด...</p>
                    <p v-else-if="lessonProgressError" class="text-xs font-bold text-rose-600">{{ lessonProgressError }}</p>
                    <EmptyState v-else-if="!lessonProgressRows[l.id]?.length" icon="users" title="ยังไม่มีผู้เรียนเปิดบทเรียนนี้" />
                    <div v-else class="space-y-1.5">
                      <div
                        v-for="row in lessonProgressRows[l.id]"
                        :key="row.id"
                        class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg bg-white border border-slate-100"
                      >
                        <div class="min-w-0">
                          <p class="text-xs font-bold text-slate-700 truncate">{{ progressRowName(row) }}</p>
                          <p class="text-[11px] text-slate-400">
                            <template v-if="l.content_type === 'video'">
                              สูงสุด {{ formatSeconds(row.max_position_seconds) }} / {{ formatSeconds(l.duration_seconds) }}
                              · ล่าสุด {{ formatSeconds(row.last_position_seconds) }}
                            </template>
                            <template v-else>
                              สูงสุด หน้า {{ row.max_page ?? '—' }} / {{ l.page_count ?? row.total_pages ?? '—' }}
                              · ล่าสุด หน้า {{ row.last_page ?? '—' }}
                            </template>
                            · อัปเดต {{ formatDateTime(row.updated_at) }}
                          </p>
                        </div>
                        <button
                          class="shrink-0 px-2.5 py-1 rounded-lg border border-slate-200 text-[11px] font-bold text-slate-600 hover:border-brand-400 hover:text-brand-600"
                          @click="requestCompletionOverride(l, row)"
                        >
                          ทำเครื่องหมายว่าเรียนจบให้
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- ── ADR-029 §2.5 — attempts readout (score only) ────
                       "Every attempt is still recorded, so the admin can see
                       someone who took eleven tries."

                       There is deliberately NO way to see which options a
                       learner picked: §4 item 2 is unresolved and
                       PDPA-adjacent, and the chosen answers are not stored
                       at all, so a UI implying otherwise would be a promise
                       the schema cannot keep. -->
                  <div v-if="expandedAttemptsLessonId === l.id" class="mt-3 pt-3 border-t border-slate-200">
                    <p class="text-xs font-bold text-slate-900 mb-2 flex items-center gap-1">
                      ผลแบบทดสอบผู้เรียน
                      <!-- §4.B1/B2 — was a paragraph ending "(ADR-029 §4)". -->
                      <InfoPopover label="ผลแบบทดสอบผู้เรียน" :text="QUIZ_ATTEMPTS_EXPLANATION" />
                    </p>
                    <p v-if="loadingQuizAttemptsFor === l.id" class="text-xs text-slate-400">กำลังโหลด...</p>
                    <p v-else-if="quizAttemptsError" class="text-xs font-bold text-rose-600">{{ quizAttemptsError }}</p>
                    <EmptyState v-else-if="!quizAttemptRows[l.id]?.length" icon="users" title="ยังไม่มีผู้เรียนทำแบบทดสอบนี้" />
                    <div v-else class="space-y-1.5">
                      <div
                        v-for="row in quizAttemptRows[l.id]"
                        :key="row.id"
                        class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg bg-white border border-slate-100"
                      >
                        <div class="min-w-0">
                          <p class="text-xs font-bold text-slate-700 truncate">{{ attemptRowName(row) }}</p>
                          <!-- score is a COUNT of correct answers, not a
                               percentage — stated in words so nobody reads
                               "3" as "3%". -->
                          <p class="text-[11px] text-slate-400">
                            ตอบถูก {{ row.score }} จาก {{ row.total_questions }} ข้อ · {{ formatDateTime(row.attempted_at) }}
                          </p>
                        </div>
                        <span class="shrink-0 text-[11px] font-bold" :class="row.passed ? 'text-emerald-600' : 'text-rose-600'">
                          {{ row.passed ? 'ผ่าน' : 'ไม่ผ่าน' }}
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- ── Lesson quiz authoring panel (ADR-009 + ADR-029) ──
                       Reachable for EVERY content type now (§2.1). -->
                  <div v-if="expandedQuizLessonId === l.id" class="mt-3 pt-3 border-t border-slate-200">
                    <p v-if="quizError" class="mb-2 text-xs font-bold text-rose-600">{{ quizError }}</p>

                    <!--
                      ADR-029 §2.4/§2.6 — the two per-lesson controls, behind a
                      gear (human, 2026-08-09: "ใช้ไม่บ่อย ปรับเป็นรูปเฟือง").

                      These are set once when the lesson is authored and then
                      almost never touched, but the panel is tall (a number
                      field, a checkbox, four lines of explanation and a Save
                      button) and it sat permanently between the admin and the
                      questions they actually came here to edit. Collapsed by
                      default; the summary line still states the two values, so
                      hiding the form does not hide the CONFIGURATION — an admin
                      can see the lesson blocks completion at 80% without
                      opening anything.
                    -->
                    <div v-if="quizSettingsForm[l.id]" class="mb-3">
                      <button
                        class="w-full flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-slate-200 hover:bg-slate-50 text-left"
                        :aria-expanded="expandedQuizSettingsLessonId === l.id"
                        @click="expandedQuizSettingsLessonId = expandedQuizSettingsLessonId === l.id ? null : l.id"
                      >
                        <Icon name="settings" :size="14" class="text-slate-400 shrink-0" />
                        <span class="min-w-0 flex-1">
                          <span class="text-xs font-bold text-slate-900 block">การตั้งค่าแบบทดสอบของบทเรียนนี้</span>
                          <span class="text-[11px] text-slate-500 block truncate">
                            เกณฑ์ผ่าน
                            {{ quizSettingsForm[l.id]!.quiz_pass_percent === '' ? 'ตามค่าของบริษัท' : quizSettingsForm[l.id]!.quiz_pass_percent + '%' }}
                            ·
                            {{ quizSettingsForm[l.id]!.quiz_blocks_completion ? 'ต้องทำให้ผ่านจึงจะเรียนจบได้' : 'ไม่บังคับต้องผ่าน' }}
                          </span>
                        </span>
                        <Icon
                          :name="expandedQuizSettingsLessonId === l.id ? 'chevron_up' : 'chevron_down'"
                          :size="14"
                          class="text-slate-400 shrink-0"
                        />
                      </button>
                    </div>

                    <div
                      v-if="quizSettingsForm[l.id] && expandedQuizSettingsLessonId === l.id"
                      class="mb-3 p-3 rounded-lg bg-white border border-slate-200"
                    >

                      <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
                        เกณฑ์ผ่าน (%)
                        <!-- §4.B4 — "เว้นว่าง = ใช้ค่าของบริษัท" duplicates the
                             field's own placeholder, so the placeholder stays
                             and the sentence moved. §4.B2 — the second
                             paragraph closed with "(ADR-029 §2.7)". -->
                        <InfoPopover
                          label="เกณฑ์ผ่านของบทเรียนนี้"
                          :text="LESSON_QUIZ_PASS_PERCENT_EXPLANATION"
                        />
                      </label>
                      <input
                        v-model="quizSettingsForm[l.id]!.quiz_pass_percent"
                        type="number"
                        min="1"
                        max="100"
                        placeholder="ใช้ค่าของบริษัท"
                        class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
                      />
                      <!-- §4.B5 — BOTH numbers below are COMPUTED VALUES, and
                           the third branch is an ERROR. All three stay visible:
                           "ค่าที่ใช้จริงกับบทเรียนนี้: 80%" is the answer to the
                           question the admin came here with, not a description
                           of how the field works. -->
                      <p class="text-[11px] text-slate-500 mt-1 leading-relaxed">
                        <span v-if="completionSettings">ค่าของบริษัทตอนนี้: {{ completionSettings.quiz_pass_percent }}% · </span>
                        <span v-else-if="completionSettingsError" class="text-rose-600 font-bold">ยังอ่านค่าของบริษัทไม่ได้: {{ completionSettingsError }} · </span>
                        ค่าที่ใช้จริงกับบทเรียนนี้:
                        <strong class="font-bold text-slate-700">{{ effectivePassPercent(l) !== null ? effectivePassPercent(l) + '%' : '—' }}</strong>
                      </p>

                      <label class="flex items-center gap-1.5 mt-3">
                        <input v-model="quizSettingsForm[l.id]!.quiz_blocks_completion" type="checkbox" />
                        <span class="text-xs font-bold text-slate-500">ต้องทำแบบทดสอบให้ผ่าน จึงจะกดเรียนจบบทเรียนนี้ได้</span>
                        <!-- §4.B1/B2 — ADR-029 §2.6 + §3 require the cost of
                             turning this on to be stated plainly, including
                             that it sits on the certification path. All of it
                             is in QUIZ_BLOCKS_COMPLETION_EXPLANATION; only the
                             "(BR-1)" citation was dropped. -->
                        <InfoPopover
                          label="ต้องทำแบบทดสอบให้ผ่านจึงจะเรียนจบได้"
                          :text="QUIZ_BLOCKS_COMPLETION_EXPLANATION"
                        />
                      </label>

                      <div class="mt-2 flex items-center gap-2">
                        <button
                          class="btn-primary"
                          :disabled="savingQuizSettingsFor === l.id"
                          @click="saveQuizSettings(l.id)"
                        >
                          {{ savingQuizSettingsFor === l.id ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
                        </button>
                        <span v-if="quizSettingsSavedFor === l.id" class="text-[11px] font-bold text-emerald-600">บันทึกแล้ว</span>
                      </div>
                    </div>

                    <!-- ── ADR-030 §2.3/§2.5 — where this lesson's questions
                         come from. ONE LINE, deliberately: §3 keeps "create a
                         new quiz right here" as the default path, so the
                         library must not sit between the admin and the
                         "เพิ่มคำถามใหม่" box below. Typing a question still
                         creates and attaches a quiz by itself. -->
                    <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs">
                      <span v-if="l.quiz" class="text-slate-500 min-w-0">
                        ชุดคำถาม: <strong class="font-bold text-slate-700 break-words">{{ l.quiz.title }}</strong>
                      </span>
                      <!-- §4.B5 — "ยังไม่มีชุดคำถาม" is STATE. The instruction
                           that followed it moved behind the ⓘ. -->
                      <span v-else class="text-slate-400">ยังไม่มีชุดคำถาม</span>
                      <InfoPopover label="ชุดคำถามของบทเรียนนี้" :text="QUIZ_SOURCE_EXPLANATION" />
                      <button
                        class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1 shrink-0"
                        @click="toggleQuizPicker(l)"
                      >
                        <Icon name="layers" :size="12" />
                        {{ showQuizPickerFor === l.id ? 'ปิดรายการคลัง' : 'เลือกจากคลัง' }}
                      </button>
                      <button
                        v-if="l.quiz_id !== null"
                        class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600 hover:border-rose-300 hover:text-rose-600 shrink-0"
                        @click="requestDetachQuiz(l)"
                      >
                        ยกเลิกการเชื่อมโยง
                      </button>
                    </div>

                    <!-- The picker. Everything it offers came from
                         GET /module-lessons/{lesson}/available-quizzes — see
                         availableQuizzes' docblock for why nothing is
                         filtered here. -->
                    <div v-if="showQuizPickerFor === l.id" class="mb-3 p-3 rounded-lg bg-white border border-slate-200">
                      <p class="text-xs font-bold text-slate-900 mb-2 flex items-center gap-1">
                        ชุดคำถามในคลัง
                        <!-- §4.B1/B2 — was a paragraph ending "(ADR-030 §2.5)". -->
                        <InfoPopover label="ชุดคำถามในคลัง" :text="QUIZ_PICKER_EXPLANATION" />
                      </p>
                      <p v-if="loadingAvailableFor === l.id" class="text-xs text-slate-400">กำลังโหลด...</p>
                      <p v-else-if="availableQuizzesError" class="text-xs font-bold text-rose-600">{{ availableQuizzesError }}</p>
                      <EmptyState
                        v-else-if="!availableQuizzes[l.id]?.length"
                        icon="layers"
                        title="ไม่มีชุดว่างในคลัง"
                        message="สร้างเตรียมไว้ล่วงหน้าได้ที่แท็บ “แบบทดสอบท้ายบทเรียน” หรือพิมพ์คำถามด้านล่างเพื่อสร้างใหม่ที่นี่"
                      />
                      <div v-else class="space-y-1.5">
                        <button
                          v-for="q in availableQuizzes[l.id]"
                          :key="q.id"
                          :disabled="attachingQuizId !== null || q.id === l.quiz_id"
                          class="w-full flex flex-wrap items-center justify-between gap-2 px-3 py-2 rounded-lg border text-left transition-colors"
                          :class="q.id === l.quiz_id ? 'border-emerald-200 bg-emerald-50 cursor-default' : 'border-slate-100 bg-white hover:border-brand-400 disabled:opacity-50'"
                          @click="attachQuiz(l, q.id)"
                        >
                          <span class="min-w-0">
                            <span class="block text-xs font-bold text-slate-700 break-words">{{ q.title }}</span>
                            <span class="block text-[11px] text-slate-400">{{ q.question_count }} คำถาม</span>
                          </span>
                          <span v-if="q.id === l.quiz_id" class="shrink-0 text-[11px] font-bold text-emerald-600">ใช้อยู่ตอนนี้</span>
                          <span v-else class="shrink-0 text-[11px] font-bold text-brand-600">
                            {{ attachingQuizId === q.id ? 'กำลังเชื่อมโยง...' : 'เลือกชุดนี้' }}
                          </span>
                        </button>
                      </div>
                    </div>

                    <!-- The question/option editor — the SAME component the
                         library tab uses (extracted, not forked). -->
                    <QuizQuestionEditor
                      :questions="l.quiz_questions ?? []"
                      :add-question-path="`/module-lessons/${l.id}/quiz-questions`"
                      :reload="reloadOpenLessonQuiz"
                    />
                  </div>
                </template>
              </div>
            </TransitionGroup>
            </template>

            <!--
              TASK-188 §5.C1 — the `v-if="!isWideLayout || !selectedLesson"` that
              used to wrap this block is GONE. That condition hid the only
              add-lesson button on the screen whenever a lesson was selected,
              which is precisely the state the human was in when they reported
              they could not find it ("ผมหาปุ่มไม่เจอในการเพิ่มบทเรียน"). A Section
              always accepts another lesson, whatever the inspector is showing.
            -->
            <div class="flex justify-end mb-2">
              <button
                class="px-2.5 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700"
                data-test="add-lesson-section"
                @click="toggleLessonForm(m.id)"
              >
                + เพิ่มบทเรียน
              </button>
            </div>
            <form v-if="showLessonForm[m.id]" class="p-3 rounded-lg bg-slate-50/60 border border-slate-100 grid grid-cols-2 gap-2.5" @submit.prevent="submitLesson(m.id)">
              <div class="col-span-2">
                <label class="text-xs font-bold text-slate-500">ชื่อบทเรียน</label>
                <input v-model="lessonForm[m.id]!.title" required class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">ประเภทเนื้อหา</label>
                <!-- ADR-028 §2.1 — `image` is a first-class lesson type now. -->
                <select v-model="lessonForm[m.id]!.content_type" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm">
                  <option value="video">วิดีโอ</option>
                  <option value="pdf">PDF</option>
                  <option value="image">รูปภาพ</option>
                  <option value="quiz">แบบทดสอบท้ายบท</option>
                  <option value="link">ลิงก์</option>
                </select>
              </div>

              <!-- ADR-028 §2.1 — video, PDF and image can each be either an
                   uploaded file (stored on our private disk, streamed only
                   after an authorization check) or an external URL. Link is
                   always a URL; quiz has no content_ref at all. -->
              <template v-if="isUploadableType(lessonForm[m.id]!.content_type)">
                <div>
                  <label class="text-xs font-bold text-slate-500">แหล่งเนื้อหา</label>
                  <div class="mt-1 flex gap-2">
                    <label class="flex items-center gap-1.5 text-xs">
                      <input v-model="lessonForm[m.id]!.source_type" type="radio" value="embed" />
                      {{ lessonForm[m.id]!.content_type === 'video' ? 'ลิงก์ iframe/embed' : 'ลิงก์ภายนอก' }}
                    </label>
                    <label class="flex items-center gap-1.5 text-xs">
                      <input v-model="lessonForm[m.id]!.source_type" type="radio" value="upload" /> อัปโหลดไฟล์
                    </label>
                  </div>
                </div>
                <div v-if="lessonForm[m.id]!.source_type === 'embed'">
                  <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
                    {{ lessonForm[m.id]!.content_type === 'video' ? 'ลิงก์ embed (YouTube/Vimeo)' : 'ลิงก์เนื้อหา' }}
                    <!-- §4.B1/B3 — the two authoring-help paragraphs. This copy
                         still told the admin to press "ดูตัวอย่าง" after saving;
                         that button was removed on 2026-08-09 and only the edit
                         form's wording was updated. One string now. -->
                    <InfoPopover
                      v-if="isFramedLesson(lessonForm[m.id]!.content_type, lessonForm[m.id]!.source_type)"
                      label="ลิงก์ embed"
                      :text="
                        embedUrlExplanation({
                          rewritten: embedUrlWasRewritten(lessonForm[m.id]!.content_ref),
                          mayNotDisplay: embedUrlMayNotDisplay(lessonForm[m.id]!.content_ref),
                        })
                      "
                    />
                  </label>
                  <input v-model="lessonForm[m.id]!.content_ref" required type="url" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />

                  <!-- §4.B5 — the URL the learner's iframe will load: data. -->
                  <p
                    v-if="
                      isFramedLesson(lessonForm[m.id]!.content_type, lessonForm[m.id]!.source_type) &&
                      embedUrlInUse(lessonForm[m.id]!.content_ref)
                    "
                    class="text-[11px] text-slate-500 mt-1 leading-relaxed break-all"
                  >
                    ลิงก์ที่ระบบจะใช้แสดงในบทเรียน:
                    <span class="font-bold text-slate-700">{{ embedUrlInUse(lessonForm[m.id]!.content_ref) }}</span>
                  </p>
                </div>
                <div v-else>
                  <label class="text-xs font-bold text-slate-500 flex items-center gap-1">
                    ไฟล์
                    <!-- §4.B1 — was a permanent grey "รองรับ .mp4 …" line. -->
                    <InfoPopover label="ไฟล์ที่รองรับ" :text="hintFor(lessonForm[m.id]!.content_type)" />
                  </label>
                  <input
                    type="file"
                    :accept="acceptFor(lessonForm[m.id]!.content_type)"
                    required
                    class="mt-1 w-full text-xs"
                    @change="onLessonFileChange(m.id, $event)"
                  />
                </div>

                <div v-if="lessonForm[m.id]!.source_type === 'upload'" class="col-span-2">
                  <label class="flex items-center gap-1.5">
                    <input :id="`lesson-is-downloadable-${m.id}`" v-model="lessonForm[m.id]!.is_downloadable" type="checkbox" />
                    <span class="text-xs font-bold text-slate-500">อนุญาตให้ผู้เรียนดาวน์โหลดไฟล์นี้</span>
                    <!-- §4.B1/B3 — ADR-028 §2.2, stated honestly. R3 of the
                         ADR-028 sprint plan: a label that reads as copy
                         protection gets the PR rejected, because a company may
                         make real disclosure decisions about confidential
                         material on the strength of what we tell them here.
                         Same string as the edit form now. -->
                    <InfoPopover
                      label="อนุญาตให้ผู้เรียนดาวน์โหลดไฟล์นี้"
                      :text="DOWNLOADABLE_EXPLANATION"
                    />
                  </label>
                </div>
              </template>
              <div v-else-if="lessonForm[m.id]!.content_type !== 'quiz'">
                <label class="text-xs font-bold text-slate-500">ลิงก์เนื้อหา</label>
                <input v-model="lessonForm[m.id]!.content_ref" required type="url" class="mt-1 w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm" />
              </div>
              <!-- §4.B1 — the quiz branch's paragraph. The label stays as the
                   field's own name; the instruction moved. -->
              <p v-else class="col-span-2 text-xs font-bold text-slate-500 flex items-center gap-1">
                แบบทดสอบท้ายบท
                <InfoPopover label="บทเรียนแบบทดสอบท้ายบท" :text="QUIZ_LESSON_TYPE_EXPLANATION" />
              </p>

              <div class="col-span-2 flex items-center gap-1.5">
                <input :id="`lesson-is-published-${m.id}`" v-model="lessonForm[m.id]!.is_published" type="checkbox" />
                <label :for="`lesson-is-published-${m.id}`" class="text-xs font-bold text-slate-500">เผยแพร่ทันที (ถ้าไม่เลือก จะบันทึกเป็นฉบับร่าง)</label>
              </div>

              <!-- TASK-145 AC — a file bigger than post_max_size uploads with
                   VISIBLE progress, and cancelling mid-upload leaves no orphan
                   (the abandoned .part file is swept by uploads:prune). -->
              <div
                v-if="
                  submittingLessonFor === m.id &&
                  lessonForm[m.id]!.source_type === 'upload' &&
                  isUploadableType(lessonForm[m.id]!.content_type)
                "
                class="col-span-2 flex items-center gap-2"
              >
                <div class="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                  <div
                    class="h-full rounded-full bg-brand-600 transition-all duration-150"
                    :style="{ width: `${Math.round((lessonUploadProgress[m.id] ?? 0) * 100)}%` }"
                  ></div>
                </div>
                <span class="text-[11px] font-bold text-slate-500 tabular-nums shrink-0">
                  {{ Math.round((lessonUploadProgress[m.id] ?? 0) * 100) }}%
                </span>
                <button type="button" class="text-[11px] font-bold text-rose-600 shrink-0" @click="cancelLessonUpload(m.id)">ยกเลิก</button>
              </div>

              <p v-if="lessonError[m.id]" class="col-span-2 text-xs font-bold text-rose-600">{{ lessonError[m.id] }}</p>
              <div class="col-span-2 flex justify-end">
                <button type="submit" :disabled="submittingLessonFor === m.id" class="btn-primary">
                  {{ submittingLessonFor === m.id ? 'กำลังบันทึก...' : 'บันทึก' }}
                </button>
              </div>
            </form>
          </div>
        </div>
        </TransitionGroup>
      </div>
      </div><!-- /right pane -->
      </div><!-- /two-pane grid -->
      </template>
    </section>

    <!-- Exams -->
    <section v-if="activeTab === 'exams'" class="mt-4">
      <CompanyScopeNotice action="จัดการแบบประเมินผล" />
      <div class="flex justify-end mb-2">
        <button class="btn-primary" @click="showExamForm = !showExamForm">
          + เพิ่มแบบประเมินผล
        </button>
      </div>
      <form v-if="showExamForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitExam">
        <div class="col-span-2">
          <label class="text-xs font-bold text-slate-500">ชื่อแบบประเมินผล</label>
          <input v-model="examForm.title" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">Cert tier</label>
          <select v-model="examForm.cert_tier_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <option value="" disabled>เลือก tier</option>
            <option v-for="t in certTiers" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-bold text-slate-500">เกณฑ์ผ่าน (คะแนน)</label>
          <input v-model="examForm.passing_score" type="number" min="0" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
        </div>
        <p v-if="examFormError" class="col-span-2 text-xs font-bold text-rose-600">{{ examFormError }}</p>
        <div class="col-span-2 flex justify-end">
          <button type="submit" class="btn-primary">บันทึก</button>
        </div>
      </form>
      <p v-if="questionError" class="mb-2 text-xs font-bold text-rose-600">{{ questionError }}</p>
      <EmptyState v-if="!exams.length" icon="check_square" title="ยังไม่มีแบบประเมินผล" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
        <div v-for="ex in exams" :key="ex.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <template v-if="editingExamId === ex.id">
            <div class="grid grid-cols-2 gap-3">
              <div class="col-span-2">
                <label class="text-xs font-bold text-slate-500">ชื่อแบบประเมินผล</label>
                <input v-model="editExamForm.title" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">Cert tier</label>
                <select v-model="editExamForm.cert_tier_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                  <option v-for="t in certTiers" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500">เกณฑ์ผ่าน (คะแนน)</label>
                <input v-model="editExamForm.passing_score" type="number" min="0" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-2">
              <button class="text-xs font-bold text-slate-500" @click="cancelEditExam">ยกเลิก</button>
              <button class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold" @click="saveEditExam(ex.id)">บันทึก</button>
            </div>
          </template>
          <div v-else class="flex items-center justify-between">
            <div>
              <p class="text-sm font-bold text-slate-900">{{ ex.title }}</p>
              <p class="text-xs text-slate-400">{{ ex.cert_tier?.name }} · เกณฑ์ผ่าน {{ ex.passing_score }}</p>
            </div>
            <div class="flex items-center gap-1.5">
              <button
                class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 flex items-center gap-1"
                @click="toggleExamQuestions(ex.id)"
              >
                <Icon name="check_square" :size="13" />
                {{ expandedExamId === ex.id ? 'ซ่อนคำถาม' : 'จัดการคำถาม' }}
                <span v-if="questionsByExam[ex.id]" class="text-slate-400">({{ questionsByExam[ex.id]?.length }})</span>
              </button>
              <button class="text-slate-400 hover:text-brand-600" title="แก้ไข" @click="startEditExam(ex)">
                <Icon name="pencil" :size="16" />
              </button>
              <button class="text-rose-600 hover:text-rose-700" title="ลบ" @click="deleteExam(ex.id)">
                <Icon name="trash" :size="16" />
              </button>
            </div>
          </div>

          <!-- Question bank authoring panel -->
          <div v-if="expandedExamId === ex.id" class="mt-3 pt-3 border-t border-slate-100">
            <p v-if="loadingQuestionsFor === ex.id" class="text-xs text-slate-400">กำลังโหลด...</p>
            <template v-else>
              <EmptyState v-if="!questionsByExam[ex.id]?.length" icon="check_square" title="ยังไม่มีคำถาม" class="mb-2" />
              <div v-else class="space-y-2 mb-3">
                <div v-for="q in questionsByExam[ex.id]" :key="q.id" class="p-3 rounded-lg bg-slate-50/60 border border-slate-100">
                  <div class="flex items-center justify-between gap-2 mb-1.5">
                    <p class="text-sm font-bold text-slate-700">{{ q.question_text }}</p>
                    <button class="text-rose-500 hover:text-rose-700 shrink-0" title="ลบคำถาม" @click="deleteQuestion(ex.id, q.id)">
                      <Icon name="trash" :size="14" />
                    </button>
                  </div>
                  <div class="space-y-1 mb-2">
                    <div v-for="opt in q.options" :key="opt.id" class="flex items-center gap-2 text-xs">
                      <button
                        class="w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0"
                        :class="opt.is_correct ? 'border-emerald-600 bg-emerald-600' : 'border-slate-300'"
                        :title="opt.is_correct ? 'คำตอบที่ถูกต้อง' : 'ทำเครื่องหมายว่าถูกต้อง'"
                        @click="markOptionCorrect(ex.id, opt.id)"
                      >
                        <Icon v-if="opt.is_correct" name="check" :size="9" class="text-white" />
                      </button>
                      <span :class="opt.is_correct ? 'font-bold text-emerald-700' : 'text-slate-600'" class="flex-1">{{ opt.option_text }}</span>
                      <button class="text-slate-300 hover:text-rose-600" title="ลบตัวเลือก" @click="deleteOption(ex.id, opt.id)">
                        <Icon name="x" :size="12" />
                      </button>
                    </div>
                  </div>
                  <!-- §4.B5 — the exam question bank's copy of the same line.
                       Error stays; the how-to reads the shared string. -->
                  <p v-if="questionHasNoCorrectAnswer(q)" class="flex items-center gap-1 text-[11px] font-bold text-amber-600 mb-2">
                    <Icon name="alert" :size="12" class="shrink-0" />
                    ยังไม่ได้เลือกคำตอบที่ถูกต้อง
                    <InfoPopover label="ยังไม่ได้เลือกคำตอบที่ถูกต้อง" :text="QUIZ_NO_CORRECT_ANSWER_HOWTO" />
                  </p>
                  <div class="flex items-center gap-1.5">
                    <button
                      class="px-2 py-1 rounded-lg bg-brand-600 text-white text-xs font-bold disabled:opacity-50 shrink-0"
                      :disabled="addingOptionFor === q.id || !newOptionText[q.id]?.trim()"
                      @click="addOption(ex.id, q.id)"
                    >
                      + เพิ่ม
                    </button>
                    <input
                      :ref="(el) => setOptionInputEl(q.id, el as Element | null)"
                      v-model="newOptionText[q.id]"
                      placeholder="เพิ่มตัวเลือก..."
                      class="flex-1 px-2 py-1 rounded border border-slate-200 text-xs"
                      @keyup.enter="addOption(ex.id, q.id)"
                    />
                  </div>
                </div>
              </div>
            </template>
            <div class="flex items-center gap-1.5">
              <input
                v-model="newQuestionText[ex.id]"
                placeholder="เพิ่มคำถามใหม่..."
                class="flex-1 px-2.5 py-1.5 rounded-lg border border-slate-200 text-sm"
                @keyup.enter="addQuestion(ex.id)"
              />
              <button
                class="btn-primary"
                :disabled="addingQuestionFor === ex.id || !newQuestionText[ex.id]?.trim()"
                @click="addQuestion(ex.id)"
              >
                + เพิ่มคำถาม
              </button>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </section>

    <!-- ═══ Agent progress (TASK-152) ═══════════════════════════════════
         Every fraction below arrives from GET /academy-progress-summary as a
         SQL aggregate. Nothing on this screen divides one number by another,
         and the three-endpoint client-side join that produced the old
         (truncated, therefore wrong) numbers has been deleted, not disabled. -->
    <section v-if="activeTab === 'progress'" class="mt-4">
      <div class="mb-2 flex flex-col sm:flex-row sm:items-center gap-2">
        <input
          v-model="progressSearch"
          type="text"
          placeholder="ค้นหา ชื่อ / เบอร์ / อีเมล"
          class="w-full max-w-xs px-3 py-2 rounded-lg border border-slate-200 text-sm"
          @input="onProgressSearchInput"
        />
        <p v-if="progressMeta" class="text-[11px] text-slate-400">
          ตัวแทนทั้งบริษัท {{ progressAgentCount }} คน · บทเรียนที่นับความคืบหน้า {{ progressRequiredTotal }} บท
          <span v-if="progressMeta.total !== progressAgentCount"> · ตรงกับคำค้น {{ progressMeta.total }} คน</span>
        </p>
      </div>
      <!-- ADR-031 §2.4 — say WHICH lessons the denominator counts, once, at
           the top: an admin comparing this screen against the โมดูล tab's
           lesson list would otherwise read the difference as a bug. -->
      <!-- §4.B2 — the "(ADR-031 §2.4)" citation is gone; the rule it pointed at
           stays. This block is on the ความคืบหน้าตัวแทน tab, not the builder, so
           §4.B1 does not apply to it — only the citation rule does. -->
      <p class="text-[11px] text-slate-400 mb-2 leading-relaxed">
        “X/Y บทเรียน” นับเฉพาะบทที่<strong class="font-bold">เผยแพร่แล้วและไม่ใช่บทเสริม</strong> ·
        บทฉบับร่างและบทเสริมไม่ถูกนับเป็นตัวหาร ·
        ตัวเลขทั้งหมดคำนวณจากข้อมูลครบทั้งบริษัท ไม่ได้ตัดตามหน้า
      </p>

      <p v-if="progressLoading" class="text-xs text-slate-400">กำลังโหลดความคืบหน้า...</p>
      <div v-else-if="progressError" class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 flex items-center justify-between gap-3">
        <span>{{ progressError }}</span>
        <button class="shrink-0 px-3 py-1.5 rounded-lg bg-rose-100 text-xs font-bold text-rose-700 hover:bg-rose-200" @click="loadProgressSummary">
          ลองใหม่
        </button>
      </div>
      <EmptyState v-else-if="!progressRows.length" icon="users" title="ไม่พบตัวแทน" />
      <template v-else>
      <TransitionGroup tag="div" name="list-fade" class="space-y-2">
        <div v-for="a in progressRows" :key="a.user_id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <div class="flex items-center justify-between cursor-pointer" @click="toggleProgressAgent(a.user_id)">
            <div class="flex items-center gap-2 min-w-0 flex-wrap">
              <p class="text-sm font-bold text-slate-900">{{ a.name }}</p>
              <!-- Complete per agent now: /user-certifications paginates at 15
                   for the WHOLE company, so these badges used to disappear. -->
              <span
                v-for="t in a.cert_tiers_passed"
                :key="t.id"
                class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[11px] font-bold"
              >
                {{ t.name }}
              </span>
              <span v-if="!a.cert_tiers_passed.length" class="text-[11px] text-slate-400">ยังไม่ผ่านใบรับรองใดๆ</span>
            </div>
            <div class="flex items-center gap-2 shrink-0 text-xs text-slate-400">
              <span>
                {{ a.completed_required_count }}/{{ a.required_lesson_count }} บทเรียน
                <!-- Completions on a lesson that is NOW optional are reported
                     BESIDE the fraction, never inside it: counting them would
                     print "6/5", and a fraction above 1 tells an admin the
                     dashboard is broken. -->
                <span v-if="a.completed_optional_count" class="text-slate-300">(+{{ a.completed_optional_count }} บทเสริม)</span>
              </span>
              <Icon :name="expandedProgressAgentId === a.user_id ? 'chevron_up' : 'chevron_down'" :size="14" />
            </div>
          </div>

          <div v-if="expandedProgressAgentId === a.user_id" class="mt-3 pt-3 border-t border-slate-100 space-y-3">
            <EmptyState v-if="!progressOutline.length" icon="book" title="ยังไม่มี Section" />
            <!-- The outline comes from the same response — the old copy read
                 it off GET /modules, which paginates at 15 Sections, so a
                 sixteenth Section simply stopped being drawn. -->
            <div v-for="s in progressOutline" :key="s.id">
              <div class="flex items-center justify-between gap-2 mb-1">
                <p class="text-xs font-bold text-slate-600 min-w-0">
                  {{ s.title }}
                  <span v-if="!s.is_published" class="text-[10px] font-bold text-slate-400">(ฉบับร่าง)</span>
                </p>
                <p class="text-[11px] text-slate-400 shrink-0">
                  {{ agentSectionCompleted(a, s.id) }}/{{ s.required_lesson_count }} บทเรียน
                </p>
              </div>
              <div class="space-y-1 pl-1">
                <div v-for="l in s.lessons" :key="l.id" class="flex items-center gap-2 text-xs">
                  <span
                    class="w-4 h-4 rounded-full border shrink-0 flex items-center justify-center"
                    :class="isLessonCompletedByAgent(a, l.id) ? 'border-emerald-600 bg-emerald-600' : 'border-slate-300'"
                  >
                    <Icon v-if="isLessonCompletedByAgent(a, l.id)" name="check" :size="10" class="text-white" />
                  </span>
                  <span :class="isLessonCompletedByAgent(a, l.id) ? 'text-slate-700 font-bold' : 'text-slate-400'">{{ l.title }}</span>
                  <span v-if="l.is_optional" class="px-1.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold shrink-0">บทเสริม</span>
                  <span v-if="!l.is_published" class="px-1.5 rounded-full bg-slate-100 text-slate-400 text-[10px] font-bold shrink-0">ฉบับร่าง</span>
                </div>
              </div>
            </div>

            <!-- TASK-058: manual grant-without-exam (BR-1 admin override) -->
            <div v-if="certTiersNotYetPassed(a).length" class="pt-2 border-t border-slate-100">
              <p class="text-[11px] text-slate-400 mb-1.5">อนุมัติใบรับรองโดยไม่ต้องสอบ (ไม่ได้รับ XP)</p>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="t in certTiersNotYetPassed(a)"
                  :key="t.id"
                  type="button"
                  :disabled="grantingTierKey === `${a.user_id}:${t.id}`"
                  class="px-2.5 py-1 rounded-lg border border-slate-200 text-[11px] font-bold text-slate-600 hover:border-brand-400 hover:text-brand-600 disabled:opacity-50"
                  @click="grantCertification(a.user_id, t)"
                >
                  {{ grantingTierKey === `${a.user_id}:${t.id}` ? 'กำลังอนุมัติ...' : `+ อนุมัติ ${t.name}` }}
                </button>
              </div>
              <p v-if="grantError" class="text-[11px] text-rose-600 mt-1.5">{{ grantError }}</p>
            </div>
          </div>
        </div>
      </TransitionGroup>

      <!-- The agent LIST is the only paginated thing here, and it is honest:
           the fractions above do not depend on which page you are on. -->
      <div v-if="progressMeta && progressMeta.last_page > 1" class="mt-3 flex items-center justify-center gap-2">
        <button
          class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 disabled:opacity-40 hover:bg-slate-50"
          :disabled="progressMeta.current_page <= 1"
          @click="goToProgressPage(progressMeta.current_page - 1)"
        >
          ก่อนหน้า
        </button>
        <span class="text-xs text-slate-400">หน้า {{ progressMeta.current_page }} / {{ progressMeta.last_page }}</span>
        <button
          class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 disabled:opacity-40 hover:bg-slate-50"
          :disabled="progressMeta.current_page >= progressMeta.last_page"
          @click="goToProgressPage(progressMeta.current_page + 1)"
        >
          ถัดไป
        </button>
      </div>
      </template>
    </section>
    </template>

    <!-- Incomplete-answer reminder modal -->
    <div v-if="showIncompleteWarningModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeIncompleteWarningModal">
      <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-5">
        <div class="flex items-center gap-2 mb-2">
          <Icon name="alert" :size="18" class="text-amber-600 shrink-0" />
          <p class="text-sm font-bold text-slate-900">มีคำถามที่ยังไม่ได้เลือกคำตอบที่ถูกต้อง</p>
        </div>
        <p class="text-xs text-slate-500 mb-2">ตัวแทนจะทำข้อสอบชุดนี้ไม่ได้ครบถ้วนจนกว่าจะเลือกคำตอบที่ถูกต้องให้ครบทุกข้อ:</p>
        <ul class="text-xs text-slate-700 space-y-1 mb-3 max-h-40 overflow-y-auto">
          <li v-for="q in incompleteWarningQuestions" :key="q.id" class="flex items-start gap-1.5">
            <Icon name="alert" :size="12" class="text-amber-500 mt-0.5 shrink-0" />
            <span>{{ q.question_text }}</span>
          </li>
        </ul>
        <label class="flex items-center gap-1.5 text-xs text-slate-500 mb-4">
          <input v-model="dontShowIncompleteWarningAgain" type="checkbox" />
          ไม่ต้องแจ้งเตือนอีก
        </label>
        <div class="flex justify-end">
          <button class="btn-primary" @click="closeIncompleteWarningModal">
            เข้าใจแล้ว
          </button>
        </div>
      </div>
    </div>

    <!-- TASK-066 — replace native window.confirm(). Bug fix (2026-08-01,
         human-reported: sub-menu nav needed a hard refresh to render) —
         these were SIBLINGS of <main>, making the template a multi-root
         Fragment, which breaks App.vue's <Transition mode="out-in"> around
         <RouterView> (see AgentManagementView.vue's identical fix for the
         full explanation). Moved inside <main>. -->
    <ConfirmDialog
      :show="pendingDeleteModuleId !== null"
      variant="danger"
      title="ลบ Section"
      body="ลบ Section นี้จะลบบทเรียนทั้งหมดภายในไปด้วย ยืนยันหรือไม่?"
      @confirm="confirmDeleteModule"
      @update:show="(v) => { if (!v) pendingDeleteModuleId = null }"
    />
    <!--
      ADR-030 §2.3 / §4 item 2 — detaching is not destructive to the QUIZ, but
      where `quiz_blocks_completion` is on it switches a gate off on the BR-1
      certification path. detachWarningBody says which of those two situations
      the admin is actually in; the body is computed rather than fixed
      precisely so it cannot overstate the consequence on a lesson where the
      quiz was only advisory.
    -->
    <ConfirmDialog
      :show="pendingDetachLesson !== null"
      :variant="pendingDetachLesson?.quiz_blocks_completion ? 'danger' : 'warning'"
      title="ยกเลิกการเชื่อมโยงชุดคำถาม"
      :body="detachWarningBody"
      :busy="detaching"
      @confirm="confirmDetachQuiz"
      @update:show="(v) => { if (!v) pendingDetachLesson = null }"
    />
    <!-- ADR-028 §2.3 guard 2 / §5 — the escape hatch for the verified
         progress gate, audit-logged server-side because it feeds the BR-1
         cert gate. §5 asks for it to be discoverable BEFORE rollout: it
         sits directly on the progress row that shows why a learner is
         stuck.
         §4.B2 — the `body` below used to end "…มีผลต่อใบรับรอง (BR-1)". -->
    <ConfirmDialog
      :show="pendingCompletionOverride !== null"
      variant="primary"
      :title="pendingCompletionOverride ? `ทำเครื่องหมายว่าเรียนจบ: ${progressRowName(pendingCompletionOverride.row)}` : ''"
      body="ระบบจะบันทึกว่าผู้เรียนคนนี้เรียนบทเรียนนี้จบแล้ว แม้จะยังดู/อ่านไม่ครบตามเกณฑ์ การทำรายการนี้ถูกบันทึกใน Audit Log และมีผลต่อใบรับรอง ยืนยันหรือไม่?"
      :busy="overridingCompletion"
      @confirm="confirmCompletionOverride"
      @update:show="(v) => { if (!v) pendingCompletionOverride = null }"
    />
    <!--
      TASK-188 Phase D / §6.D4 — the retype confirmation. ConfirmDialog, NOT a
      window.confirm(): TASK-066 removed every one of those from this app.

      `retypeConfirmBody` is built entirely from the impact endpoint's response,
      and `confirmRetypeLesson` is the ONLY caller of the PUT for a retype — the
      stored file is deleted and the progress rows are cleared by that request,
      so nothing may be sent before the admin accepts.
    -->
    <ConfirmDialog
      :show="pendingRetype !== null"
      variant="danger"
      title="เปลี่ยนประเภทเนื้อหาของบทเรียน"
      :body="retypeConfirmBody"
      :busy="retypeSaving"
      @confirm="confirmRetypeLesson"
      @update:show="(v) => { if (!v) pendingRetype = null }"
    />
    <ConfirmDialog
      :show="pendingGrant !== null"
      variant="primary"
      :title='pendingGrant ? `อนุมัติใบรับรอง "${pendingGrant.tier.name}"` : ""'
      body="อนุมัติให้ตัวแทนผ่านใบรับรองนี้โดยไม่ต้องสอบจริง ยืนยันหรือไม่?"
      :busy="grantingTierKey !== null"
      @confirm="confirmGrantCertification"
      @update:show="(v) => { if (!v) pendingGrant = null }"
    />

    <!-- "ตัวอย่างที่ตัวแทนจะเห็น" (human request 2026-08-09). Inside <main>
         for the same multi-root Fragment reason as the dialogs above.
         Keyed on the lesson id so switching lessons remounts the modal
         (fresh quiz selections, fresh media element) instead of swapping
         the content underneath it — same reasoning as AcademyView's
         PdfViewerModal key. -->
    <LessonPreviewModal
      v-if="previewLesson"
      :key="previewLesson.id"
      :lesson="previewLesson"
      :settings="completionSettings"
      :settings-loading="completionSettingsLoading"
      :settings-error="completionSettingsError"
      @close="closeLessonPreview"
      @reload-settings="loadCompletionSettings"
    />
  </main>
</template>
