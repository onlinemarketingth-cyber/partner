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
 * this page is only the shell.
 *
 * TASK-208 / ADR-038 — the Super Admin company picker this page used to
 * carry (and the nine copies of it on other screens) is gone: scope now
 * comes from the single switcher in AdminNavigation, via the activeCompany
 * store. The old header comment here even flagged the duplication as
 * deliberate scope-creep avoidance; this is the task that paid it off.
 */
import { computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import CommissionSplitSettingCard from '@/design-system/components/CommissionSplitSettingCard.vue'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const activeCompany = useActiveCompanyStore()

onMounted(() => activeCompany.loadCompanies())
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

    <CompanyScopeNotice action="แก้ไขการแบ่งคอมมิชชั่น" />

    <div v-if="!activeCompany.requiresCompanyPick" class="mt-4 max-w-2xl">
      <!-- key: remount the card when the company changes, so it refetches
           instead of showing the previous company's switch state. -->
      <CommissionSplitSettingCard
        :key="activeCompany.companyId ?? 'own'"
        :company-id="activeCompany.companyId"
        :is-super-admin="isSuperAdmin"
      />
    </div>
  </main>
</template>
