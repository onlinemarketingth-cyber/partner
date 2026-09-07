/**
 * 2026-09-07, production report — what a refusal actually was.
 *
 * A visitor filling in the sign-up form saw
 * "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง" with a 429 in the console.
 * The server was not unreachable. It was refusing: POST /register is
 * `throttle:10,1`, and more than ten submissions had come from that address
 * inside a minute.
 *
 * Every catch on this page tested for 422 and sent everything else to one
 * network message, so a throttle, an expired CSRF token and a real outage were
 * the same sentence — and only one of the three was ever true.
 *
 * That is worse than unhelpful, it is misdirection with a cost: the reader
 * goes to check their internet, or reloads and submits again, which extends
 * the very lockout they are inside. Each of these tests is one wrong sentence
 * that can no longer be shown.
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
      public retryAfterSeconds: number | null = null,
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

const currentRoute = { name: '', params: {} as Record<string, unknown>, query: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({
  useRoute: () => currentRoute,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

import RegisterView from '../RegisterView.vue'

/**
 * Arrive through /c/<code> so the company resolves and step 2 — the form that
 * POSTs /register — is on screen, which is where the report came from.
 */
async function mountAtForm() {
  currentRoute.name = 'company-signup-link'
  currentRoute.params = { code: 'genesenn' }
  currentRoute.query = {}
  post.mockResolvedValue({ company_name: 'GENESENN' })

  const wrapper = mount(RegisterView, {
    global: { stubs: { Icon: true, RouterLink: true, Teleport: true } },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountAtForm>>

/** Fill the form and submit it, with the /register call rejecting as given. */
async function submitWith(wrapper: Wrapper, rejection: unknown) {
  await wrapper.find('#first_name').setValue('kreangyot')
  await wrapper.find('#last_name').setValue('ohuyhannapa')
  await wrapper.find('#email').setValue('ikenyaa-gh123@gmail.com')
  await wrapper.find('#phone').setValue('0696361565')
  await wrapper.find('#password').setValue('Str0ngPassword')
  await wrapper.find('#password_confirmation').setValue('Str0ngPassword')

  post.mockRejectedValue(rejection)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

const NETWORK = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  get.mockResolvedValue({ data: {} })
})

describe('RegisterView — a throttled submission', () => {
  it('no longer blames the connection', async () => {
    // The exact production report: HTTP 429, and a page saying the server
    // could not be reached.
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(429, { message: 'Too Many Attempts.' }, 47))

    expect(wrapper.text()).not.toContain(NETWORK)
  })

  it('says how long to wait, from the server\'s own Retry-After', async () => {
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(429, { message: 'Too Many Attempts.' }, 47))

    expect(wrapper.text()).toContain('47 วินาที')
  })

  it('rounds a long wait to minutes', async () => {
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(429, {}, 97))

    expect(wrapper.text()).toContain('2 นาที')
  })

  it('invents no number when the server did not send one', async () => {
    // BR-7 — a made-up wait is a number nobody chose, and being wrong about
    // it is what teaches people to ignore the message.
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(429, {}, null))

    expect(wrapper.text()).toContain('รอสักครู่')
    expect(wrapper.text()).not.toMatch(/\d+ วินาที/)
  })

  it('says the typed details are still there, so nobody starts over', async () => {
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(429, {}, 47))

    expect(wrapper.text()).toContain('ยังอยู่ครบ')
    expect((wrapper.find('#email').element as HTMLInputElement).value).toBe('ikenyaa-gh123@gmail.com')
  })
})

describe('RegisterView — the other refusals that were not outages', () => {
  it('tells an expired page to refresh instead of blaming the network', async () => {
    // 419 is Sanctum's CSRF token expiring on a form left open — nothing the
    // person typed is wrong, and retrying the same page never fixes it.
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(419, {}))

    expect(wrapper.text()).toContain('รีเฟรชหน้า')
    expect(wrapper.text()).not.toContain(NETWORK)
  })

  it('names a server fault as one', async () => {
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(500, {}))

    expect(wrapper.text()).toContain('ระบบขัดข้อง')
    expect(wrapper.text()).not.toContain(NETWORK)
  })

  it('still says the server is unreachable when it really is', async () => {
    // The message was never wrong in itself — only wrong for everything else.
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new TypeError('Failed to fetch'))

    expect(wrapper.text()).toContain(NETWORK)
  })

  it('keeps a 422 rendering as field errors', async () => {
    const wrapper = await mountAtForm()
    await submitWith(wrapper, new FakeApiError(422, { errors: { email: ['อีเมลนี้ถูกใช้แล้ว'] } }))

    expect(wrapper.text()).toContain('อีเมลนี้ถูกใช้แล้ว')
    expect(wrapper.text()).not.toContain(NETWORK)
  })
})

describe('RegisterView — the invite-code step', () => {
  it('does not call a throttled request an invalid code', async () => {
    /*
     * /register/resolve-invite-code is throttle:10,1 as well, and the old
     * catch reported every ApiError as "รหัสเชิญไม่ถูกต้อง" — sending somebody
     * to ask their team leader for a replacement code that was never the
     * problem.
     */
    currentRoute.name = 'register'
    currentRoute.params = {}
    currentRoute.query = {}

    const wrapper = mount(RegisterView, {
      global: { stubs: { Icon: true, RouterLink: true, Teleport: true } },
    })
    await flushPromises()

    await wrapper.find('#invite_code').setValue('GENESENN')
    post.mockRejectedValue(new FakeApiError(429, {}, 30))
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('30 วินาที')
    expect(wrapper.text()).not.toContain('ไม่พบรหัสเชิญนี้')
  })
})
