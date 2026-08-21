<script setup lang="ts">
/**
 * AgentRosterView — "รายชื่อตัวแทน" sub-page of "จัดการตัวแทน" (TASK-204).
 *
 * Split out of AgentManagementView.vue, which used to hold 5 tabs behind one
 * `activeTab` ref sharing a single roster fetch. This page owns the two that
 * ag-lead ruled should MERGE rather than become 2 separate routes — "ใช้งาน
 * อยู่" and "ปิดใช้งาน" are the SAME roster (one `loadAgents()` call), filtered
 * client-side by `rosterFilter` exactly like the old page's `filteredAgents`
 * computed already did. A second fetch just to show the same data filtered
 * the other way would be wasteful and was explicitly rejected.
 *
 * Everything else that lived only inside the roster's per-row "แก้ไข" flow
 * travelled here too: the create-agent form, <AgentEditModal> (and therefore
 * the "grant cert without exam" panel, which only ever rendered inside that
 * modal), and the `?q=`/`?edit=` deep-link handling (TASK-126/128 — kept as a
 * capability even though nothing currently links to it, per its own original
 * comment).
 *
 * `certTiers`/`certifications` also live here and only here: their one
 * consumer, the modal's "grant cert without exam" panel (section 5), only
 * ever opens from this page.
 *
 * `inviteLinks` is loaded HERE TOO (a second copy of the same
 * /agent-invite-links fetch AgentInviteLinksView.vue also makes) — a
 * deliberate judgment call, not an oversight. <AgentEditModal> renders a
 * "ลิงก์ชวนเข้าทีมที่สร้างไว้: N ลิงก์" line + a "ดูในแท็บ ลิงก์ชวนทีม" shortcut
 * whenever it is HANDED a company-wide link list at all (`v-if="inviteLinks"`
 * — see that component's own props doc: ABSENT means "this host has no
 * idea" and the line hides). Leaving it unset here would silently drop that
 * panel from the modal. This is a different endpoint from /users
 * (roster/active/inactive), so it is not the "fetch the same list twice"
 * case ag-lead ruled out above.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import SuccessDialog from '@/design-system/components/SuccessDialog.vue'
import AgentEditModal from './AgentEditModal.vue'
import {
  type AgentItem,
  type IdDocumentTypeChoice,
  ID_DOCUMENT_TYPE_OPTIONS,
  fetchAllPages,
  idNumberInputMode,
  idNumberMaxLength,
  idNumberPlaceholder,
  normalizeIdNumber,
} from './agentEdit'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

interface CompanyOption {
  id: number
  name: string
}
// TASK-058/061 — reused for the "grant cert without exam" panel inside
// <AgentEditModal>.
interface CertTierOption {
  id: number
  key: string
  name: string
}
interface UserCertificationItem {
  id: number
  user_id: number
  cert_tier: CertTierOption | null
}
/** Only what <AgentEditModal>'s `inviteLinks` prop needs — see file docblock. */
interface AgentInviteLinkRef {
  agent_id: number
}

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const route = useRoute()
const router = useRouter()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const agents = ref<AgentItem[]>([])
const companies = ref<CompanyOption[]>([])
const certTiers = ref<CertTierOption[]>([])
const certifications = ref<UserCertificationItem[]>([])

// TASK-060 — search (name/phone/email partial; national_id exact via
// blind-index hash), same query-param contract as ClientController's
// /clients search (TASK-049) — see UserController::index.
const q = ref('')
const nationalIdSearch = ref('')
const searching = ref(false)

// TASK-060 — mirrors ClientManagementView's buildClientsPath(): empty
// search params are simply omitted, so an empty search box still loads
// the full (unfiltered) roster.
function buildUsersPath(): string {
  const params = new URLSearchParams()
  params.set('include_inactive', '1')
  if (q.value.trim()) params.set('q', q.value.trim())
  if (nationalIdSearch.value.trim()) params.set('national_id', nationalIdSearch.value.trim())
  return `/users?${params.toString()}`
}

async function loadAgents() {
  loading.value = true
  errorMessage.value = ''
  try {
    const requests: Promise<unknown>[] = [
      fetchAllPages<AgentItem>(buildUsersPath()),
      api.get<{ data: CertTierOption[] }>('/cert-tiers'),
      api.get<{ data: UserCertificationItem[] }>('/user-certifications'),
    ]
    // TASK-209 P4 — the company list for the create form comes from the
    // global store now (one fetch for the whole app, idempotent), not a
    // second /companies call per screen.
    if (isSuperAdmin.value) requests.push(activeCompany.loadCompanies())
    const [res, tiersRes, certsRes] = await Promise.all(requests)
    agents.value = res as AgentItem[]
    certTiers.value = (tiersRes as { data: CertTierOption[] }).data
    certifications.value = (certsRes as { data: UserCertificationItem[] }).data
    companies.value = activeCompany.companies
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

async function search() {
  searching.value = true
  try {
    await loadAgents()
  } finally {
    searching.value = false
  }
}
function clearSearch() {
  q.value = ''
  nationalIdSearch.value = ''
  search()
}

// ── ใช้งานอยู่ / ปิดใช้งาน — merged into one filter (ag-lead ruling, TASK-204) ──
type RosterFilter = 'active' | 'inactive'
const rosterFilter = ref<RosterFilter>('active')
const filterTabs: Array<{ id: RosterFilter; label: string }> = [
  { id: 'active', label: 'ใช้งานอยู่' },
  { id: 'inactive', label: 'ปิดใช้งาน' },
]
const filteredAgents = computed(() => agents.value.filter((a) => (rosterFilter.value === 'active' ? a.is_active : !a.is_active)))
const activeCount = computed(() => agents.value.filter((a) => a.is_active).length)
const inactiveCount = computed(() => agents.value.filter((a) => !a.is_active).length)

function registeredViaLabel(via?: AgentItem['registered_via']): string {
  const labels: Record<string, string> = { email: 'อีเมล', facebook: 'Facebook', line: 'LINE', google: 'Google' }
  return via ? (labels[via] ?? '-') : '-'
}

// ── Create form ──
// Backend expects first_name/last_name (not a single `name`) — see
// StoreUserRequest.
const showCreateForm = ref(false)
const createForm = ref({
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  role: 'agent' as 'agent' | 'company_admin',
  // TASK-123 — NOT defaulted to 'thai_national_id' the way the public
  // register form is. Here an admin is typing on someone else's behalf.
  id_document_type: '' as IdDocumentTypeChoice,
  national_id: '',
})
const creating = ref(false)
const createNeedsIdDocumentType = computed(
  () => createForm.value.national_id.trim() !== '' && createForm.value.id_document_type === '',
)
// UserService::create() reads $data['company_id'] directly when the actor
// is Super Admin — omitting it isn't just a 422, it's an undefined-array-key
// error server-side.
const createCompanyId = ref<number | null>(null)
async function submitCreate() {
  if (isSuperAdmin.value && !createCompanyId.value) {
    errorMessage.value = 'กรุณาเลือกบริษัทก่อนบันทึก'
    return
  }
  if (createNeedsIdDocumentType.value) {
    errorMessage.value = 'กรุณาเลือกประเภทเอกสาร (บัตรประชาชน หรือ หนังสือเดินทาง) เมื่อกรอกเลขที่เอกสาร'
    return
  }
  creating.value = true
  errorMessage.value = ''
  const documentNumber = normalizeIdNumber(createForm.value.id_document_type, createForm.value.national_id)
  try {
    await api.post('/users', {
      first_name: createForm.value.first_name,
      last_name: createForm.value.last_name,
      email: createForm.value.email,
      password: createForm.value.password,
      role: createForm.value.role,
      ...(documentNumber
        ? { id_document_type: createForm.value.id_document_type, national_id: documentNumber }
        : {}),
      ...(isSuperAdmin.value ? { company_id: createCompanyId.value } : {}),
    })
    createForm.value = {
      first_name: '',
      last_name: '',
      email: '',
      password: '',
      role: 'agent',
      id_document_type: '',
      national_id: '',
    }
    showCreateForm.value = false
    await loadAgents()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      errorMessage.value =
        body.errors?.national_id?.[0] ?? body.errors?.id_document_type?.[0] ?? 'สร้างไม่สำเร็จ (422)'
    } else {
      errorMessage.value = e instanceof ApiError ? `สร้างไม่สำเร็จ (${e.status})` : 'สร้างไม่สำเร็จ'
    }
  } finally {
    creating.value = false
  }
}

// ── Recruit links — loaded here too, only to feed <AgentEditModal>'s
// `inviteLinks` prop (see file docblock). No revoke/list UI on this page. ──
const inviteLinks = ref<AgentInviteLinkRef[]>([])
async function loadInviteLinks() {
  try {
    inviteLinks.value = await fetchAllPages<AgentInviteLinkRef>('/agent-invite-links')
  } catch {
    /* the modal's link-count line simply stays hidden — see its own v-if */
  }
}

/** <AgentEditModal>'s "ดูในแท็บ ลิงก์ชวนทีม" shortcut — now a real navigation
 *  since the links list lives on its own route (AgentInviteLinksView.vue). */
function showLinksForAgent(agentId: number) {
  router.push({ name: 'agent-invite-links', query: { agent: String(agentId) } })
}

// ═══ <AgentEditModal> (TASK-129) ═══
const editingAgentId = ref<number | null>(null)

// TASK-210 (human, 2026-08-19: "กดบันทึก หากบันทึกสำเร็จให้ขึ้นปิดหน้าจอ modal
// นี้ และขึ้น modal ใหม่ว่าบันทึกสำเร็จ"). The confirmation is raised HERE and
// not inside the edit modal because the edit modal closes itself on success —
// anything it rendered goes with it.
const savedMessage = ref('')
const showSavedDialog = ref(false)

async function onAgentEditorSaved(payload: { leaderChanged: boolean; successMessage?: string }) {
  if (payload.successMessage) {
    savedMessage.value = payload.successMessage
    showSavedDialog.value = true
  }
  await loadAgents()
  if (payload.leaderChanged) await loadInviteLinks()
}

onMounted(() => {
  // TASK-126 — arriving from a ทีมขาย card's "แก้ไขข้อมูลตัวแทน" link, via this
  // page's OWN search parameter (`q`, forwarded to UserController::index's
  // name/phone/email LIKE). Setting it BEFORE loadAgents() means the first
  // fetch is already narrowed.
  const handoffQuery = typeof route.query.q === 'string' ? route.query.q.trim() : ''
  if (handoffQuery) {
    q.value = handoffQuery
    rosterFilter.value = 'active'
  }
  void loadAgents().then(() => {
    // TASK-128 r2 — `?edit=<agent_id>` deep link. Kept even though nothing
    // in this codebase currently links to it (confirmed via grep) — a
    // linkable "open this agent's form" URL is genuinely useful on its own
    // and costs nothing to keep. Opened AFTER the roster resolves, and
    // silently ignored when the id isn't in the loaded set (deactivated /
    // re-scoped agent, or a stale link).
    const editId = Number(route.query.edit)
    if (!Number.isFinite(editId) || editId <= 0) return
    if (agents.value.some((a) => a.id === editId)) editingAgentId.value = editId
  })
  loadInviteLinks()
})

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAgents() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="list"
      title="รายชื่อตัวแทน"
      subtitle="รายชื่อ, บทบาท, สถานะใบรับรองของตัวแทน"
      description="ไม่มีระบบส่งอีเมล — ตั้งรหัสผ่านชั่วคราวแล้วแจ้ง agent เอง (ยืนยันจากมนุษย์แล้ว)"
      accent-color="brand"
      storage-key="agent-roster"
    >
      <template #actions>
        <button
          class="btn-primary"
          @click="showCreateForm = !showCreateForm"
        >
          + เพิ่มตัวแทน
        </button>
      </template>
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in filterTabs"
            :key="t.id"
            type="button"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="rosterFilter === t.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="rosterFilter = t.id"
          >
            {{ t.label }} ({{ t.id === 'active' ? activeCount : inactiveCount }})
          </button>
        </div>
      </template>
    </HeroHeader>

    <CompanyScopeNotice action="จัดการรายชื่อตัวแทน" />

    <div class="bg-white/95 border border-slate-200 rounded-xl p-4 mt-4">
      <div class="flex flex-col md:flex-row gap-3">
        <div class="relative flex-1">
          <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="q"
            type="text"
            placeholder="ค้นหา ชื่อ / เบอร์ / อีเมล"
            class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-brand-400"
            @keyup.enter="search"
          />
        </div>
        <div class="relative flex-1">
          <Icon name="shield" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            v-model="nationalIdSearch"
            type="text"
            maxlength="13"
            placeholder="เลขที่เอกสาร (บัตรประชาชน 13 หลัก หรือหนังสือเดินทาง)"
            class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:border-brand-400"
            @keyup.enter="search"
          />
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button
            :disabled="searching"
            class="btn-primary"
            @click="search"
          >
            {{ searching ? 'กำลังค้นหา...' : 'ค้นหา' }}
          </button>
          <button
            class="btn-secondary"
            @click="clearSearch"
          >
            ล้าง
          </button>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-2">
        <Icon name="shield" :size="12" class="inline mr-0.5" />
        ค้นด้วยเลขที่เอกสาร (บัตรประชาชน หรือ หนังสือเดินทาง) ต้องกรอกครบทั้งหมดและตรงทุกตัวอักษร (exact match)
      </p>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <form
      v-if="showCreateForm"
      class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3"
      @submit.prevent="submitCreate"
    >
      <div v-if="isSuperAdmin" class="col-span-2">
        <label class="text-xs font-bold text-slate-500">บริษัท (Super Admin)</label>
        <select v-model.number="createCompanyId" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
          <option :value="null">— เลือกบริษัท —</option>
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">ชื่อ</label>
        <input v-model="createForm.first_name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">นามสกุล</label>
        <input v-model="createForm.last_name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">อีเมล</label>
        <input v-model="createForm.email" type="email" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">รหัสผ่านชั่วคราว (8 ตัวขึ้นไป มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข)</label>
        <input v-model="createForm.password" type="text" minlength="8" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">บทบาท</label>
        <select v-model="createForm.role" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
          <option value="agent">Agent</option>
          <option value="company_admin">Company Admin</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">ประเภทเอกสารยืนยันตัวตน</label>
        <select
          v-model="createForm.id_document_type"
          :required="createNeedsIdDocumentType"
          class="mt-1 w-full px-3 py-2 rounded-lg border text-sm"
          :class="createNeedsIdDocumentType ? 'border-rose-300' : 'border-slate-200'"
        >
          <option value="">— ไม่ระบุ —</option>
          <option v-for="opt in ID_DOCUMENT_TYPE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <p v-if="createNeedsIdDocumentType" class="text-[11px] text-rose-600 mt-1">
          กรอกเลขที่เอกสารแล้ว ต้องเลือกประเภทเอกสารด้วย
        </p>
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">เลขที่เอกสาร (ไม่บังคับ)</label>
        <input
          v-model="createForm.national_id"
          type="text"
          :inputmode="idNumberInputMode(createForm.id_document_type)"
          :maxlength="idNumberMaxLength(createForm.id_document_type)"
          :placeholder="idNumberPlaceholder(createForm.id_document_type)"
          class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          :class="createForm.id_document_type === 'passport' ? 'uppercase' : ''"
        />
      </div>
      <div class="col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreateForm = false">ยกเลิก</button>
        <button type="submit" :disabled="creating" class="btn-primary">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!filteredAgents.length" icon="users" title="ไม่มีรายชื่อในหมวดนี้" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="a in filteredAgents" :key="a.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
              <Icon name="user" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate">
                  {{ a.name }}
                  <span v-if="isSuperAdmin && a.company" class="text-xs font-normal text-slate-400">· {{ a.company.name }}</span>
                  <span
                    v-if="a.is_team_leader"
                    class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-amber-50 text-amber-700 text-[11px] font-bold align-middle"
                  >
                    <Icon name="shield_check" :size="12" />
                    หัวหน้าทีม
                  </span>
                </p>
                <p class="text-xs text-slate-400 truncate">{{ a.email }}</p>
                <p v-if="a.role === 'agent'" class="text-xs mt-1" :class="a.has_passed_basic_cert ? 'text-emerald-600' : 'text-amber-600'">
                  {{ a.has_passed_basic_cert ? 'ผ่าน Basic แล้ว' : 'ยังไม่ผ่าน Basic (BR-1)' }}
                </p>
                <p v-if="a.agent_approval_status === 'pending'" class="text-xs text-amber-600 mt-1">รออนุมัติ (สมัครผ่าน {{ registeredViaLabel(a.registered_via) }})</p>
                <p v-else-if="a.agent_approval_status === 'rejected'" class="text-xs text-rose-600 mt-1">
                  ถูกปฏิเสธ<span v-if="a.approval_rejection_reason"> — {{ a.approval_rejection_reason }}</span>
                </p>
              </div>
            </div>
            <button
              type="button"
              class="btn-secondary shrink-0 gap-1.5"
              @click="editingAgentId = a.id"
            >
              <Icon name="pencil" :size="14" />
              แก้ไข
            </button>
          </div>
        </div>
      </TransitionGroup>
    </template>

    <AgentEditModal
      :agent-id="editingAgentId"
      :roster="agents"
      :cert-tiers="certTiers"
      :certifications="certifications"
      :companies="companies"
      :invite-links="inviteLinks"
      @close="editingAgentId = null"
      @saved="onAgentEditorSaved"
      @show-links="showLinksForAgent"
    />

    <!-- TASK-210 — shown after <AgentEditModal> has closed itself. -->
    <SuccessDialog v-model:show="showSavedDialog" :body="savedMessage" />
  </main>
</template>
