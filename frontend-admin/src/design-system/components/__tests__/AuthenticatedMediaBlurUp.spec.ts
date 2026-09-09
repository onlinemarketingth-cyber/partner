/**
 * 2026-09-09 (human: "หน้า frontend load รูปมาที่หลังประสบการณ์ไม่ดี
 * ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ จนโหลดเสร็จได้หรือไม่").
 *
 * Every product image in this app sits behind a Sanctum-protected stream,
 * so the browser cannot point an <img> at it: the app fetches it and turns
 * it into a blob. Until that finishes there is a grey box where the photo
 * goes, and a grid of grey boxes popping into photos one at a time is
 * exactly what the human is describing.
 *
 * The API now sends a ~20px base64 copy of each picture inline with the
 * list (App\Support\Media\ImageThumbnailer::placeholder), which needs no
 * request and no authorisation — so it can be painted in the first frame
 * and sharpen into the real photo when that lands.
 *
 * ── WHAT IS PINNED HERE ──
 *
 * That the blurred copy is SHOWN BEFORE the fetch resolves (the whole
 * point), that the real image replaces it afterwards, and — the part most
 * likely to be broken by a later edit — that a caller which passes no
 * placeholder still gets the plain grey box this component has always
 * shown, rather than a broken <img> pointing at nothing.
 */
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AuthenticatedMedia from '../AuthenticatedMedia.vue'

const TINY = 'data:image/webp;base64,UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4H'

/** A fetch that stays pending until the test releases it. */
function deferredFetch() {
  let release: () => void = () => {}
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })

  // @ts-expect-error test stub
  globalThis.fetch = vi.fn(async () => {
    await gate
    return { ok: true, status: 200, blob: async () => new Blob(['x']) }
  })

  return { release }
}

/*
 * A DIFFERENT url per mount, deliberately.
 *
 * useAuthenticatedMedia caches resolved blobs at module scope, keyed by
 * source url — on purpose, so two cards showing the same photo share one
 * fetch (TASK-223). Reusing one url here would mean the second test warms
 * the cache for the third, which would then resolve instantly and never
 * exercise the loading state it exists to check.
 */
let nextId = 0
const mountMedia = (placeholder: string | null) =>
  mount(AuthenticatedMedia, {
    props: {
      src: `/api/v1/product-media/${++nextId}/thumbnail`,
      placeholder,
      class: 'w-full h-full object-cover',
    },
    global: { stubs: { Icon: true } },
  })

describe('the blurred copy carries the wait', () => {
  beforeEach(() => {
    URL.createObjectURL = () => 'blob:real-photo'
    URL.revokeObjectURL = () => {}
  })

  it('paints the blurred copy while the real photo is still being fetched', async () => {
    const { release } = deferredFetch()
    const w = mountMedia(TINY)
    await flushPromises()

    const img = w.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe(TINY)
    expect(img.classes()).toContain('am-blur')

    // The caller's own sizing classes must survive — the blur is an extra
    // class on the same element, not a replacement for it.
    expect(img.classes()).toContain('object-cover')

    release()
  })

  it('sharpens into the real photo once it arrives', async () => {
    const { release } = deferredFetch()
    const w = mountMedia(TINY)
    await flushPromises()

    release()
    await flushPromises()

    const img = w.find('img')
    expect(img.attributes('src')).toBe('blob:real-photo')
    expect(img.classes()).not.toContain('am-blur')
  })

  it('still shows the plain box when there is no blurred copy', async () => {
    /*
     * The regression that would be worst: an <img> with no src at all,
     * which every browser draws as a broken-image icon — visibly worse
     * than the grey box it replaced. A video, a product with no photos,
     * and an image GD could not read all reach this branch.
     */
    const { release } = deferredFetch()
    const w = mountMedia(null)
    await flushPromises()

    expect(w.find('img').exists()).toBe(false)
    expect(w.find('div').exists()).toBe(true)

    release()
  })
})
