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
 * This file is presentation only: how that code is shown, and how what a
 * person types is tidied on its way to the server (which normalises again —
 * this is a courtesy, never the check). Vouchers issued
 * before the change still carry their long codes and are displayed unchanged —
 * chopping a 40-character token into groups of three makes it less readable,
 * not more.
 */

const SHORT_LENGTH = 6
const GROUP = 3

/**
 * The alphabet the server draws from: Crockford base32, minus I, L, O and U.
 * Mirrored here ONLY to tidy input as it is typed — the server is where the
 * rule lives (App\Support\VoucherCode).
 */
const ALPHABET = /[^0-9ABCDEFGHJKMNPQRSTVWXYZ]/g

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

/**
 * What the person meant, from what they typed — uppercased, with the printed
 * card's dash and any stray spaces removed, and O/I/L mapped onto the 0/1 they
 * are always mistaken for.
 *
 * ONLY applied to input that is still short enough to be a short code. A
 * pasted 40-character legacy token is handed over untouched: it is
 * case-sensitive and may legitimately contain those very letters, so tidying
 * it would turn a valid code into "not found".
 */
export function tidyVoucherCodeInput(raw: string): string {
  const trimmed = raw.trim()
  if (trimmed.length > SHORT_LENGTH + 1) return trimmed

  return trimmed
    .toUpperCase()
    // Explicit character comparisons, not `'IL'.includes(c)`: a space is a
    // substring of anything you write with spaces in it, and mapping a stray
    // space onto '1' would silently corrupt a correctly typed code.
    .replace(ALPHABET, (character) => {
      if (character === 'O') return '0'
      if (character === 'I' || character === 'L') return '1'

      return ''
    })
    .slice(0, SHORT_LENGTH)
}
