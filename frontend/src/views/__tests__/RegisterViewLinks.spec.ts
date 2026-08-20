/**
 * TASK-232/233 — the two SHORT signup links land on a usable form.
 *
 * ── WHY EACH OF THESE IS WORTH PINNING ──
 *
 * 1. `/c/<code>` IS THE WHOLE POINT OF TASK-233. Before it, a company had
 *    no signup link at all: `?company=<slug>` themed the login page and was
 *    never read by registration, so a recruit still had to be handed a code
 *    out of band and type it in. If this stops skipping the code step, the
 *    feature is silently back to where it started and the page still
 *    "works" — which is exactly the kind of regression nobody reports.
 *
 * 2. `?ref=` MUST KEEP WORKING. Team leaders have already sent those
 *    64-character URLs to people. This is the assertion a future "the short
 *    codes replaced these" cleanup has to argue with.
 *
 * 3. A DEAD LINK RECOVERS, IT DOES NOT STRAND. The backend answers 404 for
 *    unknown, expired, revoked and exhausted alike — deliberately, so a
 *    stranger cannot probe it. That means the page can never explain
 *    precisely what went wrong, and the only decent behaviour left is to
 *    drop the visitor onto the ordinary code form with a reason. A recruit
 *    who reaches a dead end is a recruit the company loses.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const post = vi.fn()
const get = vi.fn()

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
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

/** The route the component believes it is on. Swapped per test. */
const currentRoute = { name: '', params: {} as Record<string, unknown>, query: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({
  useRoute: () => currentRoute,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

import RegisterView from '../RegisterView.vue'

function setRoute(name: string, params: Record<string, unknown> = {}, query: Record<string, unknown> = {}) {
  currentRoute.name = name
  currentRoute.params = params
  currentRoute.query = query
}

async function mountRegister() {
  const wrapper = mount(RegisterView, {
    global: { stubs: { Icon: true, RouterLink: true, Teleport: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  get.mockResolvedValue({ data: {} })
  setRoute('register')
})

describe('RegisterView — the short signup links (TASK-232/233)', () => {
  it('resolves the company from /c/<code> without the recruit typing anything', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต' })
    setRoute('company-signup-link', { code: 'thailife' })

    const wrapper = await mountRegister()

    expect(post).toHaveBeenCalledWith('/register/resolve-invite-code', { invite_code: 'thailife' })
    // The company has to be VISIBLE. A recruit signing up needs to know
    // which company they are joining before they type a password.
    expect(wrapper.text()).toContain('ไทยประกันชีวิต')
  })

  it('never asks for the invite code again once /c/<code> resolved it', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต' })
    setRoute('company-signup-link', { code: 'thailife' })

    const wrapper = await mountRegister()

    // If this input is back, TASK-233 has been undone: the link no longer
    // does the one thing it was built to do.
    expect(wrapper.find('#invite_code').exists()).toBe(false)
  })

  it('drops a dead /c/<code> onto the ordinary code form with a reason, never a dead end', async () => {
    post.mockRejectedValue(new FakeApiError(404, {}))
    setRoute('company-signup-link', { code: 'expiredcode' })

    const wrapper = await mountRegister()

    expect(wrapper.find('#invite_code').exists()).toBe(true)
    expect(wrapper.text()).toMatch(/รหัสเชิญ|หมดอายุ/)
  })

  it('reads the team invite from the PATH on /j/<code>', async () => {
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    setRoute('team-signup-link', { code: 'K7M3QP2X9A' })

    const wrapper = await mountRegister()

    expect(post).toHaveBeenCalledWith('/register/resolve-ref-token', { ref_token: 'K7M3QP2X9A' })
    expect(wrapper.text()).toContain('สมชาย')
  })

  it('still reads the 64-character ?ref= token leaders have already sent out', async () => {
    const legacy = 'a'.repeat(64)
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    setRoute('register', {}, { ref: legacy })

    await mountRegister()

    expect(post).toHaveBeenCalledWith('/register/resolve-ref-token', { ref_token: legacy })
  })

  it('asks for a code as usual when there is no link of either kind', async () => {
    setRoute('register')

    const wrapper = await mountRegister()

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('#invite_code').exists()).toBe(true)
  })

  it('does not confuse a /c/ code for a team token', async () => {
    // The two live at different prefixes and resolve through different
    // endpoints. Sending a company code to the team resolver would 404 and
    // strand a recruit on a link that is perfectly valid.
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต' })
    setRoute('company-signup-link', { code: 'thailife' })

    await mountRegister()

    expect(post).not.toHaveBeenCalledWith('/register/resolve-ref-token', expect.anything())
  })

  it('does not claim a step the recruit never saw, or ask for a code that is not on screen', async () => {
    // FOUND IN UAT, 2026-08-20. The page rendered "ขั้นตอน 2 จาก 2" above a
    // subtitle reading "กรอกรหัสเชิญของบริษัทเพื่อเริ่มสมัคร" — on a screen
    // with no invite-code field and no way back to step 1.
    //
    // The same two computeds already suppressed both for a ?ref= arrival,
    // with a comment explaining why. The company link is the same
    // situation and was simply not covered.
    post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต' })
    setRoute('company-signup-link', { code: 'thailife' })

    const wrapper = await mountRegister()
    const text = wrapper.text()

    expect(text).not.toContain('ขั้นตอน 2 จาก 2')
    expect(text).not.toContain('กรอกรหัสเชิญของบริษัทเพื่อเริ่มสมัคร')
    expect(text).not.toContain('เปลี่ยนรหัส')
  })

  it('brings the step counter back when the link was dead and the code form returned', async () => {
    // The counter and the "enter your code" line are TRUE again on that
    // screen, so suppressing them there would be the opposite mistake.
    post.mockRejectedValue(new FakeApiError(404, {}))
    setRoute('company-signup-link', { code: 'expiredcode' })

    const wrapper = await mountRegister()

    expect(wrapper.text()).toContain('ขั้นตอน 1 จาก 2')
  })
})
