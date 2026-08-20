<script setup lang="ts">
/**
 * VideoSettingsView (Admin app) — "ตั้งค่าวิดีโอ" (TASK-202).
 *
 * Relocated out of ThemeSettingsView's "PER-COMPANY SETTINGS ROW" into its
 * own submenu page under "ตั้งค่าระบบ" (human request, 2026-08-17: these
 * cards are not theme/branding and each deserves its own findable menu
 * item, same as "ธีม / แบรนด์" and "ตั้งค่า Email SMTP").
 *
 * Lineage (state/behavior unchanged, only the page it lives on moved):
 *  - TASK-104 (human, 2026-08-04): moved this config from the product
 *    catalogue into ThemeSettingsView, because it was never product-scoped
 *    — the same limits govern Academy lesson clips and announcement
 *    attachments, not just product media.
 *  - TASK-175 §3 D2 (human decision): kept as its own card with its own
 *    save button against `/video-processing-settings`, separate from the
 *    theme form's `PUT /company-theme` — a single "บันทึก" that silently
 *    PUTs to two APIs makes a half-failed save impossible to diagnose.
 *
 * Same Super Admin company-picker pattern this codebase already repeats on
 * ThemeSettingsView / ProductCatalogView / AcademyManagementView — kept
 * duplicated here rather than extracted (scope creep beyond TASK-202).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
// TASK-208 — company scope now comes from the header switcher, not a
// per-screen picker (ADR-038).
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// TASK-208 — this screen used to own a company <select> and its own
// /companies fetch. Both moved to the global store (ADR-038): one picker in
// AdminNavigation, one persisted answer, every screen follows it.
const activeCompany = useActiveCompanyStore()

/*
 * TASK-104 (human, 2026-08-04): "ย้ายเมนูตั้งค่าวิดีโอไปที่ ธีม/แบรนด์
 * เปลี่ยนชื่อเป็น ตั้งค่าระบบ."
 *
 * ADR-007's video-compression config used to be a tab in the product
 * catalogue. It was never product-scoped — the same limits govern
 * Academy lesson clips and announcement attachments — so an admin
 * looking for it had to guess "products" for something that is not about
 * products. It belongs with the other company-wide settings.
 *
 * The Super Admin company picker is REUSED, not duplicated: this page
 * already has one driving `loadTheme()`, and two pickers on one screen
 * that can disagree about which company you are editing is a bug waiting
 * to be filed.
 */
const videoSettingsForm = ref({ max_upload_mb: 200, target_resolution: '720p', target_bitrate_kbps: 2500 })
const loadingVideoSettings = ref(false)
const savingVideoSettings = ref(false)
const videoSettingsError = ref('')
const videoSettingsSaved = ref(false)

async function loadVideoSettings(): Promise<void> {
  if (activeCompany.requiresCompanyPick) return
  loadingVideoSettings.value = true
  videoSettingsError.value = ''
  try {
    const path = isSuperAdmin.value
      ? `/video-processing-settings?company_id=${activeCompany.companyId}`
      : '/video-processing-settings'
    const res = await api.get<{ data: typeof videoSettingsForm.value }>(path)
    videoSettingsForm.value = res.data
  } catch (e) {
    videoSettingsError.value = e instanceof ApiError ? `โหลดค่าตั้งวิดีโอไม่สำเร็จ (${e.status})` : 'โหลดค่าตั้งวิดีโอไม่สำเร็จ'
  } finally {
    loadingVideoSettings.value = false
  }
}

async function saveVideoSettings(): Promise<void> {
  if (activeCompany.requiresCompanyPick) {
    videoSettingsError.value = 'กรุณาเลือกบริษัทก่อนบันทึก'

    return
  }
  savingVideoSettings.value = true
  videoSettingsError.value = ''
  videoSettingsSaved.value = false
  try {
    await api.put('/video-processing-settings', {
      ...(isSuperAdmin.value ? { company_id: activeCompany.companyId } : {}),
      ...videoSettingsForm.value,
    })
    videoSettingsSaved.value = true
    setTimeout(() => (videoSettingsSaved.value = false), 2000)
  } catch (e) {
    videoSettingsError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ — ตรวจสอบค่าที่กรอก (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingVideoSettings.value = false
  }
}

// Follows the company picker: changing it reloads the form, so it can
// never end up describing a different company than what's selected.
watch(() => activeCompany.companyId, () => loadVideoSettings())
onMounted(loadVideoSettings)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="settings"
      icon-color="text-brand-600"
      title="ตั้งค่าวิดีโอ"
      subtitle="ค่าตั้งการย่อไฟล์วิดีโอก่อนอัปโหลด — ใช้กับสื่อขาย สินค้า และคลิปใน Academy ทั้งหมด"
      accent-color="brand"
      storage-key="admin-video-settings"
    />

    <!-- TASK-208 — the per-screen picker is gone; scope comes from the
         header switcher. This only explains the blocked state. -->
    <CompanyScopeNotice action="แก้ไขตั้งค่าวิดีโอ" />

    <section class="mt-4 max-w-2xl bg-white/95 border border-slate-200 rounded-2xl p-5">
      <p class="text-base font-bold text-slate-500 mb-1 flex items-center gap-1.5">
        <Icon name="settings" :size="14" /> ตั้งค่าวิดีโอ
      </p>
      <p class="text-xs text-slate-400 mb-3 leading-relaxed">
        ค่าตั้งการย่อไฟล์วิดีโอก่อนอัปโหลด (ขนาดสูงสุด / ความละเอียด / บิตเรตเป้าหมาย) — ใช้กับรูป-วิดีโอสินค้า,
        สื่อการขาย และคลิปใน Academy ทั้งหมด ถ้าไม่ตั้งค่าจะใช้ค่า default ของระบบ
        (BR-7 — ค่านี้เป็น config ที่แก้ไขได้เสมอ ไม่ hardcode)
      </p>

      <p v-if="videoSettingsError" class="mb-2 text-xs font-bold text-rose-600">{{ videoSettingsError }}</p>

      <div v-if="!activeCompany.requiresCompanyPick">
        <p v-if="loadingVideoSettings" class="text-xs text-slate-400">กำลังโหลด...</p>
        <form v-else class="grid grid-cols-1 gap-3" @submit.prevent="saveVideoSettings">
          <div>
            <label class="text-xs font-bold text-slate-500">ขนาดไฟล์วิดีโอสูงสุด (MB)</label>
            <input v-model.number="videoSettingsForm.max_upload_mb" type="number" min="10" max="2000" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">ความละเอียดเป้าหมาย</label>
            <select v-model="videoSettingsForm.target_resolution" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
              <option value="480p">480p</option>
              <option value="720p">720p</option>
              <option value="1080p">1080p</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">บิตเรตเป้าหมาย (kbps)</label>
            <input v-model.number="videoSettingsForm.target_bitrate_kbps" type="number" min="500" max="20000" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div class="flex items-center justify-end gap-2">
            <span v-if="videoSettingsSaved" class="text-xs font-bold text-emerald-600">บันทึกแล้ว</span>
            <button type="submit" :disabled="savingVideoSettings" class="btn-primary">
              {{ savingVideoSettings ? 'กำลังบันทึก...' : 'บันทึกค่าวิดีโอ' }}
            </button>
          </div>
        </form>
      </div>
      <p v-else class="text-xs text-slate-400">เลือกบริษัทด้านบนก่อน</p>
    </section>
  </main>
</template>
