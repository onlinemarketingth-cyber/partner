/**
 * 2026-09-10 (human: "ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์
 * เพราะทำงานคนละหน้าที่กัน").
 *
 * The front-desk account belongs in this app — it just belongs on one screen
 * of it. So unlike an Agent it is not signed out; it is sent to the screen it
 * came for.
 *
 * This guard is UX, never enforcement: the backend's RestrictVoucherStaff
 * middleware refuses the API calls regardless, and these tests would still
 * pass if it did not — which is exactly why the middleware has its own test
 * (tests/Feature/Platform/UserAbilityGrantTest.php). What is pinned here is
 * the shape that keeps the guard correct without maintenance: an ALLOWLIST,
 * so a route added tomorrow is closed to this role until somebody opens it on
 * purpose.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue(''),
    post: vi.fn().mockResolvedValue({}),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ensureCsrfCookie: vi.fn(),
  ApiError: class extends Error {},
}))

import router from '@/router'

async function signInAs(role: string): Promise<void> {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'พนักงานหน้าร้าน', role } as never
  // 'ready' so the guard does not try to fetch /me on every navigation.
  auth.status = 'ready'
}

beforeEach(async () => {
  setActivePinia(createPinia())
  await router.replace('/login').catch(() => {})
})

describe('router — the front-desk account sees one screen', () => {
  it('sends them to the redemption screen instead of a page of 403s', async () => {
    await signInAs('voucher_staff')

    await router.push('/order-payments')
    expect(router.currentRoute.value.name).toBe('voucher-redeem')
  })

  it('closes a screen it has never heard of, rather than opening it', async () => {
    // The allowlist, stated as a test: /users is not on it, and neither is
    // anything added after this file was written.
    await signInAs('voucher_staff')

    await router.push('/users')
    expect(router.currentRoute.value.name).toBe('voucher-redeem')
  })

  it('lets them reach the screen they came for', async () => {
    await signInAs('voucher_staff')

    await router.push('/voucher-redeem')
    expect(router.currentRoute.value.name).toBe('voucher-redeem')
  })

  it('lets them manage their own account — their own record is not somebody else\'s data', async () => {
    await signInAs('voucher_staff')

    await router.push('/profile')
    expect(router.currentRoute.value.name).toBe('profile')
  })

  it('does not touch an ordinary admin', async () => {
    // The most likely way this guard goes wrong: a condition that starts
    // catching everybody.
    await signInAs('company_admin')

    await router.push('/order-payments')
    expect(router.currentRoute.value.name).toBe('order-payments')
  })
})
