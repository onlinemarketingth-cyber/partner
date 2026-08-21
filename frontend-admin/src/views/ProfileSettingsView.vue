<script setup lang="ts">
/**
 * ProfileSettingsView (Admin app) — personal profile customization
 * (human-requested feature, not tied to any BR): avatar upload +
 * background (gradient or uploaded image), always self-scoped (backend
 * /me/... endpoints never take a user_id — see UserProfileController).
 * Mirrors frontend/src/views/ProfileSettingsView.vue (ADR-003: no
 * shared package between the two frontends yet).
 *
 * Avatar/background files are served from the PUBLIC disk (unlike
 * client documents, which stay private/access-gated per Section 5 rule
 * 6 — see UserProfileService's own comment on why that rule doesn't
 * apply to these non-sensitive, decorative images).
 */
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import { initials } from '@/utils/initials'
import { resolveBackgroundStyle } from '@/utils/userBackground'
import { compressImage } from '@/utils/imageCompression'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import type { AuthUser } from '@/stores/auth'

const auth = useAuthStore()

const avatarBusy = ref(false)
const avatarError = ref('')
const avatarInput = ref<HTMLInputElement | null>(null)

function triggerAvatarPicker(): void {
  avatarInput.value?.click()
}

async function onAvatarSelected(e: Event): Promise<void> {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return

  avatarBusy.value = true
  avatarError.value = ''
  try {
    // Shrink large photos client-side before upload (human-requested) —
    // see utils/imageCompression.ts. Avatars are small on screen, so a
    // fairly aggressive max dimension is fine here.
    const compressed = await compressImage(file, { maxDimension: 640, quality: 0.85 })
    const formData = new FormData()
    formData.append('avatar', compressed)
    const res = await api.postForm<{ data: AuthUser }>('/me/avatar', formData)
    auth.setUser(res.data)
  } catch (e) {
    avatarError.value = e instanceof ApiError ? 'อัปโหลดไม่สำเร็จ — ตรวจสอบชนิดไฟล์ (jpg/png/webp) และขนาด (ไม่เกิน 4MB)' : 'อัปโหลดไม่สำเร็จ'
  } finally {
    avatarBusy.value = false
    if (avatarInput.value) avatarInput.value.value = ''
  }
}

async function removeAvatar(): Promise<void> {
  avatarBusy.value = true
  avatarError.value = ''
  try {
    const res = await api.delete<{ data: AuthUser }>('/me/avatar')
    auth.setUser(res.data)
  } catch {
    avatarError.value = 'ลบรูปไม่สำเร็จ'
  } finally {
    avatarBusy.value = false
  }
}

// --- Background: two mutually-exclusive modes, gradient or image ---
type BackgroundTab = 'gradient' | 'image'
const activeTab = ref<BackgroundTab>(auth.user?.background.type === 'image' ? 'image' : 'gradient')

const color1 = ref(auth.user?.background.config?.color1 ?? '#1e3a8a')
const color2 = ref(auth.user?.background.config?.color2 ?? '#f59e0b')
const angle = ref(auth.user?.background.config?.angle ?? 135)

const gradientBusy = ref(false)
const gradientError = ref('')

async function saveGradient(): Promise<void> {
  gradientBusy.value = true
  gradientError.value = ''
  try {
    const res = await api.put<{ data: AuthUser }>('/me/background', {
      color1: color1.value,
      color2: color2.value,
      angle: angle.value,
    })
    auth.setUser(res.data)
  } catch {
    gradientError.value = 'บันทึกไม่สำเร็จ'
  } finally {
    gradientBusy.value = false
  }
}

const imageBusy = ref(false)
const imageError = ref('')
const backgroundImageInput = ref<HTMLInputElement | null>(null)

function triggerBackgroundImagePicker(): void {
  backgroundImageInput.value?.click()
}

async function onBackgroundImageSelected(e: Event): Promise<void> {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return

  imageBusy.value = true
  imageError.value = ''
  try {
    // Background images render full-bleed, so keep a larger max
    // dimension than the avatar — see utils/imageCompression.ts.
    const compressed = await compressImage(file, { maxDimension: 1920, quality: 0.8 })
    const formData = new FormData()
    formData.append('background_image', compressed)
    const res = await api.postForm<{ data: AuthUser }>('/me/background/image', formData)
    auth.setUser(res.data)
  } catch (e) {
    imageError.value = e instanceof ApiError ? 'อัปโหลดไม่สำเร็จ — ตรวจสอบชนิดไฟล์ (jpg/png/webp) และขนาด (ไม่เกิน 8MB)' : 'อัปโหลดไม่สำเร็จ'
  } finally {
    imageBusy.value = false
    if (backgroundImageInput.value) backgroundImageInput.value.value = ''
  }
}

const resetBusy = ref(false)
async function resetBackground(): Promise<void> {
  resetBusy.value = true
  try {
    const res = await api.delete<{ data: AuthUser }>('/me/background')
    auth.setUser(res.data)
    color1.value = '#1e3a8a'
    color2.value = '#f59e0b'
    angle.value = 135
  } finally {
    resetBusy.value = false
  }
}

// Live preview — gradient tab previews the in-progress color picks
// (not yet saved), image tab previews the already-saved image (there's
// no "unsaved" state for an upload — it saves immediately on select).
const previewStyle = computed(() => {
  if (activeTab.value === 'gradient') {
    return resolveBackgroundStyle({ type: 'gradient', config: { color1: color1.value, color2: color2.value, angle: angle.value }, image_url: null })
  }
  return resolveBackgroundStyle(auth.user?.background ?? null)
})

// --- Name (first name / last name) ---
const firstName = ref(auth.user?.first_name ?? '')
const lastName = ref(auth.user?.last_name ?? '')
const nameBusy = ref(false)
const nameError = ref('')
const nameSaved = ref(false)

async function saveName(): Promise<void> {
  nameBusy.value = true
  nameError.value = ''
  nameSaved.value = false
  try {
    const res = await api.put<{ data: AuthUser }>('/me/name', {
      first_name: firstName.value,
      last_name: lastName.value,
    })
    auth.setUser(res.data)
    nameSaved.value = true
  } catch (e) {
    nameError.value = e instanceof ApiError ? 'บันทึกไม่สำเร็จ — กรุณากรอกทั้งชื่อและนามสกุล' : 'บันทึกไม่สำเร็จ'
  } finally {
    nameBusy.value = false
  }
}

// --- Password ---
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirmation = ref('')
const passwordBusy = ref(false)
const passwordError = ref('')
const passwordSaved = ref(false)

// Show/hide toggle per field — independent so the person can reveal
// just the one they're checking, not all three at once.
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showNewPasswordConfirmation = ref(false)

async function savePassword(): Promise<void> {
  passwordBusy.value = true
  passwordError.value = ''
  passwordSaved.value = false
  try {
    await api.put('/me/password', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: newPasswordConfirmation.value,
    })
    passwordSaved.value = true
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirmation.value = ''
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const errors = (e.body as { errors?: Record<string, string[]> } | undefined)?.errors
      passwordError.value = errors ? Object.values(errors).flat().join(' ') : 'บันทึกไม่สำเร็จ'
    } else {
      passwordError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    passwordBusy.value = false
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="user"
      icon-color="text-brand-600"
      title="โปรไฟล์ของฉัน"
      subtitle="รูปโปรไฟล์และพื้นหลังส่วนตัว"
      accent-color="brand"
      storage-key="admin-profile-settings"
    />

    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Avatar -->
      <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-4">รูปโปรไฟล์</h2>
        <div class="flex items-center gap-4">
          <img
            v-if="auth.user?.avatar_url"
            :src="auth.user.avatar_url"
            :alt="auth.user.name"
            class="w-20 h-20 rounded-full object-cover border border-slate-200"
          />
          <div v-else class="w-20 h-20 rounded-full bg-brand-50 flex items-center justify-center text-xl font-bold text-brand-700">
            {{ initials(auth.user?.name ?? '?') }}
          </div>
          <div class="flex flex-col gap-2">
            <button
              type="button"
              :disabled="avatarBusy"
              @click="triggerAvatarPicker"
              class="btn-primary"
            >
              {{ avatarBusy ? 'กำลังอัปโหลด...' : 'อัปโหลดรูป' }}
            </button>
            <button
              v-if="auth.user?.avatar_url"
              type="button"
              :disabled="avatarBusy"
              @click="removeAvatar"
              class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 font-bold text-sm hover:bg-slate-200 disabled:opacity-50"
            >
              ลบรูป
            </button>
          </div>
          <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onAvatarSelected" />
        </div>
        <p v-if="avatarError" class="mt-3 text-xs font-bold text-rose-600">{{ avatarError }}</p>
        <p class="mt-3 text-xs text-slate-400">jpg / png / webp ขนาดไม่เกิน 4MB</p>
      </div>

      <!-- Background -->
      <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-4">พื้นหลังระบบ</h2>

        <div class="flex gap-1 p-1 rounded-xl bg-slate-100 w-fit mb-4">
          <button
            type="button"
            @click="activeTab = 'gradient'"
            class="px-3 py-1.5 rounded-lg text-xs font-bold"
            :class="activeTab === 'gradient' ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500'"
          >
            ไล่สี
          </button>
          <button
            type="button"
            @click="activeTab = 'image'"
            class="px-3 py-1.5 rounded-lg text-xs font-bold"
            :class="activeTab === 'image' ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500'"
          >
            รูปภาพ
          </button>
        </div>

        <div class="rounded-xl border border-slate-200 h-24 mb-4" :style="previewStyle"></div>

        <div v-if="activeTab === 'gradient'" class="space-y-3">
          <div class="flex items-center gap-3">
            <label class="text-xs font-bold text-slate-500 w-16">สีที่ 1</label>
            <input v-model="color1" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
            <label class="text-xs font-bold text-slate-500 w-16">สีที่ 2</label>
            <input v-model="color2" type="color" class="w-10 h-8 rounded cursor-pointer border border-slate-200" />
          </div>
          <div class="flex items-center gap-3">
            <label class="text-xs font-bold text-slate-500 w-16 shrink-0">องศา</label>
            <input v-model.number="angle" type="range" min="0" max="360" class="flex-1" />
            <span class="text-xs font-bold text-slate-500 w-10 text-right">{{ angle }}°</span>
          </div>
          <button
            type="button"
            :disabled="gradientBusy"
            @click="saveGradient"
            class="btn-primary"
          >
            {{ gradientBusy ? 'กำลังบันทึก...' : 'ใช้พื้นหลังนี้' }}
          </button>
          <p v-if="gradientError" class="text-xs font-bold text-rose-600">{{ gradientError }}</p>
        </div>

        <div v-else class="space-y-3">
          <button
            type="button"
            :disabled="imageBusy"
            @click="triggerBackgroundImagePicker"
            class="btn-primary"
          >
            {{ imageBusy ? 'กำลังอัปโหลด...' : 'อัปโหลดรูปพื้นหลัง' }}
          </button>
          <input ref="backgroundImageInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onBackgroundImageSelected" />
          <p v-if="imageError" class="text-xs font-bold text-rose-600">{{ imageError }}</p>
          <p class="text-xs text-slate-400">jpg / png / webp ขนาดไม่เกิน 8MB</p>
        </div>

        <button
          v-if="auth.user?.background.type"
          type="button"
          :disabled="resetBusy"
          @click="resetBackground"
          class="mt-4 px-4 py-2 rounded-xl bg-slate-100 text-slate-600 font-bold text-sm hover:bg-slate-200 disabled:opacity-50"
        >
          รีเซ็ตเป็นค่าเริ่มต้น
        </button>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Name -->
      <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-4">ชื่อ-นามสกุล</h2>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">ชื่อ</label>
            <input
              v-model="firstName"
              type="text"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">นามสกุล</label>
            <input
              v-model="lastName"
              type="text"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <button
            type="button"
            :disabled="nameBusy"
            @click="saveName"
            class="btn-primary"
          >
            {{ nameBusy ? 'กำลังบันทึก...' : 'บันทึกชื่อ' }}
          </button>
          <p v-if="nameSaved" class="text-xs font-bold text-emerald-600">บันทึกสำเร็จ</p>
          <p v-if="nameError" class="text-xs font-bold text-rose-600">{{ nameError }}</p>
        </div>
      </div>

      <!-- Password -->
      <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-4">เปลี่ยนรหัสผ่าน</h2>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">รหัสผ่านปัจจุบัน</label>
            <div class="relative">
              <input
                v-model="currentPassword"
                :type="showCurrentPassword ? 'text' : 'password'"
                autocomplete="current-password"
                class="w-full px-3 py-2 pr-10 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                @click="showCurrentPassword = !showCurrentPassword"
              >
                <Icon :name="showCurrentPassword ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">รหัสผ่านใหม่</label>
            <div class="relative">
              <input
                v-model="newPassword"
                :type="showNewPassword ? 'text' : 'password'"
                autocomplete="new-password"
                class="w-full px-3 py-2 pr-10 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                @click="showNewPassword = !showNewPassword"
              >
                <Icon :name="showNewPassword ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">ยืนยันรหัสผ่านใหม่</label>
            <div class="relative">
              <input
                v-model="newPasswordConfirmation"
                :type="showNewPasswordConfirmation ? 'text' : 'password'"
                autocomplete="new-password"
                class="w-full px-3 py-2 pr-10 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                @click="showNewPasswordConfirmation = !showNewPasswordConfirmation"
              >
                <Icon :name="showNewPasswordConfirmation ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <button
            type="button"
            :disabled="passwordBusy"
            @click="savePassword"
            class="btn-primary"
          >
            {{ passwordBusy ? 'กำลังบันทึก...' : 'เปลี่ยนรหัสผ่าน' }}
          </button>
          <p v-if="passwordSaved" class="text-xs font-bold text-emerald-600">เปลี่ยนรหัสผ่านสำเร็จ</p>
          <p v-if="passwordError" class="text-xs font-bold text-rose-600">{{ passwordError }}</p>
          <p class="text-xs text-slate-400">อย่างน้อย 8 ตัวอักษร มีพิมพ์ใหญ่ พิมพ์เล็ก และตัวเลข</p>
        </div>
      </div>
    </div>
  </main>
</template>
