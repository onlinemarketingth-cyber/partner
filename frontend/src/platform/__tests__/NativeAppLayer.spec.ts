/**
 * MOB-27 / MOB-29 / MOB-31 (2026-10-02) — the app-only blocking screens.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE LOCK SCREEN HAS NO WAY OUT. Cancelling the system prompt must leave
 *    "unlock" (retry) and "ออกจากระบบ" on screen; an agent whose phone lost
 *    its biometrics must still be able to sign out and back in.
 * 2. THE LOCK SHOWS WITH NOBODY SIGNED IN — a lock over the login page.
 * 3. THE WRONG SCREEN WINS. A required update outranks the lock, the lock
 *    outranks "no internet" (unlocking works offline).
 * 4. THE OFFLINE NOTICE IS A RAW KEY. These can appear before the dictionary
 *    loads (offline at launch), so the Thai has to be there regardless.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const push = vi.fn<AnyFn>()
const authenticate = vi.fn<AnyFn>()
const getStatus = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => true, getPlatform: () => 'ios' },
}))
vi.mock('@aparajita/capacitor-biometric-auth', () => ({
  BiometricAuth: { authenticate: (o: unknown) => authenticate(o), checkBiometry: vi.fn<AnyFn>() },
}))
vi.mock('@capacitor/network', () => ({
  Network: { getStatus: () => getStatus(), addListener: vi.fn<AnyFn>() },
}))
vi.mock('@capacitor/preferences', () => ({
  Preferences: { get: async () => ({ value: null }), set: vi.fn<AnyFn>() },
}))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ name: 'home', params: {}, query: {} }),
}))
vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn<AnyFn>(),
    post: vi.fn<AnyFn>().mockResolvedValue(undefined),
    delete: vi.fn<AnyFn>(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn<AnyFn>(),
  setToken: vi.fn<AnyFn>(),
}))

import NativeAppLayer from '../components/NativeAppLayer.vue'
import { clearLock, lockState } from '../biometric'
import { networkState } from '../network'
import { updateState } from '../versionPolicy'
import { useAuthStore, type AuthUser } from '@/stores/auth'

function signIn() {
  useAuthStore().setUser({ id: 7, name: 'สมหญิง' } as unknown as AuthUser)
}

function mountLayer() {
  return mount(NativeAppLayer, {
    global: { stubs: { Icon: true, AppLogo: true } },
  })
}

function buttonByText(wrapper: ReturnType<typeof mountLayer>, text: string) {
  return wrapper.findAll('button').find((b) => b.text().includes(text))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useRealTimers()
  push.mockReset()
  authenticate.mockReset().mockResolvedValue(undefined)
  getStatus.mockReset().mockResolvedValue({ connected: true })
  clearLock()
  networkState.offline = false
  networkState.checking = false
  updateState.required = false
  updateState.available = null
  updateState.storeUrl = null
})

describe('lock screen', () => {
  it('asks for biometrics by itself, and disappears on success', async () => {
    vi.useFakeTimers()
    signIn()
    lockState.active = true
    const wrapper = mountLayer()

    expect(wrapper.text()).toContain('แอปถูกล็อกอยู่')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    expect(authenticate).toHaveBeenCalledTimes(1)
    expect(lockState.active).toBe(false)
    expect(wrapper.text()).not.toContain('แอปถูกล็อกอยู่')
  })

  it('after a cancel it stays, explains, and offers retry and sign-out', async () => {
    vi.useFakeTimers()
    signIn()
    authenticate.mockRejectedValue(Object.assign(new Error('x'), { code: 'userCancel' }))
    lockState.active = true
    const wrapper = mountLayer()
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    expect(lockState.active).toBe(true)
    expect(wrapper.text()).toContain('ยกเลิกการยืนยันตัวตนแล้ว')
    expect(buttonByText(wrapper, 'ปลดล็อก')).toBeDefined()

    vi.useRealTimers()
    await buttonByText(wrapper, 'ออกจากระบบ')?.trigger('click')
    // Sign-out loads platform/push.ts on demand (app only) before revoking.
    await vi.waitFor(() => expect(useAuthStore().user).toBeNull())
    expect(lockState.active).toBe(false)
    expect(push).toHaveBeenCalledWith(expect.objectContaining({ name: 'login' }))
  })

  it('never covers the login page', () => {
    lockState.active = true
    const wrapper = mountLayer()

    expect(wrapper.text()).not.toContain('แอปถูกล็อกอยู่')
  })
})

describe('which screen wins', () => {
  it('required update > lock > offline', async () => {
    signIn()
    lockState.active = true
    networkState.offline = true
    updateState.required = true
    const wrapper = mountLayer()
    expect(wrapper.text()).toContain('กรุณาอัปเดตแอป')
    expect(wrapper.text()).not.toContain('แอปถูกล็อกอยู่')

    updateState.required = false
    await flushPromises()
    expect(wrapper.text()).toContain('แอปถูกล็อกอยู่')
    expect(wrapper.text()).not.toContain('ไม่มีการเชื่อมต่ออินเทอร์เน็ต')

    lockState.active = false
    await flushPromises()
    expect(wrapper.text()).toContain('ไม่มีการเชื่อมต่ออินเทอร์เน็ต')
  })
})

describe('offline notice', () => {
  it('retries and clears when the connection is back', async () => {
    networkState.offline = true
    const wrapper = mountLayer()

    await buttonByText(wrapper, 'ลองใหม่')?.trigger('click')
    await flushPromises()

    expect(getStatus).toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('ไม่มีการเชื่อมต่ออินเทอร์เน็ต')
  })
})

describe('update', () => {
  it('the blocking screen has no way past it except the store', () => {
    updateState.required = true
    updateState.storeUrl = 'https://apps.apple.com/app/id1'
    const wrapper = mountLayer()

    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(1)
    expect(buttons[0]?.text()).toContain('ไปที่หน้าอัปเดต')
  })

  it('the banner can be dismissed', async () => {
    updateState.available = '1.5.0'
    const wrapper = mountLayer()
    expect(wrapper.text()).toContain('1.5.0')

    await wrapper.find('button[aria-label="ปิด"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('1.5.0')
  })
})
