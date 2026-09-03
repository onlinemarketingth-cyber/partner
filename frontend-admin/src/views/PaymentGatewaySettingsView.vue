<script setup lang="ts">
/**
 * PaymentGatewaySettingsView (Admin app) — "ช่องทางรับชำระเงิน" (ADR-027 / TASK-139).
 *
 * Super-Admin-only, INCLUDING the read. Most settings screens in this app
 * read wider than they write; this one does not, because the list itself is
 * a map of where every tenant's revenue goes. Backend:
 *   GET  /companies/{id}/payment-gateways
 *   PUT  /companies/{id}/payment-gateways/{provider}
 *   POST /companies/{id}/payment-gateways/activate
 * all gated by Ability::SettingsPaymentGatewayUpdate.
 *
 * ── PER COMPANY, WHICH IS THE WHOLE POINT ──
 *
 * Unlike "ตั้งค่า Email SMTP" (one platform row), credentials here belong to
 * ONE company and its customers' money lands in THAT company's account.
 * ADR-027 §3 records a rejected draft that put a single OMISE_SECRET_KEY in
 * .env for the whole platform — every tenant's revenue into one account, with
 * nothing on any screen looking wrong. So this screen refuses to do anything
 * at all until a specific company is picked in the header, and shows whose
 * gateway is on screen at all times.
 *
 * ── SECRETS ARE NEVER LOADED BACK ──
 *
 * A field the backend marks `secret` arrives as `value: null, is_set: true`.
 * There is no reveal and nothing to reveal: the API has no way to return one.
 * A blank secret on save means "keep the stored one", which is what lets an
 * admin fix a public key or change mode without re-typing a secret key they
 * do not have to hand.
 *
 * ── SAVING IS VERIFYING ──
 *
 * The backend proves credentials against the provider's API as part of
 * saving, and does not store them when that fails. So there is no separate
 * "test connection" button here — there is no state in which credentials are
 * saved but unproven, and a button implying otherwise would invite one.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'

interface GatewayField {
  key: string
  label: string
  required: boolean
  secret: boolean
  help?: string
  /** null for every secret field — see the file docblock. */
  value: string | null
  is_set: boolean
}
interface Gateway {
  provider: string
  label: string
  requires_human_verification: boolean
  is_active: boolean
  is_live: boolean
  is_configured: boolean
  is_verified: boolean
  verified_at: string | null
  verified_note: string | null
  fields: GatewayField[]
}

const activeCompany = useActiveCompanyStore()

const loading = ref(false)
const loadError = ref('')
const gateways = ref<Gateway[]>([])

/** Per provider: what is typed in its form right now. */
const drafts = ref<Record<string, Record<string, string>>>({})
const liveMode = ref<Record<string, boolean>>({})
const savingProvider = ref<string | null>(null)
const activatingProvider = ref<string | null>(null)
const errorFor = ref<Record<string, string>>({})
const noticeFor = ref<Record<string, string>>({})

const basePath = computed(() => `/companies/${activeCompany.companyId}/payment-gateways`)

/**
 * The draft object for one provider, created on demand.
 *
 * `noUncheckedIndexedAccess` is on in this project, so a bare
 * `drafts[provider][key]` is `possibly undefined` — and it genuinely can be,
 * for the render that happens between a company switch and the response
 * arriving. Creating the object here rather than asserting it away means the
 * template binds to something real in that window instead of throwing.
 */
function draftFor(provider: string): Record<string, string> {
  const existing = drafts.value[provider]
  if (existing) return existing
  const created: Record<string, string> = {}
  drafts.value[provider] = created
  return created
}

function applyOverview(data: { active_provider: string | null; gateways: Gateway[] }): void {
  // `active_provider` on the payload is deliberately not stored: each
  // gateway row already carries its own `is_active`, and a second copy of
  // "which one is on" is a second thing that can disagree with the first.
  gateways.value = data.gateways

  for (const gateway of data.gateways) {
    const draft: Record<string, string> = {}
    for (const field of gateway.fields) {
      // Secrets come back null and stay blank. A non-secret (a public key)
      // is pre-filled, because it is visible in the pay page's HTML anyway
      // and hiding it from the one person who has to check it is wrong.
      draft[field.key] = field.secret ? '' : (field.value ?? '')
    }
    drafts.value[gateway.provider] = draft
    liveMode.value[gateway.provider] = gateway.is_live
  }
}

async function loadGateways(): Promise<void> {
  if (activeCompany.requiresCompanyPick) {
    gateways.value = []
    return
  }
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get<{ data: { active_provider: string | null; gateways: Gateway[] } }>(basePath.value)
    applyOverview(res.data)
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'โหลดการตั้งค่าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

onMounted(loadGateways)
// Re-load on a company switch. Without this the screen would keep showing
// the previous company's configuration under the new company's name — on a
// screen about where money goes, that is the worst possible stale render.
watch(() => activeCompany.companyId, loadGateways)

function messageFrom(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  const body = e.body as { message?: string; errors?: Record<string, string[]> } | undefined
  // The provider's own rejection ("Omise ปฏิเสธ secret key นี้", "ตั้งค่าเป็น
  // โหมด LIVE แต่ใส่ skey ของ TEST") arrives under `errors.credentials` and
  // is the only thing that tells an admin what to fix.
  return Object.values(body?.errors ?? {}).flat()[0] ?? body?.message ?? fallback
}

async function saveGateway(gateway: Gateway): Promise<void> {
  savingProvider.value = gateway.provider
  errorFor.value[gateway.provider] = ''
  noticeFor.value[gateway.provider] = ''
  try {
    const res = await api.put<{
      data: { active_provider: string | null; gateways: Gateway[] }
      message: string | null
    }>(`${basePath.value}/${gateway.provider}`, {
      credentials: draftFor(gateway.provider),
      is_live: liveMode.value[gateway.provider] ?? false,
    })
    applyOverview(res.data)
    // The verification note names the ACCOUNT that answered — a green tick
    // cannot tell an admin they just connected the wrong company's Omise,
    // and on this screen that is the mistake worth catching.
    noticeFor.value[gateway.provider] = res.message ?? 'บันทึกและตรวจสอบการเชื่อมต่อสำเร็จ'
  } catch (e) {
    errorFor.value[gateway.provider] = messageFrom(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingProvider.value = null
  }
}

async function activateGateway(gateway: Gateway): Promise<void> {
  activatingProvider.value = gateway.provider
  errorFor.value[gateway.provider] = ''
  noticeFor.value[gateway.provider] = ''
  try {
    const res = await api.post<{ data: { active_provider: string | null; gateways: Gateway[] } }>(
      `${basePath.value}/activate`,
      { provider: gateway.provider },
    )
    applyOverview(res.data)
    noticeFor.value[gateway.provider] = 'เปิดใช้งานช่องทางนี้แล้ว'
  } catch (e) {
    errorFor.value[gateway.provider] = messageFrom(e, 'เปิดใช้งานไม่สำเร็จ')
  } finally {
    activatingProvider.value = null
  }
}

/**
 * The webhook URL an admin pastes into the provider's dashboard.
 *
 * Shown rather than described, because a mistyped webhook URL produces no
 * error anywhere: the provider posts into nothing, the charge succeeds, and
 * orders simply never get confirmed. Built from the SAME company id the rest
 * of this screen uses, so it cannot name a different company than the
 * credentials above it.
 *
 * ── VITE_API_BASE_URL, NOT window.location.origin ──
 *
 * A bug caught before anyone pasted this anywhere. This page and the backend
 * are not on the same origin in EITHER environment:
 *
 *   local — page http://admin.localhost:5179 (Vite), API :8010
 *   prod  — page https://admin.partner.syncvision.io,
 *           API  https://admin.partner.syncvision.io/backend  (ADR-039)
 *
 * So `window.location.origin` produced a URL pointing at the dev server
 * locally and one missing the /backend mount in production. Both would have
 * looked completely plausible on screen and failed silently — which is the
 * exact failure mode this field exists to prevent. It is built from the one
 * value already proven to reach this backend: the base every other request
 * on this screen goes through.
 */
function webhookUrl(provider: string): string {
  const apiBase = (import.meta.env.VITE_API_BASE_URL as string).replace(/\/+$/, '')

  return `${apiBase}/api/v1/webhooks/payments/${provider}/${activeCompany.companyId}`
}

const copiedWebhook = ref('')
async function copyWebhook(provider: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(webhookUrl(provider))
    copiedWebhook.value = provider
    setTimeout(() => (copiedWebhook.value = ''), 1800)
  } catch {
    // Clipboard blocked — the URL is on screen to copy by hand.
  }
}

function formatVerifiedAt(value: string | null): string {
  if (!value) return ''
  return new Date(value).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="credit_card"
      icon-color="text-brand-600"
      title="ช่องทางรับชำระเงิน"
      subtitle="ตั้งค่าผู้ให้บริการรับชำระเงินของแต่ละบริษัท (Super Admin เท่านั้น)"
      accent-color="brand"
      storage-key="admin-payment-gateways"
    />

    <!-- Refuses to render anything until a specific company is chosen. On a
         screen about where a tenant's money lands, "ทุกบริษัท" is not a
         meaningful scope — it would mean editing nobody's credentials. -->
    <div
      v-if="activeCompany.requiresCompanyPick"
      class="mt-4 bg-white/95 border border-amber-200 rounded-2xl p-5 text-sm font-bold text-amber-700 flex items-center gap-2"
    >
      <Icon name="alert" :size="16" />
      กรุณาเลือกบริษัทที่ต้องการตั้งค่าจากแถบด้านบนก่อน
    </div>

    <template v-else>
      <div class="mt-4 flex items-center gap-2 text-sm text-slate-500">
        <Icon name="building" :size="16" class="text-brand-600" />
        กำลังตั้งค่าให้บริษัท
        <span class="font-bold text-slate-900">{{ activeCompany.companyName }}</span>
      </div>

      <div v-if="loading" class="mt-4 bg-white/95 border border-slate-200 rounded-2xl p-5 text-sm text-slate-400">
        กำลังโหลด...
      </div>
      <div v-else-if="loadError" class="mt-4 bg-white/95 border border-rose-200 rounded-2xl p-5 text-sm font-bold text-rose-600">
        {{ loadError }}
      </div>

      <div v-else class="mt-4 space-y-4 max-w-3xl">
        <div
          v-for="gateway in gateways"
          :key="gateway.provider"
          class="bg-white/95 border rounded-2xl p-5"
          :class="gateway.is_active ? 'border-brand-300 ring-1 ring-brand-100' : 'border-slate-200'"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                {{ gateway.label }}
                <span
                  v-if="gateway.is_active"
                  class="px-2 py-0.5 rounded-full bg-brand-600 text-white text-[11px] font-bold"
                >ใช้งานอยู่</span>
                <!-- A live/test badge, because a company collecting real
                     money through test keys looks identical everywhere else
                     in this product. -->
                <span
                  v-if="gateway.is_configured && !gateway.is_live"
                  class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold"
                >โหมดทดสอบ</span>
              </h2>
              <!--
                2026-09-03 (human request) — the caption that used to sit here
                said "ตั้งค่าพร้อมเพย์/บัญชีธนาคารที่หน้าข้อมูลบริษัท". There is no such
                page: the admin console has no field for payment_promptpay_id
                or the bank columns anywhere, even though the API accepts them
                and the public pay page reads them. Directions to a screen
                that does not exist are worse than no directions.
              -->
            </div>

            <button
              v-if="!gateway.is_active"
              type="button"
              class="btn-secondary shrink-0"
              :disabled="activatingProvider === gateway.provider"
              @click="activateGateway(gateway)"
            >
              {{ activatingProvider === gateway.provider ? 'กำลังเปิด...' : 'เปิดใช้งานช่องทางนี้' }}
            </button>
          </div>

          <p v-if="gateway.is_verified" class="mt-3 text-xs text-emerald-700 flex items-start gap-1.5">
            <Icon name="check" :size="14" class="mt-0.5 shrink-0" />
            <span>{{ gateway.verified_note }} ({{ formatVerifiedAt(gateway.verified_at) }})</span>
          </p>

          <!-- Credential form. A provider with no fields (the manual flow)
               renders none, and says where its configuration actually lives
               rather than showing an empty box. -->
          <form v-if="gateway.fields.length" class="mt-4 space-y-3" @submit.prevent="saveGateway(gateway)">
            <div v-for="field in gateway.fields" :key="field.key">
              <label class="text-xs font-bold text-slate-500 block mb-1">
                {{ field.label }}
                <span v-if="field.required" class="text-rose-500">*</span>
              </label>
              <input
                v-model="draftFor(gateway.provider)[field.key]"
                :type="field.secret ? 'password' : 'text'"
                :autocomplete="field.secret ? 'new-password' : 'off'"
                :placeholder="field.secret && field.is_set ? '••••••••  (เว้นว่างไว้เพื่อไม่เปลี่ยน)' : ''"
                class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
              />
              <p v-if="field.help" class="mt-1 text-xs text-slate-400">{{ field.help }}</p>
              <p v-if="field.secret" class="mt-1 text-xs text-slate-400">
                {{ field.is_set ? 'ตั้งค่าไว้แล้ว — ระบบไม่แสดงค่านี้ออกมาอีก' : 'ยังไม่ได้ตั้งค่า' }}
              </p>
            </div>

            <div class="flex items-center justify-between gap-3 pt-1">
              <div>
                <p class="text-sm font-bold text-slate-600">โหมดใช้งานจริง (Live)</p>
                <p class="text-xs text-slate-400">
                  ปิด = โหมดทดสอบ ไม่มีการเรียกเก็บเงินจริง — คีย์ต้องตรงกับโหมด ไม่งั้นระบบจะปฏิเสธตอนบันทึก
                </p>
              </div>
              <button
                type="button"
                class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
                :class="liveMode[gateway.provider] ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
                @click="liveMode[gateway.provider] = !liveMode[gateway.provider]"
              >
                <div
                  class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
                  :class="liveMode[gateway.provider] ? 'translate-x-7' : 'translate-x-0'"
                ></div>
              </button>
            </div>

            <!-- The webhook URL. Shown because a mistyped one fails silently:
                 the charge succeeds and orders simply never get confirmed. -->
            <div class="pt-2 border-t border-slate-100">
              <label class="text-xs font-bold text-slate-500 block mb-1">Webhook URL (วางในหน้า Dashboard ของผู้ให้บริการ)</label>
              <div class="flex items-center gap-2">
                <code class="flex-1 px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-700 break-all">
                  {{ webhookUrl(gateway.provider) }}
                </code>
                <button type="button" class="btn-secondary shrink-0" @click="copyWebhook(gateway.provider)">
                  {{ copiedWebhook === gateway.provider ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                </button>
              </div>
              <p class="mt-1 text-xs text-slate-400">
                ถ้าไม่ตั้ง webhook ระบบจะยังตัดบัตรได้ แต่รายการที่ยืนยันช้าจะไม่ถูกอัปเดตสถานะอัตโนมัติ
              </p>
            </div>

            <div class="flex justify-end pt-1">
              <button type="submit" :disabled="savingProvider === gateway.provider" class="btn-primary">
                {{ savingProvider === gateway.provider ? 'กำลังตรวจสอบ...' : 'บันทึกและตรวจสอบการเชื่อมต่อ' }}
              </button>
            </div>
          </form>

          <p v-if="errorFor[gateway.provider]" class="mt-2 text-xs font-bold text-rose-600">
            {{ errorFor[gateway.provider] }}
          </p>
          <p v-if="noticeFor[gateway.provider]" class="mt-2 text-xs font-bold text-emerald-600">
            {{ noticeFor[gateway.provider] }}
          </p>
        </div>
      </div>
    </template>
  </main>
</template>
