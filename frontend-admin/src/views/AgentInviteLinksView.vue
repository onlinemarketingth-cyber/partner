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
import LinkQrModal from '@/design-system/components/LinkQrModal.vue'
import { useI18n } from '@/composables/useI18n'

const { lang, td } = useI18n()

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
      e instanceof ApiError ? `${td('team.load_failed')} (${e.status})` : td('team.load_failed')
  } finally {
    linksLoading.value = false
  }
}

function linksForAgent(agentId: number): AgentInviteLink[] {
  return inviteLinks.value.filter((l) => l.agent_id === agentId)
}

const agentNameById = computed(() => new Map(agents.value.map((a) => [a.id, a.name])))
function linkOwnerName(link: AgentInviteLink): string {
  return agentNameById.value.get(link.agent_id) ?? td('team.agent_hash', '', { id: link.agent_id })
}

// Company-wide list, optionally narrowed to one agent (?agent=<id> deep link
// from <AgentEditModal>'s "ดูในแท็บ ลิงก์ชวนทีม" shortcut).
const linkFilterAgentId = ref<number | null>(null)
const visibleLinks = computed(() =>
  linkFilterAgentId.value === null ? inviteLinks.value : linksForAgent(linkFilterAgentId.value),
)

function formatDate(iso: string | null): string {
  if (!iso) return '-'

  // Buddhist-era Thai months in TH, plain Gregorian in EN — same Date, only
  // the locale differs.
  return new Date(iso).toLocaleDateString(lang.value === 'EN' ? 'en-GB' : 'th-TH', { dateStyle: 'medium' })
}
/** NULL max_uses is "unlimited", never "0" — the two mean opposite things. */
function linkUsageLabel(link: AgentInviteLink): string {
  return link.max_uses === null
    ? String(link.used_count)
    : td('links.used_of', '', { used: link.used_count, max: link.max_uses })
}
/** 0-100, or null when there is no ceiling to fill. */
function linkUsagePercent(link: AgentInviteLink): number | null {
  if (link.max_uses === null || link.max_uses === 0) return null

  return Math.min(100, Math.round((link.used_count / link.max_uses) * 100))
}
function linkExpiryLabel(link: AgentInviteLink): string {
  return link.expires_at ? formatDate(link.expires_at) : td('links.no_expiry')
}
function linkStatus(link: AgentInviteLink): { label: string; usable: boolean } {
  if (link.is_usable) return { label: td('team.status_active'), usable: true }
  if (link.revoked_at) return { label: td('team.status_revoked'), usable: false }

  return { label: td('team.status_exhausted'), usable: false }
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
 * QR (TASK-240, reworked 2026-09-01) — one dialog for the whole table, not a
 * per-row inline panel. See LinkQrModal.vue for why the row shows only an
 * icon: a QR sized to a table row would be smaller than the panel it
 * replaced, and a thumbnail in every row would generate QR codes nobody
 * asked to see, which is exactly what the old per-row cache existed to avoid.
 */
const qrLink = ref<AgentInviteLink | null>(null)

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
    errorMessage.value =
      e instanceof ApiError ? `${td('team.revoke_failed')} (${e.status})` : td('team.revoke_failed')
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
      :title="td('team.title')"
      :subtitle="td('team.subtitle')"
      accent-color="brand"
      storage-key="agent-invite-links"
    />

    <CompanyScopeNotice v-if="!embedded" :action="td('team.scope_action')" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-bold text-slate-900">{{ td('team.card_title') }}</p>
          <p class="text-xs text-slate-500 mt-1">
            {{ td('team.card_help') }}
          </p>
        </div>
        <button class="btn-secondary shrink-0" :disabled="linksLoading" @click="loadInviteLinks">
          {{ linksLoading ? td('common.loading') : td('common.refresh') }}
        </button>
      </div>
      <div v-if="linkFilterAgentId !== null" class="mt-3 flex items-center gap-2">
        <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-1 rounded-lg">
          {{
            td('team.filter_showing', '', {
              name: agentNameById.get(linkFilterAgentId) ?? td('team.agent_hash', '', { id: linkFilterAgentId }),
            })
          }}
        </span>
        <button class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="linkFilterAgentId = null">
          {{ td('team.filter_clear') }}
        </button>
      </div>
    </div>

    <LoadingSkeleton v-if="linksLoading && !inviteLinks.length" type="list" :rows="3" class="mt-4" />
    <EmptyState v-else-if="!visibleLinks.length" icon="link" :title="td('links.empty_team')" class="mt-4" />
    <!--
      2026-09-01 (human decision) — a TABLE, matching ลิงก์สมัครตัวแทน. The
      orphan warning stays a full-width row UNDER its link rather than a
      column: it applies to a minority of rows, and a column that is empty
      nine times out of ten spends horizontal space to say nothing.
    -->
    <div v-else class="mt-4 bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 text-[11px] text-slate-500">
            <th class="px-3 py-2 font-bold w-10"><span class="sr-only">{{ td('links.col_qr') }}</span></th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_name') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_owner') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_link') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_used') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_expires') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_status') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_created') }}</th>
            <th class="text-right px-4 py-2 font-bold"><span class="sr-only">{{ td('links.col_actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="link in visibleLinks" :key="link.id">
            <tr class="border-t border-slate-100 align-middle" :class="link.revoked_at ? 'opacity-60' : ''">
              <td class="px-3 py-2">
                <button
                  type="button"
                  data-test="toggle-qr"
                  class="w-8 h-8 rounded-lg text-slate-500 hover:text-brand-600 hover:bg-brand-50 inline-flex items-center justify-center transition"
                  :title="td('links.qr_open')"
                  :aria-label="td('links.qr_open')"
                  @click="qrLink = link"
                >
                  <Icon name="qr_code" :size="18" />
                </button>
              </td>
              <td class="px-4 py-2 min-w-0">
                <p class="font-bold text-slate-800 truncate max-w-[200px]">{{ link.label || td('links.untitled') }}</p>
              </td>
              <td class="px-4 py-2 text-slate-600 truncate max-w-[160px]">{{ linkOwnerName(link) }}</td>
              <td class="px-4 py-2">
                <button
                  class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-700 hover:text-brand-800 max-w-[240px]"
                  :title="link.public_url"
                  @click="copyInviteLink(link)"
                >
                  <span class="truncate">{{ link.public_url }}</span>
                  <Icon :name="copiedId === link.id ? 'check' : 'copy'" :size="13" class="shrink-0" />
                  <span class="shrink-0 font-normal text-slate-400">
                    {{ copiedId === link.id ? td('common.copied') : td('common.copy') }}
                  </span>
                </button>
              </td>
              <td class="px-4 py-2 whitespace-nowrap">
                <span class="text-slate-700 tabular-nums">{{ linkUsageLabel(link) }}</span>
                <span v-if="link.max_uses === null" class="text-[11px] text-slate-400">
                  · {{ td('links.unlimited') }}
                </span>
                <div
                  class="mt-1 h-1 w-20 rounded-full overflow-hidden"
                  :class="linkUsagePercent(link) === null ? 'bg-slate-100' : 'bg-slate-200'"
                >
                  <div
                    v-if="linkUsagePercent(link) !== null"
                    class="h-full bg-brand-500 rounded-full"
                    :style="{ width: linkUsagePercent(link) + '%' }"
                  />
                </div>
              </td>
              <td
                class="px-4 py-2 text-xs whitespace-nowrap"
                :class="link.expires_at ? 'text-slate-600' : 'text-slate-400'"
              >
                {{ linkExpiryLabel(link) }}
              </td>
              <td class="px-4 py-2">
                <span
                  class="text-[11px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap"
                  :class="linkStatus(link).usable ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ linkStatus(link).label }}
                </span>
              </td>
              <td class="px-4 py-2 text-xs text-slate-500 whitespace-nowrap">{{ formatDate(link.created_at) }}</td>
              <td class="px-4 py-2 text-right">
                <button
                  v-if="!link.revoked_at"
                  class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 whitespace-nowrap"
                  @click="askRevokeLink(link)"
                >
                  {{ td('links.revoke_team') }}
                </button>
              </td>
            </tr>
            <tr v-if="isOrphanedByFlag(link)" class="border-t border-amber-100 bg-amber-50/60">
              <td></td>
              <td colspan="8" class="px-4 py-1.5 text-xs text-amber-700">{{ td('links.orphan_warning') }}</td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <LinkQrModal
      :url="qrLink?.public_url ?? null"
      :filename="qrLink ? `invite-qr-${qrLink.id}` : 'invite-qr'"
      :caption="td('links.caption_team')"
      @close="qrLink = null"
    />

    <ConfirmDialog
      v-model:show="showLinkRevokeConfirm"
      variant="danger"
      :title="td('team.revoke_title')"
      :body="
        pendingLinkRevoke
          ? td('team.revoke_body', '', {
              label: pendingLinkRevoke.label || td('links.untitled'),
              owner: linkOwnerName(pendingLinkRevoke),
            })
          : ''
      "
      :busy="revokingLink"
      @confirm="confirmRevokeLink"
    />
  </main>
</template>
