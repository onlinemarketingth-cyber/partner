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

    // object-cover is the crop. object-contain would not crop, but only by
    // letterboxing inside a fixed box — neither belongs here.
    expect(classes).not.toContain('object-cover')
    expect(classes).not.toContain('object-contain')
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

  it('keeps the card capped to the viewport so an uncapped image cannot overflow it', () => {
    // The counterpart to leaving the image uncapped: overflow is prevented
    // on the CARD, which scrolls, rather than by cropping the image.
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
