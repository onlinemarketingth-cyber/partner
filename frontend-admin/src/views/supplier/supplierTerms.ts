/**
 * 2026-09-17 — the vocabulary of a supply deal, in one place.
 *
 * Three screens render these labels (the list, the detail page and the payout
 * queue) and a fourth reads them from a dropdown. Three copies of
 * "percent_of_sale → เปอร์เซ็นต์จากราคาขาย" is three chances for one of them
 * to say something slightly different about the same deal, which reads as two
 * different deals.
 *
 * ── THE UNIT TRAP, WRITTEN DOWN ──
 *
 * `gp_value` is ONE column carrying TWO units, decided by `gp_mode`:
 *
 *   percent_of_sale / percent_of_net   basis points — 3000 means 30%
 *   fixed_per_unit                     satang       — 3000 means ฿30.00
 *
 * The same stored number therefore means a hundredfold difference depending on
 * a neighbouring field. formatGp() is the only thing allowed to render it, so
 * that the conversion exists once instead of at every call site where somebody
 * might divide by the wrong constant.
 */

import { ApiError } from '@/api/client'

export type GpMode = 'percent_of_sale' | 'percent_of_net' | 'fixed_per_unit'
export type ReleaseTrigger = 'on_payment' | 'on_redeemed' | 'on_delivered'

export const GP_MODE_LABELS: Record<GpMode, string> = {
  percent_of_sale: 'เปอร์เซ็นต์จากราคาขาย',
  percent_of_net: 'เปอร์เซ็นต์จากยอดหลังหักค่าแนะนำ',
  fixed_per_unit: 'จำนวนเงินคงที่ต่อชิ้น',
}

/**
 * The one-line explanation of what each mode actually does to a supplier's
 * money. Shown under the dropdown because "percent of net" and "percent of
 * sale" sound interchangeable and are not: on a heavily-commissioned product
 * they differ by a lot, and the second MOVES when somebody edits a commission
 * rate.
 */
export const GP_MODE_HINTS: Record<GpMode, string> = {
  percent_of_sale: 'คิดจากราคาที่ลูกค้าจ่าย ก่อนหักค่าแนะนำ',
  percent_of_net: 'คิดจากยอดที่เหลือหลังจ่ายค่าแนะนำแล้ว — ตัวเลขนี้จะขยับเมื่อแก้อัตราค่าแนะนำ',
  fixed_per_unit: 'หักเป็นจำนวนเงินตายตัวต่อการขาย 1 ครั้ง ไม่ขึ้นกับราคา',
}

export const RELEASE_TRIGGER_LABELS: Record<ReleaseTrigger, string> = {
  on_payment: 'ทันทีที่ลูกค้าชำระเงิน',
  on_redeemed: 'เมื่อลูกค้ามาใช้สิทธิ์',
  on_delivered: 'เมื่อจัดส่งสินค้าแล้ว',
}

export const RELEASE_TRIGGER_HINTS: Record<ReleaseTrigger, string> = {
  on_payment: 'เหมาะกับสินค้าที่ส่งมอบทันที',
  on_redeemed: 'เหมาะกับคอร์ส/บริการที่ลูกค้าต้องมาใช้ที่สาขา',
  on_delivered: 'เหมาะกับสินค้าที่ต้องจัดส่ง — ยอดจะยังไม่ปล่อยจนกว่าจะบันทึกเลขพัสดุ',
}

/** 10000 basis points = 100%. */
const BASIS_POINT_SCALE = 10000

/** The stored figure, in the unit its mode implies. */
export function formatGp(mode: GpMode, value: number): string {
  if (mode === 'fixed_per_unit') {
    return `฿${(value / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  }

  // Percentages are kept to two places rather than rounded to whole numbers:
  // 2.5% is a real term and "3%" is a different deal.
  return `${(value / (BASIS_POINT_SCALE / 100)).toLocaleString('th-TH', { maximumFractionDigits: 2 })}%`
}

/** Withholding, always basis points. 300 → "3%". */
export function formatWht(rate: number | null): string {
  if (rate === null) return 'ไม่หัก'

  return `${(rate / (BASIS_POINT_SCALE / 100)).toLocaleString('th-TH', { maximumFractionDigits: 2 })}%`
}

/**
 * Percent typed by a person ↔ basis points stored.
 *
 * Separate from the satang conversion deliberately: they are both "divide by
 * a hundred" and they are not the same hundred, and the day somebody reuses
 * one for the other the error is invisible — a plausible number, wrong by a
 * factor of a hundred, in a column nobody re-reads.
 */
export function percentToBasisPoints(percent: number): number {
  return Math.round(percent * 100)
}

export function basisPointsToPercent(basisPoints: number): number {
  return basisPoints / 100
}

export function bahtToSatang(baht: number): number {
  return Math.round(baht * 100)
}

export function satangToBaht(satang: number): number {
  return satang / 100
}

/**
 * A supply deal as the form holds it — in STORED units, not typed ones.
 *
 * Lives here rather than in SupplierForm.vue because `<script setup>` cannot
 * export anything, and because three screens need the shape while only one
 * renders the inputs.
 */
export interface SupplierFormValue {
  name: string
  legal_name: string | null
  tax_id: string | null
  contact_name: string | null
  contact_phone: string | null
  contact_email: string | null
  address: string | null
  is_active: boolean
  gp_mode: GpMode | null
  gp_value: number | null
  release_trigger: ReleaseTrigger | null
  min_withdrawal_satang: number | null
  wht_rate: number | null
  payout_bank_name: string | null
  payout_bank_account_number: string | null
  payout_bank_account_name: string | null
}

/**
 * A brand-new supplier: a name and nothing else.
 *
 * Every deal term is null rather than pre-filled with a plausible default,
 * which is BR-7 at the UI layer. A pre-filled 30% GP is a number nobody agreed
 * to, saved by somebody who assumed it came from the deal.
 */
export function emptySupplier(): SupplierFormValue {
  return {
    name: '',
    legal_name: null,
    tax_id: null,
    contact_name: null,
    contact_phone: null,
    contact_email: null,
    address: null,
    is_active: true,
    gp_mode: null,
    gp_value: null,
    release_trigger: null,
    min_withdrawal_satang: null,
    wht_rate: null,
    payout_bank_name: null,
    payout_bank_account_number: null,
    payout_bank_account_name: null,
  }
}

/**
 * Laravel's per-field validation errors off an ApiError, or null.
 *
 * `ApiError` carries the raw response as `body`; the field map lives inside
 * it. Pulled out here because the supplier screens depend on the messages
 * arriving at the right FIELD — "ต้องระบุทั้งรูปแบบ GP และค่า GP คู่กัน" is
 * useless in a banner at the top of a four-panel form and exact underneath the
 * box it is about.
 */
export function fieldErrors(e: unknown): Record<string, string[]> | null {
  if (!(e instanceof ApiError)) return null

  const body = e.body as { errors?: Record<string, string[]> } | undefined

  return body?.errors ?? null
}
