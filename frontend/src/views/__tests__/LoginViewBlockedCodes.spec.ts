/**
 * MOB-30 (2026-10-02) — the login screen after an account deletion request.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. A BLOCKED ACCOUNT IS TOLD TO CHECK ITS WI-FI. LoginView only knew three
 *    403 codes; anything else fell through to "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้".
 *    An account with a pending deletion request answers 403 with
 *    error_code 'deletion_requested' and a Thai sentence — that sentence is
 *    what must be shown, for this code and any future one.
 * 2. THE KNOWN CODES LOSE THEIR PANELS. The generic branch must not swallow
 *    email_unverified / approval_pending / approval_rejected.
 * 3. THE AGENT CANNOT TELL THE REQUEST WAS SENT — ?notice=deletion_requested
 *    must produce the confirmation.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const post = vi.fn<AnyFn>()
const route = { name: 'login', params: {}, query: {} as Record<string, string> }

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn<AnyFn>().mockResolvedValue(''),
    post: (...args: unknown[]) => post(...args),
    delete: vi.fn<AnyFn>(),
  },
  ApiError: FakeApiError,
  ensureCsrfCookie: vi.fn<AnyFn>().mockResolvedValue(undefined),
  setToken: vi.fn<AnyFn>(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn<AnyFn>(), replace: vi.fn<AnyFn>() }),
  useRoute: () => route,
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import LoginView from '../LoginView.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  route.query = {}
})

async function submitWith(error: unknown) {
  post.mockRejectedValue(error)
  const wrapper = mount(LoginView, { global: { stubs: { Icon: true, AppLogo: true } } })
  await wrapper.find('#email').setValue('a@example.com')
  await wrapper.find('#password').setValue('Secret123')
  await wrapper.find('form').trigger('submit')
  await flushPromises()

  return wrapper
}

describe('a 403 this screen has no panel for', () => {
  it("shows the server's own sentence (deletion_requested)", async () => {
    const wrapper = await submitWith(
      new FakeApiError(403, {
        message: 'บัญชีนี้อยู่ระหว่างรอลบตามคำขอของคุณ',
        error_code: 'deletion_requested',
      }),
    )

    expect(wrapper.text()).toContain('บัญชีนี้อยู่ระหว่างรอลบตามคำขอของคุณ')
    expect(wrapper.text()).not.toContain('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')
  })

  it('falls back to a Thai sentence when the server sent none', async () => {
    const wrapper = await submitWith(new FakeApiError(403, { error_code: 'something_new' }))

    expect(wrapper.text()).toContain('บัญชีนี้เข้าสู่ระบบไม่ได้ในขณะนี้')
  })

  it('the known codes keep their own panel', async () => {
    const wrapper = await submitWith(
      new FakeApiError(403, {
        message: 'รอการอนุมัติ',
        error_code: 'approval_pending',
        can_resend_verification: false,
        can_reapply: false,
        rejection_reason: null,
      }),
    )

    expect(wrapper.text()).toContain('บัญชีของคุณรอการอนุมัติ')
  })

  it('a real network failure still says so', async () => {
    const wrapper = await submitWith(new TypeError('Failed to fetch'))

    expect(wrapper.text()).toContain('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')
  })
})

describe('after "ขอลบบัญชี"', () => {
  it('confirms the request was sent', () => {
    route.query = { notice: 'deletion_requested' }
    const wrapper = mount(LoginView, { global: { stubs: { Icon: true, AppLogo: true } } })

    expect(wrapper.text()).toContain('ส่งคำขอลบบัญชีแล้ว')
  })

  it('says nothing of the kind on an ordinary visit', () => {
    const wrapper = mount(LoginView, { global: { stubs: { Icon: true, AppLogo: true } } })

    expect(wrapper.text()).not.toContain('ส่งคำขอลบบัญชีแล้ว')
    expect(wrapper.text()).not.toContain('บัญชีของคุณถูกลบแล้ว')
  })

  // MOB-12 follow-up (2026-10-03) — deleted on the spot (nothing unpaid, or
  // waived): a different sentence, never "your request was sent".
  it('says the account is deleted when it was deleted immediately', () => {
    route.query = { notice: 'account_deleted' }
    const wrapper = mount(LoginView, { global: { stubs: { Icon: true, AppLogo: true } } })

    expect(wrapper.text()).toContain('บัญชีของคุณถูกลบแล้ว')
    expect(wrapper.text()).not.toContain('ส่งคำขอลบบัญชีแล้ว')
  })
})
