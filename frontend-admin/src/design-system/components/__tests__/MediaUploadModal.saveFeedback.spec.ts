/**
 * ADR-052 — MediaUploadModal says "saved" ONCE per user action: once per
 * batch of files (never once per file), once per link — and only after the
 * caller's list behind it has been re-read (`refresh`).
 */
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import MediaUploadModal from '../MediaUploadModal.vue'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

function deferred<T = unknown>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

type ModalProps = InstanceType<typeof MediaUploadModal>['$props']

function mountModal(props: Partial<ModalProps> & Pick<ModalProps, 'uploadFn'>) {
  return mount(MediaUploadModal, {
    props: { title: 'อัปโหลด', accept: '*', ...props },
    global: { stubs: { Icon: true } },
  })
}

function drop(w: ReturnType<typeof mountModal>, names: string[]) {
  const input = w.find('input[type="file"]')
  const files = names.map((n) => new File(['x'], n, { type: 'application/pdf' }))
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })

  return input.trigger('change')
}

describe('MediaUploadModal — ADR-052', () => {
  it('raises ONE dialog for a batch of files, after every file landed AND the refresh finished', async () => {
    const uploads = [deferred(), deferred()]
    let call = 0
    const uploadFn = vi.fn(() => ({ promise: uploads[call++]!.promise, abort: () => {} }))
    const refresh = deferred()
    const refreshFn = vi.fn(() => refresh.promise)
    const w = mountModal({ uploadFn, refresh: refreshFn })

    await drop(w, ['a.pdf', 'b.pdf'])
    uploads[0]!.resolve({})
    await flushPromises()
    // One of two done — per-file status only, no dialog yet.
    expect(w.emitted('uploaded')).toHaveLength(1)
    expect(saveFeedbackState.show).toBe(false)

    uploads[1]!.resolve({})
    await flushPromises()
    expect(refreshFn).toHaveBeenCalledTimes(1)
    // The list behind has not been re-read yet.
    expect(saveFeedbackState.show).toBe(false)

    refresh.resolve(undefined)
    await flushPromises()
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('อัปโหลดแล้ว 2 ไฟล์')
  })

  it('a batch with a failed file raises no dialog and keeps the per-file error', async () => {
    let call = 0
    const uploadFn = vi.fn(() => ({
      promise: call++ === 0 ? Promise.resolve({}) : Promise.reject(new Error('ไฟล์ใหญ่เกินไป')),
      abort: () => {},
    }))
    const refreshFn = vi.fn(async () => {})
    const w = mountModal({ uploadFn, refresh: refreshFn })

    await drop(w, ['a.pdf', 'b.pdf'])
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(refreshFn).not.toHaveBeenCalled()
    expect(w.text()).toContain('ไฟล์ใหญ่เกินไป')
  })

  it('a link raises its own dialog after the refresh; a failing link raises none', async () => {
    const embedFn = vi.fn().mockRejectedValueOnce(new Error('ลิงก์ไม่ถูกต้อง')).mockResolvedValueOnce({})
    const refreshFn = vi.fn(async () => {})
    const w = mountModal({ uploadFn: vi.fn(() => ({ promise: Promise.resolve({}), abort: () => {} })), embedFn, refresh: refreshFn })

    await w.find('input[type="url"]').setValue('https://bad')
    await w.findAll('button').find((b) => b.text().includes('เพิ่มลิงก์'))!.trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)
    expect(w.text()).toContain('ลิงก์ไม่ถูกต้อง')

    await w.find('input[type="url"]').setValue('https://youtu.be/x')
    await w.findAll('button').find((b) => b.text().includes('เพิ่มลิงก์'))!.trigger('click')
    await flushPromises()
    expect(refreshFn).toHaveBeenCalledTimes(1)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('เพิ่มลิงก์แล้ว')
  })

  it('says the screen may be behind when the re-read fails after a successful upload', async () => {
    const uploadFn = vi.fn(() => ({ promise: Promise.resolve({}), abort: () => {} }))
    const w = mountModal({ uploadFn, refresh: vi.fn(async () => { throw new Error('reload failed') }) })

    await drop(w, ['a.pdf'])
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})
