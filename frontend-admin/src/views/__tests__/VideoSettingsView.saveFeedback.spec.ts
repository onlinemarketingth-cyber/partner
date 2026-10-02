/**
 * ADR-052 — "ตั้งค่าวิดีโอ" says it saved only after the server stored it,
 * and the form then shows what the server stored, not what was typed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown = null,
    ) {
      super(`API error ${status}`)
    }
  },
}))

import VideoSettingsView from '../VideoSettingsView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

/** `noUncheckedIndexedAccess` — fail loudly when an expected element is missing. */
function nth<T>(items: T[], index: number): T {
  const item = items[index < 0 ? items.length + index : index]
  if (item === undefined) throw new Error(`no element at index ${index}`)

  return item
}

const STORED = { max_upload_mb: 200, target_resolution: '720p', target_bitrate_kbps: 2500 }

async function mountView() {
  get.mockResolvedValue({ data: { ...STORED } })
  const wrapper = mount(VideoSettingsView, {
    global: { stubs: { HeroHeader: true, CompanyScopeNotice: true, Icon: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'แอดมิน', role: 'company_admin' } as never
})

describe('VideoSettingsView — save feedback (ADR-052)', () => {
  it('shows the modal only after the PUT resolved, with the server-stored values on screen', async () => {
    const wrapper = await mountView()
    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))

    await wrapper.find('input[type="number"]').setValue(999)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    // Request still in flight — nothing may claim it saved yet.
    expect(saveFeedbackState.show).toBe(false)

    // The server clamps/normalises: what it stored differs from what was typed.
    resolvePut({ data: { max_upload_mb: 500, target_resolution: '1080p', target_bitrate_kbps: 4000 } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกค่าตั้งวิดีโอแล้ว')
    const inputs = wrapper.findAll('input[type="number"]')
    expect((nth(inputs, 0).element as HTMLInputElement).value).toBe('500')
    expect((nth(inputs, 1).element as HTMLInputElement).value).toBe('4000')
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('1080p')
  })

  it('a failed save raises no modal and shows the error', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(new ApiError(422, null))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ')
  })

  it('no longer flashes an inline "บันทึกแล้ว" — the modal is the only success signal', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: { ...STORED } })

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.count).toBe(1)
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })
})
