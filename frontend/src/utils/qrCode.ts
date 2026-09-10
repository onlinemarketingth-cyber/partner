/**
 * TASK-056 — generalized QR generation, extracted from PaymentPageView's
 * inline PromptPay QR rendering (ADR-017). Same library, same
 * client-side-only guarantee: the encoded text is NEVER sent to a
 * third-party image service (§6) — `qrcode` renders the PNG entirely in
 * the browser. Reused by ShareLinkModal for product-share and
 * order-payment links (arbitrary URLs, not just PromptPay payloads).
 */
import QRCode from 'qrcode'

/**
 * 2026-09-10 — `level` and `margin` became worth exposing once the voucher
 * code shrank to six characters.
 *
 * A tiny payload fits in the smallest QR version, so the modules are large and
 * the extra redundancy of level 'H' (≈30% recoverable) costs nothing visible —
 * and it is exactly what a voucher needs: the thing being scanned is a phone
 * screen with a crack across it, a screenshot forwarded through a chat app
 * twice, or a card printed on an office laser printer.
 *
 * The margin is the QUIET ZONE. The spec asks for four modules; `1` was chosen
 * for the PromptPay QR, which sits on a white card with space around it. A
 * voucher QR is rendered on a dark card and downloaded as a PNG that people
 * crop, so it carries its own white border rather than relying on the layout
 * around it.
 *
 * Defaults are unchanged, so every existing caller keeps its exact output.
 */
export async function generateQrDataUrl(
  text: string,
  size = 240,
  options: { level?: 'L' | 'M' | 'Q' | 'H'; margin?: number } = {},
): Promise<string> {
  try {
    return await QRCode.toDataURL(text, {
      margin: options.margin ?? 1,
      width: size,
      errorCorrectionLevel: options.level ?? 'M',
    })
  } catch {
    return ''
  }
}
