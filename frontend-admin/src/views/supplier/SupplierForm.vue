<script setup lang="ts">
/**
 * SupplierForm — the four panels of a supply deal.
 *
 * Shared by the create panel on the list screen and the ภาพรวม tab on the
 * detail screen, because they are the same form and two copies would drift —
 * and the half that drifts is always the one with the money rules on it.
 *
 * ── THE FOUR PANELS, AND WHY THEY ARE IN THIS ORDER ──
 *
 *   1. ข้อมูลคู่ค้า    who they are. Known first, and the only required field
 *                     in the whole form is in here.
 *   2. เงื่อนไขดีล     GP, when money becomes payable, the withdrawal floor.
 *   3. ภาษี           tax id and withholding.
 *   4. บัญชีรับเงิน    where the transfer goes. Known last, in practice.
 *
 * That is the order the facts arrive in real life, and a form that demands
 * them in a different order is a form people fill in from a notebook.
 *
 * ── UNITS ARE CONVERTED HERE AND NOWHERE ELSE ──
 *
 * The API stores basis points for percentages and satang for money; a person
 * types 30 and 500.00. The conversion lives in supplierTerms.ts and is applied
 * at this boundary only, so the payload is always in stored units and the
 * inputs are always in human ones. Doing it further in would mean some
 * component eventually sends 30 where 3000 was meant — a plausible number,
 * wrong by a hundredfold, in a column that decides our margin.
 */
import { computed } from 'vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import {
  GP_MODE_HINTS,
  GP_MODE_LABELS,
  RELEASE_TRIGGER_HINTS,
  RELEASE_TRIGGER_LABELS,
  type GpMode,
  type ReleaseTrigger,
  type SupplierFormValue,
  bahtToSatang,
  basisPointsToPercent,
  percentToBasisPoints,
  satangToBaht,
} from './supplierTerms'

const props = defineProps<{
  modelValue: SupplierFormValue
  errors?: Record<string, string[]>
}>()

const emit = defineEmits<{ 'update:modelValue': [SupplierFormValue] }>()

function patch(changes: Partial<SupplierFormValue>): void {
  emit('update:modelValue', { ...props.modelValue, ...changes })
}

function errorFor(field: string): string | null {
  return props.errors?.[field]?.[0] ?? null
}

const gpModes = Object.keys(GP_MODE_LABELS) as GpMode[]
const releaseTriggers = Object.keys(RELEASE_TRIGGER_LABELS) as ReleaseTrigger[]

/** True when gp_value should be read as money rather than as a percentage. */
const gpIsFixed = computed(() => props.modelValue.gp_mode === 'fixed_per_unit')

/** The GP as a person types it: a percentage, or baht. */
const gpInput = computed<number | null>(() => {
  const raw = props.modelValue.gp_value
  if (raw === null) return null

  return gpIsFixed.value ? satangToBaht(raw) : basisPointsToPercent(raw)
})

function setGpValue(raw: string): void {
  if (raw === '') {
    patch({ gp_value: null })

    return
  }

  const n = Number(raw)
  if (Number.isNaN(n)) return

  patch({ gp_value: gpIsFixed.value ? bahtToSatang(n) : percentToBasisPoints(n) })
}

/**
 * Changing the MODE reinterprets the value, so the value is cleared.
 *
 * 30 meaning 30% becomes 30 meaning ฿0.30 the moment the mode flips to fixed,
 * and the field would keep showing "30" while meaning something a hundred
 * times smaller. Clearing forces the number to be restated in the unit that is
 * now in force — an extra keystroke against a class of error nobody can see.
 */
function setGpMode(mode: string): void {
  patch({
    gp_mode: (mode || null) as GpMode | null,
    gp_value: null,
  })
}

const minWithdrawalInput = computed<number | null>(() =>
  props.modelValue.min_withdrawal_satang === null ? null : satangToBaht(props.modelValue.min_withdrawal_satang))

function setMinWithdrawal(raw: string): void {
  patch({ min_withdrawal_satang: raw === '' ? null : bahtToSatang(Number(raw)) })
}

const whtInput = computed<number | null>(() =>
  props.modelValue.wht_rate === null ? null : basisPointsToPercent(props.modelValue.wht_rate))

function setWht(raw: string): void {
  patch({ wht_rate: raw === '' ? null : percentToBasisPoints(Number(raw)) })
}

const inputClass = 'min-h-[40px] w-full px-3 rounded-lg border border-slate-300 text-sm focus:border-brand-500 focus:outline-none'
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- ── 1. ข้อมูลคู่ค้า ────────────────────────────────────────────── -->
    <fieldset class="space-y-3">
      <legend class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">ข้อมูลคู่ค้า</legend>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ชื่อคู่ค้า <span class="text-rose-600">*</span></span>
        <input
          :value="modelValue.name"
          data-test="supplier-name"
          type="text"
          :class="inputClass"
          placeholder="เช่น คลินิกความงาม ก."
          @input="patch({ name: ($event.target as HTMLInputElement).value })"
        />
        <span v-if="errorFor('name')" class="block text-xs text-rose-600 mt-1">{{ errorFor('name') }}</span>
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ชื่อนิติบุคคล (ใช้ออกเอกสาร)</span>
        <input
          :value="modelValue.legal_name ?? ''"
          type="text"
          :class="inputClass"
          @input="patch({ legal_name: ($event.target as HTMLInputElement).value || null })"
        />
      </label>

      <div class="grid grid-cols-2 gap-3">
        <label class="block">
          <span class="block text-xs font-bold text-slate-600 mb-1">ผู้ติดต่อ</span>
          <input
            :value="modelValue.contact_name ?? ''"
            type="text"
            :class="inputClass"
            @input="patch({ contact_name: ($event.target as HTMLInputElement).value || null })"
          />
        </label>
        <label class="block">
          <span class="block text-xs font-bold text-slate-600 mb-1">เบอร์โทร</span>
          <input
            :value="modelValue.contact_phone ?? ''"
            type="tel"
            :class="inputClass"
            @input="patch({ contact_phone: ($event.target as HTMLInputElement).value || null })"
          />
        </label>
      </div>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">อีเมล</span>
        <input
          :value="modelValue.contact_email ?? ''"
          type="email"
          :class="inputClass"
          @input="patch({ contact_email: ($event.target as HTMLInputElement).value || null })"
        />
        <span v-if="errorFor('contact_email')" class="block text-xs text-rose-600 mt-1">{{ errorFor('contact_email') }}</span>
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ที่อยู่</span>
        <textarea
          :value="modelValue.address ?? ''"
          rows="2"
          class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:border-brand-500 focus:outline-none"
          @input="patch({ address: ($event.target as HTMLTextAreaElement).value || null })"
        ></textarea>
      </label>

      <label class="flex items-center gap-2 pt-1">
        <input
          :checked="modelValue.is_active"
          data-test="supplier-active"
          type="checkbox"
          class="h-4 w-4 rounded border-slate-300"
          @change="patch({ is_active: ($event.target as HTMLInputElement).checked })"
        />
        <span class="text-sm text-slate-700">ใช้งานอยู่</span>
        <InfoPopover text="ปิดการใช้งานเมื่อจบดีลแล้ว — ประวัติและยอดค้างจ่ายยังอยู่ครบและยังจ่ายได้ตามปกติ แต่จะผูกสินค้าหรือสร้างบัญชีผู้ใช้ใหม่ไม่ได้" />
      </label>
    </fieldset>

    <!-- ── 2. เงื่อนไขดีล ─────────────────────────────────────────────── -->
    <fieldset class="space-y-3">
      <legend class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">เงื่อนไขดีล</legend>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">รูปแบบ GP (ส่วนที่เราเก็บไว้)</span>
        <select
          :value="modelValue.gp_mode ?? ''"
          data-test="gp-mode"
          :class="inputClass"
          @change="setGpMode(($event.target as HTMLSelectElement).value)"
        >
          <option value="">— ยังไม่กำหนด —</option>
          <option v-for="mode in gpModes" :key="mode" :value="mode">{{ GP_MODE_LABELS[mode] }}</option>
        </select>
        <span v-if="modelValue.gp_mode" class="block text-xs text-slate-500 mt-1">{{ GP_MODE_HINTS[modelValue.gp_mode] }}</span>
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">
          ค่า GP {{ gpIsFixed ? '(บาทต่อการขาย 1 ครั้ง)' : '(เปอร์เซ็นต์)' }}
        </span>
        <input
          :value="gpInput ?? ''"
          data-test="gp-value"
          type="number"
          step="0.01"
          min="0"
          :class="inputClass"
          :placeholder="gpIsFixed ? 'เช่น 500.00' : 'เช่น 30'"
          @input="setGpValue(($event.target as HTMLInputElement).value)"
        />
        <span v-if="errorFor('gp_value')" data-test="gp-value-error" class="block text-xs text-rose-600 mt-1">{{ errorFor('gp_value') }}</span>
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">คู่ค้าเบิกเงินได้เมื่อไหร่</span>
        <select
          :value="modelValue.release_trigger ?? ''"
          data-test="release-trigger"
          :class="inputClass"
          @change="patch({ release_trigger: (($event.target as HTMLSelectElement).value || null) as ReleaseTrigger | null })"
        >
          <option value="">— ยังไม่กำหนด —</option>
          <option v-for="t in releaseTriggers" :key="t" :value="t">{{ RELEASE_TRIGGER_LABELS[t] }}</option>
        </select>
        <span v-if="modelValue.release_trigger" class="block text-xs text-slate-500 mt-1">{{ RELEASE_TRIGGER_HINTS[modelValue.release_trigger] }}</span>
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ยอดขั้นต่ำต่อการขอเบิก (บาท)</span>
        <input
          :value="minWithdrawalInput ?? ''"
          data-test="min-withdrawal"
          type="number"
          step="0.01"
          min="0"
          :class="inputClass"
          placeholder="เว้นว่าง = ไม่กำหนดขั้นต่ำ"
          @input="setMinWithdrawal(($event.target as HTMLInputElement).value)"
        />
        <!-- The asymmetry is worth one sentence: it is easy to read this as a
             floor on payments generally, and then wonder why a small debt was
             settled anyway. -->
        <span class="block text-xs text-slate-500 mt-1">ใช้เฉพาะตอนคู่ค้าขอเบิกเอง — ถ้าเราตั้งจ่ายให้ ยอดเท่าไหร่ก็จ่ายได้</span>
      </label>
    </fieldset>

    <!-- ── 3. ภาษี ───────────────────────────────────────────────────── -->
    <fieldset class="space-y-3">
      <legend class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">ภาษี</legend>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">เลขประจำตัวผู้เสียภาษี</span>
        <input
          :value="modelValue.tax_id ?? ''"
          type="text"
          :class="inputClass"
          @input="patch({ tax_id: ($event.target as HTMLInputElement).value || null })"
        />
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">อัตราหัก ณ ที่จ่าย (เปอร์เซ็นต์)</span>
        <input
          :value="whtInput ?? ''"
          data-test="wht-rate"
          type="number"
          step="0.01"
          min="0"
          max="100"
          :class="inputClass"
          placeholder="เว้นว่าง = ไม่หัก"
          @input="setWht(($event.target as HTMLInputElement).value)"
        />
        <!-- Stated rather than defaulted: 0% and 3% are both correct answers
             to different questions, and picking one for the user would deduct
             tax from a goods supplier or skip it on a service one. -->
        <span class="block text-xs text-slate-500 mt-1">
          ขายสินค้าโดยทั่วไปไม่หัก · ค่าบริการมักหัก 3% — ตั้งรายสินค้าทับได้ที่หน้าสินค้า
        </span>
      </label>
    </fieldset>

    <!-- ── 4. บัญชีรับเงิน ───────────────────────────────────────────── -->
    <fieldset class="space-y-3">
      <legend class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">บัญชีรับเงิน</legend>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ธนาคาร</span>
        <input
          :value="modelValue.payout_bank_name ?? ''"
          data-test="bank-name"
          type="text"
          :class="inputClass"
          @input="patch({ payout_bank_name: ($event.target as HTMLInputElement).value || null })"
        />
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">เลขที่บัญชี</span>
        <input
          :value="modelValue.payout_bank_account_number ?? ''"
          data-test="bank-account-number"
          type="text"
          :class="inputClass"
          @input="patch({ payout_bank_account_number: ($event.target as HTMLInputElement).value || null })"
        />
      </label>

      <label class="block">
        <span class="block text-xs font-bold text-slate-600 mb-1">ชื่อบัญชี</span>
        <input
          :value="modelValue.payout_bank_account_name ?? ''"
          data-test="bank-account-name"
          type="text"
          :class="inputClass"
          @input="patch({ payout_bank_account_name: ($event.target as HTMLInputElement).value || null })"
        />
      </label>

      <p class="text-xs text-slate-500">
        บัญชีนี้คือบัญชีที่ <strong>เราโอนเข้า</strong> ให้คู่ค้า — คนละบัญชีกับบัญชีที่บริษัทใช้รับเงินจากลูกค้า
      </p>
    </fieldset>
  </div>
</template>
