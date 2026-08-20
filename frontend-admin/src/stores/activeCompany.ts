import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

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

const STORAGE_KEY = 'sva.admin.activeCompanyId'

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
  const selectedId = ref<number | null>(readPersisted())

  function readPersisted(): number | null {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw === null || raw === '' || raw === 'all') return null
      const n = Number(raw)

      return Number.isFinite(n) ? n : null
    } catch {
      // Private-mode Safari and friends throw on localStorage access.
      return null
    }
  }

  function persist(value: number | null): void {
    try {
      if (value === null) localStorage.setItem(STORAGE_KEY, 'all')
      else localStorage.setItem(STORAGE_KEY, String(value))
    } catch {
      // Non-fatal: the picker still works for this session.
    }
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
    scopedPath,
    loadCompanies,
  }
})
