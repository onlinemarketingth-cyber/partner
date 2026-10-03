<script setup lang="ts">
/**
 * ProfileSettingsView — personal profile customization (human-requested
 * feature, not tied to any BR): avatar upload, name, password, bank
 * account. Always self-scoped (backend /me/... endpoints never take a
 * user_id — see UserProfileController).
 *
 * TASK-160 — the personal BACKGROUND picker used to live here too
 * (gradient / image tabs). It is gone: the app's look is the company's,
 * set once in Admin, and is no longer an individual override. The avatar
 * stays, because that is the agent's identity rather than the company's
 * brand surface.
 *
 * Avatar files are served from the PUBLIC disk (unlike
 * client documents, which stay private/access-gated per Section 5 rule
 * 6 — see UserProfileService's own comment on why that rule doesn't
 * apply to these non-sensitive, decorative images).
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { api, ApiError } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — every save on this screen used to set a
// sticky inline "บันทึกสำเร็จ" ref that never cleared: scroll down, save
// something else, and the page ended up covered in stale green ticks with
// no way to tell which one just happened. Those refs are gone; a toast
// auto-dismisses and names the specific thing that was saved.
import { useToastStore } from '@/stores/toast'
// Sprint TZI18N-2 — payout section strings come from /lang/{th,en}.json.
import { useI18n } from '@/composables/useI18n'
import { initials } from '@/utils/initials'
import { compressImage } from '@/utils/imageCompression'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import NationalIdSegments from '@/design-system/components/NationalIdSegments.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import Icon from '@/design-system/components/Icon.vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import AccountDeletionDialog from '@/design-system/components/AccountDeletionDialog.vue'
import {
  authenticateBiometric,
  checkBiometricSupport,
  isBiometricLockEnabled,
  setBiometricLockEnabled,
} from '@/platform/biometric'
import type { AccountDeletionOutcome, AuthUser } from '@/stores/auth'

const { td } = useI18n()
const auth = useAuthStore()
const themeStore = useThemeStore()
const router = useRouter()
const toast = useToastStore()

// Sign out: clear the Sanctum session (auth.logout() also clears the
// client-side user) then send the person to the login screen. Guarded so
// a network hiccup on /logout still returns the UI to a sane state.
// TASK-064 — carries ?company=<slug> so the login page stays themed
// after logout (see theme.ts's loginRouteLocation() docblock).
const loggingOut = ref(false)
async function handleLogout(): Promise<void> {
  loggingOut.value = true
  const target = themeStore.loginRouteLocation()
  try {
    await auth.logout()
  } finally {
    loggingOut.value = false
    router.push(target)
  }
}

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
    toast.success('อัปเดตรูปโปรไฟล์แล้ว')
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
    toast.success('ลบรูปโปรไฟล์แล้ว')
  } catch {
    avatarError.value = 'ลบรูปไม่สำเร็จ'
  } finally {
    avatarBusy.value = false
  }
}

// --- Name (first name / last name) ---
const firstName = ref(auth.user?.first_name ?? '')
const lastName = ref(auth.user?.last_name ?? '')
const nameBusy = ref(false)
const nameError = ref('')

async function saveName(): Promise<void> {
  nameBusy.value = true
  nameError.value = ''
  try {
    const res = await api.put<{ data: AuthUser }>('/me/name', {
      first_name: firstName.value,
      last_name: lastName.value,
    })
    auth.setUser(res.data)
    // ADR-052 — the inputs were seeded once at setup and never watched, so
    // without this they keep what was TYPED (untrimmed, pre-normalisation)
    // and look stored when they may not be. Re-read them from the server.
    firstName.value = res.data.first_name ?? ''
    lastName.value = res.data.last_name ?? ''
    toast.success(`บันทึกชื่อ "${res.data.name}" แล้ว`)
  } catch (e) {
    nameError.value = e instanceof ApiError ? 'บันทึกไม่สำเร็จ — กรุณากรอกทั้งชื่อและนามสกุล' : 'บันทึกไม่สำเร็จ'
  } finally {
    nameBusy.value = false
  }
}

/* --- Notification email (2026-08-22) ---------------------------------
 *
 * The switch flips OPTIMISTICALLY and rolls back on failure, rather than
 * waiting for the round-trip. A toggle that ignores the first press for
 * 300ms gets pressed twice, and the second press is the one that sticks —
 * leaving the person with the opposite of what they wanted and no idea why.
 *
 * The server's answer is still authoritative: the response carries the full
 * owner resource, and auth.setUser() overwrites whatever this guessed.
 */
const emailNotificationsEnabled = ref(auth.user?.email_notifications_enabled ?? true)
const savingEmailPref = ref(false)
const emailPrefError = ref('')

async function toggleEmailNotifications(): Promise<void> {
  if (savingEmailPref.value) return

  const previous = emailNotificationsEnabled.value
  const next = !previous

  emailNotificationsEnabled.value = next
  savingEmailPref.value = true
  emailPrefError.value = ''

  try {
    const res = await api.put<{ data: AuthUser }>('/me/notification-preferences', {
      email_notifications_enabled: next,
    })
    auth.setUser(res.data)
    emailNotificationsEnabled.value = res.data.email_notifications_enabled ?? next
    toast.success(next ? 'เปิดการแจ้งเตือนทางอีเมลแล้ว' : 'ปิดการแจ้งเตือนทางอีเมลแล้ว')
  } catch {
    emailNotificationsEnabled.value = previous
    emailPrefError.value = 'บันทึกไม่สำเร็จ — กรุณาลองใหม่'
  } finally {
    savingEmailPref.value = false
  }
}

// --- Bank account (TASK-044 Phase A) — self-service, always operates on
// the caller's own row via PUT /me/bank-account (UpdateBankAccountRequest,
// all 3 fields optional/nullable). Unlike the Admin edit form, this view
// gets back the FULL unmasked number (UserResource::forOwner()), so there
// is no "masked placeholder, only send if changed" problem here — the
// fields are simply prefilled with the real current value like the Name
// section above and resubmitted as-is.
const bankName = ref(auth.user?.bank_name ?? '')
const bankAccountNumber = ref(auth.user?.bank_account_number ?? '')
const bankAccountHolderName = ref(auth.user?.bank_account_holder_name ?? '')
const bankBusy = ref(false)
const bankError = ref('')

async function saveBankAccount(): Promise<void> {
  bankBusy.value = true
  bankError.value = ''
  try {
    const res = await api.put<{ data: AuthUser }>('/me/bank-account', {
      bank_name: bankName.value || null,
      bank_account_number: bankAccountNumber.value || null,
      bank_account_holder_name: bankAccountHolderName.value || null,
    })
    auth.setUser(res.data)
    // ADR-052 — show what the server stored, not what was typed (see saveName).
    bankName.value = res.data.bank_name ?? ''
    bankAccountNumber.value = res.data.bank_account_number ?? ''
    bankAccountHolderName.value = res.data.bank_account_holder_name ?? ''
    toast.success('บันทึกบัญชีธนาคารแล้ว')
  } catch (e) {
    bankError.value = e instanceof ApiError ? 'บันทึกไม่สำเร็จ — ตรวจสอบข้อมูลที่กรอก' : 'บันทึกไม่สำเร็จ'
  } finally {
    bankBusy.value = false
  }
}

// --- Identity document (2026-08-27) ---
//
// Removed from the registration form on purpose (see RegisterView.vue's own
// note) and collected here instead, where the person already has an account
// of their own to protect. Same self-scoped pattern as the bank section
// above: PUT /me/id-document always operates on the caller's own row.
//
// UNLIKE the bank fields, the server REQUIRES both values when this form is
// submitted — an empty submission could only ever be a mistake. The
// per-company duplicate check also runs on this endpoint, so a number
// already used by somebody else in the company comes back as a 422 on
// `national_id` and is shown inline below.
type IdDocumentType = 'thai_national_id' | 'passport'

const idDocumentType = ref<IdDocumentType>(auth.user?.id_document_type ?? 'thai_national_id')
const nationalId = ref(auth.user?.national_id ?? '')
const idDocBusy = ref(false)
const idDocError = ref('')

const isThaiIdDocument = computed(() => idDocumentType.value === 'thai_national_id')

function selectIdDocumentType(next: IdDocumentType): void {
  if (idDocumentType.value === next) return
  idDocumentType.value = next
  // Clear the number with the type, same reasoning as the registration form
  // had: a 13-digit Thai ID under a "passport" label is a guaranteed 422,
  // and a confusing one. The two documents share one column; they must not
  // share a value in this form.
  nationalId.value = ''
  idDocError.value = ''
}

async function saveIdDocument(): Promise<void> {
  idDocBusy.value = true
  idDocError.value = ''
  try {
    const res = await api.put<{ data: AuthUser }>('/me/id-document', {
      id_document_type: idDocumentType.value,
      // Passport letters go up uppercased to match what the field shows;
      // the backend upper-cases before hashing either way, so this only
      // avoids storing a value that renders one way and is stored another.
      national_id: isThaiIdDocument.value ? nationalId.value.trim() : nationalId.value.trim().toUpperCase(),
    })
    auth.setUser(res.data)
    // ADR-052 — show what the server stored, not what was typed (see saveName).
    // Assigned directly, NOT through selectIdDocumentType(): that clears the
    // number on a type change, which is right for a person switching tabs
    // and wrong for a sync.
    idDocumentType.value = res.data.id_document_type ?? idDocumentType.value
    nationalId.value = res.data.national_id ?? ''
    toast.success(td('payout.id_saved'))
  } catch (e) {
    // The server's own message when it has one — it carries the two cases
    // the person can act on (bad shape, already used in this company).
    // Rewriting them here would replace a specific answer with a vague one.
    const body = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]>; message?: string }) : null
    idDocError.value =
      body?.errors?.national_id?.[0] ??
      body?.errors?.id_document_type?.[0] ??
      (e instanceof ApiError ? td('payout.save_failed') : td('common.error_generic'))
  } finally {
    idDocBusy.value = false
  }
}

// --- Password ---
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirmation = ref('')
const passwordBusy = ref(false)
const passwordError = ref('')

// Show/hide toggle per field — independent so the person can reveal
// just the one they're checking, not all three at once.
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showNewPasswordConfirmation = ref(false)

async function savePassword(): Promise<void> {
  passwordBusy.value = true
  passwordError.value = ''
  try {
    await api.put('/me/password', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: newPasswordConfirmation.value,
    })
    toast.success('เปลี่ยนรหัสผ่านแล้ว')
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

/* --- Biometric unlock (MOB-29, 2026-10-02) ---------------------------------
 *
 * Owner decision: the agent switches it on and off here. Shown ONLY inside
 * the iOS/Android app and only when the phone can actually do it (hardware
 * present and a face/finger enrolled) — in a browser checkBiometricSupport()
 * answers "unavailable" without loading anything, so the row never renders.
 *
 * Exception: a lock that is already ON stays visible even if the phone has
 * since lost its biometrics, so the agent can still switch it off (the lock
 * screen falls back to the device passcode meanwhile).
 *
 * Switching ON asks for a successful scan first: a setting that would lock
 * the agent out on the next launch must be proven to work now, while they
 * can still back out. Switching OFF needs nothing — it only removes a gate.
 * The setting lives on the phone (platform/biometric.ts), never on the server.
 */
const showBiometric = ref(false)
const biometricEnabled = ref(false)
const savingBiometric = ref(false)
const biometricError = ref('')

onMounted(async () => {
  const userId = auth.user?.id
  if (userId === undefined) return

  const [support, enabled] = await Promise.all([checkBiometricSupport(), isBiometricLockEnabled(userId)])
  biometricEnabled.value = enabled
  showBiometric.value = support.available || enabled
})

async function toggleBiometric(): Promise<void> {
  const userId = auth.user?.id
  if (savingBiometric.value || userId === undefined) return

  const next = !biometricEnabled.value
  savingBiometric.value = true
  biometricError.value = ''
  try {
    if (next) {
      const result = await authenticateBiometric({
        reason: td('profile.biometric_confirm_reason', 'ยืนยันตัวตนเพื่อเปิดใช้การปลดล็อก'),
        cancel: td('common.cancel', 'ยกเลิก'),
        title: td('profile.biometric_title', 'ปลดล็อกด้วย Face ID / ลายนิ้วมือ'),
      })
      if (result !== 'ok') {
        if (result !== 'cancelled') {
          biometricError.value = td('profile.biometric_not_confirmed', 'ยังไม่ได้เปิด — ยืนยันตัวตนไม่สำเร็จ')
        }

        return
      }
    }

    await setBiometricLockEnabled(userId, next)
    biometricEnabled.value = next
    toast.success(
      next
        ? td('profile.biometric_enabled', 'เปิดการปลดล็อกด้วย Face ID / ลายนิ้วมือแล้ว')
        : td('profile.biometric_disabled', 'ปิดการปลดล็อกด้วย Face ID / ลายนิ้วมือแล้ว'),
    )
  } catch {
    biometricError.value = td('profile.biometric_save_failed', 'บันทึกไม่สำเร็จ — กรุณาลองใหม่')
  } finally {
    savingBiometric.value = false
  }
}

/* --- Account deletion request (MOB-30, 2026-10-02) ------------------------
 *
 * Both the web portal and the app — one rule for both. The dialog collects
 * the password and reason; the auth store sends the request and forgets the
 * session (the server has revoked it). This view only decides where the
 * agent lands: the login screen, carrying a flag that makes it say the
 * request was sent — otherwise a person who just asked to be removed would
 * see a plain login form and wonder whether anything happened.
 */
const showDeletionDialog = ref(false)

/*
 * MOB-12 follow-up (owner decision 2026-10-03): the account may already be
 * GONE ('deleted' — nothing unpaid, or waived) or WAITING for an admin
 * ('pending' — the agent kept their unpaid commission). Two different
 * notices, because "your request was sent" over an account that no longer
 * exists would send the person back to wait for something already done.
 */
function onDeletionRequested(outcome: AccountDeletionOutcome = 'pending'): void {
  const login = themeStore.loginRouteLocation()
  const notice = outcome === 'deleted' ? 'account_deleted' : 'deletion_requested'
  void router.replace({ ...login, query: { ...login.query, notice } })
}

/**
 * TASK-105 (human: "frontend ตรง head ปรับชื่อให้ตรงกับ setup จากระบบ").
 *
 * The page title is the SAME configured label as the bottom-nav tab that
 * opens this screen. Hardcoding it meant a company that renamed the tab
 * still landed on a page announcing the platform's own name for it.
 * Fallbacks match BottomNav.vue exactly — if the two drifted, an unset
 * tenant would see the mismatch this task exists to remove.
 */
const pageTitle = computed(() => themeStore.label('nav_profile', 'โปรไฟล์'))
const pageIcon = computed(() => themeStore.icon('nav_profile', 'user'))
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      :icon="pageIcon"
      icon-color="text-ink-brand"
      :title="pageTitle"
      :subtitle="td('profile.subtitle')"
      accent-color="brand"
      storage-key="profile-settings"
      back-page="/"
      :back-label="td('nav.home2')"
    />

    <div class="mt-4 grid grid-cols-1 gap-4">
      <!-- Avatar -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-4">{{ td('profile.avatar') }}</h2>
        <div class="flex items-center gap-4">
          <img
            v-if="auth.user?.avatar_url"
            :src="auth.user.avatar_url"
            :alt="auth.user.name"
            class="w-20 h-20 rounded-full object-cover border border-line-card"
          />
          <div v-else class="w-20 h-20 rounded-full bg-brand-50 flex items-center justify-center text-xl font-bold text-brand-700">
            {{ initials(auth.user?.name ?? '?') }}
          </div>
          <div class="flex flex-col gap-2">
            <!-- TASK-079 Phase 4 — AppButton: its spinner replaces the
                 "กำลังอัปโหลด..." label swap (no reflow) and it brings the
                 44px tap target these buttons never had. -->
            <AppButton :loading="avatarBusy" @click="triggerAvatarPicker">{{ td('profile.upload_photo') }}</AppButton>
            <button
              v-if="auth.user?.avatar_url"
              type="button"
              :disabled="avatarBusy"
              @click="removeAvatar"
              class="px-4 py-2 rounded-xl bg-surface-chip text-ink-card-muted font-bold text-sm hover:bg-slate-200 disabled:opacity-50"
            >
              {{ td('profile.remove_photo') }}
            </button>
          </div>
          <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onAvatarSelected" />
        </div>
        <p v-if="avatarError" class="mt-3 text-xs font-bold text-ink-danger">{{ avatarError }}</p>
        <p class="mt-3 text-xs text-ink-card-subtle">{{ td('profile.photo_hint') }}</p>
      </div>

      <!-- TASK-160 — the personal background picker was here (gradient /
           image tabs, colour pickers, angle slider, upload, reset).
           Removed on the human's instruction: the app's look is the
           company's, set once in Admin, and is no longer something an
           individual agent can override. Nothing replaces it — a card
           saying "your background now comes from your company" would be
           chrome explaining an absence.

           Avatar stays: that is the agent's own identity, not the
           company's brand surface. -->
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4">
      <!-- Name -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-4">{{ td('field.full_name') }}</h2>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('field.first_name') }}</label>
            <input
              v-model="firstName"
              type="text"
              class="bg-surface-input text-ink-input w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('field.last_name') }}</label>
            <input
              v-model="lastName"
              type="text"
              class="bg-surface-input text-ink-input w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <AppButton :loading="nameBusy" @click="saveName">{{ td('profile.save_name') }}</AppButton>
          <!-- Success is a toast now (TASK-079 Phase 2) — the old inline
               "บันทึกสำเร็จ" never cleared itself. Errors stay inline,
               next to the fields the person has to correct. -->
          <p v-if="nameError" class="text-xs font-bold text-ink-danger">{{ nameError }}</p>
        </div>
      </div>

      <!-- Notification email (2026-08-22) —————————————————————————————
           Shipped with the email itself, not after it. Email an agent cannot
           turn off is not a feature: the first person who does not want it
           filters the sender, which silently costs them the approval and
           payment mails too. -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-1">{{ td('profile.email_notifications') }}</h2>
        <p class="text-xs text-ink-card-subtle mb-4">
          {{ td('profile.email_notifications_help') }}
        </p>

        <button
          type="button"
          :disabled="savingEmailPref"
          class="w-full min-h-[44px] flex items-center justify-between gap-3 disabled:opacity-60"
          @click="toggleEmailNotifications"
        >
          <span class="text-sm font-bold text-ink-card">
            {{ emailNotificationsEnabled ? 'เปิดอยู่' : 'ปิดอยู่' }}
          </span>

          <!-- A switch, not a checkbox: this is an immediate preference
               change, not a field inside a form the person still has to
               submit. The track uses the derived surface pair so it stays
               visible on a dark tenant (ADR-023). -->
          <span
            class="relative shrink-0 w-12 h-7 rounded-full transition-colors"
            :class="emailNotificationsEnabled ? 'bg-surface-primary' : 'bg-surface-chip'"
            role="switch"
            :aria-checked="emailNotificationsEnabled"
          >
            <span
              class="absolute top-1 w-5 h-5 rounded-full bg-surface-card shadow transition-all"
              :class="emailNotificationsEnabled ? 'left-6' : 'left-1'"
            ></span>
          </span>
        </button>

        <p v-if="emailPrefError" class="text-xs font-bold text-ink-danger mt-2">{{ emailPrefError }}</p>
      </div>

      <!-- Biometric unlock (MOB-29) — app only; see showBiometric. The
           explanation is behind the ⓘ (CLAUDE.md §7), the row stays one
           control. Same switch markup as the email row above. -->
      <div v-if="showBiometric" class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <div class="flex items-center gap-1 mb-3">
          <h2 class="text-sm font-bold text-ink-card">
            {{ td('profile.biometric_title', 'ปลดล็อกด้วย Face ID / ลายนิ้วมือ') }}
          </h2>
          <InfoPopover :label="td('profile.biometric_title', 'ปลดล็อกด้วย Face ID / ลายนิ้วมือ')">
            {{
              td(
                'profile.biometric_help',
                'เมื่อเปิดไว้ แอปจะขอสแกนใบหน้าหรือลายนิ้วมือ (หรือรหัสผ่านเครื่อง) ทุกครั้งที่เปิดแอป และเมื่อกลับเข้าแอปหลังจากออกไปนานกว่า 1 นาที เพื่อไม่ให้คนที่หยิบโทรศัพท์ของคุณไปเห็นข้อมูลลูกค้าและค่าแนะนำ — ระบบไม่ได้เก็บรหัสผ่านของคุณ และข้อมูลใบหน้า/ลายนิ้วมือไม่ออกจากเครื่อง',
              )
            }}
          </InfoPopover>
        </div>

        <button
          type="button"
          :disabled="savingBiometric"
          class="w-full min-h-[44px] flex items-center justify-between gap-3 disabled:opacity-60"
          @click="toggleBiometric"
        >
          <span class="text-sm font-bold text-ink-card">
            {{ biometricEnabled ? td('profile.switch_on', 'เปิดอยู่') : td('profile.switch_off', 'ปิดอยู่') }}
          </span>
          <span
            class="relative shrink-0 w-12 h-7 rounded-full transition-colors"
            :class="biometricEnabled ? 'bg-surface-primary' : 'bg-surface-chip'"
            role="switch"
            :aria-checked="biometricEnabled"
          >
            <span
              class="absolute top-1 w-5 h-5 rounded-full bg-surface-card shadow transition-all"
              :class="biometricEnabled ? 'left-6' : 'left-1'"
            ></span>
          </span>
        </button>

        <p v-if="biometricError" class="text-xs font-bold text-ink-danger mt-2">{{ biometricError }}</p>
      </div>

      <!-- Password -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-4">{{ td('profile.change_password') }}</h2>
        <div class="space-y-3">
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('profile.current_password') }}</label>
            <div class="relative">
              <input
                v-model="currentPassword"
                :type="showCurrentPassword ? 'text' : 'password'"
                autocomplete="current-password"
                class="bg-surface-input text-ink-input w-full px-3 py-2 pr-10 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-card-subtle hover:text-ink-card-muted"
                @click="showCurrentPassword = !showCurrentPassword"
              >
                <Icon :name="showCurrentPassword ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('profile.new_password') }}</label>
            <div class="relative">
              <input
                v-model="newPassword"
                :type="showNewPassword ? 'text' : 'password'"
                autocomplete="new-password"
                class="bg-surface-input text-ink-input w-full px-3 py-2 pr-10 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-card-subtle hover:text-ink-card-muted"
                @click="showNewPassword = !showNewPassword"
              >
                <Icon :name="showNewPassword ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('profile.confirm_new_password') }}</label>
            <div class="relative">
              <input
                v-model="newPasswordConfirmation"
                :type="showNewPasswordConfirmation ? 'text' : 'password'"
                autocomplete="new-password"
                class="bg-surface-input text-ink-input w-full px-3 py-2 pr-10 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <button
                type="button"
                tabindex="-1"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-card-subtle hover:text-ink-card-muted"
                @click="showNewPasswordConfirmation = !showNewPasswordConfirmation"
              >
                <Icon :name="showNewPasswordConfirmation ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>
          <AppButton :loading="passwordBusy" @click="savePassword">{{ td('profile.change_password') }}</AppButton>
          <p v-if="passwordError" class="text-xs font-bold text-ink-danger">{{ passwordError }}</p>
          <p class="text-xs text-ink-card-subtle">{{ td('profile.password_rule') }}</p>
        </div>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4">
      <!-- 2026-08-27 — "can you be paid yet?", answered before the two forms
           that answer it rather than after. The flag is the SERVER's
           (User::hasCompletePayoutDetails via UserResource), never re-derived
           here, so this notice and the payout gate can never disagree.

           Shown only while something is missing: a permanent green "all
           good" panel is noise on every visit after the first. -->
      <div
        v-if="auth.user && !auth.user.payout_details_complete"
        class="bg-surface-warning border border-line-card rounded-2xl p-4 flex items-start gap-3"
      >
        <Icon name="info" :size="18" class="mt-0.5 shrink-0 text-ink-warning" />
        <div>
          <p class="text-sm font-bold text-ink-warning">{{ td('payout.incomplete_title') }}</p>
          <p class="text-xs text-ink-card-subtle mt-0.5">
            {{ td('payout.incomplete_body') }}
          </p>
        </div>
      </div>

      <!-- Identity document — collected here, not at sign-up (2026-08-27) -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-1">{{ td('payout.id_document') }}</h2>
        <p class="text-xs text-ink-card-subtle mb-3">
          {{ td('payout.id_document_why') }}
        </p>

        <div class="grid grid-cols-2 gap-2 mb-3">
          <button
            type="button"
            :aria-pressed="idDocumentType === 'thai_national_id'"
            class="min-h-[44px] px-3 py-2.5 rounded-xl border text-sm font-bold transition-colors"
            :class="
              idDocumentType === 'thai_national_id'
                ? 'bg-brand-600 border-brand-600 text-ink-primary'
                : 'bg-surface-card border-line-card text-ink-card-muted hover:border-brand-500'
            "
            @click="selectIdDocumentType('thai_national_id')"
          >
            {{ td('payout.id_type_thai') }}
          </button>
          <button
            type="button"
            :aria-pressed="idDocumentType === 'passport'"
            class="min-h-[44px] px-3 py-2.5 rounded-xl border text-sm font-bold transition-colors"
            :class="
              idDocumentType === 'passport'
                ? 'bg-brand-600 border-brand-600 text-ink-primary'
                : 'bg-surface-card border-line-card text-ink-card-muted hover:border-brand-500'
            "
            @click="selectIdDocumentType('passport')"
          >
            {{ td('payout.id_type_passport') }}
          </button>
        </div>

        <label class="text-xs font-bold text-ink-card-muted block mb-1">
          {{ isThaiIdDocument ? td('payout.id_number_thai') : td('payout.id_number_passport') }}
        </label>
        <!-- A Thai ID gets the card's own five groups (NationalIdSegments);
             a passport is one free-form field, because its number has no
             printed grouping to mirror. Same split the registration form
             used before this moved here. -->
        <NationalIdSegments
          v-if="isThaiIdDocument"
          id="profile_national_id"
          v-model="nationalId"
          :invalid="Boolean(idDocError)"
          :aria-label="td('payout.id_number_thai')"
          @update:model-value="idDocError = ''"
        />
        <input
          v-else
          id="profile_national_id"
          v-model="nationalId"
          type="text"
          autocomplete="off"
          spellcheck="false"
          autocapitalize="characters"
          maxlength="12"
          :placeholder="td('payout.passport_ph')"
          class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder placeholder:normal-case w-full px-3 py-2 rounded-xl border text-sm uppercase tracking-wide focus:outline-none focus:ring-2 focus:ring-brand-200"
          :class="idDocError ? 'border-rose-400' : 'border-line-input'"
          @input="idDocError = ''"
        />
        <p class="text-xs text-ink-card-subtle mt-1">
          {{ isThaiIdDocument ? td('payout.id_hint_thai') : td('payout.id_hint_passport') }}
        </p>

        <AppButton :loading="idDocBusy" class="mt-3" @click="saveIdDocument">{{ td('payout.id_save') }}</AppButton>
        <p v-if="idDocError" class="mt-2 text-xs font-bold text-ink-danger">{{ idDocError }}</p>
      </div>

      <!-- Bank account (TASK-044 Phase A) -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5">
        <h2 class="text-sm font-bold text-ink-card mb-4">{{ td('bank.title') }}</h2>
        <p class="text-xs text-ink-card-subtle mb-3">{{ td('bank.help') }}</p>
        <div class="grid grid-cols-1 gap-3">
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('bank.name') }}</label>
            <input
              v-model="bankName"
              type="text"
              :placeholder="td('bank.name_ph')"
              class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('bank.account_number') }}</label>
            <input
              v-model="bankAccountNumber"
              type="text"
              inputmode="numeric"
              :placeholder="td('bank.account_number_ph')"
              class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label class="text-xs font-bold text-ink-card-muted block mb-1">{{ td('bank.account_name') }}</label>
            <input
              v-model="bankAccountHolderName"
              type="text"
              :placeholder="td('bank.holder_ph')"
              class="bg-surface-input text-ink-input placeholder:text-ink-input-placeholder w-full px-3 py-2 rounded-xl border border-line-input text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
        </div>
        <AppButton :loading="bankBusy" class="mt-3" @click="saveBankAccount">{{ td('bank.save') }}</AppButton>
        <p v-if="bankError" class="mt-2 text-xs font-bold text-ink-danger">{{ bankError }}</p>
      </div>

      <!-- Sign out -->
      <div class="bg-surface-card/95 border border-line-card rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold text-ink-card mb-1">{{ td('profile.account') }}</h3>
        <p class="text-xs text-ink-card-subtle mb-3">{{ td('profile.signout_help') }}</p>
        <button
          type="button"
          :disabled="loggingOut"
          @click="handleLogout"
          class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-line-card bg-surface-danger text-ink-danger font-bold text-sm hover:bg-rose-100 disabled:opacity-50"
        >
          <Icon name="arrow_right" :size="18" />
          {{ loggingOut ? 'กำลังออกจากระบบ...' : 'ออกจากระบบ' }}
        </button>
      </div>

      <!-- Danger zone — account deletion request (MOB-30). Last on the page
           and visually apart, so nobody reaches it on the way to something
           else. What happens next is behind the ⓘ and repeated, in full, in
           the dialog itself before anything is sent. -->
      <div class="bg-surface-card/95 border border-rose-200 rounded-2xl p-5 shadow-sm">
        <div class="flex items-center gap-1 mb-3">
          <h3 class="text-sm font-bold text-ink-danger">{{ td('profile.delete_title', 'ขอลบบัญชี') }}</h3>
          <InfoPopover :label="td('profile.delete_title', 'ขอลบบัญชี')">
            {{
              td(
                'profile.delete_help',
                'คำขอจะถูกส่งไปให้ผู้ดูแลระบบของบริษัทพิจารณาและดำเนินการ เมื่อส่งแล้วคุณจะออกจากระบบทันที ประวัติค่าแนะนำและการเบิกเงินจะยังถูกเก็บไว้เป็นหลักฐานทางการเงิน',
              )
            }}
          </InfoPopover>
        </div>
        <button
          type="button"
          class="w-full min-h-[44px] flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-rose-200 bg-surface-card text-ink-danger font-bold text-sm hover:bg-surface-danger"
          @click="showDeletionDialog = true"
        >
          <Icon name="trash" :size="18" />
          {{ td('profile.delete_button', 'ขอลบบัญชี') }}
        </button>
      </div>
    </div>

    <!-- Inside <main> on purpose: a second root would make this view a
         multi-root fragment (see ClientsView's note on ConfirmDialog). -->
    <AccountDeletionDialog v-model:show="showDeletionDialog" @requested="onDeletionRequested" />
  </main>
</template>
