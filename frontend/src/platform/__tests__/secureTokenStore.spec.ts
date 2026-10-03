/**
 * MOB-01 (2026-10-02) — the sign-in token inside the iOS/Android app.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE APP SIGNS AGENTS OUT. If the saved token is not put back before
 *    the boot /me call, every launch lands on the login screen — the app
 *    "works", it just forgets you, and nobody files that as a bug.
 * 2. THE WEB PORTAL CHANGES. In a browser nothing here may run: no plugin
 *    load, no second copy of the token. The owner's condition for the app
 *    was that shared links and the web portal stay exactly as they are.
 * 3. LOGOUT DOES NOT STICK. Saving and forgetting must land in the order
 *    they were asked for, or a quick login → logout leaves a token behind.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const native = { value: false }
const stored = new Map<string, string>()
const pluginLoaded = vi.fn<() => void>()
const failNextGet = { value: false }

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => native.value },
}))

vi.mock('@aparajita/capacitor-secure-storage', () => {
  pluginLoaded()

  return {
    SecureStorage: {
      get: async (key: string) => {
        if (failNextGet.value) throw new Error('keychain locked')

        return stored.get(key) ?? null
      },
      set: async (key: string, value: string) => {
        await new Promise((resolve) => setTimeout(resolve, 5))
        stored.set(key, value)
      },
      remove: async (key: string) => {
        stored.delete(key)

        return true
      },
    },
  }
})

async function freshClient() {
  vi.resetModules()

  return import('@/api/client')
}

beforeEach(() => {
  native.value = false
  failNextGet.value = false
  stored.clear()
  pluginLoaded.mockClear()
  localStorage.clear()
})

describe('sign-in token inside the app', () => {
  it('puts the token saved by an earlier launch back before boot', async () => {
    native.value = true
    stored.set('sva_token', 'saved-token')
    const client = await freshClient()

    await client.restoreNativeToken()

    expect(client.getToken()).toBe('saved-token')
    expect(client.authHeaders(new Headers()).get('Authorization')).toBe('Bearer saved-token')
  })

  it('the Keychain copy wins when iOS has cleared localStorage', async () => {
    native.value = true
    stored.set('sva_token', 'keychain-token')
    localStorage.setItem('sva_token', 'stale-token')
    const client = await freshClient()

    await client.restoreNativeToken()

    expect(client.getToken()).toBe('keychain-token')
  })

  it('saves on login and forgets on logout, in that order', async () => {
    native.value = true
    const client = await freshClient()

    client.setToken('first-token')
    await vi.waitFor(() => expect(stored.get('sva_token')).toBe('first-token'))

    // The save is slower than the delete (see the mock): only running them
    // in order keeps the logout from being overwritten.
    client.setToken('second-token')
    client.setToken(null)
    await new Promise((resolve) => setTimeout(resolve, 30))

    expect(stored.has('sva_token')).toBe(false)
  })

  it('a Keychain that cannot be read means signed out, not a crash', async () => {
    native.value = true
    failNextGet.value = true
    const client = await freshClient()

    await expect(client.restoreNativeToken()).resolves.toBeUndefined()
    expect(client.getToken()).toBeNull()
  })
})

describe('the web portal is unchanged', () => {
  it('never loads the plugin and keeps the token in localStorage only', async () => {
    const client = await freshClient()

    await client.restoreNativeToken()
    client.setToken('web-token')

    expect(pluginLoaded).not.toHaveBeenCalled()
    expect(stored.size).toBe(0)
    expect(localStorage.getItem('sva_token')).toBe('web-token')
    expect(client.getToken()).toBe('web-token')
  })
})
