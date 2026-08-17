<script setup lang="ts">
/**
 * SalesTeamGrid — a flat 3-column card grid of agents (TASK-050 redesign
 * r3). Leaders first, then most-active. Expanding a leader's team is now a
 * centred 60vw MODAL (opened via OPEN_TEAM_MODAL from SalesTeamView), so
 * this grid no longer needs per-row expansion panels — it just lays out
 * cards. Reused both for the top-level roots and, inside the modal, for a
 * leader's downline (hence still a standalone component).
 */
import { computed } from 'vue'
import SalesTeamCard from './SalesTeamCard.vue'
import { isLeaderNode, type TeamNode } from './salesTeam'

const props = defineProps<{
  nodes: TeamNode[]
  // TASK-051 — when true the caller already ordered the nodes (flat
  // search/sort mode); skip the default leaders-first sort and render as-is.
  //
  // 2026-08-17 (human-reported) — ALSO forwarded down to the card as `flat`,
  // under a name that describes what the CARD needs to know, not how this
  // grid got its list. `inLeadersTab` alone used to be treated as "this
  // card is a tree root" by SalesTeamCard's `showLeaderBadge` / "อยู่ใต้"
  // logic — true only in the default (non-`preSorted`) hierarchy render of
  // that tab, where every card really is a root. A search or sort
  // FLATTENS the tree (see SalesTeamView's `isFlat`/`searchPool`
  // comments), so the "หัวหน้าทีม" tab's flat results can include a plain
  // nested member found by name — a card that is not a root, sitting in a
  // tab whose cards were assumed to always be roots. See SalesTeamCard's
  // own docblock for the two things this fixes. (This used to be declared
  // TWICE on this props type by mistake — one property, forwarded to two
  // places on the card.)
  preSorted?: boolean
  /**
   * TASK-127 (human, 2026-08-05): "ในหน้าหัวหน้าทีมไม่ควรซ้ำซ้อนว่าเป็น
   * หัวหน้าทีมของคนนี้อีก". Inside a tab literally titled หัวหน้าทีม, a gold
   * "หัวหน้าทีม" badge on every card only restates the tab name. Passed
   * through to the card so the badge is suppressed THERE and nowhere else —
   * in the ตัวแทนอิสระ tab and inside the downline modal the same badge is
   * real information, so it stays.
   */
  inLeadersTab?: boolean
  /**
   * TASK-203 — Approve/Reject buttons only render on a card in the
   * "รออนุมัติเข้าทีม" tab, even though the amber "รออนุมัติ" chip itself can
   * show on a pending agent's card in ANY tab (see SalesTeamCard's own
   * comment). Same pass-through pattern as `inLeadersTab`: this grid is
   * reused for the top-level roots AND for a leader's downline inside the
   * ขยายดูลูกทีม modal, so the caller states per-render whether mutation
   * buttons belong on these particular cards rather than the card guessing
   * from which tab happens to be active elsewhere on the page.
   */
  showApprovalActions?: boolean
}>()

const sorted = computed(() =>
  props.preSorted
    ? props.nodes
    : [...props.nodes].sort(
        // TASK-125 — leaders-first uses the same OR rule as the tab split
        // (flag OR reports), so a designated leader who has recruited nobody
        // yet still sorts with the leaders instead of sinking to the bottom.
        (a, b) =>
          Number(isLeaderNode(b)) - Number(isLeaderNode(a)) ||
          b.total_deals - a.total_deals ||
          b.client_count - a.client_count ||
          (a.agent_name ?? '').localeCompare(b.agent_name ?? ''),
      ),
)
</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
    <SalesTeamCard
      v-for="node in sorted"
      :key="node.agent_id"
      :node="node"
      :in-leaders-tab="inLeadersTab"
      :flat="preSorted"
      :show-approval-actions="showApprovalActions"
    />
  </div>
</template>
