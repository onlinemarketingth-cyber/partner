/**
 * MOB-29 / MOB-30 (2026-10-02) — the two new rows on the profile page.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. A WRONG PASSWORD SIGNS THE AGENT OUT ANYWAY. The session may only be
 *    dropped after the server accepted (202) — a 422 must keep them signed in
 *    with the server's Thai sentence on screen.
 * 2. AN ACCEPTED REQUEST LEAVES A DEAD TOKEN BEHIND. The server revoked it;
 *    the client must forget it (setToken(null)) and the user, or the next
 *    screen fires 401s.
 * 3. THE AGENT LANDS ON A PLAIN LOGIN FORM and cannot tell whether anything
 *    was sent — the login route must carry the right notice ("deleted" or
 *    "request sent"), and keep the company's ?company= theme.
 * 6. (MOB-12 follow-up, owner decision 2026-10-03) THE DIALOG MUST SAY WHAT
 *    WILL HAPPEN BEFORE IT HAPPENS: nothing unpaid → deleted immediately;
 *    unpaid → the amount (from satang) and a choice between waiving it
 *    (deleted immediately) and keeping it (admin pays, then deletes); waive
 *    hidden with the server's reason when it is not possible. No choice made
 *    → nothing is sent.
 * 4. THE BIOMETRIC ROW APPEARS IN A BROWSER, or in the app on a phone that
 *    cannot do it. It must render only where it works (owner decision: app
 *    only) — except to switch OFF a lock that is already on.
 * 5. SWITCHING THE LOCK ON WITHOUT PROVING IT WORKS — the next launch would
 *    lock the agent out.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const native = { value: false }
const post = vi.fn<AnyFn>()
const get = vi.fn<AnyFn>()
const setToken = vi.fn<AnyFn>()
const replace = vi.fn<AnyFn>()
const checkBiometry = vi.fn<AnyFn>()
const authenticate = vi.fn<AnyFn>()
const prefs = new Map<string, string>()

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

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'ios' : 'web'),
  },
}))
vi.mock('@aparajita/capacitor-biometric-auth', () => ({
  BiometricAuth: {
    checkBiometry: () => checkBiometry(),
    authenticate: (o: unknown) => authenticate(o),
  },
}))
vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: prefs.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => {
      prefs.set(key, value)
    },
    remove: async ({ key }: { key: string }) => {
      prefs.delete(key)
    },
  },
}))
vi.mock('@capacitor-firebase/messaging', () => ({
  FirebaseMessaging: { deleteToken: vi.fn<AnyFn>() },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn<AnyFn>(),
    patch: vi.fn<AnyFn>(),
    delete: vi.fn<AnyFn>(),
    postForm: vi.fn<AnyFn>(),
    download: vi.fn<AnyFn>(),
  },
  ApiError: FakeApiError,
  ensureCsrfCookie: vi.fn<AnyFn>().mockResolvedValue(undefined),
  setToken: (t: string | null) => setToken(t),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn<AnyFn>(), replace }),
  useRoute: () => ({ name: 'profile', params: {}, query: {} }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProfileSettingsView from '../ProfileSettingsView.vue'
import AccountDeletionDialog from '@/design-system/components/AccountDeletionDialog.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const ME = {
  id: 7,
  name: 'สมหญิง ทดสอบ',
  first_name: 'สมหญิง',
  last_name: 'ทดสอบ',
  email: 'somying@example.com',
  role: 'agent',
  bank_name: null,
  bank_account_number: null,
  bank_account_holder_name: null,
  national_id: null,
  id_document_type: null,
  payout_details_complete: true,
} as unknown as AuthUser

beforeEach(() => {
  setActivePinia(createPinia())
  native.value = false
  post.mockReset()
  previewWith(NOTHING_OWED)
  setToken.mockReset()
  replace.mockReset()
  prefs.clear()
  checkBiometry.mockReset().mockResolvedValue({ isAvailable: true, biometryType: 2 })
  authenticate.mockReset().mockResolvedValue(undefined)
  useAuthStore().setUser(ME)
})

const NOTHING_OWED = { pending_commission_satang: 0, can_waive: true, waive_blocked_reason: null }
const OWED = { pending_commission_satang: 123450, can_waive: true, waive_blocked_reason: null }
const BLOCKED_REASON = 'มีรายการเบิก/ทำจ่ายค่าแนะนำของคุณที่กำลังดำเนินการอยู่'

/** Every GET in this file is either the preview or something that wants `{ data: {} }`. */
function previewWith(preview: unknown) {
  get
    .mockReset()
    .mockImplementation(async (path: string) =>
      String(path).startsWith('/me/account-deletion-request/preview')
        ? { data: preview }
        : { data: {} },
    )
}

async function mountDialog() {
  const wrapper = mount(AccountDeletionDialog, {
    props: { show: true },
    global: { stubs: { Icon: true, InfoPopover: { template: '<span><slot /></span>' } } },
  })
  await flushPromises()

  return wrapper
}

describe('AccountDeletionDialog — loading what is owed', () => {
  it('shows a loading state, then the preview, and only then the form', async () => {
    let resolve!: (v: unknown) => void
    get.mockReturnValue(new Promise((r) => (resolve = r)))
    const wrapper = mount(AccountDeletionDialog, {
      props: { show: true },
      global: { stubs: { Icon: true, InfoPopover: true } },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="preview-loading"]').exists()).toBe(true)
    expect(wrapper.find('#account-deletion-password').exists()).toBe(false)
    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeDefined()

    resolve({ data: NOTHING_OWED })
    await flushPromises()

    expect(wrapper.find('[data-test="preview-loading"]').exists()).toBe(false)
    expect(wrapper.find('#account-deletion-password').exists()).toBe(true)
  })

  it('says when the preview failed, sends nothing, and can retry', async () => {
    get.mockRejectedValueOnce(new FakeApiError(500, null))
    const wrapper = await mountDialog()

    expect(wrapper.find('[data-test="preview-error"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    expect(post).not.toHaveBeenCalled()

    await wrapper.get('[data-test="preview-error"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="immediate-notice"]').exists()).toBe(true)
  })
})

describe('AccountDeletionDialog — nothing owed: deleted immediately', () => {
  it('says the account is deleted immediately and cannot be undone', async () => {
    const wrapper = await mountDialog()

    const notice = wrapper.get('[data-test="immediate-notice"]').text()
    expect(notice).toContain('ลบทันที')
    expect(notice).toContain('ย้อนกลับไม่ได้')
    expect(wrapper.find('[data-test="commission-choice"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="submit"]').text()).toBe('ลบบัญชีทันที')
  })

  it('asks for the password before sending anything', async () => {
    const wrapper = await mountDialog()

    await wrapper.find('form').trigger('submit')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณากรอกรหัสผ่าน')
  })

  it('200 deleted → session forgotten, no choice sent, the host is told "deleted"', async () => {
    post.mockResolvedValue({ data: { status: 'deleted', forfeited_commission_satang: null } })
    const wrapper = await mountDialog()

    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('textarea').setValue('  ย้ายบริษัท  ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/me/account-deletion-request', {
      password: 'Correct1',
      reason: 'ย้ายบริษัท',
    })
    expect(setToken).toHaveBeenCalledWith(null)
    expect(useAuthStore().user).toBeNull()
    expect(wrapper.emitted('requested')).toEqual([['deleted']])
    // No /logout: the token is already dead, calling it would only 401.
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('a wrong password keeps the agent signed in and shows the server sentence', async () => {
    post.mockRejectedValue(
      new FakeApiError(422, { message: 'x', errors: { password: ['รหัสผ่านไม่ถูกต้อง'] } }),
    )
    const wrapper = await mountDialog()

    await wrapper.find('input[type="password"]').setValue('wrong')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('รหัสผ่านไม่ถูกต้อง')
    expect(setToken).not.toHaveBeenCalled()
    expect(useAuthStore().user).not.toBeNull()
    expect(wrapper.emitted('requested')).toBeUndefined()
  })
})

describe('AccountDeletionDialog — commission owed: the agent chooses', () => {
  it('shows the amount from satang and both options', async () => {
    previewWith(OWED)
    const wrapper = await mountDialog()

    expect(wrapper.get('[data-test="owed-amount"]').text()).toBe('฿1,234.50')
    expect(wrapper.find('[data-test="choice-waive"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="choice-keep"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('ยินยอมไม่รับค่าคอม')
    expect(wrapper.text()).toContain('ต้องการรับค่าคอม')
    expect(wrapper.find('[data-test="immediate-notice"]').exists()).toBe(false)
  })

  it('sends nothing until a choice is made', async () => {
    previewWith(OWED)
    const wrapper = await mountDialog()

    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('form').trigger('submit')

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณาเลือกว่าจะรอรับค่าแนะนำ หรือยินยอมสละสิทธิ์')
  })

  it('waive → sends commission_choice waive and reports "deleted"', async () => {
    previewWith(OWED)
    post.mockResolvedValue({ data: { status: 'deleted', forfeited_commission_satang: 123450 } })
    const wrapper = await mountDialog()

    await wrapper.get('[data-test="choice-waive"]').setValue(true)
    expect(wrapper.get('[data-test="submit"]').text()).toBe('ลบบัญชีทันที')
    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/me/account-deletion-request', {
      password: 'Correct1',
      commission_choice: 'waive',
    })
    expect(wrapper.emitted('requested')).toEqual([['deleted']])
  })

  it('keep → sends commission_choice keep and reports "pending"', async () => {
    previewWith(OWED)
    post.mockResolvedValue({ data: { status: 'pending', requested_at: '2026-10-03T10:00:00Z' } })
    const wrapper = await mountDialog()

    await wrapper.get('[data-test="choice-keep"]').setValue(true)
    expect(wrapper.get('[data-test="submit"]').text()).toBe('ส่งคำขอลบบัญชี')
    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/me/account-deletion-request', {
      password: 'Correct1',
      commission_choice: 'keep',
    })
    expect(setToken).toHaveBeenCalledWith(null)
    expect(wrapper.emitted('requested')).toEqual([['pending']])
  })

  it('when waiving is blocked, offers only "keep" with the server reason', async () => {
    previewWith({
      pending_commission_satang: 50000,
      can_waive: false,
      waive_blocked_reason: BLOCKED_REASON,
    })
    post.mockResolvedValue({ data: { status: 'pending' } })
    const wrapper = await mountDialog()

    expect(wrapper.find('[data-test="choice-waive"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="waive-blocked"]').text()).toBe(BLOCKED_REASON)
    expect((wrapper.get('[data-test="choice-keep"]').element as HTMLInputElement).checked).toBe(
      true,
    )

    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/me/account-deletion-request', {
      password: 'Correct1',
      commission_choice: 'keep',
    })
  })

  it('a 409 on waive shows the server sentence, stays signed in, and re-reads what is owed', async () => {
    previewWith(OWED)
    post.mockRejectedValue(new FakeApiError(409, { message: BLOCKED_REASON }))
    const wrapper = await mountDialog()
    get.mockClear()

    await wrapper.get('[data-test="choice-waive"]').setValue(true)
    await wrapper.find('input[type="password"]').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain(BLOCKED_REASON)
    expect(setToken).not.toHaveBeenCalled()
    expect(wrapper.emitted('requested')).toBeUndefined()
    expect(get).toHaveBeenCalledWith('/me/account-deletion-request/preview')
  })
})

async function mountProfile() {
  const wrapper = mount(ProfileSettingsView, {
    global: { stubs: { Icon: true, HeroHeader: true, Teleport: true } },
  })
  await flushPromises()

  return wrapper
}

describe('ProfileSettingsView — account deletion (web and app alike)', () => {
  async function deleteFromProfile() {
    useThemeStore().theme = { company: { slug: 'thai-life' } } as never
    const wrapper = await mountProfile()

    const open = wrapper.findAll('button').find((b) => b.text().trim() === 'ขอลบบัญชี')
    expect(open).toBeDefined()
    await open?.trigger('click')
    await flushPromises()

    return wrapper
  }

  it('deleted immediately → the themed login with the "deleted" notice', async () => {
    post.mockResolvedValue({ data: { status: 'deleted' } })
    const wrapper = await deleteFromProfile()

    await wrapper.find('#account-deletion-password').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(replace).toHaveBeenCalledWith({
      name: 'login',
      query: { company: 'thai-life', notice: 'account_deleted' },
    })
  })

  it('kept for an admin → the themed login with the "request sent" notice', async () => {
    previewWith(OWED)
    post.mockResolvedValue({ data: { status: 'pending' } })
    const wrapper = await deleteFromProfile()

    await wrapper.get('[data-test="choice-keep"]').setValue(true)
    await wrapper.find('#account-deletion-password').setValue('Correct1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(replace).toHaveBeenCalledWith({
      name: 'login',
      query: { company: 'thai-life', notice: 'deletion_requested' },
    })
  })
})

describe('ProfileSettingsView — biometric unlock row', () => {
  it('is not rendered in a browser, and nothing is asked of a plugin', async () => {
    const wrapper = await mountProfile()

    expect(wrapper.text()).not.toContain('ปลดล็อกด้วย Face ID / ลายนิ้วมือ')
    expect(checkBiometry).not.toHaveBeenCalled()
  })

  it('is not rendered in the app on a phone without biometrics', async () => {
    native.value = true
    checkBiometry.mockResolvedValue({ isAvailable: false, biometryType: 0 })
    const wrapper = await mountProfile()

    expect(wrapper.text()).not.toContain('ปลดล็อกด้วย Face ID / ลายนิ้วมือ')
  })

  it('in the app, switching ON needs a successful scan and is remembered for this user', async () => {
    native.value = true
    const wrapper = await mountProfile()
    expect(wrapper.text()).toContain('ปลดล็อกด้วย Face ID / ลายนิ้วมือ')

    authenticate.mockRejectedValueOnce(
      Object.assign(new Error('x'), { code: 'authenticationFailed' }),
    )
    // Located through its own card: "เปิดอยู่" CONTAINS "ปิดอยู่" in Thai, so
    // matching on the label would find the e-mail switch above it.
    const toggle = () => {
      const root = wrapper.element as HTMLElement
      const heading = Array.from(root.querySelectorAll<HTMLHeadingElement>('h2')).find((el) =>
        el.textContent?.includes('Face ID'),
      )
      const card = heading?.closest('.rounded-2xl')
      ;(
        card?.querySelector('[role="switch"]')?.closest('button') as HTMLButtonElement | null
      )?.click()
    }
    toggle()
    await flushPromises()
    expect(prefs.get('sv_biometric_lock:7')).toBeUndefined()
    expect(wrapper.text()).toContain('ยังไม่ได้เปิด')

    toggle()
    await flushPromises()
    expect(prefs.get('sv_biometric_lock:7')).toBe('1')
    expect(wrapper.text()).toContain('เปิดอยู่')
  })

  it('a lock that is already on stays switchable even if biometrics went away', async () => {
    native.value = true
    prefs.set('sv_biometric_lock:7', '1')
    checkBiometry.mockResolvedValue({ isAvailable: false, biometryType: 0 })
    const wrapper = await mountProfile()

    expect(wrapper.text()).toContain('ปลดล็อกด้วย Face ID / ลายนิ้วมือ')
  })
})
