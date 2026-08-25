<script setup lang="ts">
/**
 * LoginView — Sanctum SPA session login.
 *
 * Visual treatment follows docs/design/CI-001-neural-atlas-reference.md:
 * cool gray gradient background, one large rounded-2xl card, editorial
 * headline (light italic + bold upright in one line) — the treatment the
 * ADR explicitly reserved for marketing-style screens like this one.
 * Brand (navy) stays the only accent here; gold is intentionally NOT
 * used (reserved for gamification/success moments elsewhere in the
 * app). [Originally indigo/lime — see CI-002 addendum below.]
 *
 * CI-001 addendum (2026-07-08, human-reviewed against the live reference
 * shot): the reference's near-black is confined to small pills/nav on a
 * mostly light-gray canvas, not a full dark panel — so the brand panel
 * here is narrower + one step lighter than the first pass, and the
 * full-panel dot-grid texture was removed (reference confines that motif
 * to the small logo mark only — "used sparingly" per our own decision).
 * The italic-light+bold-upright pairing stays for Latin/English copy but
 * is NOT applied to Thai script (Kanit italic has no native Thai
 * legibility precedent) — Thai headline uses weight contrast only.
 *
 * CI-001 addendum 2 (2026-07-08, human-reviewed against the actual
 * marketing-hero screenshot): the reference has NO dark panel at all —
 * it's a single light-gray canvas with near-black *pill* (rounded-full)
 * nav/CTA buttons and outline pill tags. Rebuilt as a single-column
 * light layout to match. Human-approved scope: rounded-full is adopted
 * **only on this screen** (submit button, lang toggle, tag pills,
 * password-visibility toggle) — CI-001 Decision #2 (keep rounded-xl
 * everywhere else — TopNavigation, HeroHeader, TabFilterBar, etc.)
 * still stands; do not spread rounded-full to shared components without
 * a separate sign-off.
 *
 * CI-002 (2026-07-08): brand/gold colors sampled from the GENESENN
 * co-brand logo replace indigo/lime **project-wide**, not just here —
 * see docs/design/CI-002-genesenn-brand.md and tailwind.config.js.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore, ApiError } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useI18n } from '@/composables/useI18n'
import { api, ensureCsrfCookie } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const theme = useThemeStore()

/**
 * The company this login page belongs to, or null.
 *
 * Read from the theme the store resolved at boot (`?company=<slug>`, or the
 * slug cached from a previous visit) — the same source the colours, logo and
 * font on this card already come from, so the name cannot disagree with the
 * branding around it.
 *
 * Null is a real answer, not a missing one: somebody who opened a bare
 * /login with nothing cached has not told us which company they belong to,
 * and the honest response is to show no company at all.
 */
const companyName = computed(() => theme.theme?.company?.name ?? null)
const { lang, t, setLang } = useI18n()

// TASK-055 / ADR-018 — per-company app-name override for the wordmark
// (benefits from the pre-login public theme load). `t` here is the TH/EN
// i18n helper; the brand label override comes from the theme store.
// TASK-121 — resolved inside AppLogo.vue now (see the note in App.vue).

// TASK-065 (human-reported 2026-07-31 QA, follow-up to TASK-063) — this
// page previously hardcoded its backdrop gradient and card background,
// so only the submit button (bg-brand-600, driven by primary_hex) ever
// varied per company; the branded /login?company=<slug> link from
// TASK-063 was pointless beyond the accent colour. Reuses the SAME
// resolvers App.vue already uses for the authenticated shell
// (companyBackgroundStyle) so this isn't a new theming mechanism — same
// "falls back to the neutral CI-001 gradient when the company hasn't
// set one" behaviour as everywhere else (BR-7: never hardcode, but a
// company with no background config keeps looking like today).
const defaultPageBackground = {
  background: 'linear-gradient(160deg, #eef0f2 0%, #dde1e6 45%, #cfd4da 100%)',
}
const pageBackgroundStyle = computed(() => {
  const company = theme.companyBackgroundStyle
  return Object.keys(company).length ? company : defaultPageBackground
})

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const remember = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function toggleLang() {
  setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

// Backend messages come back in English (lang/en/auth.php — no
// Accept-Language negotiation wired up yet, see lang/th/auth.php notes).
// Map the two known shapes to localized copy rather than show raw API
// text to a Thai-reading user; anything unrecognized still falls back
// to the raw message so nothing is silently swallowed.
function localizeAuthError(raw: string): string {
  if (lang.value !== 'TH') return raw
  if (raw.includes('do not match')) return 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
  if (raw.includes('Too many login attempts')) return 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณาลองใหม่อีกครั้งในภายหลัง'
  return raw
}

const emailError = computed(() => fieldErrors.value.email?.[0])

/**
 * TASK-115 / TASK-116 point 6 — THE LOGIN GATE'S 403.
 *
 * The credentials were CORRECT; what failed is authorization (see
 * LoginBlockedException — 403, not 422, precisely so this does not render as
 * a field error on the password box). Treating it as a generic error would
 * tell someone "เข้าสู่ระบบไม่สำเร็จ" when their password was right, and
 * would hide the one thing they can act on.
 *
 * Every one of the five keys is always present in the body (the exception's
 * own documented contract), so nothing below needs to null-guard per branch.
 * `can_resend_verification` / `can_reapply` are the SERVER's decisions about
 * what is offered — this view renders them, it never derives them from
 * `error_code` itself, so the two can never disagree.
 */
type LoginBlockCode = 'email_unverified' | 'approval_pending' | 'approval_rejected'

interface LoginBlockedBody {
  message: string
  error_code: LoginBlockCode
  can_resend_verification: boolean
  can_reapply: boolean
  rejection_reason: string | null
}

const blocked = ref<LoginBlockedBody | null>(null)

function isLoginBlockedBody(body: unknown): body is LoginBlockedBody {
  if (typeof body !== 'object' || body === null) return false
  const code = (body as { error_code?: unknown }).error_code
  return code === 'email_unverified' || code === 'approval_pending' || code === 'approval_rejected'
}

// Resend-verification state. The endpoint ALWAYS answers 200 with the same
// neutral message whether or not the address exists (it is unauthenticated,
// so any other behaviour would make it a membership oracle), which is why
// there is no error branch to render here — only "sent" and "sending".
const resending = ref(false)
const resendMessage = ref('')

async function resendVerification() {
  if (resending.value || !blocked.value?.can_resend_verification) return
  resending.value = true
  resendMessage.value = ''
  try {
    await ensureCsrfCookie()
    const res = await api.post<{ message: string }>('/register/resend-verification-email', {
      email: email.value,
    })
    // The backend's own conditional wording ("หากอีเมลนี้อยู่ในระบบ...") is
    // honest in both branches — safer to show it verbatim than to claim a
    // send we cannot actually confirm happened.
    resendMessage.value = res.message
  } catch {
    // Only a transport failure or the 5/min throttle can land here.
    resendMessage.value = t(
      'login_resend_failed',
      'ส่งอีเมลยืนยันไม่สำเร็จ กรุณารอสักครู่แล้วลองใหม่อีกครั้ง',
      'Could not send the verification email. Please wait a moment and try again.',
    )
  } finally {
    resending.value = false
  }
}

async function handleSubmit() {
  if (submitting.value) return
  errorMessage.value = ''
  fieldErrors.value = {}
  blocked.value = null
  resendMessage.value = ''
  submitting.value = true

  try {
    await authStore.login(email.value, password.value, remember.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    router.push(redirect)
  } catch (err) {
    if (err instanceof ApiError && err.status === 403 && isLoginBlockedBody(err.body)) {
      blocked.value = err.body
    } else if (err instanceof ApiError && err.status === 422) {
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
    :style="pageBackgroundStyle"
  >
    <!-- TASK-065 — bg-surface-card/95 (not bg-surface-card) so this card follows the
         company's card_bg_hex/card_text_hex the same way every other
         content card in the app does (main.css's global `.bg-surface-card\/95`
         + `.has-card-text` rules, set by theme.ts's apply() at boot).
         Companies that never set a card colour keep today's pure-white
         card (--card-bg defaults to 255 255 255). -->
    <div class="w-full max-w-xl rounded-[28px] bg-surface-card/95 shadow-xl border border-line-card/80 overflow-hidden p-8 sm:p-12">
      <!-- Top row: logo pill + lang toggle pill, echoing the reference's
           nav-pill row (no dark panel — see CI-001 addendum 2 above) -->
      <div class="flex items-center justify-between">
        <!-- TASK-121 — AppLogo resolves theme.loginLogo itself now. -->
        <AppLogo mode="wordmark" :height="30" />

        <button
          type="button"
          @click="toggleLang"
          class="relative w-14 h-7 shrink-0 bg-surface-chip rounded-full border border-line-card flex items-center px-1"
        >
          <div
            class="absolute top-1 bottom-1 w-6 bg-surface-card rounded-full shadow flex items-center justify-center transition-all duration-300"
            :class="lang === 'TH' ? 'translate-x-0' : 'translate-x-7'"
          >
            <span class="text-[9px] font-black text-ink-brand">{{ lang }}</span>
          </div>
        </button>
      </div>

      <!-- Outline tag pills, echoing the reference's "Smart glasses / NeuroAtlas" tags -->
      <div class="mt-8 flex items-center gap-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full border border-line-card text-xs font-bold text-ink-card-muted">
          {{ t('login_tag_portal', 'พอร์ทัลตัวแทน', 'Agent Portal') }}
        </span>
        <!-- TASK-055 / ADR-018 — the COMPANY's own name, from the theme this
             page already loaded, never a hardcoded one.

             This used to read "Thai Life" literally, which meant every other
             tenant's agents signed in to a page naming somebody else's
             company — on the one screen that is supposed to establish they
             are in the right place.

             `v-if` rather than a fallback string: when no slug resolved
             (a bare /login with nothing cached) there is no truthful company
             to name, and the pill is simply absent. Inventing a platform
             name here would put a brand on a white-label page. -->
        <span
          v-if="companyName"
          class="inline-flex items-center px-3 py-1 rounded-full border border-line-card text-xs font-bold text-ink-card-muted"
        >
          {{ companyName }}
        </span>
      </div>

      <div class="mt-4">
        <h1 class="text-3xl sm:text-4xl leading-tight text-ink-card">
          <!-- Italic reserved for Latin script (see CI-001 addendum) — Thai
               keeps the light/bold weight contrast without a slant. -->
          <span class="font-light text-ink-card-muted" :class="lang === 'EN' ? 'italic' : ''">{{ t('login_hello', 'ยินดีต้อนรับ', 'Welcome') }}</span>
          <span class="font-bold"> {{ t('login_back', 'กลับมา', 'back') }}</span>
        </h1>
        <p class="mt-2 text-sm text-ink-card-muted">
          {{ t('login_sub', 'เข้าสู่ระบบเพื่อดำเนินการต่อ', 'Sign in to continue') }}
        </p>
      </div>

      <form class="mt-8 space-y-4" @submit.prevent="handleSubmit" novalidate>
          <!-- ══ TASK-116 / TASK-115 — the three login-blocked states ══════
               A 403 is NOT the generic error banner below: the password was
               right, so the copy must say what is actually holding the
               account and what (if anything) the person can do next. The
               three states are deliberately distinguishable from each other
               — the only audience that can ever reach one is the account's
               own owner, since the gate runs after credentials verify, so
               collapsing them would help no attacker and would leave a real
               agent unable to tell "verify your email" from "wait" from
               "you were declined". -->
          <div
            v-if="blocked?.error_code === 'email_unverified'"
            class="rounded-xl bg-surface-warning border border-amber-100 px-3 py-3 text-sm text-ink-warning"
          >
            <div class="flex items-start gap-2">
              <Icon name="mail" :size="16" class="mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="font-bold">{{ t('login_blocked_unverified', 'ยังไม่ได้ยืนยันอีเมล', 'Email not verified yet') }}</p>
                <p class="mt-1 leading-relaxed">{{ blocked.message }}</p>
              </div>
            </div>

            <!-- The ONE actionable state. The button appears only because
                 the SERVER said `can_resend_verification` — never because
                 this view inferred it from the error code. -->
            <button
              v-if="blocked.can_resend_verification"
              type="button"
              :disabled="resending"
              class="mt-3 w-full min-h-[44px] rounded-xl bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-all active:scale-95 disabled:opacity-60 disabled:pointer-events-none inline-flex items-center justify-center gap-1.5"
              @click="resendVerification"
            >
              <Icon v-if="!resending" name="refresh" :size="16" />
              {{ resending
                ? t('login_resending', 'กำลังส่ง...', 'Sending...')
                : t('login_resend', 'ส่งอีเมลยืนยันอีกครั้ง', 'Resend verification email') }}
            </button>
            <p v-if="resendMessage" class="mt-2 text-xs leading-relaxed">{{ resendMessage }}</p>
          </div>

          <div
            v-else-if="blocked?.error_code === 'approval_pending'"
            class="flex items-start gap-2 rounded-xl bg-surface-warning border border-amber-100 px-3 py-3 text-sm text-ink-warning"
          >
            <Icon name="clock" :size="16" class="mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="font-bold">{{ t('login_blocked_pending', 'บัญชีของคุณรอการอนุมัติ', 'Your account is awaiting approval') }}</p>
              <p class="mt-1 leading-relaxed">{{ blocked.message }}</p>
              <!-- Informational only, by design (TASK-021): there is no
                   action a pending applicant can take, so offering one would
                   be a lie. Told what to expect instead of left guessing. -->
              <p class="mt-1 leading-relaxed">
                {{ t(
                  'login_blocked_pending_hint',
                  'หัวหน้าทีมหรือผู้ดูแลระบบของบริษัทจะเป็นผู้อนุมัติ เมื่ออนุมัติแล้วคุณจะเข้าสู่ระบบได้ทันที',
                  'Your team leader or a company administrator will approve it. You can sign in as soon as they do.',
                ) }}
              </p>
            </div>
          </div>

          <div
            v-else-if="blocked?.error_code === 'approval_rejected'"
            class="rounded-xl bg-surface-danger border border-rose-100 px-3 py-3 text-sm text-ink-danger"
          >
            <div class="flex items-start gap-2">
              <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="font-bold">{{ t('login_blocked_rejected', 'บัญชีนี้ไม่ได้รับการอนุมัติ', 'This account was not approved') }}</p>
                <p class="mt-1 leading-relaxed">{{ blocked.message }}</p>
                <!-- The admin's own words, echoed back to the person they
                     are about. Only ever populated on this branch. -->
                <p v-if="blocked.rejection_reason" class="mt-2 leading-relaxed">
                  <span class="font-bold">{{ t('login_rejection_reason', 'เหตุผล:', 'Reason:') }}</span>
                  {{ blocked.rejection_reason }}
                </p>
              </div>
            </div>

            <!-- ADR-005 decision 7 — a rejection is never terminal, so this
                 must never read as one. Shown only when the server says
                 `can_reapply`. -->
            <RouterLink
              v-if="blocked.can_reapply"
              :to="{ name: 'register' }"
              class="mt-3 w-full min-h-[44px] rounded-xl bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-all active:scale-95 inline-flex items-center justify-center gap-1.5"
            >
              {{ t('login_reapply', 'สมัครใหม่', 'Apply again') }}
              <Icon name="arrow_right" :size="16" />
            </RouterLink>
          </div>

          <div
            v-if="errorMessage"
            class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2.5 text-sm text-ink-danger"
          >
            <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
            <span>{{ errorMessage }}</span>
          </div>

          <div>
            <label for="email" class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('login_email', 'อีเมล', 'Email') }}
            </label>
            <div class="relative">
              <Icon name="mail" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
              <!-- The placeholder is GENERIC on purpose. This is a white-label
                   portal: every company reaches this page under their own
                   brand, so the previous "agent@thailife.test" showed one
                   tenant's name — and a test account at that — to every other
                   company's agents. `name@example.com` is already the
                   convention in ProductShareView and ShareLinkModal. -->
              <input
                id="email"
                v-model="email"
                type="email"
                autocomplete="username"
                required
                class="bg-surface-input w-full pl-9 pr-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
                :class="emailError ? 'border-rose-300' : 'border-line-input'"
                placeholder="name@example.com"
              />
            </div>
          </div>

          <div>
            <label for="password" class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('login_password', 'รหัสผ่าน', 'Password') }}
            </label>
            <div class="relative">
              <Icon name="key" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
              <input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                required
                class="bg-surface-input w-full pl-9 pr-10 py-2.5 rounded-xl border border-line-input text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
                placeholder="••••••••"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute right-1.5 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center rounded-full text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card-muted transition-colors"
                :title="showPassword ? t('hide', 'ซ่อน', 'Hide') : t('show', 'แสดง', 'Show')"
              >
                <Icon :name="showPassword ? 'eye_off' : 'eye'" :size="16" />
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-ink-card-muted cursor-pointer select-none">
              <input v-model="remember" type="checkbox" class="rounded border-line-card text-ink-brand focus:ring-brand-500/30" />
              {{ t('login_remember', 'จดจำฉัน', 'Remember me') }}
            </label>
            <!-- TODO: CONFIRM (product) — password reset flow not specced yet; out of scope for this task. -->
            <span class="text-ink-card-subtle cursor-not-allowed" :title="t('login_forgot_todo', 'ยังไม่เปิดใช้งาน', 'Not available yet')">
              {{ t('login_forgot', 'ลืมรหัสผ่าน?', 'Forgot password?') }}
            </span>
          </div>

          <button
            type="submit"
            :disabled="submitting"
            class="w-full py-2.5 rounded-full bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
          >
            <span>{{ submitting ? t('login_submitting', 'กำลังเข้าสู่ระบบ...', 'Signing in...') : t('login_submit', 'เข้าสู่ระบบ', 'Sign in') }}</span>
            <Icon v-if="!submitting" name="arrow_right" :size="16" />
          </button>
        </form>
      </div>
  </div>
</template>
