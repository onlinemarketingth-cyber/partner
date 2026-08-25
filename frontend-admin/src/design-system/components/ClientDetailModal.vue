<script setup lang="ts">
/**
 * ClientDetailModal — the client file, without leaving the list.
 *
 * ── WHY (human, 2026-08-22, with a screenshot of the client list) ──
 *
 * "ผมคลิ๊กรายละเอียดแล้วพบว่า หน้าจอลายละเอียดนั้นดูแล้วกลับมาดูอีกคน
 * อยากให้ปรับเป็น Modal" — checking three customers meant three full
 * navigations and three trips back, losing the list's scroll position and
 * search each time.
 *
 * The full page (ClientFileView) STAYS: SalesTeamView deep-links into it and
 * a URL you can paste is worth keeping. This is the second surface, for the
 * "check several people quickly" job the list is actually used for.
 *
 * Everything either surface KNOWS lives in composables/useClientFile.ts, so
 * the two cannot drift on what a stage means or whether somebody has paid.
 * This file owns layout, the summary strip, and editing.
 *
 * ── THE SUMMARY STRIP IS THE POINT ──
 *
 * The same message asked: "ผมเช็คได้ยังไงว่าลูกค้าคนนี้อยู่ในสถานะใด
 * จ่ายเงินหรือยัง รอทำอะไร ในหน้าเดียว". Before this, an admin got a stage
 * label and had to infer the rest. Each deal now states its stage, its
 * payment state, and who is being waited on, in one row.
 *
 * ── EDITING ──
 *
 * ClientFileView's header still reads "ดูอย่างเดียว — การแก้ไขข้อมูลยังเป็น
 * สิทธิ์ของ Agent Portal เท่านั้น". That boundary is deliberately broken here,
 * on the human's instruction, for Super Admin and Company Admin only. The
 * backend needed nothing: ClientPolicy::update and PUT /clients/{id} have
 * existed all along and the Admin app simply never called them.
 */
import { computed, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import Icon from './Icon.vue'
import LoadingSkeleton from './LoadingSkeleton.vue'
import { THAILAND_PROVINCES } from '../constants/thailandProvinces'
import {
  formatDate,
  formatDateTime,
  formatMoney,
  formatSize,
  paymentBadgeClasses,
  statusBadgeClasses,
  useClientFile,
  type ClientDetail,
  type ClientDocumentItem,
} from '@/composables/useClientFile'

const props = defineProps<{
  /** null closes the modal. A number opens (and loads) that client. */
  clientId: number | null
}>()

const emit = defineEmits<{
  close: []
  /** Fired after a successful save so the list can refresh the changed row. */
  saved: [client: ClientDetail]
}>()

const auth = useAuthStore()
const file = useClientFile()
const {
  loading,
  hasLoadedOnce,
  errorMessage,
  client,
  documents,
  activities,
  relatedAgents,
  nationalIdDisplay,
  referralSummaries,
} = file

/*
 * WHO MAY EDIT. Super Admin and Company Admin, per the human's instruction.
 *
 * A UI gate only — ClientPolicy::update is what actually decides, and it
 * additionally scopes a Company Admin to their own company (BR-6). Hiding
 * the button from an Agent is courtesy; the server refusing them is the
 * rule. Agents keep editing their own clients in the Agent Portal, which is
 * where that has always lived.
 */
const mayEdit = computed(
  () => auth.user?.role === 'super_admin' || auth.user?.role === 'company_admin',
)

const CLIENT_STATUSES = [
  { key: 'new', label: 'ใหม่' },
  { key: 'contacted', label: 'ติดต่อแล้ว' },
  { key: 'interested', label: 'สนใจ' },
  { key: 'not_interested', label: 'ไม่สนใจ' },
]

// ── Edit form ─────────────────────────────────────────────────────────
const editing = ref(false)
const saving = ref(false)
const saveError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

interface EditForm {
  name: string
  phone: string
  email: string
  national_id: string
  consent_given_at: string
  health_notes: string
  status: string
  lead_source: string
  date_of_birth: string
  address: string
  province: string
  occupation: string
}

const form = ref<EditForm>(blankForm())

function blankForm(): EditForm {
  return {
    name: '',
    phone: '',
    email: '',
    national_id: '',
    consent_given_at: '',
    health_notes: '',
    status: 'new',
    lead_source: '',
    date_of_birth: '',
    address: '',
    province: '',
    occupation: '',
  }
}

function startEditing(): void {
  const c = client.value
  if (!c) return

  form.value = {
    name: c.name,
    phone: c.phone,
    email: c.email ?? '',
    /*
     * The FULL national ID, never the masked one. ClientResource sends
     * `national_id` only to a viewer allowed to see it — the same two roles
     * that can reach this button — so for them it is present. Prefilling the
     * mask instead would submit "x-xxxx-xxxxx-xx-3" as the real value and
     * fail the ThaiNationalId rule, or worse, save it.
     */
    national_id: c.national_id ?? '',
    // <input type="date"> wants YYYY-MM-DD; the API sends a full timestamp
    // for consent and a plain date for DOB.
    consent_given_at: c.consent_given_at ? c.consent_given_at.slice(0, 10) : '',
    health_notes: c.health_notes ?? '',
    status: c.status.key,
    lead_source: c.lead_source ?? '',
    date_of_birth: c.date_of_birth ?? '',
    address: c.address ?? '',
    province: c.province ?? '',
    occupation: c.occupation ?? '',
  }
  fieldErrors.value = {}
  saveError.value = ''
  editing.value = true
}

function cancelEditing(): void {
  editing.value = false
  fieldErrors.value = {}
  saveError.value = ''
}

/** '' means "clear this optional field", which the API expects as null. */
function orNull(value: string): string | null {
  const trimmed = value.trim()

  return trimmed === '' ? null : trimmed
}

async function save(): Promise<void> {
  const c = client.value
  if (!c || saving.value) return

  saving.value = true
  saveError.value = ''
  fieldErrors.value = {}

  try {
    const res = await api.put<{ data: ClientDetail }>(`/clients/${c.id}`, {
      name: form.value.name.trim(),
      phone: form.value.phone.trim(),
      email: orNull(form.value.email),
      national_id: orNull(form.value.national_id),
      consent_given_at: orNull(form.value.consent_given_at),
      health_notes: orNull(form.value.health_notes),
      status: form.value.status,
      lead_source: orNull(form.value.lead_source),
      date_of_birth: orNull(form.value.date_of_birth),
      address: orNull(form.value.address),
      province: orNull(form.value.province),
      occupation: orNull(form.value.occupation),
    })

    // The response is the authority, not the form: the server normalises
    // (masking, category name, the reloaded referrals) and re-rendering from
    // what was typed would show a client that does not exist yet.
    client.value = res.data
    editing.value = false
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { message?: string; errors?: Record<string, string[]> } | null
      fieldErrors.value = body?.errors ?? {}
      // The per-field messages are rendered beside their inputs; this is the
      // fallback for a 422 that names no field at all.
      saveError.value = Object.keys(fieldErrors.value).length > 0
        ? 'กรุณาตรวจสอบข้อมูลที่กรอก'
        : (body?.message ?? 'บันทึกไม่สำเร็จ')
    } else if (e instanceof ApiError && e.status === 403) {
      saveError.value = 'คุณไม่มีสิทธิ์แก้ไขข้อมูลลูกค้ารายนี้'
    } else {
      saveError.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    saving.value = false
  }
}

function fieldError(key: string): string | null {
  return fieldErrors.value[key]?.[0] ?? null
}

// ── Open / close ──────────────────────────────────────────────────────
watch(
  () => props.clientId,
  (id) => {
    // reset() clears the cached per-referral timelines too — without it the
    // previous customer's sales history would render under this one's name.
    file.reset()
    editing.value = false
    if (id !== null) void file.load(id)
  },
  { immediate: true },
)

function requestClose(): void {
  // An unsaved edit must not vanish to a stray backdrop tap. This is the one
  // place the modal refuses to close on its own.
  if (editing.value) return
  emit('close')
}

async function downloadDocument(doc: ClientDocumentItem): Promise<void> {
  try {
    await api.download(`/client-documents/${doc.id}/download`, doc.original_filename)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ดาวน์โหลดไม่สำเร็จ (${e.status})` : 'ดาวน์โหลดไม่สำเร็จ'
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="client-modal">
      <div
        v-if="clientId !== null"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4"
        @click.self="requestClose"
      >
        <div
          class="client-modal-panel w-full max-w-4xl max-h-[80vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
        >
          <!-- Header -->
          <div class="shrink-0 px-5 py-4 border-b border-slate-200 flex items-center gap-3">
            <span class="w-9 h-9 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
              <Icon name="user" :size="18" />
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="text-base font-bold text-slate-800 truncate">
                {{ client?.name ?? 'แฟ้มทะเบียนลูกค้า' }}
              </h2>
              <p class="text-xs text-slate-500 truncate">
                {{ client ? `${client.phone} · ลูกค้าตั้งแต่ ${formatDate(client.created_at)}` : 'กำลังโหลด…' }}
              </p>
            </div>

            <span
              v-if="client && !editing"
              :class="['text-xs font-bold px-2 py-1 rounded-lg whitespace-nowrap', statusBadgeClasses(client.status.key)]"
            >
              {{ client.status.label }}
            </span>

            <button
              v-if="client && mayEdit && !editing"
              type="button"
              class="shrink-0 min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 transition"
              @click="startEditing"
            >
              <Icon name="edit" :size="14" /> แก้ไข
            </button>

            <button
              type="button"
              :disabled="editing"
              :title="editing ? 'กรุณาบันทึกหรือยกเลิกก่อนปิด' : 'ปิด'"
              class="shrink-0 w-9 h-9 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 flex items-center justify-center transition disabled:opacity-40 disabled:cursor-not-allowed"
              @click="emit('close')"
            >
              <Icon name="x" :size="18" />
            </button>
          </div>

          <!-- Body -->
          <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
            <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="detail" />

            <div
              v-else-if="errorMessage"
              class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700"
            >
              {{ errorMessage }}
            </div>

            <template v-else-if="client">
              <!-- ═══ EDIT FORM ═══ -->
              <form v-if="editing" class="space-y-4" @submit.prevent="save">
                <p
                  v-if="saveError"
                  class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700"
                >
                  {{ saveError }}
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">ชื่อ-นามสกุล *</span>
                    <input
                      v-model="form.name"
                      type="text"
                      required
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                    <span v-if="fieldError('name')" class="text-xs text-rose-600">{{ fieldError('name') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">เบอร์โทร *</span>
                    <input
                      v-model="form.phone"
                      type="tel"
                      required
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                    <span v-if="fieldError('phone')" class="text-xs text-rose-600">{{ fieldError('phone') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">อีเมล</span>
                    <input
                      v-model="form.email"
                      type="email"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                    <span v-if="fieldError('email')" class="text-xs text-rose-600">{{ fieldError('email') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">สถานะ</span>
                    <select
                      v-model="form.status"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    >
                      <option v-for="s in CLIENT_STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <span v-if="fieldError('status')" class="text-xs text-rose-600">{{ fieldError('status') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">วันเกิด</span>
                    <input
                      v-model="form.date_of_birth"
                      type="date"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                    <span v-if="fieldError('date_of_birth')" class="text-xs text-rose-600">{{ fieldError('date_of_birth') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">อาชีพ</span>
                    <input
                      v-model="form.occupation"
                      type="text"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">จังหวัด</span>
                    <!-- A select, not free text: the API validates against the
                         fixed 77-province list, so a typo would be a 422 the
                         admin cannot diagnose. Blank = clear the field. -->
                    <select
                      v-model="form.province"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    >
                      <option value="">— ไม่ระบุ —</option>
                      <option v-for="p in THAILAND_PROVINCES" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <span v-if="fieldError('province')" class="text-xs text-rose-600">{{ fieldError('province') }}</span>
                  </label>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">ที่มาของลูกค้า</span>
                    <input
                      v-model="form.lead_source"
                      type="text"
                      class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    />
                  </label>
                </div>

                <label class="block">
                  <span class="text-xs font-bold text-slate-500 block mb-1">ที่อยู่</span>
                  <textarea
                    v-model="form.address"
                    rows="2"
                    class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                  ></textarea>
                </label>

                <!-- PDPA-sensitive block, boxed and labelled so nobody edits
                     these by accident. The human explicitly chose to include
                     them; the boundary is that they are visibly different
                     from an address, not that they are hidden. -->
                <fieldset class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 space-y-3">
                  <legend class="px-2 text-xs font-bold text-amber-700 flex items-center gap-1.5">
                    <Icon name="shield" :size="13" /> ข้อมูลอ่อนไหว (PDPA)
                  </legend>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="block">
                      <span class="text-xs font-bold text-slate-500 block mb-1">เลขบัตรประชาชน</span>
                      <input
                        v-model="form.national_id"
                        type="text"
                        inputmode="numeric"
                        maxlength="17"
                        class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                      />
                      <span v-if="fieldError('national_id')" class="text-xs text-rose-600">{{ fieldError('national_id') }}</span>
                    </label>

                    <label class="block">
                      <span class="text-xs font-bold text-slate-500 block mb-1">วันที่ให้ความยินยอม</span>
                      <input
                        v-model="form.consent_given_at"
                        type="date"
                        class="w-full min-h-[40px] px-3 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                      />
                      <span v-if="fieldError('consent_given_at')" class="text-xs text-rose-600">{{ fieldError('consent_given_at') }}</span>
                    </label>
                  </div>

                  <label class="block">
                    <span class="text-xs font-bold text-slate-500 block mb-1">บันทึกสุขภาพ</span>
                    <textarea
                      v-model="form.health_notes"
                      rows="3"
                      class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    ></textarea>
                    <span v-if="fieldError('health_notes')" class="text-xs text-rose-600">{{ fieldError('health_notes') }}</span>
                  </label>
                </fieldset>
              </form>

              <!-- ═══ READ VIEW ═══ -->
              <template v-else>
                <!-- ── สถานะ / ชำระเงิน / รออะไร — the whole point ── -->
                <section>
                  <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                    สถานะดีล · การชำระเงิน · รอดำเนินการ
                  </h3>

                  <p
                    v-if="referralSummaries.length === 0"
                    class="px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-500"
                  >
                    ลูกค้ารายนี้ยังไม่มีดีล/สินค้าที่สนใจ
                  </p>

                  <div v-else class="space-y-2">
                    <div
                      v-for="s in referralSummaries"
                      :key="s.referral.id"
                      class="rounded-xl border border-slate-200 p-3"
                    >
                      <div class="flex items-start gap-2 flex-wrap">
                        <p class="text-sm font-bold text-slate-800 flex-1 min-w-0">
                          {{ s.referral.product?.name ?? 'ไม่ระบุสินค้า' }}
                        </p>
                        <span
                          :class="['text-xs font-bold px-2 py-0.5 rounded-lg border whitespace-nowrap', paymentBadgeClasses(s.payment)]"
                        >
                          {{ s.paymentLabel }}
                        </span>
                      </div>

                      <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                        <p class="text-slate-600">
                          <span class="text-slate-400 block">ขั้นตอนปัจจุบัน</span>
                          <span class="font-bold">{{ s.stageLabel }}</span>
                        </p>
                        <p class="text-slate-600">
                          <span class="text-slate-400 block">รอดำเนินการ</span>
                          <span class="font-bold">{{ s.waitingOn }}</span>
                        </p>
                        <p class="text-slate-600">
                          <span class="text-slate-400 block">มูลค่า</span>
                          <span class="font-bold">
                            {{ s.amountSatang === null ? 'ไม่ระบุ' : `฿${formatMoney(s.amountSatang)}` }}
                          </span>
                        </p>
                      </div>

                      <p class="mt-2 text-xs text-slate-400">
                        ผู้ขาย: {{ s.referral.agent?.name ?? 'ไม่ระบุ' }}
                        <template v-if="s.referral.co_agent">
                          · ผู้ขายร่วม: {{ s.referral.co_agent.name }}
                          <template v-if="s.referral.split_percentage">({{ s.referral.split_percentage }}%)</template>
                        </template>
                        <template v-if="s.referral.order?.paid_at">
                          · ชำระเมื่อ {{ formatDateTime(s.referral.order.paid_at) }}
                          <template v-if="s.referral.order.verified_by">
                            · ตรวจสอบโดย {{ s.referral.order.verified_by.name }}
                          </template>
                        </template>
                      </p>
                    </div>
                  </div>
                </section>

                <!-- ── ข้อมูลทะเบียน ── -->
                <section class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ข้อมูลทะเบียน</h3>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1.5 text-sm text-slate-600">
                    <p><span class="text-slate-400">เบอร์โทร:</span> {{ client.phone }}</p>
                    <p><span class="text-slate-400">อีเมล:</span> {{ client.email ?? 'ยังไม่ระบุ' }}</p>
                    <p><span class="text-slate-400">เลขบัตรประชาชน:</span> {{ nationalIdDisplay }}</p>
                    <p><span class="text-slate-400">วันเกิด:</span> {{ formatDate(client.date_of_birth) }}</p>
                    <p><span class="text-slate-400">อาชีพ:</span> {{ client.occupation ?? 'ยังไม่ระบุ' }}</p>
                    <p><span class="text-slate-400">จังหวัด:</span> {{ client.province ?? 'ยังไม่ระบุ' }}</p>
                    <p class="md:col-span-2"><span class="text-slate-400">ที่อยู่:</span> {{ client.address ?? 'ยังไม่ระบุ' }}</p>
                    <p><span class="text-slate-400">ที่มา:</span> {{ client.lead_source ?? 'ยังไม่ระบุ' }}</p>
                    <p>
                      <span class="text-slate-400">ให้ความยินยอม:</span>
                      <span :class="client.consent_given_at ? 'text-emerald-600 font-bold' : 'text-rose-600 font-bold'">
                        {{ client.consent_given_at ? formatDate(client.consent_given_at) : 'ยังไม่ให้ความยินยอม' }}
                      </span>
                    </p>
                  </div>

                  <div v-if="client.health_notes" class="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-3">
                    <p class="text-xs font-bold text-amber-700 mb-1 flex items-center gap-1.5">
                      <Icon name="shield" :size="13" /> บันทึกสุขภาพ (PDPA)
                    </p>
                    <p class="text-sm text-slate-700 whitespace-pre-line">{{ client.health_notes }}</p>
                  </div>
                </section>

                <!-- ── Agent ที่เกี่ยวข้อง ── -->
                <section class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Agent ที่เกี่ยวข้อง</h3>
                  <div class="flex flex-wrap gap-2">
                    <span
                      v-for="a in relatedAgents"
                      :key="a.id"
                      class="text-xs px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700"
                    >
                      <span class="font-bold">{{ a.name }}</span>
                      <span class="text-slate-500"> · {{ a.roles.join(' / ') }}</span>
                    </span>
                  </div>
                </section>

                <!-- ── เอกสาร ── -->
                <section class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                    เอกสารแนบ ({{ documents.length }})
                  </h3>
                  <p v-if="documents.length === 0" class="text-sm text-slate-400">ยังไม่มีเอกสาร</p>
                  <ul v-else class="space-y-1">
                    <li v-for="d in documents" :key="d.id">
                      <button
                        type="button"
                        class="w-full min-h-[36px] flex items-center gap-2 text-left text-sm text-slate-600 hover:text-brand-700 transition"
                        @click="downloadDocument(d)"
                      >
                        <Icon name="document" :size="14" class="text-slate-400 shrink-0" />
                        <span class="truncate flex-1">{{ d.original_filename }}</span>
                        <span class="text-xs text-slate-400 shrink-0">{{ formatSize(d.size_bytes) }}</span>
                      </button>
                    </li>
                  </ul>
                </section>

                <!-- ── กิจกรรมล่าสุด ── -->
                <section class="rounded-xl border border-slate-200 p-4">
                  <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                    กิจกรรมล่าสุด ({{ activities.length }})
                  </h3>
                  <p v-if="activities.length === 0" class="text-sm text-slate-400">ยังไม่มีการบันทึกกิจกรรม</p>
                  <ul v-else class="space-y-2">
                    <li v-for="a in activities.slice(0, 8)" :key="a.id" class="text-sm">
                      <p class="text-slate-700">
                        <span class="text-xs font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ a.type.label }}</span>
                        {{ a.summary }}
                      </p>
                      <p class="text-xs text-slate-400">
                        {{ a.logged_by_name }} · {{ formatDateTime(a.occurred_at) }}
                        <template v-if="a.follow_up_at"> · ติดตาม {{ formatDateTime(a.follow_up_at) }}</template>
                      </p>
                    </li>
                  </ul>
                </section>
              </template>
            </template>
          </div>

          <!-- Footer — only while editing, so the read view keeps its height -->
          <div v-if="editing" class="shrink-0 px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
            <button
              type="button"
              :disabled="saving"
              class="min-h-[40px] px-4 rounded-lg border border-slate-300 text-sm font-bold text-slate-600 hover:bg-slate-50 transition disabled:opacity-50"
              @click="cancelEditing"
            >
              ยกเลิก
            </button>
            <button
              type="button"
              :disabled="saving"
              class="min-h-[40px] px-4 rounded-lg bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 transition disabled:opacity-50"
              @click="save"
            >
              {{ saving ? 'กำลังบันทึก…' : 'บันทึก' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Same two-part motion as AnnouncementModal: the scrim only fades, the panel
   fades and rises. A backdrop that slides is a backdrop you notice, and the
   job of a scrim is to not be noticed. prefers-reduced-motion is handled
   globally in assets/main.css. */
.client-modal-enter-active {
  transition: opacity 220ms ease-out;
}
.client-modal-leave-active {
  transition: opacity 180ms ease-in;
}
.client-modal-enter-from,
.client-modal-leave-to {
  opacity: 0;
}

.client-modal-enter-active .client-modal-panel {
  transition:
    transform 300ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 220ms ease-out;
}
.client-modal-leave-active .client-modal-panel {
  transition:
    transform 180ms ease-in,
    opacity 180ms ease-in;
}
.client-modal-enter-from .client-modal-panel,
.client-modal-leave-to .client-modal-panel {
  opacity: 0;
  transform: translateY(20px);
}
</style>
