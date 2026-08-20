<script setup lang="ts">
/**
 * ThemeSettingsView (Admin app) — "ตั้งค่าธีม / แบรนด์" (TASK-055 Phase 3,
 * ADR-018). Company Admins white-label their own company's AGENT PORTAL
 * (frontend) — colors, font, background, logos, loading screen, and a
 * curated set of label overrides. The Admin app itself is NEVER re-themed
 * by this screen (ADR-018 decision 4); the live preview is a self-contained
 * mock driven purely by reactive form state (inline styles), so nothing
 * here touches the Admin app's own :root vars / Tailwind config.
 *
 * Data flow (matches the rest of the app — every response is `{ data }`-
 * wrapped, unwrapped at the call site):
 *  - Company Admin: GET /me/theme → their own company's theme.
 *  - Super Admin: pick a company (GET /companies), load via
 *    GET /public/theme/{slug}, and send company_id on every write.
 *  - Save presentational fields: PUT /company-theme (never logo paths).
 *  - Logos/background image: POST /company-theme/asset (multipart, per slot),
 *    saved immediately; the fresh Theme comes back in the response.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 / ADR-038 — one company scope for the whole app.
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { api, ApiError } from '@/api/client'
import { compressImage } from '@/utils/imageCompression'
import { generateQrDataUrl } from '@/utils/qrCode'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import IconPicker from '@/design-system/components/IconPicker.vue'
import GradientPicker from '@/design-system/components/GradientPicker.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import { FONT_CATALOG, CATEGORY_LABELS, type FontCategory } from '@/data/fontCatalog'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// ── Types (presentational only — mirrors ThemeResource, ADR-018) ──────────
type BackgroundType = 'solid' | 'gradient' | 'image'
// TASK-161 §3.1 — the nav bar gets its own solid/gradient switch. No
// 'image' member on purpose: only two surfaces gained a gradient (app
// background + nav bar) and only the app background takes an image.
type NavBgType = 'solid' | 'gradient'
type AssetSlot = 'nav' | 'login' | 'favicon' | 'loading' | 'background'

interface Theme {
  company: { name: string; slug: string }
  // TASK-063 — branded /login?company=<slug> link for this company, built
  // server-side by ThemeResource (never hardcoded here — mirrors the
  // ProductShareLinkResource pattern). null only if the company somehow
  // has no slug yet.
  login_link: string | null
  primary_hex: string | null
  accent_hex: string | null
  nav_bg_hex: string | null
  /*
   * TASK-161 §3.1 — nav-bar solid/gradient switch. `nav_bg_hex` above stays
   * the SOLID value and is untouched; these two are the new nullable
   * columns beside it (null/absent ⇒ solid, so every pre-TASK-161 row keeps
   * rendering exactly as before with no data migration).
   *
   * Optional (`?`) for the same reason `recommended_slot_count` below is:
   * as of this screen's build ThemeResource does not expose these two keys
   * yet (ag-dev owns §3 of the same task, in parallel), so they read as
   * `undefined` until it does. Every read here defaults, and the save
   * payload sends both regardless — Laravel's validated() drops keys
   * UpdateThemeRequest doesn't accept, so this no-ops rather than 422s in
   * the interim (identical precedent, TASK-069).
   *
   * NOTE the key naming: `nav_bg_config` is `{ color1, color2, angle }` per
   * §3.1, while the app background's `background_config` is
   * `{ from, to, angle }`. The spec calls the nav shape a "mirror" of the
   * background's — it mirrors the TYPE/CONFIG column pair, not the inner
   * key names. Flagged to ag-lead; coded to §3.1 as written.
   */
  nav_bg_type?: NavBgType | null
  nav_bg_config?: Record<string, unknown> | null
  nav_text_hex: string | null
  nav_active_hex: string | null
  card_bg_hex: string | null
  card_text_hex: string | null
  card_border_hex: string | null
  card_shadow: string | null
  background: { type: BackgroundType | null; config: Record<string, unknown> | null; image_url: string | null }
  font_family: string | null
  font_family_thai: string | null
  font_family_latin: string | null
  font_weights: number[] | null
  logos: { nav_url: string | null; login_url: string | null; favicon_url: string | null; loading_url: string | null }
  loading: { bg_hex: string | null; message: string | null }
  label_overrides: Record<string, string>
  // TASK-057 — key => Icon.vue icon-name map for the bottom-nav (BR-7).
  nav_icon_overrides: Record<string, string>
  // TASK-069 / ADR-020 — "จำนวนสินค้าแนะนำ" (recommended-row slot count,
  // BR-7). The `company_theme_settings.recommended_slot_count` column and
  // its consumer (ProductRecommendationService) already exist (TASK-068),
  // but as of this screen's build, ThemeResource does NOT yet expose this
  // field and UpdateThemeRequest does NOT yet accept it — so this key will
  // read as `undefined` and a save will silently NOT persist a new value
  // until ag-dev adds it to both (2 small additions, same shape as every
  // other plain-integer field already on this Resource/Request). Flagged
  // to ag-lead in this task's write-up; kept here (rather than left out
  // entirely) so the UI needs no further change once that lands.
  recommended_slot_count?: number | null
}

// Neutral fallbacks used ONLY to give the native color inputs a value while
// the field is "unset" (null). Saving a null clears back to the backend
// default; these hexes are never persisted unless the admin actually edits.
const DEFAULTS = { primary: '#1e3a8a', accent: '#f59e0b', loadingBg: '#0f172a', navBg: '#ffffff', navText: '#334155', card: '#ffffff', cardText: '#0f172a' }

// Curated label keys the Agent Portal's t(key, default) helper reads.
/*
 * TASK-105 (human: "frontend ตรง head ปรับชื่อให้ตรงกับ setup จากระบบ").
 *
 * These labels now drive BOTH the Agent Portal's bottom-nav tab AND the
 * page header of the screen that tab opens, so a company that renames
 * "ขาย" does not end up with a menu saying one thing and the page it
 * opens saying another.
 *
 * Two corrections came out of that:
 *  - `nav_sales` was MISSING here entirely. BottomNav.vue has read
 *    `theme.label('nav_sales', …)` since TASK-053, so the sales tab was
 *    the one tab an admin could not rename — a silent gap, because the
 *    tab rendered fine on its fallback.
 *  - The placeholders claimed defaults the app does not use
 *    ('หน้าแรก' vs the real 'หน้าหลัก', 'คอมมิชชั่น' vs 'ค่าคอม'). A
 *    placeholder that lies about the default is worse than none: it tells
 *    the admin the field is already set to something it isn't.
 */
/*
 * TASK-169 Phase 4b (2026-08-12) — the third bottom-nav tab is สินค้า now,
 * and it reads a NEW key, `nav_products`. `nav_sales` was deliberately NOT
 * recycled (ag-lead ruling, TASK-169 §5.1): a company that had renamed "ขาย"
 * must not find their own word suddenly labelling a different destination.
 *
 * The two retired keys are handled DIFFERENTLY, on purpose:
 *
 *  - `nav_profile` — retired from the tab bar in TASK-079 but STILL READ, by
 *    the Agent Portal's ProfileSettingsView page header. It stays, captioned
 *    "หน้าโปรไฟล์ (ไม่ใช่เมนูล่าง)" so nobody expects a sixth tab.
 *
 *  - `nav_sales`   — REMOVED from both lists (human, 2026-08-12: "เมนูขาย
 *    เลิกใช้ เอาออก"). Nothing reads it: TASK-169 merged ขาย into ลูกค้า and
 *    deleted ReferralsView, the only page that used it as a title. A field
 *    that changes nothing is clutter in a settings screen, and leaving it
 *    invited an admin to spend attention on a control with no effect.
 *
 *    A stored `nav_sales` override in an existing company's
 *    `label_overrides` / `nav_icon_overrides` is now unreachable from this
 *    form — harmless, because it is dead data no code path consults. It is
 *    left in place rather than migrated away: destroying a company's saved
 *    word to tidy a JSON blob is not a trade worth making.
 */
const LABEL_FIELDS: { key: string; caption: string; placeholder: string }[] = [
  { key: 'app_name', caption: 'ชื่อแอป', placeholder: 'Sync Vision Agent' },
  { key: 'nav_home', caption: 'เมนู: หน้าหลัก', placeholder: 'หน้าหลัก' },
  { key: 'nav_clients', caption: 'เมนู: ลูกค้า', placeholder: 'ลูกค้า' },
  { key: 'nav_products', caption: 'เมนู: สินค้า', placeholder: 'สินค้า' },
  { key: 'nav_academy', caption: 'เมนู: Academy', placeholder: 'Academy' },
  { key: 'nav_commission', caption: 'เมนู: ค่าคอม', placeholder: 'ค่าคอม' },
  { key: 'nav_profile', caption: 'หน้าโปรไฟล์ (ไม่ใช่เมนูล่าง)', placeholder: 'โปรไฟล์' },
]

// TASK-057 — bottom-nav icon override rows (BR-7). Same 5 keys as
// LABEL_FIELDS minus app_name (app_name has no icon), each with the icon
// name the Agent Portal's BottomNav.vue falls back to when unset — must
// stay in sync with those literals in BottomNav.vue.
/**
 * `inBottomNav` — is this key an actual TAB, or just an overridable icon?
 *
 * The bottom bar has exactly FIVE slots and they are decided in code
 * (`BottomNav.vue`): home / clients / products / academy / commission. That is
 * information architecture, not configuration — a company can rename or
 * re-icon a tab, it cannot add or remove one. These keys only override the
 * LOOK of a slot that already exists.
 *
 * The other two are overridable but are not tabs: `nav_profile` dresses the
 * profile PAGE (reached from the avatar in the top bar, not the bar), and
 * `nav_sales` is retired entirely — TASK-169 merged ขาย into ลูกค้า, and
 * `BottomNav` no longer reads it. Both stay listed so a company that already
 * set one can see and clear it (the `nav_profile` precedent, TASK-079).
 *
 * The preview below MUST filter on this flag. Rendering one tab per editable
 * key made the preview show seven tabs where the agent sees five — an admin
 * would pick an icon for "ขาย", watch it appear in the preview, and never
 * find it in the app (human, 2026-08-12).
 */
const NAV_ICON_FIELDS: { key: string; caption: string; fallback: string; inBottomNav: boolean }[] = [
  { key: 'nav_home', caption: 'เมนู: หน้าหลัก', fallback: 'home', inBottomNav: true },
  { key: 'nav_clients', caption: 'เมนู: ลูกค้า', fallback: 'users', inBottomNav: true },
  { key: 'nav_products', caption: 'เมนู: สินค้า', fallback: 'box', inBottomNav: true },
  { key: 'nav_academy', caption: 'เมนู: Academy', fallback: 'brain', inBottomNav: true },
  { key: 'nav_commission', caption: 'เมนู: ค่าคอม', fallback: 'money', inBottomNav: true },
  { key: 'nav_profile', caption: 'หน้าโปรไฟล์ (ไม่ใช่เมนูล่าง)', fallback: 'user', inBottomNav: false },
]

/**
 * One row per menu, carrying BOTH its label and its icon (human, 2026-08-12:
 * "2 card นี้เรื่องเดียวกัน รวม UI เป็น card เดียวกัน").
 *
 * These were two cards, each listing the same menus — so "เมนู: ลูกค้า"
 * appeared twice on the page and renaming a tab meant editing it in one card
 * and re-finding it in another. One row, one menu, both of its settings.
 *
 * Joined rather than merged into a single constant: LABEL_FIELDS is read by
 * the save loop and the preview's label fallbacks, NAV_ICON_FIELDS by the
 * preview's icons — rewriting both shapes to change a layout would touch four
 * call sites for no gain. `app_name` is deliberately not here: it is a label
 * with no icon and no menu.
 */
const MENU_ROWS = NAV_ICON_FIELDS.map((icon) => ({
  ...icon,
  placeholder: LABEL_FIELDS.find((l) => l.key === icon.key)?.placeholder ?? '',
}))

/** The five that actually render as tabs — the preview's source of truth. */
const BOTTOM_NAV_FIELDS = NAV_ICON_FIELDS.filter((f) => f.inBottomNav)

/*
 * ══════════ TASK-175 — four tabs over ONE form ══════════
 *
 * Seven stacked sections were four screens of scrolling on a 2K display. They
 * are now four tabs (§4's mapping), and the tab bar sits in HeroHeader's
 * `#tabs` slot — the same place and the same markup CommissionPlansView uses,
 * rather than a second tab idiom invented for this one screen.
 *
 * WHAT THIS IS NOT: it is not fewer settings and it is not less scrolling in
 * total (§2). The scrolling moves INSIDE a panel, so the live preview and the
 * one save button never leave the viewport.
 *
 * THE ONE RULE THAT MATTERS — every section below stays MOUNTED and is hidden
 * with `v-show`, never `v-if`. All four tabs are a single form saved by a
 * single `PUT /company-theme`; an unmounted panel would take its edits with
 * it, so an admin who adjusts the colours, moves to ฟอนต์ and presses save
 * would lose the colours silently, under a "บันทึกแล้ว" toast (§4). This is
 * what `ThemeSettingsView.spec.ts` exists to hold in place.
 *
 * The membership marker is `v-show` ON EACH `<section>` rather than four
 * wrapper divs, deliberately: the sections keep their existing DOM position
 * and indentation, so this change adds attributes and moves nothing. Sections
 * that share a tab need not be adjacent — which is why ลิงก์ Login and
 * หน้าร้าน can both be "อื่นๆ" without ชื่อแอปและเมนู being relocated out from
 * between them.
 *
 * The three per-company setting cards that used to sit below the grid
 * (ตั้งค่าวิดีโอ / การมองเห็นข้อมูลทีม / การแบ่งคอมมิชชั่น) were never part of
 * this — they were not theme, they wrote to three other endpoints and kept
 * their own three save buttons (§3 D2, human decision). TASK-202 (human
 * request, 2026-08-17) moved all three out to their own submenu pages under
 * "ตั้งค่าระบบ" (VideoSettingsView / TeamVisibilitySettingsView /
 * CommissionSplitSettingsView), so this page is theme/branding only now.
 */
type ThemeTab = 'colors' | 'fontsLogos' | 'naming' | 'other'

const THEME_TABS: { key: ThemeTab; label: string; icon: string }[] = [
  { key: 'colors', label: 'สี', icon: 'palette' },
  { key: 'fontsLogos', label: 'ฟอนต์และโลโก้', icon: 'type' },
  { key: 'naming', label: 'ชื่อและเมนู', icon: 'list' },
  { key: 'other', label: 'อื่นๆ', icon: 'dots' },
]

const activeTab = ref<ThemeTab>('colors')

// ── Form state ────────────────────────────────────────────────────────────
const theme = ref<Theme | null>(null) // last-loaded (used for logo URLs)
const loading = ref(false)
const loadError = ref('')

const primaryHex = ref<string | null>(null)
const accentHex = ref<string | null>(null)
// App-chrome colours (top bar + bottom-nav "menu"). Null ⇒ platform default.
const navBg = ref<string | null>(null)
// TASK-161 — nav-bar fill mode. Defaults to 'solid' (never null): the
// backend treats null as solid anyway, and a third "unset" state on a
// two-option switch is a state no admin can see or reason about.
const navBgType = ref<NavBgType>('solid')
// Gradient stops for the nav bar. Seeded in populateForm() from colours the
// company ALREADY has (its nav solid colour + its primary), never from an
// invented palette — BR-7/CLAUDE.md §8 rule 2. The 135° default matches the
// app-background gradient control's existing default (an angle, not a
// business value).
const navGradientColor1 = ref<string>(DEFAULTS.navBg)
const navGradientColor2 = ref<string>(DEFAULTS.primary)
const navGradientAngle = ref<number>(135)
const navText = ref<string | null>(null)
// Bottom-nav "active tab" override. null ⇒ keeps following the primary
// brand colour (the pre-existing behaviour — the field never changes what
// renders unless the admin explicitly sets it).
const navActive = ref<string | null>(null)
const cardBg = ref<string | null>(null)
const cardText = ref<string | null>(null)
const cardBorderMode = ref<'default' | 'none' | 'custom'>('default')
const cardBorderColor = ref('#e2e8f0')
const cardShadow = ref<string | null>(null)

const SHADOW_MAP: Record<string, string> = {
  none: 'none',
  sm: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
  md: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
  lg: '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
  xl: '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
}
const SHADOW_OPTIONS = [
  { value: '', label: 'ค่าเริ่มต้น' },
  { value: 'none', label: 'ไม่มีเงา' },
  { value: 'sm', label: 'เล็ก' },
  { value: 'md', label: 'กลาง' },
  { value: 'lg', label: 'ใหญ่' },
  { value: 'xl', label: 'ใหญ่มาก' },
]

// Split font picker: a Thai header font + a Latin/English header font,
// chosen separately, filtered by a category chip row. Either may be null
// (⇒ use the platform default). The category filter only narrows which
// options appear in the two dropdowns; it is never persisted.
const fontThai = ref<string | null>(null)
const fontLatin = ref<string | null>(null)
const fontCategory = ref<FontCategory | 'all'>('all')

const thaiFonts = computed(() =>
  FONT_CATALOG.filter(
    (f) => f.script === 'thai' && (fontCategory.value === 'all' || f.categories.includes(fontCategory.value)),
  ),
)
const latinFonts = computed(() =>
  FONT_CATALOG.filter(
    (f) => f.script === 'latin' && (fontCategory.value === 'all' || f.categories.includes(fontCategory.value)),
  ),
)

const backgroundType = ref<BackgroundType | null>(null)
const solidColor = ref<string>('#f8fafc')
const gradientFrom = ref<string>('#1e3a8a')
const gradientTo = ref<string>('#f59e0b')
const gradientAngle = ref<number>(135)

const loadingBgHex = ref<string | null>(null)
const loadingMessage = ref<string>('')

// TASK-069 / ADR-020 — "จำนวนสินค้าแนะนำ" (BR-7). See the Theme interface's
// own comment above: the backend column/consumer exist, but the read/write
// endpoint doesn't expose this field yet — kept here so the field just
// starts working once ag-dev lands that follow-up, default mirrors
// ProductRecommendationService::DEFAULT_SLOT_COUNT.
const recommendedSlotCount = ref<number>(8)

const labels = reactive<Record<string, string>>(
  Object.fromEntries(LABEL_FIELDS.map((f) => [f.key, ''])),
)

// TASK-057 — bottom-nav icon overrides. '' = unset (use the built-in
// fallback), same empty-string-means-unset convention as `labels`.
const icons = reactive<Record<string, string>>(
  Object.fromEntries(NAV_ICON_FIELDS.map((f) => [f.key, ''])),
)

// TASK-208 — was a local company <select> + its own /companies fetch. The
// alias below keeps every existing read in this (very long) file working
// unchanged while the single source of truth moves to the global store.
const activeCompany = useActiveCompanyStore()
const selectedCompanyId = computed(() => activeCompany.companyId)
const selectedCompany = computed(() =>
  activeCompany.companies.find((c) => c.id === activeCompany.companyId) ?? null)

// Color <input type=color> needs a non-null value; keep the "unset ⇒ null"
// semantic while still showing a sensible swatch. get() falls back to the
// neutral default, set() writes the real hex.
function hexModel(source: typeof primaryHex, fallback: string) {
  return computed<string>({
    get: () => source.value ?? fallback,
    set: (v: string) => { source.value = v },
  })
}
const primaryModel = hexModel(primaryHex, DEFAULTS.primary)
const accentModel = hexModel(accentHex, DEFAULTS.accent)
const loadingBgModel = hexModel(loadingBgHex, DEFAULTS.loadingBg)
const navBgModel = hexModel(navBg, DEFAULTS.navBg)
const navTextModel = hexModel(navText, DEFAULTS.navText)
const cardBgModel = hexModel(cardBg, DEFAULTS.card)
const cardTextModel = hexModel(cardText, DEFAULTS.cardText)
// Fallback tracks the current primary colour (not a fixed hex) since that's
// what the bottom-nav active tab renders as when unset.
const navActiveModel = computed<string>({
  get: () => navActive.value ?? primaryModel.value,
  set: (v: string) => { navActive.value = v },
})

// ── Load ──────────────────────────────────────────────────────────────────
function populateForm(t: Theme): void {
  theme.value = t
  primaryHex.value = t.primary_hex
  accentHex.value = t.accent_hex
  navBg.value = t.nav_bg_hex
  // TASK-161 — nav fill mode + its two stops. The stops fall back to
  // colours this company already has (its solid nav colour, its primary)
  // rather than to a made-up pair, so the first switch to "ไล่สี" shows a
  // real, visibly-different gradient instead of white-on-white.
  navBgType.value = t.nav_bg_type === 'gradient' ? 'gradient' : 'solid'
  const navCfg = t.nav_bg_config ?? {}
  navGradientColor1.value = (navCfg.color1 as string) ?? t.nav_bg_hex ?? DEFAULTS.navBg
  navGradientColor2.value = (navCfg.color2 as string) ?? t.primary_hex ?? DEFAULTS.primary
  navGradientAngle.value = typeof navCfg.angle === 'number' ? navCfg.angle : 135
  navText.value = t.nav_text_hex
  navActive.value = t.nav_active_hex
  cardBg.value = t.card_bg_hex
  cardText.value = t.card_text_hex
  if (!t.card_border_hex) cardBorderMode.value = 'default'
  else if (t.card_border_hex === 'none') cardBorderMode.value = 'none'
  else { cardBorderMode.value = 'custom'; cardBorderColor.value = t.card_border_hex }
  cardShadow.value = t.card_shadow
  // Split fonts: prefer the per-script values, fall back to the legacy
  // single font_family for either when null.
  fontThai.value = t.font_family_thai ?? t.font_family
  fontLatin.value = t.font_family_latin ?? t.font_family
  loadingBgHex.value = t.loading.bg_hex
  loadingMessage.value = t.loading.message ?? ''

  backgroundType.value = t.background.type
  const cfg = t.background.config ?? {}
  solidColor.value = (cfg.color as string) ?? '#f8fafc'
  gradientFrom.value = (cfg.from as string) ?? '#1e3a8a'
  gradientTo.value = (cfg.to as string) ?? '#f59e0b'
  gradientAngle.value = typeof cfg.angle === 'number' ? cfg.angle : 135

  for (const f of LABEL_FIELDS) labels[f.key] = t.label_overrides?.[f.key] ?? ''
  for (const f of NAV_ICON_FIELDS) icons[f.key] = t.nav_icon_overrides?.[f.key] ?? ''
  // TASK-069 — reads as undefined until ThemeResource exposes this field
  // (see the Theme interface's comment); falls back to the same default
  // ProductRecommendationService already uses server-side.
  recommendedSlotCount.value = t.recommended_slot_count ?? 8
}

async function loadTheme(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    if (isSuperAdmin.value) {
      const slug = selectedCompany.value?.slug
      if (!slug) { loading.value = false; return }
      const res = await api.get<{ data: Theme }>(`/public/theme/${slug}`)
      populateForm(res.data)
    } else {
      const res = await api.get<{ data: Theme }>('/me/theme')
      populateForm(res.data)
    }
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลธีมไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await activeCompany.loadCompanies()
  // With no company scoped (ทุกบริษัท) there is no single theme to edit —
  // the notice in the template explains it and loadTheme() is skipped.
  if (!activeCompany.requiresCompanyPick) await loadTheme()
})

watch(() => activeCompany.companyId, () => { if (!activeCompany.requiresCompanyPick) loadTheme() })

// TASK-063 (human-reported 2026-07-31) — the Agent Portal /login page
// can't know which company's theme to paint until SOMEONE is logged in
// (no company signal pre-auth). Fix: let the Admin generate/copy a
// branded /login?company=<slug> link + QR here and hand it to agents
// directly, rather than adding a new tenant-lookup step to the login
// flow itself (see chat discussion — human picked this option).
const loginLink = computed(() => theme.value?.login_link ?? null)
const loginLinkQr = ref('')
const loginLinkCopied = ref(false)
watch(
  loginLink,
  async (link) => {
    loginLinkQr.value = link ? await generateQrDataUrl(link, 220) : ''
  },
  { immediate: true },
)
async function copyLoginLink(): Promise<void> {
  if (!loginLink.value) return
  try {
    await navigator.clipboard.writeText(loginLink.value)
    loginLinkCopied.value = true
    setTimeout(() => { loginLinkCopied.value = false }, 2000)
  } catch {
    // Clipboard API can be unavailable (e.g. non-HTTPS/older browser) —
    // the link is still shown in a selectable text field as a fallback.
  }
}
function downloadLoginLinkQr(): void {
  if (!loginLinkQr.value || !theme.value) return
  const a = document.createElement('a')
  a.href = loginLinkQr.value
  a.download = `login-qr-${theme.value.company.slug}.png`
  a.click()
}

// ── Dynamic Google Font loading (for the live preview) ────────────────────
// One <link> per script slot (idempotent id) so switching either dropdown
// swaps only that slot's href and the preview shows both faces immediately.
function loadPreviewFont(font: string | null, id: string): void {
  if (!font) return
  const family = font.replace(/ /g, '+')
  const href = `https://fonts.googleapis.com/css2?family=${family}:wght@400;500;700&display=swap`
  let link = document.getElementById(id) as HTMLLinkElement | null
  if (!link) {
    link = document.createElement('link')
    link.id = id
    link.rel = 'stylesheet'
    document.head.appendChild(link)
  }
  link.href = href
}
watch(fontThai, (f) => loadPreviewFont(f, 'sv-theme-preview-font-thai'), { immediate: true })
watch(fontLatin, (f) => loadPreviewFont(f, 'sv-theme-preview-font-latin'), { immediate: true })

// ── Color reset ─────────────────────────────────────────────────────────
function resetPrimary(): void { primaryHex.value = null }
function resetAccent(): void { accentHex.value = null }
function resetLoadingBg(): void { loadingBgHex.value = null }
function resetNavBg(): void { navBg.value = null }
function resetNavText(): void { navText.value = null }
function resetNavActive(): void { navActive.value = null }
function resetCardBg(): void { cardBg.value = null }
function resetCardText(): void { cardText.value = null }

// ── Save (presentational fields only — never logo paths) ──────────────────
const saving = ref(false)
const saveError = ref('')
const saved = ref(false)

function buildLabelOverrides(): Record<string, string> {
  const out: Record<string, string> = {}
  for (const f of LABEL_FIELDS) {
    const v = labels[f.key]?.trim()
    if (v) out[f.key] = v // empty ⇒ omit the key (removes the override)
  }
  return out
}

// TASK-057 — mirrors buildLabelOverrides() exactly.
function buildIconOverrides(): Record<string, string> {
  const out: Record<string, string> = {}
  for (const f of NAV_ICON_FIELDS) {
    const v = icons[f.key]?.trim()
    if (v) out[f.key] = v // empty ⇒ omit the key (removes the override)
  }
  return out
}

function buildBackgroundConfig(): Record<string, unknown> | null {
  if (backgroundType.value === 'gradient') {
    return { from: gradientFrom.value, to: gradientTo.value, angle: gradientAngle.value }
  }
  if (backgroundType.value === 'solid') {
    return { color: solidColor.value }
  }
  return null // image ⇒ config lives in the uploaded file, or type cleared
}

/*
 * TASK-161 §3.1 — nav-bar gradient config.
 *
 * Key names are `color1`/`color2`, NOT the `from`/`to` that
 * buildBackgroundConfig() above uses. That asymmetry is the spec's, not a
 * typo here: §3.1 defines `nav_bg_config` as `{ color1, color2, angle }`
 * while `background_config` has shipped as `{ from, to, angle }` since
 * TASK-055. Both call sites feed the SAME GradientPicker component and
 * differ only in this one mapping function — which is exactly why the
 * mapping lives here and not inside the component.
 */
function buildNavBgConfig(): Record<string, unknown> | null {
  if (navBgType.value !== 'gradient') return null

  return { color1: navGradientColor1.value, color2: navGradientColor2.value, angle: navGradientAngle.value }
}

async function save(): Promise<void> {
  saving.value = true
  saveError.value = ''
  saved.value = false
  try {
    const payload: Record<string, unknown> = {
      primary_hex: primaryHex.value,
      accent_hex: accentHex.value,
      nav_bg_hex: navBg.value || null,
      // TASK-161 §3.1 — always send both. A 'gradient' type with a missing
      // stop is a 422 by design server-side, so the config is built whole
      // or sent as null; it is never half-specified from here.
      nav_bg_type: navBgType.value,
      nav_bg_config: buildNavBgConfig(),
      nav_text_hex: navText.value || null,
      nav_active_hex: navActive.value || null,
      card_bg_hex: cardBg.value || null,
      card_text_hex: cardText.value || null,
      card_border_hex: cardBorderMode.value === 'default' ? null : cardBorderMode.value === 'none' ? 'none' : cardBorderColor.value,
      card_shadow: cardShadow.value || null,
      loading_bg_hex: loadingBgHex.value,
      background_type: backgroundType.value,
      background_config: buildBackgroundConfig(),
      // Split fonts (per-script). Legacy font_family kept for back-compat:
      // the Thai face is the sensible single fallback for old consumers.
      font_family_thai: fontThai.value || null,
      font_family_latin: fontLatin.value || null,
      font_family: fontThai.value || fontLatin.value || null,
      // Default weight set whenever either font is chosen (ADR-018), else null.
      font_weights: fontThai.value || fontLatin.value ? [400, 500, 700] : null,
      loading_message: loadingMessage.value.trim() || null,
      label_overrides: buildLabelOverrides(),
      nav_icon_overrides: buildIconOverrides(),
      // TASK-069 — see the Theme interface's comment: UpdateThemeRequest
      // doesn't accept this key yet, so Laravel's validated() strips it
      // and this currently no-ops server-side (harmless — never causes a
      // 422) until ag-dev adds the 2-line follow-up.
      recommended_slot_count: recommendedSlotCount.value,
    }
    if (isSuperAdmin.value && selectedCompanyId.value) payload.company_id = selectedCompanyId.value

    const res = await api.put<{ data: Theme }>('/company-theme', payload)
    populateForm(res.data)
    saved.value = true
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    saving.value = false
  }
}

// ── Logo / background-image uploads (immediate, per slot) ──────────────────
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024
const uploadingSlot = ref<AssetSlot | null>(null)
const uploadError = ref('')

async function uploadAsset(slot: AssetSlot, file: File): Promise<void> {
  uploadError.value = ''
  if (!file.type.startsWith('image/')) {
    uploadError.value = 'กรุณาเลือกไฟล์รูปภาพ (jpg / png / webp / svg)'
    return
  }
  uploadingSlot.value = slot
  try {
    // Favicons must stay small/square; other slots can be a bit larger.
    const maxDim = slot === 'favicon' ? 256 : 1024
    const compressed = await compressImage(file, { maxDimension: maxDim, quality: 0.85 })
    if (compressed.size > MAX_UPLOAD_BYTES) {
      uploadError.value = 'ไฟล์ใหญ่เกินไป (ไม่เกิน 5MB)'
      return
    }
    const formData = new FormData()
    formData.append('slot', slot)
    formData.append('file', compressed)
    if (isSuperAdmin.value && selectedCompanyId.value) {
      formData.append('company_id', String(selectedCompanyId.value))
    }
    const res = await api.postForm<{ data: Theme }>('/company-theme/asset', formData)
    populateForm(res.data)
  } catch (e) {
    uploadError.value = e instanceof ApiError ? e.message : 'อัปโหลดไม่สำเร็จ'
  } finally {
    uploadingSlot.value = null
  }
}

const logoTiles: { slot: AssetSlot; caption: string; urlKey: keyof Theme['logos'] }[] = [
  { slot: 'nav', caption: 'โลโก้แถบเมนู (Nav)', urlKey: 'nav_url' },
  { slot: 'login', caption: 'โลโก้หน้าเข้าสู่ระบบ', urlKey: 'login_url' },
  { slot: 'favicon', caption: 'Favicon', urlKey: 'favicon_url' },
  { slot: 'loading', caption: 'โลโก้หน้าโหลด', urlKey: 'loading_url' },
]

const assetInputs = ref<Record<string, HTMLInputElement | null>>({})
function setAssetInput(slot: string, el: unknown): void {
  assetInputs.value[slot] = (el as HTMLInputElement) ?? null
}
function triggerAssetPicker(slot: AssetSlot): void {
  assetInputs.value[slot]?.click()
}
async function onAssetSelected(slot: AssetSlot, e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) await uploadAsset(slot, file)
  input.value = ''
}

// ── Live preview styles (driven purely by reactive form state) ────────────
// Per-glyph stack: Latin face FIRST, Thai face SECOND — Latin chars render
// in the Latin face, Thai chars fall through to the Thai face. Mirrors the
// Agent Portal's runtime apply().
const previewFontFamily = computed(() => {
  const stack: string[] = []
  if (fontLatin.value) stack.push(`"${fontLatin.value}"`)
  if (fontThai.value && fontThai.value !== fontLatin.value) stack.push(`"${fontThai.value}"`)
  if (!stack.length) stack.push('Kanit')
  stack.push('sans-serif')
  return stack.join(', ')
})
const previewPrimary = computed(() => primaryHex.value ?? DEFAULTS.primary)
const previewAccent = computed(() => accentHex.value ?? DEFAULTS.accent)
const previewLoadingBg = computed(() => loadingBgHex.value ?? DEFAULTS.loadingBg)
// App-chrome (top bar + bottom-nav) colours — fall back to the platform
// defaults (white bar + slate text) when unset so the preview matches runtime.
const previewNavBg = computed(() => navBg.value ?? DEFAULTS.navBg)
/*
 * TASK-161 hard requirement — the preview must show the GRADIENT nav bar
 * before anything is saved. Both nav surfaces in the phone mock (top bar +
 * bottom nav) read this, not previewNavBg, so a `background:` shorthand
 * carrying a linear-gradient() drops straight in exactly as it will at
 * runtime (`--nav-bg` is consumed as `background: var(--nav-bg)` in the
 * Agent Portal's App.vue and BottomNav.vue — TASK-161 §1).
 */
const previewNavBackground = computed(() =>
  navBgType.value === 'gradient'
    ? `linear-gradient(${navGradientAngle.value}deg, ${navGradientColor1.value}, ${navGradientColor2.value})`
    : previewNavBg.value,
)
const previewNavText = computed(() => navText.value ?? DEFAULTS.navText)
const previewNavActive = computed(() => navActive.value ?? previewPrimary.value)
const previewCardBg = computed(() => cardBg.value ?? DEFAULTS.card)
const previewCardText = computed(() => cardText.value ?? DEFAULTS.cardText)
const previewCardBorder = computed(() =>
  cardBorderMode.value === 'none' ? 'transparent' : cardBorderMode.value === 'custom' ? cardBorderColor.value : '#e2e8f0',
)
const previewCardShadow = computed(() => (cardShadow.value ? SHADOW_MAP[cardShadow.value] ?? 'none' : 'none'))
const previewAppName = computed(() => labels.app_name?.trim() || 'Sync Vision')

const previewBackgroundStyle = computed(() => {
  if (backgroundType.value === 'gradient') {
    return { background: `linear-gradient(${gradientAngle.value}deg, ${gradientFrom.value}, ${gradientTo.value})` }
  }
  if (backgroundType.value === 'solid') {
    return { background: solidColor.value }
  }
  if (backgroundType.value === 'image' && theme.value?.background.image_url) {
    return { background: `center/cover no-repeat url(${theme.value.background.image_url})` }
  }
  return { background: '#f1f5f9' }
})

// TASK-057 — preview icons now follow the admin's picked overrides (fall
// back to the same defaults the real Agent Portal BottomNav.vue uses).
const previewNavIcons = computed(() =>
  BOTTOM_NAV_FIELDS.map((f) => icons[f.key]?.trim() || f.fallback),
)

/**
 * TASK-105 — both preview rows are derived from NAV_ICON_FIELDS so they can
 * never fall out of step. The previous version hardcoded five labels while
 * the icon row was generated, so adding nav_sales silently shifted every
 * label one tab to the left. The label fallbacks are read from
 * LABEL_FIELDS' placeholders, which are themselves the real BottomNav
 * defaults — one source of truth instead of three.
 */
const previewNavLabels = computed(() =>
  BOTTOM_NAV_FIELDS.map(
    (f) =>
      labels[f.key]?.trim() ||
      LABEL_FIELDS.find((l) => l.key === f.key)?.placeholder ||
      '',
  ),
)

/*
 * ══════════ TASK-161 §4 — colour presets ══════════
 *
 * A preset is a named snapshot of this company's COLOURS only (§3.2's list:
 * primary/accent/nav/card/background type+config). Never logos, fonts,
 * labels or icons — those are a company's identity, not a "look".
 *
 * Two things about the API shape that the UI here is deliberately built
 * around (TASK-161 §3.2, ag-dev owns the endpoints):
 *
 *  1. POST /theme-presets takes a NAME ONLY. The server snapshots the
 *     values already stored in company_theme_settings; it does not accept
 *     a colour payload from the client. The consequence the admin must be
 *     told about (and is, in the section's helper text): unsaved edits in
 *     the form above are NOT what gets captured — the last SAVED colours
 *     are. Silently snapshotting something different from what the screen
 *     is showing would be the worst possible outcome here.
 *  2. Presets belong to ONE company (BR-6 + a ThemePresetPolicy that gives
 *     an Agent nothing). TASK-161 §5.2 (human decision, 2026-08-11) added
 *     Super Admin access — but scoped, not cross-company: they work inside
 *     one company's context, exactly like every other section on this
 *     screen. So every preset call carries `company_id` for a Super Admin,
 *     taken from the SAME company picker at the top of the page that
 *     already decides which theme they are editing (there is deliberately
 *     no second selector — two pickers is how the list and the form end up
 *     describing different companies).
 *
 *     A Company Admin sends no company_id at all: the server ignores any
 *     they send and uses their own, so sending one would only be
 *     misleading about where the data came from.
 *
 *     Still NOT possible: applying one company's OWNED preset to another.
 *     The server rejects a preset whose company disagrees with the selected
 *     one, and this screen never offers it — the list only ever shows the
 *     selected company's presets, plus the shared ones described next.
 *
 *  3. TASK-217 (human request, 2026-08-20) — a Super Admin can now save a
 *     preset as ชุดกลาง: `company_id` NULL on the server, `is_shared` here.
 *     Those rows appear in EVERY company's list and apply onto whichever
 *     company is currently selected. That is the one and only way a palette
 *     crosses a tenant boundary, and it is deliberate: a preset carries hex
 *     values and nothing else (§3.2's colour surface — no names, no logos,
 *     no business data), so sharing one is the platform shipping a look,
 *     not a tenancy leak.
 *
 *     The checkbox that creates them is rendered for a Super Admin only,
 *     and the server strips the flag for anybody else — so a Company Admin
 *     who somehow sends it gets an ordinary company-scoped preset, not a
 *     422 about a control they were never shown.
 */
interface ThemePreset {
  id: number
  name: string
  /**
   * TASK-164 §1 — a preset the PLATFORM provisioned: the company's
   * "ค่าเริ่มต้น" restore point plus the five designed palettes. Read-only:
   * this screen hides rename and delete for them and keeps "ใช้ชุดนี้".
   *
   * The flag is why the CONTROLS are absent, not the enforcement — the
   * server refuses both verbs with a 422 whatever the client sends.
   */
  is_system: boolean
  /**
   * TASK-217 — ชุดกลาง: the row has no owning company, so EVERY company
   * sees it and may apply it. Only a Super Admin may rename or delete one
   * (it is in use by every other tenant, and nothing on a Company Admin's
   * screen would tell them so).
   *
   * Independent of `is_system`: a shared preset is normally one a Super
   * Admin saved by hand, while the five designed palettes are per-company
   * system rows. A row can be both, and the read-only rules simply stack.
   */
  is_shared: boolean
  /** Colour surface only — see §3.2 for the exact key list. */
  colors: Record<string, unknown>
}

/**
 * A Super Admin can manage presets, but only once the picker has resolved
 * a company — before that there is no tenant to list, and an unscoped call
 * would 422 (the API requires the id precisely because TenantScope does
 * not constrain a Super Admin). Same guard shape as VideoSettingsView's
 * loadVideoSettings() and TeamVisibilitySettingsView's
 * loadTeamVisibilitySettings() (TASK-202 moved those two off this page,
 * but they still share this page's company picker convention).
 */
const canManagePresets = computed(() => !isSuperAdmin.value || !!selectedCompanyId.value)

/** `company_id` is a Super-Admin-only parameter — see note 2 above. */
function presetCompanyPayload(): Record<string, number> {
  return isSuperAdmin.value && selectedCompanyId.value
    ? { company_id: selectedCompanyId.value }
    : {}
}

const presets = ref<ThemePreset[]>([])
const presetsLoading = ref(false)
const presetsError = ref('')
const newPresetName = ref('')
/**
 * TASK-217 — "ใช้ร่วมทุกบริษัท". Reset after every successful save on
 * purpose: sharing is the exceptional case, so it must be re-chosen each
 * time rather than remembered and applied to the next save by surprise.
 */
const newPresetShared = ref(false)
const presetNameInput = ref<HTMLInputElement | null>(null)
const savingPreset = ref(false)
const applyingPreset = ref(false)
const deletingPreset = ref(false)
const pendingApplyPreset = ref<ThemePreset | null>(null)
const pendingDeletePreset = ref<ThemePreset | null>(null)
const renamingPresetId = ref<number | null>(null)
const renameDraft = ref('')

/**
 * TASK-164 §4 — the tooltip on the "ชุดมาตรฐาน" chip. Deliberately explains
 * what IS possible (apply) rather than only what is not: an admin who sees a
 * row with fewer buttons than its neighbours should be told why in one hover,
 * not left to try and get a 422.
 */
const SYSTEM_PRESET_HINT = 'ชุดสีมาตรฐานของระบบ — ใช้งานได้ แต่เปลี่ยนชื่อหรือลบไม่ได้'

/**
 * TASK-217 — the tooltip on the "ชุดกลาง" chip. Same principle as
 * SYSTEM_PRESET_HINT above: say what IS possible first, so an admin looking
 * at a row with fewer buttons than its neighbours learns why in one hover.
 */
const SHARED_PRESET_HINT = 'ชุดสีกลางที่ใช้ร่วมกันทุกบริษัท — กด "ใช้ชุดนี้" เพื่อนำมาใช้กับบริษัทนี้ได้ ส่วนการเปลี่ยนชื่อ/ลบทำได้เฉพาะ Super Admin'

/**
 * Whether THIS admin may rename or delete THIS preset — i.e. whether the
 * pencil/bin pair is rendered at all.
 *
 * Mirrors ThemePresetPolicy::update() on the server, and is the reason the
 * controls are ABSENT rather than present-and-failing: an action that is
 * visible but always answers 422 is worse than one that was never offered
 * (the same call TASK-164 made for system presets). The server still
 * refuses independently — this function decides what to draw, never what is
 * allowed.
 */
function canEditPreset(preset: ThemePreset): boolean {
  if (preset.is_system) return false
  if (preset.is_shared) return isSuperAdmin.value

  return true
}

function presetErrorMessage(e: unknown, fallback: string): string {
  return e instanceof ApiError ? e.message : fallback
}

function presetHex(colors: Record<string, unknown>, key: string): string | null {
  const v = colors[key]

  return typeof v === 'string' && v ? v : null
}

/**
 * The nav swatch has to be able to show a GRADIENT preset, otherwise two
 * presets that differ only in their nav gradient look identical in the
 * list — which defeats the point of a preview.
 */
function presetNavBackground(colors: Record<string, unknown>): string | null {
  const cfg = (colors.nav_bg_config ?? null) as Record<string, unknown> | null
  if (colors.nav_bg_type === 'gradient' && cfg) {
    const c1 = typeof cfg.color1 === 'string' ? cfg.color1 : null
    const c2 = typeof cfg.color2 === 'string' ? cfg.color2 : null
    const angle = typeof cfg.angle === 'number' ? cfg.angle : 135
    if (c1 && c2) return `linear-gradient(${angle}deg, ${c1}, ${c2})`
  }

  return presetHex(colors, 'nav_bg_hex')
}

/**
 * Swatch strip for one preset row. A slot with no stored value is skipped
 * rather than filled with a placeholder colour: showing a hex the preset
 * does not actually contain would misrepresent what "ใช้ชุดนี้" will do.
 */
function presetSwatches(preset: ThemePreset): { key: string; caption: string; background: string }[] {
  const c = preset.colors ?? {}
  const slots: { key: string; caption: string; background: string | null }[] = [
    { key: 'primary', caption: 'สีหลัก', background: presetHex(c, 'primary_hex') },
    { key: 'accent', caption: 'สีรอง', background: presetHex(c, 'accent_hex') },
    { key: 'nav', caption: 'แถบเมนู', background: presetNavBackground(c) },
    { key: 'card', caption: 'การ์ด', background: presetHex(c, 'card_bg_hex') },
  ]

  return slots
    .filter((s): s is { key: string; caption: string; background: string } => s.background !== null)
    .map((s) => ({ key: s.key, caption: s.caption, background: s.background }))
}

async function loadPresets(): Promise<void> {
  if (!canManagePresets.value) return
  presetsLoading.value = true
  presetsError.value = ''
  try {
    // Same query-string shape as VideoSettingsView/TeamVisibilitySettingsView
    // (TASK-202 moved those pages out, but the ?company_id= convention stays).
    const path = isSuperAdmin.value
      ? `/theme-presets?company_id=${selectedCompanyId.value}`
      : '/theme-presets'
    const res = await api.get<{ data: ThemePreset[] }>(path)
    presets.value = res.data
  } catch (e) {
    presetsError.value = presetErrorMessage(e, 'โหลดชุดสีไม่สำเร็จ')
  } finally {
    presetsLoading.value = false
  }
}

function focusPresetName(): void {
  presetNameInput.value?.focus()
}

async function savePreset(): Promise<void> {
  const name = newPresetName.value.trim()
  if (!name) {
    presetsError.value = 'กรุณาตั้งชื่อชุดสีก่อนบันทึก'
    focusPresetName()

    return
  }
  savingPreset.value = true
  presetsError.value = ''
  try {
    /*
     * TASK-163 — SAVE THE FORM FIRST, THEN SNAPSHOT.
     *
     * The human hit this immediately: they adjusted the colours, clicked
     * "บันทึกสีปัจจุบันเป็นชุด", applied the new preset, and the screen went
     * back to the OLD colours. Nothing was broken — the server snapshots
     * `company_theme_settings`, and the edits were still only in this form,
     * so the preset captured the pre-edit values and applying it restored
     * exactly those.
     *
     * There WAS a warning line about it. A warning is documentation of a
     * trap, not a fix: the button sits under the colour form, says "current
     * colours", and "current" plainly means the ones on screen. Making the
     * user perform a two-step dance the label never mentions is the design
     * being wrong, not the user misreading it.
     *
     * So the button now does both steps. `save()` is the SAME validated
     * write the main save button uses — the alternative (POSTing the form's
     * colours as a preset payload) was rejected in TASK-161 §3.2 because a
     * client-supplied colour blob bypasses the field validation entirely.
     *
     * Side effect, deliberate and stated on screen: saving a preset also
     * commits the pending edits to the live theme. That is what an admin who
     * pressed a button labelled "save the current colours" already believes
     * is happening.
     *
     * `save()` never throws — it traps into `saveError` (see its catch) — so
     * this checks the flag rather than relying on an exception.
     */
    await save()
    if (saveError.value) {
      presetsError.value = `บันทึกการตั้งค่าไม่สำเร็จ จึงยังไม่ได้สร้างชุดสี — ${saveError.value}`

      return
    }

    // Name (+ the company for a Super Admin) only — the server reads the
    // colours itself, and they are now the ones just written above.
    await api.post('/theme-presets', {
      name,
      ...presetCompanyPayload(),
      // TASK-217 — Super-Admin-only. Sent only when actually checked, so a
      // Company Admin's request is byte-for-byte what it was before this
      // task; the server strips the key for them regardless.
      ...(isSuperAdmin.value && newPresetShared.value ? { is_shared: true } : {}),
    })
    newPresetName.value = ''
    newPresetShared.value = false
    // Re-list rather than push the create response: the list endpoint is
    // the one shape this screen actually depends on.
    await loadPresets()
  } catch (e) {
    presetsError.value = presetErrorMessage(e, 'บันทึกชุดสีไม่สำเร็จ')
  } finally {
    savingPreset.value = false
  }
}

async function applyPendingPreset(): Promise<void> {
  const preset = pendingApplyPreset.value
  if (!preset) return
  applyingPreset.value = true
  presetsError.value = ''
  try {
    // §5.2 — a Super Admin states which company they are acting in; the
    // server refuses if the preset belongs to a different one, so this can
    // never silently theme the wrong tenant.
    await api.post(`/theme-presets/${preset.id}/apply`, presetCompanyPayload())
    // The apply endpoint writes company_theme_settings in one transaction
    // (§3.2); re-reading the theme is what makes the form + live preview
    // show the applied colours, and it works whatever that endpoint
    // chooses to return.
    await loadTheme()
    pendingApplyPreset.value = null
  } catch (e) {
    presetsError.value = presetErrorMessage(e, 'ใช้ชุดสีไม่สำเร็จ')
    pendingApplyPreset.value = null
  } finally {
    applyingPreset.value = false
  }
}

function startRenamePreset(preset: ThemePreset): void {
  // The button is not rendered for a preset this admin may not change;
  // this is the second check, so a future refactor that loses the v-if
  // cannot open an editor whose save is guaranteed to 422.
  if (!canEditPreset(preset)) return
  renamingPresetId.value = preset.id
  renameDraft.value = preset.name
}

function cancelRenamePreset(): void {
  renamingPresetId.value = null
  renameDraft.value = ''
}

async function submitRenamePreset(preset: ThemePreset): Promise<void> {
  const name = renameDraft.value.trim()
  if (!name || name === preset.name) {
    cancelRenamePreset()

    return
  }
  presetsError.value = ''
  try {
    await api.put(`/theme-presets/${preset.id}`, { name })
    cancelRenamePreset()
    await loadPresets()
  } catch (e) {
    presetsError.value = presetErrorMessage(e, 'เปลี่ยนชื่อชุดสีไม่สำเร็จ')
  }
}

async function deletePendingPreset(): Promise<void> {
  const preset = pendingDeletePreset.value
  if (!preset) return
  deletingPreset.value = true
  presetsError.value = ''
  try {
    await api.delete(`/theme-presets/${preset.id}`)
    presets.value = presets.value.filter((p) => p.id !== preset.id)
    pendingDeletePreset.value = null
  } catch (e) {
    presetsError.value = presetErrorMessage(e, 'ลบชุดสีไม่สำเร็จ')
    pendingDeletePreset.value = null
  } finally {
    deletingPreset.value = false
  }
}

// Switching company must re-list — otherwise a Super Admin would be looking
// at company A's presets while every other panel on the screen has moved to
// B, which is exactly the confusion §5.2 scopes them to avoid. Same
// watcher shape as the video / team-visibility sections above.
watch(() => activeCompany.companyId, () => loadPresets())

onMounted(loadPresets)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: Kanit, sans-serif;">
    <HeroHeader
      icon="sparkles"
      icon-color="text-brand-600"
      title="ตั้งค่าระบบ"
      subtitle="ธีม / แบรนด์ ของ Agent Portal และค่าตั้งวิดีโอของบริษัทคุณ"
      accent-color="brand"
      storage-key="admin-theme-settings"
    >
      <template #actions>
        <span v-if="saved" class="text-xs font-bold text-emerald-600 whitespace-nowrap">บันทึกแล้ว</span>
        <button
          type="button"
          :disabled="saving || loading"
          @click="save"
          class="px-4 py-2 rounded-xl bg-brand-600 text-white font-bold hover:bg-brand-700 shadow-sm text-sm whitespace-nowrap disabled:opacity-50"
        >
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกธีม/แบรนด์' }}
        </button>
      </template>

      <!--
        TASK-175 §4 — the four theme tabs. In HeroHeader's `#tabs` slot (which
        already draws the divider and keeps them inside the same card), so the
        tab bar sits directly under the ONE save button that commits all four
        of them. Same markup as CommissionPlansView's tab row.

        These switch the LEFT column only; the preview column is deliberately
        outside them and stays visible on every tab — that is the point of the
        whole exercise.
      -->
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto" role="tablist">
          <button
            v-for="t in THEME_TABS"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="activeTab === t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key"
          >
            <Icon :name="t.icon" :size="16" />
            {{ t.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <!--
      The theme save's failures, at the TOP.
      They used to sit beside a second copy of the save button at the very
      bottom of the left column — so on a page this tall, pressing the header
      button and failing showed the reason roughly 2,000px below the thing you
      pressed. The button is now in one place (the header) and so is its news.
    -->
    <div
      v-if="saveError || uploadError"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700"
    >
      {{ saveError || uploadError }}
    </div>

    <CompanyScopeNotice action="แก้ไขธีม/แบรนด์" />

    <p v-if="loadError" class="mt-4 text-sm font-bold text-rose-600">{{ loadError }}</p>

    <div v-if="loading && !activeCompany.requiresCompanyPick" class="mt-4 text-sm text-slate-400">กำลังโหลด...</div>

    <div v-else-if="!activeCompany.requiresCompanyPick" class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- ══════════ LEFT: editor (the four tab panels) ══════════
           TASK-175 §5 — `.theme-tab-panel` caps this column's height in `dvh`
           and lets it scroll on its own; see the <style> block at the bottom
           of this file for the cap and for the short-viewport escape hatch.

           `flex flex-col gap-4`, not `space-y-4`: a `v-show`-hidden section is
           still a sibling, and space-y's `> * + *` margin would leave a stray
           16px above the first card of every tab but the first. Flex `gap`
           ignores `display: none` children, so each panel starts flush with
           the preview beside it. -->
      <div class="theme-tab-panel flex flex-col gap-4">
        <!-- ══════════ TASK-162 — ONE colour card ══════════
             (human: "เลื่อนสีไปอยู่ใน card เดียวกันทั้งหมด ถ้าคุณจะแยก ใช้เส้นเป็นตัวแยก
             แบบนี้ทำให้ UI สับสน รวมถึงชุดสีที่บันทึกไว้ด้วย")

             สี, พื้นหลัง, ชุดสีที่บันทึกไว้ and the colour half of หน้าโหลด used to be
             four sibling cards. Sibling cards of equal weight read as unrelated
             topics, so nobody scans past the one they are in — twice a control was
             reported as "not there" when it was simply in the next card down (the
             nav-bar picker, then the app background).

             One card now, groups separated by a 0.5px rule with a persistent left
             label column. The rule alone is NOT the fix (§2.1): without the label
             it just chops one long list into anonymous chunks. ฟอนต์ deliberately
             keeps its own card below — a typeface is not a colour (§2). -->
        <!-- TAB "สี" — this one card already carries ชุดสีที่บันทึกไว้ at its
             foot (TASK-162), which is exactly §4's pairing for this tab. -->
        <section v-show="activeTab === 'colors'" class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-4">สี</h2>

          <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 pb-4">
            <h3 class="w-full sm:w-24 shrink-0 text-xs font-bold text-slate-900 sm:pt-1.5">แบรนด์</h3>
            <div class="flex-1 min-w-0 space-y-3">
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีหลัก (Primary)</label>
                <input v-model="primaryModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="primaryModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetPrimary" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ primaryHex ? 'รีเซ็ตเป็นค่าเริ่มต้น' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีรอง (Accent)</label>
                <input v-model="accentModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="accentModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetAccent" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ accentHex ? 'รีเซ็ตเป็นค่าเริ่มต้น' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-4 border-t border-slate-200" style="border-top-width: 0.5px">
            <h3 class="w-full sm:w-24 shrink-0 text-xs font-bold text-slate-900 sm:pt-1.5">แถบเมนู</h3>
            <div class="flex-1 min-w-0 space-y-3">
              <!-- TASK-161 §4 — nav bar: solid or gradient. Same select →
                   conditional-body ergonomics as the พื้นหลัง (app background)
                   section further down, and the gradient body is literally the
                   same GradientPicker component. -->
              <div class="space-y-3">
                <!-- ONE row, not two.
                     It shipped as a "พื้นหลังแถบเมนู" row holding only the
                     solid/gradient select, with a second "สีพื้นหลังแถบเมนู" row
                     below it holding the actual colour picker. Two rows whose
                     labels differ by one word read as one control, so the human
                     selected Solid and reasonably concluded there was nowhere
                     left to pick a colour — the picker was one row down, behind
                     a near-duplicate label.
                     The type select now sits INSIDE the colour row, so choosing
                     Solid never moves the thing you were looking for. -->
                <div class="flex items-center gap-3 flex-wrap">
                  <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีพื้นหลังแถบเมนู</label>
                  <select
                    v-model="navBgType"
                    class="px-2 py-1.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
                  >
                    <option value="solid">สีพื้น (Solid)</option>
                    <option value="gradient">ไล่สี (Gradient)</option>
                  </select>

                  <template v-if="navBgType === 'solid'">
                    <input v-model="navBgModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                    <input
                      v-model="navBgModel"
                      type="text"
                      class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                    />
                    <button type="button" @click="resetNavBg" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                      {{ navBg ? 'รีเซ็ต' : 'ค่าเริ่มต้น' }}
                    </button>
                  </template>
                </div>

                <div v-if="navBgType === 'gradient'" class="space-y-2">
                  <GradientPicker
                    v-model:color1="navGradientColor1"
                    v-model:color2="navGradientColor2"
                    v-model:angle="navGradientAngle"
                  />
                  <p class="text-xs text-slate-400 leading-relaxed">
                    ข้อความและไอคอนบนแถบเมนูต้องอ่านออกทั้งสองปลายของการไล่สี — ตรวจที่ตัวอย่างด้านขวาก่อนบันทึก
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีตัวอักษรแถบเมนู</label>
                <input v-model="navTextModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="navTextModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetNavText" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ navText ? 'รีเซ็ต' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีปุ่มเมนูด้านล่าง (ที่เลือกอยู่)</label>
                <input v-model="navActiveModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="navActiveModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetNavActive" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ navActive ? 'รีเซ็ต' : 'ตามสีหลัก' }}
                </button>
              </div>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-4 border-t border-slate-200" style="border-top-width: 0.5px">
            <h3 class="w-full sm:w-24 shrink-0 text-xs font-bold text-slate-900 sm:pt-1.5">การ์ด</h3>
            <div class="flex-1 min-w-0 space-y-3">
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีการ์ด/พื้นผิว</label>
                <input v-model="cardBgModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="cardBgModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetCardBg" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ cardBg ? 'รีเซ็ต' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีตัวอักษรการ์ด</label>
                <input v-model="cardTextModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input
                  v-model="cardTextModel"
                  type="text"
                  class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
                <button type="button" @click="resetCardText" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ cardText ? 'รีเซ็ต' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
              <!-- Card border -->
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">เส้นขอบการ์ด</label>
                <select v-model="cardBorderMode" class="px-2 py-1.5 rounded-lg border border-slate-200 text-sm">
                  <option value="default">ค่าเริ่มต้น</option>
                  <option value="none">ไม่มีเส้นขอบ</option>
                  <option value="custom">กำหนดสี</option>
                </select>
                <template v-if="cardBorderMode === 'custom'">
                  <input v-model="cardBorderColor" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                  <input v-model="cardBorderColor" type="text" class="w-24 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono" />
                </template>
              </div>
              <!-- Card shadow -->
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">เงาการ์ด</label>
                <select v-model="cardShadow" class="px-2 py-1.5 rounded-lg border border-slate-200 text-sm">
                  <option v-for="o in SHADOW_OPTIONS" :key="o.value" :value="o.value || null">{{ o.label }}</option>
                </select>
              </div>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-4 border-t border-slate-200" style="border-top-width: 0.5px">
            <h3 class="w-full sm:w-24 shrink-0 text-xs font-bold text-slate-900 sm:pt-1.5">พื้นหลังแอป</h3>
            <div class="flex-1 min-w-0 space-y-3">
              <select
                v-model="backgroundType"
                class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              >
                <option :value="null">ค่าเริ่มต้น</option>
                <option value="solid">สีพื้น (Solid)</option>
                <option value="gradient">ไล่สี (Gradient)</option>
                <option value="image">รูปภาพ (Image)</option>
              </select>

              <div v-if="backgroundType === 'solid'" class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-16">สีพื้น</label>
                <input v-model="solidColor" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input v-model="solidColor" type="text" class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono" />
              </div>

              <!-- TASK-161 §4 — this block used to be inline markup here; it was
                   extracted into GradientPicker so the nav bar's new gradient
                   control IS this control rather than a look-alike. Rendering
                   identically to before is the point (same labels, same swatch
                   sizes, same 0–360 slider). -->
              <GradientPicker
                v-else-if="backgroundType === 'gradient'"
                v-model:color1="gradientFrom"
                v-model:color2="gradientTo"
                v-model:angle="gradientAngle"
              />

              <div v-else-if="backgroundType === 'image'" class="space-y-3">
                <div v-if="theme?.background.image_url" class="rounded-xl border border-slate-200 h-24 bg-cover bg-center"
                     :style="{ backgroundImage: `url(${theme.background.image_url})` }"></div>
                <button
                  type="button"
                  :disabled="uploadingSlot === 'background'"
                  @click="triggerAssetPicker('background')"
                  class="btn-primary"
                >
                  {{ uploadingSlot === 'background' ? 'กำลังอัปโหลด...' : 'อัปโหลด/เปลี่ยนรูปพื้นหลัง' }}
                </button>
                <input
                  :ref="(el) => setAssetInput('background', el)"
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  class="hidden"
                  @change="(e) => onAssetSelected('background', e)"
                />
              </div>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-4 border-t border-slate-200" style="border-top-width: 0.5px">
            <h3 class="w-full sm:w-24 shrink-0 text-xs font-bold text-slate-900 sm:pt-1.5">หน้าโหลด</h3>
            <div class="flex-1 min-w-0 space-y-3">
              <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 w-28 shrink-0">สีพื้นหลัง</label>
                <input v-model="loadingBgModel" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
                <input v-model="loadingBgModel" type="text" class="w-28 px-2 py-1.5 rounded-lg border border-slate-200 text-xs font-mono" />
                <button type="button" @click="resetLoadingBg" class="text-xs font-bold text-slate-400 hover:text-slate-600">
                  {{ loadingBgHex ? 'รีเซ็ต' : 'ค่าเริ่มต้น' }}
                </button>
              </div>
              <div>
                <label class="text-xs font-bold text-slate-500 block mb-1">ข้อความหน้าโหลด</label>
                <input
                  v-model="loadingMessage"
                  type="text"
                  placeholder="กำลังโหลด..."
                  class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
                />
              </div>
            </div>
          </div>

          <!-- TASK-162 §2.2 — the presets block is separated MORE strongly than
               the five groups above (heavier rule + a surface tint that runs to
               the card edge). It is not a sixth setting: it is the thing that
               acts on all five, and at equal weight its "ใช้ชุดนี้" button reads
               as belonging to whatever row sits nearest. Last, because it saves
               what is above it. -->
          <div class="-mx-5 -mb-5 mt-4 px-5 py-4 rounded-b-2xl border-t-2 border-slate-200 bg-slate-50">
            <h3 class="text-xs font-bold text-slate-900 mb-1 flex items-center gap-1.5">
              <Icon name="palette" :size="14" class="text-brand-600" /> ชุดสีที่บันทึกไว้
            </h3>
            <template v-if="canManagePresets">
              <!-- TASK-163 — this used to warn that the snapshot came from
                   the SAVED values, not the ones on screen. The button now
                   saves first, so that caveat is gone; what replaces it
                   states the side effect instead, because the button doing
                   two things is the part a user could still be surprised by. -->
              <p class="text-xs text-slate-400 mb-4 leading-relaxed">
                กด "บันทึกสีปัจจุบันเป็นชุด" แล้วระบบจะ
                <span class="font-bold text-slate-500">บันทึกการตั้งค่าปัจจุบันลงระบบให้ด้วย</span>
                แล้วจึงเก็บเป็นชุด — ชุดสีที่ได้จะตรงกับสีที่เห็นอยู่บนหน้าจอเสมอ
              </p>

              <p v-if="presetsError" class="mb-3 text-xs font-bold text-rose-600">{{ presetsError }}</p>

              <!-- Save current colours as a preset -->
              <div class="flex items-center gap-2 mb-4">
                <input
                  ref="presetNameInput"
                  v-model="newPresetName"
                  type="text"
                  maxlength="60"
                  placeholder="ตั้งชื่อชุดสี เช่น โทนหลักบริษัท"
                  class="flex-1 min-w-0 px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
                  @keyup.enter="savePreset"
                />
                <button
                  type="button"
                  :disabled="savingPreset"
                  class="shrink-0 px-3 py-2 rounded-xl bg-brand-600 text-white font-bold text-xs hover:bg-brand-700 disabled:opacity-50"
                  @click="savePreset"
                >
                  {{ savingPreset ? 'กำลังบันทึก...' : 'บันทึกสีปัจจุบันเป็นชุด' }}
                </button>
              </div>

              <!-- TASK-217 — Super Admin only. Placed BELOW the name row and
                   above the list, because it modifies the button directly
                   above it: a control that changes who a save belongs to has
                   to be read before that save is pressed, not found
                   afterwards. Company Admins never see it (the server also
                   strips the flag for them). -->
              <label
                v-if="isSuperAdmin"
                class="flex items-start gap-2 mb-4 -mt-2 cursor-pointer select-none"
              >
                <input
                  v-model="newPresetShared"
                  type="checkbox"
                  class="mt-0.5 w-4 h-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                />
                <span class="text-xs leading-relaxed">
                  <span class="font-bold text-slate-700">บันทึกเป็นชุดกลาง — ใช้ร่วมกันทุกบริษัท</span>
                  <span class="block text-slate-400">
                    ชุดกลางจะขึ้นในรายการของทุกบริษัท และกด "ใช้ชุดนี้" ได้ทุกที่ ·
                    สีที่เก็บยังคงเป็นสีของบริษัทที่เลือกอยู่ตอนนี้ ·
                    เปลี่ยนชื่อหรือลบได้เฉพาะ Super Admin
                  </span>
                </span>
              </label>

              <p v-if="presetsLoading" class="text-xs text-slate-400">กำลังโหลด...</p>

              <EmptyState
                v-else-if="!presets.length"
                icon="palette"
                title="ยังไม่มีชุดสีที่บันทึกไว้"
                message='กด "บันทึกสีปัจจุบันเป็นชุด" เพื่อเก็บสีที่ใช้อยู่ตอนนี้ไว้เป็นจุดกลับ — ทดลองสีใหม่ได้โดยไม่ต้องกลัวหาสีเดิมไม่เจอ'
                cta-label="ตั้งชื่อชุดแรก"
                :cta-disabled="false"
                @cta="focusPresetName"
              />

              <ul v-else class="space-y-2">
                <li
                  v-for="preset in presets"
                  :key="preset.id"
                  class="flex items-center gap-3 bg-white/95 border border-slate-200 rounded-xl p-3"
                >
                  <!-- Swatch preview -->
                  <div class="flex items-center gap-1 shrink-0">
                    <span
                      v-for="swatch in presetSwatches(preset)"
                      :key="swatch.key"
                      :title="swatch.caption"
                      class="w-6 h-6 rounded-md border border-slate-200"
                      :style="{ background: swatch.background }"
                    ></span>
                  </div>

                  <!-- Name (inline rename) -->
                  <div class="flex-1 min-w-0">
                    <input
                      v-if="renamingPresetId === preset.id && canEditPreset(preset)"
                      v-model="renameDraft"
                      type="text"
                      maxlength="60"
                      class="w-full px-2 py-1 rounded-lg border border-brand-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
                      @keyup.enter="submitRenamePreset(preset)"
                      @keyup.esc="cancelRenamePreset"
                    />
                    <div v-else class="flex items-center gap-1.5 min-w-0">
                      <p class="text-sm font-bold text-slate-900 truncate">{{ preset.name }}</p>
                      <!-- TASK-164 §4 — mark a platform preset. A chip, not
                           a disabled bin icon: an action that is visible but
                           always fails is worse than one that is not offered.
                           Icon component, never an emoji (CI standard). -->
                      <span
                        v-if="preset.is_system"
                        :title="SYSTEM_PRESET_HINT"
                        class="shrink-0 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500 text-[11px] font-bold"
                      >
                        <Icon name="shield_check" :size="11" />
                        ชุดมาตรฐาน
                      </span>
                      <!-- TASK-217 — a palette every company shares. Its own
                           chip rather than a variant of the one above: they
                           answer different questions ("who made this" vs
                           "who else is using it") and a row can carry both. -->
                      <span
                        v-if="preset.is_shared"
                        :title="SHARED_PRESET_HINT"
                        class="shrink-0 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-brand-50 text-brand-600 text-[11px] font-bold"
                      >
                        <Icon name="globe" :size="11" />
                        ชุดกลาง
                      </span>
                    </div>
                  </div>

                  <!-- Actions -->
                  <div
                    v-if="renamingPresetId === preset.id && canEditPreset(preset)"
                    class="flex items-center gap-1 shrink-0"
                  >
                    <button
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-brand-600 text-white font-bold text-xs hover:bg-brand-700"
                      @click="submitRenamePreset(preset)"
                    >
                      บันทึกชื่อ
                    </button>
                    <button
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200"
                      @click="cancelRenamePreset"
                    >
                      ยกเลิก
                    </button>
                  </div>
                  <div v-else class="flex items-center gap-1 shrink-0">
                    <button
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-brand-600 text-white font-bold text-xs hover:bg-brand-700"
                      @click="pendingApplyPreset = preset"
                    >
                      ใช้ชุดนี้
                    </button>
                    <!-- TASK-164 §4 / TASK-217 — rename/delete only for a
                         preset THIS admin owns: not a system one, and not a
                         ชุดกลาง unless they are Super Admin. "ใช้ชุดนี้" above
                         stays for every preset — read-only is not unusable. -->
                    <template v-if="canEditPreset(preset)">
                      <button
                        type="button"
                        title="เปลี่ยนชื่อ"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100"
                        @click="startRenamePreset(preset)"
                      >
                        <Icon name="pencil" :size="14" />
                      </button>
                      <button
                        type="button"
                        title="ลบชุดสี"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"
                        @click="pendingDeletePreset = preset"
                      >
                        <Icon name="trash" :size="14" />
                      </button>
                    </template>
                  </div>
                </li>
              </ul>
            </template>

            <!-- TASK-161 §5.2 — a Super Admin now manages presets too, but
                 only inside one company, chosen with the picker at the top of
                 this page. The only state left where the section cannot act is
                 "the picker has not resolved a company yet". -->
            <p v-else class="text-xs text-slate-400 leading-relaxed">
              เลือกบริษัทที่ด้านบนของหน้าก่อน แล้วชุดสีของบริษัทนั้นจะแสดงที่นี่ —
              ชุดสีที่บันทึกไว้เป็นของแต่ละบริษัทแยกกัน จึงนำของบริษัทหนึ่งไปใช้กับอีกบริษัทไม่ได้
              ยกเว้น "ชุดกลาง" ที่ใช้ร่วมกันได้ทุกบริษัท
            </p>
          </div>
        </section>

        <!-- TAB "ฟอนต์และโลโก้" (1 of 2) — Font (split: Thai + Latin, category-filtered) -->
        <section v-show="activeTab === 'fontsLogos'" class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-4">ฟอนต์</h2>

          <!-- Category chip filter -->
          <div class="flex flex-wrap gap-1.5 mb-4">
            <button
              v-for="cat in CATEGORY_LABELS"
              :key="cat.key"
              type="button"
              @click="fontCategory = cat.key"
              :class="[
                'px-3 py-1 rounded-full text-xs font-bold border transition-colors',
                fontCategory === cat.key
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-slate-500 border-slate-200 hover:border-brand-300 hover:text-brand-600',
              ]"
            >
              {{ cat.label }}
            </button>
          </div>

          <!-- Thai font -->
          <div class="mb-4">
            <label class="text-xs font-bold text-slate-500 block mb-1">ฟอนต์ไทย (Header)</label>
            <select
              v-model="fontThai"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            >
              <option :value="null">— ใช้ฟอนต์เริ่มต้น —</option>
              <option v-for="f in thaiFonts" :key="f.name" :value="f.name" :style="{ fontFamily: `'${f.name}', sans-serif` }">
                {{ f.name }}
              </option>
            </select>
          </div>

          <!-- Latin / English font -->
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">ฟอนต์อังกฤษ / Latin (Header)</label>
            <select
              v-model="fontLatin"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            >
              <option :value="null">— ใช้ฟอนต์เริ่มต้น —</option>
              <option v-for="f in latinFonts" :key="f.name" :value="f.name" :style="{ fontFamily: `'${f.name}', sans-serif` }">
                {{ f.name }}
              </option>
            </select>
          </div>

          <p class="mt-3 text-xs text-slate-400">
            เลือกฟอนต์ไทยและอังกฤษแยกกัน — ตัวอย่างจะแสดงทันที (ตัวอักษรอังกฤษใช้ฟอนต์อังกฤษ ตัวไทยใช้ฟอนต์ไทย)
          </p>
        </section>

        <!-- TAB "ฟอนต์และโลโก้" (2 of 2) — Logos -->
        <section v-show="activeTab === 'fontsLogos'" class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-4">โลโก้</h2>
          <div class="grid grid-cols-2 gap-4">
            <div v-for="tile in logoTiles" :key="tile.slot" class="border border-slate-200 rounded-xl p-3">
              <p class="text-xs font-bold text-slate-500 mb-2">{{ tile.caption }}</p>
              <div class="h-16 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center mb-2 overflow-hidden">
                <img v-if="theme?.logos[tile.urlKey]" :src="theme.logos[tile.urlKey] as string" :alt="tile.caption" class="max-h-full max-w-full object-contain" />
                <Icon v-else name="image" :size="20" class="text-slate-300" />
              </div>
              <button
                type="button"
                :disabled="uploadingSlot === tile.slot"
                @click="triggerAssetPicker(tile.slot)"
                class="w-full px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 disabled:opacity-50"
              >
                {{ uploadingSlot === tile.slot ? 'กำลังอัปโหลด...' : (theme?.logos[tile.urlKey] ? 'เปลี่ยน' : 'อัปโหลด') }}
              </button>
              <input
                :ref="(el) => setAssetInput(tile.slot, el)"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/svg+xml"
                class="hidden"
                @change="(e) => onAssetSelected(tile.slot, e)"
              />
            </div>
          </div>
          <p class="mt-3 text-xs text-slate-400">รูปภาพ ไม่เกิน 5MB</p>
        </section>

        <!-- TASK-063 — branded Login link + QR for agents. Solves "หน้า
             Login ไม่เปลี่ยนสีตามธีม" (Login page doesn't paint this
             company's theme) — the Agent Portal /login page has no way to
             know which company a visitor belongs to before they log in,
             so it needs the ?company=<slug> hint carried in the URL. -->
        <!-- The login-link card USED TO SIT HERE, on the "อื่นๆ" tab. It now
             lives below the whole tab block — see its own comment there. -->

        <!--
          ONE CARD PER SUBJECT. Label and icon are two settings of the SAME
          menu, so they belong on the same row — as two cards the page listed
          every menu twice and an admin renaming a tab had to find it again in
          the other card to re-icon it.
        -->
        <!-- TAB "ชื่อและเมนู" — the only card on its tab. -->
        <section v-show="activeTab === 'naming'" class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-1">ชื่อแอปและเมนู</h2>
          <p class="text-xs text-slate-400 mb-4">เว้นว่างไว้ = ใช้ค่ามาตรฐาน</p>

          <!-- app_name is a label with no icon and no menu — its own field. -->
          <div class="mb-5">
            <label class="text-xs font-bold text-slate-500 block mb-1">ชื่อแอป</label>
            <input
              v-model="labels['app_name']"
              type="text"
              placeholder="Sync Vision Agent"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>

          <div class="space-y-3">
            <div
              v-for="f in MENU_ROWS"
              :key="f.key"
              class="rounded-xl border border-slate-200 p-3"
            >
              <p class="text-xs font-bold text-slate-500 mb-2">{{ f.caption }}</p>
              <input
                v-model="labels[f.key]"
                type="text"
                :placeholder="f.placeholder"
                class="w-full px-3 py-2 mb-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <!-- `label="ไอคอน"`, not the menu name: the row above already
                   says which menu this is, and repeating it inside the picker
                   is what made the page feel like two lists. -->
              <IconPicker
                v-model="icons[f.key]!"
                label="ไอคอน"
                :fallback-icon="f.fallback"
                :fallback-label="`มาตรฐาน (${f.fallback})`"
                :clear-label="`ใช้ไอคอนมาตรฐาน (${f.fallback})`"
              />
            </div>
          </div>
        </section>

        <!-- TASK-069 / ADR-020 — "จำนวนสินค้าแนะนำ" (recommended-row slot
             count, BR-7). Co-located here (Theme Settings) rather than
             the Banners tab since this travels through the SAME
             /company-theme endpoint as every other field on this page —
             see the Theme interface's comment above for the backend
             follow-up this needs before it actually persists. -->
        <section v-show="activeTab === 'other'" class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-1">หน้าร้าน (Storefront) — สินค้าแนะนำ</h2>
          <p class="text-xs text-slate-400 mb-4">
            จำนวนช่องสินค้าที่แสดงในแถว "แนะนำสำหรับคุณ" บน Agent Portal — ปักหมุดเองไม่ครบจำนวนนี้ ระบบจะเติมด้วยสินค้าขายดี (เกรด ABC) อัตโนมัติ
          </p>
          <div class="flex items-center gap-3">
            <label class="text-xs font-bold text-slate-500 shrink-0">จำนวนสินค้าแนะนำ</label>
            <input
              v-model.number="recommendedSlotCount"
              type="number"
              min="1"
              max="50"
              class="w-24 px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
        </section>

        <!--
          The second save button lived here and was REMOVED (human,
          2026-08-12). It called the same `save()` as the header's, so the page
          had one action with two triggers — and sitting directly under the
          "หน้าร้าน" card it read as that card's own save, which it never was.
          Every other card on this page owns exactly one endpoint and one
          button; this one broke the rule the others follow.
        -->
      </div>

      <!-- ══════════ RIGHT: live preview (sticky) ══════════ -->
      <div class="lg:sticky lg:top-20 self-start">
        <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
          <h2 class="text-sm font-bold text-slate-900 mb-4">ตัวอย่าง (Agent Portal)</h2>

          <!-- iPhone-shaped mock (≈19.5:9, Dynamic Island), mirroring the mobile Agent Portal shell. -->
          <div class="relative mx-auto w-[280px] h-[606px] rounded-[3rem] border-[11px] border-slate-900 overflow-hidden shadow-2xl flex flex-col" :style="previewBackgroundStyle">
            <!-- Dynamic Island -->
            <div class="absolute top-2 left-1/2 -translate-x-1/2 w-[86px] h-[26px] bg-black rounded-full z-20"></div>
            <!-- Top bar -->
            <div class="flex items-center gap-2 px-4 pt-9 pb-2.5 backdrop-blur border-b border-slate-200" :style="{ background: previewNavBackground }">
              <div class="w-7 h-7 rounded-lg flex items-center justify-center overflow-hidden" :style="{ background: previewPrimary }">
                <img v-if="theme?.logos.nav_url" :src="theme.logos.nav_url" alt="logo" class="w-full h-full object-contain" />
                <Icon v-else name="sparkles" :size="14" class="text-white" />
              </div>
              <span class="font-bold text-sm" :style="{ fontFamily: previewFontFamily, color: previewNavText }">{{ previewAppName }}</span>
            </div>

            <!-- Body (fills the phone; bottom nav pins to the base) -->
            <div class="flex-1 overflow-y-auto p-4 space-y-3" :style="{ fontFamily: previewFontFamily }">
              <div class="flex items-center gap-2">
                <button class="px-3 py-1.5 rounded-lg text-white font-bold text-xs" :style="{ background: previewPrimary }">
                  ปุ่มหลัก
                </button>
                <span class="px-2.5 py-1 rounded-full text-white font-bold text-[11px]" :style="{ background: previewAccent }">
                  Accent
                </span>
              </div>

              <div class="rounded-xl border p-3" :style="{ background: previewCardBg, color: previewCardText, borderColor: previewCardBorder, boxShadow: previewCardShadow }">
                <p class="text-sm font-bold" :style="{ color: previewCardText }">ตัวอย่างการ์ด</p>
                <p class="text-xs mt-1 leading-relaxed" :style="{ color: previewCardText, opacity: 0.75 }">
                  ข้อความตัวอย่างภาษาไทยสำหรับดูฟอนต์ที่เลือก 0123456789
                </p>
                <p class="text-xs mt-1 leading-relaxed" :style="{ color: previewCardText, opacity: 0.75 }">
                  The quick brown fox jumps over the lazy dog 0123456789
                </p>
              </div>

              <div class="flex items-center gap-2">
                <span class="text-[11px] text-slate-500 font-bold">หน้าโหลด:</span>
                <div class="w-16 h-8 rounded-lg border border-slate-200" :style="{ background: previewLoadingBg }"></div>
                <span class="text-[11px] text-slate-500 truncate">{{ loadingMessage || 'กำลังโหลด...' }}</span>
              </div>
            </div>

            <!-- Bottom nav (icon + label, mirroring the real 5-tab shell) -->
            <div class="flex items-stretch justify-around backdrop-blur border-t border-slate-200 px-1 py-1.5"
                 :style="{ fontFamily: previewFontFamily, background: previewNavBackground }">
              <div v-for="(label, i) in previewNavLabels" :key="i"
                   class="flex flex-col items-center gap-0.5 flex-1 min-w-0"
                   :style="i === 0 ? { color: previewNavActive } : { color: previewNavText, opacity: 0.6 }">
                <Icon :name="previewNavIcons[i] ?? 'home'" :size="16" />
                <span class="text-[9px] font-bold truncate max-w-full">{{ label }}</span>
              </div>
            </div>
          </div>

          <p class="mt-3 text-xs text-slate-400">
            ตัวอย่างนี้แสดงการเปลี่ยนแปลงแบบเรียลไทม์ (ยังไม่บันทึก) — ไม่กระทบหน้าตาแอปผู้ดูแล
          </p>
        </div>
      </div>
    </div>

    <!--
      OUTSIDE THE TABS ON PURPOSE (human, 2026-08-12: "เอาออกมาอยู่ใต้ tab
      theme ให้ผู้ใช้ copy ง่าย ไม่ต้องไปซ่อนใน Tab").

      Everything in the tabs above is something you EDIT and then save. This is
      the one thing on the page you come here to TAKE — the link an admin hands
      to their agents. Burying a copy-this-and-send-it action three tabs deep
      makes the most frequent visit to this screen the slowest one.

      It also has nothing to save: `?company=<slug>` is derived, so it belongs
      to no tab's form and its own `v-if="theme"` is the only condition it has
      ever needed — a company with no loaded theme has no link to show.
    -->
    <section v-if="theme" class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-5">
      <h2 class="text-sm font-bold text-slate-900 mb-1">ลิงก์ Login สำหรับตัวแทน</h2>
      <p class="text-xs text-slate-400 mb-4">
        ส่งลิงก์นี้ (หรือให้สแกน QR) ให้ตัวแทนของบริษัทนี้ เพื่อให้หน้า Login แสดงสีธีมที่ตั้งไว้ตั้งแต่ก่อนเข้าสู่ระบบ
      </p>
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <input
              :value="loginLink ?? '—'"
              readonly
              type="text"
              class="flex-1 min-w-0 px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono bg-slate-50 text-slate-600"
              @focus="($event.target as HTMLInputElement).select()"
            />
            <button
              type="button"
              :disabled="!loginLink"
              @click="copyLoginLink"
              class="shrink-0 px-3 py-2 rounded-xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 disabled:opacity-50"
            >
              {{ loginLinkCopied ? 'คัดลอกแล้ว' : 'คัดลอกลิงก์' }}
            </button>
          </div>
          <p v-if="!loginLink" class="mt-2 text-xs text-rose-500">บริษัทนี้ยังไม่มี slug — ไม่สามารถสร้างลิงก์ได้</p>
        </div>
        <div v-if="loginLinkQr" class="shrink-0 flex flex-col items-center gap-2">
          <img :src="loginLinkQr" alt="QR โค้ดลิงก์ Login" class="w-28 h-28 rounded-lg border border-slate-200" />
          <button type="button" @click="downloadLoginLinkQr" class="text-xs font-bold text-brand-600 hover:text-brand-700">
            ดาวน์โหลด QR
          </button>
        </div>
      </div>
    </section>

    <!-- TASK-161 §4 — applying a preset OVERWRITES every colour field, so it
         confirms first. TASK-066 replaced every window.confirm() in this app
         with this component; both dialogs live INSIDE <main> because a
         sibling would make this template a multi-root Fragment and break
         App.vue's <Transition mode="out-in"> (same fix as AnnouncementsView). -->
    <ConfirmDialog
      :show="pendingApplyPreset !== null"
      variant="warning"
      :busy="applyingPreset"
      title="ใช้ชุดสีนี้?"
      :body="
        pendingApplyPreset
          ? `ระบบจะเขียนทับสีทั้งหมดของธีมปัจจุบันด้วยชุด “${pendingApplyPreset.name}” — สีหลัก สีรอง แถบเมนู การ์ด และพื้นหลัง (โลโก้ ฟอนต์ และข้อความไม่เปลี่ยน) สีเดิมที่ยังไม่ได้บันทึกเป็นชุดจะหายไป`
          : ''
      "
      @confirm="applyPendingPreset"
      @update:show="(v) => { if (!v) pendingApplyPreset = null }"
    />

    <ConfirmDialog
      :show="pendingDeletePreset !== null"
      variant="danger"
      :busy="deletingPreset"
      title="ลบชุดสีนี้?"
      :body="pendingDeletePreset ? `ยืนยันลบชุดสี “${pendingDeletePreset.name}” — ธีมที่ใช้อยู่ตอนนี้ไม่เปลี่ยน` : ''"
      @confirm="deletePendingPreset"
      @update:show="(v) => { if (!v) pendingDeletePreset = null }"
    />
  </main>
</template>

<style scoped>
/*
 * ══════════ TASK-175 §5 — how the panel is capped ══════════
 *
 * The editor column gets a height budget and scrolls inside it, so the page
 * itself does not have to scroll to reach the bottom of a tab. That is what
 * keeps the live preview and the single save button on screen while you edit
 * (human: "อยากให้ปรับอยู่ในแค่ 1 หน้าจอ คำนวนปรับตาม % ความสูงหน้าจอ").
 *
 * `dvh`, NOT `vh` — `vh` is measured against the LARGEST viewport, i.e. with
 * a mobile browser's URL bar collapsed, so a `vh` budget is too tall whenever
 * that bar is showing and the bottom of the panel is cut off. `dvh` tracks the
 * viewport that actually exists right now.
 *
 * BOTH GUARDS ARE DELIBERATE, and the cap only exists inside them:
 *
 *  - `min-height: 800px` — below that, NO cap: the page scrolls normally, just
 *    as it did before this task. On a 13" laptop (~740px of viewport) a capped
 *    panel is barely 400px tall, which is worse than what we started with.
 *    Better on 2K must not be paid for by worse on a laptop (§5).
 *  - `min-width: 1024px` (Tailwind `lg`) — below that the preview stacks BELOW
 *    the editor instead of beside it, so there is nothing to keep in view and
 *    an inner scroll region would only trap the reader's thumb.
 *
 * A browser without `dvh` drops the max-height declaration, is left with
 * `overflow-y: auto` on a container that never exceeds its content, and so
 * simply keeps today's behaviour. Failing back to "the page scrolls" is the
 * only safe direction to fail here.
 *
 * 17rem (272px) is the page chrome ABOVE this column at that width: the sticky
 * admin nav (~80px), <main>'s py-6 (24px), the HeroHeader card with its tab row
 * (~130px) and the 16px gap under it. It is intentionally a slight
 * over-estimate — running a little short leaves a strip of background, running
 * long puts the save button off screen, and only one of those is a bug.
 */
@media (min-width: 1024px) and (min-height: 800px) {
  .theme-tab-panel {
    max-height: calc(100dvh - 17rem);
    overflow-y: auto;
    /* Keeps the scrollbar off the colour swatches at the row ends. */
    padding-right: 0.25rem;
  }
}
</style>
