<script setup lang="ts">
/**
 * AgentApprovalsView — "รออนุมัติ" sub-page of "จัดการตัวแทน" (TASK-204).
 *
 * Split out of AgentManagementView.vue's "รออนุมัติ" tab (originally TASK-020 /
 * ADR-005 decision 3; TASK-117 / ADR-025 §7 added the ?status= filter so an
 * admin can also review what team leaders approved on their own).
 *
 * This tab was already close to self-contained — its own endpoint
 * (/agent-approvals), its own rows, its own actions. The one thing the old
 * page did that this page deliberately DROPS: `approvePending()` /
 * `submitReject()` / `submitRevokeApproval()` used to also call the parent's
 * `loadAgents()` (the /users roster) after every action, because approving
 * someone moved them onto the SAME page's "ใช้งานอยู่" tab and the old page
 * needed its shared roster to reflect that immediately. That roster now
 * lives on a different route entirely (AgentRosterView.vue) — a queue action
 * here has nothing left on THIS page that needs a /users refetch, so only
 * `loadPendingApprovals()` runs after each action.
 *
 * `pendingCount` (a separate ref from `approvalRows.length`) is also
 * dropped: it only ever existed to feed the OLD tab bar's own badge
 * ("รออนุมัติ (N)"), which does not exist any more — the top submenu row
 * shows no counts (see AdminNavigation.vue). AgentDashboardOverview.vue's
 * own "ผู้ใช้ที่รออนุมัติ" panel already covers the "how many are waiting"
 * question elsewhere with its own independent fetch.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { type AgentItem, fetchAllPages } from './agentEdit'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const errorMessage = ref('')

// TASK-117 / ADR-025 §7 — the queue is no longer pending-only: an agent
// approved by a team leader leaves "pending" the instant the leader presses
// the button, so an Admin auditing what leaders have been doing needs
// ?status=approved too.
type ApprovalStatusFilter = 'pending' | 'approved' | 'rejected'
const approvalStatus = ref<ApprovalStatusFilter>('pending')
const approvalRows = ref<AgentItem[]>([])
const loadingPending = ref(false)

const approvalStatusTabs: Array<{ id: ApprovalStatusFilter; label: string }> = [
  { id: 'pending', label: 'รออนุมัติ' },
  { id: 'approved', label: 'อนุมัติแล้ว' },
  { id: 'rejected', label: 'ถูกปฏิเสธ' },
]

async function loadPendingApprovals() {
  loadingPending.value = true
  try {
    // fetchAllPages, not a bare GET: /agent-approvals paginates at 15 and an
    // approved list is unbounded — page 1 alone would quietly hide most of
    // what an admin came here to review.
    approvalRows.value = await fetchAllPages<AgentItem>(`/agent-approvals?status=${approvalStatus.value}`)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดคิวอนุมัติไม่สำเร็จ (${e.status})` : 'โหลดคิวอนุมัติไม่สำเร็จ'
  } finally {
    loadingPending.value = false
  }
}

watch(approvalStatus, () => {
  loadPendingApprovals()
})

onMounted(loadPendingApprovals)

/**
 * WHO admitted this agent, in one line. Two independent null cases, and
 * neither may ever surface as the string "null":
 *   1. approval_source is null — the row predates TASK-115 or the agent was
 *      created directly by an Admin (never an "approval" event);
 *   2. approval_source is set but approved_by is null/absent — most often a
 *      SUPER ADMIN approved and TenantScope hides that user row from a
 *      Company Admin. The source is still authoritative and worth showing;
 *      only the name is unavailable.
 */
function approvalProvenance(item: AgentItem): string {
  if (!item.approval_source) {
    return 'ไม่มีข้อมูลผู้อนุมัติ (อนุมัติก่อนระบบเริ่มบันทึกที่มา หรือเป็นตัวแทนที่ผู้ดูแลสร้างเอง)'
  }
  const who = item.approved_by?.name ?? 'ไม่ทราบชื่อ (ผู้อนุมัติอยู่นอกขอบเขตข้อมูลของบริษัทนี้)'
  return item.approval_source === 'team_leader' ? `อนุมัติโดยหัวหน้าทีม: ${who}` : `อนุมัติโดยผู้ดูแล: ${who}`
}
function approvalSourceChip(item: AgentItem): string {
  if (item.approval_source === 'team_leader') return 'หัวหน้าทีมอนุมัติ'
  if (item.approval_source === 'admin') return 'ผู้ดูแลอนุมัติ'
  return 'ไม่ระบุที่มา'
}
function registeredViaLabel(via?: AgentItem['registered_via']): string {
  const labels: Record<string, string> = { email: 'อีเมล', facebook: 'Facebook', line: 'LINE', google: 'Google' }
  return via ? (labels[via] ?? '-') : '-'
}
function formatDate(iso: string | null | undefined): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

// ── Approve / reject (TASK-020) ──
const rejectingId = ref<number | null>(null)
const rejectReason = ref('')
async function approvePending(item: AgentItem) {
  try {
    await api.put(`/agent-approvals/${item.id}/approve`)
    await loadPendingApprovals()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อนุมัติไม่สำเร็จ (${e.status})` : 'อนุมัติไม่สำเร็จ'
  }
}
async function submitReject(item: AgentItem) {
  try {
    await api.put(`/agent-approvals/${item.id}/reject`, { reason: rejectReason.value || undefined })
    rejectingId.value = null
    rejectReason.value = ''
    await loadPendingApprovals()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ปฏิเสธไม่สำเร็จ (${e.status})` : 'ปฏิเสธไม่สำเร็จ'
  }
}

// TASK-117 / ADR-025 §7 — reversing an approval, including one a team leader
// made. Same inline-reason pattern as reject() above rather than a
// ConfirmDialog, because the payload is the same (RejectAgentRequest) and a
// dialog cannot take a free-text reason. Server semantics
// (AgentApprovalService::revoke): the user goes back to REJECTED, not to
// pending, and the login gate (TASK-115) locks them out again immediately.
const revokingApprovalId = ref<number | null>(null)
const revokeReason = ref('')
async function submitRevokeApproval(item: AgentItem) {
  try {
    await api.put(`/agent-approvals/${item.id}/revoke`, { reason: revokeReason.value || undefined })
    revokingApprovalId.value = null
    revokeReason.value = ''
    await loadPendingApprovals()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `เพิกถอนการอนุมัติไม่สำเร็จ (${e.status})` : 'เพิกถอนการอนุมัติไม่สำเร็จ'
  }
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadPendingApprovals() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="clock"
      title="รออนุมัติ"
      subtitle="คิวอนุมัติตัวแทนที่สมัครเข้ามาเอง"
      accent-color="brand"
      storage-key="agent-approvals"
    />

    <CompanyScopeNotice action="ตรวจอนุมัติตัวแทน" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
      <button
        v-for="s in approvalStatusTabs"
        :key="s.id"
        type="button"
        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
        :class="approvalStatus === s.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
        @click="approvalStatus = s.id"
      >
        {{ s.label }}
      </button>
      <p class="text-xs text-slate-400">
        ดู "อนุมัติแล้ว" เพื่อตรวจสอบว่าหัวหน้าทีมรับใครเข้าบริษัทไปบ้าง (ADR-025 §7)
      </p>
    </div>

    <LoadingSkeleton v-if="loadingPending && !approvalRows.length" type="list" :rows="3" class="mt-4" />
    <EmptyState
      v-else-if="!approvalRows.length"
      icon="user_plus"
      :title="approvalStatus === 'pending' ? 'ไม่มี Agent รออนุมัติ' : 'ไม่มีรายการในสถานะนี้'"
      class="mt-4"
    />
    <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
      <div v-for="p in approvalRows" :key="p.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="user_plus" :size="18" class="text-amber-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">
                {{ p.name }}
                <span v-if="isSuperAdmin && p.company" class="text-xs font-normal text-slate-400">· {{ p.company.name }}</span>
              </p>
              <p class="text-xs text-slate-400 truncate">{{ p.email }}</p>
              <p v-if="p.phone" class="text-xs text-slate-400 truncate">{{ p.phone }}</p>
              <p class="text-xs text-amber-600 mt-1">สมัครผ่าน {{ registeredViaLabel(p.registered_via) }}</p>
              <template v-if="approvalStatus === 'approved'">
                <p class="mt-1.5">
                  <span
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold"
                    :class="
                      p.approval_source === 'team_leader'
                        ? 'bg-amber-50 text-amber-700'
                        : p.approval_source === 'admin'
                          ? 'bg-slate-100 text-slate-600'
                          : 'bg-slate-50 text-slate-400'
                    "
                  >
                    <Icon :name="p.approval_source === 'team_leader' ? 'shield_check' : 'shield'" :size="12" />
                    {{ approvalSourceChip(p) }}
                  </span>
                </p>
                <p class="text-xs text-slate-500 mt-1">{{ approvalProvenance(p) }}</p>
                <p v-if="p.approved_at" class="text-xs text-slate-400">เมื่อ {{ formatDate(p.approved_at) }}</p>
              </template>
              <p v-if="approvalStatus === 'rejected' && p.approval_rejection_reason" class="text-xs text-rose-600 mt-1">
                เหตุผล: {{ p.approval_rejection_reason }}
              </p>
            </div>
          </div>
          <div class="flex gap-1 shrink-0">
            <template v-if="approvalStatus === 'pending'">
              <button class="text-xs font-bold text-emerald-600 hover:text-emerald-700 px-2 py-1" @click="approvePending(p)">
                อนุมัติ
              </button>
              <button
                class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1"
                @click="rejectingId = rejectingId === p.id ? null : p.id"
              >
                ปฏิเสธ
              </button>
            </template>
            <button
              v-else-if="approvalStatus === 'approved'"
              class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1"
              @click="revokingApprovalId = revokingApprovalId === p.id ? null : p.id"
            >
              เพิกถอนการอนุมัติ
            </button>
          </div>
        </div>
        <div v-if="rejectingId === p.id" class="mt-3 pt-3 border-t border-slate-100 flex gap-2 items-center">
          <input
            v-model="rejectReason"
            type="text"
            placeholder="เหตุผล (ไม่บังคับ)"
            class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
          />
          <button class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700" @click="submitReject(p)">
            ยืนยันปฏิเสธ
          </button>
        </div>
        <div v-if="revokingApprovalId === p.id" class="mt-3 pt-3 border-t border-slate-100">
          <p class="text-xs text-slate-500 mb-2">
            เพิกถอนการอนุมัติของ{{ p.name }} — สถานะจะกลับไปเป็น "ถูกปฏิเสธ" และผู้ใช้จะเข้าสู่ระบบไม่ได้ทันที
            ใช้เมื่อต้องการกลับคำตัดสินของหัวหน้าทีมหรือของผู้ดูแลคนก่อน
          </p>
          <div class="flex gap-2 items-center">
            <input
              v-model="revokeReason"
              type="text"
              placeholder="เหตุผล (ไม่บังคับ — ผู้ใช้จะเห็นเหตุผลนี้)"
              class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
            />
            <button
              class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700"
              @click="submitRevokeApproval(p)"
            >
              ยืนยันเพิกถอน
            </button>
          </div>
        </div>
      </div>
    </TransitionGroup>
  </main>
</template>
