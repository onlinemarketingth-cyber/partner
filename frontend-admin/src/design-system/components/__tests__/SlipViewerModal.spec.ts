/**
 * 2026-09-10 (human: "แก้ปุ่มดูสลิป ตอนนี้เป็นการ download เปลี่ยนเป็น Modal
 * ดูสลิป").
 *
 * Checking a slip is the most common thing anyone does on the payments
 * screen, and it used to mean: download a file, find it, open it in another
 * app, compare it against a row you can no longer see, delete it.
 *
 * What is pinned here is the part that is easy to get subtly wrong — the
 * slip is behind an access-checked endpoint, so it cannot be an `<img src>`;
 * the bytes have to be fetched with credentials, and the object URL they
 * become has to be released, because a customer's bank record left in
 * memory is a customer's bank record left in memory.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const getBlob = vi.fn()
const download = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    constructor(readonly status: number) {
      super(`api ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    getBlob: (...args: unknown[]) => getBlob(...args),
    download: (...args: unknown[]) => download(...args),
  },
  ApiError: ApiErrorStub,
}))

import SlipViewerModal from '../SlipViewerModal.vue'

const revoked: string[] = []

async function mountModal(blobType = 'image/jpeg') {
  getBlob.mockResolvedValue(new Blob(['slip-bytes'], { type: blobType }))

  const wrapper = mount(SlipViewerModal, {
    props: { orderId: 1, orderNumber: 'ORD-0001' },
    global: { stubs: { Icon: true } },
    attachTo: document.body,
  })
  await flushPromises()

  return wrapper
}

describe('looking at a slip', () => {
  beforeEach(() => {
    getBlob.mockReset()
    download.mockReset()
    revoked.length = 0
    document.body.innerHTML = ''
    URL.createObjectURL = () => 'blob:slip'
    URL.revokeObjectURL = (u: string) => { revoked.push(u) }
  })

  it('fetches the protected file and shows it inline', async () => {
    // A plain <img src> cannot: GET /orders/{id}/slip is access-checked, and
    // the browser would not send the session with it.
    const w = await mountModal()

    expect(getBlob).toHaveBeenCalledWith('/orders/1/slip')
    expect(document.querySelector('[data-test="slip-image"]')?.getAttribute('src')).toBe('blob:slip')

    w.unmount()
  })

  it('does not download anything just to be looked at', async () => {
    const w = await mountModal()

    expect(download).not.toHaveBeenCalled()

    w.unmount()
  })

  it('still offers the download, for whoever wants the file', async () => {
    // Nothing was taken away — it moved inside, as a secondary action.
    const w = await mountModal()

    await document.querySelector<HTMLButtonElement>('[data-test="download-slip"]')?.click()

    expect(download).toHaveBeenCalledWith('/orders/1/slip', 'slip-ORD-0001.jpg')

    w.unmount()
  })

  it('releases the object url when it closes', async () => {
    const w = await mountModal()

    w.unmount()

    expect(revoked).toContain('blob:slip')
  })

  it('says an order has no slip rather than showing a broken image', async () => {
    getBlob.mockRejectedValue(new ApiErrorStub(404))

    const w = mount(SlipViewerModal, {
      props: { orderId: 1, orderNumber: 'ORD-0001' },
      global: { stubs: { Icon: true } },
      attachTo: document.body,
    })
    await flushPromises()

    expect(document.querySelector('[data-test="slip-error"]')?.textContent).toContain('ไม่มีสลิปแนบไว้')

    w.unmount()
  })

  it('says so when the file is not an image at all', async () => {
    // Nothing stops a future upload path accepting a PDF, and an <img>
    // pointed at one shows a broken icon — which reads as "the slip is
    // corrupt" rather than "this one is a PDF".
    const w = await mountModal('application/pdf')

    expect(document.querySelector('[data-test="slip-image"]')).toBeNull()
    expect(document.body.textContent).toContain('ไม่ใช่รูปภาพ')

    w.unmount()
  })
})
