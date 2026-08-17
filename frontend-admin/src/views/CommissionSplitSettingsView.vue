<script setup lang="ts">
/**
 * CommissionSplitSettingsView (Admin app) — "คอมมิชชั่นตัวแทนร่วม" (TASK-202).
 *
 * Relocated out of ThemeSettingsView's "PER-COMPANY SETTINGS ROW" into its
 * own submenu page under "ตั้งค่าระบบ" (human request, 2026-08-17: these
 * cards are not theme/branding and each deserves its own findable menu
 * item, same as "ธีม / แบรนด์" and "ตั้งค่า Email SMTP").
 *
 * Lineage: TASK-174 (D2, human decision) — the per-company switch for
 * TASK-026's co-agent commission split, a company-wide feature switch (not
 * a per-plan rate, so it does not live on CommissionPlansView). All of its
 * behavior lives in `CommissionSplitSettingCard.vue` (design-system) —
 * this page is only the shell + the Super Admin company picker it needs;
 * the card's internals are untouched by this move.
 *
 * Same Super Admin company-picker pattern this codebase already repeats on
 * ThemeSettingsView / ProductCatalogView / AcademyManagementView — kept
 * duplicated here rather than extracted (scope creep beyond TASK-202).
 */
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import CommissionSplitSettingCard from '@/design-system/components/CommissionSplitSettingCard.vue'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

interface CompanyItem {
  id: number
  name: string
  slug: string
}

// Super Admin company picker.
const companies = ref<CompanyItem[]>([])
const selectedCompanyId = ref<number | null>(null)
const companiesError = ref('')

async function loadCompanies(): Promise<void> {
  try {
    const res = await api.get<{ data: CompanyItem[] }>('/companies')
    companies.value = res.data
    const first = res.data[0]
    if (first) {
      selectedCompanyId.value = first.id
    }
  } catch (e) {
    companiesError.value = e instanceof ApiError ? e.message : 'โหลดรายชื่อบริษัทไม่สำเร็จ'
  }
}

onMounted(() => {
  if (isSuperAdmin.value) loadCompanies()
})
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="money"
      icon-color="text-brand-600"
      title="คอมมิชชั่นตัวแทนร่วม"
      subtitle="เปิด/ปิดการแบ่งคอมมิชชั่นกับตัวแทนร่วมดีล"
      accent-color="brand"
      storage-key="admin-commission-split-settings"
    />

    <!-- Super Admin company picker -->
    <div v-if="isSuperAdmin" class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-4 flex items-center gap-3">
      <Icon name="building" :size="18" class="text-brand-600 shrink-0" />
      <label class="text-xs font-bold text-slate-500 shrink-0">บริษัท</label>
      <select
        v-model.number="selectedCompanyId"
        class="flex-1 max-w-xs px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>
    <p v-if="companiesError" class="mt-2 text-xs font-bold text-rose-600">{{ companiesError }}</p>

    <div class="mt-4 max-w-2xl">
      <CommissionSplitSettingCard :company-id="selectedCompanyId" :is-super-admin="isSuperAdmin" />
    </div>
  </main>
</template>
