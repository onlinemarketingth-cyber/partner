/**
 * MOB-23 (2026-10-02) — "save this file" in the app and in the browser.
 *
 * ── WHY THE APP CANNOT USE THE BROWSER'S WAY ──
 *
 * The web portal saves a file by clicking an invisible `<a download>`. Inside
 * the app that click goes nowhere: neither WKWebView (iOS) nor Android's
 * WebView implements downloads unless the native shell adds a handler, and
 * Capacitor's shell does not. The button would look pressed and nothing would
 * happen — no error, no file.
 *
 * So in the app the bytes are written to the app's own CACHE folder and the
 * phone's share sheet is opened on that file. From there the agent can "Save
 * to Files" / "Save Image", or send it straight into LINE, which is what they
 * were going to do with a payment slip anyway.
 *
 * Cache, not Documents: the OS may clear it under storage pressure, which is
 * right for a copy whose only job is to be handed to the share sheet. Nothing
 * here asks for a storage permission, because the app only writes inside its
 * own sandbox.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * In a browser saveBlob() is, line for line, the code api/client.ts had
 * before (requestDownload / requestDownloadAbsolute), and neither plugin is
 * ever loaded (dynamic import), so the web bundle a browser downloads does
 * not grow by them.
 */
import { isNativeApp } from './index'

/** Folder inside the app's cache directory. Keeps our files in one place. */
const CACHE_FOLDER = 'shared'

/**
 * A file name that is safe as a single path segment on both platforms.
 *
 * The names come from the server (`original_filename` of an uploaded client
 * document), so a name like "../../x" or "a/b.pdf" is possible in principle.
 * Only the separators are replaced — Thai characters stay, because the agent
 * reads this name in the share sheet and in the Files app.
 */
export function safeFileName(name: string): string {
  const cleaned = Array.from(name, (ch) => (ch.charCodeAt(0) < 32 ? '_' : ch))
    .join('')
    .replace(/[/\\:*?"<>|]/g, '_')
    .trim()

  return cleaned === '' || cleaned === '.' || cleaned === '..' ? 'download' : cleaned
}

/** Blob → bare base64 (no `data:...;base64,` prefix), which writeFile wants. */
export function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error ?? new Error('read failed'))
    reader.onload = () => {
      const result = String(reader.result ?? '')
      resolve(result.slice(result.indexOf(',') + 1))
    }
    reader.readAsDataURL(blob)
  })
}

/**
 * Write base64 bytes into the app's cache folder and return the file:// URI
 * the share sheet accepts. Native only — callers check isNativeApp() first.
 */
export async function writeCacheFile(base64: string, filename: string): Promise<string> {
  const { Filesystem, Directory } = await import('@capacitor/filesystem')
  const result = await Filesystem.writeFile({
    path: `${CACHE_FOLDER}/${safeFileName(filename)}`,
    data: base64,
    directory: Directory.Cache,
    recursive: true,
  })

  return result.uri
}

/**
 * The share sheet rejects when the person closes it without choosing
 * anything. That is a decision, not a failure — callers show an error toast
 * on a rejection, and "ดาวน์โหลดไม่สำเร็จ" after a deliberate cancel would be
 * a lie.
 */
export function isShareCancel(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error ?? '')

  return /cancel/i.test(message)
}

/** Open the share sheet on a cached file. Cancelling is not an error. */
export async function shareCachedFile(uri: string, title?: string): Promise<void> {
  const { Share } = await import('@capacitor/share')
  try {
    await Share.share({ files: [uri], title, dialogTitle: title })
  } catch (error) {
    if (!isShareCancel(error)) throw error
  }
}

/**
 * Save a downloaded file.
 *
 * Browser: the invisible `<a download>` click, exactly as before.
 * App: cache folder + share sheet (see the header for why).
 */
export async function saveBlob(blob: Blob, filename: string): Promise<void> {
  if (isNativeApp()) {
    const uri = await writeCacheFile(await blobToBase64(blob), filename)
    await shareCachedFile(uri, filename)

    return
  }

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/**
 * Save an image the page generated itself (a QR code), given as a data: URL.
 *
 * Same split as saveBlob(). The browser path is the anchor click the two
 * callers (ShareLinkModal's QR download, PaymentPageView's PromptPay QR)
 * already used; the anchor is attached to the document while it is clicked,
 * which PaymentPageView always did and which some browsers require.
 */
export async function saveDataUrl(dataUrl: string, filename: string): Promise<void> {
  if (isNativeApp()) {
    const uri = await writeCacheFile(dataUrl.slice(dataUrl.indexOf(',') + 1), filename)
    await shareCachedFile(uri, filename)

    return
  }

  const link = document.createElement('a')
  link.href = dataUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
}
