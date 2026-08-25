<script setup lang="ts">
/**
 * SalesTeamRow — one agent as a table row, plus the row it expands into.
 *
 * ── WHY A ROW AT ALL (human, 2026-08-22: "ตอนนี้ดูยากมาก") ──
 *
 * Each agent used to be a card carrying ~25 numbers and 6 controls. Six of
 * them filled the screen, and because a card is an independent block, the
 * same figure sat at a different pixel on every one — so nothing could be
 * compared. The reported screenshot is the proof: five of six agents were
 * entirely zero, and it took real effort to notice.
 *
 * The page already had sort controls (ลูกทีม / ยอดขาย / ค่าคอม). Sorting only
 * means something when the sorted values line up, which a card grid never
 * does — the feature was there and inert.
 *
 * ── WHAT THE ROW SHOWS, AND WHAT IT DEFERS ──
 *
 * Row: identity, team size, clients, deals, close rate, sales, commission
 * paid, a pipeline bar, an approval chip.
 *
 * Expansion: SalesTeamCard in `compact` mode — the per-stage numbers, the
 * downline list and every approval control. That component is reused rather
 * than reimplemented because all of it is injected, stateful and already
 * working; a second copy is a second place to drift.
 *
 * ── THE HIERARCHY IS WHY THIS IS NOT JUST A TABLE ──
 *
 * A leader's reports render as indented rows with the SAME columns, so a
 * leader and their team are finally comparable to each other. The card grid
 * could not do that at all — a downline was a modal.
 */
import { computed, ref } from 'vue'
import SalesTeamCard from './SalesTeamCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import { stageCounts } from '@/utils/pipelineStages'
import { formatBaht, initial, isPendingApproval, type TeamNode } from './salesTeam'

const props = withDefaults(
  defineProps<{
    node: TeamNode
    depth?: number
    inLeadersTab?: boolean
    flat?: boolean
    showApprovalActions?: boolean
  }>(),
  { depth: 0 },
)

const expanded = ref(false)

const isDesignatedLeader = computed(() => props.node.is_team_leader)
const hasReports = computed(() => props.node.children.length > 0)

/**
 * TASK-127's rule, carried over unchanged: inside the tab literally titled
 * หัวหน้าทีม a gold badge on every row restates the tab name, and once most
 * agents are granted it stops distinguishing anything. What still carries
 * information there is the EXCEPTION — an agent with reports who was never
 * granted the flag. `flat` is the case that broke this assumption once: a
 * search flattens the tree, so that tab can contain a nested member.
 */
const showLeaderBadge = computed(
  () => isDesignatedLeader.value && !(props.inLeadersTab && !props.flat),
)
const showUngrantedChip = computed(() => hasReports.value && !isDesignatedLeader.value)

const stages = computed(() => stageCounts(props.node.deals_by_stage))
const stageTotal = computed(() => stages.value.reduce((sum, s) => sum + s.count, 0))

/*
 * THE PIPELINE BAR — a shape instead of eight numbers.
 *
 * Eight labelled counters per agent was the single largest block on the old
 * card and the least readable: eight small numbers, almost always zero,
 * repeated six times down the page.
 *
 * A stacked bar trades exact values for a silhouette you can compare across
 * rows at a glance — who is stacked at the front of the journey, who is
 * stacked at the end. The exact counts are not lost: they are on the segment
 * title, and written out in full in the expansion.
 *
 * The palette walks one hue from light to dark IN STAGE ORDER, so the bar
 * reads as progress rather than as eight unrelated categories. It is
 * generated from the stage index, never a fixed list — `deals_by_stage`
 * carries every stage the server knows and that count changes with an ADR
 * (BR-7, TASK-179 §4.1). A hardcoded array of eight colours would silently
 * drop the ninth stage.
 */
const BAR_SHADES = [
  'bg-brand-200',
  'bg-brand-300',
  'bg-brand-400',
  'bg-brand-500',
  'bg-brand-600',
  'bg-brand-700',
  'bg-brand-800',
  'bg-brand-900',
]
function shadeFor(index: number): string {
  // Modulo, not index-out-of-bounds: a ninth stage repeats a shade rather
  // than rendering as transparent nothing.
  return BAR_SHADES[index % BAR_SHADES.length] ?? 'bg-brand-500'
}

const segments = computed(() =>
  stages.value
    .map((s, i) => ({ ...s, index: i }))
    .filter((s) => s.count > 0)
    .map((s) => ({
      ...s,
      pct: stageTotal.value === 0 ? 0 : (s.count / stageTotal.value) * 100,
      shade: shadeFor(s.index),
    })),
)

/**
 * The close rate WITH its denominator.
 *
 * "100.0%" over three deals is technically true and practically misleading —
 * it reads like a track record. Showing 3/3 beside it puts the base in front
 * of the reader, which is the honest version of the same fact.
 */
/*
 * A named function rather than an inline computed body, so the spec's
 * restated copy can be compared to it character for character (see
 * SalesTeamRow.spec.ts). Mutation-checking found the inline version was
 * unguarded: deleting the denominator and dividing by zero both left the
 * suite green, because the guard could only reach `function` declarations.
 */
function closeRateOf(node: { closed_deals: number; total_deals: number }) {
  const { closed_deals: closed, total_deals: total } = node

  return {
    ratio: `${closed}/${total}`,
    pct: total === 0 ? null : (closed / total) * 100,
  }
}

const closeRate = computed(() => closeRateOf(props.node))

const approval = computed(() => {
  if (isPendingApproval(props.node)) return { tone: 'pending', label: 'รออนุมัติ' }
  if (showUngrantedChip.value) return { tone: 'ungranted', label: 'ยังไม่ให้สิทธิ์' }
  if (isDesignatedLeader.value) return { tone: 'leader', label: 'หัวหน้าทีม' }

  return { tone: 'plain', label: '—' }
})

function approvalChipClasses(tone: string): string {
  switch (tone) {
    case 'pending':
      return 'bg-amber-100 text-amber-700'
    case 'ungranted':
      // Slate, not red: not being granted is a normal state, not a fault.
      return 'bg-slate-100 text-slate-600'
    case 'leader':
      return 'bg-amber-50 text-amber-700'
    default:
      return 'text-slate-300'
  }
}
</script>

<template>
  <tr
    class="hover:bg-slate-50 transition-colors cursor-pointer"
    :class="isDesignatedLeader && depth === 0 ? 'bg-amber-50/25' : ''"
    @click="expanded = !expanded"
  >
    <!-- ── ตัวแทน ── -->
    <td
      class="px-4 py-3.5 border-b border-slate-100"
      :class="isDesignatedLeader && depth === 0 ? 'shadow-[inset_3px_0_0_theme(colors.amber.400)]' : ''"
    >
      <div class="flex items-center gap-2.5" :style="{ paddingLeft: `${depth * 22}px` }">
        <Icon
          name="chevron_right"
          :size="14"
          class="text-slate-400 shrink-0 transition-transform"
          :class="expanded ? 'rotate-90' : ''"
        />
        <img v-if="node.avatar_url" :src="node.avatar_url" alt="" class="w-8 h-8 rounded-full object-cover shrink-0" />
        <div
          v-else
          class="w-8 h-8 rounded-full flex items-center justify-center text-[13px] font-bold shrink-0 text-white"
          :class="isDesignatedLeader ? 'bg-amber-500' : 'bg-brand-500'"
        >
          {{ initial(node.agent_name) }}
        </div>
        <div class="min-w-0">
          <p class="text-[15px] font-bold text-slate-900 truncate leading-tight">
            {{ node.agent_name ?? `#${node.agent_id}` }}
          </p>
          <p v-if="node.agent_phone || node.agent_email" class="text-[13px] text-slate-500 truncate tabular-nums">
            {{ node.agent_phone ?? node.agent_email }}
          </p>
        </div>
        <span
          v-if="showLeaderBadge"
          class="text-[12px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 shrink-0"
        >หัวหน้าทีม</span>
      </div>
    </td>

    <!-- ── ทีม ── -->
    <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px] text-slate-700 tabular-nums">
      <span v-if="hasReports">{{ node.children.length }} คน</span>
      <span v-else class="text-slate-300">—</span>
    </td>

    <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px] font-bold text-slate-900 tabular-nums">
      {{ node.client_count }}
    </td>

    <!-- ── ดีล: closed/total, so the rate below has a visible base ── -->
    <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px] text-slate-900 tabular-nums">
      <span class="font-bold">{{ closeRate.ratio }}</span>
    </td>

    <td class="px-4 py-3.5 border-b border-slate-100">
      <div v-if="closeRate.pct !== null" class="flex items-center gap-2">
        <span class="h-1.5 w-12 rounded-full bg-slate-100 overflow-hidden shrink-0">
          <span class="block h-full rounded-full bg-brand-500" :style="{ width: `${closeRate.pct}%` }"></span>
        </span>
        <span class="text-[13px] text-slate-600 tabular-nums">{{ closeRate.pct.toFixed(0) }}%</span>
      </div>
      <span v-else class="text-[13px] text-slate-300">—</span>
    </td>

    <!-- ── ยอดขาย ── -->
    <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px] text-slate-900 tabular-nums whitespace-nowrap">
      ฿{{ formatBaht(node.total_sales_satang) }}
      <!-- TASK-179 §4.2 — deals closed with no paid order, so contributing
           zero baht here. DISCLOSED, never estimated, and only when there is
           something to disclose: a caveat that is always on screen is a
           caveat nobody reads. The sentence itself is in the expansion. -->
      <Icon
        v-if="node.closed_deals_without_order > 0"
        name="info"
        :size="13"
        class="inline text-amber-500 align-[-1px] ml-0.5"
        :title="`อีก ${node.closed_deals_without_order} ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ ยอดนี้จึงยังไม่รวม`"
      />
    </td>

    <td class="px-4 py-3.5 border-b border-slate-100 text-[14.5px] text-slate-700 tabular-nums whitespace-nowrap">
      ฿{{ formatBaht(node.total_commission_satang) }}
    </td>

    <!-- ── Pipeline ── -->
    <td class="px-4 py-3.5 border-b border-slate-100">
      <div v-if="segments.length" class="flex h-2.5 w-32 rounded-full overflow-hidden bg-slate-100">
        <span
          v-for="seg in segments"
          :key="seg.key"
          class="block h-full first:rounded-l-full last:rounded-r-full"
          :class="seg.shade"
          :style="{ width: `${seg.pct}%` }"
          :title="`${seg.label}: ${seg.count}`"
        ></span>
      </div>
      <span v-else class="text-[13px] text-slate-300">—</span>
    </td>

    <td class="px-4 py-3.5 border-b border-slate-100">
      <span :class="['text-[12px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap', approvalChipClasses(approval.tone)]">
        {{ approval.label }}
      </span>
    </td>
  </tr>

  <!-- ── The expansion: everything a row cannot hold ── -->
  <tr v-if="expanded">
    <td colspan="9" class="border-b border-slate-200 bg-slate-50/60 p-0">
      <div class="px-4 py-3" :style="{ paddingLeft: `${16 + depth * 22}px` }">
        <SalesTeamCard
          compact
          :node="node"
          :in-leaders-tab="inLeadersTab"
          :flat="flat"
          :show-approval-actions="showApprovalActions"
        />
      </div>
    </td>
  </tr>

  <!--
    Reports as sibling rows, not a nested table.

    One <table> means one set of column widths, so a leader's numbers and
    their team's line up in the same vertical rails — which is the entire
    point and the thing the card grid could not do at any depth. A nested
    table would size its own columns and break the alignment immediately.

    Shown only while the parent is expanded, so the default view stays one
    row per leader.
  -->
  <template v-if="expanded">
    <SalesTeamRow
      v-for="child in node.children"
      :key="child.agent_id"
      :node="child"
      :depth="depth + 1"
      :in-leaders-tab="inLeadersTab"
      :flat="flat"
      :show-approval-actions="showApprovalActions"
    />
  </template>
</template>
