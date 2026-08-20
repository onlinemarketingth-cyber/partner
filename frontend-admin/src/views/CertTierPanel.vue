<script setup lang="ts">
/**
 * TASK-221 — manage Academy cert tiers (ระดับใบรับรอง).
 *
 * WHY THIS SCREEN EXISTS
 * ----------------------
 * There was no way to create a cert tier. `GET /cert-tiers` was the only
 * route, and the rows came from CatalogSeeder — a DEV-ONLY seeder that also
 * inserts placeholder brands and products, so it was never run on
 * production. Production therefore had ZERO tiers, and the symptom the
 * human hit was two screens away: the Academy Section form would not save,
 * because its "Cert tier" <select> is required and had nothing in it.
 *
 * ONE LIST FOR THE WHOLE PLATFORM. `cert_tiers` has no company_id (see the
 * table's migration), so these rows are shared by every company. That is
 * why this panel is Super-Admin-only and says so on screen: an admin who
 * cannot see that renaming "Basic" renames it for every tenant would
 * reasonably assume it was theirs to change.
 *
 * BR-7 — no tier names, keys or ordering are suggested by this component.
 * CLAUDE.md §2 documents "Basic (mandatory) -> Intermediate -> High" as the
 * intended shape, but a form that pre-fills it decides it for the operator.
 * The empty state points at the documented shape as a HINT and fills in
 * nothing.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'

interface CertTier {
  id: number
  key: string
  name: string
  sort_order: number
  is_mandatory: boolean
}

const emit = defineEmits<{ changed: [] }>()

const tiers = ref<CertTier[]>([])
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const errorMessage = ref('')

const showForm = ref(false)
const editingId = ref<number | null>(null)
const pendingDelete = ref<CertTier | null>(null)

const form = ref({ key: '', name: '', sort_order: null as number | null, is_mandatory: false })

const isEditing = computed(() => editingId.value !== null)

/**
 * Spelled out rather than inlined in the template: the point of the
 * sentence is that the delete reaches EVERY company, and a message that
 * long is unreadable squeezed into an attribute.
 */
const deleteBody = computed(() =>
  `ลบ "${pendingDelete.value?.name ?? ''}" ออกจากทุกบริษัทในระบบ — `
  + 'ถ้ายังมีโมดูล แบบทดสอบ อัตราค่าคอมมิชชั่น หรือการรับรองตัวแทนผูกอยู่ '
  + 'ระบบจะไม่ยอมให้ลบ และจะบอกว่าติดอะไรอยู่',
)

/**
 * The key is the handle server code matches on (`where('key','basic')`).
 * Once anything depends on the tier the server refuses to change it — this
 * disables the input for the same reason, so the admin learns before typing
 * rather than after a 422.
 */
const editingTier = computed(() => tiers.value.find((t) => t.id === editingId.value) ?? null)

function message(e: unknown, fallback: string): string {
  return e instanceof ApiError ? e.message : fallback
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    // No company_id: this endpoint is global. Sending one used to 500
    // (TASK-209 filtered a column that does not exist) — fixed server-side
    // in TASK-221, and there is nothing here to scope anyway.
    tiers.value = (await api.get<{ data: CertTier[] }>('/cert-tiers')).data
  } catch (e) {
    errorMessage.value = message(e, 'โหลดระดับใบรับรองไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function startCreate(): void {
  editingId.value = null
  form.value = { key: '', name: '', sort_order: null, is_mandatory: false }
  showForm.value = true
  errorMessage.value = ''
}

function startEdit(tier: CertTier): void {
  editingId.value = tier.id
  form.value = {
    key: tier.key,
    name: tier.name,
    sort_order: tier.sort_order,
    is_mandatory: tier.is_mandatory,
  }
  showForm.value = true
  errorMessage.value = ''
}

function cancel(): void {
  showForm.value = false
  editingId.value = null
  errorMessage.value = ''
}

async function submit(): Promise<void> {
  const name = form.value.name.trim()
  const key = form.value.key.trim()

  if (!name) {
    errorMessage.value = 'กรุณากรอกชื่อระดับ'

    return
  }
  if (!key) {
    errorMessage.value = 'กรุณากรอกรหัส (key)'

    return
  }

  saving.value = true
  errorMessage.value = ''
  try {
    const payload: Record<string, unknown> = { key, name, is_mandatory: form.value.is_mandatory }
    // Omitted on create so the server assigns the next free slot rather
    // than letting two tiers share 0 — which makes every "highest passed
    // tier" query order arbitrarily, and shows up as a wrong commission
    // tier rather than a wrong list.
    if (form.value.sort_order !== null) payload.sort_order = form.value.sort_order

    if (isEditing.value) {
      await api.put(`/cert-tiers/${editingId.value}`, payload)
    } else {
      await api.post('/cert-tiers', payload)
    }

    showForm.value = false
    editingId.value = null
    await load()
    // The parent's Section form builds its required <select> from this
    // list; without this it would still be empty right after the admin
    // created the very tier that unblocks it.
    emit('changed')
  } catch (e) {
    errorMessage.value = message(e, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  const tier = pendingDelete.value
  if (!tier) return

  deleting.value = true
  errorMessage.value = ''
  try {
    await api.delete(`/cert-tiers/${tier.id}`)
    await load()
    emit('changed')
  } catch (e) {
    // The server answers 422 with a sentence naming exactly what still
    // uses the tier. Surfacing that verbatim is the whole point — a
    // generic "delete failed" would send the admin hunting.
    errorMessage.value = message(e, 'ลบไม่สำเร็จ')
  } finally {
    deleting.value = false
    pendingDelete.value = null
  }
}

onMounted(load)
</script>

<template>
  <section class="mt-4">
    <div class="bg-white/95 border border-slate-200 rounded-2xl p-5">
      <div class="flex items-start justify-between gap-3 mb-1">
        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
          <Icon name="shield_check" :size="14" class="text-brand-600" />
          ระดับใบรับรอง (Cert Tier)
        </h2>
        <button
          type="button"
          class="shrink-0 px-3 py-1.5 rounded-lg bg-brand-600 text-white font-bold text-xs hover:bg-brand-700"
          @click="startCreate"
        >
          + เพิ่มระดับ
        </button>
      </div>

      <!-- Stated on screen, not just in a docblock: an admin who cannot see
           that this list is shared would reasonably assume it is theirs. -->
      <p class="text-xs text-slate-400 mb-4 leading-relaxed">
        ระดับใบรับรอง<span class="font-bold text-slate-500">ใช้ร่วมกันทุกบริษัท</span>
        — แก้ไขที่นี่จะมีผลกับทุกบริษัทในระบบ · โมดูล, แบบทดสอบ,
        อัตราค่าคอมมิชชั่น และการรับรองตัวแทน ล้วนอ้างอิงระดับเหล่านี้
      </p>

      <p v-if="errorMessage" class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs font-bold text-rose-700">
        {{ errorMessage }}
      </p>

      <!-- Add / edit form -->
      <form
        v-if="showForm"
        class="mb-4 p-4 rounded-xl border-2 border-brand-200 bg-brand-50/30"
        @submit.prevent="submit"
      >
        <p class="text-[11px] font-bold text-brand-600 mb-0.5">
          {{ isEditing ? 'แก้ไขระดับใบรับรอง' : 'เพิ่มระดับใบรับรอง' }}
        </p>
        <h3 class="text-base font-bold text-slate-900 mb-3">
          {{ form.name.trim() || 'ยังไม่ได้ตั้งชื่อ' }}
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="block">
            <span class="block text-xs font-bold text-slate-600 mb-1">ชื่อที่แสดง *</span>
            <input
              v-model="form.name"
              type="text"
              maxlength="100"
              placeholder="เช่น ระดับพื้นฐาน"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </label>

          <label class="block">
            <span class="block text-xs font-bold text-slate-600 mb-1">รหัส (key) *</span>
            <input
              v-model="form.key"
              type="text"
              maxlength="50"
              placeholder="basic"
              :disabled="isEditing && !!editingTier"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:bg-slate-100 disabled:text-slate-400"
            />
            <span class="block mt-1 text-[11px] text-slate-400">
              a-z, 0-9, _ เท่านั้น · เป็นรหัสที่ระบบใช้อ้างอิง
              <template v-if="isEditing">· เปลี่ยนไม่ได้ถ้ามีข้อมูลผูกอยู่แล้ว</template>
            </span>
          </label>

          <label class="block">
            <span class="block text-xs font-bold text-slate-600 mb-1">ลำดับ</span>
            <input
              v-model.number="form.sort_order"
              type="number"
              min="0"
              placeholder="เว้นว่าง = ต่อท้ายอัตโนมัติ"
              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
            <span class="block mt-1 text-[11px] text-slate-400">
              เลขน้อย = ระดับต่ำกว่า · ใช้ตัดสินว่าใคร "ผ่านระดับสูงสุด" ระดับไหน
            </span>
          </label>

          <label class="flex items-start gap-2 md:pt-6 cursor-pointer select-none">
            <input
              v-model="form.is_mandatory"
              type="checkbox"
              class="mt-0.5 w-4 h-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
            />
            <span class="text-xs leading-relaxed">
              <span class="font-bold text-slate-700">เป็นระดับบังคับ</span>
              <span class="block text-slate-400">ตัวแทนต้องผ่านระดับนี้ก่อนจึงจะขายได้ (BR-1)</span>
            </span>
          </label>
        </div>

        <div class="flex justify-end gap-2 mt-4">
          <button
            type="button"
            class="px-3 py-2 rounded-xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200"
            @click="cancel"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="px-4 py-2 rounded-xl bg-brand-600 text-white font-bold text-xs hover:bg-brand-700 disabled:opacity-50"
          >
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>

      <p v-if="loading" class="text-xs text-slate-400">กำลังโหลด...</p>

      <EmptyState
        v-else-if="!tiers.length"
        icon="shield_check"
        title="ยังไม่มีระดับใบรับรอง"
        message="ต้องมีอย่างน้อย 1 ระดับ ก่อนจึงจะสร้าง Section ใน Academy หรือตั้งอัตราค่าคอมมิชชั่นได้ — โครงที่ระบบออกแบบไว้คือ Basic (บังคับ) → Intermediate → High"
        cta-label="เพิ่มระดับแรก"
        :cta-disabled="false"
        @cta="startCreate"
      />

      <ul v-else class="space-y-2">
        <li
          v-for="tier in tiers"
          :key="tier.id"
          class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl p-3"
        >
          <span class="shrink-0 w-7 h-7 rounded-lg bg-slate-100 text-slate-500 text-xs font-bold flex items-center justify-center">
            {{ tier.sort_order }}
          </span>

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5 min-w-0">
              <p class="text-sm font-bold text-slate-900 truncate">{{ tier.name }}</p>
              <span
                v-if="tier.is_mandatory"
                title="ตัวแทนต้องผ่านระดับนี้ก่อนจึงจะขายได้ (BR-1)"
                class="shrink-0 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-amber-50 text-amber-700 text-[11px] font-bold"
              >
                <Icon name="shield_check" :size="11" />
                บังคับ
              </span>
            </div>
            <p class="text-[11px] text-slate-400 font-mono truncate">{{ tier.key }}</p>
          </div>

          <div class="flex items-center gap-1 shrink-0">
            <button
              type="button"
              title="แก้ไข"
              class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100"
              @click="startEdit(tier)"
            >
              <Icon name="pencil" :size="14" />
            </button>
            <button
              type="button"
              title="ลบ"
              class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"
              @click="pendingDelete = tier"
            >
              <Icon name="trash" :size="14" />
            </button>
          </div>
        </li>
      </ul>
    </div>

    <ConfirmDialog
      :show="pendingDelete !== null"
      variant="danger"
      title="ลบระดับใบรับรอง?"
      :body="deleteBody"
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
      @update:show="(v: boolean) => { if (!v) pendingDelete = null }"
    />
  </section>
</template>
