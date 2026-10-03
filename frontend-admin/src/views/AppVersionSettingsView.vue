<script setup lang="ts">
/**
 * AppVersionSettingsView (Admin app) — "เวอร์ชันแอปมือถือ" (2026-10-02, MOB-13).
 *
 * Super-Admin-only screen for the mobile app's version policy, one row per
 * platform (`app_version_policies` — no company_id: ONE binary in each store
 * serves every company, so a per-company minimum would let one company lock
 * every other company's agents out of the app). Backend:
 *   GET /api/v1/platform/app-version-policies   (both platforms)
 *   PUT /api/v1/platform/app-version-policies   (one platform per save)
 * Both Super Admin only, server-side; the route's `requiresSuperAdmin` is UX.
 *
 * One save button PER PLATFORM, not one for the page: iOS and Android are
 * released on different days, and a single button that PUTs twice turns a
 * half-failed save into a guessing game (same reasoning as TASK-175 D2).
 *
 * Every explanation lives behind an InfoPopover (CLAUDE.md §7, 2026-08-17
 * ruling) — the form stays three compact rows per card.
 *
 * Empty fields are sent as null: "no policy" blocks nobody.
 */
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { confirmSaved } from '@/composables/useSaveFeedback'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import PlatformScopeBadge from '@/design-system/components/PlatformScopeBadge.vue'

type Platform = 'ios' | 'android'

interface VersionPolicy {
  platform: Platform
  min_supported_version: string | null
  latest_version: string | null
  store_url: string | null
}

interface PolicyForm {
  min_supported_version: string
  latest_version: string
  store_url: string
}

const PLATFORM_LABELS: Record<Platform, string> = {
  ios: 'iOS (App Store)',
  android: 'Android (Google Play)',
}

const STORE_URL_PLACEHOLDERS: Record<Platform, string> = {
  ios: 'https://apps.apple.com/app/id…',
  android: 'https://play.google.com/store/apps/details?id=…',
}

const MIN_VERSION_HELP =
  'แอปที่เก่ากว่าเวอร์ชันนี้จะถูกบังคับให้อัปเดตก่อนจึงจะใช้งานต่อได้ ใช้เมื่อเวอร์ชันเก่าใช้งานกับระบบไม่ได้แล้ว (เช่น มีการแก้ไขด้านความปลอดภัย) — เว้นว่างไว้ = ไม่บังคับใครเลย รูปแบบ x.y.z เช่น 1.4.0'
const LATEST_VERSION_HELP =
  'เวอร์ชันล่าสุดที่อยู่บนร้านค้าแอป ผู้ใช้ที่เก่ากว่านี้ (แต่ไม่ต่ำกว่าขั้นต่ำ) จะเห็นคำแนะนำให้อัปเดตแต่ยังใช้งานต่อได้ — ต้องไม่ต่ำกว่าเวอร์ชันขั้นต่ำ ไม่เช่นนั้นทุกคนจะถูกบังคับให้อัปเดตไปยังเวอร์ชันที่ยังไม่มีอยู่'
const STORE_URL_HELP =
  'ลิงก์ที่ปุ่ม "อัปเดต" ในแอปจะเปิด ควรเป็นหน้าแอปของเราบน App Store หรือ Google Play ต้องขึ้นต้นด้วย https://'

const loading = ref(false)
const loadError = ref('')
const policies = ref<VersionPolicy[]>([])
const forms = ref<Record<Platform, PolicyForm>>({
  ios: { min_supported_version: '', latest_version: '', store_url: '' },
  android: { min_supported_version: '', latest_version: '', store_url: '' },
})
const saving = ref<Record<Platform, boolean>>({ ios: false, android: false })
const saveErrors = ref<Record<Platform, string>>({ ios: '', android: '' })

function applyPolicy(policy: VersionPolicy): void {
  forms.value[policy.platform] = {
    min_supported_version: policy.min_supported_version ?? '',
    latest_version: policy.latest_version ?? '',
    store_url: policy.store_url ?? '',
  }
  const index = policies.value.findIndex((p) => p.platform === policy.platform)
  if (index >= 0) policies.value[index] = policy
}

async function loadPolicies(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get<{ data: VersionPolicy[] }>('/platform/app-version-policies')
    policies.value = res.data
    res.data.forEach(applyPolicy)
  } catch (e) {
    loadError.value =
      e instanceof ApiError
        ? `โหลดนโยบายเวอร์ชันไม่สำเร็จ (${e.status})`
        : 'โหลดนโยบายเวอร์ชันไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function blankToNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

async function savePolicy(platform: Platform): Promise<void> {
  saving.value[platform] = true
  saveErrors.value[platform] = ''
  const form = forms.value[platform]
  try {
    // ADR-052 — the card is re-read from what the server stored, then the
    // dialog says it saved.
    await confirmSaved(
      () =>
        api.put<{ data: VersionPolicy }>('/platform/app-version-policies', {
          platform,
          min_supported_version: blankToNull(form.min_supported_version),
          latest_version: blankToNull(form.latest_version),
          store_url: blankToNull(form.store_url),
        }),
      {
        apply: (res) => applyPolicy(res.data),
        message: `บันทึกนโยบายเวอร์ชัน ${PLATFORM_LABELS[platform]} แล้ว`,
      },
    )
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const errors = (e.body as { errors?: Record<string, string[]> } | undefined)?.errors
      saveErrors.value[platform] = errors
        ? Object.values(errors).flat().join(' ')
        : 'บันทึกไม่สำเร็จ'
    } else {
      saveErrors.value[platform] =
        e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    saving.value[platform] = false
  }
}

onMounted(loadPolicies)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="display_cog"
      icon-color="text-brand-600"
      title="เวอร์ชันแอปมือถือ"
      subtitle="กำหนดเวอร์ชันขั้นต่ำและเวอร์ชันล่าสุดของแอป iOS / Android (Super Admin เท่านั้น)"
      accent-color="brand"
      storage-key="admin-app-version-settings"
    />

    <PlatformScopeBadge reason="แอปบนร้านค้ามีตัวเดียวสำหรับทุกบริษัท" />

    <div
      v-if="loading"
      class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-5 text-sm text-slate-400"
    >
      กำลังโหลด...
    </div>
    <div
      v-else-if="loadError"
      class="mt-4 bg-white/95 border border-rose-200 rounded-2xl p-5 flex items-center justify-between gap-3"
    >
      <p class="text-sm font-bold text-rose-600">{{ loadError }}</p>
      <button type="button" class="btn-secondary" @click="loadPolicies">ลองใหม่</button>
    </div>
    <EmptyState
      v-else-if="policies.length === 0"
      icon="display_cog"
      title="ยังไม่มีนโยบายเวอร์ชัน"
      message="ระบบยังไม่มีแถวตั้งค่าของแพลตฟอร์มใด ๆ — ติดต่อผู้ดูแลระบบให้รัน migration"
    />

    <div v-else class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4 max-w-5xl">
      <form
        v-for="policy in policies"
        :key="policy.platform"
        :data-platform="policy.platform"
        class="bg-white/95 border border-slate-200 rounded-2xl p-5"
        @submit.prevent="savePolicy(policy.platform)"
      >
        <h2 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
          <Icon name="display_cog" :size="16" class="text-brand-600" />
          {{ PLATFORM_LABELS[policy.platform] }}
        </h2>

        <div class="space-y-3">
          <div>
            <div class="flex items-center justify-between gap-2 mb-1">
              <label :for="`${policy.platform}-min`" class="text-xs font-bold text-slate-500">
                เวอร์ชันขั้นต่ำที่รองรับ
              </label>
              <InfoPopover label="เวอร์ชันขั้นต่ำที่รองรับ" :text="MIN_VERSION_HELP" />
            </div>
            <input
              :id="`${policy.platform}-min`"
              v-model="forms[policy.platform].min_supported_version"
              type="text"
              inputmode="decimal"
              placeholder="เช่น 1.0.0 (เว้นว่าง = ไม่บังคับ)"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>

          <div>
            <div class="flex items-center justify-between gap-2 mb-1">
              <label :for="`${policy.platform}-latest`" class="text-xs font-bold text-slate-500">
                เวอร์ชันล่าสุด
              </label>
              <InfoPopover label="เวอร์ชันล่าสุด" :text="LATEST_VERSION_HELP" />
            </div>
            <input
              :id="`${policy.platform}-latest`"
              v-model="forms[policy.platform].latest_version"
              type="text"
              inputmode="decimal"
              placeholder="เช่น 1.2.0"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>

          <div>
            <div class="flex items-center justify-between gap-2 mb-1">
              <label :for="`${policy.platform}-store`" class="text-xs font-bold text-slate-500">
                ลิงก์ร้านค้าแอป
              </label>
              <InfoPopover label="ลิงก์ร้านค้าแอป" :text="STORE_URL_HELP" />
            </div>
            <input
              :id="`${policy.platform}-store`"
              v-model="forms[policy.platform].store_url"
              type="url"
              :placeholder="STORE_URL_PLACEHOLDERS[policy.platform]"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>

          <p v-if="saveErrors[policy.platform]" class="text-xs font-bold text-rose-600">
            {{ saveErrors[policy.platform] }}
          </p>

          <div class="flex justify-end pt-1">
            <button type="submit" :disabled="saving[policy.platform]" class="btn-primary">
              {{ saving[policy.platform] ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </main>
</template>
