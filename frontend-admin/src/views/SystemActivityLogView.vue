<script setup lang="ts">
/**
 * "บันทึกการใช้งานระบบ" — the combined activity log, on its own page under
 * ตั้งค่าระบบ (human request, 2026-09-05).
 *
 * The log itself has existed since TASK-041 and gained its filters in
 * TASK-240/241/242, but it lived as tab 1 of 4 inside "นโยบายและรายงาน" —
 * findable only by someone who already knew it was there. The human asked
 * for it "อยู่ใน เมนู Setting", which is what this page is: a menu entry, a
 * URL worth bookmarking, and nothing else on the screen competing with it.
 *
 * Deliberately thin. Every behaviour is in ActivityLogPanel, which the
 * reports page renders too — one implementation, so the page somebody
 * bookmarked and the page somebody was sent cannot disagree about what the
 * system recorded.
 */
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import ActivityLogPanel from './ActivityLogPanel.vue'
</script>

<template>
  <div class="px-4 lg:px-6 pb-4 lg:pb-6 w-full" style="font-family: Kanit, sans-serif;">
    <HeroHeader
      icon="document"
      title="บันทึกการใช้งานระบบ"
      subtitle="ใครทำอะไรไปบ้าง — ค้นหาตามช่วงวันที่ บุคคล และกิจกรรม"
      accent-color="brand"
      storage-key="system-activity-log"
    />

    <div class="mt-4">
      <ActivityLogPanel />
    </div>
  </div>
</template>
