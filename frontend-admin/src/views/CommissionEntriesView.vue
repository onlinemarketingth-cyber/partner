<script setup lang="ts">
/**
 * รายรายการ — /commission/entries.
 *
 * Every row in the commission ledger, for answering "why is this number what
 * it is" about one deal. Read-only: settling a row by hand was removed when
 * every payout moved through รอบจ่าย (see CommissionLedgerPanel's own
 * tombstone), so this is an audit view and nothing else.
 *
 * Its own route as of 2026-09-15 — owner: "ย้าย ตั้งจ่าย รอบจ่าย รายรายการ ไป
 * เป็น sub menu". It was the original /commission page, then a tab, and is a
 * page again; the panel underneath never changed.
 */
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import CommissionLedgerPanel from './CommissionLedgerPanel.vue'
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="list"
      title="รายรายการ"
      subtitle="ทุกแถวในบัญชีค่าแนะนำ"
      description="สำหรับตรวจสอบดีลเดียว — ใครขาย ลูกค้าคนไหน อัตราเท่าไร คิดจากฐานอะไร · หน้านี้ดูอย่างเดียว การจ่ายทั้งหมดทำที่ ตั้งจ่าย → รอบจ่าย"
      accent-color="brand"
      storage-key="commission-entries"
    />

    <CompanyScopeNotice action="ดูรายการค่าแนะนำ" />

    <CommissionLedgerPanel />
  </main>
</template>
