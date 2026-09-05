<script setup lang="ts">
/**
 * ActivityLogPanel — "ใครทำอะไรไปบ้างในระบบ", one list with real filters.
 *
 * ── WHY THIS COMPONENT EXISTS (2026-09-05, human correction) ──
 *
 * All of this lived inside PolicyReportView as tab 1 of 4, on a page called
 * "นโยบายและรายงาน", reached from a submenu. The human's actual request was
 * simpler and blunter than what got built:
 *
 *   "ผมต้องการ Log รวมว่าใครทำอะไรไปบ้างที่ผ่านมาในระบบ อยู่ใน เมนู Setting
 *    พร้อมระบบ Filter ค้นหาตามวันที่เวลา เป็นช่วง และรายบุคคล และกิจกรรม"
 *
 * — a COMBINED log, in the Settings menu, filterable by date range, by
 * person, and by activity. Two things were wrong before:
 *
 *   1. it was not findable. Being a tab inside a reports page means the
 *      screen exists but nobody arrives at it.
 *   2. the ACTIVITY filter was a free-text box with the placeholder
 *      "เช่น commission_rule.created" — a search box you can only use if you
 *      already know the answer. Nobody types `user.team_leader_changed` from
 *      memory, so in practice there was no activity filter at all.
 *
 * It is a component rather than a view because it now has two homes: its own
 * Settings page (SystemActivityLogView, where people go looking for it) and
 * the audit tab of the reports page (where it has always been, and where a
 * bookmark still lands). One implementation, so the two can never disagree
 * about what the log says.
 *
 * The per-person filter stays — the human asked for it — but as a FILTER on
 * this one list, never as a separate per-person history screen. That
 * distinction is the correction being applied here.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import DateRangeFilter from '@/design-system/components/DateRangeFilter.vue'
import { fetchAllPages } from './agentEdit'
import { AUDIT_ACTION_GROUPS, auditActionLabel } from '@/utils/auditActions'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const activeCompany = useActiveCompanyStore()
const route = useRoute()
const router = useRouter()

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback

  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

interface AuditLogRow {
  id: number
  company_id: number | null
  actor_name: string | null
  action: string
  auditable_type: string
  auditable_id: number
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}
interface PaginationMeta { current_page: number; last_page: number; total: number; per_page: number }

const rows = ref<AuditLogRow[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const loadedOnce = ref(false)
const errorMessage = ref('')
const expandedRowId = ref<number | null>(null)

/**
 * `?actor=<id>`, read at SETUP TIME.
 *
 * Anything that is not a positive integer means no filter at all: a truncated
 * or hand-edited URL must read as "everybody", never as `NaN` in a query
 * string.
 */
function actorFromQuery(): number | '' {
  const actor = Number(route.query.actor)

  return Number.isInteger(actor) && actor > 0 ? actor : ''
}

const filters = ref({
  action: '',
  date_from: '',
  date_to: '',
  // '' = everybody, never "nobody".
  actor_user_id: actorFromQuery(),
})

/**
 * The people who can appear as an actor.
 *
 * NO scopedPath() here. fetchAllPages applies it internally, so wrapping the
 * path a second time sends `company_id` twice — which PHP resolves to the
 * last one and is therefore invisible until the day the two differ.
 * `include_inactive=1` on purpose: a deactivated account is exactly the one
 * somebody comes to this screen to ask about.
 */
interface ActorOption { id: number; name: string }
const actorOptions = ref<ActorOption[]>([])

async function loadActorOptions() {
  try {
    actorOptions.value = await fetchAllPages<ActorOption>('/users?include_inactive=1')
  } catch {
    // A dropdown that will not load must not take the log down with it — the
    // log is why anybody opened this page.
    actorOptions.value = []
  }
}

/**
 * The filters, without the page — shared by the table and the CSV.
 *
 * An export is taken away and shown to somebody (an auditor, a lawyer). A
 * file that quietly answers a WIDER question than the screen it came from is
 * worse than no export at all, because the difference is found by the person
 * it was handed to.
 */
function filterParams(): URLSearchParams {
  const params = new URLSearchParams()
  if (isSuperAdmin.value && activeCompany.companyId) params.set('company_id', String(activeCompany.companyId))
  if (filters.value.actor_user_id !== '') params.set('actor_user_id', String(filters.value.actor_user_id))
  if (filters.value.action) params.set('action', filters.value.action)
  if (filters.value.date_from) params.set('date_from', filters.value.date_from)
  if (filters.value.date_to) params.set('date_to', filters.value.date_to)

  return params
}

function query(page: number): string {
  const params = filterParams()
  params.set('page', String(page))

  return params.toString()
}

async function load(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: AuditLogRow[]; meta: PaginationMeta }>(`/audit-logs?${query(page)}`)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดบันทึกการใช้งานไม่สำเร็จ')
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

/*
 * The trail as a file. api.download(), never an <a href> (Section 5 rule 6):
 * the request has to carry the same session cookie and XSRF token as every
 * other call, and the server records who took the copy.
 *
 * The server refuses a range wider than a year and says by how much; that
 * message is what ApiError carries, so it is shown verbatim rather than
 * replaced with a generic failure the admin cannot act on.
 */
const exporting = ref(false)

async function exportCsv() {
  exporting.value = true
  errorMessage.value = ''
  try {
    const q = filterParams().toString()
    await api.download(`/audit-logs/export${q ? `?${q}` : ''}`)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ส่งออก CSV ไม่สำเร็จ')
  } finally {
    exporting.value = false
  }
}

/**
 * The actor filter lives in the URL.
 *
 * The question is often asked FROM somewhere else, and the answer is usually
 * shown to somebody else. A filter that exists only in component state can be
 * neither linked to nor sent.
 *
 * `replace`, not `push`: refining a filter is not a place in history.
 *
 * Only the id travels, never the name — a person's name in a URL ends up in
 * browser history and in the Referer of everything the next page loads (§6).
 */
function syncFiltersToUrl() {
  const q: Record<string, string> = { ...route.query as Record<string, string> }

  if (filters.value.actor_user_id === '') delete q.actor
  else q.actor = String(filters.value.actor_user_id)

  void router.replace({ query: q })
}

function applyFilters() {
  syncFiltersToUrl()
  load(1)
}

/** Cleared from the chip above the table, without touching the other filters. */
function clearActorFilter() {
  filters.value.actor_user_id = ''
  applyFilters()
}

/**
 * "7 วันล่าสุด" / "30 วันล่าสุด".
 *
 * DateRangeFilter already offers เดือนนี้ / เดือนก่อน / ทั้งปี / Q1-Q4, which
 * are the shapes a REPORT is read in. A log is read by recency — "what
 * happened since I last looked" — and that question has no quarter in it.
 */
function setRecentDays(days: number) {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - (days - 1))

  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

  filters.value.date_from = iso(from)
  filters.value.date_to = iso(to)
  applyFilters()
}

function clearAllFilters() {
  filters.value = { action: '', date_from: '', date_to: '', actor_user_id: '' }
  applyFilters()
}

const hasAnyFilter = computed(
  () => filters.value.action !== ''
    || filters.value.date_from !== ''
    || filters.value.date_to !== ''
    || filters.value.actor_user_id !== '',
)

const selectedActorName = computed(
  () => actorOptions.value.find((a) => a.id === filters.value.actor_user_id)?.name ?? '',
)

/** The Thai name of whatever the activity filter currently holds. */
const selectedActionLabel = computed(() => {
  const value = filters.value.action
  if (value === '') return ''

  const group = AUDIT_ACTION_GROUPS.find((g) => g.prefix === value)

  return group ? `${group.label} (ทั้งหมด)` : auditActionLabel(value)
})

function goToPage(page: number) {
  if (!meta.value || page < 1 || page > meta.value.last_page) return
  load(page)
}
function toggleRow(row: AuditLogRow) {
  expandedRowId.value = expandedRowId.value === row.id ? null : row.id
}

// The header's company picker narrows this list too (ADR-038).
watch(() => activeCompany.companyId, () => {
  load(1)
  // The people who can appear as an actor are a per-company list too.
  void loadActorOptions()
})

onMounted(() => {
  load(1)
  void loadActorOptions()
})
</script>

<template>
  <section>
    <!-- What this log does and does not contain. Stated where it is read,
         because "no rows" is otherwise indistinguishable from "nothing
         happened" — and the second is a conclusion nobody should draw by
         accident. -->
    <div class="mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
      บันทึกนี้เก็บ <strong>การเปลี่ยนแปลง</strong> ที่กระทบสิทธิ์ เงิน คำสั่งซื้อ และข้อมูลส่วนบุคคล
      รวมถึงการเข้าสู่ระบบ (สำเร็จ / ไม่สำเร็จ / ถูกล็อก)
      — <strong>ยังไม่เก็บการ “เปิดดู” ข้อมูลทั่วไป</strong> ยกเว้น 2 กรณีที่บันทึกไว้แล้ว
      (เปิดดูข้อมูล Super Admin และหัวหน้าทีมเปิดแฟ้มลูกค้าของลูกทีม)
    </div>

    <div class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-bold text-slate-500 mb-1">ผู้ทำรายการ</label>
          <select
            v-model="filters.actor_user_id"
            data-test="actor-filter"
            class="px-3 py-2 rounded-lg border border-slate-200 text-sm min-w-[14rem] bg-white"
          >
            <option value="">ทุกคน</option>
            <option v-for="actor in actorOptions" :key="actor.id" :value="actor.id">{{ actor.name }}</option>
          </select>
        </div>

        <!-- The filter that replaced a free-text box nobody could use. Each
             group is selectable as a whole (it sends the action prefix,
             which the API matches with LIKE) so "ทุกอย่างเกี่ยวกับผู้ใช้" is
             one click rather than eleven. -->
        <div>
          <label class="block text-xs font-bold text-slate-500 mb-1">กิจกรรม</label>
          <select
            v-model="filters.action"
            data-test="action-filter"
            class="px-3 py-2 rounded-lg border border-slate-200 text-sm min-w-[18rem] bg-white"
          >
            <option value="">ทุกกิจกรรม</option>
            <optgroup v-for="group in AUDIT_ACTION_GROUPS" :key="group.label" :label="group.label">
              <option v-if="group.prefix" :value="group.prefix">— {{ group.label }} (ทั้งหมด) —</option>
              <option v-for="a in group.actions" :key="a.value" :value="a.value">{{ a.label }}</option>
            </optgroup>
          </select>
        </div>

        <DateRangeFilter v-model:date-from="filters.date_from" v-model:date-to="filters.date_to" :years-back="3" :years-forward="0" />

        <button class="btn-primary" @click="applyFilters">
          กรอง
        </button>
        <button
          class="btn-secondary inline-flex items-center gap-1.5"
          data-test="export-audit-csv"
          :disabled="exporting"
          @click="exportCsv"
        >
          <Icon name="download" :size="14" />
          {{ exporting ? 'กำลังส่งออก…' : 'ส่งออก CSV' }}
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="text-slate-400">ช่วงที่ดูบ่อย:</span>
        <button class="px-2.5 py-1 rounded-lg border border-slate-200 font-bold text-slate-600 hover:bg-slate-50" data-test="last-7-days" @click="setRecentDays(7)">
          7 วันล่าสุด
        </button>
        <button class="px-2.5 py-1 rounded-lg border border-slate-200 font-bold text-slate-600 hover:bg-slate-50" data-test="last-30-days" @click="setRecentDays(30)">
          30 วันล่าสุด
        </button>
        <button v-if="hasAnyFilter" class="ml-auto text-slate-500 font-bold underline hover:no-underline" data-test="clear-filters" @click="clearAllFilters">
          ล้างตัวกรองทั้งหมด
        </button>
      </div>

      <p class="text-[11px] text-slate-400">
        ไฟล์ที่ส่งออกใช้ตัวกรองเดียวกับตารางด้านล่าง · ส่งออกได้ครั้งละไม่เกิน 1 ปี
        (ถ้าไม่ระบุวันที่ จะได้ข้อมูลย้อนหลัง 1 ปีล่าสุด) · ระบบบันทึกทุกครั้งที่มีการส่งออก
      </p>
    </div>

    <!-- Says out loud that the list is narrowed. Without it, arriving on a
         filtered screen looks identical to a quiet day across the platform. -->
    <div
      v-if="filters.actor_user_id !== '' || filters.action !== ''"
      class="mb-3 px-4 py-2.5 rounded-xl bg-brand-50 border border-brand-200 text-sm text-brand-800 flex items-center gap-2 flex-wrap"
      data-test="filter-chip"
    >
      <Icon name="user" :size="16" />
      <span v-if="filters.actor_user_id !== ''">
        กำลังดูเฉพาะกิจกรรมของ <strong>{{ selectedActorName || `ผู้ใช้ #${filters.actor_user_id}` }}</strong>
      </span>
      <span v-if="filters.action !== ''">
        เฉพาะกิจกรรม <strong>{{ selectedActionLabel }}</strong>
      </span>
      <button v-if="filters.actor_user_id !== ''" class="ml-auto text-xs font-bold underline hover:no-underline" @click="clearActorFilter">
        ดูของทุกคน
      </button>
    </div>

    <div v-if="errorMessage" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">{{ errorMessage }}</div>

    <LoadingSkeleton v-if="loading && !loadedOnce" type="list" :rows="5" />
    <template v-else>
      <EmptyState v-if="!rows.length" icon="document" title="ยังไม่มีบันทึกการใช้งานตามเงื่อนไขนี้" />
      <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-left">
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">เวลา</th>
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ผู้ทำรายการ</th>
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500">กิจกรรม</th>
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500">เป้าหมาย</th>
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">IP</th>
              <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-center">รายละเอียด</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in rows" :key="row.id">
              <tr class="border-b border-slate-50 last:border-0">
                <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">{{ formatDateTime(row.created_at) }}</td>
                <td class="px-4 py-2.5 text-slate-700">{{ row.actor_name ?? '—' }}</td>
                <td class="px-4 py-2.5">
                  <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ auditActionLabel(row.action) }}</span>
                </td>
                <td class="px-4 py-2.5 text-slate-500 text-xs whitespace-nowrap">{{ row.auditable_type }} #{{ row.auditable_id }}</td>
                <td class="px-4 py-2.5 text-xs text-slate-400 whitespace-nowrap">{{ row.ip_address ?? '—' }}</td>
                <td class="px-4 py-2.5 text-center">
                  <button class="text-slate-400 hover:text-brand-600" @click="toggleRow(row)">
                    <Icon :name="expandedRowId === row.id ? 'chevron_up' : 'chevron_down'" :size="16" />
                  </button>
                </td>
              </tr>
              <tr v-if="expandedRowId === row.id" class="border-b border-slate-50 bg-slate-50/60">
                <td colspan="6" class="px-4 py-3">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <p class="text-xs font-bold text-slate-500 mb-1">ค่าเดิม (old_values)</p>
                      <pre class="text-xs bg-white border border-slate-200 rounded-lg p-2 overflow-x-auto">{{ row.old_values ? JSON.stringify(row.old_values, null, 2) : '—' }}</pre>
                    </div>
                    <div>
                      <p class="text-xs font-bold text-slate-500 mb-1">ค่าใหม่ (new_values)</p>
                      <pre class="text-xs bg-white border border-slate-200 rounded-lg p-2 overflow-x-auto">{{ row.new_values ? JSON.stringify(row.new_values, null, 2) : '—' }}</pre>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center justify-between text-xs text-slate-500">
        <span>หน้า {{ meta.current_page }} / {{ meta.last_page }} (ทั้งหมด {{ meta.total.toLocaleString('th-TH') }} รายการ)</span>
        <div class="flex gap-1">
          <button
            class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold disabled:opacity-40"
            :disabled="meta.current_page <= 1"
            @click="goToPage(meta.current_page - 1)"
          >
            ก่อนหน้า
          </button>
          <button
            class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold disabled:opacity-40"
            :disabled="meta.current_page >= meta.last_page"
            @click="goToPage(meta.current_page + 1)"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
