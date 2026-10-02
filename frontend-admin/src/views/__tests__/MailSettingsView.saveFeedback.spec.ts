/**
 * ADR-052 — "ตั้งค่า Email SMTP" says it saved only after the server stored
 * it, and the form then shows what the server stored. The test-mail button
 * persists nothing, so it keeps its inline result and raises no save modal.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
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

import MailSettingsView from '../MailSettingsView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const STORED = {
  smtp_host: 'smtp.old.test',
  smtp_port: 465,
  encryption: 'ssl',
  username: 'mailer',
  password_set: true,
  from_address: 'noreply@old.test',
  from_name: 'Old',
  is_enabled: true,
}

async function mountView() {
  get.mockResolvedValue({ data: { ...STORED } })
  const wrapper = mount(MailSettingsView, {
    global: { stubs: { HeroHeader: true, Icon: true, PlatformScopeBadge: true } },
  })
  await flushPromises()

  return wrapper
}

const host = (w: Awaited<ReturnType<typeof mountView>>) =>
  (w.find('input[placeholder="smtp.hostinger.com"]').element as HTMLInputElement).value

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  useAuthStore().user = { id: 1, name: 'ซูเปอร์', role: 'super_admin', email: 'su@test' } as never
})

describe('MailSettingsView — save feedback (ADR-052)', () => {
  it('shows the modal only after the PUT resolved, with the server-stored host on screen', async () => {
    const wrapper = await mountView()
    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))

    await wrapper.find('input[placeholder="smtp.hostinger.com"]').setValue('  SMTP.New.Test  ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: { ...STORED, smtp_host: 'smtp.new.test' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่า Email SMTP แล้ว')
    expect(host(wrapper)).toBe('smtp.new.test')
    expect(wrapper.text()).not.toContain('บันทึกสำเร็จ')
  })

  it('a failed save raises no modal and shows the validation error', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(new ApiError(422, { errors: { smtp_host: ['กรุณากรอก SMTP Host'] } }))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('กรุณากรอก SMTP Host')
  })

  it('sending a test mail keeps its inline result and raises no save modal', async () => {
    const wrapper = await mountView()
    post.mockResolvedValue({ message: 'ok' })

    const testButton = wrapper.findAll('button[type="button"]').find((b) => b.text().includes('ทดสอบส่งอีเมล'))!
    await testButton.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/platform/mail-settings/test', { to: 'su@test' })
    expect(wrapper.text()).toContain('ส่งอีเมลทดสอบสำเร็จ')
    expect(saveFeedbackState.show).toBe(false)
  })
})
