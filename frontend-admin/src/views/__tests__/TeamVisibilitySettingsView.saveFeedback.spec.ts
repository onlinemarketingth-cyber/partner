/**
 * ADR-052 — "ตั้งค่าทีม" says it saved only after the server stored it, and
 * the radios then show the row the server stored, not what was clicked.
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

import TeamVisibilitySettingsView from '../TeamVisibilitySettingsView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

/** `noUncheckedIndexedAccess` — fail loudly when an expected element is missing. */
function nth<T>(items: T[], index: number): T {
  const item = items[index < 0 ? items.length + index : index]
  if (item === undefined) throw new Error(`no element at index ${index}`)

  return item
}

async function mountView() {
  get.mockResolvedValue({ data: { client_visibility_level: 'counts_only', is_enabled: true, recruit_policy: 'all_certified' } })
  const wrapper = mount(TeamVisibilitySettingsView, {
    global: {
      stubs: {
        HeroHeader: true,
        CompanyScopeNotice: true,
        Icon: true,
        InfoPopover: { template: '<span class="info"><slot /></span>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

const checked = (wrapper: Awaited<ReturnType<typeof mountView>>, sel: string) =>
  (wrapper.find(sel).element as HTMLInputElement).checked

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'แอดมิน', role: 'company_admin' } as never
})

describe('TeamVisibilitySettingsView — save feedback (ADR-052)', () => {
  it('shows the modal only after the PUT resolved, with the server-stored row on screen', async () => {
    const wrapper = await mountView()
    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))

    await wrapper.find('[data-test="recruit-designated"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server kept all_certified (e.g. it refused the change) — the screen must say so.
    resolvePut({ data: { client_visibility_level: 'names', is_enabled: true, recruit_policy: 'all_certified' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าทีมแล้ว')
    expect(checked(wrapper, '[data-test="recruit-all_certified"]')).toBe(true)
    expect(checked(wrapper, '[data-test="recruit-designated"]')).toBe(false)
    expect(checked(wrapper, 'input[value="names"]')).toBe(true)
  })

  it('a failed save raises no modal and shows the error', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(new ApiError(500, null))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ (500)')
  })

  it('switching the team page off is saved with the modal and no inline "บันทึกแล้ว"', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: { client_visibility_level: 'counts_only', is_enabled: false, recruit_policy: 'all_certified' } })

    await wrapper.find('button[type="button"]').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(nth(put.mock.calls, 0)[1]).toMatchObject({ is_enabled: false })
    expect(saveFeedbackState.show).toBe(true)
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })
})
