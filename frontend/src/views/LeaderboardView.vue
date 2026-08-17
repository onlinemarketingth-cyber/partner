<script setup lang="ts">
/**
 * LeaderboardView — gamification ranking, wired to the real API.
 *
 * Uses the gold accent (not brand/navy) per CI-002: gold is the
 * designated gamification/success-moment color project-wide (XP,
 * badges, leaderboard).
 *
 * BR-5: XP comes from (a) learning/certification and (b) sales
 * pipeline progress; rates and badge conditions live in
 * `gamification_rules`/`badges` config — never hardcoded, so no XP
 * numbers appear anywhere in this file, only values as returned by
 * the API.
 *
 * "Level" (Phase 9): /leaderboard now returns level_number and
 * next_level_xp_required per row, computed server-side by LevelService
 * from the Admin-configured level_thresholds table (BR-7) — no formula
 * lives in this file, only values as returned by the API.
 *
 * The original placeholder had weekly/monthly/all_time period tabs,
 * but /leaderboard only returns an all-time total (no period
 * filtering was built server-side this phase) — removed rather than
 * shipping tabs that silently do nothing.
 *
 * Bug fix: GET /leaderboard requires an explicit ?company_id= when the
 * caller is Super Admin (they have no single "own company" to default
 * to — see LeaderboardController). This Agent Portal screen has no
 * company picker (Super Admin is meant to use the Admin app for
 * cross-company views), so it used to fire the request anyway and show
 * a raw "โหลดข้อมูลไม่สำเร็จ (422)" — confusing, since nothing was
 * actually broken. Now it skips the call entirely for Super Admin and
 * shows a clear explanation instead.
 *
 * UI (2026-07-12): restyled to a "#1 spotlight card + ranked list"
 * layout per a reference design the human supplied. Adapted to fields
 * this API actually returns rather than copying the reference
 * literally — it showed a profile photo, a job-title subtitle, and a
 * "time" stat that don't exist anywhere in this data model (User has
 * no avatar/photo, no title field, and there's no "time" metric in
 * BR-5's XP model). Substituted: initials avatar (no photo upload
 * feature exists), "Lv.X" subtitle (already-real per-row data from
 * LevelService, see above), and the star row is driven by
 * row.level_number (capped at 3), not a decorative/fixed count — same
 * "never show a number the API didn't return" rule as the rest of this
 * file.
 */
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — see the Super Admin note above: this
// screen is exactly where the audit's "โหลดข้อมูลไม่สำเร็จ (422)" example
// came from. apiErrorMessage() keeps status codes out of the UI for good.
import { apiErrorMessage } from '@/utils/apiError'
import { initials } from '@/utils/initials'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

interface LeaderboardRow {
  rank: number
  user: { id: number; name: string; avatar_url: string | null }
  total_xp: number
  level_number: number
  next_level_xp_required: number | null
}

interface UserBadgeItem {
  id: number
  user: { id: number; name: string } | null
  badge: { id: number; key: string; name: string; icon: string } | null
  earned_at: string
}

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const rows = ref<LeaderboardRow[]>([])
const badges = ref<UserBadgeItem[]>([])

const myRow = computed(() => rows.value.find((r) => r.user.id === auth.user?.id) ?? null)
const topRow = computed(() => rows.value[0] ?? null)

/** Podium-style pill coloring: rank 1/2-3/rest, not tied to any business rule. */
function pillClass(rank: number): string {
  if (rank === 1) return 'bg-gold-600 text-white'
  if (rank <= 3) return 'bg-amber-500 text-white'
  return 'bg-slate-500 text-white'
}

const kpis = computed(() => [
  { label: 'XP ของคุณ', value: myRow.value ? myRow.value.total_xp.toLocaleString('th-TH') : '—' },
  { label: 'อันดับของคุณ', value: myRow.value ? `#${myRow.value.rank}` : '—' },
  { label: 'Level ปัจจุบัน', value: myRow.value ? `Lv.${myRow.value.level_number}` : '—' },
])

async function loadAll() {
  // Super Admin has no single "own company" — /leaderboard would
  // always 422 asking for one, and this screen has no company picker
  // (that's the Admin app's job). Skip the call and explain, rather
  // than showing a raw HTTP error.
  if (isSuperAdmin.value) {
    hasLoadedOnce.value = true
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    const [leaderboardRes, badgesRes] = await Promise.all([
      api.get<{ data: LeaderboardRow[] }>('/leaderboard'),
      api.get<{ data: UserBadgeItem[] }>('/user-badges'),
    ])
    rows.value = leaderboardRes.data
    badges.value = badgesRes.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="trophy"
      icon-color="text-gold-600"
      title="Leaderboard"
      subtitle="อันดับ XP ของทีมขาย"
      description="XP มาจากการเรียนจบ/ผ่านใบรับรอง และความคืบหน้าใน Pipeline (BR-5)"
      :kpis="kpis"
      accent-color="gold"
      storage-key="leaderboard"
    />

    <div v-if="isSuperAdmin" class="mt-4 px-4 py-3 rounded-xl bg-surface-chip border border-line-card text-sm text-ink-card-muted">
      Leaderboard แยกตามบริษัท — Super Admin กรุณาดูผ่าน Admin app แทน (หน้านี้ไม่มีตัวเลือกบริษัท)
    </div>
    <!-- TASK-079 Phase 2 (UX audit): dead-end error banner — retry lets the
         agent recover without reloading the whole SPA. -->
    <div v-else-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadAll"
      >
        ลองใหม่
      </button>
    </div>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="!isSuperAdmin && loading && !hasLoadedOnce" type="list" :rows="5" class="mt-4" />
      <div v-else-if="!isSuperAdmin">
        <h2 class="mt-4 mb-2 text-sm font-bold text-ink-card px-1">Badge ที่ได้รับ</h2>
        <EmptyState
          v-if="!badges.length"
          icon="star"
          title="ยังไม่ได้รับ badge"
          message="Badge จะได้รับจากการมอบโดย Company Admin — เงื่อนไขอัตโนมัติยังไม่เปิดใช้งาน (BR-7)"
        />
        <TransitionGroup v-else tag="div" name="list-fade" class="grid grid-cols-2 md:grid-cols-4 gap-2">
          <div v-for="b in badges" :key="b.id" class="bg-surface-card/95 border border-line-card rounded-xl p-4 text-center">
            <Icon :name="b.badge?.icon ?? 'star'" :size="24" class="text-gold-600 mx-auto" />
            <p class="text-xs font-bold text-ink-card mt-2">{{ b.badge?.name }}</p>
          </div>
        </TransitionGroup>

        <h2 class="mt-6 mb-2 text-sm font-bold text-ink-card px-1">อันดับ XP</h2>
        <EmptyState
          v-if="!rows.length"
          icon="trophy"
          title="ยังไม่มีข้อมูลอันดับ"
          message="XP จะถูกบันทึกอัตโนมัติเมื่อเรียนจบโมดูล ผ่านใบรับรอง หรือมีความคืบหน้าใน Pipeline (BR-5)"
        />
        <div v-else-if="topRow" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <!-- Spotlight card — rank 1 -->
          <div class="lg:col-span-1 bg-surface-card/95 border border-gold-200 rounded-2xl p-6 shadow-sm flex flex-col items-center text-center">
            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gold-600 text-white text-xs font-bold">
              <Icon name="trophy" :size="14" />
              อันดับ 1
            </span>
            <img
              v-if="topRow.user.avatar_url"
              :src="topRow.user.avatar_url"
              :alt="topRow.user.name"
              class="w-24 h-24 rounded-full ring-4 ring-gold-100 object-cover mt-4"
            />
            <div v-else class="w-24 h-24 rounded-full bg-gold-50 ring-4 ring-gold-100 flex items-center justify-center text-2xl font-bold text-gold-700 mt-4">
              {{ initials(topRow.user.name) }}
            </div>
            <div class="flex items-center gap-0.5 mt-3">
              <svg
                v-for="n in 3"
                :key="n"
                width="16"
                height="16"
                viewBox="0 0 24 24"
                :fill="n <= Math.min(topRow.level_number, 3) ? 'currentColor' : 'none'"
                stroke="currentColor"
                stroke-width="1.5"
                class="text-gold-500"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                />
              </svg>
            </div>
            <p class="mt-3 text-base font-bold text-ink-card">
              {{ topRow.user.name }}
            </p>
            <p v-if="topRow.user.id === auth.user?.id" class="text-xs font-bold text-gold-600">(คุณ)</p>
            <p class="text-xs text-ink-card-muted mt-0.5">Lv.{{ topRow.level_number }}</p>
            <p class="mt-4 text-3xl font-bold text-gold-600 leading-none">{{ topRow.total_xp.toLocaleString('th-TH') }}</p>
            <p class="text-xs font-bold text-ink-card-muted mt-1">XP</p>
          </div>

          <!-- Full ranked list, including rank 1 again -->
          <TransitionGroup tag="div" name="list-fade" class="lg:col-span-2 space-y-2">
            <div
              v-for="row in rows"
              :key="row.user.id"
              class="bg-surface-card/95 border rounded-xl p-3 flex items-center gap-3"
              :class="row.user.id === auth.user?.id ? 'border-gold-400 ring-1 ring-gold-200' : 'border-line-card'"
            >
              <span class="w-6 shrink-0 text-center text-sm font-bold text-ink-card-subtle">{{ row.rank }}</span>
              <img
                v-if="row.user.avatar_url"
                :src="row.user.avatar_url"
                :alt="row.user.name"
                class="w-10 h-10 rounded-full object-cover shrink-0"
              />
              <div v-else class="w-10 h-10 rounded-full bg-gold-50 flex items-center justify-center text-xs font-bold text-gold-700 shrink-0">
                {{ initials(row.user.name) }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-ink-card truncate">
                  {{ row.user.name }}
                  <span v-if="row.user.id === auth.user?.id" class="text-xs font-bold text-gold-600">(คุณ)</span>
                </p>
                <p class="text-xs text-ink-card-muted">Lv.{{ row.level_number }}</p>
              </div>
              <span class="px-3 py-1.5 rounded-full text-xs font-bold shrink-0" :class="pillClass(row.rank)">
                {{ row.total_xp.toLocaleString('th-TH') }} XP
              </span>
            </div>
          </TransitionGroup>
        </div>
      </div>
    </Transition>
  </main>
</template>
