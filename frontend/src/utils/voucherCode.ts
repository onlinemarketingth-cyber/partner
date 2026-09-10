/**
 * 2026-09-10 (human: "Admin ที่ใช้บัตร voucher นั้นต้องใช้วิธี Key
 * ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
 *
 * The redemption code used to be 40 random characters — fine for a QR, useless
 * for a person. Staff at a counter read it off a customer's phone and TYPE it,
 * and forty mixed-case characters cannot be typed, read aloud, or written on a
 * form. New codes are six characters from an alphabet with no I, L, O or U
 * (see App\Support\VoucherCode server-side, which is where the rule lives).
 *
 * This file is presentation only: how that code is shown. Vouchers issued
 * before the change still carry their long codes and are displayed unchanged —
 * chopping a 40-character token into groups of three makes it less readable,
 * not more.
 */

const SHORT_LENGTH = 6
const GROUP = 3

/** A code of the current, keyable shape — as opposed to a legacy token. */
export function isShortVoucherCode(code: string): boolean {
  return code.length === SHORT_LENGTH
}

/**
 * How the code is written down: ABC-123.
 *
 * Chunking is the difference between copying six characters in one glance and
 * losing your place half way. The server strips the dash again on the way in,
 * so a person may type it or leave it out.
 */
export function formatVoucherCode(code: string): string {
  if (!isShortVoucherCode(code)) return code

  return `${code.slice(0, GROUP)}-${code.slice(GROUP)}`
}
