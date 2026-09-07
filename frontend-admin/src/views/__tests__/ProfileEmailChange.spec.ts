/**
 * TASK-247 — changing your own login address.
 *
 * The last field on the profile screen that was not self-service. Correcting a
 * typo in the address you sign in with meant asking an admin — on a screen
 * that, until TASK-246, could not do it either.
 *
 * Two things have to be true and neither is visible in a screenshot:
 *
 *  1. THE CURRENT PASSWORD GOES WITH IT. The email is the identifier this
 *     account authenticates as, so changing it is the first half of a
 *     takeover; a borrowed session must not be enough on its own. The server
 *     enforces it (UpdateEmailRequest), and a form that did not collect it
 *     would simply always fail.
 *
 *  2. THE AUTH USER IS REFRESHED FROM THE RESPONSE. This is the one field
 *     where an optimistic local value that drifted would be the worst thing to
 *     be wrong about — it is what the next sign-in uses.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const put = vi.fn()
const setUser = vi.fn()

let authUser: Record<string, unknown> = {}

vi.mock('@/api/client', () => {
  class ApiError extends Error {
    constructor(public status: number, public body: unknown) {
      super(`API error ${status}`)
    }
  }

  return {
    api: {
      get: vi.fn(async () => ({ data: [] })),
      post: vi.fn(),
      put: (...args: unknown[]) => put(...args),
      patch: vi.fn(),
      delete: vi.fn(),
      postForm: vi.fn(),
      download: vi.fn(),
    },
    ApiError,
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return authUser
    },
    setUser: (...args: unknown[]) => setUser(...args),
  }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

import ProfileSettingsView from '../ProfileSettingsView.vue'
import { ApiError } from '@/api/client'

async function mountView() {
  const wrapper = mount(ProfileSettingsView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div />' },
        Icon: true,
        EmptyState: true,
        LoadingSkeleton: true,
        ConfirmDialog: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const at = (w: Wrapper, test: string) => w.find(`[data-test="${test}"]`)

async function changeEmail(wrapper: Wrapper, email: string, password: string) {
  await at(wrapper, 'profile-email').setValue(email)
  await at(wrapper, 'profile-email-password').setValue(password)
  await at(wrapper, 'profile-email-save').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  put.mockReset()
  setUser.mockReset()
  authUser = {
    id: 1,
    name: 'สมชาย ใจดี',
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    email: 'old@example.com',
    role: 'company_admin',
    avatar_url: null,
    // A real background object, not null: the view reads
    // `auth.user?.background.type` at setup and the field is non-nullable in
    // AuthUser — this fixture mirrors what /me actually sends.
    background: { type: 'gradient', config: { color1: '#1e3a8a', color2: '#0f172a', angle: 135 }, image_url: null },
  }
})

describe('ProfileSettingsView — my own login address', () => {
  it('starts from the address currently on the account', async () => {
    const wrapper = await mountView()

    expect((at(wrapper, 'profile-email').element as HTMLInputElement).value).toBe('old@example.com')
  })

  it('sends the new address together with the current password', async () => {
    put.mockResolvedValue({ data: { ...authUser, email: 'new@example.com' } })

    const wrapper = await mountView()
    await changeEmail(wrapper, 'new@example.com', 'Str0ngPassword')

    expect(put).toHaveBeenCalledWith('/me/email', {
      current_password: 'Str0ngPassword',
      email: 'new@example.com',
    })
  })

  it('refreshes the signed-in user from the response, not optimistically', async () => {
    // The next sign-in uses this value; a local guess that drifted from the
    // server would lock somebody out of their own account.
    const saved = { ...authUser, email: 'new@example.com' }
    put.mockResolvedValue({ data: saved })

    const wrapper = await mountView()
    await changeEmail(wrapper, 'new@example.com', 'Str0ngPassword')

    expect(setUser).toHaveBeenCalledWith(saved)
    expect(wrapper.text()).toContain('ครั้งถัดไปให้เข้าสู่ระบบด้วยอีเมลนี้')
  })

  it('clears the password box after a successful save', async () => {
    // It is a live credential sitting in a form on a screen somebody walks
    // away from. Nothing needs it after the request has been made.
    put.mockResolvedValue({ data: { ...authUser, email: 'new@example.com' } })

    const wrapper = await mountView()
    await changeEmail(wrapper, 'new@example.com', 'Str0ngPassword')

    expect((at(wrapper, 'profile-email-password').element as HTMLInputElement).value).toBe('')
  })

  it('shows the server\'s own reason rather than one generic failure', async () => {
    /*
     * "รหัสผ่านปัจจุบันไม่ถูกต้อง" and "อีเมลนี้ถูกใช้กับบัญชีอื่นแล้ว" are
     * different problems with different fixes. A single "บันทึกไม่สำเร็จ"
     * hides which one happened, and the person retypes the wrong field.
     */
    put.mockRejectedValue(new ApiError(422, {
      errors: { current_password: ['รหัสผ่านปัจจุบันไม่ถูกต้อง'] },
    }))

    const wrapper = await mountView()
    await changeEmail(wrapper, 'new@example.com', 'wrong')

    expect(at(wrapper, 'profile-email-error').text()).toContain('รหัสผ่านปัจจุบันไม่ถูกต้อง')
    expect(setUser).not.toHaveBeenCalled()
  })

  it('says why the password is being asked for, before it is asked', async () => {
    // A password box on a "change my email" form looks like an odd thing to
    // want until you know the email is the login identifier.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('คือชื่อผู้ใช้ที่คุณใช้เข้าสู่ระบบ')
  })
})
