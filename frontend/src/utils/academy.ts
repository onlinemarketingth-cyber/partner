/**
 * Academy — the shapes and the read-only predicates the four Academy
 * screens share (TASK-167).
 *
 * WHY THIS FILE EXISTS. `/academy` (the list), `/academy/lessons/:id`,
 * `/academy/lessons/:id/quiz` and `/academy/exams/:id` are four components
 * now. Every one of them has to answer "what kind of content is this",
 * "may this learner open it" and "which lesson comes next" — and the one
 * defect this codebase keeps producing is the SECOND copy of a rule that
 * then drifts from the first. So there is exactly one copy of each, here.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * The content-type branches below (contentTypeLabel, lessonContentIcon,
 * lessonHasInlinePlayer, isUploadedPdf, isUploadedImage) are MIRRORED by
 * the Admin lesson preview at
 * `frontend-admin/src/design-system/components/LessonPreviewModal.vue`
 * ("ตัวอย่างที่ตัวแทนจะเห็น"). If a lesson renders differently there, the
 * preview lies to the author — worse than no preview, because they trust
 * it. Change both in the same PR. (The Admin copy has NO progress
 * reporting by design; do not port that into it.)
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * ADR-029 §2.7 — `is_correct` is ALWAYS null here: ModuleLessonResource
 * masks it for the Agent role. Typed as `null` so nothing can start reading
 * it — "never which answer is right".
 */
export interface ModuleLessonQuizOptionItem {
  id: number
  option_text: string
  is_correct: null
  sort_order: number
}

export interface ModuleLessonQuizQuestionItem {
  id: number
  question_text: string
  sort_order: number
  options: ModuleLessonQuizOptionItem[]
}

/**
 * ADR-028 §2.2 — the resource carries TWO urls on purpose:
 *   inline_url  → what the in-app player/reader renders from
 *   stream_url  → what the download button points at (Content-Disposition:
 *                 attachment once is_downloadable)
 * `content_ref` is null for anything we store ourselves (a private-disk
 * path is never exposed — CLAUDE.md §5 rule 6) and holds the external URL
 * for an embed video or an external pdf/link.
 *
 * `duration_seconds` / `page_count` are SERVER-measured media dimensions
 * (ffprobe / pdfinfo), never a completion figure (ADR-028 §4).
 */
export interface ModuleLessonItem {
  id: number
  module_id: number
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
  xp_reward: number
  is_published: boolean
  /**
   * TASK-165 §3.1 — does this lesson complete ITSELF?
   *
   * true  → the server measures it and records the completion the moment
   *         the gate is met; NO completion control is rendered (ADR-028 §1:
   *         completion is earned, not asserted).
   * false → the ADR-028 §2.3 fallback — the learner presses the button.
   *
   * READ, NEVER RE-DERIVED. It is LessonCompletionGate::isMeasurable()'s own
   * answer; a copy composed from content_type + source_type +
   * is_downloadable would drift, and the drift either shows a button that
   * 422s or hides the only control that could finish the lesson — on the
   * BR-1 certification path.
   */
  completion_is_automatic: boolean
  /**
   * ADR-029 — `quiz_question_count` is the ONLY field that says a quiz
   * exists while it is locked; `quiz_questions` is OMITTED from the payload
   * entirely until `quiz_unlocked` (ModuleLessonResource §2.2), so no screen
   * can render a question the learner was not meant to see. `quiz_unlocked`
   * is server-computed from the ADR-028 content gate and is read, never
   * re-derived. The pass MARK is never sent to an Agent.
   */
  quiz_question_count: number
  quiz_unlocked: boolean
  quiz_blocks_completion: boolean
  quiz_passed: boolean | null
  quiz_questions?: ModuleLessonQuizQuestionItem[]
  /**
   * ADR-031 §2.4 — an OPTIONAL lesson is shown and takeable, it is simply
   * outside every progress denominator and never gates the next lesson.
   *
   * §2.2/§2.3 — `is_locked` is a RENDERING HINT; the rule is enforced on
   * four server routes (stream, completion POST, progress PUT, quiz-attempt
   * POST). `lock_message` is the SERVER'S ready-made Thai sentence and is
   * rendered VERBATIM: "ต้องเรียนบทก่อนหน้าให้จบก่อน" and "จะเปิดในอีก 3 วัน"
   * are different problems for the learner (§3) and only LessonAccessGate
   * knows which applies. `unlocks_at` travels beside it so the countdown is
   * live rather than baked into a cacheable string.
   */
  is_optional: boolean
  is_locked: boolean
  lock_reason: 'not_published' | 'drip' | 'sequential_previous' | null
  lock_message: string | null
  unlocks_at: string | null
}

export interface ModuleItem {
  id: number
  cert_tier: { id: number; key: string; name: string } | null
  product: { id: number; name: string } | null
  title: string
  /**
   * ADR-031 §2.2/§2.3 — exposed to LEARNERS deliberately: this is course
   * STRUCTURE, not the completion THRESHOLDS ADR-028 §4 withheld. A learner
   * who cannot see the rule can only experience it as the app being broken.
   */
  enforce_sequential: boolean
  drip_days: number | null
  unlocks_at: string | null
  /** ADR-031 §2.4 — the denominator: published AND not optional, server-side. */
  lesson_count: number
  required_lesson_count: number
  optional_lesson_count: number
  lessons: ModuleLessonItem[]
}

export interface ModuleCompletionItem {
  id: number
  module_lesson: { id: number; module_id: number } | null
}

/**
 * POST /module-lessons/{lesson}/quiz-attempts response (ADR-029 §2.7).
 *
 * `score` is a COUNT out of `total_questions`, never a percentage, and the
 * per-question `results` say only whether the learner's OWN answer was
 * right — there is no correct-option id anywhere in it.
 *
 * TASK-167 §4.2 narrowed what the UI may DO with this: pass/fail only. The
 * fields stay typed because they are in the payload; nothing renders them.
 */
export interface ModuleLessonQuizAttemptResult {
  id: number
  module_lesson_id: number
  score: number
  total_questions: number
  passed: boolean
  attempted_at: string
  results: { question_id: number; answered: boolean; is_correct: boolean }[]
}

export interface ExamItem {
  id: number
  cert_tier: { id: number; key: string; name: string } | null
  title: string
  passing_score: number
}

export interface ExamOptionItem {
  id: number
  option_text: string
  is_correct: boolean | null
  sort_order: number
}

export interface ExamQuestionItem {
  id: number
  question_text: string
  sort_order: number
  options: ExamOptionItem[]
}

export interface ExamDetail extends ExamItem {
  questions: ExamQuestionItem[]
}

export interface ExamAttemptItem {
  id: number
  exam: { id: number; title: string } | null
  score: number
  passed: boolean
  attempted_at: string
}

export interface Certification {
  id: number
  cert_tier: { id: number; key: string; name: string } | null
  passed_at: string
}

// ── Progress predicates ─────────────────────────────────────────────

/**
 * ADR-031 §2.4 — which lessons a learner actually SEES. `ModuleResource`
 * ships `lessons` with is_published as a FIELD, not a filter, so a draft
 * would otherwise render as a row that can never be ticked and would make
 * the list disagree with the "X/Y" above it.
 */
export function visibleLessons(m: ModuleItem): ModuleLessonItem[] {
  return m.lessons.filter((l) => l.is_published)
}

/** ADR-031 §2.4 — published AND not optional. The numerator's universe. */
export function isRequiredLesson(lesson: ModuleLessonItem): boolean {
  return lesson.is_published && !lesson.is_optional
}

/**
 * "What can this learner actually do next" — THE one predicate, used by the
 * list's next-step card and by the lesson screen's §4.1 hand-off.
 *
 * Required (so not optional, §2.4 — skipping is allowed), published, not
 * locked (§2.2/§2.3), not already complete. The "not locked" clause is what
 * stops the app navigating somewhere the server would refuse: a button that
 * cannot work, in navigation form. Under a sequential Section the first
 * incomplete required lesson is by definition the unlocked one, so this
 * narrows the answer rather than emptying it.
 */
export function firstIncompleteLesson(
  pool: ModuleItem[],
  completedLessonIds: ReadonlySet<number>,
): { lesson: ModuleLessonItem; module: ModuleItem } | null {
  for (const m of pool) {
    const lesson = m.lessons.find(
      (l) => isRequiredLesson(l) && !l.is_locked && !completedLessonIds.has(l.id),
    )
    if (lesson) return { lesson, module: m }
  }

  return null
}

// ── Content-type predicates ─────────────────────────────────────────

/**
 * ADR-028 §2.2 — RENDER from inline_url, DOWNLOAD from stream_url.
 * stream_url serves `Content-Disposition: attachment` once is_downloadable
 * is on, which is what a download button wants and exactly what a
 * <video>/<canvas> does not. Falls back to stream_url so a lesson still
 * plays against a pre-ADR-028 API.
 */
export function lessonInlineSrc(lesson: ModuleLessonItem): string | null {
  return lesson.inline_url ?? lesson.stream_url
}

/**
 * A video lesson only has an inline player when the API gave a usable
 * source. Seeded lessons carry `source_type: null` with a plain
 * `content_ref` URL, which is neither branch — those open in a new tab.
 */
export function lessonHasInlinePlayer(lesson: ModuleLessonItem): boolean {
  if (lesson.content_type !== 'video') return false
  if (lesson.source_type === 'upload') return !!lessonInlineSrc(lesson)
  if (lesson.source_type === 'embed') return !!lesson.content_ref

  return false
}

/**
 * ADR-028 §2.4 — an UPLOADED pdf opens in the in-app reader. An external
 * `pdf`/`link` lesson is somebody else's page: no file of ours to render
 * and no way to observe reading position on it.
 */
export function isUploadedPdf(lesson: ModuleLessonItem): boolean {
  return lesson.content_type === 'pdf' && lesson.source_type === 'upload' && !!lessonInlineSrc(lesson)
}

/** ADR-028 §2.1 added `image` as a lesson content type; it renders inline. */
export function isUploadedImage(lesson: ModuleLessonItem): boolean {
  return lesson.content_type === 'image' && lesson.source_type === 'upload' && !!lessonInlineSrc(lesson)
}

/** True when the lesson has nothing to show at all — an authoring gap, not an app error. */
export function lessonHasNoContent(lesson: ModuleLessonItem): boolean {
  return (
    lesson.content_type !== 'quiz' &&
    !lessonHasInlinePlayer(lesson) &&
    !isUploadedPdf(lesson) &&
    !isUploadedImage(lesson) &&
    !lesson.content_ref
  )
}

// ── Labels ──────────────────────────────────────────────────────────

/*
 * TASK-152a — THE TWO ASSESSMENTS HAVE DIFFERENT NAMES. KEEP THEM APART.
 *
 *   แบบทดสอบท้ายบทเรียน  ADR-029. Scoped to ONE lesson. May block that
 *                        lesson's completion. Pass/fail only (§3).
 *   แบบประเมินผล         BR-1. Scoped to a CERT TIER. Failing it means no
 *                        certification, so no selling rights. Scored.
 *
 * A learner must not be asked to tell them apart by a suffix. If you add a
 * string naming either, use these words and no others.
 */
const CONTENT_TYPE_LABELS: Record<string, string> = {
  video: 'วิดีโอ',
  pdf: 'เอกสาร PDF',
  link: 'ลิงก์',
  image: 'รูปภาพ',
  quiz: 'แบบทดสอบท้ายบทเรียน',
}

export function contentTypeLabel(lesson: ModuleLessonItem): string {
  return CONTENT_TYPE_LABELS[lesson.content_type] ?? 'เนื้อหา'
}

export function lessonContentIcon(lesson: ModuleLessonItem): string {
  return lesson.content_type === 'video'
    ? 'play'
    : lesson.content_type === 'quiz'
      ? 'check_square'
      : 'document'
}

/**
 * The row's primary action — never a generic "เปิด". Every branch names what
 * the tap actually does, because the label IS the affordance.
 */
export function lessonActionLabel(lesson: ModuleLessonItem): string {
  // ADR-031 — a locked row must not advertise an action it cannot perform.
  if (lesson.is_locked) return 'ยังไม่เปิดให้เรียน'
  if (lesson.content_type === 'quiz') return 'ทำแบบทดสอบท้ายบทเรียน'
  if (isUploadedPdf(lesson)) return 'อ่านเอกสารในแอป'
  if (isUploadedImage(lesson)) return 'ดูรูปภาพ'
  if (lessonHasInlinePlayer(lesson)) return 'ดูวิดีโอ'
  // Label the REAL behaviour: these open a new tab, they do not render here.
  if (lesson.content_type === 'video') return 'เปิดวิดีโอในแท็บใหม่'
  if (lesson.content_type === 'pdf') return 'เปิดเอกสาร PDF'

  return 'เปิดเนื้อหา'
}

export function lessonProcessingLabel(status: ModuleLessonItem['processing_status']): string {
  switch (status) {
    case 'pending':
    case 'processing':
      return 'วิดีโอกำลังประมวลผล กรุณารอสักครู่แล้วลองใหม่'
    case 'failed':
      return 'ย่อไฟล์ไม่สำเร็จ — เล่นจากไฟล์ต้นฉบับ'
    default:
      return ''
  }
}

/**
 * ADR-031 §2.3 — the countdown is rendered CLIENT-side because the enum
 * message deliberately carries no date: a server-rendered one is stale the
 * moment the response is cached.
 */
export function lockCountdownText(lesson: ModuleLessonItem): string {
  if (!lesson.unlocks_at) return ''
  const opensAt = new Date(lesson.unlocks_at)
  if (Number.isNaN(opensAt.getTime())) return ''
  const msLeft = opensAt.getTime() - Date.now()
  if (msLeft <= 0) return ''
  const days = Math.ceil(msLeft / 86_400_000)
  const when = opensAt.toLocaleDateString('th-TH', { dateStyle: 'medium' })

  return days > 1 ? `จะเปิดในอีก ${days} วัน (${when})` : `จะเปิดในวันที่ ${when}`
}

/**
 * ADR-029 §2.2 — what the learner must DO to open the quiz. Never how far
 * they got, never a threshold; same silence about numbers as
 * LessonCompletionGate::blockedMessage() on the server.
 */
export function quizLockedHint(lesson: ModuleLessonItem): string {
  if (lesson.content_type === 'video') return 'ดูวิดีโอให้ครบก่อน จึงจะทำแบบทดสอบท้ายบทเรียนได้'
  if (lesson.content_type === 'pdf') return 'อ่านเอกสารให้ครบก่อน จึงจะทำแบบทดสอบท้ายบทเรียนได้'

  return 'เรียนเนื้อหาให้ครบก่อน จึงจะทำแบบทดสอบท้ายบทเรียนได้'
}

export function completedLessonIdsFrom(completions: ModuleCompletionItem[]): Set<number> {
  const ids = new Set<number>()
  for (const c of completions) {
    if (c.module_lesson?.id) ids.add(c.module_lesson.id)
  }

  return ids
}

export function formatAcademyDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
