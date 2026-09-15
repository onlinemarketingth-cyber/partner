<script setup lang="ts">
/**
 * รอบจ่าย — /commission/runs.
 *
 * Owner: "ย้าย ตั้งจ่าย รอบจ่าย รายรายการ ไปเป็น sub menu".
 *
 * The queue's third home in as many days, and the moves were not churn: it
 * began as its own menu ("คำขอเบิกค่าคอม") over a list that was always empty,
 * became a tab inside จ่ายเงิน once both doors started raising the same payout
 * object, and is now its own route under the ค่าแนะนำ pillar — a real path, so
 * the left-hand menu is the only switcher and the page can be linked to.
 *
 * This file is only the page chrome. Everything the queue DOES lives in
 * CommissionPayoutQueuePanel, unchanged by any of those moves, which is the
 * whole reason they cost nothing.
 */
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import CommissionPayoutQueuePanel from './CommissionPayoutQueuePanel.vue'
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="invoice"
      title="รอบจ่าย"
      subtitle="อนุมัติ · ส่งบัญชีโอน · ยืนยันเมื่อโอนจริงแล้ว"
      description="รายการมาจาก 2 ทาง — ตัวแทนกดขอเบิกเอง หรือคุณกดตั้งจ่ายให้ · ค่าแนะนำจะถูกปิดและตัวแทนจะได้อีเมล ก็ต่อเมื่อกด “บันทึกว่าโอนแล้ว” เท่านั้น"
      accent-color="brand"
      storage-key="commission-runs"
    />

    <CompanyScopeNotice action="ดูรอบจ่ายค่าแนะนำ" />

    <CommissionPayoutQueuePanel />
  </main>
</template>
