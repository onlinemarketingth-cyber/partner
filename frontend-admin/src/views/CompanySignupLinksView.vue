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
    formError.value = 'เลือก "หมดอายุ" ไว้แล้ว กรุณาระบุวันหมดอายุ'

    return
  }
  if (form.value.hasLimit && !form.value.maxUses) {
    formError.value = 'เลือก "จำกัดจำนวน" ไว้แล้ว กรุณาระบุจำนวนคน'

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
    formError.value = e instanceof ApiError ? e.message : 'สร้างลิงก์ไม่สำเร็จ'
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
    errorMessage.value = e instanceof ApiError ? `โหลดรายการไม่สำเร็จ (${e.status})` : 'โหลดรายการไม่สำเร็จ'
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
    errorMessage.value = e instanceof ApiError ? `ปิดลิงก์ไม่สำเร็จ (${e.status})` : 'ปิดลิงก์ไม่สำเร็จ'
  } finally {
    revoking.value = false
  }
}

// ── Labels ──────────────────────────────────────────────────────────────
function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}

/** NULL is "ไม่จำกัด", never "0" — the two mean opposite things. */
function usageLabel(link: SignupLink): string {
  return link.max_uses === null
    ? `สมัครแล้ว ${link.used_count} คน · ไม่จำกัดจำนวน`
    : `สมัครแล้ว ${link.used_count} / ${link.max_uses} คน`
}

function expiryLabel(link: SignupLink): string {
  return link.expires_at === null ? 'ไม่มีวันหมดอายุ' : `หมดอายุ ${formatDate(link.expires_at)}`
}

function status(link: SignupLink): { label: string; ok: boolean } {
  if (link.revoked_at) return { label: 'ปิดแล้ว', ok: false }
  if (!link.is_valid) return { label: 'ใช้ไม่ได้แล้ว', ok: false }

  return { label: 'ใช้งานได้', ok: true }
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
      title="ลิงก์สมัครตัวแทน"
      subtitle="ลิงก์เปิดรับสมัครตัวแทนของบริษัท — คนที่กดลิงก์เข้าหน้าสมัครได้เลย ไม่ต้องกรอกรหัสเชิญ"
      accent-color="brand"
      storage-key="company-signup-links"
    />

    <CompanyScopeNotice v-if="!embedded" action="จัดการลิงก์สมัครตัวแทน" />

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-bold text-slate-900">ลิงก์สมัครของบริษัทนี้</p>
          <p class="text-xs text-slate-500 mt-1">
            ต่างจาก "ลิงก์ชวนทีม" ตรงที่ลิงก์นี้เป็นของบริษัท ไม่ผูกกับหัวหน้าทีมคนไหน
            — คนที่สมัครเข้ามาจะไม่ถูกนับเป็นลูกทีมของใคร
          </p>
        </div>
        <button class="btn-primary shrink-0" data-test="new-signup-link" @click="showForm = !showForm">
          {{ showForm ? 'ยกเลิก' : '+ สร้างลิงก์ใหม่' }}
        </button>
      </div>
    </div>

    <!-- Create form -->
    <div v-if="showForm" class="mt-3 bg-white/95 border border-brand-200 rounded-xl p-4 space-y-3">
      <div>
        <label for="signup_code" class="block text-xs font-bold text-slate-600 mb-1">
          รหัสในลิงก์ <span class="font-normal text-slate-400">(เว้นว่าง = สุ่มให้)</span>
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
          ใช้ได้เฉพาะ a-z, 0-9 และ - · <b>ตั้งแล้วเปลี่ยนไม่ได้</b> เพราะลิงก์อาจถูกพิมพ์แจกไปแล้ว
          หากต้องการรหัสใหม่ให้ปิดลิงก์นี้แล้วสร้างใหม่
        </p>
      </div>

      <div>
        <label for="signup_label" class="block text-xs font-bold text-slate-600 mb-1">
          ชื่อเรียก <span class="font-normal text-slate-400">(ไม่บังคับ)</span>
        </label>
        <input
          id="signup_label"
          v-model="form.label"
          type="text"
          placeholder="ใบปลิวสาขาสีลม"
          class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="flex items-center gap-2 text-xs font-bold text-slate-600">
            <input v-model="form.hasExpiry" data-test="has-expiry" type="checkbox" class="rounded" />
            กำหนดวันหมดอายุ
          </label>
          <input
            v-if="form.hasExpiry"
            v-model="form.expiresAt"
            type="datetime-local"
            class="mt-1.5 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <p v-else class="text-[11px] text-slate-400 mt-1.5">ไม่หมดอายุ — เหมาะกับลิงก์ที่พิมพ์ลงใบปลิวหรือป้าย</p>
        </div>
        <div>
          <label class="flex items-center gap-2 text-xs font-bold text-slate-600">
            <input v-model="form.hasLimit" data-test="has-limit" type="checkbox" class="rounded" />
            จำกัดจำนวนคน
          </label>
          <input
            v-if="form.hasLimit"
            v-model="form.maxUses"
            type="number"
            min="1"
            class="mt-1.5 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <p v-else class="text-[11px] text-slate-400 mt-1.5">ไม่จำกัดจำนวนคนที่สมัครผ่านลิงก์นี้</p>
        </div>
      </div>

      <p v-if="formError" class="text-xs font-bold text-rose-600">{{ formError }}</p>

      <div class="flex justify-end gap-2">
        <button class="btn-secondary" @click="showForm = false; resetForm()">ยกเลิก</button>
        <button class="btn-primary" data-test="save-signup-link" :disabled="saving" @click="submitForm">
          {{ saving ? 'กำลังสร้าง...' : 'สร้างลิงก์' }}
        </button>
      </div>
    </div>

    <LoadingSkeleton v-if="loading && !links.length" type="list" :rows="3" class="mt-4" />
    <EmptyState
      v-else-if="!links.length"
      icon="link"
      title="ยังไม่มีลิงก์สมัครตัวแทน"
      description="สร้างลิงก์แรกเพื่อให้คนใหม่สมัครเข้าบริษัทได้โดยไม่ต้องกรอกรหัสเชิญ"
      class="mt-4"
    />
    <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
      <div
        v-for="link in links"
        :key="link.id"
        class="bg-white/95 border border-slate-200 rounded-xl p-4"
        :class="link.revoked_at ? 'opacity-60' : ''"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="link" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">
                {{ link.label || 'ลิงก์ไม่มีชื่อ' }}
                <span v-if="isSuperAdmin && link.company_name" class="text-xs font-normal text-slate-400">
                  · {{ link.company_name }}
                </span>
              </p>
              <button
                class="mt-1 flex items-center gap-1.5 text-xs font-bold text-brand-700 hover:text-brand-800 max-w-full"
                :title="link.signup_url"
                @click="copy(link)"
              >
                <span class="truncate">{{ link.signup_url }}</span>
                <Icon :name="copiedId === link.id ? 'check' : 'copy'" :size="13" class="shrink-0" />
                <span class="shrink-0 font-normal text-slate-400">
                  {{ copiedId === link.id ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                </span>
              </button>
              <p class="text-xs text-slate-500 mt-1">{{ usageLabel(link) }} · {{ expiryLabel(link) }}</p>
              <p class="text-xs text-slate-400">
                สร้างเมื่อ {{ formatDate(link.created_at) }}
                <template v-if="link.created_by_name"> · โดย {{ link.created_by_name }}</template>
              </p>
            </div>
          </div>
          <div class="flex flex-col items-end gap-1.5 shrink-0">
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-lg"
              :class="status(link).ok ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ status(link).label }}
            </span>
            <button
              v-if="!link.revoked_at"
              data-test="revoke-signup-link"
              class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1"
              @click="pendingRevoke = link"
            >
              ปิดลิงก์
            </button>
          </div>
        </div>
      </div>
    </TransitionGroup>

    <ConfirmDialog
      :show="pendingRevoke !== null"
      variant="danger"
      title="ปิดลิงก์สมัครตัวแทน"
      :body="
        pendingRevoke
          ? `ปิดลิงก์ ${pendingRevoke.signup_url} — คนที่กดลิงก์นี้จะสมัครไม่ได้อีก (คนที่สมัครไปแล้ว ${pendingRevoke.used_count} คนยังอยู่ตามเดิม) ถ้าลิงก์นี้ถูกพิมพ์แจกไปแล้ว ลิงก์บนกระดาษจะใช้ไม่ได้ทันที และย้อนกลับไม่ได้`
          : ''
      "
      :busy="revoking"
      @confirm="confirmRevoke"
      @update:show="(v: boolean) => { if (!v) pendingRevoke = null }"
    />
  </main>
</template>
