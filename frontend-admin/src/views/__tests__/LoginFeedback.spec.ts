/**
 * TASK-247 — what the login screen says when it refuses.
 *
 * Three refusals reach this screen and only one of them was readable:
 *
 *   • WRONG PASSWORD said so, but never that the account was one attempt from
 *     being locked. The lock then arrived with no warning.
 *   • LOCKED OUT said "try again later" and dropped the wait — the server
 *     interpolates the seconds into an English sentence and this screen
 *     renders Thai, so the number never survived the translation. Somebody
 *     refreshes for an unknown number of minutes and ends up asking an admin
 *     to reset a password that was never wrong.
 *   • BLOCKED (403) WAS NOT HANDLED AT ALL. A suspended company fell to the
 *     `else` branch and was told "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" — which is
 *     false, and sends the reader to check their internet instead of their
 *     administrator. This is the one that mattered most and the one that
 *     looked, from the code, like nothing was missing.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const login = vi.fn()

/*
 * The class is defined INSIDE the factory: vi.mock is hoisted above every
 * top-level statement in this file, so a class declared out here would not
 * exist yet when the factory runs. It is re-exported below for the tests.
 */
vi.mock('@/stores/auth', () => {
  class ApiError extends Error {
    constructor(public status: number, public body: unknown) {
      super(`API error ${status}`)
    }
  }

  return {
    useAuthStore: () => ({ login: (...args: unknown[]) => login(...args) }),
    ApiError,
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

import LoginView from '../LoginView.vue'
// The same class the view will compare against with `instanceof` — imported
// from the mocked module rather than redeclared, or the branch never matches.
import { ApiError } from '@/stores/auth'

async function submit() {
  const wrapper = mount(LoginView, {
    global: { stubs: { Icon: true, AppLogo: true } },
  })

  await wrapper.find('#email').setValue('somchai@example.com')
  await wrapper.find('#password').setValue('whatever')
  await wrapper.find('form').trigger('submit')
  await flushPromises()

  return wrapper
}

/** The 422 body the API sends for a wrong password. */
function credentials(attemptsRemaining: number) {
  return new ApiError(422, {
    message: 'These credentials do not match our records.',
    errors: { email: ['These credentials do not match our records.'] },
    attempts_remaining: attemptsRemaining,
    lockout_seconds: null,
  })
}

beforeEach(() => {
  login.mockReset()
})

describe('LoginView — counting down to the lock', () => {
  it('says nothing about attempts while there is room to spare', async () => {
    // A warning that is always on is one nobody reads by the time it matters.
    login.mockRejectedValue(credentials(4))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('อีเมลหรือรหัสผ่านไม่ถูกต้อง')
    expect(wrapper.text()).not.toContain('เหลืออีก')
  })

  it('warns once two attempts are left', async () => {
    login.mockRejectedValue(credentials(2))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('เหลืออีก 2 ครั้ง')
  })

  it('says the next failure locks the account', async () => {
    // Zero remaining is not "locked" — it is the last warning that will be
    // given, and the difference is what makes the lock expected.
    login.mockRejectedValue(credentials(0))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('ครั้งถัดไปที่ผิด บัญชีจะถูกล็อกชั่วคราว')
  })
})

describe('LoginView — locked out', () => {
  it('says how long, in minutes when it is long', async () => {
    login.mockRejectedValue(new ApiError(422, {
      message: 'Too many login attempts. Please try again in 97 seconds.',
      errors: { email: ['Too many login attempts. Please try again in 97 seconds.'] },
      attempts_remaining: null,
      lockout_seconds: 97,
    }))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('2 นาที')
  })

  it('keeps seconds when the wait is under a minute', async () => {
    login.mockRejectedValue(new ApiError(422, {
      message: 'Too many login attempts.',
      errors: { email: ['Too many login attempts.'] },
      attempts_remaining: null,
      lockout_seconds: 40,
    }))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('40 วินาที')
  })

  it('says the password is still fine, so nobody asks for a reset', async () => {
    /*
     * The actual cost of the old message. A lock reads like a broken account,
     * and the next action is a support request for a password that was never
     * wrong — which then really does change the credential.
     */
    login.mockRejectedValue(new ApiError(422, {
      message: 'Too many login attempts.',
      errors: { email: ['Too many login attempts.'] },
      attempts_remaining: null,
      lockout_seconds: 60,
    }))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('ไม่ต้องขอรีเซ็ต')
  })

  it('does not mark the email box as the problem', async () => {
    // Nothing is wrong with what was typed; the throttle is closed. A red
    // field says "fix this", and there is nothing here to fix.
    login.mockRejectedValue(new ApiError(422, {
      message: 'Too many login attempts.',
      errors: { email: ['Too many login attempts.'] },
      attempts_remaining: null,
      lockout_seconds: 60,
    }))

    const wrapper = await submit()

    expect(wrapper.find('#email').classes()).not.toContain('border-rose-300')
  })
})

describe('LoginView — the account is blocked (403)', () => {
  it('shows the reason the server gave', async () => {
    /*
     * The branch that did not exist. The password was CORRECT; the account is
     * not allowed in. The server sends Thai copy written for exactly this
     * reader, so it is shown as-is rather than restated here.
     */
    login.mockRejectedValue(new ApiError(403, {
      message: 'บริษัทของคุณถูกระงับการใช้งานอยู่ในขณะนี้ จึงไม่สามารถเข้าใช้งานระบบได้ กรุณาติดต่อผู้ดูแลระบบของบริษัทของคุณ',
      error_code: 'company_inactive',
    }))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('บริษัทของคุณถูกระงับการใช้งาน')
  })

  it('no longer blames the network for it', async () => {
    // The regression this file exists for: a 403 used to fall to the `else`
    // branch and say the server could not be reached, which is false and
    // sends the reader to the wrong place entirely.
    login.mockRejectedValue(new ApiError(403, {
      message: 'บัญชีของคุณอยู่ระหว่างรอการอนุมัติจากบริษัทของคุณ กรุณารอการติดต่อกลับ',
      error_code: 'approval_pending',
    }))

    const wrapper = await submit()

    expect(wrapper.text()).not.toContain('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')
    expect(wrapper.text()).toContain('รอการอนุมัติ')
  })

  it('still says the server is unreachable when it really is', async () => {
    login.mockRejectedValue(new Error('network down'))

    const wrapper = await submit()

    expect(wrapper.text()).toContain('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')
  })
})
