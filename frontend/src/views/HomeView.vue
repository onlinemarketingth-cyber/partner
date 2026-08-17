<script setup lang="ts">
/**
 * HomeView — personal home hub for the Agent Portal mobile app
 * (TASK-053 Phase 3). Aggregates /me/home + /me/tasks + /announcements.
 *
 * All figures (XP, goals, money) come straight from the API — nothing
 * is hardcoded (BR-3/BR-7). Money is integer satang server-side and is
 * divided by 100 only here, at the display layer (BR-3).
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — one shared error normalizer, no raw HTTP
// status codes in user-facing copy (utils/apiError.ts).
// TASK-079 Phase 4 — isAbortError() so leaving the page mid-load doesn't
// surface as "your internet is down" (see the controller below).
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useAuthStore } from '@/stores/auth'
import AppButton from '@/design-system/components/AppButton.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ProgressRing from '@/design-system/components/ProgressRing.vue'
import AnnouncementModal, { type AnnouncementDisplayStyle } from '@/design-system/components/AnnouncementModal.vue'
// TASK-080 — announcements can also render as an inline banner carousel.
import AnnouncementBanner from '@/design-system/components/AnnouncementBanner.vue'
import { bannerAnnouncementsForPage, type BannerAwareAnnouncement } from '@/utils/announcementBanners'
import { getAnnouncementViewCount, recordAnnouncementView } from '@/utils/seenAnnouncements'
import { initials } from '@/utils/initials'

// TASK-076 (2026-08-02, human request: "ระบบ banner ข่าวสารให้เปิดอย่าง
// น้อย 4 ครั้ง ถึงไม่ขึ้น") — used only if GET /announcement-settings
// fails; matches config/announcements.php's own platform default so the
// UX degrades to the same behavior the backend would've returned anyway.
const FALLBACK_REPEAT_COUNT = 4
// TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) — same
// fallback-on-fetch-failure pattern, matches config/announcements.php's
// default_display_style.
const FALLBACK_DISPLAY_STYLE: AnnouncementDisplayStyle = 'bottom_sheet'

interface HomeGoal {
  metric: 'sales_satang' | 'deals' | 'clients' | string
  metric_label: string
  target_value: number
  actual_value: number
  progress: number
}
interface HomeData {
  profile: { id: number; name: string; avatar_url: string | null }
  gamification: {
    level_number: number
    total_xp: number
    level_xp_floor: number
    next_level_xp: number
    badges_count: number
  }
  goals: HomeGoal[]
  task_counts: { follow_ups_due: number; open_deals: number; failed_exams: number }
  unread_notifications: number
  // TASK-107 / ADR-024 §9 — how many agents report directly to the caller.
  // Server-derived from users.manager_id (never a flag the client asserts),
  // and carried on THIS payload specifically so Home can decide whether to
  // render the "ทีมของฉัน" menu entry without a second request.
  direct_reports_count: number
}
interface TasksData {
  follow_ups: Array<{
    id: number
    client_id: number
    client_name: string
    summary: string | null
    follow_up_at: string
  }>
  open_deals: Array<{
    id: number
    client_name: string
    product_name: string | null
    stage_key: string
    stage_label: string
  }>
  failed_exams: Array<{ id: number; title: string; passing_score: number }>
}
// TASK-080 — the /announcements payload now also carries the three
// display flags (show_as_modal / show_as_banner / banner_pages) on top of
// what AnnouncementModal renders; see utils/announcementBanners.ts.
type Announcement = BannerAwareAnnouncement

const BRAND = '#2F4183'

const auth = useAuthStore()
const loading = ref(true)
const errorMessage = ref('')
const home = ref<HomeData | null>(null)
const tasks = ref<TasksData | null>(null)
const news = ref<Announcement[]>([])
// TASK-080 — banners are filtered from the FULL response, not from the
// 5-item `news` preview slice below: an announcement flagged as a banner
// must appear as one even when it falls outside the ข่าวสาร preview.
const bannerNews = ref<Announcement[]>([])

// TASK-075/076 (2026-08-02) — a large full-screen modal must auto-pop up
// on Home load for the newest announcement the agent hasn't reached the
// admin-configured view limit on yet (view counts kept in localStorage
// — see utils/seenAnnouncements.ts; the limit itself is admin-editable,
// BR-7, fetched from GET /announcement-settings). Tapping a news card
// manually opens the same modal and also counts as a view.
const modalAnnouncement = ref<Announcement | null>(null)
const showAnnouncementModal = ref(false)
// TASK-077 — admin-configured display style (BR-7, one global value per
// company), fetched alongside repeat_count from the same settings call.
const announcementDisplayStyle = ref<AnnouncementDisplayStyle>(FALLBACK_DISPLAY_STYLE)
// bottom_strip only: auto-popup opens collapsed (non-blocking), a manual
// card tap opens already-expanded — see AnnouncementModal.vue.
const modalStartExpanded = ref(true)

function openAnnouncement(a: Announcement, opts: { auto?: boolean } = {}) {
  modalAnnouncement.value = a
  modalStartExpanded.value = !opts.auto
  showAnnouncementModal.value = true
  recordAnnouncementView(auth.user?.id, a.id)
}
function closeAnnouncementModal() {
  showAnnouncementModal.value = false
}

// TASK-079 Phase 4 (2026-08-03, UX audit) — one controller for this
// view's whole lifetime. Home is the app's landing screen and fires 4
// requests at once, so it is the most likely place for an agent to tap
// straight through to another tab mid-load; without this those 4 keep
// running and resolve into a dead component.
const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [homeRes, tasksRes, newsRes, settingsRes] = await Promise.all([
      api.get<{ data: HomeData }>('/me/home', pageAbort.signal),
      api.get<{ data: TasksData }>('/me/tasks', pageAbort.signal),
      api.get<{ data: Announcement[] }>('/announcements', pageAbort.signal),
      api.get<{ data: { repeat_count: number; display_style: AnnouncementDisplayStyle } }>('/announcement-settings', pageAbort.signal).catch(() => null),
    ])
    home.value = homeRes.data
    tasks.value = tasksRes.data
    news.value = newsRes.data.slice(0, 5)
    bannerNews.value = bannerAnnouncementsForPage(newsRes.data, 'home')

    const repeatCount = settingsRes?.data.repeat_count ?? FALLBACK_REPEAT_COUNT
    announcementDisplayStyle.value = settingsRes?.data.display_style ?? FALLBACK_DISPLAY_STYLE

    // Backend already orders is_pinned desc, published_at desc — the
    // first item in the full (unsliced) response that hasn't yet
    // reached the view limit is the "newest" one to auto-pop.
    //
    // TASK-080 — an announcement can now opt OUT of the modal entirely
    // (show_as_modal false = "banner only"), so the auto-popup must skip
    // those rather than pop a card the admin explicitly said should only
    // live inline. The view-count / repeat_count logic below is
    // unchanged — this is only an extra eligibility condition.
    const notExhausted = newsRes.data.find(
      (a) => a.show_as_modal && getAnnouncementViewCount(auth.user?.id, a.id) < repeatCount,
    )
    if (notExhausted) openAnnouncement(notExhausted, { auto: true })
  } catch (e) {
    // TASK-079 Phase 4 — a load we cancelled ourselves is not a failure;
    // never paint an error banner for a screen the agent already left.
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
onMounted(loadAll)

const firstName = computed(() => auth.user?.first_name || home.value?.profile.name || auth.user?.name || '')
const companyName = computed(() => auth.user?.company?.name ?? '')

// TASK-079 Phase 1 — every destination that is NOT a BottomNav tab lives
// here, unconditionally. See the template comment on the เมนูทั้งหมด grid
// for why this replaced the old conditional quick-action cards.
// /referrals is absent on purpose: it became a BottomNav tab in this same
// phase. /leaderboard is on hold per human instruction (2026-08-03).
interface MenuLink {
  to: string
  icon: string
  label: string
}

const BASE_MENU_LINKS: MenuLink[] = [
  { to: '/orders', icon: 'cart', label: 'คำสั่งซื้อ' },
  { to: '/products', icon: 'box', label: 'สินค้า' },
  { to: '/pipeline', icon: 'pipeline', label: 'กระบวนการขาย' },
  { to: '/affiliate-links', icon: 'link', label: 'ลิงก์พันธมิตร' },
  { to: '/announcements', icon: 'megaphone', label: 'ข่าวสาร' },
  { to: '/profile', icon: 'user', label: 'โปรไฟล์' },
]

/**
 * TASK-109 / ADR-024 §9 — "ทีมของฉัน" is the ONE conditional entry in this
 * grid, shown only to an agent who actually has direct reports.
 *
 * Here rather than as a sixth BottomNav tab, for the reasons ADR-024 §9
 * records: the bar has five slots on a 375px screen and Thai labels stop
 * being legible below 11px, and a tab that exists for some agents and not
 * others breaks the fixed-position muscle memory TASK-079 established.
 *
 * APPENDED, never inserted: the whole point of the fixed grid (see the
 * template comment below) is that every destination keeps a stable
 * position. Putting a conditional entry anywhere but last would shift the
 * six unconditional ones for leaders only.
 *
 * `direct_reports_count` is the server's answer, not ours — and it is only
 * a rendering hint either way: /me/team re-derives leadership itself and
 * answers is_leader:false for a non-leader who navigates there directly,
 * so hiding this tile never stands in for authorisation.
 */
const menuLinks = computed<MenuLink[]>(() => {
  const links = [...BASE_MENU_LINKS]
  // Two independent reasons to show the entry, and BOTH are needed.
  //
  // `direct_reports_count > 0` is the monitor case (ADR-024 §9).
  //
  // `is_team_leader` is the recruiting case (ADR-025 §2), and it was
  // missing — which made the feature unreachable for exactly the person
  // it exists for. A freshly designated leader has zero reports, so the
  // tile never rendered, so they could not open /my-team, so they could
  // not mint their first invite link, so they could never get a first
  // report. Chicken-and-egg: the only escape was typing the URL by hand.
  const isMonitor = (home.value?.direct_reports_count ?? 0) > 0
  const isRecruiter = auth.user?.is_team_leader === true
  if (isMonitor || isRecruiter) {
    links.push({ to: '/my-team', icon: 'users', label: 'ทีมของฉัน' })
  }
  return links
})

// Level ring fraction — guard divide-by-zero (treat as full when the
// level has no defined XP span yet).
const levelFraction = computed(() => {
  const g = home.value?.gamification
  if (!g) return 0
  const span = g.next_level_xp - g.level_xp_floor
  if (span <= 0) return 1
  return (g.total_xp - g.level_xp_floor) / span
})

// Primary goal: prefer the sales target, else the first goal (if any).
const primaryGoal = computed<HomeGoal | null>(() => {
  const goals = home.value?.goals ?? []
  if (!goals.length) return null
  return goals.find((g) => g.metric === 'sales_satang') ?? goals[0] ?? null
})
const otherGoals = computed<HomeGoal[]>(() => {
  const goals = home.value?.goals ?? []
  const primary = primaryGoal.value
  return goals.filter((g) => g !== primary)
})

function formatGoalValue(goal: HomeGoal, value: number): string {
  if (goal.metric === 'sales_satang') {
    return '฿' + (value / 100).toLocaleString('th-TH')
  }
  return value.toLocaleString('th-TH')
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH')
}

const allTasksEmpty = computed(
  () =>
    !!tasks.value &&
    !tasks.value.follow_ups.length &&
    !tasks.value.open_deals.length &&
    !tasks.value.failed_exams.length,
)
</script>

<template>
  <!-- TASK-079 Phase 4 (2026-08-03, UX audit): the app had TWO page shells.
       Nine views used <main class="min-h-screen px-4 py-6 lg:px-8"> + a real
       <HeroHeader>; four (Home, Orders, Notifications, Announcements) were
       hand-rolled `px-4 py-4` divs with a bare <h1> — so the header band,
       the horizontal padding and the vertical rhythm all changed as the
       agent moved between tabs. All 13 views now share this one shell.

       Follow-up (2026-08-03, human request): Home is the ONE view with no
       <HeroHeader>. A "หน้าหลัก / สรุปงานและข่าวสารของคุณ" band here was
       pure redundancy — the BottomNav already shows หน้าหลัก as the active
       tab and the top bar already carries the company logo, so the card
       spent ~72px of a phone screen restating what the chrome says. Every
       OTHER view keeps its HeroHeader: they need the title (you can arrive
       there from several places) and the secondary ones need its back
       button. The greeting card below is the real first element. -->
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: var(--app-font);">
    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <!-- Loading skeleton. TASK-079 Phase 4 (UX audit): this used to be a
           hand-rolled stack of `animate-pulse` boxes — one of 5 views that
           each invented their own, so "loading" looked different on every
           screen while LoadingSkeleton.vue sat unused next door. `dashboard`
           is the closest match to what actually paints here (KPI-ish ring
           row + a content block). -->
      <LoadingSkeleton v-if="loading" type="dashboard" />

      <!-- Error. `mt-4` dropped along with the HeroHeader (see the shell
           comment above) — with no header card above it, that margin sat on
           top of <main>'s own py-6 and pushed the first card down twice. -->
      <div
        v-else-if="errorMessage"
        class="bg-surface-card/95 border border-line-card rounded-2xl shadow-sm p-5 flex flex-col items-center gap-3 text-center"
      >
        <Icon name="alert" :size="32" class="text-ink-danger" />
        <p class="text-sm text-ink-card-muted font-bold">{{ errorMessage }}</p>
        <AppButton @click="loadAll">ลองใหม่</AppButton>
      </div>

      <div v-else-if="home" class="space-y-4">
        <!-- a. Greeting / profile -->
        <AppCard class="flex items-center gap-3">
          <img
            v-if="home.profile.avatar_url"
            :src="home.profile.avatar_url"
            alt=""
            class="w-12 h-12 rounded-full object-cover border border-line-card"
          />
          <span
            v-else
            class="w-12 h-12 rounded-full bg-brand-100 text-brand-700 font-bold flex items-center justify-center"
          >
            {{ initials(home.profile.name) }}
          </span>
          <div class="min-w-0">
            <p class="text-base font-bold text-ink-card truncate">สวัสดี, {{ firstName }}</p>
            <p v-if="companyName" class="text-xs text-ink-card-muted truncate">{{ companyName }}</p>
          </div>
        </AppCard>

        <!-- TASK-080 — announcement banners (page key 'home'). Sits high
             on the page, directly under the greeting: a banner is the
             non-blocking alternative to the auto-popup modal, so it has
             to be visible without scrolling to be worth anything.
             Renders nothing when there are no banner-flagged
             announcements, so the space-y-4 rhythm is untouched. -->
        <AnnouncementBanner :items="bannerNews" @select="openAnnouncement" />

        <!-- ── เมนูทั้งหมด (TASK-079 Phase 1, 2026-08-03 UX audit) ──
             Replaces the two stand-alone quick-action cards (Orders,
             Products) that used to sit here. The audit found several pages
             were effectively invisible: /affiliate-links had NO entry point
             anywhere in the app, and /pipeline + /announcements were
             rendered on Home only when their data was non-empty — so a new
             agent with no deals and no news could not discover them at all.
             A fixed grid means every destination is always present at a
             stable position, which also makes it muscle-memory-able (the
             conditional cards moved around depending on data).
             /leaderboard is deliberately NOT listed here — human instruction
             2026-08-03 put it on hold. -->
        <AppCard padding="sm">
          <p class="text-xs font-bold text-ink-card-subtle px-1 pb-2">เมนูทั้งหมด</p>
          <div class="grid grid-cols-3 gap-1">
            <RouterLink
              v-for="link in menuLinks"
              :key="link.to"
              :to="link.to"
              class="flex flex-col items-center justify-center gap-1.5 py-3 px-1 rounded-xl transition-all active:scale-95 active:bg-surface-chip"
            >
              <span class="w-11 h-11 rounded-xl bg-brand-100 text-brand-700 flex items-center justify-center shrink-0">
                <Icon :name="link.icon" :size="20" />
              </span>
              <span class="text-[11px] font-bold text-ink-card text-center leading-tight">{{ link.label }}</span>
            </RouterLink>
          </div>
        </AppCard>

        <!-- b. Goal rings -->
        <div class="grid grid-cols-2 gap-3">
          <!-- Ring 1: Level -->
          <AppCard class="flex flex-col items-center gap-2">
            <ProgressRing
              :fraction="levelFraction"
              :center-text="'Lv ' + home.gamification.level_number"
              label="เลเวล"
              :color="BRAND"
            />
            <div class="text-center">
              <p class="text-sm font-bold text-ink-card">
                {{ home.gamification.total_xp.toLocaleString('th-TH') }} XP
              </p>
              <p class="text-xs text-ink-card-muted">{{ home.gamification.badges_count }} เหรียญตรา</p>
            </div>
          </AppCard>

          <!-- Ring 2: Primary goal -->
          <AppCard class="flex flex-col items-center gap-2">
            <template v-if="primaryGoal">
              <ProgressRing
                :fraction="primaryGoal.progress / 100"
                :center-text="primaryGoal.progress + '%'"
                label="เป้าหมาย"
                :color="BRAND"
              />
              <div class="text-center">
                <p class="text-xs font-bold text-ink-card-muted">{{ primaryGoal.metric_label }}</p>
                <p class="text-sm font-bold text-ink-card">
                  {{ formatGoalValue(primaryGoal, primaryGoal.actual_value) }} /
                  {{ formatGoalValue(primaryGoal, primaryGoal.target_value) }}
                </p>
              </div>
            </template>
            <template v-else>
              <ProgressRing :fraction="0" center-text="—" label="เป้าหมาย" :color="BRAND" />
              <p class="text-xs text-ink-card-subtle text-center">ยังไม่ได้ตั้งเป้าหมาย</p>
            </template>
          </AppCard>
        </div>

        <!-- Additional goals as slim bars -->
        <div v-if="otherGoals.length" class="space-y-2">
          <AppCard v-for="goal in otherGoals" :key="goal.metric" padding="sm">
            <div class="flex items-center justify-between mb-1.5">
              <span class="text-xs font-bold text-ink-card-muted">{{ goal.metric_label }}</span>
              <span class="text-xs font-bold text-ink-card">{{ goal.progress }}%</span>
            </div>
            <div class="h-2 rounded-full bg-slate-200 overflow-hidden">
              <div
                class="h-full rounded-full bg-brand-500"
                :style="{ width: Math.min(100, Math.max(0, goal.progress)) + '%' }"
              ></div>
            </div>
          </AppCard>
        </div>

        <!-- c. งานที่ต้องทำ — TASK-079 Phase 3 (UX audit): the task/news cards
             below only ever had `hover:shadow-md`, which never fires on a
             touchscreen, so a tap produced no visual response at all and
             agents tapped twice. `active:scale-[0.98]` is the touch
             equivalent; the menu grid above already got one in Phase 1. -->
        <div class="space-y-3">
          <!-- TASK-098 — page-level heading: ink-app, not ink-card. It sits on
               the company background, and a tenant with a light background but a
               dark card would get card ink painted onto it. -->
          <h2 class="text-sm font-bold text-ink-app">งานที่ต้องทำ</h2>
          <div class="grid grid-cols-3 gap-2">
            <AppCard padding="sm" class="flex flex-col items-center gap-1">
              <Icon name="clock" :size="20" class="text-ink-warning" />
              <span class="text-lg font-bold text-ink-card">{{ home.task_counts.follow_ups_due }}</span>
              <span class="text-[11px] leading-tight text-ink-card-muted text-center">ติดตามลูกค้า</span>
            </AppCard>
            <AppCard padding="sm" class="flex flex-col items-center gap-1">
              <Icon name="pipeline" :size="20" class="text-ink-brand" />
              <span class="text-lg font-bold text-ink-card">{{ home.task_counts.open_deals }}</span>
              <span class="text-[11px] leading-tight text-ink-card-muted text-center">ดีลที่ค้าง</span>
            </AppCard>
            <AppCard padding="sm" class="flex flex-col items-center gap-1">
              <Icon name="brain" :size="20" class="text-ink-danger" />
              <span class="text-lg font-bold text-ink-card">{{ home.task_counts.failed_exams }}</span>
              <span class="text-[11px] leading-tight text-ink-card-muted text-center">สอบที่ยังไม่ผ่าน</span>
            </AppCard>
          </div>

          <!-- All-empty state -->
          <div
            v-if="allTasksEmpty"
            class="bg-surface-card/95 border border-dashed border-line-card rounded-2xl p-4 flex items-center justify-center gap-2 text-ink-card-muted"
          >
            <Icon name="check_circle" :size="20" class="text-ink-success" />
            <span class="text-sm font-bold">ไม่มีงานค้าง</span>
          </div>

          <!-- Follow-ups. TASK-079 Phase 4 — the surface is <AppCard interactive>
               now; the RouterLink stays the outer element so this is still a
               real link (long-press / open-in-new-tab keep working), and
               `interactive` carries over Phase 3's active:scale press state. -->
          <div v-if="tasks?.follow_ups.length" class="space-y-2">
            <RouterLink v-for="f in tasks.follow_ups" :key="f.id" to="/clients" class="block">
              <AppCard interactive padding="sm">
                <div class="flex items-center gap-2">
                  <Icon name="clock" :size="16" class="text-ink-warning shrink-0" />
                  <span class="text-sm font-bold text-ink-card truncate">{{ f.client_name }}</span>
                  <span class="ml-auto text-[11px] text-ink-card-subtle shrink-0">{{ formatDate(f.follow_up_at) }}</span>
                </div>
                <p v-if="f.summary" class="text-xs text-ink-card-muted line-clamp-1 mt-0.5">{{ f.summary }}</p>
              </AppCard>
            </RouterLink>
          </div>

          <!-- Open deals -->
          <div v-if="tasks?.open_deals.length" class="space-y-2">
            <RouterLink v-for="d in tasks.open_deals" :key="d.id" to="/pipeline" class="block">
              <AppCard interactive padding="sm">
                <div class="flex items-center gap-2">
                  <Icon name="pipeline" :size="16" class="text-ink-brand shrink-0" />
                  <span class="text-sm font-bold text-ink-card truncate">{{ d.client_name }}</span>
                  <span class="ml-auto text-[11px] font-bold px-2 py-0.5 rounded-full bg-surface-chip text-ink-card-muted shrink-0">
                    {{ d.stage_label }}
                  </span>
                </div>
                <p v-if="d.product_name" class="text-xs text-ink-card-muted line-clamp-1 mt-0.5">{{ d.product_name }}</p>
              </AppCard>
            </RouterLink>
          </div>

          <!-- Failed exams -->
          <div v-if="tasks?.failed_exams.length" class="space-y-2">
            <RouterLink v-for="ex in tasks.failed_exams" :key="ex.id" to="/academy" class="block">
              <AppCard interactive padding="sm">
                <div class="flex items-center gap-2">
                  <Icon name="brain" :size="16" class="text-ink-danger shrink-0" />
                  <span class="text-sm font-bold text-ink-card truncate">{{ ex.title }}</span>
                </div>
                <p class="text-xs text-ink-card-muted mt-0.5">เกณฑ์ผ่าน {{ ex.passing_score }}%</p>
              </AppCard>
            </RouterLink>
          </div>
        </div>

        <!-- d. ข่าวสาร -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-ink-app">ข่าวสาร</h2>
            <AppButton v-if="news.length" to="/announcements" variant="ghost" size="sm" class="-mr-2">
              ดูทั้งหมด
            </AppButton>
          </div>
          <div
            v-if="!news.length"
            class="bg-surface-card/95 border border-dashed border-line-card rounded-2xl p-4 flex items-center justify-center gap-2 text-ink-card-muted"
          >
            <Icon name="megaphone" :size="20" class="text-ink-card-subtle" />
            <span class="text-sm">ยังไม่มีข่าวสาร</span>
          </div>
          <div v-else class="space-y-2">
            <button v-for="a in news" :key="a.id" type="button" class="w-full text-left" @click="openAnnouncement(a)">
              <AppCard interactive padding="sm" class="flex items-center gap-3">
                <img
                  v-if="a.image_url"
                  :src="a.image_url"
                  alt=""
                  class="w-12 h-12 rounded-xl object-cover border border-line-card shrink-0"
                />
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5">
                    <Icon v-if="a.is_pinned" name="star" :size="14" class="text-ink-warning shrink-0" />
                    <p class="text-sm font-bold text-ink-card truncate">{{ a.title }}</p>
                  </div>
                  <p v-if="a.published_at" class="text-[11px] text-ink-card-subtle mt-0.5">{{ formatDate(a.published_at) }}</p>
                </div>
              </AppCard>
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <AnnouncementModal
      :show="showAnnouncementModal"
      :announcement="modalAnnouncement"
      :display-style="announcementDisplayStyle"
      :start-expanded="modalStartExpanded"
      @close="closeAnnouncementModal"
    />
  </main>
</template>
