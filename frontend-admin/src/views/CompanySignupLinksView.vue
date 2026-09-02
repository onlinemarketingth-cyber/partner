<script setup lang="ts">
/**
 * CompanySignupLinksView — "ลิงก์สมัครตัวแทน" (TASK-233).
 *
 * ── WHY THIS SCREEN IS NEW RATHER THAN A REDESIGN ──
 *
 * `company_invite_codes` has been in the schema since ADR-005 and the
 * application has only ever READ it. There was no route, no controller and
 * no screen that could create one: setting a company up meant somebody
 * opening the production database and typing an INSERT. And what it made
 * was not a link — a recruit had to reach /register on their own and type
 * the code in by hand.
 *
 * So this page is the feature, not a nicer view of an existing one.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──
 *
 * The code cannot be edited after creation, and the form says so. It is the
 * printed part of the URL — on a flyer, a business card, the sign in the
 * branch office. Changing it does not edit the flyer already on the wall;
 * it kills it. Wanting a different code means wanting a different link, so
 * the honest action is to close this one and create another, which is
 * exactly what the UI offers.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'
import LinkQrModal from '@/design-system/components/LinkQrModal.vue'
import { useI18n } from '@/composables/useI18n'

const { lang, td } = useI18n()

/** CompanyInviteCodeResource, field for field. */
interface SignupLink {
  id: number
  company_id: number
  company_name?: string | null
  code: string
  label: string | null
  signup_url: string
  /** NULL = never expires. Never coerced to a falsy date. */
  expires_at: string | null
  /** NULL = unlimited. Never coerced to 0, which would mean the opposite. */
  max_uses: number | null
  used_count: number
  revoked_at: string | null
  is_valid: boolean
  created_by_name?: string | null
  created_at: string
}

const auth = useAuthStore()
const activeCompany = useActiveCompanyStore()

const links = ref<SignupLink[]>([])
const loading = ref(false)
const errorMessage = ref('')
const copiedId = ref<number | null>(null)

const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// ── Create form ─────────────────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const formError = ref('')
const form = ref({
  code: '',
  label: '',
  /**
   * Both limits are a two-step choice on purpose: a checkbox for "does this
   * link expire at all", then the value. `expires_at: null` and "the admin
   * has not filled the date in yet" are completely different statements,
   * and a single empty text box cannot tell them apart — it would quietly
   * make "forever" the thing that happens when somebody gets distracted.
   * The API enforces the same distinction with `present` rather than
   * `sometimes`.
   */
  hasExpiry: false,
  expiresAt: '',
  hasLimit: false,
  maxUses: '' as string,
})

function resetForm() {
  form.value = { code: '', label: '', hasExpiry: false, expiresAt: '', hasLimit: false, maxUses: '' }
  formError.value = ''
}

async function submitForm() {
  formError.value = ''

  if (form.value.hasExpiry && !form.value.expiresAt) {
    formError.value = td('signup.err_expiry_required')

    return
  }
  if (form.value.hasLimit && !form.value.maxUses) {
    formError.value = td('signup.err_limit_required')

    return
  }

  saving.value = true
  try {
    await api.post('/company-invite-codes', {
      ...(isSuperAdmin.value && activeCompany.companyId !== null
        ? { company_id: activeCompany.companyId }
        : {}),
      code: form.value.code.trim() || null,
      label: form.value.label.trim() || null,
      // Sent explicitly as null, never omitted — see the comment on `form`.
      expires_at: form.value.hasExpiry ? form.value.expiresAt : null,
      max_uses: form.value.hasLimit ? Number(form.value.maxUses) : null,
    })
    showForm.value = false
    resetForm()
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : td('signup.err_create_failed')
  } finally {
    saving.value = false
  }
}

// ── List ────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: SignupLink[] }>(activeCompany.scopedPath('/company-invite-codes'))
    links.value = res.data
  } catch (e) {
    errorMessage.value =
      e instanceof ApiError ? `${td('links.load_failed')} (${e.status})` : td('links.load_failed')
  } finally {
    loading.value = false
  }
}

async function copy(link: SignupLink) {
  try {
    await navigator.clipboard.writeText(link.signup_url)
    copiedId.value = link.id
    setTimeout(() => {
      if (copiedId.value === link.id) copiedId.value = null
    }, 2000)
  } catch {
    // Clipboard permission denied, or an insecure context. The URL is on
    // screen and selectable, so failing quietly beats an error toast about
    // something the admin can still do by hand.
  }
}

// ── QR (TASK-240, reworked 2026-09-01) ─────────────────────────────────
/**
 * One dialog for the whole table (LinkQrModal), instead of a per-row inline
 * panel with its own cache, "generating" flag and open-row id. The row shows
 * an icon; the QR is generated once, on click, inside the modal — so a
 * company with fifty printed signup links still renders zero QR codes on
 * load, which is what the old per-row laziness was protecting.
 */
const qrLink = ref<SignupLink | null>(null)

// ── Revoke ──────────────────────────────────────────────────────────────
const pendingRevoke = ref<SignupLink | null>(null)
const revoking = ref(false)

async function confirmRevoke() {
  const link = pendingRevoke.value
  if (!link) return
  revoking.value = true
  try {
    await api.delete(`/company-invite-codes/${link.id}`)
    await load()
    pendingRevoke.value = null
  } catch (e) {
    errorMessage.value =
      e instanceof ApiError ? `${td('links.revoke_failed')} (${e.status})` : td('links.revoke_failed')
  } finally {
    revoking.value = false
  }
}

// ── Labels ──────────────────────────────────────────────────────────────
function formatDate(iso: string | null): string {
  if (!iso) return ''

  // Buddhist-era, Thai month names in TH; plain Gregorian in EN. Locale is
  // the ONLY difference — the same Date, never a re-parsed string.
  return new Date(iso).toLocaleDateString(lang.value === 'EN' ? 'en-GB' : 'th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

/** NULL is "unlimited", never "0" — the two mean opposite things. */
function usageLabel(link: SignupLink): string {
  return link.max_uses === null
    ? String(link.used_count)
    : td('links.used_of', '', { used: link.used_count, max: link.max_uses })
}

/** 0-100, or null when there is no ceiling to fill. */
function usagePercent(link: SignupLink): number | null {
  if (link.max_uses === null || link.max_uses === 0) return null

  return Math.min(100, Math.round((link.used_count / link.max_uses) * 100))
}

function expiryLabel(link: SignupLink): string {
  return link.expires_at === null ? td('links.no_expiry') : formatDate(link.expires_at)
}

function status(link: SignupLink): { label: string; ok: boolean } {
  if (link.revoked_at) return { label: td('links.status_revoked'), ok: false }
  if (!link.is_valid) return { label: td('links.status_unusable'), ok: false }

  return { label: td('links.status_active'), ok: true }
}

onMounted(load)
watch(() => activeCompany.companyId, load)

/**
 * Rendered inside LinksHubView's tab bar rather than as its own page
 * (2026-08-22 — the three link screens became one page with three tabs).
 *
 * The hub owns the HeroHeader and the company-scope notice, so `embedded`
 * suppresses this file's copies of both. Nothing else changes: every fetch,
 * filter, mutation and watcher here is untouched. Rewriting them into the
 * hub would have made a second copy of working code, which is the drift this
 * codebase keeps paying for.
 */
defineProps<{ embedded?: boolean }>()

</script>

<template>
  <main :class="embedded ? '' : 'min-h-screen px-4 py-6 lg:px-8'">
    <HeroHeader
      v-if="!embedded"
      icon="link"
      :title="td('signup.title')"
      :subtitle="td('signup.subtitle')"
      accent-color="brand"
      storage-key="company-signup-links"
    />

    <CompanyScopeNotice v-if="!embedded" :action="td('signup.scope_action')" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-bold text-slate-900">{{ td('signup.card_title') }}</p>
          <p class="text-xs text-slate-500 mt-1">
            {{ td('signup.card_help') }}
          </p>
        </div>
        <button class="btn-primary shrink-0" data-test="new-signup-link" @click="showForm = !showForm">
          {{ showForm ? td('common.cancel') : td('signup.btn_new') }}
        </button>
      </div>
    </div>

    <!-- Create form -->
    <div v-if="showForm" class="mt-3 bg-white/95 border border-brand-200 rounded-xl p-4 space-y-3">
      <div>
        <label for="signup_code" class="block text-xs font-bold text-slate-600 mb-1">
          {{ td('signup.field_code') }}
          <span class="font-normal text-slate-400">{{ td('signup.field_code_hint') }}</span>
        </label>
        <div class="flex items-center gap-1 text-sm">
          <span class="text-slate-400 shrink-0">.../c/</span>
          <input
            id="signup_code"
            v-model="form.code"
            data-test="signup-code"
            type="text"
            placeholder="thailife"
            class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
        </div>
        <p class="text-[11px] text-slate-400 mt-1">
          {{ td('signup.code_help_charset') }} · <b>{{ td('signup.code_help_immutable') }}</b>
          {{ td('signup.code_help_tail') }}
        </p>
      </div>

      <div>
        <label for="signup_label" class="block text-xs font-bold text-slate-600 mb-1">
          {{ td('signup.field_label') }}
          <span class="font-normal text-slate-400">{{ td('signup.field_label_hint') }}</span>
        </label>
        <input
          id="signup_label"
          v-model="form.label"
          type="text"
          :placeholder="td('signup.label_placeholder')"
          class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="flex items-center gap-2 text-xs font-bold text-slate-600">
            <input v-model="form.hasExpiry" data-test="has-expiry" type="checkbox" class="rounded" />
            {{ td('signup.opt_expiry') }}
          </label>
          <input
            v-if="form.hasExpiry"
            v-model="form.expiresAt"
            type="datetime-local"
            class="mt-1.5 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <p v-else class="text-[11px] text-slate-400 mt-1.5">{{ td('signup.opt_expiry_none') }}</p>
        </div>
        <div>
          <label class="flex items-center gap-2 text-xs font-bold text-slate-600">
            <input v-model="form.hasLimit" data-test="has-limit" type="checkbox" class="rounded" />
            {{ td('signup.opt_limit') }}
          </label>
          <input
            v-if="form.hasLimit"
            v-model="form.maxUses"
            type="number"
            min="1"
            class="mt-1.5 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <p v-else class="text-[11px] text-slate-400 mt-1.5">{{ td('signup.opt_limit_none') }}</p>
        </div>
      </div>

      <p v-if="formError" class="text-xs font-bold text-rose-600">{{ formError }}</p>

      <div class="flex justify-end gap-2">
        <button class="btn-secondary" @click="showForm = false; resetForm()">{{ td('common.cancel') }}</button>
        <button class="btn-primary" data-test="save-signup-link" :disabled="saving" @click="submitForm">
          {{ saving ? td('signup.btn_creating') : td('signup.btn_create') }}
        </button>
      </div>
    </div>

    <LoadingSkeleton v-if="loading && !links.length" type="list" :rows="3" class="mt-4" />
    <EmptyState
      v-else-if="!links.length"
      icon="link"
      :title="td('links.empty_signup_title')"
      :description="td('links.empty_signup_message')"
      class="mt-4"
    />
    <!--
      2026-09-01 (human decision) — a TABLE, not one card per link. A card
      spent a full row's height on four label/value pairs that a header can
      name once, and the fields an admin scans for (how many signed up, is it
      still open) sat at different x positions on every card.

      overflow-x-auto on the wrapper, not the page: the last two columns may
      push past a narrow window, and the page body must never scroll sideways.
    -->
    <div v-else class="mt-4 bg-white/95 border border-slate-200 rounded-xl overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-slate-50 text-[11px] text-slate-500">
            <th class="px-3 py-2 font-bold w-24"><span class="sr-only">{{ td('links.col_qr') }}</span></th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_name') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_link') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_signups') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_expires') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_status') }}</th>
            <th class="text-left px-4 py-2 font-bold">{{ td('links.col_created') }}</th>
            <th class="text-right px-4 py-2 font-bold"><span class="sr-only">{{ td('links.col_actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="link in links"
            :key="link.id"
            class="border-t border-slate-100 align-middle"
            :class="link.revoked_at ? 'opacity-60' : ''"
          >
            <td class="px-3 py-2">
              <button
                type="button"
                data-test="toggle-qr"
                class="inline-flex items-center gap-1.5 pl-1.5 pr-2.5 py-1 rounded-lg border border-slate-200 text-slate-500 hover:text-brand-600 hover:border-brand-300 hover:bg-brand-50 transition"
                :title="td('links.qr_open')"
                @click="qrLink = link"
              >
                <!-- The word carries the meaning; the glyph only has to be
                     recognisable. An icon-only button here was a square
                     nobody could read (human feedback, 2026-09-02). -->
                <Icon name="qr_code" :size="28" />
                <span class="text-[11px] font-bold">QR</span>
              </button>
            </td>
            <td class="px-4 py-2 min-w-0">
              <p class="font-bold text-slate-800 truncate max-w-[220px]">{{ link.label || td('links.untitled') }}</p>
              <p class="text-[11px] text-slate-400 truncate max-w-[220px]">
                /c/{{ link.code }}
                <template v-if="isSuperAdmin && link.company_name"> · {{ link.company_name }}</template>
              </p>
            </td>
            <td class="px-4 py-2">
              <button
                class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-700 hover:text-brand-800 max-w-[260px]"
                :title="link.signup_url"
                @click="copy(link)"
              >
                <span class="truncate">{{ link.signup_url }}</span>
                <Icon :name="copiedId === link.id ? 'check' : 'copy'" :size="13" class="shrink-0" />
                <span class="shrink-0 font-normal text-slate-400">
                  {{ copiedId === link.id ? td('common.copied') : td('common.copy') }}
                </span>
              </button>
            </td>
            <td class="px-4 py-2 whitespace-nowrap">
              <span class="text-slate-700 tabular-nums">{{ usageLabel(link) }}</span>
              <span v-if="link.max_uses === null" class="text-[11px] text-slate-400">
                · {{ td('links.unlimited') }}
              </span>
              <!-- A dashed track when there is no ceiling: a full solid bar
                   under an unlimited link would read as "quota used up". -->
              <div
                class="mt-1 h-1 w-20 rounded-full overflow-hidden"
                :class="usagePercent(link) === null ? 'bg-slate-100' : 'bg-slate-200'"
              >
                <div
                  v-if="usagePercent(link) !== null"
                  class="h-full bg-brand-500 rounded-full"
                  :style="{ width: usagePercent(link) + '%' }"
                />
              </div>
            </td>
            <td class="px-4 py-2 text-xs whitespace-nowrap" :class="link.expires_at ? 'text-slate-600' : 'text-slate-400'">
              {{ expiryLabel(link) }}
            </td>
            <td class="px-4 py-2">
              <span
                class="text-[11px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap"
                :class="status(link).ok ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ status(link).label }}
              </span>
            </td>
            <td class="px-4 py-2 text-xs text-slate-500 whitespace-nowrap">
              {{ formatDate(link.created_at) }}
              <template v-if="link.created_by_name">
                <br />
                <span class="text-slate-400">{{ td('links.by_name', '', { name: link.created_by_name }) }}</span>
              </template>
            </td>
            <td class="px-4 py-2 text-right">
              <button
                v-if="!link.revoked_at"
                data-test="revoke-signup-link"
                class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 whitespace-nowrap"
                @click="pendingRevoke = link"
              >
                {{ td('links.revoke_signup') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <LinkQrModal
      :url="qrLink?.signup_url ?? null"
      :filename="qrLink ? `signup-qr-${qrLink.code}` : 'signup-qr'"
      :caption="td('links.caption_signup')"
      @close="qrLink = null"
    />

    <ConfirmDialog
      :show="pendingRevoke !== null"
      variant="danger"
      :title="td('signup.revoke_title')"
      :body="
        pendingRevoke
          ? td('signup.revoke_body', '', { url: pendingRevoke.signup_url, count: pendingRevoke.used_count })
          : ''
      "
      :busy="revoking"
      @confirm="confirmRevoke"
      @update:show="(v: boolean) => { if (!v) pendingRevoke = null }"
    />
  </main>
</template>
