<script setup lang="ts">
/**
 * PlatformScopeBadge — TASK-209 P4 / ADR-038 Class C.
 *
 * Some Admin screens are deliberately platform-wide and must IGNORE the
 * header company switcher: managing the companies themselves, the ADR-036
 * global catalog (whose tables have no company_id at all), and the single
 * platform SMTP row.
 *
 * Without a marker, a Super Admin who has scoped the header to one company
 * reads those screens as scoped too — and is wrong. This is the one-line
 * affordance that says so, in the same words everywhere.
 *
 * Renders for Super Admin only: nobody else can scope, so nobody else can be
 * misled by a scope.
 */
import Icon from './Icon.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

withDefaults(defineProps<{ reason?: string }>(), { reason: '' })

const store = useActiveCompanyStore()
</script>

<template>
  <div
    v-if="store.isSuperAdmin"
    class="mt-4 inline-flex items-start gap-2 px-3 py-2 rounded-xl bg-slate-100 border border-slate-200"
  >
    <Icon name="globe" :size="14" class="text-slate-500 shrink-0 mt-0.5" />
    <p class="text-[11px] font-bold text-slate-600 leading-relaxed">
      หน้านี้เป็นข้อมูล<span class="text-slate-900">ระดับแพลตฟอร์ม</span> — ไม่ขึ้นกับบริษัทที่เลือกไว้ด้านบน
      <span v-if="reason" class="block font-normal text-slate-500">{{ reason }}</span>
    </p>
  </div>
</template>
