/**
 * TASK-228 — the announcement image is never cropped.
 *
 * WHY THIS FILE EXISTS. AnnouncementModal had four display styles, three
 * callers and NO spec at all, which is how `object-cover` on a fixed-height
 * box survived from TASK-075 until a human sent a screenshot of a banner
 * with its bottom third missing. The rule is one line of CSS and easy to
 * undo by accident — a later "make the modal more compact" change would
 * reach for a fixed height first.
 *
 * These assert the RULE (no crop, no fixed height, whole image), not the
 * exact utility strings, so restyling is free as long as the image still
 * shows in full.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AnnouncementModal, {
  type AnnouncementDisplayStyle,
  type AnnouncementModalItem,
} from '../AnnouncementModal.vue'

const ANNOUNCEMENT: AnnouncementModalItem = {
  id: 1,
  title: 'เปิดตัวคู่มือชีวิตพร้อมสายรัดติดตามสุขภาพ 24 ช.ม.',
  content: 'GENESENN Health Tracker',
  is_pinned: false,
  published_at: '2026-08-20T00:00:00Z',
  image_url: 'https://example.test/banner.png',
  video: null,
}

const STYLES: AnnouncementDisplayStyle[] = [
  'full_screen',
  'bottom_sheet',
  'centered_card',
  'bottom_strip',
]

function mountModal(displayStyle?: AnnouncementDisplayStyle) {
  return mount(AnnouncementModal, {
    props: { show: true, announcement: ANNOUNCEMENT, displayStyle, startExpanded: true },
    global: { stubs: { Icon: true, Teleport: true } },
  })
}

/** The big banner image, not the 40px thumbnail the collapsed strip uses. */
function bannerImage(wrapper: ReturnType<typeof mountModal>) {
  return wrapper
    .findAll('img')
    .find((img) => img.classes().includes('w-full'))
}

describe('AnnouncementModal — the image is shown whole (TASK-228)', () => {
  it.each(STYLES)('never crops the image, in the %s style', (style) => {
    const img = bannerImage(mountModal(style))
    expect(img).toBeDefined()

    const classes = img!.classes()

    // object-cover IS the crop, and it is the one that must never come back.
    //
    // This used to forbid object-contain too, and that was right while the
    // image sat in a FIXED-height box: contain would have shown the whole
    // picture only by padding it with dead bars. The box is gone (h-auto),
    // and since 2026-08-21 there is a max-h-[80vh] cap so the title cannot
    // be pushed off screen by a tall poster. Against a CAP rather than a
    // box, object-contain is what stops a clamped image being squashed by
    // the w-full beside it — it does nothing at all until the cap bites.
    // So the rule this file defends is unchanged (never crop); only the
    // class that would break it is.
    expect(classes).not.toContain('object-cover')
  })

  it.each(STYLES)('caps the image below the panel height, in the %s style', (style) => {
    // Reported 2026-08-21: a tall portrait poster filled the sheet and the
    // headline sat below the fold, so the announcement opened as a picture
    // with no words. TASK-228's docblock had recorded that exact trade-off
    // as accepted; production disagreed.
    //
    // 80vh → 58vh on 2026-08-22, and the reason is the whole point of this
    // case. The PANEL is now capped at 80vh too (see the sizing describe
    // below). An image capped at the same height as its container fills it
    // completely, so the title lands below the fold again — shrinking the
    // modal alone would have changed nothing a human could see. The two
    // numbers are related, and this assertion is the only thing that says so.
    const classes = bannerImage(mountModal(style))!.classes()

    expect(classes).toContain('max-h-[58vh]')
    expect(classes).not.toContain('max-h-[80vh]')
    // The cap and object-contain are a pair: max-height alone would squash
    // the image, because w-full is still forcing the width.
    expect(classes).toContain('object-contain')
  })

  it.each(STYLES)('pins no fixed height on the image, in the %s style', (style) => {
    const classes = bannerImage(mountModal(style))!.classes()

    // Any h-<number> / h-full / sm:h-* would re-impose a box the image must
    // be squeezed into. h-auto is the one height utility that is allowed,
    // because it IS the intrinsic ratio.
    const heightUtilities = classes.filter((c) => /(^|:)h-/.test(c))
    expect(heightUtilities).toEqual(['h-auto'])
  })

  it('spans the full width so the intrinsic ratio decides the height', () => {
    expect(bannerImage(mountModal('centered_card'))!.classes()).toContain('w-full')
  })

  it('keeps the card capped to the viewport as well as the image', () => {
    // Still the backstop behind the image's own 80vh cap: overflow is
    // prevented on the CARD, which scrolls, never by cropping the image.
    for (const style of ['bottom_sheet', 'centered_card'] as const) {
      const card = mountModal(style).find('.overflow-y-auto')
      expect(card.exists()).toBe(true)
      expect(card.classes().some((c) => c.startsWith('max-h-['))).toBe(true)
    }
  })

  it('renders nothing at all when there is no announcement to show', () => {
    const wrapper = mount(AnnouncementModal, {
      props: { show: true, announcement: null },
      global: { stubs: { Icon: true, Teleport: true } },
    })
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('omits the image element entirely for an announcement without one', () => {
    const wrapper = mount(AnnouncementModal, {
      props: {
        show: true,
        announcement: { ...ANNOUNCEMENT, image_url: null },
        displayStyle: 'centered_card' as const,
      },
      global: { stubs: { Icon: true, Teleport: true } },
    })
    expect(bannerImage(wrapper as never)).toBeUndefined()
  })
})

/**
 * THE PANEL IS 80% OF THE SCREEN (human, 2026-08-22, with a screenshot:
 * "ปรับให้เป็น 80% ของ Screen พอ").
 *
 * The caps were 100% / 85vh / 92vh. At 92vh the strip of backdrop above a
 * bottom sheet is about a finger's width on a phone — visually identical to
 * full screen, which is what the screenshot showed. A later change that
 * rounds 80vh back up produces no error and no other failing test; it just
 * quietly undoes the request.
 */
describe('AnnouncementModal — the panel is 80% of the screen', () => {
  function panel(wrapper: ReturnType<typeof mountModal>) {
    const found = wrapper.find('.ann-modal-panel')
    if (!found.exists()) throw new Error('The modal panel did not render.')

    return found
  }

  it.each(STYLES)('caps the %s panel at 80vh, never the full viewport', (style) => {
    const classes = panel(mountModal(style)).classes().join(' ')

    expect(classes).toMatch(/(^|\s)(max-)?h-\[80vh\]/)
    // The three values this replaced. None of them would fail anything else.
    expect(classes).not.toContain('max-h-none')
    expect(classes).not.toContain('max-h-[92vh]')
    expect(classes).not.toContain('max-h-[85vh]')
  })

  it('gives full_screen a scrim now that it no longer covers the screen', () => {
    // It deliberately had NO backdrop: the overlay WAS the panel, so a scrim
    // would have been invisible underneath it (ADR-023 §2.1 / TASK-098). At
    // 80% that inverts — a card-coloured sheet behind a card-coloured panel
    // makes the 80% impossible to see, and it reads as full-screen as before.
    const overlay = mountModal('full_screen').find('.fixed.inset-0')

    expect(overlay.classes().join(' ')).toContain('bg-black/60')
  })
})

/**
 * THE COLLAPSED STRIP AND THE PANEL ARE MUTUALLY EXCLUSIVE.
 *
 * Caught while making the change above, not before it. The strip was `v-if`
 * and the panel its `v-else-if`; moving the panel inside <Transition> severed
 * that pairing, because a v-else-if cannot reach across an element boundary.
 * Both then render at once — a "non-blocking bar" with a blocking modal on
 * top of it, which is the one state bottom_strip exists to avoid.
 */
describe('AnnouncementModal — bottom_strip stays exclusive', () => {
  function mountStrip(startExpanded: boolean) {
    return mount(AnnouncementModal, {
      props: { show: true, announcement: ANNOUNCEMENT, displayStyle: 'bottom_strip' as const, startExpanded },
      global: { stubs: { Icon: true, Teleport: true } },
    })
  }

  it('shows only the strip while collapsed', () => {
    const wrapper = mountStrip(false)

    expect(wrapper.find('.ann-modal-panel').exists()).toBe(false)
    expect(wrapper.find('.fixed.bottom-0').exists()).toBe(true)
  })

  it('shows only the panel once expanded', () => {
    const wrapper = mountStrip(true)

    expect(wrapper.find('.ann-modal-panel').exists()).toBe(true)
    expect(wrapper.find('.fixed.bottom-0.inset-x-0').exists()).toBe(false)
  })

  it('swaps the strip for the panel when tapped', async () => {
    const wrapper = mountStrip(false)

    await wrapper.find('.fixed.bottom-0').trigger('click')

    expect(wrapper.find('.ann-modal-panel').exists()).toBe(true)
  })
})
