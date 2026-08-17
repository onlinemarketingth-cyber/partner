<script setup lang="ts">
/**
 * TeamVisibilitySettingsView (Admin app) — "การมองเห็นข้อมูลทีม" (TASK-202).
 *
 * Relocated out of ThemeSettingsView's "PER-COMPANY SETTINGS ROW" into its
 * own submenu page under "ตั้งค่าระบบ" (human request, 2026-08-17: these
 * cards are not theme/branding and each deserves its own findable menu
 * item, same as "ธีม / แบรนด์" and "ตั้งค่า Email SMTP").
 *
 * Lineage (state/behavior unchanged, only the page it lives on moved):
 *  - TASK-108 / ADR-024 §5 — BR-7: how much of a subordinate's client data
 *    a team leader may see is a per-company decision, never hardcoded.
 *    Original card + save button write to GET|PUT /team-visibility-settings.
 *  - TASK-175 §3 D2 (human decision): kept as its own card with its own
 *    save button, separate from the theme form's `PUT /company-theme`.
 *
 * `TEAM_VISIBILITY_DEFAULTS` pre-selects the SAFE `counts_only` value —
 * ADR-024 §5: the backend resolves an unconfigured tenant to `counts_only`
 * (fail CLOSED), so the form must never render with no radio selected.
 * DO NOT change this default when touching this file.
 *
 * Same Super Admin company-picker pattern this codebase already repeats on
 * ThemeSettingsView / ProductCatalogView / AcademyManagementView — kept
 * duplicated here rather than extracted (scope creep beyond TASK-202).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

interface CompanyItem {
  id: number
  name: string
  slug: string
}

// Super Admin company picker.
const companies = ref<CompanyItem[]>([])
const selectedCompanyId = ref<number | null>(null)
const companiesError = ref('')

async function loadCompanies(): Promise<void> {
  try {
    const res = await api.get<{ data: CompanyItem[] }>('/companies')
    companies.value = res.data
    const first = res.data[0]
    if (first) {
      selectedCompanyId.value = first.id
    }
  } catch (e) {
    companiesError.value = e instanceof ApiError ? e.message : 'โหลดรายชื่อบริษัทไม่สำเร็จ'
  }
}

/*
 * TASK-108 / ADR-024 §5 — "การมองเห็นข้อมูลทีม (หัวหน้าทีม)".
 *
 * BR-7: how much of a subordinate's client data a team leader may see is a
 * per-company decision, never hardcoded. Its own card + its own save button
 * for the same reason the video card has them — a different endpoint
 * (GET|PUT /team-visibility-settings), and one "บันทึก" that PUTs to several
 * APIs makes a half-failed save impossible to diagnose.
 *
 * The Super Admin company picker at the top of the page is REUSED, exactly
 * as the video card does it.
 */
type TeamVisibilityLevel = 'counts_only' | 'names' | 'full_file'

interface TeamVisibilitySettings {
  client_visibility_level: TeamVisibilityLevel
  is_enabled: boolean
}

/*
 * ADR-024 §5 — the SAFE value is pre-selected, deliberately.
 *
 * The backend resolves a company with no row (or with is_enabled = false) to
 * `counts_only`: an unconfigured tenant must fail CLOSED. If this form
 * rendered with no radio selected, the first admin to press "บันทึก" without
 * reading the options would write whatever the browser happened to submit,
 * and a blank control also reads as "nothing is restricting this yet" — the
 * opposite of the truth. Starting on `counts_only` + enabled makes the form
 * show the level that is actually in force before anyone has configured it.
 */
const TEAM_VISIBILITY_DEFAULTS: TeamVisibilitySettings = {
  client_visibility_level: 'counts_only',
  is_enabled: true,
}

// Plain-language consequences, written for a non-technical admin: each line
// says what the LEADER will see, not what the API returns.
const TEAM_VISIBILITY_OPTIONS: { value: TeamVisibilityLevel; label: string; consequence: string }[] = [
  {
    value: 'counts_only',
    label: 'เห็นแค่ตัวเลขสรุป',
    consequence: 'เห็นแค่จำนวนและสถานะ ไม่เห็นชื่อลูกค้า (ปลอดภัยที่สุด)',
  },
  {
    value: 'names',
    label: 'เห็นชื่อลูกค้า',
    consequence: 'เห็นชื่อลูกค้าและสถานะดีล แต่ไม่เห็นเบอร์ เลขบัตรประชาชน หรือข้อมูลสุขภาพ',
  },
  {
    value: 'full_file',
    label: 'เห็นแฟ้มลูกค้าเต็ม',
    consequence: 'เห็นแฟ้มลูกค้าเต็ม — ชื่อ เบอร์ เลขบัตรประชาชน เอกสาร และข้อมูลสุขภาพ เหมือนที่ลูกทีมเห็นทุกอย่าง',
  },
]

const teamVisibilityForm = ref<TeamVisibilitySettings>({ ...TEAM_VISIBILITY_DEFAULTS })
const loadingTeamVisibility = ref(false)
const savingTeamVisibility = ref(false)
const teamVisibilityError = ref('')
const teamVisibilitySaved = ref(false)

async function loadTeamVisibilitySettings(): Promise<void> {
  if (isSuperAdmin.value && !selectedCompanyId.value) return
  loadingTeamVisibility.value = true
  teamVisibilityError.value = ''
  try {
    const path = isSuperAdmin.value
      ? `/team-visibility-settings?company_id=${selectedCompanyId.value}`
      : '/team-visibility-settings'
    const res = await api.get<{ data: Partial<TeamVisibilitySettings> }>(path)
    // Spread over the defaults rather than replacing: a company with no row
    // must still render as counts_only + enabled, never as empty radios.
    teamVisibilityForm.value = { ...TEAM_VISIBILITY_DEFAULTS, ...res.data }
  } catch (e) {
    teamVisibilityError.value = e instanceof ApiError
      ? `โหลดค่าการมองเห็นข้อมูลทีมไม่สำเร็จ (${e.status})`
      : 'โหลดค่าการมองเห็นข้อมูลทีมไม่สำเร็จ'
  } finally {
    loadingTeamVisibility.value = false
  }
}

async function saveTeamVisibilitySettings(): Promise<void> {
  if (isSuperAdmin.value && !selectedCompanyId.value) {
    teamVisibilityError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'

    return
  }
  savingTeamVisibility.value = true
  teamVisibilityError.value = ''
  teamVisibilitySaved.value = false
  try {
    // company_id is only accepted from a Super Admin; for a Company Admin the
    // backend ignores it entirely and scopes the write to their own row (BR-6).
    await api.put('/team-visibility-settings', {
      ...(isSuperAdmin.value ? { company_id: selectedCompanyId.value } : {}),
      ...teamVisibilityForm.value,
    })
    teamVisibilitySaved.value = true
    setTimeout(() => (teamVisibilitySaved.value = false), 2000)
  } catch (e) {
    teamVisibilityError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingTeamVisibility.value = false
  }
}

watch(selectedCompanyId, () => loadTeamVisibilitySettings())
onMounted(loadTeamVisibilitySettings)

onMounted(() => {
  if (isSuperAdmin.value) loadCompanies()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="users"
      icon-color="text-brand-600"
      title="การมองเห็นข้อมูลทีม"
      subtitle="กำหนดว่าหัวหน้าทีมเห็นข้อมูลลูกค้าของลูกทีมได้แค่ไหน"
      accent-color="brand"
      storage-key="admin-team-visibility-settings"
    />

    <!-- Super Admin company picker -->
    <div v-if="isSuperAdmin" class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-4 flex items-center gap-3">
      <Icon name="building" :size="18" class="text-brand-600 shrink-0" />
      <label class="text-xs font-bold text-slate-500 shrink-0">บริษัท</label>
      <select
        v-model.number="selectedCompanyId"
        class="flex-1 max-w-xs px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>
    <p v-if="companiesError" class="mt-2 text-xs font-bold text-rose-600">{{ companiesError }}</p>

    <section class="mt-4 max-w-2xl bg-white/95 border border-slate-200 rounded-2xl p-5">
      <p class="text-base font-bold text-slate-500 mb-1 flex items-center gap-1.5">
        <Icon name="users" :size="14" /> การมองเห็นข้อมูลทีม (หัวหน้าทีม)
      </p>
      <p class="text-xs text-slate-400 mb-3 leading-relaxed">
        กำหนดว่าหัวหน้าทีมจะเห็นข้อมูลลูกค้าของลูกทีมได้มากแค่ไหน ในหน้า "ทีมของฉัน" บน Agent Portal
        — หัวหน้าทีมดูได้อย่างเดียว แก้ไขข้อมูลลูกค้าของลูกทีมไม่ได้
        (BR-7 — ค่านี้เป็น config ที่แก้ไขได้เสมอ ไม่ hardcode)
      </p>

      <p v-if="teamVisibilityError" class="mb-2 text-xs font-bold text-rose-600">{{ teamVisibilityError }}</p>

      <div v-if="!isSuperAdmin || selectedCompanyId">
        <p v-if="loadingTeamVisibility" class="text-xs text-slate-400">กำลังโหลด...</p>
        <form v-else class="space-y-4" @submit.prevent="saveTeamVisibilitySettings">
          <!-- Master switch -->
          <div class="flex items-start gap-3">
            <button
              type="button"
              @click="teamVisibilityForm.is_enabled = !teamVisibilityForm.is_enabled"
              class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
              :class="teamVisibilityForm.is_enabled ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
              :title="teamVisibilityForm.is_enabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน'"
            >
              <div
                class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
                :class="teamVisibilityForm.is_enabled ? 'translate-x-7' : 'translate-x-0'"
              ></div>
            </button>
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900">เปิดหน้า "ทีมของฉัน" ให้หัวหน้าทีม</p>
              <p class="text-xs text-slate-400 leading-relaxed">
                ปิดอยู่ = หัวหน้าทีมจะไม่เห็นหน้าทีมเลย (ไม่เห็นแม้แต่จำนวนลูกค้าของลูกทีม)
              </p>
            </div>
          </div>

          <!-- Visibility level. Dimmed + disabled while the master switch is
               off: the level has no effect at all in that state. -->
          <div :class="teamVisibilityForm.is_enabled ? '' : 'opacity-50'">
            <p class="text-xs font-bold text-slate-500 mb-2">หัวหน้าทีมเห็นข้อมูลลูกค้าของลูกทีมได้แค่ไหน</p>
            <div class="space-y-2">
              <label
                v-for="opt in TEAM_VISIBILITY_OPTIONS"
                :key="opt.value"
                class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer transition-colors"
                :class="teamVisibilityForm.client_visibility_level === opt.value
                  ? 'border-brand-300 bg-brand-50'
                  : 'border-slate-200 hover:border-brand-200'"
              >
                <input
                  v-model="teamVisibilityForm.client_visibility_level"
                  type="radio"
                  :value="opt.value"
                  :disabled="!teamVisibilityForm.is_enabled"
                  class="mt-0.5 shrink-0"
                />
                <span class="min-w-0">
                  <span class="block text-sm font-bold text-slate-900">{{ opt.label }}</span>
                  <span class="block text-xs text-slate-500 leading-relaxed">{{ opt.consequence }}</span>

                  <!-- PDPA warning — always rendered (not only when selected)
                       so the admin reads the consequence BEFORE choosing it.
                       Semantic danger colour, per the Admin design standards:
                       the colour carries meaning, it is not decoration. -->
                  <span
                    v-if="opt.value === 'full_file'"
                    class="mt-2 flex items-start gap-2 rounded-lg border border-rose-300 bg-rose-50 p-2.5"
                  >
                    <Icon name="alert" :size="16" class="text-rose-600 shrink-0 mt-0.5" />
                    <span class="min-w-0">
                      <span class="block text-xs font-bold text-rose-700">คำเตือน PDPA — ข้อมูลอ่อนไหว</span>
                      <span class="block text-xs text-rose-600 leading-relaxed">
                        ระดับนี้เปิดเผย "ข้อมูลสุขภาพ" ของลูกค้า ซึ่งเป็นข้อมูลอ่อนไหวตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล (PDPA)
                        ให้หัวหน้าทีมเห็น และทุกครั้งที่หัวหน้าทีมเปิดแฟ้มลูกค้า ระบบจะบันทึกการเข้าดูไว้ใน Audit Log
                        (ใคร เปิดดูลูกค้าของใคร เมื่อไร) เลือกระดับนี้เฉพาะเมื่อบริษัทมีฐานทางกฎหมายรองรับแล้วเท่านั้น
                      </span>
                    </span>
                  </span>
                </span>
              </label>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2">
            <span v-if="teamVisibilitySaved" class="text-xs font-bold text-emerald-600">บันทึกแล้ว</span>
            <button type="submit" :disabled="savingTeamVisibility" class="btn-primary">
              {{ savingTeamVisibility ? 'กำลังบันทึก...' : 'บันทึกการมองเห็นทีม' }}
            </button>
          </div>
        </form>
      </div>
      <p v-else class="text-xs text-slate-400">เลือกบริษัทด้านบนก่อน</p>
    </section>
  </main>
</template>
