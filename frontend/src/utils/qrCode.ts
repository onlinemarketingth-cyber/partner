/**
 * TASK-056 — generalized QR generation, extracted from PaymentPageView's
 * inline PromptPay QR rendering (ADR-017). Same library, same
 * client-side-only guarantee: the encoded text is NEVER sent to a
 * third-party image service (§6) — `qrcode` renders the PNG entirely in
 * the browser. Reused by ShareLinkModal for product-share and
 * order-payment links (arbitrary URLs, not just PromptPay payloads).
 */
import QRCode from 'qrcode'

export async function generateQrDataUrl(text: string, size = 240): Promise<string> {
  try {
    return await QRCode.toDataURL(text, { margin: 1, width: size })
  } catch {
    return ''
  }
}
