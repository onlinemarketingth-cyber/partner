<script setup lang="ts">
/**
 * CompanyLinksView — "ลิงก์ทั้งบริษัท" (TASK-234).
 *
 * ── WHAT THIS ANSWERS THAT NOTHING COULD BEFORE ──
 *
 * How many links does this company have out in the world, who made them,
 * and are any of them working. Six token tables existed and not one screen
 * showed them together; the only counter anybody could see at all was the
 * sales-material `view_count` on a modal inside the product editor.
 *
 * ── THE TWO LISTS AT THE BOTTOM ARE THE POINT ──
 *
 * A ranking by clicks tells an admin who is busiest, which they already
 * know. The two lists here tell them something they cannot get anywhere
 * else: which agent's links actually CONVERT, and which links are dead
 * weight. Both are derived on the client from rows the API already sent,
 * so neither costs a request.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

interface TrackedLink {
  id: number
  group: string
  group_label: string
  code: string
  short_url: string
  label: string | null
  created_by_user_id: number | null
  created_by_name?: string | null
  expires_at: string | null
  revoked_at: string | null
  is_usable: boolean
  click_count: number
  unique_click_count: number
  conversion_count: number
  /** NULL = nobody has opened it. Rendered "—", never "0%". */
  conversion_rate: number | null
  last_clicked_at: string | null
  created_at: string
}

interface GroupSummary {
  group: string
  label: string
  link_count: number
  clicks: number
  unique_clicks: number
  conversions: number
}

const activeCompany = useActiveCompanyStore()

const links = ref<TrackedLink[]>([])
const summary = ref<GroupSummary[]>([])
const loading = ref(false)
const errorMessage = ref('')
const groupFilter = ref('all')

const groupOptions = computed(() => [
  { value: 'all', label: 'ทุกกลุ่ม' },
  ...summary.value.map((row) => ({ value: row.group, label: row.label })),
])

const visible = computed(() =>
  groupFilter.value === 'all' ? links.value : links.value.filter((l) => l.group === groupFilter.value),
)

const totals = computed(() => ({
  links: links.value.length,
  clicks: links.value.reduce((s, l) => s + l.click_count, 0),
  unique: links.value.reduce((s, l) => s + l.unique_click_count, 0),
  conversions: links.value.reduce((s, l) => s + l.conversion_count, 0),
}))

/**
 * Agents ranked by RESULTS, not by clicks.
 *
 * The busiest sharer and the most effective one are usually different
 * people, and only the second is worth learning from. Ranking by clicks
 * would put whoever posts most often at the top permanently and teach the
 * company the wrong lesson about what works.
 */
const topAgents = computed(() => {
  const byAgent = new Map<string, { name: string; conversions: number; unique: number }>()

  for (const link of links.value) {
    const key = String(link.created_by_user_id ?? 'unknown')
    const row = byAgent.get(key) ?? {
      name: link.created_by_name ?? 'ไม่ทราบผู้สร้าง',
      conversions: 0,
      unique: 0,
    }
    row.conversions += link.conversion_count
    row.unique += link.unique_click_count
    byAgent.set(key, row)
  }

  return [...byAgent.values()]
    .filter((r) => r.conversions > 0)
    .sort((a, b) => b.conversions - a.conversions)
    .slice(0, 5)
})

/**
 * Links that have been out for over a month with nobody opening them.
 *
 * 30 days is a threshold, not a business rule — it decides what this list
 * shows and nothing else. Nothing is deleted, nothing expires because of
 * it, and no money moves. If it needs to be a setting later, that is a
 * decision with a form attached (BR-7), not a constant quietly acquiring
 * meaning.
 */
const DEAD_LINK_DAYS = 30

const deadLinks = computed(() => {
  const cutoff = Date.now() - DEAD_LINK_DAYS * 86400_000

  return links.value
    .filter((l) => l.click_count === 0 && l.is_usable && new Date(l.created_at).getTime() < cutoff)
    .slice(0, 10)
})

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [list, roll] = await Promise.all([
      api.get<{ data: TrackedLink[] }>(activeCompany.scopedPath('/tracked-links')),
      api.get<{ data: GroupSummary[] }>(activeCompany.scopedPath('/tracked-links?summary=1')),
    ])
    links.value = list.data
    summary.value = roll.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function rateLabel(link: TrackedLink): string {
  return link.conversion_rate === null ? '—' : `${link.conversion_rate}%`
}

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) : 'ยังไม่มีคนเปิด'
}

onMounted(load)
watch(() => activeCompany.companyId, load)

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

/**
 * "จัดการลิงก์นี้" — the jump this page could never offer as a standalone
 * screen (2026-08-22).
 *
 * Only two of the seven TrackedLinkGroup values have a management surface:
 * company_signup and team_signup. Every other group is created elsewhere in
 * the product (a product share from the agent portal, a payment link from an
 * order) and has nothing to manage here — those rows get NO button rather
 * than one that leads somewhere unhelpful. A control that appears on every
 * row and works on two is worse than a control on two rows.
 */
const emit = defineEmits<{ manage: [tab: 'signup' | 'team'] }>()

function manageTabFor(group: string): 'signup' | 'team' | null {
  if (group === 'company_signup') return 'signup'
  if (group === 'team_signup') return 'team'

  return null
}

</script>

<template>
  <main :class="embedded ? '' : 'min-h-screen px-4 py-6 lg:px-8'">
    <HeroHeader
      v-if="!embedded"
      icon="link"
      title="ลิงก์ทั้งบริษัท"
      subtitle="ลิงก์ทุกกลุ่มที่บริษัทนี้ปล่อยออกไป พร้อมจำนวนคนที่เปิดจริงและผลลัพธ์"
      accent-color="brand"
      storage-key="company-links"
    />

    <CompanyScopeNotice v-if="!embedded" action="ดูสถิติลิงก์" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
      <div class="bg-white/95 border border-slate-200 rounded-xl px-4 py-3">
        <p class="text-[11px] font-bold text-slate-500">ลิงก์ทั้งหมด</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5">{{ totals.links }}</p>
      </div>
      <div class="bg-white/95 border border-slate-200 rounded-xl px-4 py-3">
        <p class="text-[11px] font-bold text-slate-500">คนไม่ซ้ำ</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5">{{ totals.unique }}</p>
        <p class="text-[11px] text-slate-400">เปิดรวม {{ totals.clicks }} ครั้ง</p>
      </div>
      <div class="bg-white/95 border border-slate-200 rounded-xl px-4 py-3">
        <p class="text-[11px] font-bold text-slate-500">ผลลัพธ์</p>
        <p class="text-2xl font-extrabold text-emerald-600 mt-0.5">{{ totals.conversions }}</p>
      </div>
      <div class="bg-white/95 border border-slate-200 rounded-xl px-4 py-3">
        <p class="text-[11px] font-bold text-slate-500">อัตราแปลงรวม</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5">
          {{ totals.unique > 0 ? `${Math.round((totals.conversions / totals.unique) * 1000) / 10}%` : '—' }}
        </p>
      </div>
    </div>

    <!-- Per-group roll-up -->
    <div v-if="summary.length" class="mt-4 bg-white/95 border border-slate-200 rounded-xl overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 text-[11px] text-slate-500">
            <th class="text-left px-4 py-2 font-bold">กลุ่มลิงก์</th>
            <th class="text-right px-4 py-2 font-bold">ลิงก์</th>
            <th class="text-right px-4 py-2 font-bold">เปิด</th>
            <th class="text-right px-4 py-2 font-bold">คนไม่ซ้ำ</th>
            <th class="text-right px-4 py-2 font-bold">ผลลัพธ์</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in summary" :key="row.group" class="border-t border-slate-100">
            <td class="px-4 py-2 font-bold text-slate-700">{{ row.label }}</td>
            <td class="px-4 py-2 text-right text-slate-600">{{ row.link_count }}</td>
            <td class="px-4 py-2 text-right text-slate-600">{{ row.clicks }}</td>
            <td class="px-4 py-2 text-right text-slate-600">{{ row.unique_clicks }}</td>
            <td class="px-4 py-2 text-right font-bold text-emerald-600">{{ row.conversions }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-4 flex items-center gap-2">
      <label for="group_filter" class="text-xs font-bold text-slate-600">กรองตามกลุ่ม</label>
      <select
        id="group_filter"
        v-model="groupFilter"
        class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm"
      >
        <option v-for="o in groupOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <button class="btn-secondary ml-auto" :disabled="loading" @click="load">
        {{ loading ? 'กำลังโหลด...' : 'รีเฟรช' }}
      </button>
    </div>

    <LoadingSkeleton v-if="loading && !links.length" type="list" :rows="4" class="mt-4" />
    <EmptyState v-else-if="!visible.length" icon="link" title="ยังไม่มีลิงก์ในกลุ่มนี้" class="mt-4" />
    <div v-else class="mt-3 bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 text-[11px] text-slate-500">
            <th class="text-left px-4 py-2 font-bold">ลิงก์</th>
            <th class="text-left px-4 py-2 font-bold">ผู้สร้าง</th>
            <th class="text-right px-4 py-2 font-bold">คน</th>
            <th class="text-right px-4 py-2 font-bold">ผลลัพธ์</th>
            <th class="text-right px-4 py-2 font-bold">อัตรา</th>
            <th class="text-right px-4 py-2 font-bold">เปิดล่าสุด</th>
            <th class="text-right px-4 py-2 font-bold"><span class="sr-only">จัดการ</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="link in visible"
            :key="link.id"
            class="border-t border-slate-100"
            :class="link.is_usable ? '' : 'opacity-50'"
          >
            <td class="px-4 py-2 min-w-0">
              <p class="font-bold text-slate-800 truncate max-w-xs">{{ link.label || link.group_label }}</p>
              <p class="text-[11px] text-brand-700 truncate max-w-xs">{{ link.short_url }}</p>
            </td>
            <td class="px-4 py-2 text-slate-600">{{ link.created_by_name || '—' }}</td>
            <td class="px-4 py-2 text-right text-slate-700">{{ link.unique_click_count }}</td>
            <td class="px-4 py-2 text-right font-bold text-emerald-600">{{ link.conversion_count }}</td>
            <td class="px-4 py-2 text-right text-slate-700">{{ rateLabel(link) }}</td>
            <td class="px-4 py-2 text-right text-slate-500 text-xs">{{ formatDate(link.last_clicked_at) }}</td>
            <td class="px-4 py-2 text-right">
              <button
                v-if="manageTabFor(link.group)"
                type="button"
                class="min-h-[32px] px-2.5 rounded-lg text-[12px] font-bold text-brand-700 hover:bg-brand-50 transition whitespace-nowrap"
                @click="emit('manage', manageTabFor(link.group)!)"
              >
                จัดการ
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
      <div class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <p class="text-sm font-bold text-slate-900">ตัวแทนที่ลิงก์ได้ผลมากที่สุด</p>
        <p class="text-[11px] text-slate-400 mt-0.5">เรียงตามผลลัพธ์ ไม่ใช่จำนวนคลิก — คนที่แชร์บ่อยที่สุดกับคนที่ได้ผลที่สุดมักไม่ใช่คนเดียวกัน</p>
        <EmptyState v-if="!topAgents.length" icon="trophy" title="ยังไม่มีผลลัพธ์จากลิงก์" class="mt-3" />
        <ol v-else class="mt-3 space-y-1.5">
          <li v-for="(agent, i) in topAgents" :key="agent.name" class="flex items-center gap-2 text-sm">
            <span class="w-5 text-xs font-bold text-slate-400">{{ i + 1 }}</span>
            <span class="flex-1 truncate text-slate-700">{{ agent.name }}</span>
            <span class="font-bold text-emerald-600">{{ agent.conversions }}</span>
            <span class="text-[11px] text-slate-400">จาก {{ agent.unique }} คน</span>
          </li>
        </ol>
      </div>

      <div class="bg-white/95 border border-slate-200 rounded-xl p-4">
        <p class="text-sm font-bold text-slate-900">ลิงก์ที่ยังไม่มีใครเปิด</p>
        <p class="text-[11px] text-slate-400 mt-0.5">สร้างไว้เกิน {{ DEAD_LINK_DAYS }} วันแล้วยังไม่มีคนเปิดเลย</p>
        <EmptyState v-if="!deadLinks.length" icon="check" title="ไม่มีลิงก์ที่ถูกทิ้งไว้" class="mt-3" />
        <ul v-else class="mt-3 space-y-1.5">
          <li v-for="link in deadLinks" :key="link.id" class="flex items-center gap-2 text-sm">
            <Icon name="link" :size="13" class="text-slate-300 shrink-0" />
            <span class="flex-1 truncate text-slate-600">{{ link.label || link.group_label }}</span>
            <span class="text-[11px] text-slate-400 shrink-0">{{ link.created_by_name || '—' }}</span>
          </li>
        </ul>
      </div>
    </div>
  </main>
</template>
