/**
 * MOB-21 / MOB-23 (2026-10-02) — copy, share and save, in the browser and in
 * the iOS/Android app.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE WEB PORTAL CHANGES. Every function here must run the code the views
 *    had before when it is in a browser: navigator.clipboard, navigator.share,
 *    the invisible `<a download>`. Nothing errors if a refactor quietly sends
 *    the browser down a plugin path — the plugin's web fallback just behaves
 *    slightly differently, on every agent's desk at once. And no plugin may be
 *    LOADED in a browser (bundle size, and the owner's "web stays as it is").
 * 2. THE APP'S BUTTONS DO NOTHING. In a WebView `<a download>` is ignored and
 *    Android has no navigator.share — a download or share button that looks
 *    pressed and produces nothing, with no error anywhere.
 * 3. A CANCELLED SHARE SHEET SHOWS "ดาวน์โหลดไม่สำเร็จ". Closing the sheet is
 *    a choice, not a failure.
 * 4. A SERVER-SUPPLIED FILE NAME ESCAPES THE CACHE FOLDER ("../x").
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any

const native = { value: false }
const loaded = vi.fn<(name: string) => void>()
const clipboardWrite = vi.fn<AnyFn>()
const shareShare = vi.fn<AnyFn>()
const writeFile = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'ios' : 'web'),
  },
}))

vi.mock('@capacitor/clipboard', () => {
  loaded('clipboard')

  return { Clipboard: { write: (o: unknown) => clipboardWrite(o) } }
})

vi.mock('@capacitor/share', () => {
  loaded('share')

  return { Share: { share: (o: unknown) => shareShare(o) } }
})

vi.mock('@capacitor/filesystem', () => {
  loaded('filesystem')

  return {
    Directory: { Cache: 'CACHE' },
    Filesystem: { writeFile: (o: unknown) => writeFile(o) },
  }
})

import { canOpenShareSheet, copyText, shareImage, shareLink } from '../share'
import { isShareCancel, safeFileName, saveBlob, saveDataUrl } from '../files'

const PNG = 'data:image/png;base64,iVBORw0KGgo='

beforeEach(() => {
  native.value = false
  loaded.mockClear()
  clipboardWrite.mockReset().mockResolvedValue(undefined)
  shareShare.mockReset().mockResolvedValue({})
  writeFile.mockReset().mockResolvedValue({ uri: 'file:///cache/shared/x.png' })
})

describe('in a browser — exactly the code the views had', () => {
  it('copies with navigator.clipboard and loads no plugin', async () => {
    const writeText = vi.fn<AnyFn>().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })

    await copyText('https://partner.example/p/abc')

    expect(writeText).toHaveBeenCalledWith('https://partner.example/p/abc')
    expect(clipboardWrite).not.toHaveBeenCalled()
    expect(loaded).not.toHaveBeenCalled()
  })

  it('still rejects when the browser clipboard refuses — callers own the message', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: vi.fn<AnyFn>().mockRejectedValue(new Error('denied')) },
      configurable: true,
    })

    await expect(copyText('x')).rejects.toThrow('denied')
  })

  it('keeps the 2026-08-21 rule for the share button: API present AND a touch screen', () => {
    Object.defineProperty(navigator, 'share', { value: vi.fn<AnyFn>(), configurable: true })
    expect(canOpenShareSheet(true)).toBe(true)
    expect(canOpenShareSheet(false)).toBe(false)

    Object.defineProperty(navigator, 'share', { value: undefined, configurable: true })
    expect(canOpenShareSheet(true)).toBe(false)
  })

  it('shares a link with navigator.share', async () => {
    const share = vi.fn<AnyFn>().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'share', { value: share, configurable: true })

    await shareLink({ title: 'สินค้า', url: 'https://partner.example/p/abc' })

    expect(share).toHaveBeenCalledWith({ title: 'สินค้า', url: 'https://partner.example/p/abc' })
    expect(shareShare).not.toHaveBeenCalled()
  })

  it('shares the QR as a File where the browser can, else falls back to the link', async () => {
    const share = vi.fn<AnyFn>().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'share', { value: share, configurable: true })
    const realFetch = globalThis.fetch
    globalThis.fetch = vi
      .fn<AnyFn>()
      .mockResolvedValue({ blob: async () => new Blob(['x'], { type: 'image/png' }) })

    try {
      Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true })
      await shareImage({
        dataUrl: PNG,
        filename: 'qr-code.png',
        title: 'QR',
        fallbackUrl: 'https://l',
      })
      const first = share.mock.calls[0]?.[0] as { files?: File[] }
      expect(first.files?.[0]?.name).toBe('qr-code.png')

      Object.defineProperty(navigator, 'canShare', { value: undefined, configurable: true })
      await shareImage({
        dataUrl: PNG,
        filename: 'qr-code.png',
        title: 'QR',
        fallbackUrl: 'https://l',
      })
      expect(share).toHaveBeenLastCalledWith({ title: 'QR', url: 'https://l' })
    } finally {
      globalThis.fetch = realFetch
    }
    expect(loaded).not.toHaveBeenCalled()
  })

  it('saves a download with the invisible <a download> click', async () => {
    const clicks: string[] = []
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      clicks.push(this.download)
    })
    URL.createObjectURL = () => 'blob:stub'
    URL.revokeObjectURL = () => {}

    await saveBlob(new Blob(['%PDF']), 'สลิป.pdf')
    await saveDataUrl(PNG, 'promptpay-1.png')

    expect(clicks).toEqual(['สลิป.pdf', 'promptpay-1.png'])
    expect(writeFile).not.toHaveBeenCalled()
    expect(loaded).not.toHaveBeenCalled()
    click.mockRestore()
  })
})

describe('inside the app — native clipboard, share sheet, cache file', () => {
  beforeEach(() => {
    native.value = true
  })

  it('copies through @capacitor/clipboard, never navigator.clipboard', async () => {
    const writeText = vi.fn<AnyFn>()
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })

    await copyText('1234567890')

    expect(clipboardWrite).toHaveBeenCalledWith({ string: '1234567890' })
    expect(writeText).not.toHaveBeenCalled()
  })

  it('always offers the share button — Android WebView has no navigator.share', () => {
    Object.defineProperty(navigator, 'share', { value: undefined, configurable: true })

    expect(canOpenShareSheet(false)).toBe(true)
  })

  it('opens the native sheet for a link', async () => {
    await shareLink({ title: 'สินค้า', url: 'https://partner.example/p/abc' })

    expect(shareShare).toHaveBeenCalledWith(
      expect.objectContaining({ title: 'สินค้า', url: 'https://partner.example/p/abc' }),
    )
  })

  it('shares the QR as a real file from the cache folder', async () => {
    await shareImage({
      dataUrl: PNG,
      filename: 'qr-code.png',
      title: 'QR',
      fallbackUrl: 'https://l',
    })

    expect(writeFile).toHaveBeenCalledWith({
      path: 'shared/qr-code.png',
      data: 'iVBORw0KGgo=',
      directory: 'CACHE',
      recursive: true,
    })
    expect(shareShare).toHaveBeenCalledWith(
      expect.objectContaining({ files: ['file:///cache/shared/x.png'] }),
    )
  })

  it('saves a download by writing it to the cache and opening the share sheet', async () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')

    await saveBlob(new Blob(['%PDF-1.7']), 'slip-ORD-1.jpg')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({ path: 'shared/slip-ORD-1.jpg', directory: 'CACHE' }),
    )
    const written = writeFile.mock.calls[0]?.[0] as { data: string } | undefined
    expect(written?.data).toBe(btoa('%PDF-1.7'))
    expect(shareShare).toHaveBeenCalledWith(
      expect.objectContaining({ files: ['file:///cache/shared/x.png'] }),
    )
    expect(click).not.toHaveBeenCalled()
    click.mockRestore()
  })

  it('treats closing the share sheet as a choice, not a failed download', async () => {
    shareShare.mockRejectedValue(new Error('Share canceled'))
    await expect(saveDataUrl(PNG, 'qr.png')).resolves.toBeUndefined()

    shareShare.mockRejectedValue(new Error('disk full'))
    await expect(saveDataUrl(PNG, 'qr.png')).rejects.toThrow('disk full')
  })
})

describe('file names from the server', () => {
  it('cannot climb out of the cache folder, and keeps Thai names readable', () => {
    expect(safeFileName('../../etc/passwd')).toBe('.._.._etc_passwd')
    expect(safeFileName('a/b\\c:d.pdf')).toBe('a_b_c_d.pdf')
    expect(safeFileName('..')).toBe('download')
    expect(safeFileName('')).toBe('download')
    expect(safeFileName('สลิปโอนเงิน.jpg')).toBe('สลิปโอนเงิน.jpg')
  })

  it('recognises the cancel wording of both platforms', () => {
    expect(isShareCancel(new Error('Share canceled'))).toBe(true)
    expect(isShareCancel(new Error('User cancelled'))).toBe(true)
    expect(isShareCancel(new Error('No space left'))).toBe(false)
  })
})
