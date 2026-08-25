<script setup lang="ts">
/**
 * AgentInviteLinksView — "ลิงก์ชวนทีม" sub-page of "จัดการตัวแทน" (TASK-204).
 *
 * Split out of AgentManagementView.vue's "ลิงก์ชวนทีม" tab (TASK-117 /
 * ADR-025 §7) — the company-wide recruit-link oversight surface ADR-025 §7
 * traded for accepting team-leader self-approval: every link, whose it is,
 * how much quota is left, and a revoke, reachable without an admin already
 * suspecting someone.
 *
 * Owns its OWN light roster fetch. The old page fed `linkOwnerName()` /
 * `isOrphanedByFlag()` from its shared `agents` ref (loaded once for the
 * whole 5-tab page); this route has no other reason to hold the roster, so
 * it fetches its own copy of just enough of it (name + is_team_leader) to
 * resolve link ownership. This is a DIFFERENT concern from AgentRosterView's
 * merge — that page fetches the roster to RENDER it (twice, filtered, would
 * be wasteful); this page fetches it only as a lookup table for a handful of
 * link rows.
 *
 * `?agent=<id>` — the modal's "ดูในแท็บ ลิงก์ชวนทีม" shortcut
 * (AgentEditModal's `show-links` emit, handled by AgentRosterView.vue) now
 * arrives here as a real navigation with a query param, instead of an
 * internal tab+filter flip on the same page.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { type AgentItem, fetchAllPages } from './agentEdit'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { generateQrDataUrl } from '@/utils/qrCode'

/**
 * TASK-113 — AgentInviteLinkResource, field for field.
 *
 * `max_uses` and `expires_at` are NULLABLE and null means UNLIMITED
 * (ADR-025 §3) — every render path branches on `=== null`, never coerced to
 * a falsy number (which would report the exact opposite of the truth).
 */
interface AgentInviteLink {
  id: number
  company_id: number
  agent_id: number
  label: string | null
  token: string
  public_url: string
  used_count: number
  max_uses: number | null
  expires_at: string | null
  revoked_at: string | null
  /**
   * The server's own verdict from AgentInviteLink::isUsable() — covers the
   * LINK's own state only (revoked / expired / quota). The INVITER's state
   * (deactivated, de-flagged, moved company) is checked separately at
   * registration time (RegistrationService::resolveActiveInviter), so a link
   * owned by a de-flagged agent still reports is_usable = true here while
   * refusing every real signup — see isOrphanedByFlag() below.
   */
  is_usable: boolean
  created_at: string
}

const route = useRoute()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

const errorMessage = ref('')

// Light roster — name + is_team_leader only, fetched independently of
// AgentRosterView.vue (see file docblock).
const agents = ref<AgentItem[]>([])
const inviteLinks = ref<AgentInviteLink[]>([])
const linksLoading = ref(false)

async function loadInviteLinks() {
  linksLoading.value = true
  try {
    const [links, roster] = await Promise.all([
      fetchAllPages<AgentInviteLink>('/agent-invite-links'),
      fetchAllPages<AgentItem>('/users?include_inactive=1'),
    ])
    inviteLinks.value = links
    agents.value = roster
  } catch (e) {
    errorMessage.value =
      e instanceof ApiError ? `โหลดลิงก์ชวนเข้าทีมไม่สำเร็จ (${e.status})` : 'โหลดลิงก์ชวนเข้าทีมไม่สำเร็จ'
  } finally {
    linksLoading.value = false
  }
}

function linksForAgent(agentId: number): AgentInviteLink[] {
  return inviteLinks.value.filter((l) => l.agent_id === agentId)
}

const agentNameById = computed(() => new Map(agents.value.map((a) => [a.id, a.name])))
function linkOwnerName(link: AgentInviteLink): string {
  return agentNameById.value.get(link.agent_id) ?? `ตัวแทน #${link.agent_id}`
}

// Company-wide list, optionally narrowed to one agent (?agent=<id> deep link
// from <AgentEditModal>'s "ดูในแท็บ ลิงก์ชวนทีม" shortcut).
const linkFilterAgentId = ref<number | null>(null)
const visibleLinks = computed(() =>
  linkFilterAgentId.value === null ? inviteLinks.value : linksForAgent(linkFilterAgentId.value),
)

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
function linkUsageLabel(link: AgentInviteLink): string {
  return link.max_uses === null
    ? `ใช้ไปแล้ว ${link.used_count} คน · ไม่จำกัดจำนวน`
    : `ใช้ไปแล้ว ${link.used_count} / ${link.max_uses} คน`
}
function linkExpiryLabel(link: AgentInviteLink): string {
  return link.expires_at ? `หมดอายุ ${formatDate(link.expires_at)}` : 'ไม่จำกัดวันหมดอายุ'
}
function linkStatus(link: AgentInviteLink): { label: string; usable: boolean } {
  if (link.is_usable) return { label: 'ใช้งานได้', usable: true }
  if (link.revoked_at) return { label: 'ยกเลิกแล้ว', usable: false }
  return { label: 'หมดอายุ / ครบจำนวนแล้ว', usable: false }
}
/**
 * True when the row's green "ใช้งานได้" pill would mislead — see the
 * interface's own docblock above.
 */
function isOrphanedByFlag(link: AgentInviteLink): boolean {
  const owner = agents.value.find((a) => a.id === link.agent_id)
  return link.is_usable && owner !== undefined && !owner.is_team_leader
}

// ── Copy + QR (TASK-240) ───────────────────────────────────────────────
/**
 * This screen never rendered `public_url` at all before TASK-240 — the row
 * showed everything about a link except the link itself. Adding a QR here
 * with nothing to scan-and-compare it against, or copy by hand as a
 * fallback when scanning isn't convenient, would have shipped half a
 * feature, so both land together.
 */
const copiedId = ref<number | null>(null)

async function copyInviteLink(link: AgentInviteLink) {
  try {
    await navigator.clipboard.writeText(link.public_url)
    copiedId.value = link.id
    setTimeout(() => {
      if (copiedId.value === link.id) copiedId.value = null
    }, 2000)
  } catch {
    // Clipboard permission denied, or an insecure context — the URL is
    // still on screen and selectable.
  }
}

/**
 * Generated on demand, one row at a time — see the same note on
 * CompanySignupLinksView.vue's identical cache, which this mirrors.
 */
const qrDataUrl = ref<Map<number, string>>(new Map())
const openQrId = ref<number | null>(null)
const qrGenerating = ref<number | null>(null)

async function toggleQr(link: AgentInviteLink) {
  if (openQrId.value === link.id) {
    openQrId.value = null

    return
  }
  openQrId.value = link.id
  if (qrDataUrl.value.has(link.id)) return

  qrGenerating.value = link.id
  const dataUrl = await generateQrDataUrl(link.public_url, 220)
  qrDataUrl.value.set(link.id, dataUrl)
  if (qrGenerating.value === link.id) qrGenerating.value = null
}

function downloadQr(link: AgentInviteLink): void {
  const dataUrl = qrDataUrl.value.get(link.id)
  if (!dataUrl) return
  const a = document.createElement('a')
  a.href = dataUrl
  a.download = `invite-qr-${link.id}.png`
  a.click()
}

// Admin revoke — soft (revoked_at), confirmed rather than one-click: there is
// no un-revoke endpoint from this screen.
const pendingLinkRevoke = ref<AgentInviteLink | null>(null)
const revokingLink = ref(false)
const showLinkRevokeConfirm = computed({
  get: () => pendingLinkRevoke.value !== null,
  set: (v: boolean) => {
    if (!v) pendingLinkRevoke.value = null
  },
})
function askRevokeLink(link: AgentInviteLink) {
  pendingLinkRevoke.value = link
}
async function confirmRevokeLink() {
  const link = pendingLinkRevoke.value
  if (!link) return
  revokingLink.value = true
  try {
    await api.delete(`/agent-invite-links/${link.id}`)
    // Re-read rather than patching the row locally: DELETE answers 204 with
    // no body and is_usable is the server's verdict, not ours.
    await loadInviteLinks()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ยกเลิกลิงก์ไม่สำเร็จ (${e.status})` : 'ยกเลิกลิงก์ไม่สำเร็จ'
  } finally {
    revokingLink.value = false
    pendingLinkRevoke.value = null
  }
}

onMounted(() => {
  const agentParam = Number(route.query.agent)
  if (Number.isFinite(agentParam) && agentParam > 0) linkFilterAgentId.value = agentParam
  loadInviteLinks()
})

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadInviteLinks() })

/**
 * Rendered inside LinksHubView's tab bar rather than as its own page
 * (2026-08-22 — the three link screens became one page with three tabs).
 *
 * The hub owns the HeroHeader and the company-scope notice, so `embedded`
 * suppresses this file's copies of both. Nothing else changes: every fetch,
 * filter, mutation and watcher here is untouched. Rewriting them into the
 * hub would have made a second copy of working code, which is the drift this
 * codebase keeps paying for.
 */
defineProps<{ embedded?: boolean }>()

</script>

<template>
  <main :class="embedded ? '' : 'min-h-screen px-4 py-6 lg:px-8'">
    <HeroHeader
      v-if="!embedded"
      icon="link"
      title="ลิงก์ชวนทีม"
      subtitle="ลิงก์ชวนเข้าทีมทั้งหมดที่หัวหน้าทีมสร้างไว้ (ADR-025 §7)"
      accent-color="brand"
      storage-key="agent-invite-links"
    />

    <CompanyScopeNotice v-if="!embedded" action="จัดการลิงก์ชวนทีม" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-bold text-slate-900">ลิงก์ชวนเข้าทีมทั้งหมดในบริษัท</p>
          <p class="text-xs text-slate-500 mt-1">
            หัวหน้าทีมสร้างลิงก์เหล่านี้เพื่อให้คนใหม่สมัครเข้ามาอยู่ใต้ตัวเอง — ผู้ดูแลดูได้อย่างเดียว
            และยกเลิกได้ แต่สร้างแทนไม่ได้
          </p>
        </div>
        <button class="btn-secondary shrink-0" :disabled="linksLoading" @click="loadInviteLinks">
          {{ linksLoading ? 'กำลังโหลด...' : 'รีเฟรช' }}
        </button>
      </div>
      <div v-if="linkFilterAgentId !== null" class="mt-3 flex items-center gap-2">
        <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-1 rounded-lg">
          แสดงเฉพาะลิงก์ของ {{ agentNameById.get(linkFilterAgentId) ?? `ตัวแทน #${linkFilterAgentId}` }}
        </span>
        <button class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="linkFilterAgentId = null">
          ล้างตัวกรอง
        </button>
      </div>
    </div>

    <LoadingSkeleton v-if="linksLoading && !inviteLinks.length" type="list" :rows="3" class="mt-4" />
    <EmptyState v-else-if="!visibleLinks.length" icon="link" title="ยังไม่มีลิงก์ชวนเข้าทีมในบริษัทนี้" class="mt-4" />
    <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
      <div v-for="link in visibleLinks" :key="link.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="link" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">
                {{ link.label || 'ลิงก์ไม่มีชื่อ' }}
                <span class="text-xs font-normal text-slate-400">· เจ้าของ: {{ linkOwnerName(link) }}</span>
              </p>
              <button
                class="mt-1 flex items-center gap-1.5 text-xs font-bold text-brand-700 hover:text-brand-800 max-w-full"
                :title="link.public_url"
                @click="copyInviteLink(link)"
              >
                <span class="truncate">{{ link.public_url }}</span>
                <Icon :name="copiedId === link.id ? 'check' : 'copy'" :size="13" class="shrink-0" />
                <span class="shrink-0 font-normal text-slate-400">
                  {{ copiedId === link.id ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                </span>
              </button>
              <p class="text-xs text-slate-500 mt-1">{{ linkUsageLabel(link) }} · {{ linkExpiryLabel(link) }}</p>
              <p class="text-xs text-slate-400">สร้างเมื่อ {{ formatDate(link.created_at) }}</p>
              <p v-if="isOrphanedByFlag(link)" class="text-xs text-amber-600 mt-1">
                เจ้าของลิงก์ไม่มีสิทธิ์หัวหน้าทีมแล้ว — ลิงก์นี้จะสมัครไม่ผ่านจริง แม้สถานะจะขึ้นว่าใช้งานได้
              </p>
            </div>
          </div>
          <div class="flex flex-col items-end gap-1.5 shrink-0">
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-lg"
              :class="linkStatus(link).usable ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ linkStatus(link).label }}
            </span>
            <button
              type="button"
              class="text-xs font-bold text-slate-500 hover:text-slate-700 px-2 py-1 inline-flex items-center gap-1"
              @click="toggleQr(link)"
            >
              <Icon name="qr_code" :size="13" />
              {{ openQrId === link.id ? 'ซ่อน QR' : 'QR' }}
            </button>
            <button
              v-if="!link.revoked_at"
              class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1"
              @click="askRevokeLink(link)"
            >
              ยกเลิกลิงก์
            </button>
          </div>
        </div>

        <!-- TASK-240 — QR for this link, generated on demand (see toggleQr). -->
        <div v-if="openQrId === link.id" class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-3">
          <p v-if="qrGenerating === link.id" class="text-xs text-slate-400">กำลังสร้าง QR...</p>
          <template v-else-if="qrDataUrl.get(link.id)">
            <img
              :src="qrDataUrl.get(link.id)"
              alt="QR โค้ดลิงก์ชวนทีม"
              class="w-24 h-24 rounded-lg border border-slate-200 shrink-0 bg-white"
            />
            <div class="min-w-0">
              <p class="text-xs text-slate-500">สแกนเพื่อเปิดลิงก์ชวนทีมนี้โดยตรง</p>
              <button
                type="button"
                class="mt-1 text-xs font-bold text-brand-600 hover:text-brand-700"
                @click="downloadQr(link)"
              >
                ดาวน์โหลด QR
              </button>
            </div>
          </template>
        </div>
      </div>
    </TransitionGroup>

    <ConfirmDialog
      v-model:show="showLinkRevokeConfirm"
      variant="danger"
      title="ยกเลิกลิงก์ชวนเข้าทีม"
      :body="
        pendingLinkRevoke
          ? `ยกเลิกลิงก์ ${pendingLinkRevoke.label || 'ลิงก์ไม่มีชื่อ'} ของ${linkOwnerName(pendingLinkRevoke)} — คนที่กดลิงก์นี้จะสมัครไม่ได้อีก (คนที่สมัครไปแล้วยังอยู่ในทีมตามเดิม) และย้อนกลับไม่ได้`
          : ''
      "
      :busy="revokingLink"
      @confirm="confirmRevokeLink"
    />
  </main>
</template>
