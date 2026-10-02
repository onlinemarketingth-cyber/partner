/**
 * CertTierPanel — ADR-052, "saved" is said once, and only when true.
 *
 * Cert tiers are shared by every company, so an admin who cannot tell whether
 * a tier was created is an admin who creates it twice. Both writes here used
 * to close the form / drop the row and say nothing. They now raise the shared
 * dialog — after the request resolved AND the list was re-read — naming the
 * tier as the SERVER stored it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { saveFeedbackState, SAVED_BUT_STALE_BODY } from '@/composables/useSaveFeedback'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      message: string,
    ) {
      super(message)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

import CertTierPanel from '../CertTierPanel.vue'

const BASIC = { id: 1, key: 'basic', name: 'Basic', sort_order: 0, is_mandatory: true }

let serverTiers: (typeof BASIC)[] = []

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountPanel() {
  const wrapper = mount(CertTierPanel, { global: { stubs: { Icon: true } } })
  await flushPromises()

  return wrapper
}

async function fillCreateForm(wrapper: VueWrapper) {
  await wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่มระดับ'))!.trigger('click')
  await wrapper.find('input[placeholder="เช่น ระดับพื้นฐาน"]').setValue('ระดับกลาง ')
  await wrapper.find('input[placeholder="basic"]').setValue('intermediate')
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  serverTiers = [BASIC]
  get.mockImplementation(() => Promise.resolve({ data: serverTiers }))
})

describe('CertTierPanel — ADR-052', () => {
  it('create: the dialog appears only after the POST resolved, naming the tier the SERVER stored', async () => {
    const wrapper = await mountPanel()
    await fillCreateForm(wrapper)

    const write = deferred<unknown>()
    post.mockReturnValueOnce(write.promise)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const stored = { id: 2, key: 'intermediate', name: 'Intermediate (ระดับกลาง)', sort_order: 1, is_mandatory: false }
    serverTiers = [BASIC, stored]
    write.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มระดับใบรับรอง “Intermediate (ระดับกลาง)” แล้ว')
    // The list on screen is the re-read one, carrying the server's name.
    expect(wrapper.text()).toContain('Intermediate (ระดับกลาง)')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('a failing save raises NO dialog and shows the server error; the form stays open', async () => {
    const wrapper = await mountPanel()
    await fillCreateForm(wrapper)

    post.mockRejectedValueOnce(new FakeApiError(422, 'รหัสนี้ถูกใช้แล้ว'))
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('รหัสนี้ถูกใช้แล้ว')
    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.emitted('changed')).toBeUndefined()
  })

  it('edit: the dialog names the server-stored tier', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="แก้ไข"]').trigger('click')
    await wrapper.find('input[placeholder="เช่น ระดับพื้นฐาน"]').setValue('basic ที่พิมพ์')

    put.mockImplementationOnce(() => {
      serverTiers = [{ ...BASIC, name: 'Basic (แก้แล้ว)' }]

      return Promise.resolve({ data: serverTiers[0] })
    })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/cert-tiers/1', expect.objectContaining({ name: 'basic ที่พิมพ์' }))
    expect(saveFeedbackState.body).toBe('บันทึกระดับใบรับรอง “Basic (แก้แล้ว)” แล้ว')
    expect(wrapper.text()).toContain('Basic (แก้แล้ว)')
  })

  it('delete asks first, then raises the dialog over the re-read list', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="ลบ"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    del.mockImplementationOnce(() => {
      serverTiers = []

      return Promise.resolve(undefined)
    })
    const dialog = wrapper.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
    expect(dialog).toBeDefined()
    expect(dialog!.props('variant')).toBe('danger')
    dialog!.vm.$emit('confirm')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/cert-tiers/1')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบระดับใบรับรอง “Basic” แล้ว')
    expect(wrapper.text()).toContain('ยังไม่มีระดับใบรับรอง')
  })

  it('a refused delete (422: still in use) raises no dialog and shows the server sentence', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="ลบ"]').trigger('click')

    del.mockRejectedValueOnce(new FakeApiError(422, 'ยังมีโมดูล 3 รายการผูกอยู่'))
    wrapper.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)!.vm.$emit('confirm')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ยังมีโมดูล 3 รายการผูกอยู่')
  })

  it('a save whose re-read fails says it saved but the list may be behind', async () => {
    const wrapper = await mountPanel()
    await fillCreateForm(wrapper)

    post.mockImplementationOnce(() => {
      get.mockRejectedValue(new FakeApiError(500, 'down'))

      return Promise.resolve({ data: { id: 2, key: 'intermediate', name: 'Intermediate' } })
    })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})
