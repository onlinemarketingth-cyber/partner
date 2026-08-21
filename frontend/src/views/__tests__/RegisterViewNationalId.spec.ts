/**
 * The Thai national ID field accepts the number AS PRINTED ON THE CARD.
 *
 * ── THE BUG THIS PINS, REPORTED 2026-08-21 FROM /j/aN3tDZqGjR ──
 *
 * The card prints the number in groups — "1 2345 67890 12 1" — and people
 * type what they are holding. The field was maxlength="13" with no
 * normalisation, so four separators consumed four of the thirteen slots:
 * input stopped part-way through the number and the server answered
 * "เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก" to somebody who had typed thirteen
 * digits. A paste was worse — truncated in one action, with no keystroke to
 * notice it by.
 *
 * ── WHAT MUST NOT COME BACK WITH THE FIX ──
 *
 * The mod-11 CHECKSUM stays server-side, in App\Rules\ThaiNationalId, and
 * nowhere else. A second copy in Vue is how the two drift apart, and the
 * drift is invisible: the form would accept a number the server rejects, or
 * reject one it would have taken. The last test here is the one that argues
 * with a future "let's validate it in the browser too".
 *
 * The strip is also Thai-ID-ONLY. Passport numbers are letters and digits
 * (App\Rules\IdDocument: ^[A-Za-z0-9]{6,12}$); stripping non-digits there
 * would delete most of the number in front of the person typing it.
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
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

import RegisterView from '../RegisterView.vue'

/**
 * Arrive through a team link so the form is on screen without a code step —
 * the exact path the report came from.
 */
async function mountOnTheForm() {
  currentRoute.name = 'team-signup-link'
  currentRoute.params = { code: 'aN3tDZqGjR' }
  currentRoute.query = {}
  post.mockResolvedValue({ company_name: 'ไทยประกันชีวิต', inviter_name: 'สมชาย' })

  const wrapper = mount(RegisterView, {
    global: { stubs: { Icon: true, RouterLink: true, Teleport: true } },
  })
  await flushPromises()

  return wrapper
}

/** Type into the field the way a browser does: set value, then fire input. */
async function typeNationalId(wrapper: Awaited<ReturnType<typeof mountOnTheForm>>, raw: string) {
  const field = wrapper.find('#national_id')
  await field.setValue(raw)
  await flushPromises()

  return field.element as HTMLInputElement
}

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  get.mockResolvedValue({ data: {} })
})

describe('RegisterView — the Thai national ID field', () => {
  it('accepts the number typed with the dashes that are printed on the card', async () => {
    const wrapper = await mountOnTheForm()

    const el = await typeNationalId(wrapper, '1-1017-00230-70-8')

    expect(el.value).toBe('1101700230708')
  })

  it('accepts it typed with spaces, which is how the card actually groups it', async () => {
    const wrapper = await mountOnTheForm()

    const el = await typeNationalId(wrapper, '1 1017 00230 70 8')

    expect(el.value).toBe('1101700230708')
  })

  it('leaves room for the separators instead of cutting the number short', async () => {
    // The heart of the bug: maxlength="13" stopped a formatted number at
    // "1-1017-00230-" — ten of the thirteen digits, and no explanation.
    const wrapper = await mountOnTheForm()
    const field = wrapper.find('#national_id')

    expect(Number((field.element as HTMLInputElement).getAttribute('maxlength'))).toBeGreaterThanOrEqual(17)
  })

  it('still caps the number at thirteen digits', async () => {
    // Widening maxlength must not widen the NUMBER. Fourteen digits is not
    // a Thai ID, and letting one through only moves the rejection later.
    const wrapper = await mountOnTheForm()

    const el = await typeNationalId(wrapper, '11017002307089999')

    expect(el.value).toBe('1101700230708')
  })

  it('does not strip anything from a passport number', async () => {
    const wrapper = await mountOnTheForm()

    // Switch the document type using the control the person uses.
    const passportButton = wrapper
      .findAll('button')
      .find((b) => b.text().includes('หนังสือเดินทาง'))
    expect(passportButton).toBeTruthy()
    await passportButton!.trigger('click')
    await flushPromises()

    const el = await typeNationalId(wrapper, 'AB1234567')

    expect(el.value).toBe('AB1234567')
  })

  it('does not judge the checksum in the browser — that is the server\'s one job here', async () => {
    // A number with a WRONG check digit must still be typeable and still be
    // submitted. If this ever fails, a checksum has been copied into Vue and
    // the two implementations have started drifting.
    const wrapper = await mountOnTheForm()

    const el = await typeNationalId(wrapper, '1101700230700')

    expect(el.value).toBe('1101700230700')
    expect(wrapper.text()).not.toContain('เลขตรวจสอบ')
  })
})
