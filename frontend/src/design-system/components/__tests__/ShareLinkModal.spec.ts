/**
 * The share sheet's Link tab: ONE green "แชร์", and only where it leads
 * somewhere.
 *
 * ── WHAT THESE PIN, AND WHY EACH ONE REGRESSES QUIETLY ──
 *
 * 1. THE DESKTOP GATE IS NOT `!!navigator.share`. Desktop Chrome on Windows
 *    and Safari on macOS both expose that API, so the obvious-looking
 *    simplification — dropping the pointer check "because navigator.share
 *    already tells us" — puts the button straight back on the screens the
 *    2026-08-21 report asked to remove it from. Nothing breaks, no error is
 *    thrown, and the only symptom is a button an agent at a desk taps once
 *    and never again.
 *
 * 2. IT IS STILL THERE ON A PHONE. The opposite mistake is worse: a gate
 *    tightened until it hides everywhere leaves a mobile agent with copy-link
 *    as their only route, on the device where the share sheet IS the feature.
 *
 * 3. THE GREEN AND THE POSITION ARE LOAD-BEARING. For these agents the tap
 *    that used to say LINE still ends in LINE, through the phone's own sheet.
 *    Keeping LINE's #06C755 in the slot the LINE button held is what makes
 *    the replacement invisible to muscle memory. A future theming pass that
 *    "tidies" the literal into `bg-brand-600` would take that away.
 *
 * 4. NO SECOND SHARE CONTROL. The full-width "แชร์ผ่านแอปอื่น" button was
 *    removed because it did the same thing as the green one. Two of them is
 *    the state this replaced, and it reads as a bug rather than a duplicate.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ShareLinkModal from '../ShareLinkModal.vue'

/**
 * Describe the DEVICE, then mount. Both halves have to be in place before
 * setup runs: the pointer check is read once, at setup, on purpose.
 */
function onDevice({ share, coarse }: { share: boolean; coarse: boolean }) {
  if (share) {
    Object.defineProperty(navigator, 'share', { value: vi.fn(), configurable: true })
  } else {
    Reflect.deleteProperty(navigator, 'share')
  }

  window.matchMedia = ((query: string) => ({
    matches: query.includes('pointer: coarse') ? coarse : false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    onchange: null,
    dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia

  return mount(ShareLinkModal, {
    props: { show: true, url: 'https://partner.syncvision.io/j/aN3tDZqGjR' },
    global: { stubs: { Icon: true, Teleport: true } },
  })
}

/** The green button, found by the one thing that must not change about it. */
function shareButton(wrapper: ReturnType<typeof onDevice>) {
  return wrapper.findAll('button').filter((b) => b.classes().some((c) => c.includes('06C755')))
}

beforeEach(() => {
  Reflect.deleteProperty(navigator, 'share')
})

describe('ShareLinkModal — the share button', () => {
  it('is hidden on a desktop even though the browser supports sharing', () => {
    // THE WHOLE POINT. navigator.share exists here; the pointer is what
    // makes this a desk, and the copy button two rows up is the useful
    // action on it.
    const wrapper = onDevice({ share: true, coarse: false })

    expect(shareButton(wrapper)).toHaveLength(0)
  })

  it('is shown on a phone or tablet', () => {
    const wrapper = onDevice({ share: true, coarse: true })

    expect(shareButton(wrapper)).toHaveLength(1)
  })

  it('stays hidden on a touch device whose browser cannot share at all', () => {
    const wrapper = onDevice({ share: false, coarse: true })

    expect(shareButton(wrapper)).toHaveLength(0)
  })

  it('says just "แชร์" — not "แชร์ผ่านแอปอื่น"', () => {
    const wrapper = onDevice({ share: true, coarse: true })

    expect(shareButton(wrapper)[0]!.text()).toBe('แชร์')
    expect(wrapper.text()).not.toContain('แชร์ผ่านแอปอื่น')
  })

  it('keeps LINE green, which is what makes it the same tap it replaced', () => {
    const wrapper = onDevice({ share: true, coarse: true })

    expect(shareButton(wrapper)[0]!.classes().join(' ')).toContain('bg-[#06C755]')
    expect(shareButton(wrapper)[0]!.classes()).toContain('text-white')
  })

  it('no longer offers a separate LINE button', () => {
    // It opened social-plugins.line.me in a browser tab and asked an agent
    // already signed into the LINE app to sign in again.
    const wrapper = onDevice({ share: true, coarse: true })

    expect(wrapper.findAll('button').some((b) => b.text() === 'LINE')).toBe(false)
  })

  it('leaves Email at full width when the share button is hidden', () => {
    // Otherwise Email sits at half width next to an empty gap — which looks
    // like a button that failed to render rather than one that was omitted.
    const desktop = onDevice({ share: true, coarse: false })
    const phone = onDevice({ share: true, coarse: true })

    const grid = (w: ReturnType<typeof onDevice>) =>
      w.findAll('div').find((d) => d.classes().includes('grid'))!.classes()

    expect(grid(desktop)).toContain('grid-cols-1')
    expect(grid(phone)).toContain('grid-cols-2')
  })

  it('assumes desktop rather than crashing when matchMedia is unavailable', () => {
    // Some embedded webviews and test environments have no matchMedia. The
    // sheet must still open; hiding one button is a far smaller failure than
    // a modal that throws on mount.
    Object.defineProperty(navigator, 'share', { value: vi.fn(), configurable: true })
    const original = window.matchMedia
    // @ts-expect-error deliberately removing it
    delete window.matchMedia

    expect(() =>
      mount(ShareLinkModal, {
        props: { show: true, url: 'https://example.test/x' },
        global: { stubs: { Icon: true, Teleport: true } },
      }),
    ).not.toThrow()

    window.matchMedia = original
  })
})
