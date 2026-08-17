<script setup lang="ts">
/**
 * AdminHomeView — admin dashboard shell.
 *
 * Moved here from the Agent Portal's AdminView.vue per ADR-003 (Admin
 * is now its own app). As of Phase 8, every card here is real —
 * Product catalog, Academy, Manage agents, Gamification config,
 * (Super Admin only) Manage companies, and company-wide read/oversight
 * views over Clients, Referral & Pipeline, and Commission (with the
 * one "mark paid" write action) all link to working screens.
 *
 * Section 5 (Multi-Tenancy): Company Admin sees/manages only their own
 * company; Super Admin sees across companies. The "Manage companies"
 * module below is gated on the real `authStore.user.role` value from
 * `GET /api/v1/me` (not fabricated) — client-side UX only, the real
 * enforcement is CompanyPolicy server-side.
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()

const authStore = useAuthStore()
const isSuperAdmin = computed(() => authStore.user?.role === 'super_admin')

</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="settings"
      title="Admin"
      subtitle="การตั้งค่าระดับบริษัท"
      description="จัดการข้อมูลระดับบริษัท — สินค้า, Academy, ตัวแทน, Gamification"
      accent-color="brand"
      storage-key="admin"
    />

    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <!-- Product catalog — first real (non-inert) module, ERD-001 §Product Catalog -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'product-catalog' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="cube" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">Product catalog</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          แบรนด์ / หมวดหมู่ / แพ็กเกจ / อัตราคอมมิชชั่น (BR-2, BR-3)
        </p>
      </button>

      <!-- Academy — second real module, ERD-001 §Academy, BR-1 -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'academy-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="book" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">Academy</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          โมดูล / แบบทดสอบ / ความคืบหน้าใบรับรองตัวแทน (BR-1)
        </p>
      </button>

      <!-- Manage agents — third real module, Phase 7 -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'agent-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="users" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">จัดการตัวแทน</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          รายชื่อ, บทบาท, สถานะใบรับรองของตัวแทนในบริษัทของคุณ
        </p>
      </button>

      <!-- Gamification config — fourth real module, Phase 7 (API built in Phase 6) -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'gamification-config' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="star" :size="20" class="text-gold-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">ตั้งค่า Gamification</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          gamification_rules — อัตรา XP และเงื่อนไข badge (BR-5, BR-7)
        </p>
      </button>

      <!-- Manage companies — Super Admin only, fifth real module, Phase 7 -->
      <button
        v-if="isSuperAdmin"
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'company-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="building" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">จัดการบริษัท</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          มองเห็นได้เฉพาะ Super Admin — ข้ามบริษัททั้งแพลตฟอร์ม (Section 5)
        </p>
      </button>

      <!-- Clients — sixth real module, Phase 8 (read-only company-wide view) -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'client-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="user" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">ลูกค้า</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          ลูกค้าทั้งหมดในบริษัท — ดูอย่างเดียว (PDPA)
        </p>
      </button>

      <!-- Referral & Pipeline — seventh real module, Phase 8 -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'referral-pipeline-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="pipeline" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">Referral &amp; Pipeline</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          ภาพรวม Referral ทั้งบริษัท (§4.3)
        </p>
      </button>

      <!-- Commission — eighth real module, Phase 8 (the "mark paid" screen) -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'commission-management' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="money" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">Commission</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          คอมมิชชั่นทั้งบริษัท และปุ่ม "จ่ายแล้ว" (BR-4)
        </p>
      </button>

      <!-- นโยบายและรายงาน — TASK-041 (มุมที่ 4): Audit Log + Platform/
           Compliance/Config Health reports. Not added to
           AdminNavigation.vue's top nav (already 9 items) — this card
           is the entry point, same pattern as the other prototype
           screens reached only via a link-out (see
           AgentManagementView.vue's "เครื่องมือเสริม" row). -->
      <button
        type="button"
        class="text-left rounded-2xl border border-slate-200 shadow-sm p-5 bg-white/95 hover:shadow-md transition-shadow"
        @click="router.push({ name: 'policy-report' })"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center">
            <Icon name="shield" :size="20" class="text-brand-600" />
          </div>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-700">นโยบายและรายงาน</p>
        <p class="mt-1 text-xs text-slate-400 leading-relaxed">
          Audit Log, รายงานภาพรวมแพลตฟอร์ม, PDPA/Compliance, สถานะการตั้งค่า
        </p>
      </button>
    </div>
  </main>
</template>
