/**
 * MOB-21 (2026-10-02) — copy a link, share a link, share the QR picture.
 *
 * These three actions are the single most-used thing an agent does in this
 * portal: it is how a product, a payment link or a recruit link reaches a
 * customer. Each one used to call the browser API directly from five views.
 *
 * ── WHY THE APP NEEDS ITS OWN PATH ──
 *
 *  - Copy: `navigator.clipboard` needs a secure context AND, on Android's
 *    WebView, a permission the WebView never grants — the call rejects and the
 *    "คัดลอกแล้ว" tick never appears. @capacitor/clipboard writes through the
 *    OS instead.
 *  - Share: Android's WebView has no `navigator.share` at all, so the green
 *    share button (ShareLinkModal) would simply not exist in the Android app.
 *    @capacitor/share opens the real share sheet on both platforms.
 *  - QR picture: the web path shares a `File` object, which the WebView share
 *    sheet cannot carry. The app writes the PNG to its cache folder and shares
 *    the file:// URI — the form the native sheet understands — so "Save
 *    Image" and LINE both receive a real picture.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * In a browser every function below runs the code the views had before,
 * moved here unchanged: the same `navigator.clipboard.writeText`, the same
 * `navigator.share`, the same `canShare({ files })` check. Neither plugin is
 * loaded in a browser (dynamic import).
 */
import { isNativeApp } from './index'
import { shareCachedFile, writeCacheFile } from './files'

/**
 * Put text on the clipboard. Rejects when the clipboard refuses, in both
 * modes — every caller already has a catch that decides what to show.
 */
export async function copyText(text: string): Promise<void> {
  if (isNativeApp()) {
    const { Clipboard } = await import('@capacitor/clipboard')
    await Clipboard.write({ string: text })

    return
  }

  await navigator.clipboard.writeText(text)
}

/**
 * Whether to show a "share" button at all.
 *
 * Browser: ShareLinkModal's own rule (2026-08-21) — the API exists AND the
 * primary pointer is a finger. App: always — the native sheet is there on
 * every phone and tablet the app installs on.
 */
export function canOpenShareSheet(hasCoarsePointer: boolean): boolean {
  if (isNativeApp()) return true

  return typeof navigator !== 'undefined' && !!navigator.share && hasCoarsePointer
}

/**
 * Open the share sheet on a link. Rejects when the person cancels, in both
 * modes, exactly as navigator.share always did — callers treat that as a
 * no-op.
 */
export async function shareLink(options: { title?: string; url: string }): Promise<void> {
  if (isNativeApp()) {
    const { Share } = await import('@capacitor/share')
    await Share.share({ title: options.title, url: options.url, dialogTitle: options.title })

    return
  }

  await navigator.share({ title: options.title, url: options.url })
}

/**
 * Share a picture the page generated (the QR code), given as a data: URL.
 *
 * Browser: the original ShareLinkModal code — share the image as a File
 * where the browser can, otherwise fall back to sharing the link itself.
 * App: cache file + native share sheet. Cancelling never rejects in the app
 * (see files.ts isShareCancel); in a browser it rejects as it always did.
 */
export async function shareImage(options: {
  dataUrl: string
  filename: string
  title?: string
  /** Shared instead of the picture when a browser cannot share files. */
  fallbackUrl: string
}): Promise<void> {
  if (isNativeApp()) {
    const uri = await writeCacheFile(
      options.dataUrl.slice(options.dataUrl.indexOf(',') + 1),
      options.filename,
    )
    await shareCachedFile(uri, options.title)

    return
  }

  const canShareFiles = typeof navigator !== 'undefined' && !!navigator.canShare
  const res = await fetch(options.dataUrl)
  const blob = await res.blob()
  const file = new File([blob], options.filename, { type: 'image/png' })
  if (canShareFiles && navigator.canShare({ files: [file] })) {
    await navigator.share({ files: [file], title: options.title })
  } else {
    await navigator.share({ title: options.title, url: options.fallbackUrl })
  }
}
