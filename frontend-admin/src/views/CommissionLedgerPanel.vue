<script setup lang="ts">
/**
 * CommissionLedgerPanel — every commission row in the company, one line each,
 * with the one write action this app is allowed to make: "จ่ายแล้ว".
 *
 * ── 2026-09-15: THIS WAS A WHOLE MENU ITEM, AND IT SHOULD NOT HAVE BEEN ──
 *
 * Owner: "ค่าคอมมิชชั่นมันกระจายอยู่หลายเมนูมาก ผมอยากรวมเป็น Menu ที่เดียว".
 *
 * It lived at /commission as "จ่ายคอมมิชชั่น" while a second screen —
 * "ค่าคอมมิชชั่น", filed under จัดการตัวแทน — grouped the SAME rows by agent,
 * carried the bank details and produced the payout CSV. Worse, that screen's
 * own drill-down already fetched this exact endpoint with ?agent_id=. So a
 * payout run meant two menus, two pillars and the same money twice: totals and
 * the file on one page, the button that actually marks anything paid on the
 * other.
 *
 * Same shape as the 3.3-versus-resolution-table duplication a day earlier, and
 * the same fix: this is now a VIEW inside CommissionPayoutsView ("รายรายการ"),
 * not a destination. It keeps its own fetch and filters because the two views
 * genuinely ask different questions — per agent for paying people, per row for
 * auditing one deal — and sharing a query would make each worse.
 *
 * WHAT IT NO LONGER OWNS: the page header, the company-scope notice and the
 * KPI tiles' housing. The host draws those once for both views.
 *
 * BR-3: money is integer satang server-side; divided by 100 only here, at the
 * display layer. BR-4: entries are immutable except payment_status/paid_at —
 * no other field is ever editable here.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const route = useRoute()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()

/**
 * TASK-215 — `earned_via` and `override_source_agent` were being sent by
 * CommissionLedgerResource and thrown away by this screen.
 *
 * Found during UAT-016 (2026-08-19). One closed sale wrote three rows —
 * the seller's own 3.00%, the team leader's 2.50% override, and a 10%
 * campaign bonus — and this list rendered all three IDENTICALLY: same
 * client, same product, same date, three different amounts. Reading them
 * carefully, the honest first conclusion was "this sale paid the same
 * agent twice".
 *
 * It had not. But an accountant reconciling a payout run would reach that
 * same wrong conclusion, and the Resource's own docblock already says
 * earned_via is "the single most important field" for answering how a row
 * was calculated. The data was one property away the whole time.
 */
type EarnedVia =
  | 'direct'
  | 'renewal'
  | 'override'
  | 'binary_match'
  | 'matrix_override'
  | 'stairstep_override'
  | 'generation_override'
  | 'promotion_bonus'

interface LedgerItem {
  id: number
  referral: { id: number; client: { id: number; name: string } | null } | null
  agent: { id: number; name: string } | null
  cert_tier_at_time: { id: number; key: string; name: string } | null
  product: { id: number; name: string } | null
  rate_type_applied: 'percentage' | 'fixed_satang'
  rate_applied: number
  amount_satang: number
  payment_status: 'pending' | 'paid'
  earned_via: EarnedVia | null
  /** Whose sale produced this override — null on a row the agent earned themselves. */
  override_source_agent: { id: number; name: string } | null
  /**
   * 2026-09-15 — the payee is the COMPANY itself, not a person.
   *
   * A company can hold a seat at the top of its own hierarchy and be paid a
   * leader's override (ขั้นตอนที่ 4.2). Server-computed, never inferred from
   * the name: two companies may call their seat anything, and a row that
   * guessed would eventually queue somebody's real pay as a non-payment.
   */
  is_company_share?: boolean
  paid_at: string | null
  created_at: string
}

/**
 * Thai labels + colour per payout type. Deliberately NOT one neutral grey
 * chip for everything: "ค่าคอมของตัวเอง" and "ค่าคอมหัวหน้าทีม" land in
 * different people's pockets for different reasons, and the whole point of
 * the badge is that the difference is visible at a glance.
 */
const earnedViaLabels: Record<EarnedVia, { label: string; cls: string }> = {
  direct: { label: 'ค่าคอมของตัวเอง', cls: 'bg-brand-50 text-brand-700' },
  renewal: { label: 'ค่าคอมปีต่ออายุ', cls: 'bg-sky-50 text-sky-700' },
  override: { label: 'ค่าคอมหัวหน้าทีม', cls: 'bg-amber-100 text-amber-800' },
  binary_match: { label: 'Binary (จับคู่ขา)', cls: 'bg-violet-50 text-violet-700' },
  matrix_override: { label: 'Matrix (ชั้นล่าง)', cls: 'bg-violet-50 text-violet-700' },
  stairstep_override: { label: 'อันดับ (ส่วนต่าง)', cls: 'bg-violet-50 text-violet-700' },
  generation_override: { label: 'Generation', cls: 'bg-violet-50 text-violet-700' },
  promotion_bonus: { label: 'โบนัสแคมเปญ', cls: 'bg-emerald-50 text-emerald-700' },
}

function earnedViaBadge(e: LedgerItem): { label: string; cls: string } {
  // An unknown/absent value must read as unknown, never silently as
  // "direct" — mislabelling a payout type is the exact failure this fix
  // exists to remove.
  return e.earned_via
    ? (earnedViaLabels[e.earned_via] ?? { label: e.earned_via, cls: 'bg-slate-100 text-slate-600' })
    : { label: 'ไม่ระบุประเภท', cls: 'bg-slate-100 text-slate-500' }
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const entries = ref<LedgerItem[]>([])

const kpis = computed(() => {
  const pending = entries.value.filter((e) => e.payment_status === 'pending')
  const paid = entries.value.filter((e) => e.payment_status === 'paid')
  return [
    { label: 'รอจ่าย', value: formatSatang(pending.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'จ่ายแล้ว', value: formatSatang(paid.reduce((sum, e) => sum + e.amount_satang, 0)) },
    { label: 'รายการทั้งหมด', value: entries.value.length },
  ]
})

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: LedgerItem[] }>(activeCompany.scopedPath('/commission-ledger'))
    entries.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

/*
 * 2026-09-08 — the tab can be named in the URL, so the dashboard's
 * "ค่าคอมที่จ่ายให้ตัวแทนแล้ว" card lands on the set it counted rather than on
 * this screen's default (รอจ่าย), which is a different number entirely.
 *
 * Read ONCE at setup, not watched: this is a starting point handed over by a
 * link, and re-applying it would fight the person who then clicks another tab.
 * An unknown value falls through to the default rather than showing nothing.
 */
const TAB_IDS = ['all', 'pending', 'paid'] as const
type TabId = (typeof TAB_IDS)[number]

function tabFromQuery(): TabId {
  const asked = route.query.tab

  return typeof asked === 'string' && (TAB_IDS as readonly string[]).includes(asked)
    ? (asked as TabId)
    : 'pending'
}

const activeTab = ref<TabId>(tabFromQuery())
const tabs = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: entries.value.length },
  { id: 'pending', label: 'รอจ่าย', count: entries.value.filter((e) => e.payment_status === 'pending').length },
  { id: 'paid', label: 'จ่ายแล้ว', count: entries.value.filter((e) => e.payment_status === 'paid').length },
])
/**
 * TASK-215 — a second, independent filter. The existing tabs answer "has
 * this been paid out yet"; this answers "what kind of money is it", which
 * is the question a reconciliation actually starts from.
 */
const viaFilter = ref<'all' | EarnedVia>('all')
const viaFilterOptions = computed(() => {
  const present = new Set(entries.value.map((e) => e.earned_via).filter(Boolean) as EarnedVia[])

  return [
    { id: 'all' as const, label: 'ทุกประเภท' },
    // Only offer types this company actually has — a filter for a plan
    // they do not use is noise.
    ...([...present] as EarnedVia[]).map((v) => ({ id: v, label: earnedViaLabels[v]?.label ?? v })),
  ]
})

const filteredEntries = computed(() => {
  let rows = entries.value
  if (activeTab.value === 'pending') rows = rows.filter((e) => e.payment_status === 'pending')
  else if (activeTab.value === 'paid') rows = rows.filter((e) => e.payment_status === 'paid')
  if (viaFilter.value !== 'all') rows = rows.filter((e) => e.earned_via === viaFilter.value)

  return rows
})

/*
 * 2026-09-15 — THIS PANEL IS READ-ONLY AGAIN (แนวทาง C).
 *
 * markPaid() lived here from the day the ledger screen existed: one press,
 * one commission row settled, one email to the agent saying their money had
 * arrived. For a company that transfers by hand through its bank and hears
 * back from accounting days later, that email was sent before the money
 * moved — and a ledger row cannot be corrected afterwards (BR-4).
 *
 * Every payout now goes through the รอบจ่าย queue: ตั้งจ่าย raises it, and
 * the rows are settled only when somebody records the actual transfer. Two
 * ways to settle the same row is the confusion the owner asked to remove, so
 * the second one is gone rather than hidden.
 *
 * POST /commission-ledger/{id}/mark-paid still exists and is still audited —
 * it is the correction path for money settled outside the system entirely
 * (cash, an offset) — but no screen offers it any more.
 */

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}
function formatRate(entry: LedgerItem): string {
  return entry.rate_type_applied === 'percentage' ? (entry.rate_applied / 100).toFixed(2) + '%' : formatSatang(entry.rate_applied)
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
</script>

<template>
  <div>
    <!-- The paid/unpaid split, and the three totals that used to live in the
         HeroHeader this panel no longer owns. Kept as a strip rather than
         dropped: "how much is still owed across the company" is the question
         somebody opens this view to answer. -->
    <div class="mt-4 flex flex-wrap items-center gap-2" data-test="ledger-status-tabs">
      <span
        v-for="k in kpis"
        :key="k.label"
        class="text-xs text-slate-500"
      >{{ k.label }} <b class="text-slate-900">{{ k.value }}</b></span>
      <span class="ml-auto flex flex-wrap gap-1">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
          :class="activeTab === t.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'"
          :data-test="`ledger-tab-${t.id}`"
          @click="activeTab = t.id as TabId"
        >
          {{ t.label }} ({{ t.count }})
        </button>
      </span>
    </div>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <!-- TASK-215 — filter by payout type. -->
      <div v-if="viaFilterOptions.length > 2" class="mt-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-slate-400">ประเภท:</span>
        <button
          v-for="o in viaFilterOptions"
          :key="o.id"
          class="px-3 py-1.5 rounded-full border text-xs font-bold"
          :class="viaFilter === o.id ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'"
          @click="viaFilter = o.id"
        >
          {{ o.label }}
        </button>
      </div>

      <EmptyState v-if="!filteredEntries.length" icon="money" title="ยังไม่มีรายการคอมมิชชั่นในหมวดนี้" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="e in filteredEntries" :key="e.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div class="flex items-start gap-3">
            <Icon name="money" :size="18" class="text-brand-600 mt-0.5" />
            <div>
              <p class="text-sm font-bold text-slate-900">
                {{ e.referral?.client?.name ?? '—' }}
                <!-- TASK-215 — WHAT KIND of money this row is. Without
                     it, a direct commission, an upline override and a
                     campaign bonus on the same sale are three visually
                     identical lines. -->
                <span class="ml-1.5 px-2 py-0.5 rounded-md text-[11px] font-bold align-middle" :class="earnedViaBadge(e).cls">
                  {{ earnedViaBadge(e).label }}
                </span>
                <!-- 2026-09-15 — beside the earned_via badge, not instead of
                     it: this row IS a leader override, and the second chip
                     says who the leader is. Without it the company's own
                     margin reads as an agent's unpaid wages. -->
                <span
                  v-if="e.is_company_share"
                  class="ml-1.5 px-2 py-0.5 rounded-md text-[11px] font-bold align-middle bg-emerald-100 text-emerald-800"
                  :data-test="`company-share-${e.id}`"
                >
                  ส่วนของบริษัท
                </span>
              </p>
              <p class="text-xs text-slate-400">
                Agent: {{ e.agent?.name ?? '—' }}
                <!-- Whose sale earned it. Only meaningful on override
                     rows, where "Agent" is the RECIPIENT, not the seller —
                     the single most misread thing on this screen. -->
                <span v-if="e.override_source_agent" class="text-amber-700 font-bold">
                  (จากการขายของ {{ e.override_source_agent.name }})
                </span>
                · {{ e.product?.name }} · {{ e.cert_tier_at_time?.name }} tier · อัตรา {{ formatRate(e) }} · {{ formatDate(e.created_at) }}
              </p>
            </div>
          </div>
          <div class="text-right flex items-center gap-3">
            <div>
              <p class="text-sm font-bold text-slate-900">{{ formatSatang(e.amount_satang) }}</p>
              <span
                class="text-xs font-bold px-2 py-0.5 rounded-lg whitespace-nowrap"
                :class="e.payment_status === 'paid' ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'"
              >
                {{ e.payment_status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย' }}
              </span>
            </div>
            <!--
              THE COMPANY'S OWN ROW STILL SAYS WHY IT IS DIFFERENT.

              No payout button survives on this panel at all now (see the
              script's tombstone), but this line is not about a missing
              button — it answers "is this money owed to somebody?" for a row
              that looks exactly like one that is. The money never left the
              company, so nothing about it will ever appear in รอบจ่าย either.
            -->
            <span
              v-if="e.is_company_share"
              class="text-[11px] text-slate-400 max-w-[9rem] leading-tight"
              :data-test="`company-share-note-${e.id}`"
            >
              เงินอยู่กับบริษัทอยู่แล้ว ไม่ต้องโอน
            </span>
          </div>
        </div>
      </TransitionGroup>
    </template>
  </div>
</template>
