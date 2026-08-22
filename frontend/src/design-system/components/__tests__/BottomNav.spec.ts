/**
 * BottomNav — the tab bar, after TASK-169 Phase 4b swapped ขาย → สินค้า.
 *
 * THE ONE THAT MATTERS IS THE `nav_sales` TEST, and it is the item TASK-169
 * §7 singles out as most likely to be missed.
 *
 * `nav_sales` is a per-company theme override (ADR-018): a tenant may have
 * typed their own word for the ขาย tab — "งานขาย", "ปิดการขาย", a brand name,
 * anything. Phase 4b puts a DIFFERENT destination in that slot. If the new
 * tab had reused the key "because it is the same slot", every company that
 * had renamed ขาย would open the app and find their own word now labelling
 * สินค้า. Nothing throws, nothing looks broken to us, and the tenant sees the
 * platform silently mislabel a screen in their own vocabulary. That is why
 * ag-lead made the new key non-negotiable (§5.1), and it is exactly the class
 * of bug a build cannot catch — hence a test that CONSTRUCTS the case.
 *
 * Both directions are asserted, because a key that is merely ignored is as
 * wrong as one that is recycled: `nav_products` must actually rename the tab,
 * or the new key is decorative and the tab is unthemeable.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  ApiError: class extends Error {},
}))

import BottomNav from '../BottomNav.vue'
import Icon from '../Icon.vue'
import { useThemeStore } from '@/stores/theme'

/** The label a tenant typed for the OLD ขาย tab. Must never resurface. */
const RENAMED_SALES = 'ปิดการขายด่วน'

function themeWith(overrides: {
  labels?: Record<string, string>
  icons?: Record<string, string>
}) {
  const store = useThemeStore()
  // Only the two fields BottomNav reads; the rest of ThemeResource is
  // irrelevant here and `apply()` is never called, so nothing touches the DOM.
  store.theme = {
    label_overrides: overrides.labels ?? null,
    nav_icon_overrides: overrides.icons ?? null,
  } as unknown as typeof store.theme
}

async function mountNav() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/clients', component: { template: '<div />' } },
      { path: '/products', component: { template: '<div />' } },
      { path: '/academy', component: { template: '<div />' } },
      { path: '/commission', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
  return mount(BottomNav, { global: { plugins: [router] } })
}

const tabs = (wrapper: Awaited<ReturnType<typeof mountNav>>) =>
  wrapper.findAll('a').map((a) => ({ to: a.attributes('href'), label: a.text() }))

describe('BottomNav (TASK-169 Phase 4b — ขาย → สินค้า)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('has สินค้า → /products in the third slot, and no tab pointing at /referrals', async () => {
    const wrapper = await mountNav()

    expect(tabs(wrapper)).toEqual([
      { to: '/', label: 'หน้าหลัก' },
      { to: '/clients', label: 'ลูกค้า' },
      { to: '/products', label: 'สินค้า' },
      { to: '/academy', label: 'Academy' },
      // Renamed to ค่าแนะนำ on 2026-08-21 (human). Still overridable per
      // company through the same nav_commission key — what this asserts
      // is the FALLBACK a tenant sees when it has configured nothing.
      { to: '/commission', label: 'ค่าแนะนำ' },
    ])
    // The default icon is `box` — the SAME one HomeView's quick link uses for
    // /products. Two entry points to one screen must not draw it two ways.
    expect(wrapper.findAllComponents(Icon)[2]!.props('name')).toBe('box')
  })

  it('does NOT show a company’s custom nav_sales label or icon on สินค้า', async () => {
    themeWith({
      labels: { nav_sales: RENAMED_SALES },
      icons: { nav_sales: 'star' },
    })
    const wrapper = await mountNav()

    // The tenant's word for the RETIRED tab appears nowhere in the bar…
    expect(wrapper.text()).not.toContain(RENAMED_SALES)
    // …and สินค้า is still สินค้า, on its own default icon.
    const products = tabs(wrapper).find((t) => t.to === '/products')
    expect(products?.label).toBe('สินค้า')
    expect(wrapper.findAllComponents(Icon).map((i) => i.props('name'))).not.toContain('star')
  })

  it('DOES rename สินค้า when the company sets the new nav_products key', async () => {
    themeWith({
      labels: { nav_products: 'แคตตาล็อก' },
      icons: { nav_products: 'cart' },
    })
    const wrapper = await mountNav()

    const products = tabs(wrapper).find((t) => t.to === '/products')
    expect(products?.label).toBe('แคตตาล็อก')
    expect(wrapper.findAllComponents(Icon)[2]!.props('name')).toBe('cart')
    // The other four tabs are untouched by a nav_products override.
    expect(tabs(wrapper).map((t) => t.label)).toEqual([
      'หน้าหลัก',
      'ลูกค้า',
      'แคตตาล็อก',
      'Academy',
      'ค่าแนะนำ',
    ])
  })
})
