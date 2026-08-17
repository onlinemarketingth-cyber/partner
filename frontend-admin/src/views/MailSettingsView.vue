<script setup lang="ts">
/**
 * MailSettingsView (Admin app) — "ตั้งค่า Email SMTP" (TASK-190 §5).
 *
 * Super-Admin-only screen for the ONE platform-wide SMTP row
 * (`platform_mail_settings` — no company_id, see that Model's own
 * docblock referenced by PlatformMailSettingService). Backend
 * (Phase 1-2, ag-dev) is already live:
 *   GET/PUT /api/v1/platform/mail-settings
 *   gated by Ability::SettingsMailUpdate — Super Admin ONLY (a Company
 *   Admin gets 403; this is platform infrastructure, not tenant config,
 *   same reasoning as "จัดการบริษัท").
 *
 * Password handling (PlatformMailSettingService::get()/update()):
 * the real password is NEVER returned by GET — only `password_set`
 * (boolean). There is no reveal/unmask for this field (unlike bank
 * account numbers elsewhere in this app, TASK-044) — nobody needs to
 * see a fragment of an SMTP password, only whether one is configured.
 * PUT's `password` key is OPTIONAL: omit it entirely (not just send
 * empty string) to leave the stored password unchanged, so this form
 * only includes the key in its payload when the admin actually typed
 * something into the field. The eye-icon toggle here only flips the
 * <input> between type="password"/"text" for the value being TYPED —
 * it never fetches or reveals the already-stored secret, since the API
 * has nothing to reveal in the first place.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'

// Same per-file helper pattern as AnnouncementsView.vue's apiErrorMessage()
// (no shared export for this yet — each screen keeps its own copy). Prefers
// ApiError's already-extracted backend `message` (which for the test-email
// endpoint below is either the "enable + save first" guard text or the raw
// SMTP transport error — TASK-201 explicitly wants that surfaced verbatim,
// not genericized), falling back to `${fallback} (status)` only when the
// message is still the generic "API error N" placeholder.
function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

interface MailSettings {
  smtp_host: string | null
  smtp_port: number | null
  encryption: 'ssl' | 'tls' | 'none' | null
  username: string | null
  password_set: boolean
  from_address: string | null
  from_name: string | null
  is_enabled: boolean
}

const ENCRYPTION_OPTIONS: { value: 'ssl' | 'tls' | 'none'; label: string }[] = [
  { value: 'ssl', label: 'SSL' },
  { value: 'tls', label: 'TLS' },
  { value: 'none', label: 'ไม่เข้ารหัส (None)' },
]

const authStore = useAuthStore()

const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const saveError = ref('')
const saveSuccess = ref(false)
const passwordSet = ref(false)
const showPassword = ref(false)

// TASK-201 — "ทดสอบส่งอีเมล". Defaults to the logged-in Super Admin's own
// email, same auth-store access pattern AnnouncementsView.vue etc. already
// use to read the current user (see auth.ts AuthUser.email) — no separate
// "my email" lookup invented here.
const testEmail = ref(authStore.user?.email ?? '')
const testingMail = ref(false)
const testMessage = ref('')
const testMessageIsError = ref(false)

// Snapshot of the form exactly as last loaded/saved — compared against the
// live form below to know whether there are unsaved edits. The test
// endpoint always sends against the PERSISTED platform_mail_settings row
// (see MailSettingsService::applyRuntimeConfig(), read once per request at
// boot), so testing while the form is dirty would silently test config the
// admin hasn't actually saved yet — TASK-201 wants that impossible, not
// just discouraged.
const savedSnapshot = ref('')

interface MailForm {
  smtp_host: string
  smtp_port: number | ''
  encryption: 'ssl' | 'tls' | 'none'
  username: string
  password: string
  from_address: string
  from_name: string
  is_enabled: boolean
}

const form = ref<MailForm>({
  smtp_host: '',
  smtp_port: 465,
  encryption: 'ssl',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
  is_enabled: false,
})

const isDirty = computed(() => JSON.stringify(form.value) !== savedSnapshot.value)

function applySettings(s: MailSettings): void {
  form.value = {
    smtp_host: s.smtp_host ?? '',
    smtp_port: s.smtp_port ?? '',
    encryption: s.encryption ?? 'ssl',
    username: s.username ?? '',
    // Never pre-filled — see the file-level docblock: the API has no
    // real value to give this field, only password_set below.
    password: '',
    from_address: s.from_address ?? '',
    from_name: s.from_name ?? '',
    is_enabled: s.is_enabled,
  }
  passwordSet.value = s.password_set
  // Re-baseline the dirty-check snapshot: this is the moment the form
  // starts matching what the backend has persisted (a fresh load, or a
  // just-completed save that echoed the saved row back).
  savedSnapshot.value = JSON.stringify(form.value)
}

async function loadSettings(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get<{ data: MailSettings }>('/platform/mail-settings')
    applySettings(res.data)
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'โหลดการตั้งค่าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function saveSettings(): Promise<void> {
  saving.value = true
  saveError.value = ''
  saveSuccess.value = false
  try {
    const payload: Record<string, unknown> = {
      smtp_host: form.value.smtp_host,
      smtp_port: Number(form.value.smtp_port),
      encryption: form.value.encryption,
      username: form.value.username || null,
      from_address: form.value.from_address,
      from_name: form.value.from_name,
      is_enabled: form.value.is_enabled,
    }
    // Only send `password` when the admin actually typed something —
    // omitting the key entirely (not sending '') is what tells
    // PlatformMailSettingService::update() to keep the existing
    // (encrypted) password unchanged.
    if (form.value.password.trim() !== '') {
      payload.password = form.value.password
    }

    const res = await api.put<{ data: MailSettings }>('/platform/mail-settings', payload)
    applySettings(res.data)
    saveSuccess.value = true
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const errors = (e.body as { errors?: Record<string, string[]> } | undefined)?.errors
      saveError.value = errors ? Object.values(errors).flat().join(' ') : 'บันทึกไม่สำเร็จ'
    } else {
      saveError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    saving.value = false
  }
}

/**
 * TASK-201 — POST /platform/mail-settings/test. Always tests the PERSISTED
 * `platform_mail_settings` row (never the in-progress form — the Test
 * button is disabled via `isDirty` while they differ, see template), so
 * there is no unsaved-values payload to build here beyond the recipient.
 * `is_enabled` is deliberately NOT checked here — the backend is the
 * single source of truth for that gate (spec explicit instruction) and
 * returns a clear 422 either way.
 */
async function sendTestMail(): Promise<void> {
  testingMail.value = true
  testMessage.value = ''
  testMessageIsError.value = false
  try {
    await api.post<{ message: string }>('/platform/mail-settings/test', { to: testEmail.value })
    testMessage.value = `ส่งอีเมลทดสอบสำเร็จ ไปที่ ${testEmail.value} แล้ว — กรุณาตรวจสอบกล่องจดหมาย`
    testMessageIsError.value = false
  } catch (e) {
    // Real backend message shown verbatim (not genericized) — either the
    // "enable + save first" guard text or the raw SMTP transport error,
    // both of which are the actual diagnostic value of this feature.
    testMessage.value = apiErrorMessage(e, 'ทดสอบส่งอีเมลไม่สำเร็จ')
    testMessageIsError.value = true
  } finally {
    testingMail.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="mail"
      icon-color="text-brand-600"
      title="ตั้งค่า Email SMTP"
      subtitle="การตั้งค่าเซิร์ฟเวอร์ส่งอีเมลของแพลตฟอร์ม (Super Admin เท่านั้น)"
      accent-color="brand"
      storage-key="admin-mail-settings"
    />

    <div v-if="loading" class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-5 text-sm text-slate-400">
      กำลังโหลด...
    </div>
    <div v-else-if="loadError" class="mt-4 bg-white/95 border border-rose-200 rounded-2xl p-5 text-sm font-bold text-rose-600">
      {{ loadError }}
    </div>

    <form v-else class="mt-4 max-w-2xl bg-white/95 border border-slate-200 rounded-2xl p-5" @submit.prevent="saveSettings">
      <h2 class="text-sm font-bold text-slate-900 mb-4 flex items-center gap-2">
        <Icon name="mail" :size="16" class="text-brand-600" />
        การตั้งค่า SMTP Server
      </h2>

      <div class="space-y-3">
        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">SMTP Host</label>
          <input
            v-model="form.smtp_host"
            type="text"
            required
            placeholder="smtp.hostinger.com"
            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">Port</label>
            <input
              v-model.number="form.smtp_port"
              type="number"
              min="1"
              max="65535"
              required
              placeholder="465"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500 block mb-1">การเข้ารหัส (Encryption)</label>
            <select
              v-model="form.encryption"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-200"
            >
              <option v-for="opt in ENCRYPTION_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">Email / Username</label>
          <input
            v-model="form.username"
            type="text"
            placeholder="noreply@syncvision.io"
            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          />
        </div>

        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">รหัสผ่าน (Password)</label>
          <div class="relative">
            <input
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              placeholder="••••••••"
              class="w-full px-3 py-2 pr-10 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
            <button
              type="button"
              tabindex="-1"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              @click="showPassword = !showPassword"
            >
              <Icon :name="showPassword ? 'eye_off' : 'eye'" :size="16" />
            </button>
          </div>
          <p v-if="passwordSet" class="mt-1 text-xs text-slate-400">รหัสผ่านถูกตั้งไว้แล้ว (เว้นว่างไว้เพื่อไม่เปลี่ยน)</p>
          <p v-else class="mt-1 text-xs text-slate-400">ยังไม่ได้ตั้งรหัสผ่าน</p>
        </div>

        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">ชื่อผู้ส่ง (Sender Name)</label>
          <input
            v-model="form.from_name"
            type="text"
            required
            placeholder="SyncVision CRM"
            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          />
        </div>

        <div>
          <label class="text-xs font-bold text-slate-500 block mb-1">อีเมลผู้ส่ง (From Address)</label>
          <input
            v-model="form.from_address"
            type="email"
            required
            placeholder="noreply@syncvision.io"
            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          />
        </div>

        <div class="flex items-center justify-between gap-3 pt-1">
          <div>
            <p class="text-sm font-bold text-slate-600">เปิดใช้งานระบบส่งอีเมล</p>
            <p class="text-xs text-slate-400">ปิดไว้ = ไม่มีการส่งอีเมลใด ๆ ออกจากระบบ (ค่าเริ่มต้นปลอดภัยไว้ก่อน)</p>
          </div>
          <button
            type="button"
            @click="form.is_enabled = !form.is_enabled"
            class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
            :class="form.is_enabled ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
            :title="form.is_enabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน'"
          >
            <div
              class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
              :class="form.is_enabled ? 'translate-x-7' : 'translate-x-0'"
            ></div>
          </button>
        </div>

        <div class="pt-2 border-t border-slate-100">
          <label class="text-xs font-bold text-slate-500 block mb-1">ที่อยู่อีเมลสำหรับทดสอบ</label>
          <input
            v-model="testEmail"
            type="email"
            placeholder="you@example.com"
            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
          />
        </div>

        <div class="flex justify-end items-center gap-3 pt-1">
          <button type="submit" :disabled="saving" class="btn-primary">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
          </button>
          <button
            type="button"
            :disabled="testingMail || isDirty || !testEmail"
            class="btn-secondary"
            @click="sendTestMail"
          >
            {{ testingMail ? 'กำลังทดสอบ...' : 'ทดสอบส่งอีเมล' }}
          </button>
        </div>
        <p v-if="isDirty" class="text-right text-xs text-slate-400">บันทึกการตั้งค่าก่อน จึงจะทดสอบด้วยค่าล่าสุดได้</p>
        <p v-if="saveSuccess" class="text-xs font-bold text-emerald-600">บันทึกสำเร็จ</p>
        <p v-if="saveError" class="text-xs font-bold text-rose-600">{{ saveError }}</p>
        <p v-if="testMessage" class="text-xs font-bold" :class="testMessageIsError ? 'text-rose-600' : 'text-emerald-600'">{{ testMessage }}</p>
      </div>
    </form>
  </main>
</template>
