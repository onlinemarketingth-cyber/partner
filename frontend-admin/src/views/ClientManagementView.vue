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
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

interface ReferralItem {
  id: number
  product: { id: number; name: string; price_satang: number } | null
  branch: string
  preferred_time: string | null
  current_stage: { key: string; label: string }
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
  referrals: ReferralItem[]
  created_at: string
}
interface AgentOption {
  id: number
  name: string
}

const loading = ref(false)
const searching = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const clients = ref<ClientItem[]>([])
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
const router = useRouter()

function buildClientsPath(): string {
  const params = new URLSearchParams()
  if (q.value.trim()) params.set('q', q.value.trim())
  if (nationalId.value.trim()) params.set('national_id', nationalId.value.trim())
  if (agentFilter.value) params.set('agent_id', agentFilter.value)
  const qs = params.toString()
  return `/clients${qs ? `?${qs}` : ''}`
}

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [c, u] = await Promise.all([
      api.get<{ data: ClientItem[] }>(buildClientsPath()),
      api.get<{ data: AgentOption[] }>('/users'),
    ])
    clients.value = c.data
    agents.value = u.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

async function search() {
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

function goToClientFile(client: ClientItem) {
  router.push({ name: 'client-file', params: { id: client.id } })
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
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div
          v-for="c in clients"
          :key="c.id"
          class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between hover:shadow-sm transition-shadow cursor-pointer"
          @click="goToClientFile(c)"
        >
          <div class="flex items-start gap-3">
            <Icon name="user" :size="18" class="text-brand-600 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-slate-900">{{ c.name }}</p>
              <p class="text-xs text-slate-400">
                {{ c.phone }}<span v-if="c.email"> · {{ c.email }}</span> · Agent: {{ agentNameById.get(c.referring_agent_id) ?? `#${c.referring_agent_id}` }}
              </p>
              <p v-if="c.national_id_masked" class="text-xs text-slate-400 flex items-center gap-1">
                <Icon name="shield" :size="12" /> {{ c.national_id_masked }}
              </p>
            </div>
          </div>
          <div class="flex flex-col items-end gap-1 shrink-0">
            <span :class="['text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap', statusBadgeClasses(c.status.key)]">{{ c.status.label }}</span>
            <span v-if="c.consent_given_at" class="text-xs font-bold text-emerald-600 flex items-center gap-1">
              <Icon name="shield_check" :size="14" /> ให้ความยินยอมแล้ว
            </span>
            <span v-else class="text-xs font-bold text-amber-600">ยังไม่ยินยอม</span>
          </div>
        </div>
      </TransitionGroup>
    </template>
  </main>
</template>
