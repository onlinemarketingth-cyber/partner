/**
 * 2026-09-10 (human: "ปิดหน้า admin ค้างไว้ ... กดปุ่มทำงานอะไรไม่ได้
 * ต้องกดปุ่ม refresh ถึงกลับมาทำงานได้").
 *
 * The failure this recovers from is invisible by construction: a tab open
 * across a deploy asks for chunk filenames `rsync --delete` has removed, the
 * navigation fails, and nothing changes on screen. So the tests are about the
 * two ways the recovery itself could be wrong — not reacting when it should,
 * and reacting when it should not, which would hide real bugs behind a
 * refresh or trap the browser in a loop.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { isStaleAssetError, reloadForStaleAssets, installStaleAssetRecovery } from '../staleAssets'

const assign = vi.fn()

beforeEach(() => {
  assign.mockReset()
  window.sessionStorage.clear()
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { assign, pathname: '/users', search: '' },
  })
})

describe('recognising a chunk that is no longer on the server', () => {
  it('recognises what each browser actually says', () => {
    // These are the real messages; there is no error code to match on.
    expect(isStaleAssetError(new TypeError('Failed to fetch dynamically imported module: https://x/assets/UserManagementView-a1b2.js'))).toBe(true)
    expect(isStaleAssetError(new TypeError('error loading dynamically imported module'))).toBe(true)
    expect(isStaleAssetError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isStaleAssetError(new Error('Unable to preload CSS for /assets/index-9f8e.css'))).toBe(true)
  })

  it('does not treat an ordinary bug as a stale asset', () => {
    /*
     * THE ONE THAT MATTERS. "Reload on any error" would turn every real
     * exception into a refresh that erases the evidence — the app would
     * appear to work and nobody would ever see the bug.
     */
    expect(isStaleAssetError(new TypeError("Cannot read properties of undefined (reading 'name')"))).toBe(false)
    expect(isStaleAssetError(new Error('Network request failed'))).toBe(false)
    expect(isStaleAssetError(null)).toBe(false)
  })
})

describe('reloading', () => {
  it('lands on the page the person was trying to open', async () => {
    // Not where they were. They clicked a menu item; the reload should honour
    // the click rather than silently cancelling it.
    const handlers: Array<(error: unknown, to: { fullPath: string }) => void> = []
    installStaleAssetRecovery({ onError: (h) => handlers.push(h) })

    handlers[0]?.(new TypeError('Failed to fetch dynamically imported module: /assets/x.js'), { fullPath: '/order-payments' })

    expect(assign).toHaveBeenCalledWith('/order-payments')
  })

  it('ignores a navigation that failed for any other reason', () => {
    const handlers: Array<(error: unknown, to: { fullPath: string }) => void> = []
    installStaleAssetRecovery({ onError: (h) => handlers.push(h) })

    handlers[0]?.(new Error('some component threw'), { fullPath: '/order-payments' })

    expect(assign).not.toHaveBeenCalled()
  })

  it('reloads once and then refuses, so a broken build cannot loop', () => {
    /*
     * If the NEW build is the broken one, an unguarded reload spins forever
     * and takes the error message with it every time. One reload either fixes
     * it or leaves the failure on screen where somebody can read it.
     */
    expect(reloadForStaleAssets('/users')).toBe(true)
    expect(reloadForStaleAssets('/users')).toBe(false)
    expect(assign).toHaveBeenCalledTimes(1)
  })

  it('reacts to a preload failure that never reached the router', () => {
    installStaleAssetRecovery({ onError: () => {} })

    const event = new Event('vite:preloadError', { cancelable: true })
    window.dispatchEvent(event)

    // Default-prevented: Vite otherwise rethrows it as an unhandled rejection,
    // which helps nobody once we have already decided what to do about it.
    expect(event.defaultPrevented).toBe(true)
    expect(assign).toHaveBeenCalledWith('/users')
  })
})
