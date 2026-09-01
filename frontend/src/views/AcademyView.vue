<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * AcademyView — the Academy / LMS LIST. Lists only (TASK-167 §2).
 *
 * Next step → course Sections and their lesson rows → แบบประเมินผล →
 * earned certificates. Nothing opens in place any more: a lesson, its
 * end-of-lesson quiz and an exam are each their own route, so the list stays
 * a list however much content a company publishes, and Android back / iOS
 * swipe-back returns HERE instead of leaving Academy.
 *
 *   /academy/lessons/:id       AcademyLessonView      video / pdf / image / link
 *   /academy/lessons/:id/quiz  AcademyLessonQuizView  แบบทดสอบท้ายบทเรียน
 *   /academy/exams/:id         AcademyExamView        แบบประเมินผล
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * How a lesson renders is now `utils/academy.ts` (labels, icons, per-type
 * predicates) + AcademyLessonView, and that set is MIRRORED by the Admin
 * preview at `frontend-admin/src/design-system/components/
 * LessonPreviewModal.vue` ("ตัวอย่างที่ตัวแทนจะเห็น"). Change both in the
 * same PR or the preview starts lying to the author.
 * ─────────────────────────────────────────────────────────────────────
 *
 * BR-1 (Access Gate): passing the Basic certification here is what unlocks
 * SWS Referral + Pipeline, enforced server-side (User::hasPassedCertTier()).
 * This page has no gate — everyone may browse and learn.
 *
 * ADR-009 — Module is a "Section" (grouping under a cert tier); the content
 * item lives on ModuleLesson, many per Section, and completion targets a
 * LESSON.
 *
 * XP KPI: this agent's own /xp-ledger entries whose source_type is
 * module_completed or exam_passed — the two BR-5 "XP source (a)" learning
 * events. Pipeline XP is excluded; it belongs to the Commission/Pipeline
 * story. ModuleLesson.xp_reward is NOT read anywhere — gamification_rules is
 * the source of truth, resolved server-side.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/api/client'
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import {
  completedLessonIdsFrom,
  contentTypeLabel,
  firstIncompleteLesson,
  formatAcademyDate,
  lessonActionLabel,
  lessonContentIcon,
  lockCountdownText,
  visibleLessons,
  type Certification,
  type ExamAttemptItem,
  type ExamItem,
  type ModuleCompletionItem,
  type ModuleItem,
  type ModuleLessonItem,
} from '@/utils/academy'
import { useToastStore } from '@/stores/toast'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import TabFilterBar from '@/design-system/components/TabFilterBar.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
// TASK-082/083 surface hierarchy: lessons/exams/certificates are
// homogeneous, comparable rows, so they are `variant="flat"` inside
// <AppList> under an <AppListGroupHeader>. Exactly ONE `variant="raised"` on
// the screen: the next actionable step.
import AppCard from '@/design-system/components/AppCard.vue'
import AppList from '@/design-system/components/AppList.vue'
import AppListGroupHeader from '@/design-system/components/AppListGroupHeader.vue'
import ProgressRing from '@/design-system/components/ProgressRing.vue'
import { useThemeStore } from '@/stores/theme'

const theme = useThemeStore()
const toast = useToastStore()

interface CertTier {
  id: number
  key: string
  name: string
  sort_order: number
  is_mandatory: boolean
}
interface XpLedgerEntry {
  id: number
  source_type: string
  xp_awarded: number
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const certTiers = ref<CertTier[]>([])
const modules = ref<ModuleItem[]>([])
const completions = ref<ModuleCompletionItem[]>([])
const exams = ref<ExamItem[]>([])
const attempts = ref<ExamAttemptItem[]>([])
const certifications = ref<Certification[]>([])
const xpEntries = ref<XpLedgerEntry[]>([])

const learningXpTotal = computed(() =>
  xpEntries.value
    .filter((x) => x.source_type === 'module_completed' || x.source_type === 'exam_passed')
    .reduce((sum, x) => sum + x.xp_awarded, 0),
)

const completedLessonIds = computed(() => completedLessonIdsFrom(completions.value))
const passedTierKeys = computed(
  () => new Set(certifications.value.map((c) => c.cert_tier?.key).filter(Boolean)),
)

/** ADR-031 §2.4 — published AND not optional; the same predicate everywhere. */
function isRequired(lesson: ModuleLessonItem): boolean {
  return lesson.is_published && !lesson.is_optional
}

function sectionDoneCount(m: ModuleItem): number {
  return m.lessons.filter((l) => isRequired(l) && completedLessonIds.value.has(l.id)).length
}

// Flattened lesson list — tab counts/filters operate at LESSON granularity
// (ADR-009) while the template renders grouped by Section.
const allLessons = computed(() => modules.value.flatMap(visibleLessons))

/**
 * ADR-031 §2.4 — THE DENOMINATOR IS THE SERVER'S: `required_lesson_count`
 * is "published AND not optional". Counting `lessons.length` instead would
 * include optional lessons (so the ring could never reach 100%) and drafts
 * (which a learner cannot complete at all). The numerator ranges over
 * exactly that set AND over lessons still in /modules, because
 * /module-completions can hold a lesson unpublished since.
 */
const requiredLessonTotal = computed(() =>
  modules.value.reduce((sum, m) => sum + m.required_lesson_count, 0),
)
const completedLessonCount = computed(() =>
  modules.value.reduce((sum, m) => sum + sectionDoneCount(m), 0),
)
/** Optional lessons finished — shown BESIDE the fraction, never inside it. */
const completedOptionalCount = computed(
  () => allLessons.value.filter((l) => l.is_optional && completedLessonIds.value.has(l.id)).length,
)
const overallFraction = computed(() =>
  requiredLessonTotal.value ? completedLessonCount.value / requiredLessonTotal.value : 0,
)
const overallPercentText = computed(() =>
  requiredLessonTotal.value ? Math.round(overallFraction.value * 100) + '%' : '—',
)
// The one BR-1 sentence this screen owns, declared once so the HeroHeader
// description and the next-step card cannot drift apart.
const BR1_NOTE = 'ผ่านใบรับรอง Basic เพื่อปลดล็อกการส่ง Referral และ Pipeline (BR-1)'

/**
 * "What do I do next" — PRESENTATION-LEVEL PRIORITISATION ONLY. It decides
 * nothing about who may take an exam or who is certified; BR-1 is enforced
 * server-side and this page has never had a gate. It picks which of the
 * already-visible items to show first: the lowest-sort_order tier not yet
 * passed → its first incomplete lesson → failing that, its exam. The two
 * fallbacks catch data the tier walk cannot (items under an already-passed
 * tier, or no cert_tiers at all).
 */
type NextStep =
  | { kind: 'lesson'; lesson: ModuleLessonItem; module: ModuleItem }
  | { kind: 'exam'; exam: ExamItem }
  | { kind: 'certified' }

const currentTier = computed(
  () =>
    [...certTiers.value]
      .sort((a, b) => a.sort_order - b.sort_order)
      .find((t) => !passedTierKeys.value.has(t.key)) ?? null,
)

const nextStep = computed<NextStep | null>(() => {
  const tier = currentTier.value
  if (tier) {
    const inTier = firstIncompleteLesson(
      modules.value.filter((m) => m.cert_tier?.key === tier.key),
      completedLessonIds.value,
    )
    if (inTier) return { kind: 'lesson', ...inTier }
    const tierExam = exams.value.find((e) => e.cert_tier?.key === tier.key)
    if (tierExam) return { kind: 'exam', exam: tierExam }
  }
  const anyLesson = firstIncompleteLesson(modules.value, completedLessonIds.value)
  if (anyLesson) return { kind: 'lesson', ...anyLesson }
  const anyExam = exams.value.find((e) => !passedTierKeys.value.has(e.cert_tier?.key))
  if (anyExam) return { kind: 'exam', exam: anyExam }
  // Everything done — the slot becomes the earned-certification summary.
  if (certifications.value.length) return { kind: 'certified' }
  // Nothing loaded at all: render no raised card rather than an empty one.
  return null
})

// Exposed as a plain string rather than letting the template read
// `nextStep.kind` — the template would then depend on TS narrowing a
// possibly-null union inside v-if, which vue-tsc handles inconsistently.
const nextStepKind = computed(() => nextStep.value?.kind ?? null)

/**
 * TASK-152a — THE TWO ASSESSMENTS HAVE DIFFERENT NAMES. KEEP THEM APART.
 *
 *   แบบทดสอบท้ายบทเรียน  ADR-029. Scoped to ONE lesson. Pass/fail only.
 *   แบบประเมินผล         BR-1. Scoped to a CERT TIER. Failing it means no
 *                        certification, so no selling rights. Scored.
 *
 * A learner must not be asked to tell them apart by a suffix. If you add a
 * string naming either of them, use these words and no others.
 */
const nextStepActionLabel = computed(() =>
  nextStepKind.value === 'exam' ? 'ไปที่แบบประเมินผล' : 'ไปที่บทเรียน',
)

/** Where the next-step button GOES. A route now, not a scroll to a row. */
const nextStepTarget = computed(() => {
  const step = nextStep.value
  if (!step || step.kind === 'certified') return null

  return step.kind === 'lesson' ? `/academy/lessons/${step.lesson.id}` : `/academy/exams/${step.exam.id}`
})

const nextStepTitle = computed(() => {
  const step = nextStep.value
  if (!step) return ''
  if (step.kind === 'lesson') return step.lesson.title
  if (step.kind === 'exam') return step.exam.title
  return 'เรียนจบทุกบทเรียนแล้ว'
})
const nextStepMeta = computed(() => {
  const step = nextStep.value
  if (!step) return ''
  if (step.kind === 'lesson') return `${step.module.title} · ${contentTypeLabel(step.lesson)}`
  if (step.kind === 'exam') return `${step.exam.cert_tier?.name} · เกณฑ์ผ่าน ${step.exam.passing_score} คะแนน`
  return `ระดับใบรับรองปัจจุบัน: ${currentTierLabel.value}`
})

/*
 * TASK-166 — THE CERTIFICATE DOWNLOAD IS PARKED (human, 2026-08-11).
 * See docs/tasks/TASK-166-certificate-system.md.
 *
 * What sits behind it is a hardcoded English HTML string rendered through
 * dompdf with a font that has no Thai glyphs and none registered — a Thai
 * agent's name very likely comes out as tofu, and nobody has proven
 * otherwise. A certificate is something an agent shows other people, so
 * shipping it unproven is worse than hiding it. The CERTIFICATION itself is
 * not hidden: the rows below still say what was passed and when.
 *
 * Restoring the feature is un-commenting the button beside the certification
 * rows; this function is kept so that costs one comment rather than a
 * re-implementation.
 */
const downloadingCertId = ref<number | null>(null)
// eslint-disable-next-line @typescript-eslint/no-unused-vars
async function downloadCertificate(cert: Certification) {
  downloadingCertId.value = cert.id
  try {
    await api.download(
      `/user-certifications/${cert.id}/download`,
      `certificate-${cert.cert_tier?.key ?? cert.id}.pdf`,
    )
  } catch (e) {
    toast.error(apiErrorMessage(e, 'ดาวน์โหลดใบรับรองไม่สำเร็จ'))
  } finally {
    downloadingCertId.value = null
  }
}

const currentTierLabel = computed(() => {
  const passed = certTiers.value
    .filter((t) => passedTierKeys.value.has(t.key))
    .sort((a, b) => b.sort_order - a.sort_order)
  return passed[0]?.name ?? 'ยังไม่ผ่าน Basic'
})

const kpis = computed(() => [
  { label: 'ระดับใบรับรองปัจจุบัน', value: currentTierLabel.value },
  // ADR-031 §2.4 — the REQUIRED count against the REQUIRED total, not the
  // raw size of the completion set (which includes optional lessons and
  // completions on lessons since unpublished, so it could exceed the total
  // shown beside it).
  { label: 'บทเรียนที่จบแล้ว', value: `${completedLessonCount.value}/${requiredLessonTotal.value}` },
  { label: 'XP จากการเรียน', value: learningXpTotal.value.toLocaleString('th-TH') },
])

// One controller for this view's lifetime: Academy fans out to seven
// endpoints in one go, the widest fan-out in the app, so leaving mid-load is
// also the most wasteful. onUnmounted cancels all seven at once.
const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [t, m, mc, e, ea, uc, xl] = await Promise.all([
      api.get<{ data: CertTier[] }>('/cert-tiers', pageAbort.signal),
      api.get<{ data: ModuleItem[] }>('/modules', pageAbort.signal),
      api.get<{ data: ModuleCompletionItem[] }>('/module-completions', pageAbort.signal),
      api.get<{ data: ExamItem[] }>('/exams', pageAbort.signal),
      api.get<{ data: ExamAttemptItem[] }>('/exam-attempts', pageAbort.signal),
      api.get<{ data: Certification[] }>('/user-certifications', pageAbort.signal),
      api.get<{ data: XpLedgerEntry[] }>('/xp-ledger', pageAbort.signal),
    ])
    certTiers.value = t.data
    modules.value = m.data
    completions.value = mc.data
    exams.value = e.data
    attempts.value = ea.data
    certifications.value = uc.data
    xpEntries.value = xl.data
  } catch (e) {
    // A load we cancelled ourselves is not a failure.
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const activeTab = ref<'all' | 'in_progress' | 'done'>('all')
/*
 * These are LIST counts, not progress fractions, so they range over every
 * VISIBLE lesson (optional included — a learner filtering to "จบแล้ว" wants
 * the optional one they finished to be there). Counted by intersecting with
 * `allLessons` rather than reading `completedLessonIds.size`: that set can
 * hold a lesson since unpublished, which would make "จบแล้ว" larger than the
 * list it filters.
 */
const doneVisibleCount = computed(
  () => allLessons.value.filter((l) => completedLessonIds.value.has(l.id)).length,
)
const tabs = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: allLessons.value.length },
  { id: 'in_progress', label: 'กำลังเรียน', count: allLessons.value.length - doneVisibleCount.value },
  { id: 'done', label: 'จบแล้ว', count: doneVisibleCount.value },
])

/**
 * Sections render grouped; each Section's lessons are filtered by the active
 * tab, and a Section with zero matching lessons is hidden to avoid empty
 * headers.
 *
 * The filtered list and the Section's FULL counts are carried separately:
 * "2/5 บทเรียน" describes the SECTION, not the current filter (under
 * "กำลังเรียน" the filtered list holds only unfinished lessons, which would
 * make every bar read 0%).
 */
const courseSections = computed(() =>
  modules.value
    .map((m) => {
      const visible = visibleLessons(m)
      return {
        module: m,
        lessons:
          activeTab.value === 'done'
            ? visible.filter((l) => completedLessonIds.value.has(l.id))
            : activeTab.value === 'in_progress'
              ? visible.filter((l) => !completedLessonIds.value.has(l.id))
              : visible,
        // ADR-031 §2.4 — the SECTION's denominator, from the server.
        totalCount: m.required_lesson_count,
        doneCount: sectionDoneCount(m),
        optionalCount: m.optional_lesson_count,
      }
    })
    .filter((s) => s.lessons.length > 0),
)

function sectionPercent(done: number, total: number): number {
  return total ? Math.round((done / total) * 100) : 0
}

function latestAttemptFor(examId: number): ExamAttemptItem | undefined {
  return attempts.value.find((a) => a.exam?.id === examId)
}

/**
 * ADR-031 §2.2/§2.3 — a locked lesson is SHOWN and greyed, and this list
 * must not navigate into it: the server refuses on four routes and
 * discovering that through a 403 is not a design. The row already carries
 * the reason, so repeat it rather than letting the tap look broken.
 */
function onLockedLessonTap(lesson: ModuleLessonItem) {
  toast.info(lesson.lock_message ?? 'บทเรียนนี้ยังไม่เปิดให้เรียน')
}

/**
 * TASK-105 — the page title is the SAME configured label as the bottom-nav
 * tab that opens this screen. Fallbacks match BottomNav.vue exactly; if they
 * drifted, an unset tenant would see the mismatch this exists to remove.
 */
const pageTitle = computed(() => theme.label('nav_academy', 'Academy'))
const pageIcon = computed(() => theme.icon('nav_academy', 'brain'))
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      :icon="pageIcon"
      :title="pageTitle"
      :subtitle="td('academy.subtitle')"
      :description="BR1_NOTE"
      :kpis="kpis"
      accent-color="brand"
      storage-key="academy"
    >
      <template #tabs>
        <div class="px-4">
          <TabFilterBar v-model="activeTab" :tabs="tabs" accent-color="brand" />
        </div>
      </template>
    </HeroHeader>

    <!-- Dead-end error banner — retry lets the agent recover without
         reloading the whole SPA. -->
    <div
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3"
    >
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadAll"
      >
        {{ td('common.retry') }}
      </button>
    </div>

    <!-- .content-fade lives in assets/main.css and is neutralised under
         prefers-reduced-motion. <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or a <Transition> around <RouterView> breaks (the
         multi-root Fragment regression). -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" class="mt-4" />
      <div v-else>
        <!-- ── 1. WHAT DO I DO NEXT ─────────────────────────────────
             The single `variant="raised"` element this screen is allowed. An
             LMS screen has one job on a phone: answer "where am I, and what
             next". Everything below is reference material.

             When every lesson is finished and every exam passed the same slot
             becomes the earned-certification summary, so the screen never
             shows two raised cards and never none while there is still
             something to do. -->
        <AppCard v-if="nextStep" variant="raised" class="mt-4">
          <div class="flex items-center gap-4">
            <ProgressRing
              :fraction="overallFraction"
              :center-text="overallPercentText"
              :label="td('academy.progress')"
              class="shrink-0"
            />
            <!-- `flex-1 min-w-0` deliberately: a long English lesson title in
                 a flex row next to a fixed-width sibling is the squeeze that
                 wrapped a client name to one character per line on Referrals
                 at 768px. -->
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">
                {{ nextStepKind === 'certified' ? td('academy.your_certs') : td('academy.next_step') }}
              </p>
              <p class="text-lg font-bold text-ink-card leading-tight mt-0.5 break-words">
                {{ nextStepTitle }}
              </p>
              <p class="text-xs text-ink-card-muted mt-0.5 break-words">{{ nextStepMeta }}</p>
              <RouterLink
                v-if="nextStepTarget"
                :to="nextStepTarget"
                class="mt-2 min-h-[44px] px-4 py-2 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform inline-flex items-center gap-1.5"
              >
                {{ nextStepActionLabel }}
                <Icon name="chevron_right" :size="14" />
              </RouterLink>
            </div>
          </div>
          <!-- ADR-031 §2.4 — the denominator is `required_lesson_count`
               (published, not optional). Optional lessons finished are
               reported BESIDE the fraction, never inside it, or a learner who
               finished all the required material could never reach 100%. -->
          <p
            v-if="requiredLessonTotal"
            class="mt-3 pt-3 border-t border-line-card-subtle text-xs text-ink-card-muted"
          >
            {{ td('academy.progress_count', '', { done: completedLessonCount, total: requiredLessonTotal }) }}
            <span v-if="completedOptionalCount"> · {{ td('academy.bonus_extra', '', { n: completedOptionalCount }) }}</span>
          </p>
          <!-- BR-1 stays visible where the decision is made, not only in the
               header: this is the certification that unlocks selling. -->
          <p v-if="currentTier?.is_mandatory" class="mt-2 text-xs font-bold text-ink-warning">
            {{ BR1_NOTE }}
          </p>
        </AppCard>

        <!-- ── 2. COURSE SECTIONS → LESSON CHECKLIST (ADR-009) ────── -->
        <EmptyState v-if="!courseSections.length" icon="book" :title="td('academy.no_lessons')" class="mt-4" />
        <div v-else class="mt-4">
          <template v-for="s in courseSections" :key="s.module.id">
            <AppListGroupHeader :label="s.module.title" :count="s.totalCount" />
            <div class="px-1 pb-2 space-y-1">
              <p class="text-[11px] text-ink-card-subtle">
                {{ s.module.cert_tier?.name }}<span v-if="s.module.product"> · {{ s.module.product.name }}</span>
              </p>
              <!-- ADR-031 §2.2 — the Section's structure, stated to the
                   learner. Not a withheld threshold (ADR-028 §4): a learner
                   who cannot see "this section is taken in order" can only
                   experience the lock as the app being broken. -->
              <p
                v-if="s.module.enforce_sequential"
                class="text-[11px] font-bold text-ink-brand inline-flex items-center gap-1"
              >
                <Icon name="key" :size="12" /> {{ td('academy.sequential') }}
              </p>
              <div class="flex items-center gap-2">
                <div class="h-1.5 flex-1 min-w-0 rounded-full bg-slate-200 overflow-hidden">
                  <div
                    class="h-full rounded-full bg-brand-500"
                    :style="{ width: sectionPercent(s.doneCount, s.totalCount) + '%' }"
                  ></div>
                </div>
                <span class="text-[11px] font-bold text-ink-card-subtle tabular-nums shrink-0">
                  {{ td('academy.lessons_count', '', { done: s.doneCount, total: s.totalCount }) }}
                  <span v-if="s.optionalCount" class="font-normal">{{ td('academy.optional_count', '', { n: s.optionalCount }) }}</span>
                </span>
              </div>
            </div>
            <AppList>
              <!-- No `tag`: TransitionGroup renders as a fragment so the rows
                   stay DIRECT children of AppList, which its
                   `[&>*:last-child]:border-b-0` rule depends on. -->
              <TransitionGroup name="list-fade">
                <!-- ADR-031 §4 item 2 — a locked lesson is GREYED, not
                     hidden: hiding it makes the course look shorter than it
                     is. -->
                <AppCard
                  v-for="l in s.lessons"
                  :id="'lesson-' + l.id"
                  :key="l.id"
                  variant="flat"
                  :class="l.is_locked ? 'opacity-70' : ''"
                >
                  <!-- THE WHOLE ROW IS ONE TAP TARGET, for every content
                       type. A locked row goes nowhere and says why instead;
                       everything else is a link to the lesson's own route.
                       Always stacked (no `sm:`): Tailwind breakpoints track
                       the VIEWPORT, but this app renders inside a fixed
                       max-w-md column, so `sm:flex-row` fired on desktop and
                       crushed the title. -->
                  <!-- Two elements rather than one <component :is>: a locked
                       row must not be an <a> at all, or a long-press still
                       offers "open in new tab" on a URL the server refuses. -->
                  <button
                    v-if="l.is_locked"
                    type="button"
                    class="w-full text-left flex items-start gap-3 min-w-0 active:opacity-70 transition-opacity"
                    @click="onLockedLessonTap(l)"
                  >
                    <Icon name="key" :size="20" class="mt-0.5 shrink-0 text-ink-card-subtle" />
                    <div class="min-w-0 flex-1">
                      <!-- `break-words`, not `truncate`: a truncated lesson
                           title on a phone hides the only thing that
                           identifies the row. THE TITLE IS ALWAYS SHOWN,
                           locked or not (ADR-031 §4 item 2). -->
                      <p class="text-sm font-bold break-words text-ink-card-muted">
                        {{ l.title }}
                        <span
                          v-if="l.is_optional"
                          class="ml-1.5 align-middle px-1.5 py-0.5 rounded-full bg-surface-chip text-ink-card-subtle text-[10px] font-bold"
                          >{{ td('academy.bonus_lesson') }}</span
                        >
                      </p>
                      <p class="text-[11px] mt-0.5 text-ink-card-subtle font-bold">
                        {{ lessonActionLabel(l) }}
                        <span class="font-normal"> · {{ contentTypeLabel(l) }}</span>
                      </p>
                    </div>
                  </button>

                  <RouterLink
                    v-else
                    :to="`/academy/lessons/${l.id}`"
                    class="w-full text-left flex items-start gap-3 min-w-0 active:opacity-70 transition-opacity"
                  >
                    <!-- Checklist semantics: done vs not-done differ by ICON
                         as well as colour, so the state survives
                         colour-blindness and greyscale. Emerald is the app's
                         semantic success colour, not decoration. -->
                    <Icon
                      :name="completedLessonIds.has(l.id) ? 'check_circle' : lessonContentIcon(l)"
                      :size="20"
                      class="mt-0.5 shrink-0"
                      :class="completedLessonIds.has(l.id) ? 'text-ink-success' : 'text-ink-card-subtle'"
                    />
                    <div class="min-w-0 flex-1">
                      <p
                        class="text-sm font-bold break-words"
                        :class="completedLessonIds.has(l.id) ? 'text-ink-card-muted' : 'text-ink-card'"
                      >
                        {{ l.title }}
                        <!-- ADR-031 §2.4 — the label says what optional MEANS.
                             "Optional" with no explanation reads as
                             "skippable but you will be marked down". -->
                        <span
                          v-if="l.is_optional"
                          class="ml-1.5 align-middle px-1.5 py-0.5 rounded-full bg-surface-chip text-ink-card-subtle text-[10px] font-bold"
                          >{{ td('academy.bonus_lesson') }}</span
                        >
                      </p>
                      <!-- The action label IS the affordance: it names what
                           tapping does, per content type. -->
                      <p class="text-[11px] mt-0.5 text-ink-brand font-bold">
                        {{ lessonActionLabel(l) }}
                        <span class="text-ink-card-subtle font-normal"> · {{ contentTypeLabel(l) }}</span>
                        <!-- ADR-029 — the learner is told a quiz EXISTS and
                             how many questions, from `quiz_question_count`,
                             the only quiz field that survives a lock. Never
                             how far they got (ADR-028 §4). -->
                        <span v-if="l.quiz_question_count" class="text-ink-card-subtle font-normal">
                          · แบบทดสอบท้ายบทเรียน {{ l.quiz_question_count }} ข้อ
                        </span>
                      </p>
                    </div>
                    <span
                      v-if="completedLessonIds.has(l.id)"
                      class="shrink-0 mt-0.5 text-[11px] font-bold text-ink-success"
                      >{{ td('academy.completed') }}</span
                    >
                    <Icon name="chevron_right" :size="18" class="text-ink-card-subtle shrink-0 mt-0.5" />
                  </RouterLink>

                  <!-- ── ADR-031 §2.2/§2.3 — WHY this lesson is locked ────
                       The server's own sentence, VERBATIM (`lock_message`):
                       "ต้องเรียนบทก่อนหน้าให้จบก่อน" and "จะเปิดในอีก 3 วัน"
                       are different problems — one learner goes and finishes
                       a lesson, the other waits — and only LessonAccessGate
                       knows which rule bit. The countdown is rendered here
                       rather than server-side because the enum message
                       deliberately carries no date, so a cached response
                       cannot show a stale one. -->
                  <div v-if="l.is_locked" class="mt-2 pl-8 rounded-lg bg-surface-chip px-3 py-2.5">
                    <p class="text-xs font-bold text-ink-card-muted leading-relaxed">{{ l.lock_message }}</p>
                    <p v-if="lockCountdownText(l)" class="text-[11px] font-bold text-ink-brand mt-1">
                      {{ lockCountdownText(l) }}
                    </p>
                  </div>
                </AppCard>
              </TransitionGroup>
            </AppList>
          </template>
        </div>

        <!-- ── 3. แบบประเมินผล ─────────────────────────────────────
             TASK-152a — NOT "แบบทดสอบ...": that word belongs exclusively to
             the end-of-lesson quiz, and a learner cannot be asked to tell the
             two apart by their suffix. The subtitle states the BR-1
             consequence, because that is what actually distinguishes them —
             one costs you a lesson, this one costs you your selling rights. -->
        <AppListGroupHeader :label="td('academy.assessment')" :count="exams.length" />
        <p class="px-4 pb-2 text-[11px] text-ink-card-subtle">
          {{ td('academy.cert_exam') }}
        </p>
        <EmptyState v-if="!exams.length" icon="check_square" :title="td('academy.no_exam')" />
        <AppList v-else>
          <TransitionGroup name="list-fade">
            <AppCard v-for="ex in exams" :id="'exam-' + ex.id" :key="ex.id" variant="flat">
              <!-- The latest-attempt result sits on the META line, not in a
                   right-aligned `whitespace-nowrap` chip: as a chip it was the
                   widest thing on the row and guaranteed to crush a long
                   English exam title. -->
              <RouterLink
                :to="`/academy/exams/${ex.id}`"
                class="w-full text-left flex items-start gap-3 min-w-0 active:opacity-70 transition-opacity"
              >
                <Icon
                  name="check_square"
                  :size="20"
                  class="mt-0.5 shrink-0"
                  :class="passedTierKeys.has(ex.cert_tier?.key) ? 'text-ink-success' : 'text-ink-card-subtle'"
                />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-bold text-ink-card break-words">{{ ex.title }}</p>
                  <p class="text-[11px] text-ink-card-subtle mt-0.5">
                    {{ ex.cert_tier?.name }} · เกณฑ์ผ่าน {{ ex.passing_score }} คะแนน
                  </p>
                  <p
                    v-if="latestAttemptFor(ex.id)"
                    class="text-[11px] font-bold mt-0.5"
                    :class="latestAttemptFor(ex.id)?.passed ? 'text-ink-success' : 'text-ink-danger'"
                  >
                    ล่าสุด: {{ latestAttemptFor(ex.id)?.score }} คะแนน ({{
                      latestAttemptFor(ex.id)?.passed ? 'ผ่าน' : 'ไม่ผ่าน'
                    }})
                  </p>
                  <p class="text-[11px] mt-0.5 text-ink-brand font-bold">
                    {{ latestAttemptFor(ex.id) ? 'ทำแบบประเมินผลอีกครั้ง' : 'เริ่มทำแบบประเมินผล' }}
                  </p>
                </div>
                <Icon name="chevron_right" :size="18" class="text-ink-card-subtle shrink-0 mt-0.5" />
              </RouterLink>
            </AppCard>
          </TransitionGroup>
        </AppList>

        <!-- ── 4. EARNED CERTIFICATES ──────────────────────────────
             At the BOTTOM: a certificate is an ARCHIVE — proof of something
             already finished — so it belongs after the work still to do. -->
        <template v-if="certifications.length">
          <AppListGroupHeader :label="td('academy.certs_earned')" :count="certifications.length" />
          <AppList>
            <TransitionGroup name="list-fade">
              <AppCard v-for="c in certifications" :key="c.id" variant="flat">
                <div class="flex flex-col gap-2">
                  <div class="flex items-start gap-3 min-w-0 flex-1">
                    <Icon name="check_circle" :size="20" class="text-ink-success mt-0.5 shrink-0" />
                    <div class="min-w-0">
                      <p class="text-sm font-bold text-ink-card break-words">
                        ใบรับรอง {{ c.cert_tier?.name }}
                      </p>
                      <p class="text-[11px] text-ink-card-subtle mt-0.5">
                        ผ่านเมื่อ {{ formatAcademyDate(c.passed_at) }}
                      </p>
                    </div>
                  </div>
                  <!-- TASK-166 — DOWNLOAD BUTTON PARKED. The reason is in the
                       comment on downloadCertificate() above; un-comment this
                       block and delete that comment to restore it. The
                       endpoint stays live and Policy-protected (it is
                       self-scoped) — removing it would buy nothing.

                  <div class="flex items-center gap-2 shrink-0 pl-8">
                    <button
                      :disabled="downloadingCertId === c.id"
                      class="min-h-[44px] px-3 py-1.5 rounded-lg text-xs font-bold text-ink-success bg-surface-success hover:bg-emerald-100 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center justify-center gap-1.5 whitespace-nowrap"
                      @click="downloadCertificate(c)"
                    >
                      <Icon name="download" :size="14" />
                      {{ downloadingCertId === c.id ? 'กำลังดาวน์โหลด...' : 'ดาวน์โหลด PDF' }}
                    </button>
                  </div>
                  ───────────────────────────────────────────────────────── -->
                </div>
              </AppCard>
            </TransitionGroup>
          </AppList>
        </template>
      </div>
    </Transition>
  </main>
</template>
