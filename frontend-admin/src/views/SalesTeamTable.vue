<script setup lang="ts">
/**
 * SalesTeamTable — the sales team as rows instead of cards (2026-08-22).
 *
 * Replaces SalesTeamGrid at the TOP LEVEL of SalesTeamView. The grid itself
 * stays: it is still what the ขยายดูลูกทีม modal renders, where a handful of
 * cards is the right shape and there is nothing to compare across.
 *
 * ── SORTING FINALLY DOES SOMETHING ──
 *
 * SalesTeamView has had sort controls (ลูกทีม / ยอดขาย / ค่าคอม) all along.
 * Sorting a card grid rearranges blocks whose numbers sit at a different
 * pixel in each one, so the reader still has to read every card to see the
 * order — the feature was present and inert. In a table the sorted column is
 * a rail the eye follows, so the same control starts paying for itself. The
 * header cells are wired to the SAME state the existing buttons drive, not a
 * second sort of their own.
 */
import SalesTeamRow from './SalesTeamRow.vue'
import Icon from '@/design-system/components/Icon.vue'
import { isLeaderNode, type TeamNode } from './salesTeam'
import { computed } from 'vue'

const props = defineProps<{
  nodes: TeamNode[]
  /** The caller already ordered these (flat search/sort mode) — render as-is. */
  preSorted?: boolean
  inLeadersTab?: boolean
  showApprovalActions?: boolean
  /** Mirrors SalesTeamView's sort state so a header can show and change it. */
  sortField?: string | null
  sortDir?: 'asc' | 'desc'
}>()

/*
 * The union, not `string`. SalesTeamView::setSort() only accepts the three
 * fields it can actually sort by, so typing the emit loosely would let a
 * header ask for a sort the page cannot perform and only fail at runtime.
 * Kept in step with SortField there by the compiler.
 */
type SortableField = 'team' | 'sales' | 'commission'

const emit = defineEmits<{ sort: [field: SortableField] }>()

/** Same leaders-first rule as SalesTeamGrid — one ordering, two renderers. */
const sorted = computed(() =>
  props.preSorted
    ? props.nodes
    : [...props.nodes].sort(
        (a, b) =>
          Number(isLeaderNode(b)) - Number(isLeaderNode(a)) ||
          b.total_deals - a.total_deals ||
          b.client_count - a.client_count ||
          (a.agent_name ?? '').localeCompare(b.agent_name ?? ''),
      ),
)

/*
 * `field: null` marks a column the page cannot sort by. It renders as a
 * plain label rather than a dead button — a control that looks clickable and
 * does nothing is worse than no control.
 */
const COLUMNS: Array<{ label: string; field: SortableField | null }> = [
  { label: 'ตัวแทน', field: null },
  { label: 'ทีม', field: 'team' },
  { label: 'ลูกค้า', field: null },
  { label: 'ดีล (ปิด/ทั้งหมด)', field: null },
  { label: 'อัตราปิด', field: null },
  { label: 'ยอดขาย', field: 'sales' },
  /*
   * "ค่าคอม (จ่ายแล้ว)", with the parentheses — TASK-179 §4.2 settled this
   * label and a spec defends it. On the commission figure the suffix is true
   * and load-bearing (pending commission is excluded), which is exactly why
   * the SALES figure beside it must NOT carry it: there it would read as
   * "we have paid this out". Shortening it here re-opened a decision that
   * had already been made and caught by an existing test.
   */
  { label: 'ค่าคอม (จ่ายแล้ว)', field: 'commission' },
  { label: 'Pipeline', field: null },
  { label: 'สถานะ', field: null },
]
</script>

<template>
  <div class="bg-white/95 border border-slate-200 rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full border-collapse min-w-[1120px]">
        <thead>
          <tr class="bg-slate-50/70">
            <th
              v-for="col in COLUMNS"
              :key="col.label"
              class="text-left text-[12px] font-bold uppercase tracking-wider text-slate-400 px-4 py-3 border-b border-slate-200 whitespace-nowrap"
            >
              <button
                v-if="col.field"
                type="button"
                class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors uppercase tracking-wider"
                :class="sortField === col.field ? 'text-slate-700' : ''"
                @click="emit('sort', col.field)"
              >
                {{ col.label }}
                <Icon
                  v-if="sortField === col.field"
                  :name="sortDir === 'desc' ? 'chevron_down' : 'chevron_up'"
                  :size="12"
                />
              </button>
              <template v-else>{{ col.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <SalesTeamRow
            v-for="node in sorted"
            :key="node.agent_id"
            :node="node"
            :in-leaders-tab="inLeadersTab"
            :flat="preSorted"
            :show-approval-actions="showApprovalActions"
          />
        </tbody>
      </table>
    </div>
  </div>
</template>
