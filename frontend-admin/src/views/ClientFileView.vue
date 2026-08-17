<script setup lang="ts">
/**
 * ClientFileView — TASK-049. Full-page "แฟ้มทะเบียนลูกค้า" (client
 * registry file), replacing the old detail drawer on
 * ClientManagementView.vue. Read-only, same boundary as everywhere else
 * on the Admin side (creating/editing clients stays an Agent Portal
 * action); this screen only reads + downloads documents.
 *
 * Why a full page instead of a drawer: a client can have MANY referrals,
 * each with its own selling agent + sales-process timeline — the drawer
 * was too cramped to surface "1 ลูกค้า มีหลาย agent" clearly. The
 * per-referral stage-log timeline is lazy-loaded and cached per referral
 * id (re-expanding never refetches).
 *
 * PDPA (Section 6): national_id full value is only present in the API
 * payload for privileged viewers — fall back to national_id_masked
 * otherwise. health_notes stays in its own PDPA-flagged sub-box.
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
  agent: { id: number; name: string } | null
  /*
   * TASK-174 — OPTIONAL, not merely nullable, and that is the entire
   * mechanism by which this screen stops showing a co-agent once the
   * company's split is switched off.
   *
   * `ReferralResource` OMITS both keys while the switch is off (it does not
   * null them — the stored `co_agent_id` is deliberately preserved, spec §3),
   * so `r.co_agent` is `undefined` here and every `v-if="r.co_agent"` below
   * simply does not render. This screen therefore asks nothing: it does not
   * fetch the flag and does not repeat the predicate, because a second copy
   * of the rule is precisely what spec §4 forbids — the absent key IS the
   * server's answer.
   *
   * Declaring these `| null` instead would tell TS a missing field is a real
   * value, which is how a stale split gets rendered as "แบ่ง undefined%".
   */
  co_agent?: { id: number; name: string } | null
  split_percentage?: number | null
  branch: string
  preferred_time: string | null
  current_stage: { key: string; label: string }
  meeting_number: number | null
  submitted_at: string
}
interface ClientDetail {
  id: number
  referring_agent_id: number
  name: string
  phone: string
  email: string | null
  national_id_masked: string | null
  // Only present for privileged viewers (Section 6) — may be absent.
  national_id?: string | null
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
interface ClientDocumentItem {
  id: number
  original_filename: string
  size_bytes: number
}
interface ClientActivityItem {
  id: number
  logged_by_name: string
  type: { key: string; label: string }
  summary: string
  occurred_at: string
  follow_up_at: string | null
}
interface StageLogItem {
  id: number
  from_stage: { key: string; label: string } | null
  to_stage: { key: string; label: string }
  changed_by: { id: number; name: string } | null
  changed_at: string
}

const route = useRoute()
const router = useRouter()

const clientId = computed(() => Number(route.params.id))

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const client = ref<ClientDetail | null>(null)
const documents = ref<ClientDocumentItem[]>([])
const activities = ref<ClientActivityItem[]>([])

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [c, docs, acts] = await Promise.all([
      api.get<{ data: ClientDetail }>(`/clients/${clientId.value}`),
      api.get<{ data: ClientDocumentItem[] }>(`/clients/${clientId.value}/documents`),
      api.get<{ data: ClientActivityItem[] }>(`/clients/${clientId.value}/activities`),
    ])
    client.value = c.data
    documents.value = docs.data
    activities.value = acts.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const kpis = computed(() => {
  const c = client.value
  if (!c) return []
  return [
    { label: 'ดีล/สินค้าที่สนใจ', value: c.referrals.length },
    { label: 'Agent ที่เกี่ยวข้อง', value: relatedAgents.value.length },
    { label: 'เอกสารแนบ', value: documents.value.length },
  ]
})

// Section c — distinct agents touching this client: every referral's
// selling agent + co-agent, plus the referring agent. Deduped by id.
// Visually answers "1 ลูกค้า มีหลาย agent". referring agent has no name
// in the payload beyond an id, so it shows as "#id" unless that same
// person also appears as a seller/co-seller on some referral.
interface RelatedAgent {
  id: number
  name: string
  isReferring: boolean
  roles: string[]
}
const relatedAgents = computed<RelatedAgent[]>(() => {
  const c = client.value
  if (!c) return []
  const byId = new Map<number, RelatedAgent>()
  const add = (id: number, name: string, role: string) => {
    const existing = byId.get(id)
    if (existing) {
      if (!existing.roles.includes(role)) existing.roles.push(role)
    } else {
      byId.set(id, { id, name, isReferring: false, roles: [role] })
    }
  }
  for (const r of c.referrals) {
    if (r.agent) add(r.agent.id, r.agent.name, 'ผู้ขาย')
    if (r.co_agent) add(r.co_agent.id, r.co_agent.name, 'ผู้ขายร่วม')
  }
  const ref = byId.get(c.referring_agent_id)
  if (ref) {
    ref.isReferring = true
    if (!ref.roles.includes('ผู้แนะนำ')) ref.roles.push('ผู้แนะนำ')
  } else {
    byId.set(c.referring_agent_id, {
      id: c.referring_agent_id,
      name: `#${c.referring_agent_id}`,
      isReferring: true,
      roles: ['ผู้แนะนำ'],
    })
  }
  return [...byId.values()]
})

const nationalIdDisplay = computed(() => {
  const c = client.value
  if (!c) return 'ยังไม่ระบุ'
  return c.national_id ?? c.national_id_masked ?? 'ยังไม่ระบุ'
})

// ── Per-referral stage-log timeline (sales-process audit) ──────────
// Lazy-load + cache per referral id — re-expanding never refetches.
const expandedReferralId = ref<number | null>(null)
const stageLogsByReferral = ref<Record<number, StageLogItem[]>>({})
const loadingLogsFor = ref<number | null>(null)

async function toggleStageLogs(referralId: number) {
  if (expandedReferralId.value === referralId) {
    expandedReferralId.value = null
    return
  }
  expandedReferralId.value = referralId
  if (stageLogsByReferral.value[referralId]) return

  loadingLogsFor.value = referralId
  try {
    const res = await api.get<{ data: StageLogItem[] }>(`/referrals/${referralId}/stage-logs`)
    stageLogsByReferral.value[referralId] = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดประวัติไม่สำเร็จ (${e.status})` : 'โหลดประวัติไม่สำเร็จ'
  } finally {
    loadingLogsFor.value = null
  }
}

function goToPipeline(referralId: number) {
  router.push({ name: 'referral-pipeline-management', query: { open: String(referralId) } })
}
function goBackToList() {
  router.push({ name: 'client-management' })
}

async function downloadDocument(doc: ClientDocumentItem) {
  try {
    await api.download(`/client-documents/${doc.id}/download`, doc.original_filename)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ดาวน์โหลดไม่สำเร็จ (${e.status})` : 'ดาวน์โหลดไม่สำเร็จ'
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
function formatDateTime(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}
function formatDate(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'
  return new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' })
}
function formatMoney(satang: number): string {
  // BR-3: amounts are integer satang — divide by 100 only for display.
  return (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
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
    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="detail" />

    <template v-else>
      <HeroHeader
        icon="user"
        :title="client?.name ?? 'แฟ้มทะเบียนลูกค้า'"
        subtitle="แฟ้มทะเบียนลูกค้า"
        description="ดูอย่างเดียว — การแก้ไขข้อมูล/อัปโหลดเอกสารยังเป็นสิทธิ์ของ Agent Portal เท่านั้น"
        :kpis="kpis"
        accent-color="brand"
        storage-key="admin-client-file"
      >
        <template #before-icon>
          <button
            type="button"
            title="กลับไปหน้ารายชื่อลูกค้า"
            class="shrink-0 flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition text-xs font-bold whitespace-nowrap"
            @click="goBackToList"
          >
            <Icon name="list" :size="16" />
            <span class="hidden sm:inline">รายชื่อลูกค้า</span>
          </button>
        </template>
      </HeroHeader>

      <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
        {{ errorMessage }}
      </div>

      <EmptyState v-if="!client && hasLoadedOnce && !errorMessage" icon="user" title="ไม่พบข้อมูลลูกค้า" class="mt-4" />

      <template v-else-if="client">
        <!-- ═══ a. ข้อมูลทะเบียน (identity/registration) ═══ -->
        <section class="bg-white/95 border border-slate-200 rounded-xl p-5 mt-4">
          <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-3">
            <Icon name="user" :size="16" class="text-brand-600" /> ข้อมูลทะเบียน
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="phone" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">เบอร์โทร:</span> {{ client.phone }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="mail" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">อีเมล:</span> {{ client.email ?? 'ยังไม่ระบุ' }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="shield" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">เลขบัตรประชาชน:</span> {{ nationalIdDisplay }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="calendar" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">วันเกิด:</span> {{ formatDate(client.date_of_birth) }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="map_pin" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">จังหวัด:</span> {{ client.province ?? 'ยังไม่ระบุ' }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="building" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">อาชีพ:</span> {{ client.occupation ?? 'ยังไม่ระบุ' }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600 md:col-span-2">
              <Icon name="home" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">ที่อยู่:</span> {{ client.address ?? 'ยังไม่ระบุ' }}</span>
            </p>
            <p class="flex items-start gap-2 text-slate-600">
              <Icon name="cart" :size="14" class="text-slate-400 mt-0.5 shrink-0" />
              <span><span class="text-slate-400">ที่มา:</span> {{ client.lead_source ?? 'ยังไม่ระบุ' }}</span>
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-3 mt-4">
            <span :class="['text-xs font-bold px-2 py-0.5 rounded-lg', statusBadgeClasses(client.status.key)]">
              {{ client.status.label }}
            </span>
            <span v-if="client.consent_given_at" class="text-xs font-bold text-emerald-600 flex items-center gap-1">
              <Icon name="shield_check" :size="14" /> ให้ความยินยอมแล้ว · {{ formatDateTime(client.consent_given_at) }}
            </span>
            <span v-else class="text-xs font-bold text-amber-600">ยังไม่ยินยอม (PDPA)</span>
          </div>

          <div v-if="client.health_notes" class="mt-4 p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm text-slate-600">
            <span class="font-bold text-slate-700 flex items-center gap-1.5 mb-1 text-xs">
              <Icon name="shield" :size="14" class="text-amber-600" /> บันทึกสุขภาพ (PDPA — ข้อมูลอ่อนไหว)
            </span>
            {{ client.health_notes }}
          </div>
        </section>

        <!-- ═══ b. สินค้าที่สนใจ / ดีล ═══ -->
        <section class="bg-white/95 border border-slate-200 rounded-xl p-5 mt-4">
          <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-3">
            <Icon name="cart" :size="16" class="text-brand-600" /> สินค้าที่สนใจ / ดีล
          </h2>
          <EmptyState v-if="!client.referrals.length" icon="cart" title="ยังไม่มีสินค้าที่สนใจ" />
          <div v-else class="space-y-3">
            <div v-for="r in client.referrals" :key="r.id" class="border border-slate-200 rounded-xl p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-bold text-slate-900 truncate">{{ r.product?.name ?? 'ไม่ระบุสินค้า' }}</p>
                  <p class="text-xs text-slate-400 truncate mt-0.5">
                    <Icon name="building" :size="12" class="inline mr-0.5" />{{ r.branch }}
                    <span v-if="r.product"> · {{ formatMoney(r.product.price_satang) }} บาท</span>
                  </p>
                </div>
                <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-1 rounded-lg whitespace-nowrap shrink-0">
                  {{ r.current_stage.label }}
                  <span v-if="r.current_stage.key === 'ongoing_next_meeting' && r.meeting_number"> · นัดครั้งที่ {{ r.meeting_number }}</span>
                </span>
              </div>

              <!-- Selling agent — visually emphasised (the deal owner). -->
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-900 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1">
                  <Icon name="user" :size="14" class="text-brand-600" />
                  ผู้ขาย: {{ r.agent?.name ?? 'ยังไม่ระบุ' }}
                </span>
                <span
                  v-if="r.co_agent"
                  class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1"
                >
                  <Icon name="users" :size="12" class="text-slate-400" />
                  ผู้ขายร่วม: {{ r.co_agent.name }}
                  <span v-if="r.split_percentage != null" class="text-slate-400">· แบ่ง {{ r.split_percentage }}%</span>
                </span>
              </div>

              <div class="mt-3 flex items-center gap-4">
                <button
                  type="button"
                  class="text-xs font-bold text-brand-600 hover:text-brand-700 flex items-center gap-1"
                  @click="toggleStageLogs(r.id)"
                >
                  <Icon name="list" :size="12" />
                  {{ expandedReferralId === r.id ? 'ซ่อน process การขาย' : 'ดู process การขาย / log' }}
                </button>
                <button
                  type="button"
                  class="text-xs font-bold text-brand-600 hover:text-brand-700 flex items-center gap-1"
                  @click="goToPipeline(r.id)"
                >
                  <Icon name="pipeline" :size="12" />
                  ดูใน Pipeline
                </button>
              </div>

              <!-- Sales-process timeline (audit log). -->
              <div v-if="expandedReferralId === r.id" class="mt-3 pt-3 border-t border-slate-100">
                <p v-if="loadingLogsFor === r.id" class="text-xs text-slate-400">กำลังโหลด...</p>
                <EmptyState v-else-if="!stageLogsByReferral[r.id]?.length" icon="list" title="ยังไม่มีประวัติการเปลี่ยนสถานะ" />
                <ol v-else class="relative space-y-4 pl-5">
                  <li
                    v-for="log in stageLogsByReferral[r.id]"
                    :key="log.id"
                    class="relative"
                  >
                    <span class="absolute -left-5 top-1 w-2.5 h-2.5 rounded-full bg-brand-500 ring-4 ring-brand-50"></span>
                    <span class="absolute -left-[15px] top-4 bottom-[-16px] w-px bg-slate-200 last:hidden"></span>
                    <p class="text-sm font-bold text-slate-800">
                      <span v-if="log.from_stage" class="text-slate-400">{{ log.from_stage.label }} → </span>
                      {{ log.to_stage.label }}
                    </p>
                    <p class="text-xs text-slate-400 mt-0.5">
                      โดย {{ log.changed_by?.name ?? '—' }} · {{ formatDateTime(log.changed_at) }}
                    </p>
                  </li>
                </ol>
              </div>
            </div>
          </div>
        </section>

        <!-- ═══ c. Agent ที่เกี่ยวข้อง ═══ -->
        <section class="bg-white/95 border border-slate-200 rounded-xl p-5 mt-4">
          <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-1">
            <Icon name="users" :size="16" class="text-brand-600" /> Agent ที่เกี่ยวข้อง
          </h2>
          <p class="text-xs text-slate-400 mb-3">ลูกค้ารายนี้เกี่ยวข้องกับ Agent ทั้งหมด {{ relatedAgents.length }} คน</p>
          <div class="flex flex-wrap gap-2">
            <span
              v-for="a in relatedAgents"
              :key="a.id"
              class="inline-flex items-center gap-1.5 text-xs font-bold rounded-lg px-2.5 py-1.5 border"
              :class="a.isReferring ? 'bg-brand-50 border-brand-200 text-brand-700' : 'bg-slate-50 border-slate-200 text-slate-700'"
            >
              <Icon name="user" :size="12" />
              {{ a.name }}
              <span class="font-normal text-slate-400">· {{ a.roles.join(' / ') }}</span>
            </span>
          </div>
        </section>

        <!-- ═══ d. ประวัติการติดต่อ (activities) ═══ -->
        <section class="bg-white/95 border border-slate-200 rounded-xl p-5 mt-4">
          <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-3">
            <Icon name="chat" :size="16" class="text-brand-600" /> ประวัติการติดต่อ
          </h2>
          <EmptyState v-if="!activities.length" icon="chat" title="ยังไม่มีประวัติการติดต่อ" />
          <div v-else class="space-y-2">
            <div v-for="a in activities" :key="a.id" class="p-3 rounded-lg border border-slate-200 text-sm">
              <div class="min-w-0">
                <span class="text-xs font-bold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-lg">{{ a.type.label }}</span>
                <span class="text-xs text-slate-400 ml-2">{{ formatDateTime(a.occurred_at) }} · {{ a.logged_by_name }}</span>
              </div>
              <p class="mt-1 text-slate-700">{{ a.summary }}</p>
              <p v-if="a.follow_up_at" class="mt-1 text-xs text-amber-600">ติดตามอีกครั้ง: {{ formatDateTime(a.follow_up_at) }}</p>
            </div>
          </div>
        </section>

        <!-- ═══ e. เอกสารแนบ (documents) ═══ -->
        <section class="bg-white/95 border border-slate-200 rounded-xl p-5 mt-4">
          <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-3">
            <Icon name="document" :size="16" class="text-brand-600" /> เอกสารแนบ
          </h2>
          <EmptyState v-if="!documents.length" icon="document" title="ยังไม่มีเอกสาร" />
          <div v-else class="space-y-2">
            <div v-for="d in documents" :key="d.id" class="flex items-center justify-between p-2 rounded-lg border border-slate-200 text-sm">
              <div class="truncate">
                <p class="font-bold text-slate-800 truncate">{{ d.original_filename }}</p>
                <p class="text-xs text-slate-400">{{ formatSize(d.size_bytes) }}</p>
              </div>
              <button class="text-brand-600 hover:text-brand-700 shrink-0 ml-2" title="ดาวน์โหลด" @click="downloadDocument(d)">
                <Icon name="download" :size="16" />
              </button>
            </div>
          </div>
        </section>
      </template>
    </template>
  </main>
</template>
