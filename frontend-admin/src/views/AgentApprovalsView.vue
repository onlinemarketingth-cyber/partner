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

/**
 * Date AND time, for the signup moment specifically.
 *
 * A date alone is not enough here: a leader who ran a campaign yesterday
 * produces several rows all reading "20 ส.ค. 2569", and the order they
 * arrived in is exactly what an admin uses to match a row against "the one I
 * was just told about". The queue is ordered by created_at, so showing only
 * the date hides the very thing the ordering is based on.
 */
function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '-'

  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * "Signed up under X" in one line, with every real state spelled out.
 *
 * THREE OUTCOMES, AND NONE OF THEM MAY RENDER AS A BLANK:
 *   1. key ABSENT — this endpoint did not load the relation. Says so, rather
 *      than implying nobody recruited them.
 *   2. null — they used a COMPANY-wide invite code. Nobody recruited them
 *      personally; that is a fact about how they arrived, not missing data.
 *   3. present but `agent` null — the recruiting leader's row is outside this
 *      admin's company scope (TenantScope hides it). The link is still
 *      authoritative; only the name is unavailable.
 *
 * Reads recruited_via and deliberately NOT manager. manager is the CURRENT
 * upline, which an admin may re-point afterwards; "who did they sign up
 * under" is a fact about the past and must not change when the tree does.
 */
function recruitedUnder(item: AgentItem): string {
  if (item.recruited_via === undefined) return 'ไม่ได้โหลดข้อมูลผู้ชวน'
  if (item.recruited_via === null) return 'สมัครผ่านรหัสเชิญของบริษัท (ไม่มีผู้ชวนรายบุคคล)'

  const who = item.recruited_via.agent?.name ?? 'ไม่ทราบชื่อ (ผู้ชวนอยู่นอกขอบเขตข้อมูลของบริษัทนี้)'

  return item.recruited_via.link_label ? `${who} · ลิงก์ "${item.recruited_via.link_label}"` : who
}

/**
 * The same fact as one readable line for the row.
 *
 * The row has no label column, so it carries its own lead-in — and the
 * lead-in only makes sense when there IS somebody to be under. "สมัครใต้
 * สมัครผ่านรหัสเชิญของบริษัท" is what you get from gluing a fixed prefix
 * onto every branch, and it reads like a bug. The modal keeps the plain
 * value because its <dt> already supplies the label.
 */
function recruitedUnderLine(item: AgentItem): string {
  return item.recruited_via?.agent ? `สมัครใต้ ${recruitedUnder(item)}` : recruitedUnder(item)
}

function idDocumentLabel(item: AgentItem): string {
  if (item.id_document_type === 'thai_national_id') return 'บัตรประชาชน'
  if (item.id_document_type === 'passport') return 'หนังสือเดินทาง'

  return 'ไม่ได้บันทึกประเภทเอกสาร'
}

/*
 * ── The detail sheet (human request 2026-08-21) ────────────────────────
 *
 * A queue row has room for a name, a contact and a decision. Everything
 * else an admin may want before admitting somebody into a commission tree —
 * which document they registered with, whether the address is verified, who
 * recruited them, exactly when — does not fit, and cramming it in makes the
 * rows unreadable for the majority of cases that need no second look.
 *
 * So the row stays a summary and the sheet holds the record. The whole card
 * is the affordance rather than a small "ดู" link, which is how every other
 * list in this app behaves.
 *
 * It holds the ROW OBJECT, not an id: the list is already in memory and
 * re-fetching one user would need a second endpoint and a second set of
 * permissions to reason about. The trade is that the sheet shows the data as
 * of the last load — acceptable for a queue an admin refreshes by acting on
 * it, and the sheet closes on every action anyway.
 */
const detailItem = ref<AgentItem | null>(null)

// ── Approve / reject (TASK-020) ──
const rejectingId = ref<number | null>(null)
const rejectReason = ref('')
async function approvePending(item: AgentItem) {
  try {
    await api.put(`/agent-approvals/${item.id}/approve`)
    // Closed on success only. Leaving it open over a stale row would show
    // "รออนุมัติ" next to a person who has just been admitted.
    detailItem.value = null
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
    detailItem.value = null
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
    detailItem.value = null
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
          <!-- The whole summary is the way into the sheet, not a small "ดู"
               link off to one side. A button rather than a div so it is
               reachable by keyboard and announced as activatable; text-left
               because a <button> centres its content by default. -->
          <button
            type="button"
            class="flex items-start gap-3 min-w-0 text-left flex-1 rounded-lg -m-1 p-1 hover:bg-slate-50 transition-colors"
            @click="detailItem = p"
          >
            <Icon name="user_plus" :size="18" class="text-amber-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">
                {{ p.name }}
                <span v-if="isSuperAdmin && p.company" class="text-xs font-normal text-slate-400">· {{ p.company.name }}</span>
              </p>
              <p class="text-xs text-slate-400 truncate">{{ p.email }}</p>
              <p v-if="p.phone" class="text-xs text-slate-400 truncate">{{ p.phone }}</p>

              <!-- WHEN and UNDER WHOM — the two questions an admin asks
                   before admitting somebody into a commission tree, and
                   neither was on this screen before. -->
              <p class="text-xs text-slate-500 mt-1.5 flex items-center gap-1">
                <Icon name="clock" :size="12" class="shrink-0 text-slate-400" />
                <span>สมัครเมื่อ {{ formatDateTime(p.created_at) }}</span>
              </p>
              <p class="text-xs text-slate-500 mt-0.5 flex items-start gap-1">
                <Icon name="users" :size="12" class="shrink-0 mt-0.5 text-slate-400" />
                <span>{{ recruitedUnderLine(p) }}</span>
              </p>

              <!-- APPROVING AN UNVERIFIED PERSON DOES NOT LET THEM IN.
                   LoginGateService raises EmailUnverified before it ever
                   reaches the approval check, so the approval is real and
                   the login still refuses. Said here, it is a caveat; found
                   out afterwards, it reads as the button being broken. -->
              <p
                v-if="p.email_verified === false"
                class="text-xs text-amber-700 bg-amber-50 rounded-lg px-2 py-1 mt-1.5 inline-flex items-start gap-1"
              >
                <Icon name="alert" :size="12" class="shrink-0 mt-0.5" />
                <span>ยังไม่ยืนยันอีเมล — อนุมัติได้ แต่จะยังเข้าสู่ระบบไม่ได้จนกว่าจะกดลิงก์ในอีเมล</span>
              </p>

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
          </button>
          <div class="flex gap-2 shrink-0">
            <template v-if="approvalStatus === 'pending'">
              <!-- Solid green / solid red, each with its own mark. These
                   were two bare text links in the same size and weight, side
                   by side, distinguished only by hue — which is the one
                   channel a red-green colour-blind admin does not have, on
                   the one control here that cannot be undone by pressing it
                   again. Fill + icon + label means the difference survives
                   without colour at all. -->
              <button
                type="button"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 transition-colors"
                @click="approvePending(p)"
              >
                <Icon name="check" :size="14" />
                อนุมัติ
              </button>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 transition-colors"
                @click="rejectingId = rejectingId === p.id ? null : p.id"
              >
                <Icon name="x" :size="14" />
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

    <!--
      THE DETAIL SHEET.

      Teleported to <body> so it escapes the card's stacking context — a
      fixed overlay rendered inside a transformed ancestor is positioned
      against that ancestor, not the viewport, which is how modals end up
      half off-screen. Every other modal in this app does the same.

      Dismissable three ways (backdrop, ✕, Escape) because it is read-only:
      nothing here is lost by closing it, so making it hard to leave would be
      pure friction.
    -->
    <Teleport to="body">
      <div
        v-if="detailItem"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/40 p-0 sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="approval_detail_title"
        @click.self="detailItem = null"
        @keydown.esc="detailItem = null"
      >
        <div class="bg-white w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl max-h-[85vh] overflow-y-auto shadow-xl">
          <div class="sticky top-0 bg-white border-b border-slate-100 px-5 py-4 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p id="approval_detail_title" class="text-base font-bold text-slate-900 truncate">{{ detailItem.name }}</p>
              <p class="text-xs text-slate-400 truncate">
                {{ detailItem.company?.name ?? 'ไม่ระบุบริษัท' }}
              </p>
            </div>
            <button
              type="button"
              class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
              aria-label="ปิด"
              @click="detailItem = null"
            >
              <Icon name="close" :size="18" />
            </button>
          </div>

          <dl class="px-5 py-4 space-y-3 text-sm">
            <div>
              <dt class="text-xs font-bold text-slate-400">อีเมล</dt>
              <dd class="text-slate-800 break-all">{{ detailItem.email }}</dd>
              <!-- Stated as a consequence, not a status badge: "verified /
                   unverified" alone leaves the admin to work out what it
                   means for the button they are about to press. -->
              <dd v-if="detailItem.email_verified === false" class="text-xs text-amber-700 mt-1">
                ยังไม่ยืนยันอีเมล — อนุมัติได้ แต่ผู้สมัครจะยังเข้าสู่ระบบไม่ได้จนกว่าจะกดลิงก์ยืนยันในอีเมล
              </dd>
              <dd v-else-if="detailItem.email_verified === true" class="text-xs text-emerald-700 mt-1">
                ยืนยันอีเมลแล้ว
              </dd>
            </div>

            <div>
              <dt class="text-xs font-bold text-slate-400">เบอร์โทร</dt>
              <dd class="text-slate-800">{{ detailItem.phone || 'ไม่ได้ระบุ' }}</dd>
            </div>

            <div>
              <dt class="text-xs font-bold text-slate-400">เอกสารยืนยันตัวตน</dt>
              <!-- national_id_masked, never national_id. UserResource does
                   send the full value to a viewer who can('view') this agent,
                   but a decision queue is a screen that gets left open on a
                   shared desk — the last four digits answer "is this the
                   person I was told about" and the full number does not need
                   to be here to do it (§6, PDPA). -->
              <dd class="text-slate-800">
                {{ idDocumentLabel(detailItem) }} · {{ detailItem.national_id_masked || 'ไม่ได้บันทึกเลขที่เอกสาร' }}
              </dd>
            </div>

            <div>
              <dt class="text-xs font-bold text-slate-400">สมัครเมื่อ</dt>
              <dd class="text-slate-800">{{ formatDateTime(detailItem.created_at) }}</dd>
            </div>

            <div>
              <dt class="text-xs font-bold text-slate-400">สมัครใต้ (ผู้ชวน)</dt>
              <dd class="text-slate-800">{{ recruitedUnder(detailItem) }}</dd>
              <!-- The CURRENT upline, shown separately and labelled as such.
                   It is usually the same person, and when it is not, that
                   difference is the interesting fact — an admin re-pointed
                   the tree after signup. Collapsing the two would hide it. -->
              <dd v-if="detailItem.manager" class="text-xs text-slate-500 mt-0.5">
                หัวหน้าปัจจุบันในผังทีม: {{ detailItem.manager.name }}
              </dd>
            </div>

            <div>
              <dt class="text-xs font-bold text-slate-400">ช่องทางสมัคร</dt>
              <dd class="text-slate-800">{{ registeredViaLabel(detailItem.registered_via) }}</dd>
            </div>

            <div v-if="detailItem.approval_source">
              <dt class="text-xs font-bold text-slate-400">การอนุมัติ</dt>
              <dd class="text-slate-800">{{ approvalProvenance(detailItem) }}</dd>
              <dd v-if="detailItem.approved_at" class="text-xs text-slate-500 mt-0.5">
                เมื่อ {{ formatDateTime(detailItem.approved_at) }}
              </dd>
            </div>

            <div v-if="detailItem.approval_rejection_reason">
              <dt class="text-xs font-bold text-slate-400">เหตุผลที่ปฏิเสธ</dt>
              <dd class="text-rose-600">{{ detailItem.approval_rejection_reason }}</dd>
            </div>
          </dl>

          <!--
            The decision is repeated here on purpose. An admin who opened the
            sheet BECAUSE they were unsure should not have to close it, find
            the row again and hope it is the same one — the queue reorders
            itself after every action.

            Pending only. "Reject" here deliberately closes the sheet and
            opens the row's reason field rather than duplicating that input:
            two copies of one free-text box, each with its own v-model, is
            how a reason gets typed into one and submitted from the other.
          -->
          <div
            v-if="approvalStatus === 'pending'"
            class="sticky bottom-0 bg-white border-t border-slate-100 px-5 py-3 flex gap-2 justify-end"
          >
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-bold hover:bg-rose-700 transition-colors"
              @click="rejectingId = detailItem.id; detailItem = null"
            >
              <Icon name="x" :size="16" />
              ปฏิเสธ
            </button>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700 transition-colors"
              @click="approvePending(detailItem)"
            >
              <Icon name="check" :size="16" />
              อนุมัติ
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </main>
</template>
