<script setup lang="ts">
/**
 * VerifyEmailView — lands here from the link inside
 * VerifyRegistrationEmailNotification (TASK-018).
 *
 * Route: /verify-email/:id/:hash?expires=...&signature=...
 *
 * The backend's `signed` route middleware validates the route name +
 * parameters (id, hash, expires, signature) — it does not care which
 * literal domain the human clicked (see
 * VerifyRegistrationEmailNotification's own comment for why the email
 * link points here, at the frontend, rather than straight at the
 * backend). This view's only job is to forward those exact same query
 * params to `GET /api/v1/register/verify-email/{id}/{hash}` and show
 * the result — no business logic lives here (CLAUDE.md Section 7).
 */
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'

const route = useRoute()
const { t } = useI18n()

type State = 'verifying' | 'success' | 'error'
const state = ref<State>('verifying')
const message = ref('')

onMounted(async () => {
  const id = route.params.id as string
  const hash = route.params.hash as string

  // Forward every query param exactly as received (expires, signature,
  // and anything else Laravel's temporarySignedRoute() ever adds) —
  // never reconstruct or guess the signature, it must match byte-for-byte.
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(route.query)) {
    if (typeof value === 'string') query.set(key, value)
  }

  try {
    const res = await api.get<{ message: string }>(`/register/verify-email/${id}/${hash}?${query.toString()}`)
    state.value = 'success'
    message.value = res.message
  } catch (e) {
    state.value = 'error'
    message.value =
      e instanceof ApiError && (e.status === 403 || e.status === 404)
        ? t(
            'verify_link_invalid',
            'ลิงก์ยืนยันนี้ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอลิงก์ยืนยันใหม่',
            'This verification link is invalid or has expired. Please request a new one.',
          )
        : t('verify_network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง', 'Could not reach the server. Please try again.')
  }
})
</script>

<template>
  <div
    class="min-h-screen w-full flex items-center justify-center p-4 sm:p-8 font-sans"
    style="background: linear-gradient(160deg, #eef0f2 0%, #dde1e6 45%, #cfd4da 100%);"
  >
    <div class="w-full max-w-xl rounded-[28px] bg-surface-card shadow-xl border border-line-card/80 overflow-hidden p-8 sm:p-12 text-center">
      <div class="flex items-center justify-center">
        <AppLogo mode="wordmark" :height="30" />
      </div>

      <div v-if="state === 'verifying'" class="mt-10 py-4">
        <div class="mx-auto w-14 h-14 rounded-full bg-surface-chip border border-line-card-subtle flex items-center justify-center animate-pulse">
          <Icon name="mail" :size="24" class="text-ink-card-subtle" />
        </div>
        <p class="mt-4 text-sm text-ink-card-muted">
          {{ t('verify_checking', 'กำลังยืนยันอีเมลของคุณ...', 'Verifying your email...') }}
        </p>
      </div>

      <div v-else-if="state === 'success'" class="mt-10 py-4">
        <div class="mx-auto w-14 h-14 rounded-full bg-surface-success border border-emerald-100 flex items-center justify-center">
          <Icon name="shield_check" :size="24" class="text-ink-success" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">
          {{ t('verify_success_title', 'ยืนยันอีเมลสำเร็จ', 'Email verified') }}
        </h2>
        <p class="mt-2 text-sm text-ink-card-muted leading-relaxed">{{ message }}</p>
        <RouterLink
          :to="{ name: 'login' }"
          class="mt-6 inline-flex items-center justify-center gap-2 py-2.5 px-6 rounded-full bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors"
        >
          {{ t('reg_back_to_login', 'กลับไปหน้าเข้าสู่ระบบ', 'Back to sign in') }}
        </RouterLink>
      </div>

      <div v-else class="mt-10 py-4">
        <div class="mx-auto w-14 h-14 rounded-full bg-surface-danger border border-rose-100 flex items-center justify-center">
          <Icon name="alert" :size="24" class="text-ink-danger" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">
          {{ t('verify_error_title', 'ยืนยันอีเมลไม่สำเร็จ', 'Verification failed') }}
        </h2>
        <p class="mt-2 text-sm text-ink-card-muted leading-relaxed">{{ message }}</p>
        <RouterLink
          :to="{ name: 'register' }"
          class="mt-6 inline-flex items-center justify-center gap-2 py-2.5 px-6 rounded-full bg-surface-chip text-ink-card text-sm font-bold hover:bg-slate-200 transition-colors"
        >
          {{ t('verify_back_to_register', 'กลับไปหน้าสมัครสมาชิก', 'Back to registration') }}
        </RouterLink>
      </div>
    </div>
  </div>
</template>
