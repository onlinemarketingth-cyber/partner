<script setup lang="ts">
/**
 * AdminNavigation — top bar for the Admin app.
 *
 * Human request (2026-07-14): "add the necessary menu, right after the
 * Admin badge" — this was previously deliberately deferred (see the old
 * version of this comment: "no multi-item route nav... add nav items
 * here once real module routes exist") since AdminHomeView.vue was the
 * only real screen at the time. Every module route now exists (Phase
 * 7-8), so the nav items below mirror AdminHomeView.vue's card grid
 * one-to-one (same route names, same icons, same "Manage companies"
 * Super-Admin-only gate) rather than inventing a different information
 * architecture. "โปรไฟล์ของฉัน" stays out of this row — it's already
 * reachable from the avatar menu below, listing it twice would be
 * redundant.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from '@/composables/useI18n'
import { useFontSize } from '@/composables/useFontSize'
import { useAuthStore } from '@/stores/auth'
import AppLogo from './AppLogo.vue'
import Icon from './Icon.vue'
import NotificationBell from './NotificationBell.vue'
// TASK-208 / ADR-038 — the single company-scope control for the whole Admin
// app, mounted here so it is on screen on every route.
import CompanySwitcher from './CompanySwitcher.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { lang, t, setLang } = useI18n()
const { fontSize, setFontSize } = useFontSize()

function toggleLang() {
  setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

// Ported from frontend/'s TopNavigation.vue (itself ported from
// medical-saas) — Admin was previously missing these three (only had
// the language toggle + avatar below). Search button and
// NotificationBell are deliberately visual-only stubs here too, same as
// the Agent Portal — no command palette, no real /notifications
// endpoint yet (human-confirmed 2026-07-17: stub-only for this pass).
const fontSizeOptions = [
  { v: 'small', label: 'A', size: '11px' },
  { v: 'medium', label: 'A', size: '13px' },
  { v: 'large', label: 'A', size: '15px' },
] as const

const avatarInitial = computed(() => authStore.user?.name?.trim().charAt(0).toUpperCase() || '?')

const userMenuOpen = ref(false)
async function handleLogout() {
  userMenuOpen.value = false
  // Guarded so a network hiccup on /logout still returns the UI to a sane
  // state — same pattern as ProfileSettingsView.vue's handleLogout(). The
  // store's own logout() already nulls user.value in a finally, but without
  // this wrapper a thrown error here would skip the redirect entirely.
  try {
    await authStore.logout()
  } finally {
    router.push({ name: 'login' })
  }
}

interface SubMenuItem {
  name: string
  icon: string
  label: { th: string; en: string }
  // 2026-08-17 (human request) — "ตั้งค่า Email SMTP" used to be its own
  // top-level pillar (TASK-190 §5's original placement); human found it
  // undiscoverable there and asked why it wasn't grouped under "ตั้งค่า
  // ระบบ" alongside the rest of platform/company settings. Moved into
  // that pillar's subMenus below, but it must stay Super-Admin-only
  // while "ตั้งค่าระบบ"'s OTHER sub-item (theme/branding) stays open to
  // Company Admin too — pillar-level `superAdminOnly` can't express
  // "only some of my sub-items are gated", so sub-items now carry their
  // own optional flag, filtered in `visibleSubMenus`/`visibleActiveSubMenus`
  // below rather than at the whole-pillar level.
  superAdminOnly?: boolean
}

// TASK-043 — every pillar now carries a `subMenus` array instead of
// being a single flat route. Most pillars keep a single-entry
// `subMenus: [self]` (unchanged behavior — row 2 never renders for
// them, see the template). Only "จัดการตัวแทน" gets real sub-items:
// this is the fix for the bug where visiting `/announcements`,
// `/reward-center`, or `/agent-promotions` highlighted nothing in the
// top bar (those 3 routes existed but were never in `navItems`) — now
// every real route belongs to exactly one pillar's `subMenus`, so
// highlighting is always resolvable. Pattern ported from
// medical-saas's TopNavigation.vue two-row nav (row 1 = pillars, row 2
// = active pillar's sub-menu), using this app's existing
// `bg-brand-50/60 text-brand-600 font-bold` color-token convention
// (not medical-saas's indigo) and real vue-router (`route.name`)
// instead of medical-saas's emit-based module switching.
interface NavItem {
  name: string
  icon: string
  label: { th: string; en: string }
  superAdminOnly?: boolean
  subMenus: SubMenuItem[]
}

const navItems: NavItem[] = [
  {
    name: 'product-catalog',
    icon: 'cube',
    label: { th: 'สินค้า', en: 'Product catalog' },
    subMenus: [
      { name: 'product-catalog', icon: 'cube', label: { th: 'สินค้า', en: 'Product catalog' } },
      // ADR-036 (TASK-214) — shared cross-company catalog. Same
      // per-sub-item `superAdminOnly` pattern as "ตั้งค่า Email SMTP"
      // under theme-settings below (see SubMenuItem's own docblock): the
      // pillar itself stays open to Company Admin (their own "สินค้า"
      // list), only this one sub-item is Super-Admin-only.
      { name: 'catalog-management', icon: 'globe', label: { th: 'แคตตาล็อกกลาง', en: 'Global Catalog' }, superAdminOnly: true },
    ],
  },
  { name: 'academy-management', icon: 'book', label: { th: 'Academy', en: 'Academy' }, subMenus: [{ name: 'academy-management', icon: 'book', label: { th: 'Academy', en: 'Academy' } }] },
  {
    name: 'agent-management',
    icon: 'users',
    label: { th: 'จัดการตัวแทน', en: 'Agents' },
    // TASK-043 §2 — sub-items in this exact order.
    // TASK-204 (human decision) — the first item used to be ONE route
    // ('agent-management') carrying 5 internal tabs behind its own tab bar.
    // Those tabs are now 4 real routes, inserted here in the order the human
    // specified: ภาพรวม → รายชื่อตัวแทน → รออนุมัติ → ลิงก์ชวนทีม. ใช้งานอยู่ +
    // ปิดใช้งาน merge into "รายชื่อตัวแทน" (ag-lead ruling — one roster
    // fetch, filtered client-side, not two routes each re-fetching it).
    subMenus: [
      { name: 'agent-management', icon: 'dashboard', label: { th: 'ภาพรวม', en: 'Dashboard' } },
      { name: 'agent-roster', icon: 'list', label: { th: 'รายชื่อตัวแทน', en: 'Agent Roster' } },
      { name: 'agent-approvals', icon: 'clock', label: { th: 'รออนุมัติ', en: 'Pending Approvals' } },
      { name: 'agent-invite-links', icon: 'link', label: { th: 'ลิงก์ชวนทีม', en: 'Invite Links' } },
      // TASK-233 — the company-wide signup link, beside the team one.
      { name: 'company-signup-links', icon: 'link', label: { th: 'ลิงก์สมัครตัวแทน', en: 'Signup Links' } },
      // TASK-234 — the stats view over every group of link.
      { name: 'company-links', icon: 'chart', label: { th: 'ลิงก์ทั้งบริษัท', en: 'All Links' } },
      // TASK-050 (moved, human request 2026-07-23) — "ทีมขาย" leadership
      // cockpit lives as a sub-menu of "จัดการตัวแทน", right BEFORE
      // "ค่าคอมมิชชั่น", rather than as its own top-level pillar.
      { name: 'sales-team', icon: 'chart', label: { th: 'ทีมขาย', en: 'Sales Team' } },
      { name: 'agent-commission-summary', icon: 'money', label: { th: 'ค่าคอมมิชชั่น', en: 'Commission Summary' } },
      { name: 'announcements', icon: 'megaphone', label: { th: 'ข่าวสาร', en: 'Announcements' } },
      { name: 'agent-promotions', icon: 'tag', label: { th: 'Promotion', en: 'Promotion' } },
      { name: 'reward-center', icon: 'trophy', label: { th: 'ศูนย์รางวัล', en: 'Reward Center' } },
    ],
  },
  { name: 'gamification-config', icon: 'star', label: { th: 'Gamification', en: 'Gamification' }, subMenus: [{ name: 'gamification-config', icon: 'star', label: { th: 'Gamification', en: 'Gamification' } }] },
  // TASK-048 / ADR-012 — Client (Contact) + Referral/Pipeline (Deal) are
  // one sales workflow, so they live under a single "การขาย" pillar with
  // two sub-menus, mirroring the standard CRM two-object model
  // (HubSpot/Pipedrive: Contacts vs Deals under one Sales area). The
  // pillar `name` points at the first sub-route (client-management) so
  // clicking the pillar itself lands on Clients; isPillarActive() already
  // matches ANY sub-route, so both sub-pages highlight this pillar and
  // render row 2. Route names are UNCHANGED (no router/bookmark impact).
  {
    name: 'client-management',
    icon: 'cart',
    label: { th: 'ลูกค้า', en: 'Clients' },
    subMenus: [
      { name: 'client-management', icon: 'user', label: { th: 'ลูกค้า', en: 'Clients' } },
      { name: 'referral-pipeline-management', icon: 'pipeline', label: { th: 'ดีล / Pipeline', en: 'Deals / Pipeline' } },
    ],
  },
  // Untouched by TASK-043 (spec §4/§5) — stays a distinct top-level
  // pillar, not folded into agent-management's submenu.
  //
  // TASK-219 (human request, 2026-08-20) — "แผนคอมมิชชั่น" used to be its
  // OWN top-level pillar sitting next to this one. Two neighbouring
  // pillars both about commission, one holding the money that was paid and
  // one holding the rules that decide it, is a distinction the top bar
  // could not express: the icons said "money" and "layers" and nothing
  // said they were two halves of the same subject.
  //
  // They are now one pillar with two sub-items, which is what row 2 is
  // for. The human's own framing — "แยกระหว่าง จ่ายคอมมิชชั่น กับตั้งค่า" —
  // is why the FIRST sub-item is relabelled too: leaving it as
  // "Commission" beside "ตั้งค่า" would name the parent twice and still
  // never say what the page actually holds (the payout ledger).
  //
  // Route names are UNCHANGED (/commission and /commission-plans), so
  // every existing link and bookmark keeps working — including the
  // signpost ProductCatalogView renders (TASK-213) and the readiness
  // links inside CommissionPlansView itself.
  {
    name: 'commission-management',
    icon: 'money',
    label: { th: 'Commission', en: 'Commission' },
    subMenus: [
      { name: 'commission-management', icon: 'money', label: { th: 'จ่ายคอมมิชชั่น', en: 'Payouts' } },
      // ADR-011 (TASK-034) — moved in from its own top-level pillar. NOT
      // superAdminOnly: a Company Admin has always been able to set their
      // own company's commission rules, and folding the page into another
      // pillar must not quietly take that away.
      { name: 'commission-plan-settings', icon: 'layers', label: { th: 'ตั้งค่า', en: 'Settings' } },
    ],
  },
  // TASK-055 / ADR-018 — per-company white-label of the Agent Portal. Its own
  // top-level pillar: no existing pillar was a home for a Company-Admin-facing
  // branding screen (the rest are unrelated business domains). 'display_cog'
  // (monitor + gear) isn't used elsewhere. As of 2026-08-20 this pillar is
  // also where "จัดการบริษัท" lives (see its sub-item below).
  {
    name: 'theme-settings',
    icon: 'display_cog',
    label: { th: 'ตั้งค่าระบบ', en: 'System settings' },
    subMenus: [
      // 2026-08-20 (human request) — "จัดการบริษัท" used to be its own
      // top-level pillar. Moved in here, ABOVE "ธีม / แบรนด์", because
      // that is the order the human asked for and because it reads
      // correctly: pick/inspect the company first, then style it. It
      // stays Super-Admin-only via the per-sub-item flag (same mechanism
      // as "ตั้งค่า Email SMTP" below) — the pillar itself must remain
      // open to Company Admin, who still needs "ธีม / แบรนด์".
      { name: 'company-management', icon: 'building', label: { th: 'จัดการบริษัท', en: 'Companies' }, superAdminOnly: true },
      { name: 'theme-settings', icon: 'display_cog', label: { th: 'ธีม / แบรนด์', en: 'Theme / Brand' } },
      // TASK-202 (human request, 2026-08-17) — these 3 used to be per-company
      // setting cards stacked below ThemeSettingsView's tabbed editor. Split
      // into their own submenu pages for a clear, findable menu name each —
      // same access level as "ธีม / แบรนด์" above (Company Admin AND Super
      // Admin), so NO `superAdminOnly` on any of the three.
      { name: 'video-settings', icon: 'settings', label: { th: 'ตั้งค่าวิดีโอ', en: 'Video Settings' } },
      { name: 'team-visibility-settings', icon: 'users', label: { th: 'การมองเห็นข้อมูลทีม', en: 'Team Visibility' } },
      { name: 'commission-split-settings', icon: 'money', label: { th: 'คอมมิชชั่นตัวแทนร่วม', en: 'Co-agent Commission Split' } },
      // 2026-08-17 (human request) — moved in from its own top-level
      // pillar (see SubMenuItem's own docblock above for why). Still
      // Super-Admin-only (this is the ONE global platform SMTP config,
      // not per-company) — visibleSubMenus/visibleActiveSubMenus filter
      // it out for Company Admin, who still sees "ธีม / แบรนด์" above.
      { name: 'mail-settings', icon: 'mail', label: { th: 'ตั้งค่า Email SMTP', en: 'Mail Settings' }, superAdminOnly: true },
    ],
  },
  // ADR-033 (TASK-189) §2.1/F2 — voucher redemption lookup. Its own
  // top-level pillar for the same reason theme-settings got one above:
  // no existing pillar is a home for a staff-facing redemption action
  // (not agent management, not commission, not a product config screen).
  // Reachable by Company Admin/Super Admin only — real enforcement is the
  // backend's Ability::VoucherRedeem gate (§5 rule 5); this app already
  // blocks Agent entirely (TASK-057/161), so no extra client-side check
  // is needed here beyond that existing app-wide guard.
  {
    name: 'voucher-redeem',
    icon: 'tag',
    label: { th: 'ตัดสิทธิ์บัตรกำนัล', en: 'Redeem Voucher' },
    subMenus: [{ name: 'voucher-redeem', icon: 'tag', label: { th: 'ตัดสิทธิ์บัตรกำนัล', en: 'Redeem Voucher' } }],
  },
]

const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')
const visibleNavItems = computed(() => navItems.filter((item) => !item.superAdminOnly || isSuperAdmin.value))
const activeName = computed(() => route.name as string)

// The actual TASK-043 fix: a pillar is active when `route.name` matches
// ANY of its subMenus' route names, not just its own `name` — this is
// what makes `/announcements`, `/reward-center`, `/agent-promotions`
// (previously orphaned, matching nothing) correctly highlight
// "จัดการตัวแทน".
function isPillarActive(item: NavItem): boolean {
  return item.subMenus.some((sub) => sub.name === activeName.value)
}
const activePillar = computed(() => visibleNavItems.value.find((item) => isPillarActive(item)) ?? null)
// 2026-08-17 — per-sub-item `superAdminOnly` filter (see SubMenuItem's own
// docblock): a Company Admin must never see "ตั้งค่า Email SMTP" under
// "ตั้งค่าระบบ", even though the pillar itself (and its other sub-item,
// theme/branding) is open to them.
function visibleSubMenus(item: NavItem): SubMenuItem[] {
  return item.subMenus.filter((sub) => !sub.superAdminOnly || isSuperAdmin.value)
}
// Row 2 only ever renders for pillars with more than one VISIBLE sub-item
// (spec: "the other pillars ... do not add an empty/invisible row 2 for
// them") — written generically against `.length > 1` rather than a
// hardcoded name, so it keeps working as more pillars earn sub-items.
const activeSubMenus = computed(() => {
  if (!activePillar.value) return null
  const visible = visibleSubMenus(activePillar.value)
  return visible.length > 1 ? visible : null
})
</script>

<template>
  <div class="sticky top-0 z-50 font-sans select-none">
    <nav class="bg-white/90 backdrop-blur-xl border-b border-slate-200 px-4 sm:px-6 py-2 shadow-sm">
      <div class="w-full max-w-none mx-auto flex items-center justify-between gap-4 min-w-0">
        <div class="flex items-center gap-4 min-w-0">
          <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 shrink-0">
            <AppLogo mode="wordmark" :height="30" />
            <span class="text-[10px] font-black uppercase tracking-wider text-brand-600 bg-brand-50 px-2 py-1 rounded-full">
              Admin
            </span>
          </RouterLink>

          <div class="hidden lg:flex items-center gap-1 overflow-x-auto min-w-0">
            <RouterLink
              v-for="item in visibleNavItems"
              :key="item.name"
              :to="{ name: item.name }"
              class="group relative flex items-center p-2 rounded-xl transition-all duration-300 border-b-2 border-transparent shrink-0 text-sm"
              :class="isPillarActive(item)
                ? 'bg-brand-100/70 text-brand-600 font-bold border-b-brand-600'
                : 'text-slate-600 hover:text-brand-600 hover:bg-slate-50 font-bold'"
            >
              <div class="w-6 h-6 shrink-0 flex items-center justify-center">
                <Icon :name="item.icon" :size="16" />
              </div>
              <!-- Ported from medical-saas TopNavigation.vue: icon-only by
                   default, label slides/fades in on hover or when active
                   (max-w-0/opacity-0 -> max-w-xs/opacity-100, duration-300).
                   Active/inactive are mutually-exclusive class SETS (not
                   layered) — Tailwind's generated stylesheet order does
                   not follow markup order, so having both `max-w-0` and
                   `max-w-xs` present on the same element at once is
                   unreliable (verified live: max-w-0 silently won even
                   when the active override was also applied). -->
              <span
                class="whitespace-nowrap ml-2 overflow-hidden tracking-wide transition-all duration-300"
                :class="isPillarActive(item)
                  ? 'opacity-100 max-w-xs'
                  : 'opacity-0 max-w-0 group-hover:opacity-100 group-hover:max-w-xs'"
              >{{ t('admin_nav_' + item.name, item.label.th, item.label.en) }}</span>
            </RouterLink>
          </div>
        </div>

        <div class="flex items-center gap-3 shrink-0">
          <!-- TASK-208 — placed immediately left of the search button per the
               human's own instruction ("บน Head ข้างปุ่มค้นหาด้านขวามือ"). It
               is the first thing in the actions cluster on purpose: which
               company you are working in outranks every other control here. -->
          <CompanySwitcher />

          <button
            type="button"
            class="w-10 h-10 flex items-center justify-center rounded-full transition-all text-slate-500 hover:bg-slate-100 hover:text-brand-600"
            :title="t('search', 'ค้นหา', 'Search')"
          >
            <Icon name="search" :size="18" />
          </button>

          <NotificationBell />

          <div class="hidden md:flex items-center gap-0.5 bg-slate-100 rounded-full border border-slate-200 p-0.5">
            <button
              v-for="opt in fontSizeOptions"
              :key="opt.v"
              type="button"
              @click="setFontSize(opt.v)"
              :style="{ fontSize: opt.size, lineHeight: 1 }"
              :class="[
                'px-2.5 py-1.5 rounded-full font-bold transition-all min-w-[28px]',
                fontSize === opt.v ? 'bg-brand-600 text-white shadow' : 'text-slate-500 hover:text-brand-600',
              ]"
            >
              {{ opt.label }}
            </button>
          </div>

          <button
            type="button"
            @click="toggleLang"
            class="relative w-16 h-8 bg-slate-100 rounded-full border border-slate-200 cursor-pointer flex items-center px-1"
          >
            <div
              class="absolute top-1 bottom-1 w-7 bg-white rounded-full shadow flex items-center justify-center transition-all duration-300"
              :class="lang === 'TH' ? 'translate-x-0' : 'translate-x-8'"
            >
              <span class="text-[10px] font-black text-brand-600">{{ lang }}</span>
            </div>
          </button>

          <div class="relative">
            <button
              type="button"
              @click="userMenuOpen = !userMenuOpen"
              class="cursor-pointer relative transform transition-transform hover:scale-110 w-9 h-9 rounded-full overflow-hidden bg-brand-600 text-white flex items-center justify-center font-bold text-sm border border-white shadow"
            >
              <img v-if="authStore.user?.avatar_url" :src="authStore.user.avatar_url" :alt="authStore.user.name" class="w-full h-full object-cover" />
              <span v-else>{{ avatarInitial }}</span>
            </button>
            <div
              v-if="userMenuOpen"
              class="absolute right-0 mt-2 w-52 rounded-xl bg-white border border-slate-200 shadow-2xl overflow-hidden z-50"
            >
              <div v-if="authStore.user" class="px-3 py-2 border-b border-slate-100">
                <p class="text-sm font-bold text-slate-800 truncate">{{ authStore.user.name }}</p>
                <p class="text-xs text-slate-400 truncate">{{ authStore.user.email }}</p>
              </div>
              <RouterLink
                :to="{ name: 'profile' }"
                @click="userMenuOpen = false"
                class="block w-full text-left px-3 py-2 text-xs text-slate-600 hover:bg-slate-50 hover:text-brand-600 font-bold"
              >
                {{ t('profile', 'โปรไฟล์ของฉัน', 'My Profile') }}
              </RouterLink>
              <button
                type="button"
                @click="handleLogout"
                class="w-full text-left px-3 py-2 text-xs text-slate-600 hover:bg-slate-50 hover:text-rose-600 font-bold border-t border-slate-100"
              >
                {{ t('logout', 'ออกจากระบบ', 'Logout') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <!-- TASK-043 §2 — row 2: active pillar's sub-menu. Renders only for
         "จัดการตัวแทน" today (`activeSubMenus` is null for every other
         pillar, since they still have a single-entry `subMenus`) —
         visually subordinate to row 1 (smaller pills, no border/shadow
         weight), same active-highlight convention
         (`bg-brand-50/60 text-brand-600 font-bold`) but on an exact
         `route.name` match per sub-item instead of the pillar-level
         "any of" check row 1 uses. -->
    <div v-if="activeSubMenus" class="hidden lg:block bg-slate-50/80 backdrop-blur-xl border-b border-slate-200 px-4 sm:px-6">
      <div class="w-full max-w-none mx-auto flex items-center gap-1 overflow-x-auto py-1.5 pl-[2.75rem]">
        <RouterLink
          v-for="sub in activeSubMenus"
          :key="sub.name"
          :to="{ name: sub.name }"
          class="relative flex items-center p-1.5 rounded-lg transition-all duration-300 shrink-0 text-xs"
          :class="activeName === sub.name
            ? 'bg-brand-50/60 text-brand-600 font-bold'
            : 'text-slate-500 hover:text-brand-600 hover:bg-white/60 font-bold'"
        >
          <div class="w-5 h-5 shrink-0 flex items-center justify-center">
            <Icon :name="sub.icon" :size="14" />
          </div>
          <!-- ═══ TASK-126 — WHY ROW 2 SHOWS LABELS AND ROW 1 STILL HIDES THEM ═══
               This span used to carry row 1's medical-saas-ported
               collapse/expand animation (icon-only, label revealed on hover
               or when active). Human-reported 2026-08-05: they could not
               find the "จัดการตัวแทน" agents page AT ALL — and that was the
               direct cause. `agent-management` is the FIRST sub-item here,
               labelled "Dashboard"; whenever it was not the current route it
               rendered as a bare unlabelled icon, so the only way to
               discover it was to hover each of the six icons in turn.

               The two rows now DIFFER ON PURPOSE — this is not an
               inconsistency to "fix" back:
                 • Row 1 packs NINE pillars into the strip beside the logo
                   and the wordmark/Admin badge. Collapsing them to icons is
                   what keeps that row from wrapping — a deliberate,
                   established treatment (see row 1's own comment). Untouched.
                 • Row 2 holds at most SIX items on a row of its own with the
                   whole window width available. Hiding those labels buys no
                   space worth having and costs the sub-pages their entire
                   discoverability — exactly the failure reported above.

               The active item stays distinguishable via the highlight
               (`bg-brand-50/60 text-brand-600 font-bold` on the link), which
               is what that treatment was always for; being the only readable
               label was never supposed to be its job.

               Narrow screens: this whole row is `hidden lg:block`, and on a
               tight lg viewport the container's `overflow-x-auto` plus each
               link's `shrink-0` already scroll horizontally instead of
               wrapping. Always-on labels only make that scroll begin sooner
               — they cannot break the layout.

               The link above also lost its `group` class along with the
               hover-reveal: nothing in this row keys off `group-hover` any
               more, and leaving the marker there would imply some child still
               reacts to hovering the link. Row 1 keeps its `group` — it still
               needs it. -->
          <span
            class="whitespace-nowrap ml-1.5"
          >{{ t('admin_nav_sub_' + sub.name, sub.label.th, sub.label.en) }}</span>
        </RouterLink>
      </div>
    </div>
  </div>
</template>
