/**
 * TASK-063 — ported from frontend/src/utils/qrCode.ts (TASK-056) per §7's
 * "duplicate design-system/shared utils between the two independent Vue
 * apps, keep in sync" convention (ADR-003, CI-001/CI-002). Same library,
 * same client-side-only guarantee: the encoded text is NEVER sent to a
 * third-party image service (§6) — `qrcode` renders the PNG entirely in
 * the browser.
 */
import QRCode from 'qrcode'

export async function generateQrDataUrl(text: string, size = 240): Promise<string> {
  try {
    return await QRCode.toDataURL(text, { margin: 1, width: size })
  } catch {
    return ''
  }
}
