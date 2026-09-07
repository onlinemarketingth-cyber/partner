/**
 * 2026-09-07 (human, looking at a partner's own signup page): "เอา Sync Vision
 * Agent นี้ออก".
 *
 * The mark was not a bug — AppLogo already resolves a company's uploaded logo
 * and its configured app name (TASK-121). GENESENN simply has neither, so the
 * built-in fallback rendered, and the platform vendor's name sat at the top of
 * a page shown to somebody being recruited by GENESENN. That reader has no
 * relationship with the vendor; it is the one piece of branding on the page
 * that belongs to nobody in the conversation.
 *
 * So the fallback became a choice, and the line these tests hold is which case
 * it suppresses. `hide` must remove ONLY the built-in mark. A company that
 * uploaded a logo or set an app name is showing its OWN brand, and hiding that
 * would be the exact opposite of white-label — the same defect pointing the
 * other way.
 */
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

let navLogo: string | null = null
let loginLogo: string | null = null
let appName = 'Sync Vision Agent'

vi.mock('@/stores/theme', () => ({
  useThemeStore: () => ({
    get navLogo() {
      return navLogo
    },
    get loginLogo() {
      return loginLogo
    },
    label: (_key: string, fallback: string) => (appName || fallback),
  }),
}))

import AppLogo from '../AppLogo.vue'

function mountLogo(props: Record<string, unknown> = {}) {
  return mount(AppLogo, { props: { mode: 'wordmark', ...props } as never })
}

function reset() {
  navLogo = null
  loginLogo = null
  appName = 'Sync Vision Agent'
}

describe('AppLogo — an unbranded company', () => {
  it('still shows the built-in mark by default, inside the product', async () => {
    // In-app, the platform IS the thing you are using; removing it everywhere
    // would leave the top bar blank for most tenants.
    reset()

    expect(mountLogo().text()).toContain('Sync Vision')
  })

  it('renders nothing at all with fallback="hide"', async () => {
    reset()

    const wrapper = mountLogo({ fallback: 'hide' })

    expect(wrapper.text()).not.toContain('Sync Vision')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('hides the icon form too', async () => {
    reset()

    expect(mountLogo({ mode: 'icon', fallback: 'hide' }).find('svg').exists()).toBe(false)
  })
})

describe('AppLogo — a company with its own branding', () => {
  it('keeps showing an uploaded logo even when told to hide', async () => {
    // The opposite defect. This IS the company's brand; suppressing it would
    // strip the white-label page of the only mark that belongs on it.
    reset()
    loginLogo = 'https://example.com/genesenn.png'

    expect(mountLogo({ fallback: 'hide' }).find('img').exists()).toBe(true)
  })

  it('keeps showing a configured app name even when told to hide', async () => {
    reset()
    appName = 'GENESENN'

    const wrapper = mountLogo({ fallback: 'hide' })

    expect(wrapper.text()).toContain('GENESENN')
    expect(wrapper.text()).not.toContain('Sync Vision')
  })
})
