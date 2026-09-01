<script setup lang="ts">
/**
 * AffiliateLeadCaptureView — public, unauthenticated landing page a
 * prospect reaches after clicking an Agent's affiliate link (ADR-011
 * Section 4 / TASK-033). Route: /l/:token (meta.public — see
 * router/index.ts; App.vue hides TopNavigation for any meta.public
 * route, so this renders full-bleed with no app chrome automatically,
 * same as LoginView/RegisterView).
 *
 * Two API calls, both against the TASK-032/033 public routes only:
 *   - GET  /public/affiliate-leads/{token}  — on mount, link context
 *     (TASK-033 gap-fill; see AffiliateLeadCaptureController::show()).
 *     A 404 here (bad/expired token) shows a dead-link state instead
 *     of a form nobody could ever submit.
 *   - POST /public/affiliate-leads/{token}  — on submit.
 * This page never calls an authenticated endpoint itself. The router's
 * global beforeEach guard does call authStore.fetchUser() once per page
 * load regardless of route — that is pre-existing behavior already
 * applied to /login and /register (see router/index.ts), not something
 * introduced here, and it fails harmlessly (401 -> user stays null)
 * without blocking render — ag-lead judgment call not to special-case
 * it away for this route.
 *
 * Client-side validation mirrors (does not replace) the backend's
 * StoreAffiliateLeadRequest — required-field + focus-on-invalid pattern
 * reused from RegisterView.vue verbatim.
 *
 * Honeypot (hp_field): visually hidden off-screen, never `display:none`
 * (some crawlers skip those but still fill merely-offscreen fields) —
 * real visitors never see or fill it; a filled value makes the backend
 * silently no-op while still returning the same success response
 * (AffiliateLeadCaptureController::store()), so this field must NEVER
 * gain a visible label, placeholder, or validation error.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
// TASK-159 §4.2 — /l/{token} carries no company slug, so boot's
// loadPublic() bails at resolveSlug(). The theme now rides along on the
// link-context payload instead; see the `theme` field on LinkContext.
import { useThemeStore, type Theme } from '@/stores/theme'

const themeStore = useThemeStore()

const { lang, t, td, setLang } = useI18n()
function toggleLang() {
  setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

const route = useRoute()
const token = route.params.token as string

interface ProductOption {
  id: number
  name: string
  price_satang: number
}
interface LinkContext {
  company_name: string | null
  // TASK-159 §3 — the link owner's theme, same shape as
  // GET /public/theme/{slug}.
  theme: Theme | null
  agent_name: string | null
  product: ProductOption | null
  products: ProductOption[] | null
}

type PageState = 'loading' | 'ready' | 'not_found' | 'error'
const pageState = ref<PageState>('loading')
const context = ref<LinkContext | null>(null)

async function loadContext() {
  pageState.value = 'loading'
  try {
    const res = await api.get<{ data: LinkContext }>(`/public/affiliate-leads/${token}`)
    // TASK-159 §4.2 — theme FIRST, then reveal. The branded card (logo,
    // card colour, font) only renders once `pageState !== 'loading'`, so
    // a prospect arriving from social media never sees platform slate
    // flip into the company's brand. Ordering, not timing.
    themeStore.applyResolved(res.data.theme)
    context.value = res.data
    pageState.value = 'ready'
  } catch (e) {
    pageState.value = e instanceof ApiError && e.status === 404 ? 'not_found' : 'error'
  }
}
onMounted(loadContext)

function formatSatang(satang: number): string {
  return (satang / 100).toLocaleString('th-TH') + ' บาท'
}

const form = ref({
  name: '',
  phone: '',
  email: '',
  branch: '',
  preferred_time: '',
  product_id: '',
  consent: false,
  hp_field: '', // honeypot — see file header
})

const nameInputEl = ref<HTMLInputElement | null>(null)
const phoneInputEl = ref<HTMLInputElement | null>(null)
const branchInputEl = ref<HTMLInputElement | null>(null)
const productSelectEl = ref<HTMLSelectElement | null>(null)
const consentInputEl = ref<HTMLInputElement | null>(null)

const nameError = ref('')
const phoneError = ref('')
const branchError = ref('')
const productError = ref('')
const consentError = ref('')

function clearFieldErrors() {
  nameError.value = ''
  phoneError.value = ''
  branchError.value = ''
  productError.value = ''
  consentError.value = ''
}

// A link with no fixed product needs the prospect to pick one from the
// company's active catalog (see PublicAffiliateLinkContextResource).
const needsProductChoice = computed(() => !context.value?.product && !!context.value?.products?.length)

function validateForm(): boolean {
  clearFieldErrors()
  if (!form.value.name.trim()) {
    nameError.value = t('lead_name_required', 'กรุณากรอกชื่อ', 'Name is required')
    nameInputEl.value?.focus()
    return false
  }
  if (!form.value.phone.trim()) {
    phoneError.value = t('lead_phone_required', 'กรุณากรอกเบอร์โทร', 'Phone is required')
    phoneInputEl.value?.focus()
    return false
  }
  if (!form.value.branch.trim()) {
    branchError.value = t('lead_branch_required', 'กรุณากรอกสาขาที่สะดวก', 'Branch is required')
    branchInputEl.value?.focus()
    return false
  }
  if (needsProductChoice.value && !form.value.product_id) {
    productError.value = t('lead_product_required', 'กรุณาเลือกแพ็กเกจ', 'Please select a package')
    productSelectEl.value?.focus()
    return false
  }
  if (!form.value.consent) {
    consentError.value = t('lead_consent_required', 'กรุณายินยอมให้เก็บข้อมูลก่อนส่งแบบฟอร์ม', 'Please consent to data collection before submitting')
    consentInputEl.value?.focus()
    return false
  }
  return true
}

const submitting = ref(false)
const submitError = ref('')
const submitted = ref(false)

async function submitLead() {
  if (submitting.value) return
  if (!validateForm()) return
  submitError.value = ''
  submitting.value = true
  try {
    await api.post(`/public/affiliate-leads/${token}`, {
      name: form.value.name,
      phone: form.value.phone,
      email: form.value.email || undefined,
      branch: form.value.branch,
      preferred_time: form.value.preferred_time || undefined,
      product_id: context.value?.product?.id ?? (form.value.product_id ? Number(form.value.product_id) : undefined),
      consent: form.value.consent,
      hp_field: form.value.hp_field || undefined,
    })
    submitted.value = true
  } catch (e) {
    submitError.value =
      e instanceof ApiError
        ? t('lead_submit_error', 'ไม่สามารถดำเนินการได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง', 'Unable to process this request right now. Please try again.')
        : t('lead_network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง', 'Could not reach the server. Please try again.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <!-- TASK-159 §4.1/§4.2 — the page surface was a hardcoded neutral
       gradient. It is now the `surface-app` token (derived from the
       company's background, falling back to its CARD colour) with the
       company's own image/gradient layered on top when configured — the
       same two-layer model App.vue uses. Full-bleed; not the phone shell. -->
  <div
    class="min-h-screen w-full flex items-center justify-center p-4 sm:p-8 font-sans bg-surface-app"
    :style="themeStore.companyBackgroundStyle"
  >
    <!-- Loading sits OUTSIDE the card, deliberately (TASK-159 §4.2): the
         card carries the company's logo, card colour and font, so
         painting it before the theme resolves is exactly the flash this
         task exists to remove. -->
    <p v-if="pageState === 'loading'" class="text-sm text-ink-app-muted">
      {{ t('lead_loading', 'กำลังโหลด...', 'Loading...') }}
    </p>

    <div v-else class="w-full max-w-xl rounded-[28px] bg-surface-card shadow-xl border border-line-card/80 overflow-hidden p-8 sm:p-12">
      <div class="flex items-center justify-between">
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

      <!-- Invalid / expired link -->
      <div v-if="pageState === 'not_found'" class="mt-10 py-6 text-center">
        <div class="mx-auto w-14 h-14 rounded-full border border-rose-100 flex items-center justify-center">
          <Icon name="alert" :size="24" class="text-ink-danger" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">
          {{ t('lead_not_found_title', 'ลิงก์นี้ไม่ถูกต้องหรือหมดอายุ', 'This link is invalid or no longer active') }}
        </h2>
        <p class="mt-2 text-sm text-ink-card-muted">
          {{ t('lead_not_found_body', 'กรุณาติดต่อสมาชิกผู้แนะนำของคุณเพื่อขอลิงก์ใหม่', 'Please contact your member for a new link') }}
        </p>
      </div>

      <!-- Network/server error loading context -->
      <div v-else-if="pageState === 'error'" class="mt-10 py-6 text-center">
        <p class="text-sm text-ink-danger">
          {{ t('lead_network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง', 'Could not reach the server. Please try again.') }}
        </p>
        <button @click="loadContext" class="mt-3 text-sm font-bold text-ink-brand hover:underline">
          {{ t('lead_retry', 'ลองอีกครั้ง', 'Retry') }}
        </button>
      </div>

      <!-- Success -->
      <div v-else-if="submitted" class="mt-8 text-center py-4">
        <div class="mx-auto w-14 h-14 rounded-full border border-emerald-100 flex items-center justify-center">
          <Icon name="check" :size="24" class="text-ink-success" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card">
          {{ t('lead_done_title', 'ขอบคุณสำหรับข้อมูล', 'Thank you') }}
        </h2>
        <p class="mt-2 text-sm text-ink-card-muted leading-relaxed">
          {{ t('lead_done_body', 'เราจะติดต่อกลับโดยเร็วที่สุด', 'We will be in touch shortly.') }}
        </p>
      </div>

      <!-- Form -->
      <form v-else class="mt-6" novalidate @submit.prevent="submitLead">
        <div class="mb-6">
          <span class="inline-flex items-center px-3 py-1 rounded-full border border-line-card text-xs font-bold text-ink-card-muted">
            {{ context?.company_name }}
          </span>
          <h1 class="mt-4 text-2xl sm:text-3xl leading-tight text-ink-card">
            <span class="font-light text-ink-card-muted">{{ t('lead_hello', 'สนใจ', 'Interested in') }}</span>
            <span class="font-bold"> {{ context?.product?.name ?? t('lead_our_packages', 'แพ็กเกจของเรา', 'our packages') }}</span>
            <span v-if="context?.agent_name" class="block text-sm font-normal text-ink-card-subtle mt-1">
              {{ t('lead_referred_by', 'แนะนำโดย', 'Referred by') }} {{ context.agent_name }}
            </span>
          </h1>
          <p v-if="context?.product" class="mt-2 text-sm text-ink-card-muted">{{ formatSatang(context.product.price_satang) }} / ปี</p>
        </div>

        <div v-if="submitError" class="mb-4 flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2.5 text-sm text-ink-danger">
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ submitError }}</span>
        </div>

        <div class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_name', 'ชื่อ-นามสกุล', 'Full name') }} <span class="text-ink-danger">*</span>
            </label>
            <input
              ref="nameInputEl"
              v-model="form.name"
              type="text"
              autocomplete="name"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="nameError ? 'border-rose-400' : 'border-line-input'"
              @input="nameError = ''"
            />
            <p v-if="nameError" class="text-xs text-ink-danger mt-1">{{ nameError }}</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_phone', 'เบอร์โทร', 'Phone') }} <span class="text-ink-danger">*</span>
            </label>
            <input
              ref="phoneInputEl"
              v-model="form.phone"
              type="tel"
              autocomplete="tel"
              placeholder="08xxxxxxxx"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="phoneError ? 'border-rose-400' : 'border-line-input'"
              @input="phoneError = ''"
            />
            <p v-if="phoneError" class="text-xs text-ink-danger mt-1">{{ phoneError }}</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_email', 'อีเมล (ไม่บังคับ)', 'Email (optional)') }}
            </label>
            <input
              v-model="form.email"
              type="email"
              autocomplete="email"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border border-line-input text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_branch', 'สาขาที่สะดวก', 'Preferred branch') }} <span class="text-ink-danger">*</span>
            </label>
            <input
              ref="branchInputEl"
              v-model="form.branch"
              type="text"
              :placeholder="td('ph.branch')"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="branchError ? 'border-rose-400' : 'border-line-input'"
              @input="branchError = ''"
            />
            <p v-if="branchError" class="text-xs text-ink-danger mt-1">{{ branchError }}</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_preferred_time', 'เวลาที่สะดวกนัด (ไม่บังคับ)', 'Preferred time (optional)') }}
            </label>
            <BuddhistDateInput v-model="form.preferred_time" type="datetime-local" />
          </div>

          <div v-if="needsProductChoice">
            <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('lead_package', 'เลือกแพ็กเกจ', 'Select a package') }} <span class="text-ink-danger">*</span>
            </label>
            <select
              ref="productSelectEl"
              v-model="form.product_id"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="productError ? 'border-rose-400' : 'border-line-input'"
              @change="productError = ''"
            >
              <option value="" disabled>{{ t('lead_package_placeholder', '— เลือกแพ็กเกจ —', '— Select a package —') }}</option>
              <option v-for="p in context?.products ?? []" :key="p.id" :value="p.id">{{ p.name }} ({{ formatSatang(p.price_satang) }})</option>
            </select>
            <p v-if="productError" class="text-xs text-ink-danger mt-1">{{ productError }}</p>
          </div>

          <!-- Honeypot — see file header comment. Off-screen, never display:none. -->
          <div style="position: absolute; left: -9999px; top: -9999px; opacity: 0; height: 0; width: 0; overflow: hidden;" aria-hidden="true">
            <label>Leave this field empty</label>
            <input v-model="form.hp_field" type="text" tabindex="-1" autocomplete="off" />
          </div>

          <div>
            <label class="flex items-start gap-2 cursor-pointer">
              <input
                ref="consentInputEl"
                v-model="form.consent"
                type="checkbox"
                class="mt-0.5 shrink-0"
                @change="consentError = ''"
              />
              <span class="text-xs text-ink-card-muted leading-relaxed">
                {{
                  t(
                    'lead_consent',
                    'ฉันยินยอมให้เก็บและใช้ข้อมูลนี้เพื่อการติดต่อกลับ ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล (PDPA)',
                    'I consent to my data being collected and used for follow-up contact, per Thailand\'s Personal Data Protection Act (PDPA)',
                  )
                }}
              </span>
            </label>
            <p v-if="consentError" class="text-xs text-ink-danger mt-1">{{ consentError }}</p>
          </div>
        </div>

        <button
          type="submit"
          :disabled="submitting"
          class="mt-6 w-full py-2.5 rounded-full bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
        >
          <span>{{ submitting ? t('lead_submitting', 'กำลังส่ง...', 'Submitting...') : t('lead_submit', 'ส่งข้อมูล', 'Submit') }}</span>
          <Icon v-if="!submitting" name="arrow_right" :size="16" />
        </button>
      </form>
    </div>
  </div>
</template>
