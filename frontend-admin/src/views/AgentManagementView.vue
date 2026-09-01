<script setup lang="ts">
/**
 * AgentManagementView — "ภาพรวม" (Dashboard) sub-page of "จัดการตัวแทน".
 *
 * TASK-204 — this file used to own all 5 internal tabs (ภาพรวม / ใช้งานอยู่ /
 * ปิดใช้งาน / รออนุมัติ / ลิงก์ชวนทีม) behind one client-side `activeTab` ref,
 * all sharing a single roster fetch. Human decision: those 5 tabs move into
 * the top submenu row as 4 real routes (ใช้งานอยู่/ปิดใช้งาน merge into one
 * "รายชื่อตัวแทน" roster page with an internal filter — ag-lead ruling, avoids
 * fetching the same roster twice just to show it filtered two ways).
 *
 * This file is now JUST the ภาพรวม (Dashboard) destination — kept under its
 * original filename/route name (`agent-management`, path `/agents`)
 * deliberately, so nothing that already links here by route name
 * (AdminHomeView.vue's link-out card) needs to change. See:
 *   - AgentRosterView.vue      — ใช้งานอยู่ + ปิดใช้งาน (merged, filter toggle)
 *   - AgentApprovalsView.vue   — รออนุมัติ
 *   - AgentInviteLinksView.vue — ลิงก์ชวนทีม
 *
 * The chart dashboard itself is <AgentDashboardOverview /> (TASK-052 /
 * ADR-015) — self-contained, fetches its own /agent-dashboard-metrics, takes
 * no props. This wrapper exists only to give it page chrome (HeroHeader),
 * which it does not render for itself (see that component's own template —
 * it is a bare root <div>, meant to be embedded).
 */
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import { useI18n } from '@/composables/useI18n'
import AgentDashboardOverview from './AgentDashboardOverview.vue'

const { td } = useI18n()
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="dashboard"
      :title="td('dash.title')"
      :subtitle="td('dash.subtitle')"
      accent-color="brand"
      storage-key="agent-management"
    />

    <div class="mt-4">
      <AgentDashboardOverview />
    </div>
  </main>
</template>
