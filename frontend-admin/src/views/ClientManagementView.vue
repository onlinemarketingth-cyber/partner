<script setup lang="ts">
/**
 * ClientManagementView — Admin read view over the Customer domain
 * (Phase 8, restructured in TASK-049). Company Admin sees every client
 * in their company (the API narrows server-side by role —
 * ClientController::index() only scopes to "own referred" for Agent).
 * This screen is a searchable LIST; clicking a row navigates to the
 * full-page client file (ClientFileView.vue) — the old detail drawer was
 * removed in TASK-049.
 *
 * Search: free-text `q` matches name/phone/email (partial); `national_id`
 * is EXACT match only and needs all 13 digits (PDPA — Section 6).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import ClientDetailModal from '@/design-system/components/ClientDetailModal.vue'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { fetchAllPages } from './agentEdit'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

interface ReferralItem {
  id: number
  product: { id: number; name: string; price_satang: number } | null
  branch: string
  preferred_time: string | null
  current_stage: { key: string; label: string }
}

/**
 * Per-row roll-ups (2026-08-22), from ClientController::withListRollups().
 *
 * OPTIONAL, not merely nullable, and that distinction is the contract: the
 * detail endpoint does not select them, so `undefined` means "nobody asked"
 * and must render as "ไม่ทราบ" rather than as "no orders". Declaring them
 * non-optional would let a confident wrong answer through — the same failure
 * that hid the missing `order` relation for weeks.
 */
interface ClientRollups {
  unpaid_orders_count?: number
  unpaid_amount_satang?: number
  awaiting_slip_orders_count?: number
  paid_orders_count?: number
  last_activity_at?: string | null
}
interface ClientItem {
  id: number
  referring_agent_id: number
  name: string
  phone: string
  email: string | null
  national_id_masked: string | null
  consent_given_at: string | null
  health_notes: string | null
  status: { key: string; label: string }
  lead_source: string | null
  date_of_birth: string | null
  address: string | null
  province: string | null
  occupation: string | null
  client_category_name?: string | null
  referrals: ReferralItem[]
  created_at: string
}
type ClientRow = ClientItem & ClientRollups
interface AgentOption {
  id: number
  name: string
}

const loading = ref(false)
const searching = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const clients = ref<ClientRow[]>([])
const agents = ref<AgentOption[]>([])

// Search state — free-text (partial name/phone/email) + national ID
// (exact, 13 digits). Both optional; empty params are omitted server-side.
const q = ref('')
const nationalId = ref('')

// TASK-050 drill-down — arriving from the "ทีมขาย" cockpit's "ดูลูกค้า"
// button (?agent=<id>): filter the list to that one agent's clients. Set
// from the query on mount, shown as a dismissible chip, and sent as
// agent_id= alongside any q/national_id search (they stack).
const agentFilter = ref<string | null>(null)

const agentNameById = computed(() => new Map(agents.value.map((a) => [a.id, a.name])))
const agentFilterName = computed(() =>
  agentFilter.value ? (agentNameById.value.get(Number(agentFilter.value)) ?? null) : null,
)

const kpis = computed(() => [
  { label: 'ลูกค้าทั้งหมด', value: clients.value.length },
  { label: 'ให้ความยินยอมแล้ว', value: clients.value.filter((c) => c.consent_given_at).length },
])

const route = useRoute()
/**
 * TASK-209 — client data is personal health data (PDPA). Human decision,
 * 2026-08-19: this screen may NEVER list across companies, not even
 * read-only. In ทุกบริษัท mode it fetches nothing at all and shows the
 * notice instead — stricter than every other screen, deliberately.
 */
const activeCompany = useActiveCompanyStore()
const router = useRouter()

function buildClientsPath(): string {
  const params = new URLSearchParams()
  if (q.value.trim()) params.set('q', q.value.trim())
  if (nationalId.value.trim()) params.set('national_id', nationalId.value.trim())
  if (agentFilter.value) params.set('agent_id', agentFilter.value)
  const qs = params.toString()

  return activeCompany.scopedPath(`/clients${qs ? `?${qs}` : ''}`)
}

async function loadAll() {
  // Hard stop: no company scoped => no request. See the store comment above.
  if (activeCompany.requiresCompanyPick) {
    clients.value = []
    agents.value = []
    hasLoadedOnce.value = true

    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    const [c, u] = await Promise.all([
      api.get<{ data: ClientItem[] }>(buildClientsPath()),
      /*
       * fetchAllPages, NOT a bare GET (fixed 2026-08-22).
       *
       * The screenshot showed "Agent: #3" on three of four rows. Cause:
       * UserController::index() calls paginate() with no argument — 15 per
       * page, no ?per_page support — so this loaded only the first 15 users,
       * ORDERED BY NAME, while clients come back ORDERED BY LATEST. There is
       * no reason a recent client's referrer sits in the alphabetically-first
       * fifteen, which is why the fallback fired for most rows rather than a
       * few. Thai Life has 27 users; 12 of them were never in the map.
       *
       * agentEdit.ts documents this exact bug being found and fixed once
       * before, and every other roster-consuming view was migrated to the
       * helper. This call site was missed.
       *
       * include_inactive=1 for the same reason every sibling uses it: a
       * deactivated agent is soft-deleted and excluded by default, so their
       * clients would still miss the lookup even in a small company.
       *
       * No scopedPath() wrapper — fetchAllPages applies the company scope
       * itself, and wrapping it again emits company_id twice.
       */
      fetchAllPages<AgentOption>('/users?include_inactive=1'),
    ])
    clients.value = c.data
    agents.value = u
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

async function search() {
  if (activeCompany.requiresCompanyPick) return
  searching.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: ClientItem[] }>(buildClientsPath())
    clients.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ค้นหาไม่สำเร็จ (${e.status})` : 'ค้นหาไม่สำเร็จ'
  } finally {
    searching.value = false
  }
}

function clearSearch() {
  q.value = ''
  nationalId.value = ''
  search()
}

// TASK-050 — remove the per-agent filter chip: drop agent_id, strip the
// ?agent= query so a refresh doesn't reapply it, then reload. Existing
// q/national_id search is left untouched.
function clearAgentFilter() {
  agentFilter.value = null
  router.replace({ query: {} })
  search()
}

onMounted(async () => {
  // TASK-050 — honor ?agent=<id> BEFORE the first load so the initial
  // fetch is already filtered to that agent's clients.
  const agentQ = route.query.agent
  if (typeof agentQ === 'string' && agentQ.trim()) {
    agentFilter.value = agentQ.trim()
  }
  await loadAll()
  // TASK-048/049 cross-link — arriving from the Referral drawer's
  // "ดูลูกค้า" link (?open=<clientId>): jump straight to that client's
  // full-page file (the old in-place drawer no longer exists), then strip
  // the query so a refresh doesn't re-trigger it.
  const openId = Number(route.query.open)
  if (openId) {
    router.replace({ query: {} })
    router.push({ name: 'client-file', params: { id: openId } })
  }
})

/*
 * THE ROW OPENS A MODAL, NOT A PAGE (human, 2026-08-22).
 *
 * "ผมคลิ๊กรายละเอียดแล้วพบว่า หน้าจอลายละเอียดนั้นดูแล้วกลับมาดูอีกคน" —
 * checking three customers meant three navigations and three trips back,
 * losing this list's scroll position, its search and its filters each time.
 *
 * The full page (`client-file`) stays: SalesTeamView deep-links into it, and
 * the `?open=` cross-link below still routes there because arriving from
 * another screen with an id in the URL is a navigation, not a peek.
 */
const openClientId = ref<number | null>(null)

function goToClientFile(client: ClientItem) {
  openClientId.value = client.id
}

/**
 * A saved edit must be reflected in the row behind the modal.
 *
 * Patching the row in place rather than refetching the list: the admin has a
 * search term and filters applied, and a reload could drop the row they just
 * edited out from under the open modal — or reorder the list beneath them.
 */
function onClientSaved(updated: { id: number; name: string; phone: string; email: string | null; status: { key: string; label: string } }) {
  const row = clients.value.find((c) => c.id === updated.id)
  if (!row) return

  row.name = updated.name
  row.phone = updated.phone
  row.email = updated.email
  row.status = updated.status
}

/**
 * The payment chip, per client.
 *
 * ── ORDER OF THE BRANCHES IS THE DESIGN ──
 *
 * A slip nobody has verified outranks money nobody has sent, because the
 * first is blocked on US and the second is blocked on the customer. An admin
 * scanning this list is looking for work they can do right now.
 *
 * `undefined` is kept apart from 0 deliberately: the detail endpoint does not
 * select these, and reporting "no orders" about a customer nobody counted
 * would be a confident wrong answer.
 */
type PaymentTone = 'slip' | 'unpaid' | 'paid' | 'none' | 'unknown'

interface PaymentChip {
  tone: PaymentTone
  label: string
}

function paymentChip(c: ClientRow): PaymentChip {
  if (c.unpaid_orders_count === undefined) return { tone: 'unknown', label: 'ไม่ทราบ' }

  if ((c.awaiting_slip_orders_count ?? 0) > 0) return { tone: 'slip', label: 'รอตรวจสลิป' }

  if (c.unpaid_orders_count > 0) {
    const satang = c.unpaid_amount_satang ?? 0
    return { tone: 'unpaid', label: `รอชำระ ฿${(satang / 100).toLocaleString('th-TH')}` }
  }

  if ((c.paid_orders_count ?? 0) > 0) return { tone: 'paid', label: 'ชำระแล้ว' }

  return { tone: 'none', label: '—' }
}

function paymentChipClasses(tone: PaymentTone): string {
  switch (tone) {
    case 'slip':
      // Amber, never green: a slip is a CLAIM of payment. Showing it as
      // settled is how an unverified transfer becomes revenue on a screen.
      return 'bg-amber-50 text-amber-700'
    case 'unpaid':
      return 'bg-rose-50 text-rose-700'
    case 'paid':
      return 'bg-emerald-50 text-emerald-700'
    default:
      return 'bg-slate-100 text-slate-400'
  }
}

/** The deal shown on the row: the newest, with a count of the rest. */
function primaryDeal(c: ClientRow): ReferralItem | null {
  return c.referrals.length > 0 ? (c.referrals[c.referrals.length - 1] ?? null) : null
}

/**
 * "3 วันที่แล้ว", not "20 สิงหาคม 2569".
 *
 * The question this column answers is "who has been left alone", and a full
 * date makes the reader do the subtraction before they can answer it.
 */
function relativeTime(iso: string | null | undefined): string {
  if (iso === undefined) return 'ไม่ทราบ'
  if (iso === null) return 'ยังไม่มีการติดต่อ'

  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return 'ยังไม่มีการติดต่อ'

  const minutes = Math.max(0, Math.round((Date.now() - then) / 60000))
  if (minutes < 60) return `${minutes} นาที`

  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} ชม.`

  const days = Math.round(hours / 24)
  if (days < 7) return `${days} วัน`

  const weeks = Math.round(days / 7)
  if (weeks < 5) return `${weeks} สัปดาห์`

  return `${Math.round(days / 30)} เดือน`
}

/** A row with work waiting on our side gets the attention treatment. */
function needsAttention(c: ClientRow): boolean {
  return (c.awaiting_slip_orders_count ?? 0) > 0
}

function agentNameFor(c: ClientRow): string {
  // "ไม่ระบุ", never `#3`. A raw database id means nothing to the person
  // reading it, and printing one is how a lookup miss got shipped.
  return agentNameById.value.get(c.referring_agent_id) ?? 'ไม่ระบุ'
}

function statusBadgeClasses(statusKey: string): string {
  switch (statusKey) {
    case 'interested':
      return 'bg-emerald-50 text-emerald-700'
    case 'not_interested':
      return 'bg-rose-50 text-rose-600'
    case 'contacted':
      return 'bg-brand-50 text-brand-700'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

// TASK-209 — refetch when the header company changes.
watch(() => activeCompany.companyId, () => loadAll())
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="users"
      title="ลูกค้า"
      subtitle="ลูกค้าทั้งหมดในบริษัท"
      description="ดูอย่างเดียว — การเพิ่มลูกค้า/อัปโหลดเอกสารยังเป็นสิทธิ์ของ Agent Portal เท่านั้น"
      :kpis="kpis"
      accent-color="brand"
      storage-key="admin-clients"
    />

    <CompanyScopeNotice action="ดูข้อมูลลูกค้า" />

    <!-- TASK-209 — everything below is inside this guard: no company scoped
         means no client data on screen at all (PDPA). -->
    <template v-if="!activeCompany.requiresCompanyPick">
    <!-- Search bar -->
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
            v-model="nationalId"
            type="text"
            inputmode="numeric"
            maxlength="13"
            placeholder="เลขบัตรประชาชน (13 หลัก)"
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
        ค้นด้วยเลขบัตรประชาชนได้เมื่อกรอกครบ 13 หลัก และต้องตรงทั้งหมด (exact match)
      </p>
    </div>

    <!-- TASK-050 — active per-agent filter chip (drill-down from "ทีมขาย"). -->
    <div v-if="agentFilter" class="mt-4 flex items-center gap-2">
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-brand-50 text-brand-700 text-xs font-bold">
        กรองตามตัวแทน: #{{ agentFilter }}<template v-if="agentFilterName"> · {{ agentFilterName }}</template>
        <button type="button" class="hover:text-brand-900" title="ล้างตัวกรองตัวแทน" @click="clearAgentFilter">
          <Icon name="close" :size="12" />
        </button>
      </span>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!clients.length" icon="users" title="ไม่พบลูกค้า" class="mt-4" />
      <!-- ═══ THE LIST (redesigned 2026-08-22) ═══
           Human: "ดูยากมาก … แค่นี้ น้อยไปมาก".

           A TABLE, not cards. Cards suit things read one at a time; a table
           suits things compared to each other, which is what this screen is
           for. The old row used justify-between, so on a 2554px screen the
           name sat at one edge and its status at the other with ~1500px of
           nothing between — reading one row meant sweeping the whole display,
           and there were no columns to scan down instead.

           Everything except the payment chip and the last-contact column was
           ALREADY in the payload. `referrals` in particular — product name,
           price and current stage, eager-loaded since TASK-049 and rendered
           nowhere. The single biggest improvement here cost no backend work
           at all; it was data arriving in the browser and being discarded. -->
      <div class="mt-4 bg-white/95 border border-slate-200 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full border-collapse min-w-[900px]">
            <thead>
              <tr class="bg-slate-50/70">
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200">ลูกค้า</th>
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200 whitespace-nowrap">สถานะ</th>
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200">ดีล / สินค้า</th>
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200 whitespace-nowrap">การชำระเงิน</th>
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200 whitespace-nowrap">Agent</th>
                <th class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3.5 border-b border-slate-200 whitespace-nowrap">อัปเดตล่าสุด</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="c in clients"
                :key="c.id"
                class="cursor-pointer hover:bg-slate-50 transition-colors"
                :class="needsAttention(c) ? 'bg-amber-50/40' : ''"
                @click="goToClientFile(c)"
              >
                <td class="px-4 py-3.5 border-b border-slate-100" :class="needsAttention(c) ? 'shadow-[inset_3px_0_0_theme(colors.amber.500)]' : ''">
                  <div class="flex items-center gap-2">
                    <p class="text-[15px] font-bold text-slate-900">{{ c.name }}</p>
                    <!-- Consent drops to an icon. It is a legal fact, not a
                         signal that anybody must act — it was louder than the
                         actual status before. -->
                    <Icon
                      v-if="c.consent_given_at"
                      name="shield_check"
                      :size="14"
                      class="text-emerald-500 shrink-0"
                      title="ให้ความยินยอมแล้ว"
                    />
                    <Icon v-else name="shield" :size="14" class="text-amber-400 shrink-0" title="ยังไม่ให้ความยินยอม" />
                  </div>
                  <!-- tabular-nums so digits line up down the column and the
                       eye can compare them without reading each one. -->
                  <p class="text-[13px] text-slate-500 tabular-nums">
                    {{ c.phone || 'ไม่ระบุเบอร์' }}
                    <span v-if="c.client_category_name" class="text-slate-400"> · {{ c.client_category_name }}</span>
                  </p>
                </td>

                <td class="px-4 py-3.5 border-b border-slate-100">
                  <span :class="['text-[12px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap', statusBadgeClasses(c.status.key)]">
                    {{ c.status.label }}
                  </span>
                </td>

                <td class="px-4 py-3.5 border-b border-slate-100">
                  <template v-if="primaryDeal(c)">
                    <p class="text-[14.5px] text-slate-800 leading-snug">
                      {{ primaryDeal(c)?.product?.name ?? 'ไม่ระบุสินค้า' }}
                      <span
                        v-if="c.referrals.length > 1"
                        class="text-[12px] font-bold text-slate-500 bg-slate-100 rounded px-1.5 ml-1 align-[1px]"
                      >+{{ c.referrals.length - 1 }}</span>
                    </p>
                    <p class="text-[13px] text-slate-500">{{ primaryDeal(c)?.current_stage.label }}</p>
                  </template>
                  <!-- Spelled out: an empty cell reads as "failed to load",
                       a dim sentence reads as an answer. -->
                  <span v-else class="text-[13.5px] text-slate-300">ยังไม่มีดีล</span>
                </td>

                <td class="px-4 py-3.5 border-b border-slate-100">
                  <span :class="['text-[12px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap tabular-nums', paymentChipClasses(paymentChip(c).tone)]">
                    {{ paymentChip(c).label }}
                  </span>
                </td>

                <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px]" :class="agentNameFor(c) === 'ไม่ระบุ' ? 'text-slate-300' : 'text-slate-700'">
                  {{ agentNameFor(c) }}
                </td>

                <td class="px-4 py-3.5 border-b border-slate-100 text-[13.5px] text-slate-500 whitespace-nowrap tabular-nums">
                  {{ relativeTime(c.last_activity_at) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
    </template>

    <!-- The client file, without leaving this list (2026-08-22). Renders
         nothing until a row is clicked; `clientId` null IS the closed state,
         so the modal owns no second "is it open" flag to fall out of sync. -->
    <ClientDetailModal
      :client-id="openClientId"
      @close="openClientId = null"
      @saved="onClientSaved"
    />
  </main>
</template>
