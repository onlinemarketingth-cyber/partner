import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { public: true },
    },
    // TASK-218 (human decision, 2026-08-20) — where a Super Admin lands
    // instead of the agent dashboard. `public: true` so the guard below
    // does not bounce it back into itself; see the guard's own comment,
    // and SuperAdminNoticeView's docblock for why the screen exists.
    {
      path: '/admin-account',
      name: 'super-admin-notice',
      component: () => import('../views/SuperAdminNoticeView.vue'),
      meta: { public: true },
    },
    // ADR-005 / TASK-018 — public self-registration + email verification.
    // Both must be reachable by an anonymous visitor, hence `public: true`
    // like /login above (see the router.beforeEach guard below).
    {
      path: '/register',
      name: 'register',
      component: () => import('../views/RegisterView.vue'),
      meta: { public: true },
    },
    {
      path: '/verify-email/:id/:hash',
      name: 'verify-email',
      component: () => import('../views/VerifyEmailView.vue'),
      meta: { public: true },
    },
    // ADR-011 Section 4 (TASK-033) — public, unauthenticated landing page
    // for a prospect who clicked an Agent's affiliate link. The path
    // MUST be exactly `/l/:token` — AffiliateLinkRedirectController
    // (backend) does `redirect()->away("{$frontendUrl}/l/{$token}")`
    // after logging the click, so this is not a free naming choice.
    {
      path: '/l/:token',
      name: 'affiliate-lead-capture',
      component: () => import('../views/AffiliateLeadCaptureView.vue'),
      meta: { public: true },
    },
    // ADR-017 (TASK-054) — public, unauthenticated in-app payment page a
    // client reaches from the pay link their agent shared. Token-gated, no
    // app chrome (meta.public), same pattern as /l/:token above.
    {
      path: '/pay/:token',
      name: 'payment-page',
      component: () => import('../views/PaymentPageView.vue'),
      meta: { public: true },
    },
    // TASK-056 Sprint P1/P3 — public, unauthenticated product showcase a
    // prospect reaches from an Agent's product-share link. Path MUST be
    // exactly `/p/:token` — ProductShareLinkResource (backend) builds
    // public_url as "{frontend_url}/p/{token}", not a free naming choice.
    {
      path: '/p/:token',
      name: 'product-share',
      component: () => import('../views/ProductShareView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { navLabel: 'My Day' },
    },
    {
      path: '/clients',
      name: 'clients',
      component: () => import('../views/ClientsView.vue'),
      meta: { navLabel: 'ลูกค้า' },
    },
    // TASK-056 Sprint P3 — Product browse + share ("ส่วน Product").
    {
      path: '/products',
      name: 'products',
      component: () => import('../views/ProductBrowseView.vue'),
      meta: { navLabel: 'สินค้า' },
    },
    // ADR-017 (TASK-054) — authenticated order / payment collection.
    {
      path: '/orders',
      name: 'orders',
      component: () => import('../views/OrdersView.vue'),
      meta: { navLabel: 'คำสั่งซื้อ' },
    },
    /*
     * TASK-169 §5.2 — REDIRECTS, NOT DELETIONS.
     *
     * `ReferralsView.vue` and `PipelineView.vue` are gone (Phase 4b): the
     * submission log became the deal block inside each client's drawer, and
     * the board became `/clients`'s second view mode. The two URLs are not
     * gone, because agents bookmark URLs and HomeView still links to
     * `/pipeline` — the human explicitly kept that quick link
     * ("ไม่ลบ", 2026-08-11). A 404 is a worse outcome than a redirect.
     *
     * `/pipeline` lands in PIPELINE MODE, not on the client list. That is the
     * whole reason Phase 3 put the mode in the query string rather than in
     * component state (§5.3): a redirect needs somewhere specific to land,
     * and dropping an agent who asked for the board onto a roster of people
     * is exactly the regression the quick link was kept to avoid.
     *
     * NOTE: `GET /referrals` the API is untouched and still in use —
     * PipelineBoard.vue reads it, and OrdersView.vue reads it too. Only the
     * client-side ROUTE moved.
     */
    {
      path: '/referrals',
      redirect: { name: 'clients' },
    },
    {
      path: '/pipeline',
      redirect: { name: 'clients', query: { view: 'pipeline' } },
    },
    {
      path: '/academy',
      name: 'academy',
      component: () => import('../views/AcademyView.vue'),
      meta: { navLabel: 'Academy' },
    },
    /*
     * TASK-167 §2 — ROUTES, NOT OVERLAYS, AND THE REASON IS THE HARDWARE
     * BACK BUTTON.
     *
     * Everything below used to be an inline expander on /academy. Building
     * them as overlays instead would mean Android back and iOS swipe-back
     * exit Academy entirely rather than returning to the list — on a
     * phone-first app that is not a detail.
     *
     * `hideBottomNav` is the third chrome state App.vue gained for these:
     * authenticated, themed, top bar intact, no tab bar. NOT `public`,
     * which also controls the full-bleed background (§5).
     */
    {
      path: '/academy/lessons/:id',
      name: 'academy-lesson',
      component: () => import('../views/AcademyLessonView.vue'),
      meta: { navLabel: 'บทเรียน', hideBottomNav: true },
    },
    {
      path: '/academy/lessons/:id/quiz',
      name: 'academy-lesson-quiz',
      component: () => import('../views/AcademyLessonQuizView.vue'),
      meta: { navLabel: 'แบบทดสอบท้ายบทเรียน', hideBottomNav: true },
    },
    {
      path: '/academy/exams/:id',
      name: 'academy-exam',
      component: () => import('../views/AcademyExamView.vue'),
      meta: { navLabel: 'แบบประเมินผล', hideBottomNav: true },
    },
    {
      path: '/commission',
      name: 'commission',
      component: () => import('../views/CommissionView.vue'),
      meta: { navLabel: 'Commission' },
    },
    {
      path: '/leaderboard',
      name: 'leaderboard',
      component: () => import('../views/LeaderboardView.vue'),
      meta: { navLabel: 'Leaderboard' },
    },
    // ADR-011 Section 4 (TASK-033) — authenticated "My Affiliate Links".
    {
      path: '/affiliate-links',
      name: 'affiliate-links',
      component: () => import('../views/AffiliateLinksView.vue'),
      meta: { navLabel: 'ลิงก์พันธมิตร' },
    },
    // ADR-024 / TASK-109 — the team leader's read-only monitoring screen.
    // Reached from Home's เมนูทั้งหมด grid, and only when the caller has at
    // least one direct report; ADR-024 §9 explains why it is NOT a sixth
    // BottomNav tab. The route itself is deliberately unguarded beyond the
    // session check: leadership is a server-side fact (users.manager_id),
    // never a flag the client may assert, so an agent with no reports who
    // types this URL gets a clean "คุณยังไม่มีลูกทีม" state from the API's
    // own is_leader:false answer rather than a router redirect.
    {
      path: '/my-team',
      name: 'my-team',
      component: () => import('../views/MyTeamView.vue'),
      meta: { navLabel: 'ทีมของฉัน' },
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/ProfileSettingsView.vue'),
      meta: { navLabel: 'โปรไฟล์ของฉัน' },
    },
    {
      path: '/notifications',
      name: 'notifications',
      component: () => import('../views/NotificationsView.vue'),
      meta: { navLabel: 'การแจ้งเตือน' },
    },
    // TASK-075 (2026-08-02, human-confirmed via AskUserQuestion) — full
    // announcements list + search, reached via a "ดูทั้งหมด" link on
    // Home rather than a 6th BottomNav tab.
    {
      path: '/announcements',
      name: 'announcements',
      component: () => import('../views/AnnouncementsListView.vue'),
      meta: { navLabel: 'ข่าวสารทั้งหมด' },
    },
    {
      path: '/about',
      name: 'about',
      // route level code-splitting
      // this generates a separate chunk (About.[hash].js) for this route
      // which is lazy-loaded when the route is visited.
      component: () => import('../views/AboutView.vue'),
    },
  ],
})

// Sanctum SPA session guard (BR-1 access-gating for cert-locked routes is
// enforced separately, server-side, per feature — this guard only handles
// "logged in or not"). Runs on every navigation; cheap after the first
// check since fetchUser() only runs once (status flips 'idle' -> 'ready').
router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  // Bug fix (2026-08-03, human-reported: valid sessions bounced to /login).
  // Was `=== 'idle'`, which silently skipped the await once main.ts's
  // splash boot (TASK-078) had already flipped status to 'checking' —
  // Vue Router starts this first navigation inside `app.use(router)`,
  // BEFORE main.ts finishes awaiting its own fetchUser(), so the guard
  // read `isAuthenticated === false` on a session that was merely still
  // in flight and redirected. `!== 'ready'` waits for the answer instead
  // of racing it; fetchUser() itself now de-dupes concurrent callers onto
  // one shared request (see stores/auth.ts), so this costs no extra call.
  if (authStore.status !== 'ready') {
    await authStore.fetchUser()
    // TASK-055 / ADR-018 — once we know the authenticated company, load its
    // theme (corrects/overrides the pre-login public theme). Fire-and-forget
    // + resilient: never block navigation, never throw.
    if (authStore.isAuthenticated) {
      void useThemeStore().loadForMe()
    }
  }

  if (!to.meta.public && !authStore.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // A logged-in visitor has no use for the login screen, or for a bare
  // registration form — bounce them home.
  //
  // EXCEPT a recruit link (TASK-116 / ADR-025: /register?ref=<token>).
  // Human-reported 2026-08-05: a leader opened their own freshly minted
  // link to check it and silently landed on Home, which reads exactly
  // like "the link is broken". It was not — the guard was doing its job
  // on a case it had never been told about. Letting `?ref=` through means
  // the leader sees the real page their recruit will see (the single most
  // useful thing they can do with their own link), and RegisterView shows
  // a banner offering to log out. An anonymous recruit is unaffected
  // either way; this branch never runs for them.
  const hasRefToken = typeof to.query.ref === 'string' && to.query.ref.trim().length > 0

  /*
   * TASK-218 — the Agent Portal is for agents. A Super Admin gets the
   * notice screen instead of an agent dashboard rendered from their own
   * (admin) identity: zero XP, no team, no orders — which reads as a
   * broken app rather than as "wrong role for this door". Full reasoning,
   * including why the two apps share one session at all, is in
   * SuperAdminNoticeView.vue's docblock.
   *
   * Scoped to `!to.meta.public` DELIBERATELY. The public token pages
   * (/p/:token product share, /pay/:token, /l/:token affiliate) must stay
   * openable by anyone holding the link — including a Super Admin
   * checking that a link works, which is the single most likely reason
   * they would ever open this app. Blocking those would recreate the exact
   * "opened my own link and it looked broken" complaint the /register?ref=
   * exception below already exists to prevent.
   *
   * NOT a security boundary — a client-side guard never is. Every endpoint
   * is gated server-side by Policies/Abilities (CLAUDE.md §5). This removes
   * confusion, nothing more.
   *
   * super_admin ONLY, not company_admin: nobody has reported the same
   * confusion for that role, and locking a role out of a whole app on a
   * guess is not a call to make for the human (BR-7).
   */
  if (!to.meta.public && authStore.user?.role === 'super_admin') {
    return { name: 'super-admin-notice' }
  }

  if (to.name === 'login' && authStore.isAuthenticated) {
    // A Super Admin who just signed in here would otherwise be sent to
    // 'home' only for the rule above to bounce them again on the next
    // pass — one hop, stated directly.
    return authStore.user?.role === 'super_admin'
      ? { name: 'super-admin-notice' }
      : { name: 'home' }
  }

  if (to.name === 'register' && authStore.isAuthenticated && !hasRefToken) {
    return { name: 'home' }
  }
})

export default router
