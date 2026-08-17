<script setup lang="ts">
/**
 * VoucherRedeemView — "ตัดสิทธิ์บัตรกำนัล" (TASK-189 §7/F2).
 *
 * ADR-033 §2.1/§2.4 — a service-access voucher issued automatically when
 * an order is paid (analogous to a hotel voucher, per the human's own
 * framing). Redemption happens "at any branch, by staff there" — no
 * branch-matching rule, `branch` is free text, not a lookup. This screen
 * is that staff action: type/scan the code, see who/what it's for BEFORE
 * committing (C5's lookup endpoint — avoids a blind POST), then redeem.
 *
 * Gated by `Ability::VoucherRedeem` (CompanyAdmin/SuperAdmin only, NOT
 * Agent — ADR-033 §2.1's interim grant). This app already blocks the
 * Agent role entirely at the router level (TASK-057/161) — the SAME tier
 * this ability is granted to, so no extra per-route meta narrows this
 * further than every other undecorated admin route already is (see
 * router/index.ts's `requiresSuperAdmin` meta for the ONE case that
 * needs to be narrower still — Super-Admin-only — which this is not).
 * The backend is the real gate regardless (CLAUDE.md §5 rule 5).
 */
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import Icon from '@/design-system/components/Icon.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

// ADR-033 §4/C5 — VoucherResource: only what redemption staff need
// (order/product/customer display name, quota, expiry) — never the
// customer's full PDPA record.
interface Voucher {
  code: string
  status: 'active' | 'exhausted' | 'expired'
  status_label: string
  usage_quota: number | null
  used_count: number
  quota_remaining: number | null
  expires_at: string | null
  order_number: string | null
  product_name: string | null
  client_name: string | null
}

const codeInput = ref('')
const voucher = ref<Voucher | null>(null)
const lookupError = ref('')
const lookingUp = ref(false)

// A fresh code typed after a lookup must not keep showing a stale result
// (and stale result must not look redeemable via an old confirm click).
function onCodeInput() {
  voucher.value = null
  lookupError.value = ''
  redeemResult.value = null
}

async function lookupVoucher() {
  const code = codeInput.value.trim()
  if (!code || lookingUp.value) return
  lookingUp.value = true
  lookupError.value = ''
  voucher.value = null
  redeemResult.value = null
  try {
    const res = await api.get<{ data: Voucher }>(`/vouchers/${encodeURIComponent(code)}`)
    voucher.value = res.data
  } catch (e) {
    lookupError.value = apiErrorMessage(e, 'ค้นหาบัตรกำนัลไม่สำเร็จ')
  } finally {
    lookingUp.value = false
  }
}

function quotaLabel(v: Voucher): string {
  return v.usage_quota === null ? 'ไม่จำกัด' : `${v.used_count} / ${v.usage_quota} (เหลือ ${v.quota_remaining})`
}
function expiryLabel(v: Voucher): string {
  if (v.expires_at === null) return 'ไม่มีวันหมดอายุ'
  return new Date(v.expires_at).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' })
}
const statusBadgeClass: Record<Voucher['status'], string> = {
  active: 'bg-emerald-50 text-emerald-700',
  exhausted: 'bg-amber-50 text-amber-700',
  expired: 'bg-rose-50 text-rose-700',
}

// ── Redemption (branch free text — ADR-033 §2.1, "สาขาไหนก็ได้", not a
//    `branches` FK — plus a ConfirmDialog step, TASK-066/C4). ──
const branchInput = ref('')
const showConfirm = ref(false)
const redeeming = ref(false)
const redeemError = ref('')
const redeemResult = ref<Voucher | null>(null)

function openConfirm() {
  if (!voucher.value || voucher.value.status !== 'active') return
  redeemError.value = ''
  showConfirm.value = true
}

async function confirmRedeem() {
  if (!voucher.value || redeeming.value) return
  redeeming.value = true
  redeemError.value = ''
  try {
    const res = await api.post<{ data: Voucher }>('/vouchers/redeem', {
      code: voucher.value.code,
      branch: branchInput.value.trim() || undefined,
    })
    voucher.value = res.data
    redeemResult.value = res.data
    showConfirm.value = false
  } catch (e) {
    // C3 — distinct Thai messages per refusal reason (exhausted / expired /
    // not found / cross-tenant) already come back on the `code` field;
    // apiErrorMessage() surfaces that real reason, not a generic failure.
    redeemError.value = apiErrorMessage(e, 'ตัดสิทธิ์บัตรกำนัลไม่สำเร็จ')
    showConfirm.value = false
  } finally {
    redeeming.value = false
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="tag"
      title="ตัดสิทธิ์บัตรกำนัล"
      subtitle="ค้นหารหัสบัตรกำนัลของลูกค้าเพื่อตรวจสอบและตัดสิทธิ์การใช้บริการ"
      accent-color="brand"
      storage-key="voucher-redeem"
    />

    <!-- Code lookup -->
    <section class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5">
      <p class="text-base font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Icon name="search" :size="14" /> ค้นหาบัตรกำนัล
      </p>
      <form class="flex flex-col sm:flex-row gap-2" @submit.prevent="lookupVoucher">
        <input
          v-model="codeInput"
          type="text"
          placeholder="กรอกรหัสบัตรกำนัล"
          class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm font-mono tracking-wide"
          @input="onCodeInput"
        />
        <button type="submit" :disabled="!codeInput.trim() || lookingUp" class="btn-primary shrink-0">
          {{ lookingUp ? 'กำลังค้นหา...' : 'ค้นหา' }}
        </button>
      </form>

      <div v-if="lookupError" class="mt-3 px-3 py-2 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 flex items-center gap-2">
        <Icon name="alert" :size="16" class="shrink-0" />
        <span>{{ lookupError }}</span>
      </div>
    </section>

    <!-- Lookup result -->
    <section v-if="voucher" class="mt-4 bg-white/95 border border-slate-200 rounded-xl p-5 space-y-4">
      <div class="flex items-center justify-between gap-3">
        <p class="text-base font-bold text-slate-500 flex items-center gap-1.5">
          <Icon name="receipt" :size="14" /> รายละเอียดบัตรกำนัล
        </p>
        <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="statusBadgeClass[voucher.status]">
          {{ voucher.status_label }}
        </span>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div>
          <p class="text-xs font-bold text-slate-400">รหัสบัตรกำนัล</p>
          <p class="mt-0.5 font-mono font-bold text-slate-700">{{ voucher.code }}</p>
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400">เลขที่คำสั่งซื้อ</p>
          <p class="mt-0.5 font-bold text-slate-700">{{ voucher.order_number ?? '—' }}</p>
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400">สินค้า / บริการ</p>
          <p class="mt-0.5 font-bold text-slate-700">{{ voucher.product_name ?? '—' }}</p>
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400">ลูกค้า</p>
          <p class="mt-0.5 font-bold text-slate-700">{{ voucher.client_name ?? '—' }}</p>
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400">สิทธิ์การใช้งาน</p>
          <p class="mt-0.5 font-bold text-slate-700">{{ quotaLabel(voucher) }}</p>
        </div>
        <div>
          <p class="text-xs font-bold text-slate-400">วันหมดอายุ</p>
          <p class="mt-0.5 font-bold text-slate-700">{{ expiryLabel(voucher) }}</p>
        </div>
      </div>

      <div v-if="redeemResult" class="px-3 py-2 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-700 flex items-center gap-2">
        <Icon name="check_circle" :size="16" class="shrink-0" />
        <span>ตัดสิทธิ์สำเร็จ — ใช้ไปแล้ว {{ redeemResult.used_count }} ครั้ง</span>
      </div>
      <div v-else-if="redeemError" class="px-3 py-2 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 flex items-center gap-2">
        <Icon name="alert" :size="16" class="shrink-0" />
        <span>{{ redeemError }}</span>
      </div>

      <div v-if="voucher.status === 'active'" class="pt-2 border-t border-slate-100 space-y-3">
        <div>
          <label class="text-xs font-bold text-slate-500">สาขาที่ใช้บริการ (ถ้ามี)</label>
          <input
            v-model="branchInput"
            type="text"
            placeholder="เช่น สาขาสยาม, สาขาลาดพร้าว"
            class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
          />
          <p class="mt-1 text-xs text-slate-400">ใช้สิทธิ์ได้ทุกสาขา — ช่องนี้เป็นข้อความอิสระเพื่อบันทึกไว้เท่านั้น</p>
        </div>
        <div class="flex justify-end">
          <button type="button" class="btn-primary" @click="openConfirm">
            ตัดสิทธิ์
          </button>
        </div>
      </div>
    </section>

    <ConfirmDialog
      :show="showConfirm"
      variant="primary"
      title="ยืนยันการตัดสิทธิ์บัตรกำนัล"
      :body="voucher ? `ยืนยันตัดสิทธิ์บัตรกำนัลรหัส ${voucher.code}${branchInput.trim() ? ` ที่ ${branchInput.trim()}` : ''}? การกระทำนี้ไม่สามารถย้อนกลับได้` : ''"
      :busy="redeeming"
      @confirm="confirmRedeem"
      @cancel="showConfirm = false"
      @update:show="showConfirm = $event"
    />
  </main>
</template>
