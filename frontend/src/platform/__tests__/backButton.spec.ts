/**
 * MOB-25 (2026-10-02) — Android's back button, and the overlay registry it
 * consults.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. BACK LEAVES THE PAGE WITH A DIALOG OPEN. Without the registry the only
 *    thing back can do is navigate — taking a half-filled form and its
 *    confirm dialog with it. That is the first thing an Android user presses
 *    to dismiss a dialog.
 * 2. THE WRONG OVERLAY CLOSES. A confirm dialog over a client drawer: back
 *    must close the dialog (opened last), not the drawer.
 * 3. A DEAD ENTRY SWALLOWS BACK. An overlay that closed itself (✕, or its
 *    view unmounted) must leave the registry, or the next back press "closes"
 *    something that is not there and does nothing visible.
 * 4. BACK ON THE HOME SCREEN QUITS. It must minimise; quitting means a cold
 *    start with a splash on the next tap.
 * 5. THE STATUS BAR TEXT DISAPPEARS. Light nav ink means a dark bar, which
 *    needs light status text — and on an Android that is not edge to edge
 *    the bar is not on the nav colour at all, so it is left alone.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const native = { value: true }
const platform = { value: 'android' }
const setStyle = vi.fn<AnyFn>()

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? platform.value : 'web'),
  },
}))

vi.mock('@capacitor/status-bar', () => ({
  Style: { Dark: 'DARK', Light: 'LIGHT', Default: 'DEFAULT' },
  StatusBar: { setStyle: (o: unknown) => setStyle(o) },
}))

import {
  closeTopmost,
  openOverlayCount,
  pushBackHandler,
  resetBackStack,
  useCloseOnBack,
} from '../backStack'
import { handleBackButton } from '../backButton'
import { statusBarStyleForInk, syncStatusBar } from '../statusBar'

const Blank = { template: '<div />' }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/login', name: 'login', component: Blank },
      { path: '/clients', name: 'clients', component: Blank },
      { path: '/orders', name: 'orders', component: Blank },
    ],
  })
}

beforeEach(() => {
  resetBackStack()
  native.value = true
  platform.value = 'android'
  setStyle.mockReset().mockResolvedValue(undefined)
  window.history.replaceState(null, '')
})

describe('the overlay registry', () => {
  it('closes the most recently opened overlay first, one per press', () => {
    const closed: string[] = []
    pushBackHandler(() => closed.push('drawer'))
    pushBackHandler(() => closed.push('confirm'))

    expect(closeTopmost()).toBe(true)
    expect(closeTopmost()).toBe(true)
    expect(closeTopmost()).toBe(false)
    expect(closed).toEqual(['confirm', 'drawer'])
  })

  it('a component registers while open and leaves on close and on unmount', async () => {
    const open = ref(false)
    const onClose = vi.fn<AnyFn>(() => {
      open.value = false
    })
    const Sheet = defineComponent({
      setup() {
        useCloseOnBack(() => open.value, onClose)

        return () => h('div')
      },
    })
    const wrapper = mount(Sheet)

    expect(openOverlayCount()).toBe(0)
    open.value = true
    await nextTick()
    expect(openOverlayCount()).toBe(1)

    // Closed by its own ✕ — the entry goes with it.
    open.value = false
    await nextTick()
    expect(openOverlayCount()).toBe(0)

    // Unmounted while open — no dead entry left behind.
    open.value = true
    await nextTick()
    wrapper.unmount()
    expect(openOverlayCount()).toBe(0)
    expect(onClose).not.toHaveBeenCalled()
  })

  it('back closes it through the same close() the ✕ uses', async () => {
    const open = ref(true)
    const Sheet = defineComponent({
      setup() {
        useCloseOnBack(
          () => open.value,
          () => {
            open.value = false
          },
        )

        return () => h('div')
      },
    })
    mount(Sheet)
    await nextTick()

    expect(closeTopmost()).toBe(true)
    await nextTick()
    expect(open.value).toBe(false)
    expect(openOverlayCount()).toBe(0)
  })

  it('an overlay that refuses to close (busy) stays registered', async () => {
    const open = ref(true)
    const Sheet = defineComponent({
      setup() {
        useCloseOnBack(
          () => open.value,
          () => {
            /* saving — refused */
          },
        )

        return () => h('div')
      },
    })
    mount(Sheet)
    await nextTick()

    closeTopmost()
    expect(openOverlayCount()).toBe(1)
  })
})

describe('handleBackButton', () => {
  it('closes an overlay instead of navigating', async () => {
    const router = makeRouter()
    await router.push('/clients')
    await router.push('/orders')
    const closed = vi.fn<AnyFn>()
    pushBackHandler(closed)
    const back = vi.spyOn(router, 'back')
    const minimize = vi.fn<AnyFn>()

    handleBackButton(router, minimize)

    expect(closed).toHaveBeenCalled()
    expect(back).not.toHaveBeenCalled()
    expect(minimize).not.toHaveBeenCalled()
  })

  it('minimises on the home screen and on login, never quits', async () => {
    const router = makeRouter()
    const minimize = vi.fn<AnyFn>()
    await router.push('/')
    handleBackButton(router, minimize)
    await router.push('/login')
    handleBackButton(router, minimize)

    expect(minimize).toHaveBeenCalledTimes(2)
  })

  it('goes back one page when there is history', async () => {
    const router = makeRouter()
    await router.push('/clients')
    window.history.replaceState({ back: '/' }, '')
    const back = vi.spyOn(router, 'back').mockImplementation(() => {})
    const minimize = vi.fn<AnyFn>()

    handleBackButton(router, minimize)

    expect(back).toHaveBeenCalled()
    expect(minimize).not.toHaveBeenCalled()
  })

  it('minimises when a page has nothing behind it (opened from a push)', async () => {
    const router = makeRouter()
    await router.push('/orders')
    window.history.replaceState({ back: null }, '')
    const back = vi.spyOn(router, 'back')
    const minimize = vi.fn<AnyFn>()

    handleBackButton(router, minimize)

    expect(minimize).toHaveBeenCalled()
    expect(back).not.toHaveBeenCalled()
  })
})

describe('status bar follows the nav ink', () => {
  it('light ink → dark bar → light status text, and vice versa', () => {
    expect(statusBarStyleForInk('255 255 255')).toBe('DARK')
    expect(statusBarStyleForInk('51 65 85')).toBe('LIGHT')
    expect(statusBarStyleForInk('')).toBe('DEFAULT')
    expect(statusBarStyleForInk('nonsense')).toBe('DEFAULT')
  })

  it('applies it on iOS, where the page is always under the status bar', async () => {
    platform.value = 'ios'
    document.documentElement.style.setProperty('--ink-nav', '255 255 255')

    await syncStatusBar(true)

    expect(setStyle).toHaveBeenCalledWith({ style: 'DARK' })
  })

  it('leaves an Android that is not edge to edge on the system default', async () => {
    document.documentElement.style.setProperty('--ink-nav', '255 255 255')
    document.documentElement.style.setProperty('--safe-area-inset-top', '0px')
    await syncStatusBar(true)
    expect(setStyle).toHaveBeenLastCalledWith({ style: 'DEFAULT' })

    document.documentElement.style.setProperty('--safe-area-inset-top', '24px')
    await syncStatusBar(true)
    expect(setStyle).toHaveBeenLastCalledWith({ style: 'DARK' })
  })

  it('uses the default on pages without the top bar, and does nothing in a browser', async () => {
    platform.value = 'ios'
    await syncStatusBar(false)
    expect(setStyle).toHaveBeenLastCalledWith({ style: 'DEFAULT' })

    setStyle.mockClear()
    native.value = false
    await syncStatusBar(true)
    expect(setStyle).not.toHaveBeenCalled()
  })
})
