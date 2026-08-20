import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      name: 'home',
      component: () => import('../views/AdminHomeView.vue'),
      meta: { navLabel: 'Admin' },
    },
    {
      path: '/product-catalog',
      name: 'product-catalog',
      component: () => import('../views/ProductCatalogView.vue'),
      meta: { navLabel: 'Product catalog' },
    },
    // ADR-008 — dedicated full-page create/edit screens, replacing the
    // old inline expandable-row UX on ProductCatalogView.vue's
    // "products" tab. Both routes share one component (ProductEditView)
    // via an optional :id param; no extra meta needed beyond the
    // implicit "not public" auth guard every other route already gets.
    {
      path: '/product-catalog/products/new',
      name: 'product-create',
      component: () => import('../views/ProductEditView.vue'),
    },
    {
      path: '/product-catalog/products/:id/edit',
      name: 'product-edit',
      component: () => import('../views/ProductEditView.vue'),
    },
    // ADR-036 (TASK-214) — Super-Admin-only shared cross-company product
    // catalog (catalog_brands / catalog_categories / product_catalog_items).
    // `requiresSuperAdmin: true` mirrors '/companies' below: reading the
    // underlying endpoints is permissive server-side (any authenticated
    // user can GET), but every write is Super-Admin-only and a Company
    // Admin has no action they could take here (linking a product is ALSO
    // Super-Admin-only, even for the product's own company) — so the whole
    // screen is gated client-side too, same reasoning CompanyManagementView
    // already established for an admin-only config screen.
    {
      path: '/catalog',
      name: 'catalog-management',
      component: () => import('../views/CatalogManagementView.vue'),
      meta: { navLabel: 'แคตตาล็อกกลาง', requiresSuperAdmin: true },
    },
    {
      path: '/academy',
      name: 'academy-management',
      component: () => import('../views/AcademyManagementView.vue'),
      meta: { navLabel: 'Academy' },
    },
    // TASK-204 — this used to be one route with 5 internal tabs sharing one
    // roster fetch (AgentManagementView.vue). Split into 4 real routes under
    // the "จัดการตัวแทน" submenu (AdminNavigation.vue): ภาพรวม stays on this
    // path/name (AdminHomeView.vue's link-out card and every other reference
    // to 'agent-management' by route name keep working unchanged); ใช้งานอยู่
    // + ปิดใช้งาน merge into one "รายชื่อตัวแทน" roster page (ag-lead ruling —
    // avoids fetching the same roster twice just to show it filtered two
    // ways); รออนุมัติ and ลิงก์ชวนทีม become their own routes. Same access
    // level as before on all 4 — Company Admin AND Super Admin, no
    // `requiresSuperAdmin`.
    {
      path: '/agents',
      name: 'agent-management',
      component: () => import('../views/AgentManagementView.vue'),
      meta: { navLabel: 'ภาพรวมตัวแทน' },
    },
    {
      path: '/agents/roster',
      name: 'agent-roster',
      component: () => import('../views/AgentRosterView.vue'),
      meta: { navLabel: 'รายชื่อตัวแทน' },
    },
    {
      path: '/agents/pending',
      name: 'agent-approvals',
      component: () => import('../views/AgentApprovalsView.vue'),
      meta: { navLabel: 'รออนุมัติ' },
    },
    {
      path: '/agents/invite-links',
      name: 'agent-invite-links',
      component: () => import('../views/AgentInviteLinksView.vue'),
      meta: { navLabel: 'ลิงก์ชวนทีม' },
    },
    /*
     * TASK-233 — the company's OWN signup link, which had no screen at all
     * before today (see CompanySignupLinksView's header). Sits next to
     * "ลิงก์ชวนทีม" because an admin looking for one is looking for the
     * other, and the difference between them — company-wide versus
     * attributed to a team leader — is the thing they need to see side by
     * side to pick correctly.
     */
    {
      path: '/agents/signup-links',
      name: 'company-signup-links',
      component: () => import('../views/CompanySignupLinksView.vue'),
      meta: { navLabel: 'ลิงก์สมัครตัวแทน' },
    },
    /*
     * TASK-234 — every link the company has out in the world, in one table.
     *
     * Six token tables existed and not one screen showed them together; the
     * only counter visible anywhere was a sales-material `view_count`
     * buried in a modal inside the product editor.
     */
    {
      path: '/agents/links',
      name: 'company-links',
      component: () => import('../views/CompanyLinksView.vue'),
      meta: { navLabel: 'ลิงก์ทั้งบริษัท' },
    },
    {
      path: '/gamification',
      name: 'gamification-config',
      component: () => import('../views/GamificationConfigView.vue'),
      meta: { navLabel: 'Gamification' },
    },
    {
      path: '/companies',
      name: 'company-management',
      component: () => import('../views/CompanyManagementView.vue'),
      meta: { navLabel: 'จัดการบริษัท', requiresSuperAdmin: true },
    },
    {
      path: '/clients',
      name: 'client-management',
      component: () => import('../views/ClientManagementView.vue'),
      meta: { navLabel: 'ลูกค้า' },
    },
    // TASK-049 — full-page client registry file ("แฟ้มทะเบียนลูกค้า"),
    // replacing the old detail drawer on ClientManagementView. Same
    // auth-guarded group as client-management (no extra meta = not
    // public). The list route above ('/clients') is matched before this
    // '/clients/:id' param route by Vue Router, so both coexist fine.
    {
      path: '/clients/:id',
      name: 'client-file',
      component: () => import('../views/ClientFileView.vue'),
      meta: { navLabel: 'แฟ้มทะเบียนลูกค้า' },
    },
    {
      path: '/pipeline',
      name: 'referral-pipeline-management',
      component: () => import('../views/ReferralPipelineManagementView.vue'),
      meta: { navLabel: 'Referral & Pipeline' },
    },
    {
      path: '/commission',
      name: 'commission-management',
      component: () => import('../views/CommissionManagementView.vue'),
      meta: { navLabel: 'Commission' },
    },
    // TASK-050 — "ทีมขาย" leadership cockpit: manager_id reporting
    // roll-up (per-agent client/deal counts + close rate) built
    // client-side from GET /sales-team-overview. Same auth-guarded admin
    // group as the routes above (no extra meta = not public).
    {
      path: '/sales-team',
      name: 'sales-team',
      component: () => import('../views/SalesTeamView.vue'),
      meta: { navLabel: 'ทีมขาย' },
    },
    // ADR-011 (TASK-034) — distinct from '/commission' above: that route
    // is the ledger (money already earned); this one is plan
    // CONFIGURATION (rates/thresholds/windows). Human decision
    // (2026-07-20): one consolidated tabbed route rather than 5 more
    // flat nav items.
    {
      path: '/commission-plans',
      name: 'commission-plan-settings',
      component: () => import('../views/CommissionPlansView.vue'),
      meta: { navLabel: 'แผนคอมมิชชั่น' },
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/ProfileSettingsView.vue'),
      meta: { navLabel: 'โปรไฟล์ของฉัน' },
    },
    // TASK-055 Phase 3 (ADR-018) — per-company theming / white-label of the
    // Agent Portal. Company-Admin-visible (Super Admin too); this whole app
    // is already Agent-blocked at the guard above, and every theme write is
    // Policy-gated to Company/Super Admin server-side.
    {
      path: '/theme-settings',
      name: 'theme-settings',
      component: () => import('../views/ThemeSettingsView.vue'),
      meta: { navLabel: 'ตั้งค่าระบบ' },
    },
    // TASK-202 — the 3 per-company setting cards that used to sit below
    // ThemeSettingsView's tabbed editor now get their own submenu pages
    // under "ตั้งค่าระบบ" (same access level as theme-settings above —
    // Company Admin AND Super Admin, no `requiresSuperAdmin`).
    {
      path: '/video-settings',
      name: 'video-settings',
      component: () => import('../views/VideoSettingsView.vue'),
      meta: { navLabel: 'ตั้งค่าวิดีโอ' },
    },
    {
      path: '/team-visibility-settings',
      name: 'team-visibility-settings',
      component: () => import('../views/TeamVisibilitySettingsView.vue'),
      meta: { navLabel: 'การมองเห็นข้อมูลทีม' },
    },
    {
      path: '/commission-split-settings',
      name: 'commission-split-settings',
      component: () => import('../views/CommissionSplitSettingsView.vue'),
      meta: { navLabel: 'คอมมิชชั่นตัวแทนร่วม' },
    },
    // Agent Overview "เครื่องมือเสริม" link-out cards (see
    // AgentManagementView.vue's overview tab). Originally deliberately
    // NOT added to AdminNavigation.vue's top-level navItems (already had
    // 9 items) — reached only via the link-out cards. TASK-043 changed
    // this: these 3 routes are now also reachable from the
    // "จัดการตัวแทน" pillar's row-2 sub-menu in AdminNavigation.vue
    // (which is what fixed the bug where visiting them highlighted
    // nothing in the top bar) — the link-out cards on
    // AgentManagementView.vue stay as-is, this is just an additional
    // entry point, not a replacement.
    {
      path: '/agent-promotions',
      name: 'agent-promotions',
      component: () => import('../views/AgentPromotionsView.vue'),
      meta: { navLabel: 'Promotion สำหรับ Agent' },
    },
    {
      path: '/reward-center',
      name: 'reward-center',
      component: () => import('../views/RewardCenterView.vue'),
      meta: { navLabel: 'ศูนย์รางวัล' },
    },
    {
      path: '/announcements',
      name: 'announcements',
      component: () => import('../views/AnnouncementsView.vue'),
      meta: { navLabel: 'ข่าวสารถึง Agent' },
    },
    // TASK-043 — per-agent commission summary ("ค่าคอมมิชชั่น" sub-item
    // under the "จัดการตัวแทน" pillar). Deliberately a different screen
    // from '/commission' (CommissionManagementView — flat ledger rows):
    // this one is grouped-by-agent totals from the new
    // GET /agent-commission-summary endpoint. Not a replacement for
    // '/commission' — both stay.
    {
      path: '/agent-commission-summary',
      name: 'agent-commission-summary',
      component: () => import('../views/AgentCommissionSummaryView.vue'),
      meta: { navLabel: 'ค่าคอมมิชชั่น' },
    },
    // TASK-040 — "มุมมองสินค้า" (ABC grading + price promotions). Same
    // deliberate omission from AdminNavigation.vue's top nav as the 3
    // routes above (already 9+ items) — reached only via
    // ProductCatalogView.vue's link-out.
    {
      path: '/product-performance',
      name: 'product-performance',
      component: () => import('../views/ProductPerformanceView.vue'),
      meta: { navLabel: 'มุมมองสินค้า' },
    },
    // TASK-041 — "นโยบายและรายงาน" (Audit Log + Platform/Compliance/
    // Config Health reports — Section 6/8, PDPA). Same deliberate
    // omission from AdminNavigation.vue's top nav as the routes above
    // (already 9+ items) — reached only via AdminHomeView.vue's
    // link-out card.
    {
      path: '/policy-report',
      name: 'policy-report',
      component: () => import('../views/PolicyReportView.vue'),
      meta: { navLabel: 'นโยบายและรายงาน' },
    },
    // ADR-033 (TASK-189) §2.1/F2 — voucher redemption lookup. Gated
    // server-side by Ability::VoucherRedeem (CompanyAdmin/SuperAdmin,
    // NOT Agent). No extra per-route meta beyond the app-wide Agent
    // block below: that block already restricts this whole app to the
    // same CompanyAdmin/SuperAdmin tier the ability is granted to, which
    // is exactly ADR-033 §2.1's interim grant — narrower than that
    // (`requiresSuperAdmin`, used by /companies) would wrongly exclude a
    // Company Admin who the backend explicitly allows.
    {
      path: '/voucher-redeem',
      name: 'voucher-redeem',
      component: () => import('../views/VoucherRedeemView.vue'),
      meta: { navLabel: 'ตัดสิทธิ์บัตรกำนัล' },
    },
    // TASK-190 §5 — platform-wide SMTP settings. Same gating convention as
    // '/companies' above: `requiresSuperAdmin: true` meta, enforced client-
    // side by the guard below (UX only — real enforcement is the backend's
    // Ability::SettingsMailUpdate gate, Super Admin only, see
    // PlatformMailSettingController).
    {
      path: '/mail-settings',
      name: 'mail-settings',
      component: () => import('../views/MailSettingsView.vue'),
      meta: { navLabel: 'ตั้งค่า Email SMTP', requiresSuperAdmin: true },
    },
  ],
})

// Same Sanctum SPA session guard as the Agent Portal (see
// frontend/src/router/index.ts) — both apps share one backend/session,
// this just re-implements the same "logged in or not" check locally
// (ADR-003: no shared package between the two frontends yet).
router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  if (authStore.status === 'idle') {
    await authStore.fetchUser()
  }

  // This app is Company Admin / Super Admin only (ADR-003) — human-
  // confirmed 2026-07-16, resolving what used to be an open TODO here.
  // An Agent account has no legitimate use for this app: every
  // mutating action is already Policy-gated to Company Admin/Super
  // Admin server-side (Section 5), so an Agent who reached this app
  // only ever saw a confusing "everything 403s" partial experience.
  // Checked unconditionally (before the generic auth guard below) so it
  // also catches an Agent navigating straight to /login while still
  // holding a valid session.
  if (authStore.isAuthenticated && authStore.user?.role === 'agent') {
    // Guarded so a network hiccup on /logout still kicks the Agent back to
    // /login — same reasoning as ProfileSettingsView.vue's handleLogout().
    // Unlike a click handler this is a navigation guard: the redirect is a
    // `return` value, not a side-effecting router.push(), so it must sit
    // after the try/catch rather than in a finally (a finally block can't
    // supply the guard's return value without an explicit return inside
    // it, which would just duplicate this line).
    try {
      await authStore.logout()
    } catch {
      // best-effort — the redirect below must happen regardless of
      // whether the server-side /logout call succeeded.
    }
    return { name: 'login', query: { blocked: 'agent' } }
  }

  if (!to.meta.public && !authStore.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && authStore.isAuthenticated) {
    return { name: 'home' }
  }

  // "Manage Companies" is unambiguously Super Admin only (CompanyPolicy,
  // Section 5) — client-side gate here is just UX (avoid loading a page
  // that will only ever show 403s from the API); the real enforcement is
  // still server-side. This resolves only this one route's version of
  // the broader TODO above, not the whole-app question.
  if (to.meta.requiresSuperAdmin && authStore.user?.role !== 'super_admin') {
    return { name: 'home' }
  }
})

export default router
