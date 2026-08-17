/**
 * Client-side image resize/compression (human-requested — avatar/
 * background uploads should shrink large photos before hitting the
 * network, not just rely on the backend's 4MB/8MB size caps). Pure
 * Canvas API, no dependency. Falls back to returning the ORIGINAL file
 * untouched on any failure (unsupported type, decode error, canvas
 * producing a bigger result) — the backend's own validation
 * (UpdateAvatarRequest/UpdateBackgroundImageRequest) is always the
 * real gatekeeper, this is purely a best-effort size reduction.
 */
export interface CompressOptions {
  /** Longest edge, in px, after resize. Never upscales a smaller image. */
  maxDimension?: number
  /** JPEG quality, 0–1. */
  quality?: number
  /** Skip compression entirely if the file is already under this size. */
  skipIfUnderBytes?: number
}

export async function compressImage(file: File, options: CompressOptions = {}): Promise<File> {
  const { maxDimension = 1600, quality = 0.82, skipIfUnderBytes = 300 * 1024 } = options

  if (!file.type.startsWith('image/') || file.size <= skipIfUnderBytes) {
    return file
  }

  let bitmap: ImageBitmap
  try {
    bitmap = await createImageBitmap(file)
  } catch {
    return file // not a decodable image client-side — let the server validate/reject
  }

  const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height))
  const width = Math.round(bitmap.width * scale)
  const height = Math.round(bitmap.height * scale)

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    bitmap.close()
    return file
  }

  // White fill first — JPEG has no alpha channel, so a transparent PNG
  // would otherwise render with a black background after conversion.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)
  ctx.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
  if (!blob || blob.size >= file.size) {
    return file // compression didn't actually help — keep the original
  }

  const newName = file.name.replace(/\.\w+$/, '') + '.jpg'
  return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() })
}

/**
 * Human request (2026-07-23, Announcements image/video upload): "ให้ย่อไฟล์
 * ให้เท่ากับที่เรากำหนดไว้ใน server ไม่ขึ้น error แบบนี้" — compressImage()
 * above does a single best-effort pass; this wraps it in a retry loop that
 * progressively drops quality then dimension until the result actually fits
 * under `maxBytes` (the caller's known server-side limit), or every step has
 * been tried. Each attempt re-compresses from the ORIGINAL file (never a
 * previous attempt's output) to avoid compounding JPEG artifacts across
 * passes. If even the smallest/lowest-quality attempt still doesn't fit
 * (e.g. an enormous panorama, or a file that isn't actually decodable as an
 * image), the caller is expected to compare the returned file's size against
 * `maxBytes` itself and show a clear "still too big" message — this
 * function never throws and never silently drops the upload.
 */
export async function compressImageToFit(file: File, maxBytes: number, options: CompressOptions = {}): Promise<File> {
  if (!file.type.startsWith('image/') || file.size <= maxBytes) {
    return file
  }

  const dimensionSteps = [options.maxDimension ?? 1920, 1600, 1280, 1024, 800]
  const qualitySteps = [options.quality ?? 0.82, 0.7, 0.55, 0.4]

  let smallest = file
  for (const maxDimension of dimensionSteps) {
    for (const quality of qualitySteps) {
      const attempt = await compressImage(file, { maxDimension, quality, skipIfUnderBytes: 0 })
      if (attempt.size < smallest.size) smallest = attempt
      if (attempt.size <= maxBytes) return attempt
    }
  }
  return smallest // best effort — may still exceed maxBytes, caller must check
}
