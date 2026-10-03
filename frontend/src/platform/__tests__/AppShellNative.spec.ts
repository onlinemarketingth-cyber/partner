/**
 * MOB-25 / MOB-27..31 (2026-10-02) — App.vue in the browser and in the app.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. THE WEB PORTAL'S DOM CHANGES. The safe-area wrapper around public pages
 *    exists only inside the app; in a browser a public page (login) is still
 *    rendered straight into the root, as before.
 * 2. THE APP-ONLY SCREENS NEVER APPEAR (or appear on the web). App.vue renders
 *    whatever main.ts provided under NATIVE_LAYER_KEY, and nothing otherwise.
 * 3. CONTENT SITS UNDER THE NOTCH / HOME INDICATOR. The top bar and the page
 *    bottom padding carry the env(safe-area-inset-*) terms; with insets of 0
 *    (every browser — the web build never sets viewport-fit=cover) they are
 *    the same 6rem / 0 as before.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Test doubles accept whatever the code under test passes them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const native = { value: false }

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => native.value,
    getPlatform: () => (native.value ? 'ios' : 'web'),
  },
}))
vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn<AnyFn>().mockResolvedValue({ data: { unread_count: 0 } }),
    post: vi.fn<AnyFn>(),
    delete: vi.fn<AnyFn>(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn<AnyFn>(),
  setToken: vi.fn<AnyFn>(),
}))

import App from '@/App.vue'
import { NATIVE_LAYER_KEY } from '../index'
import { useAuthStore, type AuthUser } from '@/stores/auth'

const Page = defineComponent({ render: () => h('p', { id: 'page' }, 'page') })
const Layer = defineComponent({ render: () => h('div', { id: 'native-layer' }) })

async function mountApp(path: string, provideLayer: boolean) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Page },
      { path: '/login', name: 'login', component: Page, meta: { public: true } },
    ],
  })
  const pinia = createPinia()
  await router.push(path)
  const wrapper = mount(App, {
    global: {
      plugins: [router, pinia],
      provide: provideLayer ? { [NATIVE_LAYER_KEY as symbol]: Layer } : {},
      stubs: {
        BottomNav: true,
        NotificationBell: true,
        AppLogo: true,
        Icon: true,
        ToastHost: true,
      },
    },
    attachTo: document.body,
  })
  await flushPromises()

  return { wrapper, pinia }
}

beforeEach(() => {
  native.value = false
  document.body.innerHTML = ''
})

describe('browser', () => {
  it('renders no native layer, and public pages without a wrapper', async () => {
    const { wrapper } = await mountApp('/login', false)

    expect(wrapper.find('#native-layer').exists()).toBe(false)
    expect(wrapper.find('#page').element.parentElement?.className ?? '').not.toContain('safe-area')
  })
})

describe('app', () => {
  it('renders the provided layer', async () => {
    native.value = true
    const { wrapper } = await mountApp('/login', true)

    expect(wrapper.find('#native-layer').exists()).toBe(true)
  })

  it('pads public pages by the status-bar and home-indicator insets', async () => {
    native.value = true
    const { wrapper } = await mountApp('/login', true)

    expect(wrapper.find('#page').element.parentElement?.className).toContain(
      'pt-[env(safe-area-inset-top)]',
    )
  })

  it('the top bar and page padding carry the inset terms', async () => {
    native.value = true
    const { wrapper, pinia } = await mountApp('/', true)
    useAuthStore(pinia).setUser({ id: 1, name: 'x' } as unknown as AuthUser)
    await flushPromises()

    expect(wrapper.find('header').classes()).toContain('pt-[env(safe-area-inset-top)]')
    expect(wrapper.find('main').classes()).toContain('pb-[calc(6rem+env(safe-area-inset-bottom))]')
  })
})
