<script setup lang="ts">
/**
 * AccountDeletionRequestsView — "คำขอลบบัญชี" (MOB-12, 2026-10-02).
 *
 * The admin half of in-app account deletion. Apple guideline 5.1.1(v) needs
 * the agent app to offer "delete my account"; the owner decided that the
 * agent's press files a REQUEST and a Company Admin decides it:
 *
 *   agent asks → signed out and blocked at login at once →
 *   admin settles pending commission and moves the downline / clients
 *   elsewhere (existing screens) → approves → the account is anonymised.
 *   Or rejects → the agent can sign in again.
 *
 * WHAT THIS SCREEN ADDS TO THAT DECISION is the three warning counts the API
 * computes per row (pending_commission_satang / downline_count /
 * client_count). They are WARNINGS, not gates — the owner did not ask for
 * approval to be blocked by them — so they are shown on the row and repeated
 * in the approve dialog, and the button stays enabled. Money arrives as
 * integer satang and is divided by 100 only in formatSatang() below (BR-3).
 *
 * Same decision mechanics as AgentApprovalsView (the closest sibling):
 * one decision in flight at a time, the server's own sentence on a refusal,
 * the queue re-read after every decision whichever way it went, and ADR-052's
 * confirmSaved() so "saved" is only ever said after the re-read landed.
 *
 * Super Admin: the list follows the header company (fetchAllPages scopes it);
 * with "ทุกบริษัท" selected every company's requests are listed with the
 * company named on the row — the same reading-across behaviour the approvals
 * queue has. The API authorizes a Super Admin's decision on any company.
 */
import { computed, onMounted, ref, watch } from "vue";
import { useAuthStore } from "@/stores/auth";
import { ApiError, api } from "@/api/client";
import HeroHeader from "@/design-system/components/HeroHeader.vue";
import EmptyState from "@/design-system/components/EmptyState.vue";
import Icon from "@/design-system/components/Icon.vue";
import LoadingSkeleton from "@/design-system/components/LoadingSkeleton.vue";
import InfoPopover from "@/design-system/components/InfoPopover.vue";
import CompanyScopeNotice from "@/design-system/components/CompanyScopeNotice.vue";
import ConfirmDialog from "@/design-system/components/ConfirmDialog.vue";
import { useActiveCompanyStore } from "@/stores/activeCompany";
import { confirmSaved } from "@/composables/useSaveFeedback";
import { fetchAllPages } from "./agentEdit";

type DeletionStatus = "pending" | "approved" | "rejected";

/** Mirrors App\Http\Resources\AccountDeletionRequestResource. */
interface AccountDeletionRequestItem {
  id: number;
  status: DeletionStatus;
  reason: string | null;
  requested_at: string | null;
  decided_at: string | null;
  decision_note: string | null;
  decided_by?: { id: number; name: string } | null;
  company?: { id: number; name: string } | null;
  user?: {
    id: number;
    name: string;
    email: string;
    phone: string | null;
  } | null;
  /** Integer satang (BR-3). Null = not computed, which is never shown as 0. */
  pending_commission_satang: number | null;
  downline_count: number | null;
  client_count: number | null;
  /** "immediate" = deleted on the agent's own request; "admin" = decided here; null while pending. */
  resolution: "immediate" | "admin" | null;
  /** Satang the agent gave up on an immediate deletion (BR-3); null when nothing was waived. */
  forfeited_commission_satang: number | null;
}

const auth = useAuthStore();
const activeCompany = useActiveCompanyStore();
const isSuperAdmin = computed(() => auth.user?.role === "super_admin");

const statusTabs: Array<{ id: DeletionStatus; label: string }> = [
  { id: "pending", label: "รอพิจารณา" },
  { id: "approved", label: "อนุมัติแล้ว (ลบแล้ว)" },
  { id: "rejected", label: "ปฏิเสธแล้ว" },
];

const status = ref<DeletionStatus>("pending");
const rows = ref<AccountDeletionRequestItem[]>([]);
const loading = ref(false);
/** The LIST could not be read — shown instead of an empty state, never as one. */
const loadError = ref("");
/** A DECISION was refused — shown above the (re-read) list. */
const errorMessage = ref("");

/** Resolves false when the read failed (the error is already on screen). */
async function loadRequests(): Promise<boolean> {
  loading.value = true;
  loadError.value = "";
  try {
    rows.value = await fetchAllPages<AccountDeletionRequestItem>(
      `/account-deletion-requests?status=${status.value}`,
    );
    return true;
  } catch (e) {
    rows.value = [];
    loadError.value =
      e instanceof ApiError
        ? `โหลดคำขอลบบัญชีไม่สำเร็จ (${e.status})`
        : "โหลดคำขอลบบัญชีไม่สำเร็จ";
    return false;
  } finally {
    loading.value = false;
  }
}

watch(status, () => {
  errorMessage.value = "";
  loadRequests();
});
// The list is scoped server-side; a change of header company has to refetch.
watch(
  () => activeCompany.companyId,
  () => {
    loadRequests();
  },
);
onMounted(loadRequests);

// ── display helpers ──

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "-";
  return new Date(iso).toLocaleString("th-TH", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

/** Satang → "1,234.50 บาท". The only place this screen divides by 100. */
function formatSatang(satang: number): string {
  return `${(satang / 100).toLocaleString("th-TH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} บาท`;
}

/**
 * MOB-12 follow-up (owner decision 2026-10-03) — how a decided request was
 * closed, in one line. An agent who owed nothing, or who waived what was
 * owed, was deleted on the spot with no admin involved, and the approved tab
 * must say so rather than imply somebody here approved it.
 */
function decisionLine(item: AccountDeletionRequestItem): string {
  const when = formatDateTime(item.decided_at);

  if (item.resolution === "immediate") {
    const forfeited = item.forfeited_commission_satang;
    const detail =
      forfeited !== null && forfeited !== 0
        ? `สละค่าคอม ${formatSatang(forfeited)}`
        : "ไม่มีค่าคอมค้าง";

    return `ลบทันที (${detail}) เมื่อ ${when}`;
  }

  const verb =
    item.status === "approved" ? "อนุมัติโดยผู้ดูแล" : "ปฏิเสธโดยผู้ดูแล";
  const who = item.decided_by ? ` ${item.decided_by.name}` : "";

  return `${verb}${who} เมื่อ ${when}`;
}

function displayName(item: AccountDeletionRequestItem): string {
  return item.user?.name ?? `ผู้ใช้ #${item.id}`;
}

interface Warning {
  key: "commission" | "downline" | "clients";
  text: string;
  /** Something is still attached and should be handled before approving. */
  attention: boolean;
}

/**
 * The three counts as sentences. A null count (not computed) says so rather
 * than printing 0 — "nothing owed" is a claim about money, and it must only
 * appear when the server measured it.
 */
function warnings(item: AccountDeletionRequestItem): Warning[] {
  const commission = item.pending_commission_satang;
  const downline = item.downline_count;
  const clients = item.client_count;

  return [
    {
      key: "commission",
      text:
        commission === null
          ? "ค่าแนะนำค้างจ่าย: ไม่ทราบ"
          : `ค่าแนะนำค้างจ่าย ${formatSatang(commission)}`,
      attention: commission === null || commission !== 0,
    },
    {
      key: "downline",
      text:
        downline === null
          ? "ลูกทีมสายตรง: ไม่ทราบ"
          : `ลูกทีมสายตรง ${downline.toLocaleString("th-TH")} คน`,
      attention: downline === null || downline > 0,
    },
    {
      key: "clients",
      text:
        clients === null
          ? "ลูกค้าที่ดูแล: ไม่ทราบ"
          : `ลูกค้าที่ดูแล ${clients.toLocaleString("th-TH")} ราย`,
      attention: clients === null || clients > 0,
    },
  ];
}

function hasOutstanding(item: AccountDeletionRequestItem): boolean {
  return warnings(item).some((w) => w.attention);
}

// ── decisions ──

const decidingId = ref<number | null>(null);
const rejectingId = ref<number | null>(null);
const rejectNote = ref("");
const pendingDecision = ref<{
  kind: "approve" | "reject";
  item: AccountDeletionRequestItem;
} | null>(null);

/** The server's own sentence when it wrote one; the code only as a fallback. */
function decisionError(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback;
  return e.message && e.message !== `API error ${e.status}`
    ? e.message
    : `${fallback} (${e.status})`;
}

/** ADR-052 step 3: re-read, and throw if the re-read failed so the dialog says the screen may be behind. */
async function reloadAfterDecision(): Promise<void> {
  if (!(await loadRequests())) throw new Error("reload failed");
}

function askApprove(item: AccountDeletionRequestItem): void {
  if (decidingId.value !== null) return;
  pendingDecision.value = { kind: "approve", item };
}

function askReject(item: AccountDeletionRequestItem): void {
  if (decidingId.value !== null) return;
  pendingDecision.value = { kind: "reject", item };
}

function toggleRejectPanel(item: AccountDeletionRequestItem): void {
  if (rejectingId.value === item.id) {
    rejectingId.value = null;
    return;
  }
  rejectingId.value = item.id;
  rejectNote.value = "";
}

const confirmTitle = computed(() =>
  pendingDecision.value?.kind === "approve"
    ? "ยืนยันอนุมัติการลบบัญชี"
    : "ยืนยันปฏิเสธคำขอลบบัญชี",
);

const confirmBody = computed(() => {
  const pending = pendingDecision.value;
  if (!pending) return "";
  const name = displayName(pending.item);

  if (pending.kind === "reject") {
    const note = rejectNote.value.trim() || "ไม่ระบุ";
    return `ปฏิเสธคำขอลบบัญชีของ "${name}" — สมาชิกจะกลับมาเข้าสู่ระบบได้ตามปกติ\nหมายเหตุ: ${note}`;
  }

  return [
    `ลบบัญชีของ "${name}" — ข้อมูลส่วนตัว (ชื่อ อีเมล เบอร์โทร บัญชีธนาคาร เลขบัตร รูปโปรไฟล์) จะถูกลบถาวร และย้อนกลับไม่ได้`,
    "ควรจ่ายค่าแนะนำที่ค้าง และย้ายลูกทีม/ลูกค้าไปให้ผู้อื่นดูแลก่อนอนุมัติ:",
    ...warnings(pending.item).map((w) => `• ${w.text}`),
  ].join("\n");
});

async function approve(item: AccountDeletionRequestItem): Promise<void> {
  const name = displayName(item);
  await confirmSaved(
    () =>
      api.post<{ data: AccountDeletionRequestItem }>(
        `/account-deletion-requests/${item.id}/approve`,
      ),
    {
      apply: reloadAfterDecision,
      message: () =>
        `อนุมัติคำขอลบบัญชีของ ${name} แล้ว — ข้อมูลส่วนตัวถูกลบเรียบร้อย`,
    },
  );
}

async function reject(item: AccountDeletionRequestItem): Promise<void> {
  const note = rejectNote.value.trim();
  await confirmSaved(
    () =>
      api.post<{ data: AccountDeletionRequestItem }>(
        `/account-deletion-requests/${item.id}/reject`,
        {
          note: note === "" ? undefined : note,
        },
      ),
    {
      apply: async () => {
        rejectingId.value = null;
        rejectNote.value = "";
        await reloadAfterDecision();
      },
      message: (res) =>
        `ปฏิเสธคำขอลบบัญชีของ ${res?.data?.user?.name ?? displayName(item)} แล้ว — สมาชิกเข้าสู่ระบบได้ตามปกติ`,
    },
  );
}

async function confirmPendingDecision(): Promise<void> {
  const pending = pendingDecision.value;
  if (!pending || decidingId.value !== null) return;
  decidingId.value = pending.item.id;
  errorMessage.value = "";
  try {
    if (pending.kind === "approve") await approve(pending.item);
    else await reject(pending.item);
  } catch (e) {
    errorMessage.value = decisionError(
      e,
      pending.kind === "approve" ? "อนุมัติไม่สำเร็จ" : "ปฏิเสธไม่สำเร็จ",
    );
    // A refusal almost always means the world moved (another admin decided
    // first) — the list on screen is the stale half, so re-read it.
    await loadRequests();
  } finally {
    decidingId.value = null;
    // Closed whichever way it went: on success the saved dialog takes over,
    // on a refusal the banner above the list says why.
    pendingDecision.value = null;
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="trash"
      title="คำขอลบบัญชี"
      subtitle="สมาชิกที่ขอลบบัญชีของตนเองผ่านแอป"
      accent-color="brand"
      storage-key="account-deletion-requests"
    />

    <div class="mt-4 flex items-center gap-2 text-xs text-slate-500">
      <span>ขั้นตอนการพิจารณา</span>
      <InfoPopover label="คำขอลบบัญชี" :width="320">
        <p>
          สมาชิกที่ไม่มีค่าแนะนำค้างจ่าย หรือยินยอมสละค่าแนะนำที่ค้าง
          จะถูกลบบัญชีทันทีโดยไม่ต้องรอผู้ดูแล (ดูได้ในแท็บอนุมัติแล้ว ว่า
          "ลบทันที") ลูกทีมและลูกค้ายังผูกกับบัญชีเดิมจนกว่าผู้ดูแลจะย้าย
        </p>
        <p class="mt-2">
          สมาชิกที่เลือกรอรับค่าแนะนำ จะมาอยู่ในแท็บรอพิจารณา
          ระบบออกจากระบบทุกอุปกรณ์ทันที
          และสมาชิกจะเข้าสู่ระบบไม่ได้จนกว่าจะมีการพิจารณา
        </p>
        <p class="mt-2">
          ก่อนอนุมัติ ควรจ่ายค่าแนะนำที่ค้างอยู่ (เมนูจ่ายค่าแนะนำ)
          และย้ายลูกทีมสายตรงกับลูกค้าไปให้สมาชิกคนอื่นดูแล (เมนูรายชื่อสมาชิก /
          ลูกค้า) ตัวเลขบนแต่ละรายการเป็นคำเตือน ไม่ได้บังคับ
        </p>
        <p class="mt-2">
          อนุมัติแล้ว ข้อมูลส่วนตัวของสมาชิกจะถูกลบถาวร
          ส่วนประวัติค่าแนะนำและยอดขายยังเก็บไว้ครบตามเดิม ปฏิเสธแล้ว
          สมาชิกจะกลับมาเข้าสู่ระบบได้ตามปกติ
        </p>
      </InfoPopover>
    </div>

    <CompanyScopeNotice action="พิจารณาคำขอลบบัญชี" />

    <div
      v-if="errorMessage"
      class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700"
      data-test="decision-error"
    >
      {{ errorMessage }}
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2" role="tablist">
      <button
        v-for="s in statusTabs"
        :key="s.id"
        type="button"
        role="tab"
        :aria-selected="status === s.id"
        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
        :class="
          status === s.id
            ? 'bg-brand-50 text-brand-700'
            : 'text-slate-500 hover:bg-slate-100'
        "
        :data-test="`tab-${s.id}`"
        @click="status = s.id"
      >
        {{ s.label }}
      </button>
    </div>

    <LoadingSkeleton
      v-if="loading && !rows.length"
      type="list"
      :rows="3"
      class="mt-4"
    />

    <div
      v-else-if="loadError"
      class="mt-4 px-4 py-6 rounded-xl bg-rose-50 border border-rose-200 text-center"
      data-test="load-error"
    >
      <p class="text-sm text-rose-700">{{ loadError }}</p>
      <button
        type="button"
        class="mt-3 px-3 py-1.5 rounded-lg bg-white border border-rose-200 text-xs font-bold text-rose-700 hover:bg-rose-100"
        @click="loadRequests"
      >
        ลองอีกครั้ง
      </button>
    </div>

    <EmptyState
      v-else-if="!rows.length"
      icon="trash"
      :title="
        status === 'pending'
          ? 'ไม่มีคำขอลบบัญชีที่รอพิจารณา'
          : 'ไม่มีรายการในสถานะนี้'
      "
      class="mt-4"
    />

    <div v-else class="space-y-2 mt-4">
      <div
        v-for="item in rows"
        :key="item.id"
        class="bg-white/95 border border-slate-200 rounded-xl p-4"
        data-test="request-row"
      >
        <div
          class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3"
        >
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-900 break-words">
              {{ displayName(item) }}
              <span
                v-if="isSuperAdmin && item.company"
                class="text-xs font-normal text-slate-400"
              >
                · {{ item.company.name }}
              </span>
            </p>
            <p v-if="item.user?.email" class="text-xs text-slate-400 break-all">
              {{ item.user.email }}
            </p>
            <p v-if="item.user?.phone" class="text-xs text-slate-400">
              {{ item.user.phone }}
            </p>

            <p class="text-xs text-slate-500 mt-1.5 flex items-center gap-1">
              <Icon name="clock" :size="12" class="shrink-0 text-slate-400" />
              <span>ขอลบเมื่อ {{ formatDateTime(item.requested_at) }}</span>
            </p>
            <p class="text-xs text-slate-600 mt-1 break-words">
              เหตุผล: {{ item.reason || "ไม่ได้ระบุ" }}
            </p>

            <!-- The three warnings. Amber = something is still attached and
                 should be handled first; slate = nothing attached. Icon +
                 words carry the meaning, not colour alone. -->
            <ul class="mt-2 flex flex-wrap gap-1.5" data-test="warnings">
              <li
                v-for="w in warnings(item)"
                :key="w.key"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold"
                :class="
                  w.attention
                    ? 'bg-amber-50 text-amber-700'
                    : 'bg-slate-50 text-slate-500'
                "
                :data-test="`warning-${w.key}`"
              >
                <Icon
                  :name="w.attention ? 'alert' : 'check'"
                  :size="12"
                  class="shrink-0"
                />
                {{ w.text }}
              </li>
            </ul>

            <template v-if="item.status !== 'pending'">
              <p class="text-xs text-slate-500 mt-2" data-test="decision-line">
                {{ decisionLine(item) }}
              </p>
              <p
                v-if="item.decision_note"
                class="text-xs text-slate-600 mt-0.5 break-words"
              >
                หมายเหตุ: {{ item.decision_note }}
              </p>
            </template>
          </div>

          <div v-if="item.status === 'pending'" class="flex gap-2 shrink-0">
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-slate-700 text-xs font-bold hover:bg-slate-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              data-test="reject"
              :disabled="decidingId !== null"
              @click="toggleRejectPanel(item)"
            >
              <Icon name="x" :size="14" />
              ปฏิเสธ
            </button>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              data-test="approve"
              :disabled="decidingId !== null"
              @click="askApprove(item)"
            >
              <Icon name="trash" :size="14" />
              {{
                decidingId === item.id ? "กำลังดำเนินการ…" : "อนุมัติลบบัญชี"
              }}
            </button>
          </div>
        </div>

        <p
          v-if="item.status === 'pending' && hasOutstanding(item)"
          class="mt-2 text-xs text-amber-700"
        >
          ยังมีรายการผูกอยู่ — แนะนำให้จัดการก่อนอนุมัติ
        </p>

        <div
          v-if="rejectingId === item.id"
          class="mt-3 pt-3 border-t border-slate-100 flex flex-col sm:flex-row gap-2 sm:items-center"
        >
          <input
            v-model="rejectNote"
            type="text"
            maxlength="500"
            placeholder="หมายเหตุ (ไม่บังคับ)"
            class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
            data-test="reject-note"
          />
          <button
            type="button"
            class="px-3 py-1.5 rounded-lg bg-slate-700 text-white text-xs font-bold hover:bg-slate-800 disabled:opacity-50"
            data-test="submit-reject"
            :disabled="decidingId !== null"
            @click="askReject(item)"
          >
            ยืนยันปฏิเสธ
          </button>
        </div>
      </div>
    </div>

    <!-- Inside <main>: a sibling would make the template a multi-root
         Fragment and break App.vue's <Transition mode="out-in">. -->
    <ConfirmDialog
      :show="pendingDecision !== null"
      :variant="pendingDecision?.kind === 'approve' ? 'danger' : 'warning'"
      :title="confirmTitle"
      :body="confirmBody"
      :confirm-label="
        pendingDecision?.kind === 'approve' ? 'อนุมัติและลบบัญชี' : 'ปฏิเสธคำขอ'
      "
      size="md"
      :busy="decidingId !== null"
      @confirm="confirmPendingDecision"
      @update:show="
        (v: boolean) => {
          if (!v) pendingDecision = null;
        }
      "
    />
  </main>
</template>
