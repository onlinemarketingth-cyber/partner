import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { ACTIVE_COMPANY_STORAGE_KEY, readPersistedActiveCompanyId } from '@/utils/activeCompanyStorage'
import { writeStored } from '@/utils/safeStorage'

/**
 * activeCompany — TASK-208 / ADR-038: the ONE "which company am I working
 * in" answer for the whole Admin app.
 *
 * Human, 2026-08-19: "ในฐานะ Super Admin ผมแยกไม่ออกเลยกำลังแก้สินค้าจาก
 * บริษัทไหน ควรจะมีการเลือกบริษัทที่ทำงานอยู่บน Head ... และทำให้ทั้งระบบมี
 * การปรับตาม และแสดงชื่อบริษัทจะได้ทำงานได้ถูกต้อง".
 *
 * Before this, ten separate views each had their own company <select>, none
 * of them shared state, and none of them survived a route change — so a
 * Super Admin could (and did) end up editing a product without knowing whose
 * it was. The picker now lives once in AdminNavigation and every screen reads
 * this store.
 *
 * Mode (human's answer, "1+2"): a specific company is the working scope AND
 * `null` = "ทุกบริษัท" stays available as a read-across view. Screens that
 * CREATE something must therefore still refuse while `companyId` is null —
 * `requiresCompanyPick` below is that check, so the message is identical
 * everywhere.
 *
 * Security note: this is UI convenience only. Every request is still
 * authorized by Policy + TenantScope server-side (BR-6, Section 5) — picking
 * a company here cannot grant access to anything the actor could not already
 * reach, and for a Company Admin the store simply mirrors their own company.
 */
export interface CompanyOption {
  id: number
  name: string
  /**
   * TASK-208 regression fix — ThemeSettingsView loads a company's theme from
   * GET /public/theme/{slug}, which is a SLUG route, not an id route. The
   * local list this store replaced carried the slug; dropping it here made
   * `selectedCompany.slug` undefined and the Super Admin's theme page
   * silently return before it fetched anything. CompanyResource has always
   * sent the field, so this only ever needed declaring.
   */
  slug: string
}

// TASK-226 — the key moved to a leaf module so api/client.ts can read the
// same value without importing this store (which imports the client, and
// would therefore be a cycle). This store is still the only WRITER.
const STORAGE_KEY = ACTIVE_COMPANY_STORAGE_KEY

export const useActiveCompanyStore = defineStore('activeCompany', () => {
  const auth = useAuthStore()

  const companies = ref<CompanyOption[]>([])
  const loaded = ref(false)
  const loadError = ref('')

  const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

  /**
   * null = ทุกบริษัท. Only ever meaningful for a Super Admin — for anyone
   * else the getter below pins it to their own company, because TenantScope
   * has already made every other value a lie.
   */
  // Reading is shared with api/client.ts (TASK-226) so the two can never
  // disagree about what 'all' means.
  const selectedId = ref<number | null>(readPersistedActiveCompanyId())

  /**
   * Writes go through safeStorage for the same reason the read does: a
   * storage that exists but cannot be written to must cost the user their
   * remembered choice, not their click. `writeStored` already swallows the
   * quota / disabled-storage cases, so there is no try/catch here to keep
   * in step with it.
   */
  function persist(value: number | null): void {
    writeStored(STORAGE_KEY, value === null ? 'all' : String(value))
  }

  /** The company every screen should scope to. null = ทุกบริษัท (Super Admin only). */
  const companyId = computed<number | null>(() =>
    isSuperAdmin.value ? selectedId.value : (auth.user?.company?.id ?? null))

  const isAllCompanies = computed(() => isSuperAdmin.value && selectedId.value === null)

  const companyName = computed<string | null>(() => {
    if (!isSuperAdmin.value) return auth.user?.company?.name ?? null
    if (selectedId.value === null) return null

    return companies.value.find((c) => c.id === selectedId.value)?.name ?? null
  })

  /**
   * True when a create action must be blocked and the user told to pick a
   * company first. Company Admin never hits this (their company is implied
   * server-side), which is why it is not simply `companyId === null`.
   */
  const requiresCompanyPick = computed(() => isSuperAdmin.value && selectedId.value === null)

  /**
   * TASK-209 P2 — append the scope to a list endpoint's query string.
   *
   * Opt-in per call site rather than a global fetch interceptor: platform
   * endpoints (/companies, /catalog-*, /platform-mail-settings) must NOT be
   * narrowed, and some endpoints already use company_id with a different
   * meaning. An allowlist by construction is safer than an exclusion list
   * someone has to remember to extend.
   *
   * Server-side narrowing matters because these endpoints paginate: filtering
   * a page of results in the browser silently drops rows (TASK-202).
   */
  function scopedPath(path: string): string {
    if (companyId.value === null) return path

    return `${path}${path.includes('?') ? '&' : '?'}company_id=${companyId.value}`
  }

  /*
   * ── ASKING BEFORE THE SCOPE MOVES (2026-09-04, human decision) ──
   *
   * Some screens are a RECORD, not a list: the product editor, a client's
   * file. Switching company under one of those changes what the page is
   * about while somebody is halfway through typing into it, and until now it
   * happened silently — the header said one company and the form on screen
   * belonged to another.
   *
   * The human's call: switching still wins, but ASK first when there is
   * unsaved work. "แก้ไขต่อ" must then leave the picker showing the company
   * the page actually belongs to, which is why the guard runs BEFORE the
   * value is written rather than as an undo afterwards.
   *
   * ONE guard, not a list: only one view is mounted on a record at a time,
   * and a stack would silently keep a stale entry the day one forgets to
   * release. Registering twice is a bug, so the second registration
   * replaces the first and the release only clears its own.
   */
  let switchGuard: (() => boolean | Promise<boolean>) | null = null

  function guardSwitch(guard: () => boolean | Promise<boolean>): void {
    switchGuard = guard
  }

  function releaseSwitch(guard: () => boolean | Promise<boolean>): void {
    if (switchGuard === guard) switchGuard = null
  }

  /**
   * The only entry point a UI control should use.
   *
   * Returns false when the guard refused, so the caller can leave its own
   * state (an open dropdown, a highlighted row) exactly as it was — nothing
   * was changed.
   */
  async function requestCompany(id: number | null): Promise<boolean> {
    if (id === companyId.value) return true
    if (switchGuard && !(await switchGuard())) return false

    setCompany(id)

    return true
  }

  function setCompany(id: number | null): void {
    selectedId.value = id
    persist(id)
  }

  /** Idempotent — safe to call from every view's onMounted. */
  async function loadCompanies(): Promise<void> {
    if (!isSuperAdmin.value || loaded.value) return
    try {
      const res = await api.get<{ data: CompanyOption[] }>('/companies')
      companies.value = res.data
      loaded.value = true

      // A persisted id for a company that has since been deleted (or that
      // this actor can no longer see) must not stick around silently.
      if (selectedId.value !== null && !companies.value.some((c) => c.id === selectedId.value)) {
        setCompany(null)
      }
    } catch (e) {
      loadError.value = e instanceof ApiError
        ? `โหลดรายชื่อบริษัทไม่สำเร็จ (${e.status})`
        : 'โหลดรายชื่อบริษัทไม่สำเร็จ'
    }
  }

  return {
    companies,
    loaded,
    loadError,
    isSuperAdmin,
    selectedId,
    companyId,
    isAllCompanies,
    companyName,
    requiresCompanyPick,
    setCompany,
    // 2026-09-04 — every UI control switches through requestCompany();
    // setCompany stays exported because loadCompanies() calls it to drop a
    // stale persisted id, which must not be refusable by a view's guard.
    requestCompany,
    guardSwitch,
    releaseSwitch,
    scopedPath,
    loadCompanies,
  }
})
