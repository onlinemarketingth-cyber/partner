/**
 * ADR-052 — "โปรไฟล์ของฉัน": every write re-syncs the inputs from what the
 * server stored, then says it saved. The two DELETEs ask first.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const put = vi.fn()
const del = vi.fn()
const postForm = vi.fn()

vi.mock('@/api/client', () => {
  class ApiError extends Error {
    constructor(
      public status: number,
      public body: unknown = null,
    ) {
      super(`API error ${status}`)
    }
  }

  return {
    api: {
      get: vi.fn(async () => ({ data: [] })),
      post: vi.fn(),
      put: (...args: unknown[]) => put(...args),
      patch: vi.fn(),
      delete: (...args: unknown[]) => del(...args),
      postForm: (...args: unknown[]) => postForm(...args),
      download: vi.fn(),
    },
    ApiError,
  }
})

import ProfileSettingsView from '../ProfileSettingsView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

/** `noUncheckedIndexedAccess` — fail loudly when an expected element is missing. */
function nth<T>(items: T[], index: number): T {
  const item = items[index < 0 ? items.length + index : index]
  if (item === undefined) throw new Error(`no element at index ${index}`)

  return item
}

const baseUser = () =>
  ({
    id: 1,
    name: 'สมชาย ใจดี',
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    email: 'me@example.com',
    role: 'company_admin',
    avatar_url: 'https://cdn.test/a.png',
    background: { type: 'gradient', config: { color1: '#111111', color2: '#222222', angle: 90 }, image_url: null },
  }) as unknown as AuthUser

async function mountView() {
  const wrapper = mount(ProfileSettingsView, {
    global: { stubs: { HeroHeader: { template: '<div />' }, Icon: true } },
  })
  await flushPromises()

  return wrapper
}
type Wrapper = Awaited<ReturnType<typeof mountView>>

const button = (w: Wrapper, text: string) => w.findAll('button').filter((b) => b.text().trim() === text)
const textInputs = (w: Wrapper) => w.findAll('input[type="text"]')

beforeEach(() => {
  put.mockReset()
  del.mockReset()
  postForm.mockReset()
  useAuthStore().user = baseUser()
})

describe('ProfileSettingsView — save feedback (ADR-052)', () => {
  it('saving the name shows the modal only after the PUT resolved, with the server-stored name in the inputs', async () => {
    const wrapper = await mountView()
    await nth(textInputs(wrapper), 0).setValue('  สมหญิง  ')

    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))
    await nth(button(wrapper, 'บันทึกชื่อ'), 0).trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: { ...baseUser(), first_name: 'สมหญิง', last_name: 'ใจดี', name: 'สมหญิง ใจดี' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกชื่อ สมหญิง ใจดี แล้ว')
    expect((nth(textInputs(wrapper), 0).element as HTMLInputElement).value).toBe('สมหญิง')
    expect(wrapper.text()).not.toContain('บันทึกสำเร็จ')
  })

  it('a failed name save raises no modal and shows the error', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(new ApiError(422, null))

    await nth(button(wrapper, 'บันทึกชื่อ'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ — กรุณากรอกทั้งชื่อและนามสกุล')
  })

  it('removing the avatar asks first, then deletes and shows the modal', async () => {
    const wrapper = await mountView()
    await nth(button(wrapper, 'ลบรูป'), 0).trigger('click')

    expect(del).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ลบรูปโปรไฟล์ของคุณ')

    del.mockResolvedValue({ data: { ...baseUser(), avatar_url: null } })
    await nth(button(wrapper, 'ยืนยัน'), 0).trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/me/avatar')
    expect(saveFeedbackState.body).toBe('ลบรูปโปรไฟล์แล้ว')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('saving the gradient re-syncs the pickers from the server (the preview reads them)', async () => {
    const wrapper = await mountView()
    const colors = wrapper.findAll('input[type="color"]')
    await nth(colors, 0).setValue('#abcdef')
    put.mockResolvedValue({
      data: { ...baseUser(), background: { type: 'gradient', config: { color1: '#aabbcc', color2: '#222222', angle: 45 }, image_url: null } },
    })

    await nth(button(wrapper, 'ใช้พื้นหลังนี้'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('บันทึกพื้นหลังไล่สีแล้ว')
    expect((nth(colors, 0).element as HTMLInputElement).value).toBe('#aabbcc')
    expect(wrapper.text()).toContain('45°')
  })

  it('resetting the background asks first; a failure shows an error and no modal', async () => {
    const wrapper = await mountView()
    await nth(button(wrapper, 'รีเซ็ตเป็นค่าเริ่มต้น'), 0).trigger('click')
    expect(del).not.toHaveBeenCalled()

    del.mockRejectedValue(new ApiError(500, null))
    await nth(button(wrapper, 'ยืนยัน'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('รีเซ็ตพื้นหลังไม่สำเร็จ')
  })

  it('a successful reset shows the server defaults and the modal', async () => {
    const wrapper = await mountView()
    await nth(button(wrapper, 'รีเซ็ตเป็นค่าเริ่มต้น'), 0).trigger('click')
    del.mockResolvedValue({ data: { ...baseUser(), background: { type: null, config: null, image_url: null } } })
    await nth(button(wrapper, 'ยืนยัน'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('รีเซ็ตพื้นหลังเป็นค่าเริ่มต้นแล้ว')
    expect(wrapper.text()).toContain('135°')
  })

  it('changing the password clears the fields and shows the modal instead of an inline line', async () => {
    const wrapper = await mountView()
    const pw = wrapper.findAll('input[type="password"]')
    await nth(pw, 1).setValue('Old12345')
    await nth(pw, 2).setValue('New12345')
    await nth(pw, 3).setValue('New12345')
    put.mockResolvedValue({ message: 'ok' })

    await nth(button(wrapper, 'เปลี่ยนรหัสผ่าน'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('เปลี่ยนรหัสผ่านแล้ว')
    expect((nth(pw, 1).element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('เปลี่ยนรหัสผ่านสำเร็จ')
  })

  it('saving the email puts the STORED address back in the input and quotes it in the modal', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-test="profile-email"]').setValue('New@Example.COM')
    await wrapper.find('[data-test="profile-email-password"]').setValue('Str0ngPassword')
    put.mockResolvedValue({ data: { ...baseUser(), email: 'new@example.com' } })

    await wrapper.find('[data-test="profile-email-save"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="profile-email"]').element as HTMLInputElement).value).toBe('new@example.com')
    expect(saveFeedbackState.body).toBe('บันทึกอีเมล new@example.com แล้ว — ครั้งถัดไปให้เข้าสู่ระบบด้วยอีเมลนี้')
  })
})
