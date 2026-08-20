<script setup lang="ts">
/**
 * CompanyScopeNotice — TASK-208 / ADR-038.
 *
 * One shared "you are in ทุกบริษัท mode, pick a company in the header first"
 * panel, so every screen says it in the same words and points at the same
 * control. Renders nothing at all when a company IS selected (and for a
 * Company Admin, who never has this state).
 *
 * `action` lets a screen name what is blocked ("แก้ไขตั้งค่าวิดีโอ",
 * "เพิ่มแบรนด์") — vague blockers are what made the old per-screen pickers
 * confusing in the first place.
 */
import Icon from './Icon.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

withDefaults(defineProps<{ action?: string }>(), { action: 'ทำงานในหน้านี้' })

const store = useActiveCompanyStore()
</script>

<template>
  <div
    v-if="store.requiresCompanyPick"
    class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3"
  >
    <Icon name="alert" :size="18" class="text-amber-600 shrink-0 mt-0.5" />
    <div class="text-xs text-amber-800 leading-relaxed">
      <p class="font-bold">กำลังดูข้ามทุกบริษัท — {{ action }}ไม่ได้</p>
      <p class="mt-0.5">
        เลือกบริษัทที่ต้องการทำงานด้วยจากปุ่ม
        <span class="font-bold">“ทุกบริษัท”</span>
        มุมขวาบนของหน้าจอก่อน แล้วหน้านี้จะปรับตามให้อัตโนมัติ
      </p>
    </div>
  </div>
</template>
