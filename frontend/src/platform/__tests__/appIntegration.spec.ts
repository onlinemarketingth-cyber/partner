/**
 * MOB-21 / MOB-23 / MOB-25 / MOB-28 (2026-10-02) — the existing pieces that
 * now call platform/: the API client and the share sheet.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. DELETE /me/devices LOSES ITS BODY. The token identifies the row; a
 *    DELETE without it removes nothing, returns 204 or 422, and the phone
 *    keeps receiving pushes for an account that signed out. Every OTHER
 *    DELETE must still go out with no body at all.
 * 2. A DOWNLOAD IN THE APP DOES NOTHING. api.download() used to end in an
 *    `<a download>` click, which a WebView ignores — the slip download button
 *    on PipelineBoard would look pressed and produce nothing.
 * 3. THE ANDROID APP HAS NO SHARE BUTTON. ShareLinkModal hid it whenever
 *    navigator.share was missing — which it always is in Android's WebView.
 * 4. BACK LEAVES THE PAGE UNDER AN OPEN SHARE SHEET.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { flushPromises, mount } from '@vue/test-utils'

const native = { value: false }
const writeFile = vi.fn<AnyFn>()
const shareShare = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'android' : 'web'),
  },
}))
vi.mock('@capacitor/filesystem', () => ({
  Directory: { Cache: 'CACHE' },
  Filesystem: { writeFile: (o: unknown) => writeFile(o) },
}))
vi.mock('@capacitor/share', () => ({ Share: { share: (o: unknown) => shareShare(o) } }))
vi.mock('@capacitor/clipboard', () => ({ Clipboard: { write: vi.fn<AnyFn>() } }))
vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { get: vi.fn<AnyFn>(), set: vi.fn<AnyFn>(), remove: vi.fn<AnyFn>() },
}))

import { api } from '@/api/client'
import ShareLinkModal from '@/design-system/components/ShareLinkModal.vue'
import { closeTopmost, openOverlayCount, resetBackStack } from '../backStack'

const fetchMock = vi.fn<AnyFn>()

function jsonResponse(status: number, body: unknown = null): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: body === null ? {} : { 'content-type': 'application/json' },
  })
}

/** jsdom's Blob is not Node's, so a real Response cannot carry one. */
function fileResponse() {
  return { ok: true, status: 200, headers: new Headers(), blob: async () => new Blob(['jpg']) }
}

beforeEach(() => {
  native.value = false
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  writeFile.mockReset().mockResolvedValue({ uri: 'file:///cache/shared/slip.jpg' })
  shareShare.mockReset().mockResolvedValue({})
  resetBackStack()
})

describe('api.delete', () => {
  it('sends a JSON body only when one is given', async () => {
    fetchMock.mockResolvedValue(jsonResponse(204))

    await api.delete('/me/devices', undefined, { token: 'fcm-1' })
    await api.delete('/me/avatar')

    const [withBody, withoutBody] = fetchMock.mock.calls.map((c) => c[1] as RequestInit)
    expect(withBody?.method).toBe('DELETE')
    expect(withBody?.body).toBe(JSON.stringify({ token: 'fcm-1' }))
    expect(new Headers(withBody?.headers).get('Content-Type')).toBe('application/json')
    expect(withoutBody?.body).toBeUndefined()
  })
})

describe('api.download', () => {
  it('browser: the same invisible link click as before', async () => {
    fetchMock.mockResolvedValue(fileResponse())
    URL.createObjectURL = () => 'blob:x'
    URL.revokeObjectURL = () => {}
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    await api.download('/orders/1/slip', 'slip-ORD-1.jpg')

    expect(click).toHaveBeenCalledTimes(1)
    expect(writeFile).not.toHaveBeenCalled()
    click.mockRestore()
  })

  it('app: cache file + share sheet, no link click', async () => {
    native.value = true
    fetchMock.mockResolvedValue(fileResponse())
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')

    await api.download('/orders/1/slip', 'slip-ORD-1.jpg')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({ path: 'shared/slip-ORD-1.jpg', directory: 'CACHE' }),
    )
    expect(shareShare).toHaveBeenCalledWith(
      expect.objectContaining({ files: ['file:///cache/shared/slip.jpg'] }),
    )
    expect(click).not.toHaveBeenCalled()
    click.mockRestore()
  })
})

describe('ShareLinkModal inside the app', () => {
  function mountSheet() {
    Reflect.deleteProperty(navigator, 'share')
    window.matchMedia = (() => ({ matches: false })) as unknown as typeof window.matchMedia

    return mount(ShareLinkModal, {
      props: { show: true, url: 'https://partner.example/j/abc', heading: 'ชวนเข้าทีม' },
      global: { stubs: { Icon: true, Teleport: true } },
    })
  }

  it('shows the share button even without navigator.share, and uses the native sheet', async () => {
    native.value = true
    const wrapper = mountSheet()

    const share = wrapper.findAll('button').find((b) => b.text().includes('แชร์'))
    expect(share).toBeDefined()
    await share?.trigger('click')
    await flushPromises()

    expect(shareShare).toHaveBeenCalledWith(
      expect.objectContaining({ url: 'https://partner.example/j/abc', title: 'ชวนเข้าทีม' }),
    )
  })

  it('browser without navigator.share: still no share button (unchanged)', () => {
    const wrapper = mountSheet()

    expect(wrapper.findAll('button').some((b) => b.text().trim() === 'แชร์')).toBe(false)
  })

  it('registers with the back button while open', async () => {
    const wrapper = mountSheet()
    await flushPromises()
    expect(openOverlayCount()).toBe(1)

    closeTopmost()

    expect(wrapper.emitted('update:show')?.[0]).toEqual([false])
  })
})
