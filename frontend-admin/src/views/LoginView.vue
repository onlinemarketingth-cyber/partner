<script setup lang="ts">
/**
 * LoginView — Admin app session login.
 *
 * Same Sanctum SPA session mechanism as the Agent Portal's LoginView
 * (same backend, same cookie — ADR-003), same CI-001/CI-002 visual
 * language (single light canvas, brand navy pill CTA, editorial
 * headline for Latin script only). Copy says "Admin", not "Agent".
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore, ApiError } from '@/stores/auth'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { lang, t, setLang } = useI18n()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const remember = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

// router/index.ts's role guard logs an Agent account out and bounces
// them here with ?blocked=agent (human-confirmed 2026-07-16: this app
// is Company Admin/Super Admin only) — surfaced as an error state
// rather than a silent redirect.
//
// UAT-013 bug fix: this MUST be a watcher, not a one-time ref-init
// from route.query at setup(). The most common way ?blocked=agent is
// reached is an Agent submitting the login form while already sitting
// on the /login route (name 'login') — the guard's redirect resolves
// to the SAME route name ('login' -> 'login', only the query changes),
// so Vue Router reuses this component instance instead of remounting
// it. A ref initialized once at setup() never saw the update; the
// login form would silently clear back to blank with no explanation
// of why the Agent got bounced. `immediate: true` also covers the
// case of navigating here directly with the query already present.
watch(
  () => route.query.blocked,
  (blocked) => {
    if (blocked === 'agent') {
      errorMessage.value = t(
        'login_blocked_agent',
        'บัญชีตัวแทนขาย (Agent) ไม่สามารถเข้าใช้งานหน้าผู้ดูแลระบบนี้ได้ กรุณาใช้แอป Agent Portal แทน',
        'Agent accounts cannot access this admin console. Please use the Agent Portal app instead.',
      )
    }
  },
  { immediate: true },
)

function toggleLang() {
  setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

function localizeAuthError(raw: string): string {
  if (lang.value !== 'TH') return raw
  if (raw.includes('do not match')) return 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
  if (raw.includes('Too many login attempts')) return 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณาลองใหม่อีกครั้งในภายหลัง'
  return raw
}

const emailError = computed(() => fieldErrors.value.email?.[0])

async function handleSubmit() {
  if (submitting.value) return
  errorMessage.value = ''
  fieldErrors.value = {}
  submitting.value = true

  try {
    await authStore.login(email.value, password.value, remember.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    router.push(redirect)
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      const body = err.body as { errors?: Record<string, string[]> }
      fieldErrors.value = body.errors ?? {}
      const first = Object.values(fieldErrors.value)[0]?.[0]
      errorMessage.value = first ? localizeAuthError(first) : t('login_error_generic', 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่', 'Login failed. Please try again.')
    } else {
      errorMessage.value = t(
        'login_error_network',
        'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง',
        'Could not reach the server. Please try again.',
      )
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div
    class="min-h-screen w-full flex items-center justify-center p-4 sm:p-8 font-sans"
    style="background: linear-gradient(160deg, #eef0f2 0%, #dde1e6 45%, #cfd4da 100%);"
  >
    <div class="w-full max-w-xl rounded-[28px] bg-white shadow-xl border border-slate-200/80 overflow-hidden p-8 sm:p-12">
      <div class="flex items-center justify-between">
        <AppLogo mode="wordmark" :height="30" />

        <button
          type="button"
          @click="toggleLang"
          class="relative w-14 h-7 shrink-0 bg-slate-100 rounded-full border border-slate-200 flex items-center px-1"
        >
          <div
            class="absolute top-1 bottom-1 w-6 bg-white rounded-full shadow flex items-center justify-center transition-all duration-300"
            :class="lang === 'TH' ? 'translate-x-0' : 'translate-x-7'"
          >
            <span class="text-[9px] font-black text-brand-600">{{ lang }}</span>
          </div>
        </button>
      </div>

      <div class="mt-8 flex items-center gap-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-500">
          Admin Portal
        </span>
        <span class="inline-flex items-center px-3 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-500">
          Thai Life
        </span>
      </div>

      <div class="mt-4">
        <h1 class="text-3xl sm:text-4xl leading-tight text-slate-900">
          <span class="font-light text-slate-500" :class="lang === 'EN' ? 'italic' : ''">{{ t('login_hello', 'ยินดีต้อนรับ', 'Welcome') }}</span>
          <span class="font-bold"> {{ t('login_back', 'กลับมา', 'back') }}</span>
        </h1>
        <p class="mt-2 text-sm text-slate-500">
          {{ t('login_sub', 'เข้าสู่ระบบผู้ดูแลระบบเพื่อดำเนินการต่อ', 'Sign in to the admin console to continue') }}
        </p>
      </div>

      <form class="mt-8 space-y-4" @submit.prevent="handleSubmit" novalidate>
        <div
          v-if="errorMessage"
          class="flex items-start gap-2 rounded-xl bg-rose-50 border border-rose-100 px-3 py-2.5 text-sm text-rose-700"
        >
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ errorMessage }}</span>
        </div>

        <div>
          <label for="email" class="block text-xs font-bold text-slate-600 mb-1.5">
            {{ t('login_email', 'อีเมล', 'Email') }}
          </label>
          <div class="relative">
            <Icon name="mail" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              class="w-full pl-9 pr-3 py-2.5 rounded-xl border text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="emailError ? 'border-rose-300' : 'border-slate-200'"
              placeholder="admin@thailife.test"
            />
          </div>
        </div>

        <div>
          <label for="password" class="block text-xs font-bold text-slate-600 mb-1.5">
            {{ t('login_password', 'รหัสผ่าน', 'Password') }}
          </label>
          <div class="relative">
            <Icon name="key" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              id="password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              required
              class="w-full pl-9 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              placeholder="••••••••"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute right-1.5 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
              :title="showPassword ? t('hide', 'ซ่อน', 'Hide') : t('show', 'แสดง', 'Show')"
            >
              <Icon :name="showPassword ? 'eye_off' : 'eye'" :size="16" />
            </button>
          </div>
        </div>

        <div class="flex items-center justify-between text-sm">
          <label class="flex items-center gap-2 text-slate-600 cursor-pointer select-none">
            <input v-model="remember" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500/30" />
            {{ t('login_remember', 'จดจำฉัน', 'Remember me') }}
          </label>
          <span class="text-slate-300 cursor-not-allowed" :title="t('login_forgot_todo', 'ยังไม่เปิดใช้งาน', 'Not available yet')">
            {{ t('login_forgot', 'ลืมรหัสผ่าน?', 'Forgot password?') }}
          </span>
        </div>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full py-2.5 rounded-full bg-brand-600 text-white text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
        >
          <span>{{ submitting ? t('login_submitting', 'กำลังเข้าสู่ระบบ...', 'Signing in...') : t('login_submit', 'เข้าสู่ระบบ', 'Sign in') }}</span>
          <Icon v-if="!submitting" name="arrow_right" :size="16" />
        </button>
      </form>
    </div>
  </div>
</template>
