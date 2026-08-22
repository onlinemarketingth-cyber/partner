<script setup lang="ts">
/**
 * ClientsView — Customer domain (Phase 3), wired to the real API.
 *
 * Section 5 rule 4: an Agent only ever sees clients they personally
 * referred — the API already narrows /clients server-side for the
 * Agent role, so this view never needs to filter client-side (and
 * must not pretend to see more than the API returns).
 *
 * PDPA (Section 6): health_notes is sensitive personal data — shown
 * only inside the detail drawer for a client this agent can already
 * view (API already gates this; UI adds no extra exposure). Files are
 * never linked directly — every document is fetched through the
 * authenticated download endpoint, never a raw URL.
 *
 * "สินค้าที่สนใจ" (human-requested, 2026-07-13): each client's status +
 * interested product(s) are shown via their Referral rows — reused
 * rather than adding a new Client field (human's explicit choice, see
 * backend ClientResource's comment). A client can have zero, one, or
 * several referrals (several products); all are listed, never
 * collapsed to a single value. The quick "+ เพิ่มสินค้าที่สนใจ" form
 * creates a real Referral via the same POST /referrals endpoint
 * ReferralsView.vue uses (client_id preset to the open client) — BR-1
 * (Basic cert gate) applies identically, including the disabled-button
 * treatment when not yet passed.
 *
 * TASK-169 Phase 2: those rows ARE ReferralsView's rows now
 * (ReferralRow.vue), so the drawer also carries each deal's payment state
 * and TASK-141's one-press "เก็บเงินเลย" — an agent no longer has to leave
 * the client to collect on them.
 *
 * TASK-169 Phase 3 — TWO VIEW MODES ON ONE SCREEN (§3 D1+D2):
 *
 *   list (default) : people. Deals live inside each person's drawer.
 *   pipeline       : PipelineBoard, the SAME board /pipeline renders, across
 *                    every one of this agent's deals. It is the cross-client
 *                    view a per-client drawer structurally cannot give.
 *
 * THE MODE LIVES IN THE URL (`?view=pipeline`), not in component state.
 * §5.3: Phase 4 redirects /pipeline here — HomeView still links to it and the
 * human explicitly kept that link — so the redirect needs somewhere specific
 * to land. A mode held only in memory would dump the agent on the client list
 * instead of the board. Reading it from the query string is also what makes
 * browser back/forward move between the two modes.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — three findings land on this file:
//   1. ~19 sites leaked raw HTTP statuses into Thai copy → apiErrorMessage().
//   2. Every create/update/delete closed a panel and silently refetched →
//      toast.success().
//   3. Anything the agent does INSIDE the detail drawer writes to
//      errorMessage, which renders at the top of the page — behind the
//      drawer's fixed overlay, i.e. invisible. Those failures are toasted
//      as well; page-level ones (list load, create form) are not, since
//      their banner is already on screen.
// ApiError is still needed for the BR-1 422 branch in submitReferral().
// TASK-079 Phase 4 — isAbortError() for the page-level AbortController.
import { apiErrorMessage, isAbortError } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
// TASK-067 — the BR-1 gate has to know WHO is asking; see hasPassedBasic.
import { useAuthStore } from '@/stores/auth'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import NavBarAction from '@/design-system/components/NavBarAction.vue'
import TabFilterBar from '@/design-system/components/TabFilterBar.vue'
// TASK-169 Phase 3 — the /pipeline board, moved (not rewritten) into a
// shared component so this screen and that route render the same thing. Its
// ADR-026 handling lives in its own docblock.
import PipelineBoard, { type PipelineBoardKpi } from '@/design-system/components/PipelineBoard.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import FilterSheet, { type FilterOption } from '@/design-system/components/FilterSheet.vue'
// TASK-173 Phase 2 — the five native <select>s on this screen are gone. The
// element was themed; the list it OPENED was drawn by the OS and could not
// be, so under a dark tenant theme it was a small white box (ADR-018's one
// blind spot). AppSelect renders the list itself. Every conversion here is
// presentation-only: same v-model target, same emitted value, same request.
import AppSelect from '@/design-system/components/AppSelect.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
// TASK-082 (2026-08-03, UX audit): a client roster is homogeneous,
// comparable content — Material's rule is lists for that, cards only for
// heterogeneous blocks, and never cards when the user has to scan
// comparable items. Rows are flat now, grouped by client status.
import AppCard from '@/design-system/components/AppCard.vue'
import AppList from '@/design-system/components/AppList.vue'
import AppListGroupHeader from '@/design-system/components/AppListGroupHeader.vue'
import AuthenticatedMedia from '@/design-system/components/AuthenticatedMedia.vue'
// TASK-169 Phase 2 — the drawer's "สินค้าที่สนใจ" block is now the SAME
// deal row ReferralsView renders (extracted in Phase 1), so an agent can
// see a deal's payment state and collect on it without leaving the client.
import ReferralRow from '@/design-system/components/ReferralRow.vue'
// TASK-169 Phase 4a — TASK-026's split-commission control. It used to exist
// only on ReferralsView; ag-lead ruled (§5b item 1) that deleting that screen
// without rehoming this one is the worst outcome of the whole merge, because
// it decides WHO GETS PAID (BR-4). Same component that screen mounts, so the
// two can never produce different requests.
import CoAgentEditor, { type CoAgentOption } from '@/design-system/components/CoAgentEditor.vue'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'
import { useReferralOrders } from '@/composables/useReferralOrders'
import { THAILAND_PROVINCES } from '@/design-system/constants/thailandProvinces'
import { useThemeStore } from '@/stores/theme'

const theme = useThemeStore()

// ── TASK-169 Phase 3 — view mode, owned by the URL (see the file header) ──
//
// One value, one place: `route.query.view` IS the mode. Nothing mirrors it
// into a ref, because a mirror is what would let the URL and the screen
// disagree after a back/forward. Anything other than 'pipeline' means the
// client list, so a hand-typed `?view=nonsense` degrades to the default
// rather than rendering nothing.
const VIEW_PIPELINE = 'pipeline'
const route = useRoute()
const router = useRouter()
const viewMode = computed(() => (route.query.view === VIEW_PIPELINE ? VIEW_PIPELINE : 'list'))
const isListMode = computed(() => viewMode.value === 'list')

const VIEW_MODE_TABS = [
  { id: 'list', label: 'รายชื่อลูกค้า' },
  { id: VIEW_PIPELINE, label: 'กระบวนการขาย' },
]

/**
 * `push`, not `replace`: each mode switch is its own history entry, so the
 * hardware/browser back button returns to the mode the agent came from
 * instead of leaving the screen entirely. List mode DROPS the param rather
 * than writing `?view=list`, keeping `/clients` the canonical URL for the
 * default.
 */
/**
 * TASK-172 rev.2 (human, 2026-08-12) — "เพิ่มลูกค้า" is reachable from BOTH
 * modes, but the form itself only exists in the list.
 *
 * It used to be hidden outside list mode, which meant an agent looking at the
 * board had to work out that adding a person lives on another tab. Rather
 * than build a second copy of a 12-field form into the board, the button
 * SWITCHES to the list and opens the one form — which is also where the new
 * client, and the drawer that opens on them, are about to appear.
 *
 * Deliberately not a toggle any more: pressed from the board it must always
 * open, never close something the agent cannot see.
 */
function startCreateClient() {
  if (!isListMode.value) {
    setViewMode('list')
    showCreateForm.value = true

    return
  }
  showCreateForm.value = !showCreateForm.value
}

function setViewMode(mode: string) {
  if (mode === viewMode.value) return
  const query = { ...route.query }
  if (mode === VIEW_PIPELINE) query.view = VIEW_PIPELINE
  else delete query.view
  void router.push({ query })
}

interface ReferralItem {
  id: number
  product: { id: number; name: string; price_satang: number } | null
  branch: string
  // Nullable — human request (2026-07-13): "เวลาที่สะดวกนัดไม่ต้อง
  // validate", no longer a required SWS Referral field.
  preferred_time: string | null
  current_stage: { key: string; label: string }
  // TASK-026 — null unless this referral's commission is split.
  //
  // BOTH keys are OPTIONAL, for two independent reasons, and the type has to
  // be able to express "the key is not there at all":
  //
  //  1. `co_agent` is a `whenLoaded` key, so it is absent on any response
  //     that did not eager-load the relation.
  //  2. TASK-174 — while this company's co-agent split is switched OFF,
  //     `ReferralResource` omits BOTH keys entirely rather than sending
  //     nulls. Declaring `split_percentage: number | null` would let TS
  //     believe a missing field is a real `null`, which is exactly how a
  //     switched-off split would still get rendered as "0%".
  co_agent?: { id: number; name: string } | null
  split_percentage?: number | null
}
interface ClientItem {
  id: number
  name: string
  phone: string
  email: string | null
  consent_given_at: string | null
  health_notes: string | null
  referring_agent_id: number
  // Client-level status + lead source (human request, 2026-07-13,
  // following a CRM-standards comparison) — independent of any
  // Referral, so a client with zero referrals still has a status.
  status: { key: string; label: string }
  lead_source: string | null
  // TASK-014 demographic fields (human request, 2026-07-13) — general
  // personal data (Section 6), all optional.
  date_of_birth: string | null
  address: string | null
  province: string | null
  occupation: string | null
  // Human-requested: "products of interest" — already exists as
  // Referral.current_stage / Referral.product (BR-4.3 pipeline),
  // reused here rather than a new field (see backend ClientResource's
  // own comment).
  referrals: ReferralItem[]
  created_at: string
  // TASK-056 Sprint P2/P4 — client segmentation (BR-7 admin-editable
  // config, ClientCategoryService). Nullable — a client is never forced
  // into a category.
  client_category_id: number | null
  client_category_name: string | null
}
interface ClientCategoryOption {
  id: number
  name: string
}
interface ClientDocumentItem {
  id: number
  original_filename: string
  mime_type: string
  size_bytes: number
  created_at: string
}
interface ProductOption {
  id: number
  name: string
  price_satang: number
}
interface Certification {
  id: number
  // TASK-067 — needed to answer "have *I* passed Basic"; see hasPassedBasic.
  user_id: number
  cert_tier: { id: number; key: string; name: string } | null
}
interface SalesMaterialItem {
  id: number
  original_filename: string | null
  size_bytes: number | null
  mime_type: string | null
  // ADR-007 — video (upload or embed) alongside the original pdf/image.
  source_type: 'upload' | 'embed' | null
  embed_url: string | null
  processing_status: 'pending' | 'processing' | 'ready' | 'failed' | null
}
// ADR-007 — Amazon-e-com-style product image/video gallery.
interface ProductMediaItem {
  id: number
  media_type: 'image' | 'video'
  source_type: 'upload' | 'embed' | null
  stream_url: string | null
  thumbnail_url: string | null
  embed_url: string | null
  is_primary: boolean
}
// ADR-007 — admin-editable key-value spec sheet (BR-7).
interface ProductSpecItem {
  id: number
  spec_group: string | null
  spec_key: string
  spec_value: string
}
// ADR-007 Decision 3 — signed, expiring, revocable public share link.
interface ShareLinkItem {
  id: number
  share_url: string
  expires_at: string
  revoked_at: string | null
  view_count: number
}
// TASK-015 — Client Activity/Communication Log.
interface ClientActivityItem {
  id: number
  client_id: number
  logged_by_user_id: number
  logged_by_name: string
  type: { key: string; label: string }
  summary: string
  occurred_at: string
  follow_up_at: string | null
  can_edit: boolean
  can_delete: boolean
  created_at: string
}

const loading = ref(false)
// Only the very first fetch shows the full-page skeleton — subsequent
// reloads (after create/upload) update the list in place instead of
// blanking the whole page back to a skeleton every time.
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const clients = ref<ClientItem[]>([])
const toast = useToastStore()
const authStore = useAuthStore()

/**
 * Drawer-scoped failure reporting (see the file header). Keeps the banner
 * in sync for when the drawer is closed, and toasts so the agent can
 * actually read it while the drawer is open.
 */
function reportDrawerError(e: unknown, fallback: string): void {
  // TASK-079 Phase 4 (UX audit) — a request this view cancelled on unmount
  // must never surface. Guarded centrally here because every drawer load
  // funnels through this one reporter.
  if (isAbortError(e)) return
  reportDrawerMessage(apiErrorMessage(e, fallback))
}

/**
 * The same two channels, for a failure that has ALREADY been turned into a
 * sentence — CoAgentEditor emits its message rather than its exception,
 * because it is the thing that knows the 422 came from the split rules.
 * Feeding an already-normalised string back through `apiErrorMessage()`
 * would classify it as a non-ApiError and replace the server's specific
 * reason with the generic offline copy, which is exactly the failure mode
 * TASK-079 Phase 2 set out to remove.
 */
function reportDrawerMessage(message: string): void {
  errorMessage.value = message
  toast.error(message)
}

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit) — one controller for this view's
 * lifetime; every request it makes carries the signal and onUnmounted
 * cancels whatever is still in flight. Clients is the second-heaviest
 * screen (list + products + certs + categories on mount, then documents /
 * activities / materials / media / specs per drawer open).
 */
const pageAbort = new AbortController()
onUnmounted(() => pageAbort.abort())

/**
 * TASK-141 payment state, shared with ReferralsView through
 * useReferralOrders (TASK-169 Phase 2) — GET /orders is the same call that
 * screen makes, and it is scoped server-side (TenantScope on Order +
 * OrderController::index narrowing to agent_id = self, BR-6/§5.4), so no
 * order that isn't this agent's own can reach the drawer.
 *
 * TASK-191 §3.2 REVISES the original timing. It used to be deferred to the
 * first drawer open ("the deal rows only exist inside a drawer, and the
 * sweep is up to 10 requests") — true for the drawer's own rows, but no
 * longer the whole story: the new "share the paid voucher" button on the
 * COLLAPSED card (below, next to the client's name) has to know, before any
 * drawer ever opens, whether a client has a paid order at all. So the sweep
 * is now kicked off from onMounted() alongside the page's other loads
 * (ensureOrdersLoaded() is memoized — the drawer's own ensureOrdersLoaded()
 * call is a no-op once this one has already run) rather than waiting for a
 * drawer. It still runs concurrently with, not before, loadClients(), so
 * the list itself is never held up waiting on it.
 */
const {
  ordersError,
  collectingId,
  payActionError,
  showShareModal,
  shareUrl,
  shareHeading,
  shareOrderId,
  shareDefaultEmail,
  ensureOrdersLoaded,
  orderFor,
  openShareFor,
  viewSlipFor,
  collectPayment,
} = useReferralOrders(pageAbort.signal)

/**
 * The header shows the numbers for whatever mode is on screen. In pipeline
 * mode those are the BOARD's own (deal counts, "รอดำเนินการต่อ", BR-4
 * "ชำระเงินแล้ว") — it owns that data, so it hands them up rather than this
 * screen re-deriving them from a second fetch.
 */
const pipelineKpis = ref<PipelineBoardKpi[]>([])
const kpis = computed(() =>
  isListMode.value
    ? [
        { label: 'ลูกค้าที่ดูแล', value: clients.value.length },
        { label: 'ให้ความยินยอมแล้ว', value: clients.value.filter((c) => c.consent_given_at).length },
      ]
    : pipelineKpis.value,
)

// TASK-056 Sprint P4 — search (reuses the `q` free-text name/phone/
// email param that already existed server-side since TASK-049) +
// category filter (net-new — GET /clients?client_category_id=).
const searchQuery = ref('')
const selectedCategoryId = ref<number | null>(null)
let searchDebounce: ReturnType<typeof setTimeout> | null = null

async function loadClients() {
  loading.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (searchQuery.value.trim()) params.set('q', searchQuery.value.trim())
    if (selectedCategoryId.value) params.set('client_category_id', String(selectedCategoryId.value))
    const qs = params.toString()
    const res = await api.get<{ data: ClientItem[] }>(`/clients${qs ? `?${qs}` : ''}`, pageAbort.signal)
    clients.value = res.data
  } catch (e) {
    if (isAbortError(e)) return
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

watch(searchQuery, () => {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadClients, 350)
})

function selectCategory(categoryId: number | null) {
  selectedCategoryId.value = categoryId
  loadClients()
}

// --- Client categories (BR-7 config, ClientCategoryService) — loaded
// once up front for the filter sheet. The Admin CRUD screen (TASK-056
// Sprint P2) owns renaming/adding/deleting; this view is read-only.
const categories = ref<ClientCategoryOption[]>([])

// TASK-085 — the category filter moved from an inline chip row into a
// bottom sheet (see the #tabs slot). `null` is "ทั้งหมด", matching what
// selectCategory(null) already meant to the API call.
const filterSheetOpen = ref(false)

const categoryFilterOptions = computed<FilterOption[]>(() => [
  { value: null, label: 'ทั้งหมด' },
  ...categories.value.map((c) => ({ value: c.id, label: c.name })),
])

const selectedCategoryLabel = computed(() =>
  categories.value.find((c) => c.id === selectedCategoryId.value)?.name ?? null,
)

function onCategoryFilterSelect(value: string | number | null) {
  selectCategory(value === null ? null : Number(value))
}

async function loadCategories() {
  try {
    const res = await api.get<{ data: ClientCategoryOption[] }>('/client-categories', pageAbort.signal)
    categories.value = res.data
  } catch {
    // Non-fatal — the filter chips just won't render; search + the main
    // list still work fine.
  }
}

// TASK-079 Phase 4 — awaited together rather than fired and forgotten.
// Same three calls, same payloads; only the floating promises are gone.
//
// TASK-191 §3.2 adds a 4th: ensureOrdersLoaded(), so the collapsed-card
// share button (see file header above) has payment state to read the first
// time the list itself renders, not only after a drawer opens. It runs
// concurrently with the other three, not before them — hasLoadedOnce (and
// therefore the list's first paint) is set inside loadClients() itself and
// does not wait on this Promise.all to settle.
onMounted(() => {
  void Promise.all([loadClients(), loadDrawerOptions(), loadCategories(), ensureOrdersLoaded()])
})

// --- Options every client's drawer needs: products of interest + the BR-1
// gate + TASK-026's co-agent picker. Loaded once up front rather than per
// drawer open — the same three company-wide reads would otherwise repeat for
// every client the agent taps. ---
const products = ref<ProductOption[]>([])
const certifications = ref<Certification[]>([])
// TASK-026 — id+name only, this company's other agents (never yourself).
// Server-scoped (ReferralController::coAgentOptions), so BR-6 isolation is
// not something this list is trusted to do.
const coAgentOptions = ref<CoAgentOption[]>([])
/**
 * TASK-174 — is TASK-026's co-agent commission split switched ON for this
 * company? (`GET /commission-split-settings`, readable by an Agent precisely
 * so this screen can answer the question.)
 *
 * WHAT THIS FLAG IS, AND IS NOT. It is the SERVER's answer, fetched once per
 * page load alongside the other company-wide options — not a rule this file
 * implements. Spec §4 is explicit that the client only REFLECTS the switch;
 * the API refuses `PATCH /referrals/{id}/co-agent` and
 * `GET /referrals/co-agent-options` on its own while it is off, and
 * `ReferralResource` already drops the two fields. Nothing below re-derives
 * "should this split?" from stages, roles or data — it asks and renders.
 *
 * It starts FALSE and stays false if the read fails, matching
 * `CommissionSplitSettingService::isEnabledForCompany()`'s own fail-closed
 * contract: while we do not know, we do not offer a money control.
 *
 * ONE fetch per page load, not one per referral row: the flag is per COMPANY,
 * and a drawer full of deals asking the same question is the client-side
 * version of the N+1 the backend's request-scoped memo exists to avoid.
 */
const splitEnabled = ref(false)
/**
 * BR-1 (Access Gate), UI half — "have *I* passed Basic".
 *
 * TASK-067 (human-reported 2026-07-31) — `GET /user-certifications` returns
 * the FULL company roster for a Company Admin / Super Admin, not just the
 * caller's own rows, so an unfiltered `.some()` answers "has ANYONE in this
 * company passed Basic" and opens the gate for a caller who has not.
 * TASK-067 fixed that on ReferralsView, ProductBrowseView and
 * AffiliateLinksView; this screen was missed and kept the unfiltered
 * predicate. Carried across by TASK-169 Phase 4b, because deleting
 * ReferralsView would otherwise have deleted the only record of the rule
 * along with the only correct implementation of it.
 *
 * The API-level half is what actually protects BR-1 (ReferralService rejects
 * the submission whatever the UI shows). This is only honest reflection of
 * that state — never a gate the client is trusted to enforce.
 */
const hasPassedBasic = computed(() =>
  certifications.value.some((c) => c.user_id === authStore.user?.id && c.cert_tier?.key === 'basic'),
)

/** Names of the reads that failed, for the message under the product picker. */
const drawerOptionsError = ref('')

/**
 * THREE INDEPENDENT READS, EACH REPORTING ITS OWN FAILURE.
 *
 * This was one `Promise.all` inside one silent `catch`. Two things were
 * wrong with that, and together they produced a bug that could not be
 * diagnosed from the screen:
 *
 *  1. `Promise.all` rejects on the FIRST failure, and the three assignments
 *     sat after the await — so one endpoint failing discarded the other two
 *     successful responses as well. TASK-169 Phase 4 later added a third
 *     call to that same batch, widening the blast radius without anyone
 *     noticing.
 *  2. The catch said nothing. An agent whose product list failed to load
 *     saw an empty picker that is pixel-identical to "this company has no
 *     products", with no way to tell the two apart — and neither could I.
 *
 * Now each read stands alone: one failing cannot empty the others, and
 * whatever fails is NAMED on screen. "Non-fatal" must mean the rest still
 * works, never that the user is left guessing.
 */
async function loadDrawerOptions() {
  const failed: string[] = []

  async function load(label: string, run: () => Promise<void>) {
    try {
      await run()
    } catch (e) {
      // An abort is this component unmounting, not a failure to report to
      // someone who has already left the screen.
      if ((e as { name?: string } | null)?.name === 'AbortError') return
      failed.push(label)
    }
  }

  await Promise.all([
    load('สินค้า', async () => {
      products.value = (await api.get<{ data: ProductOption[] }>('/products', pageAbort.signal)).data
    }),
    load('ใบรับรอง', async () => {
      certifications.value = (
        await api.get<{ data: Certification[] }>('/user-certifications', pageAbort.signal)
      ).data
    }),
    /*
     * TASK-174 — the switch is read FIRST and the picker only afterwards,
     * because `GET /referrals/co-agent-options` now answers 403 while the
     * split is off. Chaining them inside ONE `load()` keeps that ordering
     * honest and keeps the pair reporting as a single named failure; firing
     * both in parallel would put a guaranteed 403 in the error banner on
     * every page load for every company that has the feature switched off.
     *
     * Both refs are reset before the read so a failure can only ever leave
     * the safe state (off, no options), never a stale one from a previous
     * load.
     */
    load('การแบ่งคอมมิชชั่น', async () => {
      splitEnabled.value = false
      coAgentOptions.value = []
      splitEnabled.value = (
        await api.get<{ data: { is_enabled: boolean } }>('/commission-split-settings', pageAbort.signal)
      ).data.is_enabled
      if (!splitEnabled.value) return
      coAgentOptions.value = (
        await api.get<{ data: CoAgentOption[] }>('/referrals/co-agent-options', pageAbort.signal)
      ).data
    }),
  ])

  drawerOptionsError.value = failed.length ? `โหลดข้อมูลไม่สำเร็จ: ${failed.join(', ')}` : ''
}

// --- Create client ---
const showCreateForm = ref(false)
const creating = ref(false)
const createForm = ref({
  name: '',
  phone: '',
  email: '',
  // TASK-049 — national ID (PDPA §6). Optional; validated server-side as
  // a real Thai 13-digit ID (checksum). Stored encrypted at rest.
  national_id: '',
  health_notes: '',
  consent: false,
  lead_source: '',
  // TASK-014 demographic fields — all optional.
  date_of_birth: '',
  address: '',
  province: '',
  occupation: '',
})

/**
 * TASK-173 Phase 2 — the province list for AppSelect. The leading
 * empty-string entry is the SAME `<option value="">` the native select had:
 * a real, re-selectable "no province" choice, not a placeholder prompt, so
 * an agent can still clear a province they picked by mistake. 77 entries
 * (a geographic fact, not BR-7 config — see thailandProvinces.ts), which is
 * exactly why the list has to scroll inside the sheet and answer type-ahead.
 */
const provinceOptions = computed(() => [
  { value: '', label: 'จังหวัด (ถ้ามี)' },
  ...THAILAND_PROVINCES.map((p) => ({ value: p, label: p })),
])

// Common channels offered as non-enforced <datalist> suggestions — the
// actual channel list isn't finalized/agreed (BR-7), so this is never
// validated against on the backend; an agent can type anything.
const leadSourceSuggestions = ['Referral (คนรู้จักแนะนำ)', 'Walk-in', 'Event/Booth', 'Facebook', 'LINE OA', 'Instagram']

// --- Required-field validation (human request, 2026-07-13): mark
// required fields with *, show the error right at the textbox, and
// move focus there — rather than just silently disabling the submit
// button with no explanation. ---
const nameInputEl = ref<HTMLInputElement | null>(null)
const phoneInputEl = ref<HTMLInputElement | null>(null)
const nameError = ref('')
const phoneError = ref('')

function validateCreateForm(): boolean {
  nameError.value = ''
  phoneError.value = ''
  if (!createForm.value.name.trim()) {
    nameError.value = 'กรุณากรอกชื่อลูกค้า'
    nameInputEl.value?.focus()
    return false
  }
  if (!createForm.value.phone.trim()) {
    phoneError.value = 'กรุณากรอกเบอร์โทร'
    phoneInputEl.value?.focus()
    return false
  }
  return true
}

async function submitCreate() {
  if (!validateCreateForm()) return
  creating.value = true
  errorMessage.value = ''
  try {
    // referring_agent_id is never sent from here — the Agent role has
    // it prohibited server-side (StoreClientRequest) and forced to
    // self in ClientService::create(). Only Company Admin/Super Admin
    // would need to pick an agent, which this Agent-Portal view never
    // does (that belongs in Admin, out of scope for Phase 3).
    // status is never sent — every new client always starts at "new"
    // server-side (ClientService::create()).
    const created = await api.post<{ data: ClientItem }>('/clients', {
      name: createForm.value.name,
      phone: createForm.value.phone,
      email: createForm.value.email || undefined,
      national_id: createForm.value.national_id.trim() || undefined,
      health_notes: createForm.value.health_notes || undefined,
      consent_given_at: createForm.value.consent ? new Date().toISOString() : undefined,
      lead_source: createForm.value.lead_source || undefined,
      date_of_birth: createForm.value.date_of_birth || undefined,
      address: createForm.value.address || undefined,
      province: createForm.value.province || undefined,
      occupation: createForm.value.occupation || undefined,
    })
    createForm.value = {
      name: '',
      phone: '',
      email: '',
      national_id: '',
      health_notes: '',
      consent: false,
      lead_source: '',
      date_of_birth: '',
      address: '',
      province: '',
      occupation: '',
    }
    nameError.value = ''
    phoneError.value = ''
    showCreateForm.value = false
    await loadClients()
    // The new client lands somewhere in a list sorted server-side, not
    // necessarily at the top — without this the form just disappears.
    toast.success('บันทึกลูกค้าแล้ว')

    /*
     * TASK-172 (human, 2026-08-12) — OPEN THE DRAWER ON THE CLIENT WE JUST
     * MADE.
     *
     * Almost nobody adds a client for its own sake; they add one in order to
     * sell them something, and "+ เพิ่มสินค้าที่สนใจ" lives inside the
     * drawer. Until TASK-169 Phase 4 the deleted ReferralsView did both in
     * one form, so the merge quietly turned one flow into "save, then hunt
     * for the person you just typed in" — the list is sorted server-side, so
     * they are not reliably at the top.
     *
     * Guarded on the client actually being in the reloaded list: a search
     * term or category filter can legitimately exclude them, and opening a
     * drawer whose `selectedClient` computes to null renders an empty panel.
     * The toast already confirms the save, so skipping is a fine outcome.
     */
    const createdId = created?.data?.id
    const inList = createdId == null ? null : (clients.value.find((c) => c.id === createdId) ?? null)
    if (inList) await openClient(inList)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'บันทึกลูกค้าไม่สำเร็จ')
  } finally {
    creating.value = false
  }
}

// --- Detail drawer + documents ---
const selectedClientId = ref<number | null>(null)
const selectedClient = computed(() => clients.value.find((c) => c.id === selectedClientId.value) ?? null)
const documents = ref<ClientDocumentItem[]>([])
const loadingDocuments = ref(false)

async function loadDocuments(clientId: number) {
  loadingDocuments.value = true
  try {
    const res = await api.get<{ data: ClientDocumentItem[] }>(`/clients/${clientId}/documents`, pageAbort.signal)
    documents.value = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดเอกสารไม่สำเร็จ')
  } finally {
    loadingDocuments.value = false
  }
}

/**
 * TASK-079 Phase 4 (2026-08-03, UX audit — perceived performance).
 *
 * This used to `await` the documents fetch to completion and only THEN
 * start loadActivities() — a pure waterfall: two independent endpoints,
 * neither needing the other's result, but the drawer took the SUM of both
 * round trips to fill in, with the activity list sitting blank for the
 * whole of the first one. On a phone connection that is the difference
 * between the drawer feeling instant and feeling broken.
 *
 * Same two calls, same params, both still reporting their own failure
 * independently (reportDrawerError) — they just run concurrently now, so
 * the drawer costs one round trip instead of two.
 */
async function openClient(client: ClientItem) {
  selectedClientId.value = client.id
  documents.value = []
  // ensureOrdersLoaded() joins the same concurrent batch (and is a no-op
  // after the first successful sweep) — the payment chips are decoration
  // on rows that must render whether or not it arrives.
  await Promise.all([loadDocuments(client.id), loadActivities(client.id), ensureOrdersLoaded()])
}

function closeDrawer() {
  selectedClientId.value = null
  documents.value = []
  expandedProductId.value = null
  materialsByProduct.value = {}
  mediaByProduct.value = {}
  specsByProduct.value = {}
  expandedShareLinksMaterialId.value = null
  shareLinksByMaterial.value = {}
  showReferralForm.value = false
  referralForm.value = { product_id: '', branch: '', preferred_time: '' }
  activities.value = []
  showActivityForm.value = false
  activityForm.value = { type: 'call', summary: '', follow_up_at: '' }
  editingActivityId.value = null
  editingSummary.value = ''
}

// --- TASK-015: Client Activity/Communication Log — a record of past
// calls/chats/meetings, independent of the Referral pipeline. Loaded
// alongside documents whenever the drawer opens. ---
const activities = ref<ClientActivityItem[]>([])
const loadingActivities = ref(false)
const ACTIVITY_TYPES = [
  { key: 'call', label: 'Call' },
  { key: 'chat', label: 'Chat' },
  { key: 'meeting', label: 'Meeting' },
  { key: 'other', label: 'Other' },
]

async function loadActivities(clientId: number) {
  loadingActivities.value = true
  try {
    const res = await api.get<{ data: ClientActivityItem[] }>(`/clients/${clientId}/activities`, pageAbort.signal)
    activities.value = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดประวัติการติดต่อไม่สำเร็จ')
  } finally {
    loadingActivities.value = false
  }
}

const showActivityForm = ref(false)
const creatingActivity = ref(false)
const activityForm = ref({ type: 'call', summary: '', follow_up_at: '' })

/** TASK-173 Phase 2 — ACTIVITY_TYPES in AppSelect's shape; keys unchanged. */
const activityTypeOptions = computed(() =>
  ACTIVITY_TYPES.map((t) => ({ value: t.key, label: t.label })),
)

async function submitActivity() {
  if (!selectedClientId.value || !activityForm.value.summary) return
  creatingActivity.value = true
  errorMessage.value = ''
  try {
    await api.post(`/clients/${selectedClientId.value}/activities`, {
      type: activityForm.value.type,
      summary: activityForm.value.summary,
      follow_up_at: activityForm.value.follow_up_at || undefined,
    })
    activityForm.value = { type: 'call', summary: '', follow_up_at: '' }
    showActivityForm.value = false
    await loadActivities(selectedClientId.value)
    toast.success('บันทึกการติดต่อแล้ว')
  } catch (e) {
    reportDrawerError(e, 'บันทึกการติดต่อไม่สำเร็จ')
  } finally {
    creatingActivity.value = false
  }
}

// Edit is summary-only (per TASK-015's acceptance criteria: "an agent
// can edit their own activity's summary") — the Policy also only
// permits the original logger, enforced server-side regardless of what
// the UI shows.
const editingActivityId = ref<number | null>(null)
const editingSummary = ref('')
const savingActivityEdit = ref(false)

function startEditActivity(activity: ClientActivityItem) {
  editingActivityId.value = activity.id
  editingSummary.value = activity.summary
}

function cancelEditActivity() {
  editingActivityId.value = null
  editingSummary.value = ''
}

async function saveActivityEdit(activityId: number) {
  if (!editingSummary.value || !selectedClientId.value) return
  savingActivityEdit.value = true
  errorMessage.value = ''
  try {
    await api.put(`/client-activities/${activityId}`, { summary: editingSummary.value })
    cancelEditActivity()
    await loadActivities(selectedClientId.value)
    toast.success('แก้ไขการติดต่อแล้ว')
  } catch (e) {
    reportDrawerError(e, 'แก้ไขการติดต่อไม่สำเร็จ')
  } finally {
    savingActivityEdit.value = false
  }
}

// TASK-079 Phase 2 (UX audit) — deleting an activity used to fire straight
// off the trash icon with no confirmation whatsoever, on a touch target
// sitting a few pixels from the edit pencil. Now gated by the same
// ConfirmDialog every other destructive action in the app uses.
const deleteActivityTargetId = ref<number | null>(null)
const showDeleteActivityConfirm = ref(false)
const deletingActivity = ref(false)

function askDeleteActivity(activityId: number) {
  deleteActivityTargetId.value = activityId
  showDeleteActivityConfirm.value = true
}

async function confirmDeleteActivity() {
  const activityId = deleteActivityTargetId.value
  if (!activityId || !selectedClientId.value) return
  deletingActivity.value = true
  errorMessage.value = ''
  try {
    await api.delete(`/client-activities/${activityId}`)
    showDeleteActivityConfirm.value = false
    await loadActivities(selectedClientId.value)
    toast.success('ลบการติดต่อแล้ว')
  } catch (e) {
    reportDrawerError(e, 'ลบการติดต่อไม่สำเร็จ')
  } finally {
    deletingActivity.value = false
    deleteActivityTargetId.value = null
  }
}

// --- Sales materials per interested product — lazy-loaded on demand,
// cached per product so re-toggling doesn't re-fetch. ---
const expandedProductId = ref<number | null>(null)
const materialsByProduct = ref<Record<number, SalesMaterialItem[]>>({})
const loadingMaterialsFor = ref<number | null>(null)

async function toggleMaterials(productId: number) {
  if (expandedProductId.value === productId) {
    expandedProductId.value = null
    return
  }
  expandedProductId.value = productId
  expandedShareLinksMaterialId.value = null
  // TASK-079 Phase 4 (UX audit) — the same three cache-miss fetches, now
  // explicitly awaited together instead of fired as three floating
  // promises. Identical requests and identical caching (each is skipped
  // when already cached); this only makes the concurrency intentional and
  // gives the expansion a single point at which it is "done".
  await Promise.all([
    materialsByProduct.value[productId] ? undefined : loadMaterialsFor(productId),
    mediaByProduct.value[productId] ? undefined : loadMediaFor(productId),
    specsByProduct.value[productId] ? undefined : loadSpecsFor(productId),
  ])
}

async function loadMaterialsFor(productId: number) {
  loadingMaterialsFor.value = productId
  try {
    const res = await api.get<{ data: SalesMaterialItem[] }>(`/products/${productId}/sales-materials`, pageAbort.signal)
    materialsByProduct.value[productId] = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดสื่อการขายไม่สำเร็จ')
  } finally {
    loadingMaterialsFor.value = null
  }
}

async function downloadMaterial(material: SalesMaterialItem) {
  if (!material.original_filename) return // embed rows have nothing to download
  try {
    await api.download(`/sales-materials/${material.id}/download`, material.original_filename)
  } catch (e) {
    reportDrawerError(e, 'ดาวน์โหลดไม่สำเร็จ')
  }
}

// ── Product media gallery + specs (ADR-007) — read-only display for
// agents (management stays admin-only, see frontend-admin's
// ProductCatalogView.vue). ──
const mediaByProduct = ref<Record<number, ProductMediaItem[]>>({})
const loadingMediaFor = ref<number | null>(null)
const specsByProduct = ref<Record<number, ProductSpecItem[]>>({})
const loadingSpecsFor = ref<number | null>(null)

async function loadMediaFor(productId: number) {
  loadingMediaFor.value = productId
  try {
    const res = await api.get<{ data: ProductMediaItem[] }>(`/products/${productId}/media`, pageAbort.signal)
    mediaByProduct.value[productId] = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดแกลเลอรี่ไม่สำเร็จ')
  } finally {
    loadingMediaFor.value = null
  }
}

async function loadSpecsFor(productId: number) {
  loadingSpecsFor.value = productId
  try {
    const res = await api.get<{ data: ProductSpecItem[] }>(`/products/${productId}/specs`, pageAbort.signal)
    specsByProduct.value[productId] = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดสเปคสินค้าไม่สำเร็จ')
  } finally {
    loadingSpecsFor.value = null
  }
}

// ── External sharing — ADR-007 Decision 3. Signed, expiring, revocable
// PUBLIC link an agent can send a prospect (Agents are explicitly
// allowed to mint these — StoreSalesMaterialShareLinkRequest checks
// ProductPolicy::view, not ::update). Deliberate, narrow, human-
// approved exception to "never a public URL" — never generalize. ──
const shareLinksByMaterial = ref<Record<number, ShareLinkItem[]>>({})
const expandedShareLinksMaterialId = ref<number | null>(null)
const loadingShareLinksFor = ref<number | null>(null)
const creatingShareLinkFor = ref<number | null>(null)
const shareLinkExpiryDays = ref(7)
const copiedShareLinkId = ref<number | null>(null)

async function toggleShareLinks(materialId: number) {
  if (expandedShareLinksMaterialId.value === materialId) {
    expandedShareLinksMaterialId.value = null
    return
  }
  expandedShareLinksMaterialId.value = materialId
  if (!shareLinksByMaterial.value[materialId]) await loadShareLinksFor(materialId)
}

async function loadShareLinksFor(materialId: number) {
  loadingShareLinksFor.value = materialId
  try {
    const res = await api.get<{ data: ShareLinkItem[] }>(`/sales-materials/${materialId}/share-links`, pageAbort.signal)
    shareLinksByMaterial.value[materialId] = res.data
  } catch (e) {
    reportDrawerError(e, 'โหลดลิงก์แชร์ไม่สำเร็จ')
  } finally {
    loadingShareLinksFor.value = null
  }
}

async function createShareLink(materialId: number) {
  creatingShareLinkFor.value = materialId
  try {
    await api.post(`/sales-materials/${materialId}/share-links`, { expires_in_days: shareLinkExpiryDays.value })
    await loadShareLinksFor(materialId)
    toast.success('สร้างลิงก์แชร์แล้ว')
  } catch (e) {
    reportDrawerError(e, 'สร้างลิงก์แชร์ไม่สำเร็จ')
  } finally {
    creatingShareLinkFor.value = null
  }
}

// TASK-079 Phase 2 (UX audit) — revoking a share link is irreversible and
// breaks a URL the agent may already have sent a prospect, yet it fired
// straight off the icon with no confirmation. Same ConfirmDialog treatment
// as AffiliateLinksView.vue's revoke.
const revokeTarget = ref<{ materialId: number; linkId: number } | null>(null)
const showRevokeLinkConfirm = ref(false)
const revokingLink = ref(false)

function askRevokeShareLink(materialId: number, linkId: number) {
  revokeTarget.value = { materialId, linkId }
  showRevokeLinkConfirm.value = true
}

async function confirmRevokeShareLink() {
  const target = revokeTarget.value
  if (!target) return
  revokingLink.value = true
  try {
    await api.delete(`/share-links/${target.linkId}`)
    showRevokeLinkConfirm.value = false
    await loadShareLinksFor(target.materialId)
    toast.success('ยกเลิกลิงก์แล้ว')
  } catch (e) {
    reportDrawerError(e, 'ยกเลิกลิงก์ไม่สำเร็จ')
  } finally {
    revokingLink.value = false
    revokeTarget.value = null
  }
}

async function copyShareLink(link: ShareLinkItem) {
  try {
    await navigator.clipboard.writeText(link.share_url)
    copiedShareLinkId.value = link.id
    setTimeout(() => {
      if (copiedShareLinkId.value === link.id) copiedShareLinkId.value = null
    }, 2000)
  } catch {
    // Drawer-scoped: the banner behind the overlay would never be read.
    toast.error('คัดลอกลิงก์ไม่สำเร็จ — กรุณาคัดลอกด้วยตนเอง')
  }
}

function isLinkUsable(link: ShareLinkItem): boolean {
  return !link.revoked_at && new Date(link.expires_at) > new Date()
}

// --- Quick "add a product of interest" — creates a real Referral via
// the existing SWS Referral endpoint (human's explicit choice: reuse
// Referral, not a new concept — see task discussion). BR-1 still
// applies exactly as in ReferralsView.vue; the 422 it returns for a
// not-yet-certified agent is translated into the same clear Thai
// message used there. ---
const showReferralForm = ref(false)
const creatingReferral = ref(false)
const referralForm = ref({ product_id: '', branch: '', preferred_time: '' })

/**
 * TASK-211 — human, 2026-08-19: "หน้า frontend บันทึกสินค้าไม่ได้", then
 * "คุณเอา * validate ออกจากสาขา" — so สาขา is now optional (server-side too)
 * and สินค้า is the only thing this can complain about.
 *
 * The form was not broken: สาขา was empty, and BOTH the submit handler and
 * the button's `disabled` binding tested the same condition — so pressing
 * บันทึก did nothing, sent nothing, and said nothing. In the dark theme
 * `disabled:opacity-50` on a brand-coloured button is not a difference the
 * eye reads as "disabled", so the only thing the agent could conclude was
 * that saving is broken (confirmed from their DevTools capture: no POST
 * /referrals was ever made).
 *
 * The fix is to let the press through and name the missing field. Kept as
 * its own ref rather than the drawer-wide `errorMessage` so the sentence
 * renders inside the form it is about.
 */
const referralFormError = ref('')

/**
 * TASK-173 Phase 2 — product options for AppSelect. Stringified ids, so
 * `referralForm.product_id` stays the string it is initialised and RESET to
 * (`''`), the falsy guard in `submitReferral()` keeps working, and the
 * request still sends `Number(...)`. The old markup's disabled
 * `<option value="" disabled>เลือกสินค้า</option>` prompt is now the
 * `placeholder` prop — same "visible but unpickable" behaviour.
 */
const productSelectOptions = computed(() =>
  products.value.map((p) => ({ value: String(p.id), label: p.name })),
)

async function submitReferral() {
  if (!selectedClientId.value) return

  referralFormError.value = ''
  if (!referralForm.value.product_id) {
    referralFormError.value = 'กรุณาเลือกสินค้า'
    return
  }
  // สาขา is NOT checked here — human ruling 2026-08-19, TASK-211: the field
  // is optional, and StoreReferralRequest was relaxed to `nullable` in the
  // same change so this form and the server agree.

  creatingReferral.value = true
  errorMessage.value = ''
  try {
    // preferred_time is optional (human request, 2026-07-13) — omitted
    // entirely when blank rather than sent as '', so the backend's
    // nullable/date rule doesn't reject an empty string.
    await api.post('/referrals', {
      client_id: selectedClientId.value,
      product_id: Number(referralForm.value.product_id),
      branch: referralForm.value.branch.trim() || undefined,
      preferred_time: referralForm.value.preferred_time || undefined,
    })
    referralForm.value = { product_id: '', branch: '', preferred_time: '' }
    referralFormError.value = ''
    showReferralForm.value = false
    await refreshSelectedClient()
    toast.success('เพิ่มสินค้าที่สนใจแล้ว')
  } catch (e) {
    // The BR-1 422 branch is left exactly as-is — its translated sentence
    // is more specific than anything the shared normalizer can produce.
    // Only the reporting channel changes (drawer-scoped, see file header).
    if (e instanceof ApiError && e.status === 422) {
      const errors = (e.body as { errors?: Record<string, string[]> } | undefined)?.errors
      const message = errors?.agent_id?.[0]?.includes('BR-1')
        ? 'คุณยังไม่ผ่านใบรับรอง Basic — ไปที่หน้า Academy เพื่อเรียนและสอบผ่านก่อน (BR-1)'
        : errors
          ? Object.values(errors).flat().join(' ')
          : 'บันทึกไม่สำเร็จ'
      errorMessage.value = message
      toast.error(message)
    } else {
      reportDrawerError(e, 'บันทึกไม่สำเร็จ')
    }
  } finally {
    creatingReferral.value = false
  }
}

/**
 * TASK-026 / TASK-169 Phase 4a — after a split is written, re-read just the
 * open client so the row shows who it is now shared with. `ClientController`
 * eager-loads `referrals.coAgent` on BOTH index and show (one `RELATIONS`
 * constant since Phase 2), so this single fetch is enough — before that fix
 * it silently came back without the co-agent and the line vanished until a
 * full list reload.
 */
async function onCoAgentSaved() {
  await refreshSelectedClient()
}

// Re-fetches just the open client (not the whole list) so the drawer's
// "สินค้าที่สนใจ" section shows the brand-new referral immediately.
async function refreshSelectedClient() {
  if (!selectedClientId.value) return
  try {
    const res = await api.get<{ data: ClientItem }>(`/clients/${selectedClientId.value}`, pageAbort.signal)
    const idx = clients.value.findIndex((c) => c.id === selectedClientId.value)
    if (idx !== -1) clients.value[idx] = res.data
  } catch {
    // Silent — the referral itself was created successfully (the part
    // that matters); the drawer just won't reflect it until the next
    // full list reload. Not worth surfacing a second error on top of a
    // successful create.
  }
}

function formatDateTime(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

// --- Client-level status (human request, 2026-07-13) — a manual
// CRM-style lead marker independent of any Referral, editable any time
// via PUT /clients/{id} (ClientPolicy::update already permits the
// referring Agent). ---
const updatingStatus = ref(false)
const STATUS_OPTIONS = [
  { key: 'new', label: 'New' },
  { key: 'contacted', label: 'Contacted' },
  { key: 'interested', label: 'Interested' },
  { key: 'not_interested', label: 'Not Interested' },
]

/**
 * TASK-173 Phase 2 — STATUS_OPTIONS in AppSelect's shape. Values stay the
 * status KEY, which is what the old `<option :value="s.key">` submitted and
 * what `updateStatus()` PUTs as `status`.
 */
const statusSelectOptions = computed(() =>
  STATUS_OPTIONS.map((s) => ({ value: s.key, label: s.label })),
)

/**
 * TASK-173 Phase 2 — category options. The ids are stringified ON PURPOSE:
 * the native select's `@change` handed `updateCategory()` a DOM `value`,
 * which is always a string, and `''` is the "ไม่ระบุ" clear. Emitting a
 * number here would still work (`Number(3) === 3`) but would change the
 * argument's TYPE on a function whose contract says string — the kind of
 * quiet drift a presentation-only refactor must not introduce.
 */
const categorySelectOptions = computed(() => [
  { value: '', label: 'ไม่ระบุ' },
  ...categories.value.map((c) => ({ value: String(c.id), label: c.name })),
])

/**
 * TASK-082 — group headers by client status.
 *
 * `status` is chosen over `client_category_name` because every client
 * always has one (it defaults to "new" server-side, ClientService::create),
 * whereas a category is nullable — grouping by a nullable field would
 * produce a permanent "ไม่ระบุ" bucket instead of a meaningful shape.
 *
 * Grouped over `clients`, which is exactly the array the template renders:
 * search and the category chips both narrow the list SERVER-side
 * (loadClients), so `clients` already IS the filtered set and the headers
 * can never describe rows that aren't on screen. Empty groups are dropped.
 *
 * Presentation only — no request, no new field. Labels come from whatever
 * the API put on each row (`status.label`); STATUS_OPTIONS is used only for
 * ordering, and any status key added server-side later still gets its own
 * group at the end rather than vanishing from the list.
 */
const groupedClients = computed(() => {
  const order = STATUS_OPTIONS.map((s) => s.key)
  const buckets = new Map<string, { key: string; label: string; items: ClientItem[] }>()
  for (const c of clients.value) {
    const bucket = buckets.get(c.status.key) ?? { key: c.status.key, label: c.status.label, items: [] }
    bucket.items.push(c)
    buckets.set(c.status.key, bucket)
  }
  const rank = (key: string) => {
    const i = order.indexOf(key)
    return i === -1 ? order.length : i
  }
  return [...buckets.values()].sort((a, b) => rank(a.key) - rank(b.key))
})

/**
 * TASK-191 §3.2 — governs the collapsed-card share button next to the
 * client's name: null when this client has no paid order at all (button
 * hidden), otherwise the referral id whose order to share.
 *
 * JUDGMENT CALL (spec §3.2, flagged rather than guessed silently): a client
 * can have several paid orders across different referrals (different
 * products/renewals). The spec's own resolution is "most recently paid
 * wins" (`orders.where(status=paid).sortByDesc(paid_at).first()`). This
 * reads that off `orderFor()` — the SAME per-referral map the drawer's
 * ReferralRow already uses — which tracks at most ONE order per referral
 * (its current active one; see useReferralOrders.ts). That is not the
 * client's full order history, but it is the same notion of "the order for
 * this referral" the rest of the screen already works with, so a referral
 * contributes at most one candidate here, exactly as it does everywhere
 * else `orderFor()` is read. If a real case ever needs the FULL history per
 * referral (multiple past paid orders on one referral), that is a follow-up
 * requiring a human decision, not something to solve speculatively here.
 */
function mostRecentPaidReferralId(client: ClientItem): number | null {
  let bestReferralId: number | null = null
  let bestTime = -Infinity
  for (const r of client.referrals) {
    const order = orderFor(r.id)
    if (!order || order.status !== 'paid') continue
    const paidTime = order.paid_at ? new Date(order.paid_at).getTime() : -Infinity
    if (bestReferralId === null || paidTime >= bestTime) {
      bestTime = paidTime
      bestReferralId = r.id
    }
  }
  return bestReferralId
}

async function updateStatus(statusKey: string) {
  if (!selectedClientId.value) return
  updatingStatus.value = true
  errorMessage.value = ''
  try {
    await api.put(`/clients/${selectedClientId.value}`, { status: statusKey })
    await refreshSelectedClient()
    toast.success('อัปเดตสถานะแล้ว')
  } catch (e) {
    reportDrawerError(e, 'เปลี่ยนสถานะไม่สำเร็จ')
  } finally {
    updatingStatus.value = false
  }
}

// TASK-056 Sprint P4 — assign a client to a category from the drawer.
// Empty string = clear the category (client_category_id: null).
const updatingCategory = ref(false)
async function updateCategory(categoryIdValue: string) {
  if (!selectedClientId.value) return
  updatingCategory.value = true
  errorMessage.value = ''
  try {
    await api.put(`/clients/${selectedClientId.value}`, {
      client_category_id: categoryIdValue ? Number(categoryIdValue) : null,
    })
    await refreshSelectedClient()
    await loadClients()
    toast.success('อัปเดตประเภทลูกค้าแล้ว')
  } catch (e) {
    reportDrawerError(e, 'เปลี่ยนประเภทลูกค้าไม่สำเร็จ')
  } finally {
    updatingCategory.value = false
  }
}

function statusBadgeClasses(statusKey: string): string {
  switch (statusKey) {
    case 'interested':
      return 'bg-surface-success text-ink-success'
    case 'not_interested':
      return 'bg-surface-danger text-ink-danger'
    case 'contacted':
      return 'bg-brand-50 text-brand-700'
    default:
      return 'bg-surface-chip text-ink-card-muted'
  }
}

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

async function uploadFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !selectedClientId.value) return
  uploading.value = true
  errorMessage.value = ''
  try {
    const formData = new FormData()
    formData.append('file', file)
    await api.postForm(`/clients/${selectedClientId.value}/documents`, formData)
    await openClient(selectedClient.value as ClientItem)
    toast.success('อัปโหลดเอกสารแล้ว')
  } catch (e) {
    reportDrawerError(e, 'อัปโหลดไม่สำเร็จ')
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

// Downloads go through the authenticated API endpoint only — never a
// raw/public file URL (Section 5 rule 6). api.download() must attach
// the same session credentials as every other request.
async function downloadDocument(doc: ClientDocumentItem) {
  try {
    await api.download(`/client-documents/${doc.id}/download`, doc.original_filename)
  } catch (e) {
    reportDrawerError(e, 'ดาวน์โหลดไม่สำเร็จ')
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/**
 * TASK-105 (human: "frontend ตรง head ปรับชื่อให้ตรงกับ setup จากระบบ").
 *
 * The page title is the SAME configured label as the bottom-nav tab that
 * opens this screen. Hardcoding it meant a company that renamed the tab
 * still landed on a page announcing the platform's own name for it.
 * Fallbacks match BottomNav.vue exactly — if the two drifted, an unset
 * tenant would see the mismatch this task exists to remove.
 */
const pageTitle = computed(() => theme.label('nav_clients', 'ลูกค้า'))
const pageIcon = computed(() => theme.icon('nav_clients', 'users'))

/**
 * TASK-169 Phase 3 — the client drawer is a `fixed inset-0` overlay, so it
 * would hang over the board unrelated to anything on it. Watched rather than
 * done inside setViewMode(), because the mode also changes on browser
 * back/forward, which never goes through that function.
 *
 * Declared last on purpose: closeDrawer() and the drawer state it resets are
 * defined further up.
 */
watch(isListMode, (listMode) => {
  if (!listMode) closeDrawer()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      :icon="pageIcon"
      :title="pageTitle"
      subtitle="รายชื่อลูกค้าที่คุณดูแล"
      description="เห็นเฉพาะลูกค้าที่คุณเป็นผู้แนะนำเท่านั้น (ตามสิทธิ์ Agent)"
      :kpis="kpis"
      accent-color="brand"
      storage-key="clients"
    >
      <!-- TASK-087 — navigation-bar action per Apple HIG; see NavBarAction.vue. -->
      <!-- List mode only: "เพิ่มลูกค้าใหม่" creates a PERSON, and the
           pipeline mode is a board of DEALS. A create-client button floating
           over it would be an action with no relationship to what is on
           screen. -->
      <template #actions>
        <NavBarAction icon="user_plus" label="เพิ่มลูกค้าใหม่" @click="startCreateClient" />
      </template>

      <!-- TASK-056 Sprint P4 — search + category filter.
           TASK-085 (2026-08-03, human-reported: the chip row ran off the
           screen edge, and the whole header had to fit in 20% of a phone
           screen). The chips were a second 60px row that could never fit
           an open-ended BR-7 category list on a 390px phone; they are now
           one 44px trigger sharing the search row, opening a FilterSheet.
           Net saving: ~60px, i.e. the difference between busting the 20%
           budget and sitting inside it. -->
      <template #tabs>
        <!-- TASK-169 Phase 3 — the view-mode switch. Deliberately the SAME
             TabFilterBar every other screen switches views with, rather than
             a new control invented for this one screen; two tabs put it in
             the segmented layout, so it never scrolls or clips at 375px.
             `model-value` + an explicit handler instead of `v-model`: the
             mode is not a local ref to be written, it is the URL (see the
             file header). -->
        <div class="px-4">
          <TabFilterBar
            :model-value="viewMode"
            :tabs="VIEW_MODE_TABS"
            accent-color="brand"
            @update:model-value="setViewMode"
          />
        </div>
        <!-- Client search + category filter are list-mode controls; the
             board has its own stage filter. -->
        <div v-if="isListMode" class="px-4 py-3 flex items-center gap-2">
          <div class="relative flex-1 min-w-0">
            <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              v-model="searchQuery"
              type="text"
              placeholder="ค้นหาชื่อ, เบอร์โทร, อีเมล..."
              class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full min-h-[44px] pl-9 pr-3 py-2 rounded-lg border border-line-input text-base focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <!-- The dot is the only thing telling the agent a filter is
               active now that the selected chip is no longer on screen —
               without it, a filtered list looks like an empty one. -->
          <button
            v-if="categories.length"
            type="button"
            @click="filterSheetOpen = true"
            class="relative shrink-0 w-11 h-11 flex items-center justify-center rounded-lg border transition-colors active:scale-95"
            :class="selectedCategoryId !== null
              ? 'border-brand-600 bg-brand-50 text-brand-700'
              : 'border-line-card text-ink-card-muted'"
            :aria-label="selectedCategoryLabel ? `ตัวกรอง: ${selectedCategoryLabel}` : 'ตัวกรอง'"
          >
            <Icon name="filter" :size="18" />
            <span
              v-if="selectedCategoryId !== null"
              class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-brand-600 border-2 border-white"
            ></span>
          </button>
        </div>
      </template>
    </HeroHeader>

    <!-- TASK-079 Phase 2 (UX audit): dead-end error banner — retry re-runs
         the client fetch with the current search/category filter intact. -->
    <div v-if="errorMessage && isListMode" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadClients"
      >
        ลองใหม่
      </button>
    </div>

    <!-- Create form. `showCreateForm` is NOT reset when the mode changes, so
         a half-typed client survives a look at the board and back. -->
    <div v-if="showCreateForm && isListMode" class="mt-4 bg-surface-card/95 border border-line-card rounded-xl p-4 space-y-3">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-bold text-ink-card-muted block mb-1">ชื่อลูกค้า <span class="text-ink-danger">*</span></label>
          <input
            ref="nameInputEl"
            v-model="createForm.name"
            placeholder="ชื่อลูกค้า"
            class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border text-sm"
            :class="nameError ? 'border-rose-400' : 'border-line-input'"
            @input="nameError = ''"
          />
          <p v-if="nameError" class="text-xs text-ink-danger mt-1">{{ nameError }}</p>
        </div>
        <div>
          <label class="text-xs font-bold text-ink-card-muted block mb-1">เบอร์โทร <span class="text-ink-danger">*</span></label>
          <input
            ref="phoneInputEl"
            v-model="createForm.phone"
            placeholder="เบอร์โทร"
            class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border text-sm"
            :class="phoneError ? 'border-rose-400' : 'border-line-input'"
            @input="phoneError = ''"
          />
          <p v-if="phoneError" class="text-xs text-ink-danger mt-1">{{ phoneError }}</p>
        </div>
        <input v-model="createForm.email" placeholder="อีเมล (ถ้ามี)" class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder px-3 py-2 rounded-lg border border-line-input text-sm" />
        <!-- TASK-049 — national ID (PDPA §6). Optional; server validates
             the Thai 13-digit checksum and encrypts it at rest. -->
        <input
          v-model="createForm.national_id"
          inputmode="numeric"
          maxlength="13"
          placeholder="เลขบัตรประชาชน (ถ้ามี, 13 หลัก)"
          class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder px-3 py-2 rounded-lg border border-line-input text-sm"
        />
        <input
          v-model="createForm.lead_source"
          list="lead-source-suggestions"
          placeholder="ที่มาของลูกค้า (ถ้ามี) เช่น Facebook, Walk-in"
          class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder px-3 py-2 rounded-lg border border-line-input text-sm"
        />
        <datalist id="lead-source-suggestions">
          <option v-for="s in leadSourceSuggestions" :key="s" :value="s" />
        </datalist>
        <!-- TASK-014 demographic fields (all optional) -->
        <div>
          <label class="text-xs font-bold text-ink-card-muted block mb-1">วันเกิด (ถ้ามี)</label>
          <BuddhistDateInput v-model="createForm.date_of_birth" :years-back="100" :years-forward="0" />
        </div>
        <AppSelect
          v-model="createForm.province"
          :options="provinceOptions"
          placeholder="จังหวัด (ถ้ามี)"
          title="จังหวัด"
          aria-label="จังหวัด"
        />
        <input v-model="createForm.occupation" placeholder="อาชีพ (ถ้ามี)" class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder px-3 py-2 rounded-lg border border-line-input text-sm" />
        <label class="flex items-center gap-2 text-sm text-ink-card-muted">
          <input v-model="createForm.consent" type="checkbox" />
          ลูกค้าให้ความยินยอม (PDPA)
        </label>
      </div>
      <textarea
        v-model="createForm.address"
        placeholder="ที่อยู่ (ถ้ามี)"
        rows="2"
        class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border border-line-input text-sm"
      />
      <textarea
        v-model="createForm.health_notes"
        placeholder="บันทึกข้อมูลสุขภาพ (ถ้ามี) — จัดเก็บแบบเข้ารหัส"
        rows="2"
        class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border border-line-input text-sm"
      />
      <div class="flex gap-2">
        <AppButton :loading="creating" @click="submitCreate">บันทึกลูกค้า</AppButton>
        <AppButton variant="ghost" @click="showCreateForm = false; nameError = ''; phoneError = ''">ยกเลิก</AppButton>
      </div>
    </div>

    <!-- Initial load only — subsequent reloads update the list below in place -->
    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <!-- ══ MODE: กระบวนการขาย ══
         The /pipeline board itself (PipelineBoard.vue), not a copy of it.
         Mounted only in this mode, so list mode never pays for GET
         /referrals. Everything ADR-026 — per-referral journeys, the union
         stage axis, per-template advance — lives in that component and must
         stay there. -->
    <PipelineBoard v-if="!isListMode" class="mt-4" @kpis-change="pipelineKpis = $event" />

    <!-- ══ MODE: รายชื่อลูกค้า (default) ══ -->
    <Transition v-else name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
      <div v-else>
        <!-- List -->
        <EmptyState v-if="!clients.length" icon="users" title="ยังไม่มีลูกค้าที่คุณแนะนำ" class="mt-4" />
        <!-- TASK-082 (UX audit): each client used to be its own floating
             card, which is the wrong primitive — a client roster is
             homogeneous, comparable content, and Material says list (never
             cards) when the user has to scan comparable items to find one.
             Rows are flat and butt together inside one <AppList> per status
             group, so `space-y-2` is gone and the spacing between groups
             comes from AppListGroupHeader's own padding. Grouping by status
             is also what makes this screen a different SHAPE from the other
             four lists — per-page accent colours were explicitly rejected
             (2026-08-03), so structure has to carry the differentiation. -->
        <div v-else class="mt-4">
          <template v-for="group in groupedClients" :key="group.key">
            <AppListGroupHeader :label="group.label" :count="group.items.length" />
            <AppList>
              <!-- No `tag` on TransitionGroup: it renders as a fragment, so
                   the rows stay DIRECT children of AppList — which is what
                   its `[&>*:last-child]:border-b-0` rule needs to match. -->
              <TransitionGroup name="list-fade">
                <!-- TASK-079 Phase 3 (UX audit): `hover:shadow-sm` is invisible on a
                     touchscreen, so tapping a client row gave no feedback at all
                     and agents tapped twice. `active:` is the touch equivalent.
                     TASK-082: on a flat row `interactive` is a background tint
                     instead of a scale — scaling a full-bleed row looks broken
                     against the rows it now touches. -->
                <AppCard
                  v-for="c in group.items"
                  :key="c.id"
                  variant="flat"
                  interactive
                  class="flex flex-col gap-2"
                  @click="openClient(c)"
                >
                  <!-- Flex-squeeze bug fix (2026-08-03, human-reported at 768px on
                       the Referrals screen: the client name wrapped to ONE
                       CHARACTER PER LINE — same root cause here). The text column
                       carried `min-w-0` but no `flex-1`, so it resolved to
                       `flex: 0 1 auto` and collapsed to min-content, while the
                       right-hand column (a `whitespace-nowrap` status chip over a
                       consent line) refused to yield width. `flex-1 min-w-0` on
                       the text side fixes the ratio; the chip column already had
                       `shrink-0`. Stacking below `sm` as well, mobile-first: at
                       375px "ให้ความยินยอมแล้ว" beside a status chip leaves too
                       little room for a name on the same line. -->
                  <!-- TASK-081 (typography audit): 93% of this app's text sat at
                       11-14px, so every row read as one flat grey block with no
                       hero value. The client name is what the agent scans this
                       list for — promoted to text-lg; phone/email/category are
                       demoted to supporting metadata. Unchanged by TASK-082 —
                       only the surface around it became flat. -->
                  <div class="flex items-start gap-3 min-w-0 flex-1">
                    <Icon name="user" :size="18" class="text-ink-brand mt-1 shrink-0" />
                    <div class="min-w-0">
                      <div class="flex items-center gap-1">
                        <p class="text-lg font-bold text-ink-card leading-tight">{{ c.name }}</p>
                        <!-- TASK-191 §3.2 — next to the client's name (human's
                             explicit placement instruction), visible only
                             when this client has at least one PAID order.
                             Shares the most-recently-paid one when there is
                             more than one (mostRecentPaidReferralId's own
                             doc comment records the judgment call). `.stop`
                             so tapping it never also opens the drawer
                             underneath. -->
                        <button
                          v-if="mostRecentPaidReferralId(c) !== null"
                          type="button"
                          class="shrink-0 min-h-[44px] min-w-[44px] -my-2.5 inline-flex items-center justify-center text-ink-brand hover:bg-surface-chip rounded-full active:scale-90 transition-transform"
                          title="แชร์ลิงก์ชำระเงิน / ใบเสร็จ"
                          @click.stop="openShareFor(mostRecentPaidReferralId(c)!)"
                        >
                          <Icon name="share" :size="16" />
                        </button>
                      </div>
                      <p class="text-xs text-ink-card-muted mt-0.5">{{ c.phone }}<span v-if="c.email"> · {{ c.email }}</span></p>
                      <span v-if="c.client_category_name" class="inline-block mt-1 text-[11px] font-bold px-1.5 py-0.5 rounded bg-surface-chip text-ink-card-muted">
                        {{ c.client_category_name }}
                      </span>
                    </div>
                  </div>
                  <div class="flex flex-col items-start gap-1 shrink-0 pl-8">
                    <span :class="['text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap', statusBadgeClasses(c.status.key)]">{{ c.status.label }}</span>
                    <span v-if="c.consent_given_at" class="text-xs font-bold text-ink-success flex items-center gap-1">
                      <Icon name="shield_check" :size="14" /> ให้ความยินยอมแล้ว
                    </span>
                    <span v-else class="text-xs font-bold text-ink-warning">ยังไม่ยินยอม</span>
                  </div>
                </AppCard>
              </TransitionGroup>
            </AppList>
          </template>
        </div>
      </div>
    </Transition>

    <!-- Detail drawer — slide-in from the right (see assets/main.css .drawer-*) -->
    <Transition name="drawer">
      <div v-if="selectedClient" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-slate-900/30" @click="closeDrawer" />
        <div class="drawer-panel relative w-full max-w-md bg-surface-card h-full shadow-xl p-5 overflow-y-auto">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-ink-card">{{ selectedClient.name }}</h2>
            <button class="min-h-[44px] min-w-[44px] -mr-2 inline-flex items-center justify-center text-ink-card-subtle hover:text-ink-card-muted active:scale-90 transition-transform" @click="closeDrawer"><Icon name="close" :size="20" /></button>
          </div>

          <div class="space-y-1 text-sm text-ink-card-muted">
            <p><Icon name="phone" :size="14" class="inline mr-1" />{{ selectedClient.phone }}</p>
            <p v-if="selectedClient.email"><Icon name="mail" :size="14" class="inline mr-1" />{{ selectedClient.email }}</p>
            <p v-if="selectedClient.lead_source"><Icon name="cart" :size="14" class="inline mr-1" />ที่มา: {{ selectedClient.lead_source }}</p>
            <!-- TASK-014 demographic fields — read-only (out of scope: no
                 edit-client screen exists yet, per the task spec). -->
            <p v-if="selectedClient.date_of_birth">
              <Icon name="calendar" :size="14" class="inline mr-1" />วันเกิด: {{ new Date(selectedClient.date_of_birth).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' }) }}
            </p>
            <p v-if="selectedClient.province"><Icon name="map_pin" :size="14" class="inline mr-1" />จังหวัด: {{ selectedClient.province }}</p>
            <p v-if="selectedClient.occupation"><Icon name="building" :size="14" class="inline mr-1" />อาชีพ: {{ selectedClient.occupation }}</p>
            <p v-if="selectedClient.address"><Icon name="home" :size="14" class="inline mr-1" />ที่อยู่: {{ selectedClient.address }}</p>
            <p v-if="selectedClient.health_notes" class="mt-3 p-3 rounded-lg bg-surface-chip border border-line-card">
              <span class="font-bold text-ink-card block mb-1 text-xs">บันทึกสุขภาพ (PDPA)</span>
              {{ selectedClient.health_notes }}
            </p>
          </div>

          <div class="mt-3 grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs font-bold text-ink-card-muted">สถานะลูกค้า</label>
              <!-- `:disabled` while the PUT is in flight is preserved
                   verbatim: it is what stops a second status write racing
                   the first. -->
              <AppSelect
                :model-value="selectedClient.status.key"
                :options="statusSelectOptions"
                :disabled="updatingStatus"
                title="สถานะลูกค้า"
                aria-label="สถานะลูกค้า"
                class="mt-1"
                @update:model-value="updateStatus"
              />
            </div>
            <div>
              <label class="text-xs font-bold text-ink-card-muted">ประเภทลูกค้า</label>
              <AppSelect
                :model-value="String(selectedClient.client_category_id ?? '')"
                :options="categorySelectOptions"
                :disabled="updatingCategory"
                title="ประเภทลูกค้า"
                aria-label="ประเภทลูกค้า"
                class="mt-1"
                @update:model-value="updateCategory"
              />
            </div>
          </div>

          <h3 class="mt-5 mb-2 text-sm font-bold text-ink-card flex items-center gap-2">
            <Icon name="cart" :size="16" /> สินค้าที่สนใจ
          </h3>
          <!-- TASK-141's chips are decoration on an otherwise working
               drawer, so this failure is a warning, not the page's danger
               banner — which would render behind the drawer overlay anyway. -->
          <p v-if="ordersError" class="mb-2 px-3 py-2 rounded-lg bg-surface-warning border border-line-card text-xs text-ink-warning">
            {{ ordersError }} — สถานะการชำระเงินบนรายการอาจไม่แสดง
          </p>
          <!--
            An ACTIONABLE empty state (human, 2026-08-12: "หากไม่มีข้อมูล
            สินค้าที่สนใจ Agent สามารถเลือกสินค้าในระบบที่ Active ให้ผู้ใช้ได้").

            It used to be a passive `EmptyState` box, with the only way in
            being a small "+ เพิ่มสินค้าที่สนใจ" link further down the drawer
            — so the screen said "there is nothing here" and did not say what
            to do about it. The catalogue it picks from is the Active one:
            GET /products already returns only `is_active` products to an
            Agent (TASK-156 §3), so there is nothing extra to filter here.

            BR-1 still decides. Without Basic the button is not offered and
            the reason is printed instead — the same gate the link below has,
            not a second one that could drift from it.
          -->
          <div
            v-if="!selectedClient.referrals.length"
            class="flex items-center gap-3 flex-wrap px-4 py-3 rounded-xl border border-dashed border-line-card"
          >
            <Icon name="cart" :size="20" class="text-ink-card-subtle shrink-0" />
            <p class="flex-1 min-w-0 text-xs text-ink-card-muted">ยังไม่มีสินค้าที่สนใจ</p>
            <button
              v-if="hasPassedBasic"
              type="button"
              class="shrink-0 min-h-[44px] px-3 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform"
              @click="showReferralForm = true"
            >
              + เลือกสินค้า
            </button>
            <span v-else class="shrink-0 text-xs text-ink-warning">ต้องผ่านใบรับรอง Basic ก่อน (BR-1)</span>
          </div>
          <!-- TASK-169 Phase 2 — ReferralsView's deal row, verbatim.
               `hide-client` because the drawer is already titled with the
               name (and ClientResource does not send `client` on its nested
               referrals). The product-detail expander keeps its place
               through the row's two slots. -->
          <AppList v-else>
            <ReferralRow
              v-for="r in selectedClient.referrals"
              :key="r.id"
              :referral="r"
              hide-client
              :order="orderFor(r.id)"
              :collecting="collectingId === r.id"
              :collect-disabled="collectingId !== null && collectingId !== r.id"
              :pay-error="payActionError && payActionError.id === r.id ? payActionError.message : null"
              @share="openShareFor(r.id)"
              @collect="collectPayment(r.id)"
              @view-slip="viewSlipFor(r.id)"
            >
              <template #actions-start>
                <button
                  v-if="r.product"
                  type="button"
                  class="min-h-[44px] text-xs font-bold text-ink-brand hover:text-ink-brand active:scale-95 transition-transform flex items-center gap-1 whitespace-nowrap"
                  @click="toggleMaterials(r.product.id)"
                >
                  <Icon name="download" :size="12" />
                  {{ expandedProductId === r.product.id ? 'ซ่อนรายละเอียดสินค้า' : 'ดูรูป/สเปค/สื่อการขาย' }}
                </button>
              </template>

              <template #footer>
                <!-- TASK-026 (rehomed here by TASK-169 Phase 4a, ag-lead §5b
                     item 1). FIRST in the slot, above the product collateral:
                     this is the BR-4 money control, and burying it under a
                     media gallery that may be several screens tall is how a
                     control gets "lost" without anyone deleting it.

                     Its own component, so the request it sends is byte-for-
                     byte the one ReferralsView sends — the deleted screen
                     cannot take a divergent copy of the split logic with it.
                     Deliberately NOT in `actions-start`: see the component's
                     header for why a fifth control in that row is the wrong
                     answer at 375px. -->
                <!-- TASK-174 — not rendered at all while this company's split
                     is switched off. Not disabled, not empty: the whole
                     control is gone, because a co-agent picker that the API
                     refuses to accept is a control that can only produce a
                     403. `splitEnabled` is the server's own answer (see its
                     declaration) — this v-if is a reflection of it, not a
                     second copy of the rule. -->
                <CoAgentEditor
                  v-if="splitEnabled"
                  :referral="r"
                  :options="coAgentOptions"
                  @saved="onCoAgentSaved"
                  @error="reportDrawerMessage"
                />
                <div v-if="r.product && expandedProductId === r.product.id" class="mt-2 pt-2 border-t border-line-card-subtle space-y-3">
                  <!-- Product media gallery (ADR-007) -->
                  <div>
                    <p v-if="loadingMediaFor === r.product.id" class="text-xs text-ink-card-subtle">กำลังโหลดรูป/วิดีโอ...</p>
                    <div v-else-if="mediaByProduct[r.product.id]?.length" class="grid grid-cols-4 gap-1.5">
                      <div v-for="m in mediaByProduct[r.product.id]" :key="m.id" class="relative rounded-lg overflow-hidden border border-line-card">
                        <AuthenticatedMedia
                          v-if="m.source_type !== 'embed'"
                          :src="m.media_type === 'image' ? m.stream_url : m.thumbnail_url ?? m.stream_url"
                          type="image"
                          class="w-full h-14 object-cover"
                        />
                        <a v-else :href="m.embed_url ?? '#'" target="_blank" rel="noopener" class="flex items-center justify-center h-14 bg-surface-chip text-ink-card-subtle">
                          <Icon name="link" :size="16" />
                        </a>
                        <span v-if="m.is_primary" class="absolute top-0.5 right-0.5 bg-amber-500 text-white rounded p-0.5">
                          <Icon name="star" :size="9" />
                        </span>
                      </div>
                    </div>
                  </div>
  
                  <!-- Product specs (ADR-007, BR-7) -->
                  <div v-if="specsByProduct[r.product.id]?.length" class="space-y-0.5">
                    <p v-for="s in specsByProduct[r.product.id]" :key="s.id" class="text-xs">
                      <span v-if="s.spec_group" class="text-ink-card-subtle">{{ s.spec_group }} · </span>
                      <span class="font-bold text-ink-card-muted">{{ s.spec_key }}:</span>
                      <span class="text-ink-card-muted">{{ s.spec_value }}</span>
                    </p>
                  </div>
  
                  <!-- Sales materials + external share link -->
                  <div>
                    <p v-if="loadingMaterialsFor === r.product.id" class="text-xs text-ink-card-subtle">กำลังโหลดสื่อการขาย...</p>
                    <p v-else-if="!materialsByProduct[r.product.id]?.length" class="text-xs text-ink-card-subtle">ยังไม่มีสื่อการขายสำหรับสินค้านี้</p>
                    <div v-else class="space-y-1.5">
                      <div v-for="m in materialsByProduct[r.product.id]" :key="m.id" class="rounded-lg border border-line-card-subtle">
                        <div class="flex items-center justify-between gap-2 p-1.5">
                          <span v-if="m.source_type === 'embed'" class="truncate text-xs text-ink-brand">{{ m.embed_url }}</span>
                          <span v-else class="truncate text-xs">{{ m.original_filename }}</span>
                          <div class="flex items-center gap-2 shrink-0">
                            <button v-if="m.source_type !== 'embed'" class="text-ink-brand hover:text-ink-brand" title="ดาวน์โหลด" @click="downloadMaterial(m)">
                              <Icon name="download" :size="14" />
                            </button>
                            <button class="text-ink-card-subtle hover:text-ink-brand" title="สร้างลิงก์แชร์ให้ลูกค้า" @click="toggleShareLinks(m.id)">
                              <Icon name="share" :size="14" />
                            </button>
                          </div>
                        </div>
                        <div v-if="expandedShareLinksMaterialId === m.id" class="px-1.5 pb-1.5 border-t border-line-card-subtle pt-1.5">
                          <p v-if="loadingShareLinksFor === m.id" class="text-xs text-ink-card-subtle">กำลังโหลด...</p>
                          <template v-else>
                            <div v-if="shareLinksByMaterial[m.id]?.length" class="space-y-1 mb-1.5">
                              <div v-for="link in shareLinksByMaterial[m.id]" :key="link.id" class="flex items-center justify-between gap-2 text-[11px]">
                                <span :class="isLinkUsable(link) ? 'text-ink-card-muted' : 'text-ink-card-subtle line-through'" class="truncate">{{ link.share_url }}</span>
                                <div class="flex items-center gap-1 shrink-0">
                                  <button v-if="isLinkUsable(link)" class="text-ink-brand" title="คัดลอกลิงก์" @click="copyShareLink(link)">
                                    <Icon :name="copiedShareLinkId === link.id ? 'check' : 'copy'" :size="12" />
                                  </button>
                                  <button v-if="isLinkUsable(link)" class="text-ink-danger" title="ยกเลิกลิงก์" @click="askRevokeShareLink(m.id, link.id)">
                                    <Icon name="x" :size="12" />
                                  </button>
                                </div>
                              </div>
                            </div>
                          </template>
                          <div class="flex items-center gap-1">
                            <input v-model.number="shareLinkExpiryDays" type="number" min="1" max="90" class="bg-surface-input text-ink-input w-12 px-1 py-0.5 rounded border border-line-input text-[11px]" />
                            <span class="text-[11px] text-ink-card-subtle">วัน</span>
                            <button
                              class="px-2 py-0.5 rounded bg-brand-600 text-ink-primary text-[11px] font-bold disabled:opacity-50"
                              :disabled="creatingShareLinkFor === m.id"
                              @click="createShareLink(m.id)"
                            >
                              + สร้างลิงก์แชร์
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </ReferralRow>
          </AppList>

          <button
            v-if="hasPassedBasic"
            type="button"
            class="mt-2 min-h-[44px] inline-flex items-center text-xs font-bold text-ink-brand hover:text-ink-brand active:scale-95 transition-transform"
            @click="showReferralForm = !showReferralForm"
          >
            + เพิ่มสินค้าที่สนใจ
          </button>
          <p v-else class="mt-2 text-xs text-ink-warning">ต้องผ่านใบรับรอง Basic ก่อนจึงจะเพิ่มสินค้าที่สนใจได้ (BR-1)</p>

          <div v-if="showReferralForm" class="mt-2 p-3 rounded-lg border border-dashed border-line-card space-y-2">
            <!-- An empty picker used to be indistinguishable from a broken
                 one. Say which it is; see loadDrawerOptions(). -->
            <p v-if="drawerOptionsError" class="text-xs text-ink-danger">
              {{ drawerOptionsError }} — ลองรีเฟรชหน้า
            </p>
            <p v-else-if="!productSelectOptions.length" class="text-xs text-ink-warning">
              ยังไม่มีสินค้าให้เลือก — ตรวจสอบที่ Admin ว่าสินค้าถูกเปิดใช้งานแล้วหรือยัง
            </p>
            <!-- TASK-211 — mark what is required and what is not BEFORE the
                 agent fills the form, not after they press a button that
                 does nothing. สาขา became optional on the human's ruling
                 (2026-08-19); StoreReferralRequest was relaxed to match, so
                 the two sides cannot disagree. -->
            <div>
              <p class="mb-1 text-xs font-bold text-ink-card-muted">สินค้า <span class="text-ink-danger">*</span></p>
              <AppSelect
                v-model="referralForm.product_id"
                :options="productSelectOptions"
                placeholder="เลือกสินค้า"
                title="เลือกสินค้า"
                aria-label="เลือกสินค้า"
              />
            </div>
            <div>
              <label for="referral-branch" class="mb-1 block text-xs font-bold text-ink-card-muted">
                สาขา <span class="font-normal">(ไม่บังคับ)</span>
              </label>
              <input
                id="referral-branch"
                v-model="referralForm.branch"
                placeholder="เช่น สาขาสีลม"
                class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border border-line-input text-sm"
              />
            </div>
            <div>
              <p class="mb-1 text-xs font-bold text-ink-card-muted">เวลาที่สะดวกนัด <span class="font-normal">(ไม่บังคับ)</span></p>
              <BuddhistDateInput v-model="referralForm.preferred_time" type="datetime-local" />
            </div>
            <p v-if="referralFormError" class="text-xs font-bold text-ink-danger" role="alert">
              {{ referralFormError }}
            </p>
            <div class="flex gap-2">
              <!-- Only `creatingReferral` disables this now. A press with a
                   field missing has to REACH submitReferral() — that is what
                   produces the message above. -->
              <button
                :disabled="creatingReferral"
                class="min-h-[44px] px-3 py-1.5 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center justify-center"
                @click="submitReferral"
              >
                {{ creatingReferral ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
              <button class="min-h-[44px] px-3 py-1.5 rounded-lg text-ink-card-muted text-xs font-bold active:scale-95 transition-transform inline-flex items-center justify-center" @click="showReferralForm = false; referralFormError = ''">ยกเลิก</button>
            </div>
          </div>

          <!-- TASK-015: Client Activity/Communication Log -->
          <h3 class="mt-5 mb-2 text-sm font-bold text-ink-card flex items-center gap-2">
            <Icon name="chat" :size="16" /> ประวัติการติดต่อ
          </h3>
          <p v-if="loadingActivities" class="text-xs text-ink-card-subtle">กำลังโหลด...</p>
          <EmptyState v-else-if="!activities.length" icon="chat" title="ยังไม่มีประวัติการติดต่อ" />
          <div v-else class="space-y-2">
            <div v-for="a in activities" :key="a.id" class="p-3 rounded-lg border border-line-card text-sm">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-lg">{{ a.type.label }}</span>
                  <span class="text-xs text-ink-card-subtle ml-2">{{ formatDateTime(a.occurred_at) }} · {{ a.logged_by_name }}</span>
                </div>
                <div v-if="a.can_edit || a.can_delete" class="flex items-center gap-2 shrink-0">
                  <button v-if="a.can_edit && editingActivityId !== a.id" class="text-ink-card-subtle hover:text-ink-brand" title="แก้ไข" @click="startEditActivity(a)">
                    <Icon name="pencil" :size="14" />
                  </button>
                  <button v-if="a.can_delete" class="text-ink-card-subtle hover:text-ink-danger" title="ลบ" @click="askDeleteActivity(a.id)">
                    <Icon name="trash" :size="14" />
                  </button>
                </div>
              </div>
              <div v-if="editingActivityId === a.id" class="mt-2 space-y-2">
                <textarea v-model="editingSummary" rows="2" class="bg-surface-input text-ink-input w-full px-3 py-2 rounded-lg border border-line-input text-sm" />
                <div class="flex gap-2">
                  <button
                    :disabled="savingActivityEdit || !editingSummary"
                    class="min-h-[44px] px-3 py-1.5 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center justify-center"
                    @click="saveActivityEdit(a.id)"
                  >
                    {{ savingActivityEdit ? 'กำลังบันทึก...' : 'บันทึก' }}
                  </button>
                  <button class="min-h-[44px] px-3 py-1.5 rounded-lg text-ink-card-muted text-xs font-bold active:scale-95 transition-transform inline-flex items-center justify-center" @click="cancelEditActivity">ยกเลิก</button>
                </div>
              </div>
              <p v-else class="mt-1 text-ink-card">{{ a.summary }}</p>
              <p v-if="a.follow_up_at" class="mt-1 text-xs text-ink-warning">ติดตามอีกครั้ง: {{ formatDateTime(a.follow_up_at) }}</p>
            </div>
          </div>

          <button
            type="button"
            class="mt-2 min-h-[44px] inline-flex items-center text-xs font-bold text-ink-brand hover:text-ink-brand active:scale-95 transition-transform"
            @click="showActivityForm = !showActivityForm"
          >
            + บันทึกการติดต่อ
          </button>

          <div v-if="showActivityForm" class="mt-2 p-3 rounded-lg border border-dashed border-line-card space-y-2">
            <AppSelect
              v-model="activityForm.type"
              :options="activityTypeOptions"
              title="ประเภทการติดต่อ"
              aria-label="ประเภทการติดต่อ"
            />
            <textarea v-model="activityForm.summary" placeholder="สรุปการติดต่อ" rows="2" class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-lg border border-line-input text-sm" />
            <div>
              <label class="text-xs font-bold text-ink-card-muted block mb-1">นัดติดตามอีกครั้ง (ถ้ามี)</label>
              <BuddhistDateInput v-model="activityForm.follow_up_at" type="datetime-local" />
            </div>
            <div class="flex gap-2">
              <button
                :disabled="creatingActivity || !activityForm.summary"
                class="min-h-[44px] px-3 py-1.5 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition-transform disabled:opacity-50 inline-flex items-center justify-center"
                @click="submitActivity"
              >
                {{ creatingActivity ? 'กำลังบันทึก...' : 'บันทึก' }}
              </button>
              <button class="min-h-[44px] px-3 py-1.5 rounded-lg text-ink-card-muted text-xs font-bold active:scale-95 transition-transform inline-flex items-center justify-center" @click="showActivityForm = false">ยกเลิก</button>
            </div>
          </div>

          <h3 class="mt-5 mb-2 text-sm font-bold text-ink-card flex items-center gap-2">
            <Icon name="document" :size="16" /> เอกสารแนบ
          </h3>

          <EmptyState v-if="!loadingDocuments && !documents.length" icon="document" title="ยังไม่มีเอกสาร" />
          <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
            <div v-for="d in documents" :key="d.id" class="flex items-center justify-between p-2 rounded-lg border border-line-card text-sm">
              <div class="truncate">
                <p class="font-bold text-ink-card truncate">{{ d.original_filename }}</p>
                <p class="text-xs text-ink-card-subtle">{{ formatSize(d.size_bytes) }}</p>
              </div>
              <button class="text-ink-brand hover:text-ink-brand shrink-0 ml-2" title="ดาวน์โหลด" @click="downloadDocument(d)">
                <Icon name="download" :size="16" />
              </button>
            </div>
          </TransitionGroup>

          <label class="mt-4 flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-dashed border-line-card text-sm text-ink-card-muted cursor-pointer hover:border-brand-400 hover:text-ink-brand">
            <Icon name="upload" :size="16" />
            {{ uploading ? 'กำลังอัปโหลด...' : 'อัปโหลดเอกสาร (pdf, jpg, png · สูงสุด 10MB)' }}
            <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" :disabled="uploading" @change="uploadFile" />
          </label>
        </div>
      </div>
    </Transition>

    <!-- TASK-079 Phase 2 (UX audit) — confirmations for the two destructive
         actions that had none. BOTH must stay INSIDE <main>: a sibling of
         the root turns this view into a multi-root Fragment, which breaks
         App.vue's <Transition mode="out-in"> (regression previously fixed
         across 8 views — see AnnouncementsView.vue). -->
    <ConfirmDialog
      v-model:show="showDeleteActivityConfirm"
      title="ยืนยันการลบประวัติการติดต่อ"
      body="รายการนี้จะถูกลบถาวร และกู้คืนไม่ได้"
      variant="danger"
      :busy="deletingActivity"
      @confirm="confirmDeleteActivity"
    />
    <ConfirmDialog
      v-model:show="showRevokeLinkConfirm"
      title="ยืนยันการยกเลิกลิงก์แชร์"
      body="ลูกค้าที่ได้รับลิงก์นี้ไปแล้วจะเปิดดูไม่ได้อีก"
      variant="danger"
      :busy="revokingLink"
      @confirm="confirmRevokeShareLink"
    />

    <!-- TASK-141 (here since TASK-169 Phase 2) — the share sheet
         "เก็บเงินเลย" opens. Same rule as the dialogs above: it MUST stay
         INSIDE <main>. Also outside the drawer's own <Transition>, so
         closing the drawer never takes the sheet with it. -->
    <!-- TASK-212 — email-* props are what let the sheet send through the
         platform SMTP instead of handing off to a mail client. -->
    <ShareLinkModal
      v-model:show="showShareModal"
      :url="shareUrl"
      :heading="shareHeading"
      email-type="order"
      :email-target-id="shareOrderId"
      :default-email="shareDefaultEmail"
    />

    <!-- TASK-085 — category filter, moved out of the header row. -->
    <FilterSheet
      v-model:open="filterSheetOpen"
      title="หมวดหมู่ลูกค้า"
      :options="categoryFilterOptions"
      :selected="selectedCategoryId"
      @select="onCategoryFilterSelect"
    />
  </main>
</template>
