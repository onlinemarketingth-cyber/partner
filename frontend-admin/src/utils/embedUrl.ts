/**
 * embedUrl — the one place that turns a pasted media URL into something an
 * `<iframe>` will actually render.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A `youtube.com/watch?v=<id>` URL responds with `X-Frame-Options: SAMEORIGIN`
 * and refuses to be framed — the user gets a dead grey box, with no error the
 * page can see. Only `youtube.com/embed/<id>` is framable. Three surfaces had
 * each grown their own private copy of that rewrite (ProductShareView,
 * AttachmentLightbox, MediaPreviewModal) and Academy — the one screen BR-1
 * gates selling on — was the place that missed it. Four copies of a rule is
 * four chances for the fourth screen to be wrong, so it lives here now.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * `frontend/src/utils/embedUrl.ts` and `frontend-admin/src/utils/embedUrl.ts`
 * are byte-identical copies. The two apps deliberately do not share a package
 * yet; a learner and the admin previewing that learner's screen MUST
 * normalise a URL identically, or the preview lies about whether the lesson
 * plays. Change one, copy it to the other in the same PR.
 * ─────────────────────────────────────────────────────────────────────
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * Normalisation only fixes the hosts we recognise. Any site may send
 * `X-Frame-Options`/`frame-ancestors` and there is NO reliable way to detect
 * that failure from JavaScript — the iframe's `load` event fires either way
 * and the document inside is cross-origin, so we cannot inspect it. So every
 * caller that renders an iframe must ALSO offer a visible "open in a new tab"
 * escape, exactly as AttachmentLightbox does for iOS Safari / LINE / Facebook
 * in-app browsers. `classifyEmbedUrl()` exists so an authoring UI can warn
 * about a likely-unframable URL up front; it is a hint, never a gate.
 *
 * Presentation only. Nothing here validates a URL, decides what a lesson is,
 * or talks to the API.
 */

/**
 * `youtu.be/<id>`, `youtube.com/watch?v=<id>` (with the id anywhere in the
 * query), `/embed/<id>`, `/shorts/<id>`, `/live/<id>`, plus the
 * youtube-nocookie.com privacy domain.
 */
const YOUTUBE_ID_PATTERN =
  /(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^#]*&)?v=|embed\/|shorts\/|live\/))([a-zA-Z0-9_-]{6,})/

/** Any YouTube host — used for "show the YouTube badge", NOT for framability. */
const YOUTUBE_HOST_PATTERN = /(?:youtube(?:-nocookie)?\.com|youtu\.be)/i

/**
 * URLs that already look like a purpose-built embed endpoint. Deliberately a
 * short, conservative list: a false "this is fine" is worse than a warning an
 * admin can ignore, because the admin can check the preview but the learner
 * cannot fix anything.
 */
const ALREADY_EMBED_PATTERNS: RegExp[] = [
  /\/embed(?:\/|\?|$)/i, // youtube.com/embed/, dailymotion.com/embed/video/, ...
  /player\.vimeo\.com\/video\//i,
  /drive\.google\.com\/file\/d\/[^/]+\/preview/i,
  /facebook\.com\/plugins\//i,
]

/** How a URL will behave when we try to put it in an iframe. */
export type EmbedUrlKind =
  /** A YouTube link — we can rewrite it into a form that frames reliably. */
  | 'youtube'
  /** Already an embed-shaped URL from a host that publishes one. */
  | 'embed'
  /** Unrecognised. May well refuse to be framed; warn, do not block. */
  | 'unknown'

/** The YouTube video id, or null if this is not a YouTube URL. */
export function youtubeId(url: string | null | undefined): string | null {
  if (!url) return null

  return url.match(YOUTUBE_ID_PATTERN)?.[1] ?? null
}

/** True for any YouTube URL, whether or not an id could be extracted. */
export function isYoutubeUrl(url: string | null | undefined): boolean {
  if (!url) return false

  return YOUTUBE_HOST_PATTERN.test(url)
}

/**
 * The canonical embed URL for `url`.
 *
 * YouTube watch / youtu.be / shorts / live / embed links become
 * `https://www.youtube.com/embed/<id>`. **Anything else is returned
 * unchanged** — guessing a rewrite for a host we do not know would break URLs
 * that already worked. Null/empty in, empty string out (an `<iframe src="">`
 * renders blank rather than navigating).
 */
export function toEmbedUrl(url: string | null | undefined): string {
  if (!url) return ''
  const id = youtubeId(url)

  return id ? `https://www.youtube.com/embed/${id}` : url
}

/**
 * `img.youtube.com/vi/<id>/hqdefault.jpg` — a static, token-free CDN path, so
 * it works from a public page with no API key. Null for non-YouTube URLs;
 * there is no unauthenticated equivalent for most other hosts.
 */
export function youtubeThumbnailUrl(url: string | null | undefined): string | null {
  const id = youtubeId(url)

  return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : null
}

/** Classify a URL for authoring-time guidance. Never a gate — see the header. */
export function classifyEmbedUrl(url: string | null | undefined): EmbedUrlKind {
  const value = url?.trim()
  if (!value) return 'unknown'
  if (youtubeId(value)) return 'youtube'
  if (ALREADY_EMBED_PATTERNS.some((pattern) => pattern.test(value))) return 'embed'

  return 'unknown'
}
