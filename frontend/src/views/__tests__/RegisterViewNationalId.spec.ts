/**
 * The signup form's two identity fields — what reaches the wire, and what
 * the person is told before they get there.
 *
 * ── THE NATIONAL ID ──
 *
 * Reported 2026-08-21 from /j/aN3tDZqGjR. Two separate things came out of
 * it, and only one was a bug:
 *
 *   The number that was rejected, 1234567890123, is genuinely invalid — its
 *   mod-11 check digit is 1, not 3. App\Rules\ThaiNationalId was right.
 *
 *   The real defect was that the card's printed format could not be typed.
 *   maxlength="13" with no normalisation meant four separators ate four of
 *   the thirteen slots, so the server said "must be 13 digits" to somebody
 *   who had typed thirteen. It is now five boxes matching the card's own
 *   groups (NationalIdSegments.vue, which owns its own behaviour tests).
 *
 * What is asserted HERE is only what the two must agree on: that the right
 * control appears for the right document type, and that thirteen bare
 * digits are what leave the form.
 *
 * ── THE EMAIL ──
 *
 * The email is the login identity, so a taken one makes the rest of the
 * form pointless — the person used to find that out at the very end, after
 * a national ID and a password. The check that moves that news earlier
 * talks to an ACCOUNT-EXISTENCE ORACLE, so the assertions about it are
 * mostly about restraint: it must carry the signup credential (the server
 * refuses it otherwise), it must not announce a verdict about an address
 * that is no longer on screen, and it must say nothing at all when the
 * call fails rather than inventing either answer.
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

const currentRoute = { name: '', params: {} as Record<string, unknown>, query: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({
  useRoute: () => currentRoute,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import RegisterView from '../RegisterView.vue'

const REF_TOKEN = 'aN3tDZqGjR'

/** Arrive through a team link — the exact path the report came from. */
async function mountOnTheForm() {
  currentRoute.name = 'team-signup-link'
  currentRoute.params = { code: REF_TOKEN }
  currentRoute.query = {}
  post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })

  const wrapper = mount(RegisterView, {
    global: { stubs: { Icon: true, Teleport: true } },
    attachTo: document.body,
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountOnTheForm>>

/** The boxes belonging to the Thai ID field, in order. */
function idBoxes(wrapper: Wrapper) {
  return wrapper.findAll('[role="group"] input')
}

async function switchToPassport(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((b) => b.text().includes('หนังสือเดินทาง'))
  expect(button).toBeTruthy()
  await button!.trigger('click')
  await flushPromises()
}

/**
 * Fill everything the form checks before it will POST /register.
 *
 * The email is included but its availability check never fires here: these
 * tests run on real timers and the debounce is 450ms, so nothing is asked
 * and nothing is claimed. The email suite below installs fake timers to
 * exercise that path deliberately.
 */
async function fillTheRestOfTheForm(wrapper: Wrapper) {
  await wrapper.find('#first_name').setValue('สมหญิง')
  await wrapper.find('#last_name').setValue('ทดสอบ')
  await wrapper.find('#email').setValue('somying@example.com')
  await wrapper.find('#password').setValue('correct horse 8')
  await wrapper.find('#password_confirmation').setValue('correct horse 8')
}

/** The body of the last POST /register call, if it happened. */
function registerPayload(): Record<string, unknown> | undefined {
  const calls = post.mock.calls.filter(([path]) => path === '/register')

  return calls[calls.length - 1]?.[1] as Record<string, unknown> | undefined
}

beforeEach(() => {
  vi.useRealTimers()
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  get.mockResolvedValue({ data: {} })
})

describe('RegisterView — the Thai national ID field', () => {
  it('shows the card\'s five printed groups, not one long box', async () => {
    const wrapper = await mountOnTheForm()

    expect(idBoxes(wrapper).map((b) => Number(b.attributes('maxlength')))).toEqual([1, 4, 5, 2, 1])
  })

  it('sends thirteen bare digits when the number is typed with the card\'s dashes', async () => {
    const wrapper = await mountOnTheForm()
    await fillTheRestOfTheForm(wrapper)

    // Straight into the first box, separators and all — what somebody
    // holding the card actually does.
    await idBoxes(wrapper)[0]!.setValue('1-1017-00230-70-8')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(registerPayload()?.national_id).toBe('1101700230708')
  })

  it('gives a passport ONE field, because a passport has no printed groups', async () => {
    const wrapper = await mountOnTheForm()
    await switchToPassport(wrapper)

    expect(wrapper.find('[role="group"]').exists()).toBe(false)
    expect(wrapper.find('#national_id').exists()).toBe(true)
  })

  it('does not strip anything from a passport number', async () => {
    const wrapper = await mountOnTheForm()
    await switchToPassport(wrapper)

    await wrapper.find('#national_id').setValue('AB1234567')

    expect((wrapper.find('#national_id').element as HTMLInputElement).value).toBe('AB1234567')
  })

  it('does not judge the checksum in the browser — that is the server\'s one job here', async () => {
    // 1101700230700 fails mod-11. It must still be typeable AND submittable:
    // if this ever fails, a checksum has been copied into Vue and the two
    // implementations have started drifting.
    const wrapper = await mountOnTheForm()
    await fillTheRestOfTheForm(wrapper)

    await idBoxes(wrapper)[0]!.setValue('1101700230700')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(registerPayload()?.national_id).toBe('1101700230700')
    expect(wrapper.text()).not.toContain('เลขตรวจสอบ')
  })
})

describe('RegisterView — telling the recruit their email is taken', () => {
  /** Answer the availability call, leaving every other POST as it was. */
  function emailIs(available: boolean) {
    post.mockImplementation((path: string) => {
      if (path === '/register/check-email') return Promise.resolve({ available })
      if (path === '/register/resolve-ref-token') {
        return Promise.resolve({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
      }

      return Promise.resolve({})
    })
  }

  /** Type an address and let the debounce elapse. */
  async function enterEmail(wrapper: Wrapper, email: string) {
    await wrapper.find('#email').setValue(email)
    await vi.advanceTimersByTimeAsync(600)
    await flushPromises()
  }

  beforeEach(() => {
    vi.useFakeTimers()
  })

  it('warns as soon as the address is complete, without waiting for submit', async () => {
    const wrapper = await mountOnTheForm()
    emailIs(false)

    await enterEmail(wrapper, 'somchai@example.com')

    expect(wrapper.text()).toContain('อีเมลนี้มีบัญชีในระบบแล้ว')
  })

  it('offers the login page, which is what the person actually came for', async () => {
    // "Already registered" with no way forward is a dead end dressed up as
    // helpfulness — the usual cause is that they already have an account.
    const wrapper = await mountOnTheForm()
    emailIs(false)

    await enterEmail(wrapper, 'somchai@example.com')

    const link = wrapper.findComponent({ name: 'RouterLink' })
    expect(link.exists()).toBe(true)
    expect(link.props('to')).toEqual({ name: 'login' })
  })

  it('never puts the address in the login URL', async () => {
    // A real person's email in a query string lands in browser history, in
    // the Referer header of everything the next page loads, and in every
    // access log in between (§6, PDPA) — to save one field of typing.
    const wrapper = await mountOnTheForm()
    emailIs(false)

    await enterEmail(wrapper, 'somchai@example.com')

    expect(JSON.stringify(wrapper.findComponent({ name: 'RouterLink' }).props('to')))
      .not.toContain('somchai@example.com')
  })

  it('says nothing about an address that is free', async () => {
    const wrapper = await mountOnTheForm()
    emailIs(true)

    await enterEmail(wrapper, 'nobody@example.com')

    expect(wrapper.text()).not.toContain('อีเมลนี้มีบัญชีในระบบแล้ว')
  })

  it('carries the signup credential, which is the only reason the endpoint answers', async () => {
    // CheckEmailRequest refuses a call without a live invite code or recruit
    // token — that gate is what keeps this from being a free
    // account-enumeration oracle. A view that forgot to send it would look
    // like a broken feature, and the fix somebody reaches for is removing
    // the gate.
    const wrapper = await mountOnTheForm()
    emailIs(true)

    await enterEmail(wrapper, 'nobody@example.com')

    expect(post).toHaveBeenCalledWith('/register/check-email', {
      email: 'nobody@example.com',
      ref_token: REF_TOKEN,
    })
  })

  it('does not ask about a half-typed address', async () => {
    const wrapper = await mountOnTheForm()
    emailIs(true)

    await enterEmail(wrapper, 'somchai@')

    expect(post).not.toHaveBeenCalledWith('/register/check-email', expect.anything())
  })

  it('asks once when typing stops, not once per keystroke', async () => {
    const wrapper = await mountOnTheForm()
    emailIs(true)
    post.mockClear()

    await wrapper.find('#email').setValue('somchai@example.co')
    await wrapper.find('#email').setValue('somchai@example.com')
    await vi.advanceTimersByTimeAsync(600)
    await flushPromises()

    expect(post.mock.calls.filter(([p]) => p === '/register/check-email')).toHaveLength(1)
  })

  it('drops the warning the moment the address changes', async () => {
    // A verdict about a DIFFERENT address is worse than no verdict: the
    // person cannot tell it is stale, and it is sitting under the address
    // they just corrected.
    const wrapper = await mountOnTheForm()
    emailIs(false)
    await enterEmail(wrapper, 'somchai@example.com')
    expect(wrapper.text()).toContain('อีเมลนี้มีบัญชีในระบบแล้ว')

    await wrapper.find('#email').setValue('somchai2@example.com')
    await flushPromises()

    expect(wrapper.text()).not.toContain('อีเมลนี้มีบัญชีในระบบแล้ว')
  })

  it('stays silent when the check itself fails', async () => {
    // Rate limit, dropped connection, flaky network. This is a convenience
    // on top of a server rule that still runs at submit — it must never
    // turn into a red message on a form the person can legitimately send,
    // and must never claim an address is free either.
    const wrapper = await mountOnTheForm()
    post.mockImplementation((path: string) => {
      if (path === '/register/check-email') return Promise.reject(new FakeApiError(429, {}))

      return Promise.resolve({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    })

    await enterEmail(wrapper, 'somchai@example.com')

    expect(wrapper.text()).not.toContain('อีเมลนี้มีบัญชีในระบบแล้ว')
  })

  it('still lets the form be submitted after a failed check', async () => {
    // The server-side `unique` rule is the real gate. A failed preview must
    // not become a lock on the submit button.
    const wrapper = await mountOnTheForm()
    post.mockImplementation((path: string) => {
      if (path === '/register/check-email') return Promise.reject(new FakeApiError(429, {}))

      return Promise.resolve({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })
    })
    await fillTheRestOfTheForm(wrapper)
    await enterEmail(wrapper, 'somchai@example.com')
    await idBoxes(wrapper)[0]!.setValue('1101700230708')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(registerPayload()).toBeTruthy()
  })

  it('does not submit an address it has just been told is taken', async () => {
    // Saves a round trip whose only outcome is the 422 that would land in
    // this same spot.
    const wrapper = await mountOnTheForm()
    emailIs(false)
    await fillTheRestOfTheForm(wrapper)
    await enterEmail(wrapper, 'somchai@example.com')
    await idBoxes(wrapper)[0]!.setValue('1101700230708')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(registerPayload()).toBeUndefined()
  })
})
