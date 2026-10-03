/**
 * MOB-13 — "เวอร์ชันแอปมือถือ" (Super Admin).
 *
 * WHAT BREAKS SILENTLY HERE:
 *  1. A save that says "saved" before the server stored it (ADR-052) — the
 *     modal must wait for the PUT, and the card must then show the SERVER's
 *     values, not what was typed.
 *  2. An emptied field sent as '' instead of null. The backend treats '' as
 *     an invalid version; null is "no policy". A cleared minimum that never
 *     clears is an app that keeps forcing an update nobody wants.
 *  3. One platform's save touching the other's card.
 *  4. A failed load showing an empty form, which reads as "no policy set".
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

import AppVersionSettingsView from '../AppVersionSettingsView.vue'
import { ApiError } from '@/api/client'
import { resetSaveFeedback, saveFeedbackState } from '@/composables/useSaveFeedback'

const STORED = [
  {
    platform: 'ios',
    min_supported_version: '1.0.0',
    latest_version: '1.2.0',
    store_url: 'https://apps.apple.com/app/id1',
  },
  { platform: 'android', min_supported_version: null, latest_version: null, store_url: null },
]

async function mountView(data: unknown = STORED) {
  get.mockResolvedValue({ data: structuredClone(data) })
  const wrapper = mount(AppVersionSettingsView, {
    global: {
      stubs: {
        HeroHeader: true,
        Icon: true,
        PlatformScopeBadge: true,
        InfoPopover: true,
        EmptyState: { props: ['title'], template: '<div class="empty">{{ title }}</div>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

const value = (w: Awaited<ReturnType<typeof mountView>>, id: string) =>
  (w.find(`#${id}`).element as HTMLInputElement).value

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  resetSaveFeedback()
})

describe('AppVersionSettingsView', () => {
  it('loads both platforms and fills each card from the server', async () => {
    const wrapper = await mountView()

    expect(get).toHaveBeenCalledWith('/platform/app-version-policies')
    expect(wrapper.findAll('form')).toHaveLength(2)
    expect(value(wrapper, 'ios-min')).toBe('1.0.0')
    expect(value(wrapper, 'ios-latest')).toBe('1.2.0')
    expect(value(wrapper, 'ios-store')).toBe('https://apps.apple.com/app/id1')
    expect(value(wrapper, 'android-min')).toBe('')
    // Every explanation is behind an ⓘ, never a paragraph (CLAUDE.md §7).
    expect(wrapper.findAllComponents({ name: 'InfoPopover' }).length).toBe(6)
  })

  it('shows the modal only after the PUT resolved, with the server-stored values', async () => {
    const wrapper = await mountView()
    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))

    await wrapper.find('#android-latest').setValue(' 2.0.0 ')
    await wrapper.find('form[data-platform="android"]').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    expect(put).toHaveBeenCalledWith('/platform/app-version-policies', {
      platform: 'android',
      min_supported_version: null,
      latest_version: '2.0.0',
      store_url: null,
    })

    resolvePut({
      data: {
        platform: 'android',
        min_supported_version: null,
        latest_version: '2.0.0',
        store_url: 'https://play.google.com/store/apps/details?id=x',
      },
    })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกนโยบายเวอร์ชัน Android (Google Play) แล้ว')
    expect(value(wrapper, 'android-store')).toBe('https://play.google.com/store/apps/details?id=x')
    // The other card is untouched.
    expect(value(wrapper, 'ios-latest')).toBe('1.2.0')
  })

  it('sends a cleared field as null, not an empty string', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: { ...STORED[0], min_supported_version: null } })

    await wrapper.find('#ios-min').setValue('')
    await wrapper.find('form[data-platform="ios"]').trigger('submit')
    await flushPromises()

    expect(put.mock.calls[0]![1]).toMatchObject({ platform: 'ios', min_supported_version: null })
    expect(value(wrapper, 'ios-min')).toBe('')
  })

  it('a rejected save raises no modal and shows the validation message on that card only', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(
      new ApiError(422, {
        errors: { min_supported_version: ['เวอร์ชันขั้นต่ำต้องไม่สูงกว่าเวอร์ชันล่าสุด'] },
      }),
    )

    await wrapper.find('#ios-min').setValue('9.0.0')
    await wrapper.find('form[data-platform="ios"]').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('form[data-platform="ios"]').text()).toContain(
      'เวอร์ชันขั้นต่ำต้องไม่สูงกว่าเวอร์ชันล่าสุด',
    )
    expect(wrapper.find('form[data-platform="android"]').text()).not.toContain('ต้องไม่สูงกว่า')
    // What the admin typed stays, so they can fix it.
    expect(value(wrapper, 'ios-min')).toBe('9.0.0')
  })

  it('a failed load shows an error with a retry, never an empty form', async () => {
    get.mockRejectedValueOnce(new ApiError(403, null))
    const wrapper = mount(AppVersionSettingsView, {
      global: {
        stubs: { HeroHeader: true, Icon: true, PlatformScopeBadge: true, InfoPopover: true },
      },
    })
    await flushPromises()

    expect(wrapper.findAll('form')).toHaveLength(0)
    expect(wrapper.text()).toContain('โหลดนโยบายเวอร์ชันไม่สำเร็จ (403)')

    get.mockResolvedValueOnce({ data: structuredClone(STORED) })
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'ลองใหม่')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.findAll('form')).toHaveLength(2)
  })

  it('shows the empty state when the server has no rows', async () => {
    const wrapper = await mountView([])

    expect(wrapper.findAll('form')).toHaveLength(0)
    expect(wrapper.find('.empty').text()).toBe('ยังไม่มีนโยบายเวอร์ชัน')
  })
})
