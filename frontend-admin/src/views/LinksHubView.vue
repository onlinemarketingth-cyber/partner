<script setup lang="ts">
/**
 * LinksHubView — every link screen, in one page with three tabs.
 *
 * ── WHY (human, 2026-08-22) ──
 *
 * The จัดการตัวแทน menu carried three entries — ลิงก์ชวนทีม, ลิงก์สมัครตัวแทน,
 * ลิงก์ทั้งบริษัท — that nobody could tell apart from their names.
 *
 * They are not three unrelated screens. `TrackedLinkGroup` already contains
 * `company_signup` and `team_signup`, so ลิงก์ทั้งบริษัท has ALWAYS included
 * the links the other two manage — as numbers, not as things you can act on.
 * What actually existed was two CRUD surfaces and one analytics surface over
 * one subject, split across three menu items:
 *
 *   ภาพรวม           read-only, all seven groups, clicks and conversion
 *   ลิงก์สมัครตัวแทน   create / close the COMPANY's own signup link
 *   ลิงก์ชวนทีม        team leaders' links: owner, quota, revoke
 *
 * ── WHAT THE MERGE UNLOCKS THAT THREE PAGES COULD NOT ──
 *
 * From a row in ภาพรวม you can jump to the tab that manages that link. A
 * company signup link with no clicks in three months used to mean noting the
 * code, leaving, and finding it again on another screen.
 *
 * ── TAB ORDER ──
 *
 * ภาพรวม first: it is the one opened regularly. Creating a company signup
 * link is rare by its own design — CompanySignupLinksView's docblock records
 * that the code cannot be edited because it is the printed part of a URL on
 * a flyer already on somebody's wall.
 *
 * ── LAZY BY MOUNTING, NOT BY A LOADER ──
 *
 * Each panel already fetches in its own onMounted, so `v-if` on first visit
 * IS the lazy load — no loading code was added to any of them. This matters
 * more than it looks: the ลิงก์ชวนทีม panel calls fetchAllPages twice, and
 * one of those walks the entire user roster fifteen rows per request. Loading
 * all three tabs on arrival would fire a dozen requests for a page where
 * somebody reads one tab.
 *
 * `v-show` after that, so a visited tab keeps its filters, its scroll and its
 * data when you come back to it.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import Icon from '@/design-system/components/Icon.vue'
import CompanyLinksView from './CompanyLinksView.vue'
import CompanySignupLinksView from './CompanySignupLinksView.vue'
import AgentInviteLinksView from './AgentInviteLinksView.vue'

type Tab = 'overview' | 'signup' | 'team'

const TABS: Array<{
  key: Tab
  label: string
  icon: string
  title: string
  subtitle: string
  scopeAction: string
}> = [
  {
    key: 'overview',
    label: 'ภาพรวม',
    icon: 'chart',
    title: 'ลิงก์ทั้งบริษัท',
    subtitle: 'ลิงก์ทุกกลุ่มที่บริษัทนี้ปล่อยออกไป พร้อมจำนวนคนที่เปิดจริงและผลลัพธ์',
    scopeAction: 'ดูสถิติลิงก์',
  },
  {
    key: 'signup',
    label: 'ลิงก์สมัครตัวแทน',
    icon: 'link',
    title: 'ลิงก์สมัครตัวแทน',
    subtitle: 'ลิงก์เปิดรับสมัครตัวแทนของบริษัท — คนที่กดลิงก์เข้าหน้าสมัครได้เลย ไม่ต้องกรอกรหัสเชิญ',
    scopeAction: 'จัดการลิงก์สมัครตัวแทน',
  },
  {
    key: 'team',
    label: 'ลิงก์ชวนทีม',
    icon: 'users',
    title: 'ลิงก์ชวนทีม',
    subtitle: 'ลิงก์ชวนเข้าทีมทั้งหมดที่หัวหน้าทีมสร้างไว้ (ADR-025 §7)',
    scopeAction: 'จัดการลิงก์ชวนทีม',
  },
]

const route = useRoute()
const router = useRouter()

/**
 * Which tab this page opens on.
 *
 * `?agent=` is checked FIRST and beats `?tab=`. It is the "ดูในแท็บ
 * ลิงก์ชวนทีม" jump from the agent editor, which aims at one specific tab and
 * carries a filter only that tab can apply.
 *
 * Resolved here rather than by a watcher flipping the tab after mount —
 * caught by LinksHubView.spec.ts, which found the watcher version mounting
 * the overview panel and abandoning it a tick later. That panel fetches on
 * mount, so the wasted render was two wasted requests on every arrival from
 * the agent editor, visible nowhere except a network panel.
 */
function initialTab(): Tab {
  if (route.query.agent !== undefined && route.query.agent !== null) return 'team'

  const q = route.query.tab

  return typeof q === 'string' && TABS.some((t) => t.key === q) ? (q as Tab) : 'overview'
}

const activeTab = ref<Tab>(initialTab())

/**
 * Which tabs have ever been opened.
 *
 * A Set, not a boolean per tab: this is the whole lazy-mount mechanism and
 * it must be obvious that "mounted" is one concept, not three flags that can
 * disagree.
 */
const mounted = ref<Set<Tab>>(new Set([activeTab.value]))

const active = computed(() => TABS.find((t) => t.key === activeTab.value) ?? TABS[0]!)

function selectTab(tab: Tab): void {
  if (activeTab.value === tab) return

  activeTab.value = tab
  mounted.value = new Set([...mounted.value, tab])

  /*
   * The tab goes in the URL so it can be linked, bookmarked and refreshed —
   * the three screens this replaced each had their own address, and losing
   * that would be a regression dressed as a consolidation.
   *
   * `replace`, not `push`: flipping tabs is not navigation, and filling the
   * back button with them means Back stops meaning "the page before this".
   *
   * `agent` is dropped on a manual switch. It is a deep-link filter aimed at
   * ONE tab (see below); carrying it onto another tab would leave a filter
   * applied that the user never asked for and cannot see the origin of.
   */
  const next: Record<string, unknown> = { ...route.query, tab }
  delete next.agent
  void router.replace({ query: next as never })
}

/*
 * The same jump arriving while this page is ALREADY open — the router does
 * not remount a component for a query-only change, so initialTab() never
 * runs again. NOT `immediate`: the initial case is initialTab()'s job, and
 * doing it in both places is what mounted the overview panel for nothing.
 *
 * AgentInviteLinksView reads the same query itself and filters to that
 * agent; it needed no change, because this is still the same route object.
 */
watch(
  () => route.query.agent,
  (agent) => {
    if (agent === undefined || agent === null) return

    activeTab.value = 'team'
    mounted.value = new Set([...mounted.value, 'team'])
  },
)

/**
 * From an ภาพรวม row's "จัดการ" button — the reason these are one page.
 *
 * Three separate screens could not offer this at all: seeing a dead company
 * signup link in the stats meant noting its code, leaving, and finding it
 * again somewhere else.
 */
function onManage(tab: 'signup' | 'team'): void {
  selectTab(tab)
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <!-- One header whose title follows the tab, rather than three headers
         stacked or a generic one that names none of them. None of the three
         screens had KPIs, so there is nothing to reconcile here — only the
         title, the subtitle and the scope notice's verb. -->
    <HeroHeader
      icon="link"
      :title="active.title"
      :subtitle="active.subtitle"
      accent-color="brand"
      storage-key="links-hub"
    />

    <CompanyScopeNotice :action="active.scopeAction" />

    <div class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
      <button
        v-for="tab in TABS"
        :key="tab.key"
        type="button"
        class="shrink-0 min-h-[44px] px-4 border-b-2 rounded-t-lg text-sm font-bold transition inline-flex items-center gap-2"
        :class="activeTab === tab.key
          ? 'border-brand-500 text-brand-700 bg-brand-50'
          : 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50'"
        @click="selectTab(tab.key)"
      >
        <Icon :name="tab.icon" :size="15" />
        {{ tab.label }}
      </button>
    </div>

    <!-- v-if mounts once (that IS the lazy load — each panel fetches in its
         own onMounted); v-show keeps a visited tab's state afterwards. -->
    <div class="mt-4">
      <div v-if="mounted.has('overview')" v-show="activeTab === 'overview'">
        <CompanyLinksView embedded @manage="onManage" />
      </div>
      <div v-if="mounted.has('signup')" v-show="activeTab === 'signup'">
        <CompanySignupLinksView embedded />
      </div>
      <div v-if="mounted.has('team')" v-show="activeTab === 'team'">
        <AgentInviteLinksView embedded />
      </div>
    </div>
  </main>
</template>
