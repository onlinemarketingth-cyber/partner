/**
 * What the signup form tells a recruit about their email address.
 *
 * ── WHERE THE NATIONAL ID WENT (2026-08-28, commit 632e1fd) ──
 *
 * This file used to test the ID field as well. That field is no longer on
 * the signup form: collecting a national ID before somebody even has an
 * account was moved to ProfileSettingsView, where they are asked for it once
 * there is a payout to make. The tests came out with the field, and their
 * contract went with it — see ProfileSettingsView.spec.ts for the document
 * type switch, and NationalIdSegments.spec.ts for the typing behaviour.
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
  // Required since 2026-08-27, and validateForm() refuses before the POST
  // without it — a missing phone here would make this suite pass or fail on
  // the wrong rule.
  await wrapper.find('#phone').setValue('0812345678')
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

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(registerPayload()).toBeUndefined()
  })
})
